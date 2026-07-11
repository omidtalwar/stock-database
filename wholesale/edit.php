<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';
require_once '_common.php';

$pageTitle = 'Edit Wholesale Entry';

$id  = (int)($_GET['id'] ?? 0);
$row = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM wholesale_logs WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
}
if (!$row) { $_SESSION['error'] = 'Entry not found.'; header('Location: index.php'); exit; }

$knownLocations = wsKnownLocations($pdo);
$knownItems     = $pdo->query("SELECT DISTINCT item_name FROM wholesale_logs WHERE item_name IS NOT NULL AND item_name != '' ORDER BY item_name ASC")->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $location  = trim($_POST['location'] ?? '');
    $item      = trim($_POST['item_name'] ?? '');
    $category  = trim($_POST['category'] ?? '');
    $type      = in_array($_POST['type'] ?? '', ['in','out'], true) ? $_POST['type'] : 'in';
    $qty       = (float)($_POST['quantity'] ?? 0);
    $bundles   = (int)($_POST['bundle_count'] ?? 0);
    $unitPrice = (float)($_POST['unit_price'] ?? 0);
    $totalVal  = (float)($_POST['total_value'] ?? 0);
    $notes     = trim($_POST['notes'] ?? '');
    $entryDate = trim($_POST['entry_date'] ?? '') ?: null;
    if ($entryDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) $entryDate = null;
    if ($totalVal <= 0 && $unitPrice > 0 && $qty > 0) $totalVal = $unitPrice * $qty;

    $errors = [];
    if ($location === '') $errors[] = 'Location is required.';
    if ($qty <= 0)        $errors[] = 'Quantity must be greater than 0.';

    if (empty($errors)) {
        $pdo->prepare("
            UPDATE wholesale_logs SET
                location=?, item_name=?, category=?, type=?, quantity=?, bundle_count=?,
                unit_price=?, total_value=?, notes=?, entry_date=?
            WHERE id=?
        ")->execute([
            $location, $item ?: null, $category ?: null, $type, $qty, $bundles ?: null,
            $unitPrice ?: null, $totalVal ?: null, $notes ?: null, $entryDate, $id,
        ]);
        $_SESSION['success'] = 'Entry updated.';
        header('Location: index.php?location=' . urlencode($location)); exit;
    }
    $_SESSION['error'] = implode('<br>', $errors);
    $row = array_merge($row, $_POST);
}

$curDate     = !empty($row['entry_date']) ? $row['entry_date'] : substr($row['created_at'], 0, 10);
$sh          = wsToShamsi((int)date('Y', strtotime($curDate)), (int)date('n', strtotime($curDate)), (int)date('j', strtotime($curDate)));
$jMonths     = ['۱ حمل','۲ ثور','۳ جوزا','۴ سرطان','۵ اسد','۶ سنبله',
                '۷ میزان','۸ عقرب','۹ قوس','۱۰ جدی','۱۱ دلو','۱۲ حوت'];

require_once '../includes/header.php';
?>

<style>
.type-toggle { display:flex; gap:10px; }
.type-toggle label { flex:1; border:2px solid var(--w11-border); border-radius:12px; padding:12px; text-align:center; cursor:pointer; font-weight:700; transition:all .15s; margin:0; }
.type-toggle input { display:none; }
.type-toggle input:checked + label.in  { border-color:#107C10; background:rgba(16,124,16,0.07); color:#107C10; }
.type-toggle input:checked + label.out { border-color:#C42B1C; background:rgba(196,43,28,0.06); color:#C42B1C; }
</style>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <a href="index.php" class="text-muted small"><i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_wholesale') ?></a>
        <h4 class="mt-1 mb-0 d-flex align-items-center gap-2"><i class="bi bi-pencil-square" style="color:#14B8A6;"></i>Edit Entry</h4>
    </div>
    <a href="delete.php?id=<?= $id ?>" class="btn btn-outline-danger" onclick="return confirm('Delete this entry?')">
        <i class="bi bi-trash me-1"></i>Delete
    </a>
</div>

<form method="POST" id="wsForm">
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3"><div class="card-body">

                <label class="form-label fw-semibold mb-2">Movement Type</label>
                <div class="type-toggle mb-3">
                    <input type="radio" name="type" id="tIn" value="in" <?= $row['type'] === 'in' ? 'checked' : '' ?>>
                    <label class="in" for="tIn"><i class="bi bi-box-arrow-in-down me-1"></i>Incoming (In)</label>
                    <input type="radio" name="type" id="tOut" value="out" <?= $row['type'] === 'out' ? 'checked' : '' ?>>
                    <label class="out" for="tOut"><i class="bi bi-box-arrow-up me-1"></i>Outgoing (Out)</label>
                </div>

                <label class="form-label fw-semibold mb-1">Location <span class="text-danger">*</span></label>
                <input list="ws-loc-list" type="text" name="location" class="form-control" required autocomplete="off"
                       value="<?= htmlspecialchars($row['location']) ?>">
                <datalist id="ws-loc-list">
                    <?php foreach ($knownLocations as $l): ?><option value="<?= htmlspecialchars($l) ?>"></option><?php endforeach; ?>
                </datalist>

                <hr class="my-3">

                <div class="row g-3">
                    <div class="col-sm-7">
                        <label class="form-label fw-semibold mb-1">Item / Product</label>
                        <input list="ws-item-list" type="text" name="item_name" class="form-control" autocomplete="off"
                               value="<?= htmlspecialchars($row['item_name'] ?? '') ?>">
                        <datalist id="ws-item-list">
                            <?php foreach ($knownItems as $it): ?><option value="<?= htmlspecialchars($it) ?>"></option><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-sm-5">
                        <label class="form-label fw-semibold mb-1">Category</label>
                        <input type="text" name="category" class="form-control" value="<?= htmlspecialchars($row['category'] ?? '') ?>">
                    </div>
                </div>

                <div class="row g-3 mt-0">
                    <div class="col-6 col-sm-4">
                        <label class="form-label fw-semibold mb-1">Quantity (pcs) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="quantity" id="wsQty" class="form-control fw-semibold" min="0" step="any"
                                   value="<?= htmlspecialchars($row['quantity']) ?>" oninput="calcVal()" required>
                            <span class="input-group-text">pcs</span>
                        </div>
                    </div>
                    <div class="col-6 col-sm-4">
                        <label class="form-label fw-semibold mb-1">Bundles</label>
                        <input type="number" name="bundle_count" class="form-control" min="0" value="<?= htmlspecialchars($row['bundle_count'] ?? '') ?>">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label fw-semibold mb-1 d-flex align-items-center gap-2">Date
                            <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.6rem;">Solar Hijri</span></label>
                        <div class="d-flex align-items-center gap-1">
                            <input type="number" id="wsJY" class="form-control form-control-sm text-center fw-semibold" value="<?= $sh['y'] ?>" min="1300" max="1600" style="width:70px;" oninput="syncWsShamsi()">
                            <span class="text-muted">/</span>
                            <select id="wsJM" class="form-select form-select-sm" style="width:118px;" onchange="syncWsShamsi()">
                                <?php foreach ($jMonths as $i => $nm): ?><option value="<?= $i+1 ?>" <?= $sh['m'] === $i+1 ? 'selected' : '' ?>><?= $nm ?></option><?php endforeach; ?>
                            </select>
                            <span class="text-muted">/</span>
                            <input type="number" id="wsJD" class="form-control form-control-sm text-center fw-semibold" value="<?= $sh['d'] ?>" min="1" max="31" style="width:56px;" oninput="syncWsShamsi()">
                        </div>
                        <input type="hidden" name="entry_date" id="wsDateHidden" value="<?= htmlspecialchars($curDate) ?>">
                        <div id="wsGregBadge" class="mt-1 text-muted" style="font-size:.71rem;"></div>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label fw-semibold mb-1">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($row['notes'] ?? '') ?></textarea>
                </div>

            </div></div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header fw-semibold py-2 d-flex align-items-center gap-2"><i class="bi bi-cash-stack" style="color:#107C10;"></i> Value</div>
                <div class="card-body">
                    <label class="form-label fw-semibold small mb-1">Unit Price</label>
                    <div class="input-group input-group-sm mb-3">
                        <span class="input-group-text">$</span>
                        <input type="number" name="unit_price" id="wsUnit" class="form-control" min="0" step="any" value="<?= htmlspecialchars($row['unit_price'] ?? '') ?>" oninput="calcVal()">
                    </div>
                    <label class="form-label fw-semibold small mb-1">Total Value</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">$</span>
                        <input type="number" name="total_value" id="wsTotal" class="form-control fw-bold" min="0" step="any" value="<?= htmlspecialchars($row['total_value'] ?? '') ?>" oninput="manualTotal=true">
                    </div>
                </div>
            </div>
            <div class="d-grid gap-2 mt-3">
                <button type="submit" class="btn btn-lg fw-semibold" style="background:#14B8A6;color:#fff;"><i class="bi bi-check-circle me-2"></i>Update</button>
                <a href="index.php" class="btn btn-light"><?= __('btn_cancel') ?></a>
            </div>
        </div>
    </div>
</form>

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
let manualTotal = false;
function calcVal() {
    if (manualTotal) return;
    var qty = parseFloat(document.getElementById('wsQty').value)||0, unit = parseFloat(document.getElementById('wsUnit').value)||0, t = qty*unit;
    document.getElementById('wsTotal').value = t > 0 ? t.toFixed(2) : '';
}
syncWsShamsi();
document.getElementById('wsForm').addEventListener('submit', function () {
    const btn = this.querySelector('[type=submit]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Saving…'; }
});
</script>

<?php require_once '../includes/footer.php'; ?>
