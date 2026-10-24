<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/telegram.php';
require_once '../includes/period.php';
require_once '../accessories/helpers.php';
require_once 'helpers.php';

ensureAccessoriesTables($pdo);       // makes sure accessory_owners exists
ensureAccessoryCustomerTables($pdo);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM accessory_owners WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
    $_SESSION['error'] = 'Accessory owner not found.';
    header('Location: index.php'); exit;
}

$pageTitle = $customer['name'] . ' - Accessory Customer';

// ── Delete one transaction ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_txn') {
    if (!validateFormToken('acc_cust_delete_' . $id)) {
        $_SESSION['error'] = 'Duplicate submission detected.';
        header('Location: customer.php?id=' . $id); exit;
    }
    $tid = (int)($_POST['txn_id'] ?? 0);
    if ($tid > 0) {
        $stmt = $pdo->prepare("DELETE FROM accessory_customer_txns WHERE customer_id = ? AND id = ?");
        $stmt->execute([$id, $tid]);
        tgNotify("🗑 <b>Accessory bill deleted</b>\nCustomer: " . tgEsc($customer['name']) . "\nBy: " . tgActor(), 'acc_customer');
        $_SESSION['success'] = 'Transaction deleted.';
    }
    header('Location: customer.php?id=' . $id); exit;
}

// ── Add transaction ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateFormToken('acc_cust_txn_' . $id)) {
        $_SESSION['error'] = 'Duplicate submission detected. Your data was already saved.';
        header('Location: customer.php?id=' . $id); exit;
    }

    $date = trim($_POST['txn_date'] ?? '') ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    $billNo       = trim($_POST['bill_no'] ?? '') ?: null;
    $itemName     = trim($_POST['item_name'] ?? '');
    $tanQty       = accCustDecimalOrNull($_POST['tan_quantity'] ?? null);
    $demandWeight = accCustDecimalOrNull($_POST['demand_weight'] ?? null);
    $notes        = trim($_POST['notes'] ?? '') ?: null;

    $photo = accessorySaveSinglePhoto('bill_photo');   // reused from accessories/helpers.php

    if ($itemName === '') {
        $_SESSION['error'] = 'Item name is required.';
    } elseif ($photo['error'] !== null) {
        $_SESSION['error'] = $photo['error'];
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO accessory_customer_txns
                (customer_id, txn_date, bill_no, item_name, tan_quantity, demand_weight, bill_photo, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$id, $date, $billNo, $itemName, $tanQty, $demandWeight, $photo['file'], $notes, $_SESSION['user_id'] ?? null]);
        tgNotify("🧾 <b>Accessory bill added</b>\nCustomer: " . tgEsc($customer['name'])
            . "\nItem: " . tgEsc($itemName)
            . ($billNo ? "\nBill: " . tgEsc($billNo) : '')
            . ($tanQty !== null ? "\nTan Qty: <b>" . number_format($tanQty, 2) . "</b>" : '')
            . ($demandWeight !== null ? "\nDemand: <b>" . number_format($demandWeight, 2) . "</b>" : '')
            . "\nBy: " . tgActor(), 'acc_customer');
        $_SESSION['success'] = 'Transaction saved.';
        header('Location: customer.php?id=' . $id); exit;
    }
    header('Location: customer.php?id=' . $id); exit;
}

// ── Time-period filter (Today / Week / Month / All / Custom) ──
$pf    = periodResolve();                                             // default: All Time
$condT = periodCond("COALESCE(txn_date, DATE(created_at))", $pf);

$txnStmt = $pdo->prepare("
    SELECT *
    FROM accessory_customer_txns
    WHERE customer_id = ? AND ($condT)
    ORDER BY COALESCE(txn_date, DATE(created_at)) DESC, id DESC
");
$txnStmt->execute([$id]);
$txns = $txnStmt->fetchAll();

$totals = [
    'count'  => count($txns),
    'tan'    => array_sum(array_map(fn($t) => (float)$t['tan_quantity'], $txns)),
    'demand' => array_sum(array_map(fn($t) => (float)$t['demand_weight'], $txns)),
];

$todayShamsi  = accCustToShamsi((int)date('Y'), (int)date('n'), (int)date('j'));
$jMonths      = accCustShamsiMonths();

$formToken   = generateFormToken('acc_cust_txn_' . $id);
$deleteToken = generateFormToken('acc_cust_delete_' . $id);

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <a href="index.php" class="text-muted small">
            <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_acc_customers') ?>
        </a>
        <h4 class="mt-1 mb-0"><?= htmlspecialchars($customer['name']) ?></h4>
        <p class="text-muted small mb-0"><?= htmlspecialchars($customer['phone'] ?? '') ?></p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#txnModal">
            <i class="bi bi-receipt me-2"></i>Add Bill
        </button>
    </div>
</div>

<!-- Time-period filter -->
<?= periodBar($pf, ['id' => $id]) ?>

<div class="row g-3 mb-4">
    <div class="col-4">
        <div class="card border-0" style="background:rgba(244,114,182,0.12);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Bills</div>
                <div class="fs-4 fw-bold" style="color:#DB2777;"><?= number_format($totals['count']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0" style="background:rgba(34,211,238,0.12);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Tan Quantity</div>
                <div class="fs-4 fw-bold" style="color:#0E7490;"><?= number_format($totals['tan'], 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0" style="background:rgba(124,58,237,0.12);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Demand Weight</div>
                <div class="fs-4 fw-bold" style="color:#7C3AED;"><?= number_format($totals['demand'], 2) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 fw-semibold d-flex align-items-center justify-content-between">
        <span><i class="bi bi-receipt-cutoff me-2 text-primary"></i>Bills / Transactions</span>
        <span class="text-muted small fw-normal"><?= count($txns) ?> record(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>تاریخ / Date</th>
                    <th>بل / Bill No</th>
                    <th>جنس / Item Name</th>
                    <th class="text-end">Tan Quantity</th>
                    <th class="text-end">Demand Weight</th>
                    <th>Bill Image</th>
                    <th class="d-none d-md-table-cell">Note</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($txns)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-5">No bills yet. Add the first bill for this customer.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($txns as $t): ?>
                <tr>
                    <td class="text-muted small"><?= htmlspecialchars(accCustShamsiDate($t['txn_date'])) ?></td>
                    <td><?= htmlspecialchars($t['bill_no'] ?? '') ?: '—' ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($t['item_name']) ?></td>
                    <td class="text-end"><?= $t['tan_quantity'] !== null ? number_format((float)$t['tan_quantity'], 2) : '-' ?></td>
                    <td class="text-end" style="color:#7C3AED;"><?= $t['demand_weight'] !== null ? number_format((float)$t['demand_weight'], 2) : '-' ?></td>
                    <td>
                        <?php if (!empty($t['bill_photo'])): ?>
                        <a href="/uploads/accessory-bills/<?= htmlspecialchars($t['bill_photo']) ?>" target="_blank" title="View bill image">
                            <img src="/uploads/accessory-bills/<?= htmlspecialchars($t['bill_photo']) ?>" alt="bill" style="height:34px;width:34px;object-fit:cover;border-radius:6px;border:1px solid rgba(0,0,0,.1);">
                        </a>
                        <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                    </td>
                    <td class="d-none d-md-table-cell text-muted small"><?= htmlspecialchars($t['notes'] ?? '') ?></td>
                    <td class="text-end">
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this bill? This cannot be undone.');">
                            <input type="hidden" name="_form_token" value="<?= htmlspecialchars($deleteToken) ?>">
                            <input type="hidden" name="action" value="delete_txn">
                            <input type="hidden" name="txn_id" value="<?= (int)$t['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($txns)): ?>
            <tfoot>
                <tr class="table-light fw-bold">
                    <td colspan="3" class="text-end">ټول / Total</td>
                    <td class="text-end"><?= number_format($totals['tan'], 2) ?></td>
                    <td class="text-end" style="color:#7C3AED;"><?= number_format($totals['demand'], 2) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<div class="modal fade" id="txnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="_form_token" value="<?= htmlspecialchars($formToken) ?>">
            <div class="modal-header">
                <h5 class="modal-title">Add Bill — <?= htmlspecialchars($customer['name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">جنس / Item Name</label>
                        <input type="text" name="item_name" class="form-control" required autofocus>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold"><i class="bi bi-receipt me-1 text-primary"></i>بل / Bill No</label>
                        <input type="text" name="bill_no" class="form-control" placeholder="Bill #">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Tan Quantity</label>
                        <input type="number" step="any" min="0" name="tan_quantity" class="form-control" placeholder="e.g. 12.5">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Demand Weight</label>
                        <input type="number" step="any" min="0" name="demand_weight" class="form-control">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><i class="bi bi-image me-1 text-primary"></i>Bill Image</label>
                    <input type="file" name="bill_photo" id="txnPhoto" accept="image/*" class="form-control">
                    <div id="txnPhotoPreview" class="mt-2 text-center" style="display:none;">
                        <img src="" alt="preview" style="max-height:160px;max-width:100%;border-radius:8px;border:1px solid rgba(0,0,0,.1);">
                        <div><button type="button" id="txnPhotoClear" class="btn btn-sm btn-link text-danger p-0 mt-1">Remove image</button></div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold d-flex align-items-center gap-2">
                        <i class="bi bi-calendar3 me-1 text-primary"></i>تاریخ
                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:0.62rem;">Solar Hijri</span>
                    </label>
                    <div class="d-flex align-items-center gap-1">
                        <input type="number" id="txnJYear" class="form-control form-control-sm text-center fw-semibold"
                               value="<?= $todayShamsi['y'] ?>" min="1300" max="1600" style="width:80px;" oninput="syncTxnShamsi()">
                        <span class="text-muted">/</span>
                        <select id="txnJMonth" class="form-select form-select-sm" style="width:140px;" onchange="syncTxnShamsi()">
                            <?php foreach ($jMonths as $i => $nm): ?>
                            <option value="<?= $i+1 ?>" <?= $todayShamsi['m'] === $i+1 ? 'selected' : '' ?>><?= $nm ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="text-muted">/</span>
                        <input type="number" id="txnJDay" class="form-control form-control-sm text-center fw-semibold"
                               value="<?= $todayShamsi['d'] ?>" min="1" max="31" style="width:64px;" oninput="syncTxnShamsi()">
                    </div>
                    <input type="hidden" name="txn_date" id="txnDateHidden" value="<?= date('Y-m-d') ?>">
                    <div id="txnGregorianBadge" class="mt-1 text-muted" style="font-size:0.71rem;"></div>
                </div>
                <div class="mb-1">
                    <label class="form-label fw-semibold">Note</label>
                    <input type="text" name="notes" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= __('btn_cancel') ?></button>
                <button class="btn btn-primary"><i class="bi bi-check2-circle me-2"></i><?= __('btn_save') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function accCustShamsiToGregorian(jy, jm, jd) {
    var breaks = [-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];
    var gy = jy + 621, leapJ = -14, jp = breaks[0], jm2, jump, n, i;
    for (i = 1; i < 20; i++) {
        jm2 = breaks[i]; jump = jm2 - jp;
        if (jy < jm2) break;
        leapJ += Math.floor(jump / 33) * 8 + Math.floor((jump % 33) / 4);
        jp = jm2;
    }
    n = jy - jp;
    leapJ += Math.floor(n / 33) * 8 + Math.floor((n % 33 + 3) / 4);
    if ((jump % 33) === 4 && (jump - n) === 4) leapJ++;
    var leapG = Math.floor(gy / 4) - Math.floor((Math.floor(gy / 100) + 1) * 3 / 4) - 150;
    var march = 20 + leapJ - leapG;
    var dayOfYear = jm <= 6 ? (jm - 1) * 31 + jd : 186 + (jm - 7) * 30 + jd;
    var gDay = march + dayOfYear - 1, gMon = 3, gYr = gy;
    function dim(m, y) {
        if (m === 2) return ((y%4===0&&y%100!==0)||y%400===0) ? 29 : 28;
        return [0,31,28,31,30,31,30,31,31,30,31,30,31][m];
    }
    while (gDay > dim(gMon, gYr)) { gDay -= dim(gMon, gYr); if (++gMon > 12) { gMon = 1; gYr++; } }
    return {y: gYr, m: gMon, d: gDay};
}
function syncTxnShamsi() {
    var jy = parseInt(document.getElementById('txnJYear').value)  || 0;
    var jm = parseInt(document.getElementById('txnJMonth').value) || 0;
    var jd = parseInt(document.getElementById('txnJDay').value)   || 0;
    if (jy < 1300 || jy > 1600 || jm < 1 || jm > 12 || jd < 1 || jd > 31) return;
    var g = accCustShamsiToGregorian(jy, jm, jd);
    if (!g) return;
    var gStr = g.y + '-' + String(g.m).padStart(2,'0') + '-' + String(g.d).padStart(2,'0');
    document.getElementById('txnDateHidden').value = gStr;
    document.getElementById('txnGregorianBadge').textContent =
        '≡ ' + g.y + '/' + String(g.m).padStart(2,'0') + '/' + String(g.d).padStart(2,'0') + ' (Gregorian)';
}
syncTxnShamsi();

(function () {
    const input   = document.getElementById('txnPhoto');
    const preview = document.getElementById('txnPhotoPreview');
    const img     = preview ? preview.querySelector('img') : null;
    const clear   = document.getElementById('txnPhotoClear');
    if (!input || !preview || !img) return;

    input.addEventListener('change', function () {
        const file = input.files && input.files[0];
        if (!file || !file.type.startsWith('image/')) { preview.style.display = 'none'; img.src = ''; return; }
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; preview.style.display = ''; };
        reader.readAsDataURL(file);
    });

    clear?.addEventListener('click', function () {
        input.value = '';
        img.src = '';
        preview.style.display = 'none';
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
