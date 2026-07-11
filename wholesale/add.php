<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';
require_once '../includes/telegram.php';
require_once '_common.php';

$pageTitle = 'New Wholesale Entry';

$knownLocations = wsKnownLocations($pdo);
$preLoc         = trim($_GET['location'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateFormToken('wholesale_add')) {
        $_SESSION['error'] = 'Duplicate submission detected. Your data was already saved.';
        header('Location: index.php'); exit;
    }

    $errors    = [];
    $type      = in_array($_POST['type'] ?? '', ['in','out'], true) ? $_POST['type'] : 'in';
    $notes     = trim($_POST['notes'] ?? '');
    $entryDate = trim($_POST['entry_date'] ?? '') ?: null;
    if ($entryDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) $entryDate = null;

    $locs    = $_POST['location'] ?? [];
    $amounts = $_POST['amount']   ?? [];

    // One record per location row (location + amount)
    $rows = [];
    for ($i = 0; $i < count($locs); $i++) {
        $loc = trim($locs[$i] ?? '');
        $amt = (float)($amounts[$i] ?? 0);
        if ($loc === '' && $amt <= 0) continue;      // skip empty rows silently
        if ($loc === '' || $amt <= 0) {
            $errors[] = 'Each row needs both a location and an amount.';
            continue;
        }
        $rows[] = ['loc' => $loc, 'amt' => $amt];
    }

    if (empty($rows) && empty($errors)) $errors[] = 'Add at least one location with an amount.';

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO wholesale_logs (location, type, total_value, currency, notes, entry_date, created_by)
            VALUES (?,?,?,?,?,?,?)
        ");
        $grand = 0;
        foreach ($rows as $r) {
            $stmt->execute([$r['loc'], $type, $r['amt'], 'USD', $notes ?: null, $entryDate, $_SESSION['user_id']]);
            $grand += $r['amt'];
        }

        $lines = array_map(fn($r) => tgEsc($r['loc']) . ': $ ' . tgEsc(number_format($r['amt'], 2)), $rows);
        tgNotify(($type === 'in' ? "📦 <b>Wholesale IN</b>" : "📤 <b>Wholesale OUT</b>")
            . "\n" . implode("\n", $lines)
            . "\nTotal: <b>$ " . tgEsc(number_format($grand, 2)) . "</b>"
            . "\nBy: " . tgActor(), 'stock');

        $_SESSION['success'] = count($rows) . ' entr' . (count($rows) === 1 ? 'y' : 'ies') . ' saved.';
        $backLoc = count($rows) === 1 ? '?location=' . urlencode($rows[0]['loc']) : '';
        header('Location: index.php' . $backLoc); exit;
    }

    $_SESSION['error'] = implode('<br>', array_unique($errors));
}

$formToken   = generateFormToken('wholesale_add');
$todayShamsi = wsToShamsi((int)date('Y'), (int)date('n'), (int)date('j'));
$jMonths     = ['۱ حمل','۲ ثور','۳ جوزا','۴ سرطان','۵ اسد','۶ سنبله',
                '۷ میزان','۸ عقرب','۹ قوس','۱۰ جدی','۱۱ دلو','۱۲ حوت'];

require_once '../includes/header.php';
?>

<style>
.type-toggle { display:flex; gap:10px; }
.type-toggle label {
    flex:1; border:2px solid var(--w11-border); border-radius:12px; padding:12px;
    text-align:center; cursor:pointer; font-weight:700; transition:all .15s; margin:0;
}
.type-toggle input { display:none; }
.type-toggle input:checked + label.in  { border-color:#107C10; background:rgba(16,124,16,0.07); color:#107C10; }
.type-toggle input:checked + label.out { border-color:#C42B1C; background:rgba(196,43,28,0.06); color:#C42B1C; }

.loc-quick { display:flex; flex-wrap:wrap; gap:8px; }
.loc-quick button {
    border:1px solid var(--w11-border); background:#fff; border-radius:20px;
    padding:5px 14px; font-size:.8rem; font-weight:600; cursor:pointer; color:#444;
    display:inline-flex; align-items:center; gap:6px; transition:all .15s;
}
.loc-quick button:hover { border-color:#14B8A6; color:#0d9488; }
.ws-row { display:flex; gap:10px; align-items:center; margin-bottom:10px; }
.ws-row .rm { border:none; background:transparent; color:#C42B1C; font-size:1.05rem; cursor:pointer; padding:4px 6px; border-radius:6px; }
.ws-row .rm:hover { background:rgba(196,43,28,0.08); }
.grand-box { background:rgba(20,184,166,0.08); border:1px solid rgba(20,184,166,0.25); border-radius:12px; }
</style>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <a href="index.php" class="text-muted small"><i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_wholesale') ?></a>
        <h4 class="mt-1 mb-0 d-flex align-items-center gap-2"><i class="bi bi-plus-square" style="color:#14B8A6;"></i>New Entry</h4>
    </div>
</div>

<form method="POST" id="wsForm">
<input type="hidden" name="_form_token" value="<?= htmlspecialchars($formToken) ?>">

    <div class="row g-3">
        <!-- Left: locations + amounts -->
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-body">

                    <!-- Type -->
                    <label class="form-label fw-semibold mb-2">Movement Type</label>
                    <div class="type-toggle mb-3">
                        <input type="radio" name="type" id="tIn" value="in" <?= ($_POST['type'] ?? 'in') === 'in' ? 'checked' : '' ?>>
                        <label class="in" for="tIn"><i class="bi bi-box-arrow-in-down me-1"></i>Incoming (In)</label>
                        <input type="radio" name="type" id="tOut" value="out" <?= ($_POST['type'] ?? '') === 'out' ? 'checked' : '' ?>>
                        <label class="out" for="tOut"><i class="bi bi-box-arrow-up me-1"></i>Outgoing (Out)</label>
                    </div>

                    <!-- Quick add -->
                    <label class="form-label fw-semibold mb-1">Locations &amp; Amounts <span class="text-danger">*</span></label>
                    <div class="loc-quick mb-3" id="locQuick">
                        <?php foreach (WHOLESALE_DEFAULT_LOCATIONS as $l): ?>
                        <button type="button" data-loc="<?= htmlspecialchars($l) ?>">
                            <i class="bi bi-plus-circle" style="color:<?= wsLocationColor($l) ?>;"></i><?= htmlspecialchars($l) ?>
                        </button>
                        <?php endforeach; ?>
                        <button type="button" data-loc="" style="border-style:dashed;"><i class="bi bi-plus-lg"></i>Custom</button>
                    </div>

                    <!-- Rows -->
                    <div id="wsRows"></div>

                    <div class="grand-box d-flex align-items-center justify-content-between px-3 py-2 mt-2">
                        <span class="fw-semibold">Grand Total</span>
                        <span class="fw-bold fs-6" style="color:#0d9488;" id="grandDisplay">$ 0.00</span>
                    </div>

                    <!-- Date + Notes -->
                    <div class="row g-3 mt-1">
                        <div class="col-sm-5">
                            <label class="form-label fw-semibold mb-1 d-flex align-items-center gap-2">Date
                                <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.6rem;">Solar Hijri</span></label>
                            <div class="d-flex align-items-center gap-1">
                                <input type="number" id="wsJY" class="form-control form-control-sm text-center fw-semibold"
                                       value="<?= (int)($_POST['shamsi_y'] ?? $todayShamsi['y']) ?>" min="1300" max="1600" style="width:70px;" oninput="syncWsShamsi()">
                                <span class="text-muted">/</span>
                                <select id="wsJM" class="form-select form-select-sm" style="width:118px;" onchange="syncWsShamsi()">
                                    <?php $selM = (int)($_POST['shamsi_m'] ?? $todayShamsi['m']); foreach ($jMonths as $i => $nm): ?>
                                    <option value="<?= $i+1 ?>" <?= $selM === $i+1 ? 'selected' : '' ?>><?= $nm ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="text-muted">/</span>
                                <input type="number" id="wsJD" class="form-control form-control-sm text-center fw-semibold"
                                       value="<?= (int)($_POST['shamsi_d'] ?? $todayShamsi['d']) ?>" min="1" max="31" style="width:56px;" oninput="syncWsShamsi()">
                            </div>
                            <input type="hidden" name="entry_date" id="wsDateHidden" value="<?= htmlspecialchars($_POST['entry_date'] ?? date('Y-m-d')) ?>">
                            <div id="wsGregBadge" class="mt-1 text-muted" style="font-size:.71rem;"></div>
                        </div>
                        <div class="col-sm-7">
                            <label class="form-label fw-semibold mb-1">Notes <span class="text-muted fw-normal small">(optional)</span></label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Container #, transport, remarks…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Right: submit -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small mb-2">Each location you add is saved as its own record with its own amount.</div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-lg fw-semibold" style="background:#14B8A6;color:#fff;">
                            <i class="bi bi-check-circle me-2"></i>Save Entry
                        </button>
                        <a href="index.php" class="btn btn-light"><?= __('btn_cancel') ?></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Row template -->
<datalist id="ws-loc-list">
    <?php foreach ($knownLocations as $l): ?><option value="<?= htmlspecialchars($l) ?>"></option><?php endforeach; ?>
</datalist>

<script>
function shamsiToGregorian(jy, jm, jd) {
    var breaks = [-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];
    var gy = jy + 621, leapJ = -14, jp = breaks[0], jm2, jump, n, i;
    for (i = 1; i < 20; i++) { jm2 = breaks[i]; jump = jm2 - jp; if (jy < jm2) break; leapJ += Math.floor(jump/33)*8 + Math.floor((jump%33)/4); jp = jm2; }
    n = jy - jp;
    leapJ += Math.floor(n/33)*8 + Math.floor((n%33+3)/4);
    if ((jump % 33) === 4 && (jump - n) === 4) leapJ++;
    var leapG = Math.floor(gy/4) - Math.floor((Math.floor(gy/100)+1)*3/4) - 150;
    var march = 20 + leapJ - leapG;
    var dayOfYear = jm <= 6 ? (jm-1)*31 + jd : 186 + (jm-7)*30 + jd;
    var gDay = march + dayOfYear - 1, gMon = 3, gYr = gy;
    function dim(m,y){ if(m===2) return ((y%4===0&&y%100!==0)||y%400===0)?29:28; return [0,31,28,31,30,31,30,31,31,30,31,30,31][m]; }
    while (gDay > dim(gMon,gYr)) { gDay -= dim(gMon,gYr); if (++gMon>12){ gMon=1; gYr++; } }
    return {y:gYr, m:gMon, d:gDay};
}
function syncWsShamsi() {
    var jy = parseInt(document.getElementById('wsJY').value)||0, jm = parseInt(document.getElementById('wsJM').value)||0, jd = parseInt(document.getElementById('wsJD').value)||0;
    if (jy<1300||jy>1600||jm<1||jm>12||jd<1||jd>31) return;
    var g = shamsiToGregorian(jy,jm,jd); if (!g) return;
    document.getElementById('wsDateHidden').value = g.y + '-' + String(g.m).padStart(2,'0') + '-' + String(g.d).padStart(2,'0');
    document.getElementById('wsGregBadge').textContent = '≡ ' + g.y + '/' + String(g.m).padStart(2,'0') + '/' + String(g.d).padStart(2,'0') + ' (Gregorian)';
}

const rowsBox = document.getElementById('wsRows');

function addRow(loc = '', amt = '') {
    const div = document.createElement('div');
    div.className = 'ws-row';
    div.innerHTML =
        '<input list="ws-loc-list" name="location[]" class="form-control" placeholder="Location (Kabul, China, Lahore, Gami…)" autocomplete="off" value="' + loc.replace(/"/g,'&quot;') + '">'
        + '<div class="input-group" style="max-width:200px;">'
        + '<span class="input-group-text">$</span>'
        + '<input type="number" name="amount[]" class="form-control fw-semibold ws-amt" min="0" step="any" placeholder="Amount" value="' + amt + '" oninput="calcGrand()">'
        + '</div>'
        + '<button type="button" class="rm" title="Remove" onclick="this.closest(\'.ws-row\').remove(); calcGrand();"><i class="bi bi-x-lg"></i></button>';
    rowsBox.appendChild(div);
    return div;
}

function calcGrand() {
    let g = 0;
    document.querySelectorAll('.ws-amt').forEach(el => g += parseFloat(el.value) || 0);
    document.getElementById('grandDisplay').textContent = '$ ' + g.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}

// Quick-add chips
document.querySelectorAll('#locQuick button').forEach(b => {
    b.addEventListener('click', () => {
        const loc = b.dataset.loc;
        // If an empty row exists, fill it; otherwise add a new one
        let target = null;
        document.querySelectorAll('.ws-row').forEach(r => {
            const li = r.querySelector('input[name="location[]"]');
            const ai = r.querySelector('input[name="amount[]"]');
            if (!target && li.value.trim() === '' && (ai.value.trim() === '' || parseFloat(ai.value) === 0)) target = r;
        });
        const row = target || addRow();
        if (loc) row.querySelector('input[name="location[]"]').value = loc;
        row.querySelector(loc ? 'input[name="amount[]"]' : 'input[name="location[]"]').focus();
    });
});

// Seed
<?php if ($preLoc !== ''): ?>
addRow(<?= json_encode($preLoc) ?>);
<?php else: ?>
addRow();
<?php endif; ?>
syncWsShamsi();
calcGrand();

document.getElementById('wsForm').addEventListener('submit', function () {
    const btn = this.querySelector('[type=submit]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Saving…'; }
});
</script>

<?php require_once '../includes/footer.php'; ?>
