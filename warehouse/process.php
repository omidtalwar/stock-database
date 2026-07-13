<?php
/**
 * Cloths Warehouse — save a Collect (in) or Distribute (out) entry.
 * Accepts a multipart POST (so bill image + recorded voice note can ride along)
 * and always replies with JSON. Called via fetch() from index.php.
 */
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';
require_once '../includes/telegram.php';
require_once '_common.php';

header('Content-Type: application/json; charset=utf-8');

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Invalid request.', 405);

// ── Read + validate common fields ──
$action = $_POST['action'] ?? '';
if (!in_array($action, ['collect', 'distribute'], true)) fail('Unknown action.');
$type = $action === 'collect' ? 'in' : 'out';

$category = trim($_POST['category'] ?? '');
if ($category === '')                fail('Category is required.');
if (mb_strlen($category) > 160)      fail('Category name is too long.');

$tan = (float)($_POST['tan'] ?? 0);
$gaz = (float)($_POST['gaz'] ?? 0);
if ($tan < 0 || $gaz < 0)            fail('تان and ګز cannot be negative.');
if ($tan <= 0 && $gaz <= 0)          fail('Enter a تان and/or ګز amount.');

$partyName   = trim($_POST['party_name'] ?? '');
$billNumber  = trim($_POST['bill_number'] ?? '');
$notes       = trim($_POST['notes'] ?? '');
$entryDate   = trim($_POST['entry_date'] ?? '');
if ($entryDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) $entryDate = null;
if (!$entryDate) $entryDate = date('Y-m-d');

// Distribution: a recipient name is expected, and we never let stock go negative.
if ($action === 'distribute') {
    if ($partyName === '') fail('Recipient name is required for distribution.');
    $avail = whAvailable($pdo, $category);
    if ($tan > $avail['tan'] + 1e-9) {
        fail('Not enough تان in stock for "' . $category . '". Available: ' . whNum($avail['tan']) . ' تان.');
    }
    if ($gaz > $avail['gaz'] + 1e-9) {
        fail('Not enough ګز in stock for "' . $category . '". Available: ' . whNum($avail['gaz']) . ' ګز.');
    }
}

// ── File uploads (optional) ──
function saveUpload(string $field, array $allowedExt, string $subdir, int $maxBytes): ?string {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) fail('Upload failed (' . $field . ' error ' . $f['error'] . ').');
    if ($f['size'] > $maxBytes)        fail(ucfirst($field) . ' exceeds the size limit.');

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    // Voice recordings from MediaRecorder arrive without an extension — infer from mime.
    if ($ext === '') {
        $map = ['audio/webm'=>'webm','audio/ogg'=>'ogg','audio/mp4'=>'m4a','audio/mpeg'=>'mp3','audio/wav'=>'wav'];
        $ext = $map[$f['type']] ?? 'webm';
    }
    if (!in_array($ext, $allowedExt, true)) fail('Unsupported ' . $field . ' file type (.' . $ext . ').');

    $dir = __DIR__ . '/../uploads/' . $subdir . '/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $name = $subdir . '_' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) fail('Could not save ' . $field . '.');
    return $name;
}

$billImage = saveUpload('bill_image', ['jpg','jpeg','png','webp','gif'],        'warehouse-bills', 8 * 1024 * 1024);
$voiceNote = saveUpload('voice_note', ['webm','ogg','m4a','mp3','wav','mpeg'],   'warehouse-voice', 12 * 1024 * 1024);

// ── Insert ──
$stmt = $pdo->prepare("
    INSERT INTO warehouse_logs
        (category, type, tan, gaz, party_name, bill_number, bill_image, voice_note, notes, entry_date, created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)
");
$stmt->execute([
    $category, $type, $tan, $gaz,
    $partyName ?: null, $billNumber ?: null, $billImage, $voiceNote,
    $notes ?: null, $entryDate, $_SESSION['user_id'],
]);
$id = (int)$pdo->lastInsertId();

// ── Telegram alert (best-effort) ──
$unit = trim(($tan > 0 ? whNum($tan) . ' تان' : '') . ($tan > 0 && $gaz > 0 ? ' + ' : '') . ($gaz > 0 ? whNum($gaz) . ' ګز' : ''));
tgNotify(
    ($action === 'collect' ? "🧵 <b>Warehouse — Collected</b>" : "📤 <b>Warehouse — Distributed</b>")
    . "\nCategory: <b>" . tgEsc($category) . "</b>"
    . "\nAmount: <b>" . tgEsc($unit) . "</b>"
    . ($partyName ? "\nName: " . tgEsc($partyName) : '')
    . ($billNumber ? "\nBill #: " . tgEsc($billNumber) : '')
    . "\nBy: " . tgActor(),
    'stock'
);

echo json_encode([
    'success' => true,
    'id'      => $id,
    'message' => $action === 'collect'
        ? 'Collected into warehouse.'
        : 'Distributed from warehouse.',
], JSON_UNESCAPED_UNICODE);
