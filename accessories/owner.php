<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once 'helpers.php';

ensureAccessoriesTables($pdo);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM accessory_owners WHERE id = ?");
$stmt->execute([$id]);
$owner = $stmt->fetch();

if (!$owner) {
    $_SESSION['error'] = 'Accessory owner not found.';
    header('Location: index.php'); exit;
}

$pageTitle = $owner['name'] . ' - Accessories';

$categories = accessoryCategories();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stock_in') {
    if (!validateFormToken('accessory_stockin_' . $id)) {
        $_SESSION['error'] = 'Duplicate submission detected. Your data was already saved.';
        header('Location: owner.php?id=' . $id); exit;
    }

    $category = $_POST['category'] ?? '';
    $quantity = (float)($_POST['quantity'] ?? 0);
    $inDate   = trim($_POST['in_date'] ?? '') ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inDate)) $inDate = date('Y-m-d');
    $billNo   = trim($_POST['bill_no'] ?? '') ?: null;
    $note     = trim($_POST['note'] ?? '') ?: null;

    if (!isset($categories[$category])) {
        $_SESSION['error'] = 'Choose a valid stock type.';
    } elseif ($quantity <= 0) {
        $_SESSION['error'] = 'Stock quantity must be greater than zero.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO accessory_stock_ins (owner_id, in_date, bill_no, category, quantity, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$id, $inDate, $billNo, $category, $quantity, $note, $_SESSION['user_id'] ?? null]);
        $_SESSION['success'] = number_format($quantity, 2) . ' ' . $categories[$category]['label'] . ' stock added.';
        header('Location: owner.php?id=' . $id); exit;
    }
    header('Location: owner.php?id=' . $id); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'payment') {
    if (!validateFormToken('accessory_payment_' . $id)) {
        $_SESSION['error'] = 'Duplicate submission detected. Your data was already saved.';
        header('Location: owner.php?id=' . $id); exit;
    }

    $kind     = ($_POST['kind'] ?? 'payment') === 'charge' ? 'charge' : 'payment';
    $amount   = (float)($_POST['amount'] ?? 0);
    $payDate  = trim($_POST['entry_date'] ?? '') ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) $payDate = date('Y-m-d');
    $billNo   = trim($_POST['bill_no'] ?? '') ?: null;
    $note     = trim($_POST['note'] ?? '') ?: null;

    if ($amount <= 0) {
        $_SESSION['error'] = 'Amount must be greater than zero.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO accessory_payments (owner_id, entry_date, bill_no, kind, amount, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$id, $payDate, $billNo, $kind, $amount, $note, $_SESSION['user_id'] ?? null]);
        $_SESSION['success'] = $kind === 'charge'
            ? 'Previous amount of ؋ ' . number_format($amount, 2) . ' added.'
            : 'Payment of ؋ ' . number_format($amount, 2) . ' recorded.';
        header('Location: owner.php?id=' . $id); exit;
    }
    header('Location: owner.php?id=' . $id); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_bill') {
    if (!validateFormToken('accessory_delete_' . $id)) {
        $_SESSION['error'] = 'Duplicate submission detected.';
        header('Location: owner.php?id=' . $id); exit;
    }
    $group = trim($_POST['bill_group'] ?? '');
    $eid   = (int)($_POST['entry_id'] ?? 0);
    if ($group !== '') {
        $stmt = $pdo->prepare("DELETE FROM accessory_stock_entries WHERE owner_id = ? AND bill_group = ?");
        $stmt->execute([$id, $group]);
    } elseif ($eid > 0) {
        $stmt = $pdo->prepare("DELETE FROM accessory_stock_entries WHERE owner_id = ? AND id = ?");
        $stmt->execute([$id, $eid]);
    }
    $_SESSION['success'] = 'Bill deleted.';
    header('Location: owner.php?id=' . $id); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_payment') {
    if (!validateFormToken('accessory_delete_' . $id)) {
        $_SESSION['error'] = 'Duplicate submission detected.';
        header('Location: owner.php?id=' . $id); exit;
    }
    $pid = (int)($_POST['payment_id'] ?? 0);
    if ($pid > 0) {
        $stmt = $pdo->prepare("DELETE FROM accessory_payments WHERE owner_id = ? AND id = ?");
        $stmt->execute([$id, $pid]);
    }
    $_SESSION['success'] = 'Payment deleted.';
    header('Location: owner.php?id=' . $id); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateFormToken('accessory_entry_' . $id)) {
        $_SESSION['error'] = 'Duplicate submission detected. Your data was already saved.';
        header('Location: owner.php?id=' . $id); exit;
    }

    $date     = trim($_POST['entry_date'] ?? '') ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    $billNo   = trim($_POST['bill_no'] ?? '') ?: null;

    $itemNames = $_POST['item_name'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $originalSizes = $_POST['original_size'] ?? [];
    $coffeeSizes = $_POST['coffee_size'] ?? [];
    $pesSizes = $_POST['pes_size'] ?? [];
    $plasticSizes = $_POST['plastic_size'] ?? [];
    $meterages = $_POST['meterage'] ?? [];
    $rates = $_POST['rate'] ?? [];
    $notes = $_POST['notes'] ?? [];

    $rows = [];
    $rowCount = max(
        count($itemNames),
        count($quantities),
        count($originalSizes),
        count($coffeeSizes),
        count($pesSizes),
        count($plasticSizes),
        count($meterages),
        count($rates),
        count($notes)
    );

    for ($i = 0; $i < $rowCount; $i++) {
        $itemName = trim($itemNames[$i] ?? '');
        $quantity = (float)($quantities[$i] ?? 0);
        $originalSize = decimalOrNull($originalSizes[$i] ?? null);
        $coffeeSize = decimalOrNull($coffeeSizes[$i] ?? null);
        $pesSize = decimalOrNull($pesSizes[$i] ?? null);
        $plasticSize = decimalOrNull($plasticSizes[$i] ?? null);
        $meterage = decimalOrNull($meterages[$i] ?? null);
        $rate = decimalOrNull($rates[$i] ?? null);

        $hasAnyValue = $itemName !== '' || $quantity > 0 || $originalSize !== null || $coffeeSize !== null
            || $pesSize !== null || $plasticSize !== null || $meterage !== null || $rate !== null;

        if (!$hasAnyValue) {
            continue;
        }

        if ($itemName === '' || $quantity <= 0) {
            $_SESSION['error'] = 'Every filled row needs quantity and item name.';
            header('Location: owner.php?id=' . $id); exit;
        }

        $photo = accessorySaveBillPhoto($i);
        if ($photo['error'] !== null) {
            $_SESSION['error'] = $photo['error'];
            header('Location: owner.php?id=' . $id); exit;
        }

        $rows[] = [
            'item_name' => $itemName,
            'quantity' => $quantity,
            'bill_photo' => $photo['file'],
            'original_size' => $originalSize,
            'coffee_size' => $coffeeSize,
            'pes_size' => $pesSize,
            'plastic_size' => $plasticSize,
            'meterage' => $meterage,
            'rate' => $rate,
            'total_amount' => ($meterage ?? 0) * ($rate ?? 0),
            'notes' => trim($notes[$i] ?? '') ?: null,
        ];
    }

    if (empty($rows)) {
        $_SESSION['error'] = 'Add at least one accessory row.';
    } else {
        $billGroup = bin2hex(random_bytes(8));
        $stmt = $pdo->prepare("
            INSERT INTO accessory_stock_entries
                (owner_id, entry_date, bill_no, bill_group, item_name, quantity, original_size, coffee_size,
                 pes_size, plastic_size, meterage, rate, total_amount, notes, bill_photo, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($rows as $row) {
            $stmt->execute([
                $id, $date, $billNo, $billGroup, $row['item_name'], $row['quantity'],
                $row['original_size'], $row['coffee_size'], $row['pes_size'],
                $row['plastic_size'], $row['meterage'], $row['rate'],
                $row['total_amount'], $row['notes'], $row['bill_photo'], $_SESSION['user_id'] ?? null,
            ]);
        }
        $_SESSION['success'] = count($rows) . ' accessory row(s) saved.';
        header('Location: owner.php?id=' . $id); exit;
    }
}

$entriesStmt = $pdo->prepare("
    SELECT *
    FROM accessory_stock_entries
    WHERE owner_id = ?
    ORDER BY COALESCE(entry_date, DATE(created_at)) DESC, id DESC
");
$entriesStmt->execute([$id]);
$entries = $entriesStmt->fetchAll();

// Group entry rows into individual bills (one "Add Bill" submission = one bill_group).
// Legacy rows without a bill_group are shown as their own single-row bill.
$bills = [];
foreach ($entries as $e) {
    $key = !empty($e['bill_group']) ? 'g:' . $e['bill_group'] : 'r:' . $e['id'];
    if (!isset($bills[$key])) {
        $bills[$key] = [
            'group'    => !empty($e['bill_group']) ? $e['bill_group'] : null,
            'first_id' => (int)$e['id'],
            'date'     => $e['entry_date'],
            'bill_no'  => $e['bill_no'],
            'rows'     => [],
            't'        => ['quantity'=>0,'original'=>0,'coffee'=>0,'pes'=>0,'plastic'=>0,'meterage'=>0,'amount'=>0],
        ];
    }
    $bills[$key]['rows'][] = $e;
    $bills[$key]['t']['quantity'] += (float)$e['quantity'];
    $bills[$key]['t']['original'] += (float)$e['original_size'];
    $bills[$key]['t']['coffee']   += (float)$e['coffee_size'];
    $bills[$key]['t']['pes']      += (float)$e['pes_size'];
    $bills[$key]['t']['plastic']  += (float)$e['plastic_size'];
    $bills[$key]['t']['meterage'] += (float)$e['meterage'];
    $bills[$key]['t']['amount']   += (float)$e['total_amount'];
}

$totals = [
    'rows' => count($entries),
    'quantity' => array_sum(array_map(fn($e) => (float)$e['quantity'], $entries)),
    'amount' => array_sum(array_map(fn($e) => (float)$e['total_amount'], $entries)),
    'original' => array_sum(array_map(fn($e) => (float)$e['original_size'], $entries)),
    'coffee' => array_sum(array_map(fn($e) => (float)$e['coffee_size'], $entries)),
    'pes' => array_sum(array_map(fn($e) => (float)$e['pes_size'], $entries)),
    'plastic' => array_sum(array_map(fn($e) => (float)$e['plastic_size'], $entries)),
    'meterage' => array_sum(array_map(fn($e) => (float)$e['meterage'], $entries)),
];
$avgRate = $totals['meterage'] > 0 ? $totals['amount'] / $totals['meterage'] : 0;

// Stock added per category after registration (stock-ins).
$addStmt = $pdo->prepare("
    SELECT category, COALESCE(SUM(quantity), 0) AS added
    FROM accessory_stock_ins
    WHERE owner_id = ?
    GROUP BY category
");
$addStmt->execute([$id]);
$added = [];
foreach ($addStmt->fetchAll() as $row) {
    $added[$row['category']] = (float)$row['added'];
}

// Per-category stock = opening + added - issued.
$balances = [
    'original' => ['label' => 'اصلي چیکو', 'opening' => (float)$owner['opening_original'], 'issued' => $totals['original']],
    'coffee'   => ['label' => 'کافی',       'opening' => (float)$owner['opening_coffee'],   'issued' => $totals['coffee']],
    'pes'      => ['label' => 'Pes',          'opening' => (float)$owner['opening_pes'],      'issued' => $totals['pes']],
    'plastic'  => ['label' => 'پلاستیکی',    'opening' => (float)$owner['opening_plastic'],  'issued' => $totals['plastic']],
];
foreach ($balances as $key => &$b) {
    $b['added'] = $added[$key] ?? 0;
    $b['remaining'] = $b['opening'] + $b['added'] - $b['issued'];
}
unset($b);

$stockInToken = generateFormToken('accessory_stockin_' . $id);
$todayShamsi  = accessoryToShamsi((int)date('Y'), (int)date('n'), (int)date('j'));
$jMonths      = accessoryShamsiMonths();

// Money ledger: bills generate amount owed; charges add previous owed; payments reduce it.
$payStmt = $pdo->prepare("
    SELECT * FROM accessory_payments
    WHERE owner_id = ?
    ORDER BY COALESCE(entry_date, DATE(created_at)) DESC, id DESC
");
$payStmt->execute([$id]);
$payments = $payStmt->fetchAll();

$account = [
    'bills'   => (float)$totals['amount'],
    'charged' => array_sum(array_map(fn($p) => $p['kind'] === 'charge'  ? (float)$p['amount'] : 0, $payments)),
    'paid'    => array_sum(array_map(fn($p) => $p['kind'] === 'payment' ? (float)$p['amount'] : 0, $payments)),
];
$account['payable']   = $account['bills'] + $account['charged'];
$account['remaining'] = $account['payable'] - $account['paid'];

$paymentToken = generateFormToken('accessory_payment_' . $id);
$deleteToken  = generateFormToken('accessory_delete_' . $id);
$formToken = generateFormToken('accessory_entry_' . $id);

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <a href="index.php" class="text-muted small">
            <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('accessories_title') ?>
        </a>
        <h4 class="mt-1 mb-0"><?= htmlspecialchars($owner['name']) ?></h4>
        <p class="text-muted small mb-0"><?= htmlspecialchars($owner['phone'] ?? '') ?></p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#stockInModal">
            <i class="bi bi-box-arrow-in-down me-2"></i>Add Stock
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#entryModal">
            <i class="bi bi-receipt me-2"></i>Add Bill
        </button>
        <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#paymentModal">
            <i class="bi bi-cash-coin me-2"></i>Pay
        </button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg">
        <div class="card border-0" style="background:rgba(34,211,238,0.12);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Stock Qty</div>
                <div class="fs-4 fw-bold" style="color:#0E7490;"><?= number_format($totals['quantity'], 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card border-0" style="background:rgba(16,124,16,0.10);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Total Amount</div>
                <div class="fs-4 fw-bold text-success">؋ <?= number_format($account['payable'], 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card border-0" style="background:rgba(220,53,69,0.10);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Paid</div>
                <div class="fs-4 fw-bold text-danger">؋ <?= number_format($account['paid'], 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card border-0" style="background:rgba(124,58,237,0.12);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Remaining</div>
                <div class="fs-4 fw-bold <?= $account['remaining'] < 0 ? 'text-danger' : '' ?>" style="<?= $account['remaining'] < 0 ? '' : 'color:#7C3AED;' ?>">؋ <?= number_format($account['remaining'], 2) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header py-3 fw-semibold">
        <i class="bi bi-calculator me-2 text-primary"></i>Stock Balance per Category
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0 text-center">
            <thead class="table-light">
                <tr>
                    <th></th>
                    <?php foreach ($balances as $b): ?>
                    <th><?= htmlspecialchars($b['label']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-muted small text-start fw-semibold">Opening</td>
                    <?php foreach ($balances as $b): ?>
                    <td><?= number_format($b['opening'], 2) ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td class="text-muted small text-start fw-semibold">Added</td>
                    <?php foreach ($balances as $b): ?>
                    <td class="text-primary"><?= number_format($b['added'], 2) ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td class="text-muted small text-start fw-semibold">Issued</td>
                    <?php foreach ($balances as $b): ?>
                    <td class="text-danger"><?= number_format($b['issued'], 2) ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr class="fw-bold">
                    <td class="text-start">Remaining</td>
                    <?php foreach ($balances as $b): ?>
                    <td class="<?= $b['remaining'] < 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($b['remaining'], 2) ?></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header py-3 fw-semibold d-flex align-items-center justify-content-between">
        <span><i class="bi bi-cash-stack me-2 text-success"></i>Account / حساب پیسو</span>
        <button class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#paymentModal">
            <i class="bi bi-cash-coin me-1"></i>Pay / Add Amount
        </button>
    </div>
    <div class="card-body">
        <div class="row g-3 text-center">
            <div class="col-4">
                <div class="text-muted small text-uppercase">Total Amount</div>
                <div class="fs-5 fw-bold">؋ <?= number_format($account['payable'], 2) ?></div>
            </div>
            <div class="col-4">
                <div class="text-muted small text-uppercase">Paid</div>
                <div class="fs-5 fw-bold text-danger">؋ <?= number_format($account['paid'], 2) ?></div>
            </div>
            <div class="col-4">
                <div class="text-muted small text-uppercase">Remaining</div>
                <div class="fs-5 fw-bold <?= $account['remaining'] < 0 ? 'text-danger' : 'text-success' ?>">؋ <?= number_format($account['remaining'], 2) ?></div>
            </div>
        </div>

        <?php if (!empty($payments)): ?>
        <div class="table-responsive mt-3">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>تاریخ</th>
                        <th>بل</th>
                        <th>Type</th>
                        <th class="text-end">Amount</th>
                        <th>Note</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                    <tr>
                        <td class="text-muted small"><?= htmlspecialchars(accessoryShamsiDate($p['entry_date'])) ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($p['bill_no'] ?? '') ?: '-' ?></td>
                        <td>
                            <?php if ($p['kind'] === 'charge'): ?>
                            <span class="badge bg-primary-subtle text-primary">Added</span>
                            <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger">Paid</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-semibold <?= $p['kind'] === 'charge' ? 'text-primary' : 'text-danger' ?>">
                            <?= $p['kind'] === 'charge' ? '+' : '-' ?>؋ <?= number_format((float)$p['amount'], 2) ?>
                        </td>
                        <td class="text-muted small"><?= htmlspecialchars($p['notes'] ?? '') ?></td>
                        <td class="text-end">
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this payment record?');">
                                <input type="hidden" name="_form_token" value="<?= htmlspecialchars($deleteToken) ?>">
                                <input type="hidden" name="action" value="delete_payment">
                                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="d-flex align-items-center justify-content-between mb-2">
    <h5 class="mb-0 fw-semibold"><i class="bi bi-receipt-cutoff me-2 text-primary"></i>Bills — دوکان رخت فروشی فضل الحق</h5>
    <span class="text-muted small"><?= count($bills) ?> bill(s)</span>
</div>

<?php if (empty($bills)): ?>
<div class="card"><div class="card-body text-center text-muted py-5">No bills yet.</div></div>
<?php else: ?>
<?php foreach ($bills as $bill): ?>
<div class="card mb-3">
    <div class="card-header py-2 d-flex align-items-center justify-content-between bg-light">
        <div>
            <span class="fw-semibold">بل: <?= htmlspecialchars($bill['bill_no'] ?? '') ?: '—' ?></span>
            <span class="text-muted small ms-2"><i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars(accessoryShamsiDate($bill['date'])) ?></span>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span class="fw-bold text-success">؋ <?= number_format($bill['t']['amount'], 2) ?></span>
            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this whole bill (<?= count($bill['rows']) ?> row(s))? This cannot be undone.');">
                <input type="hidden" name="_form_token" value="<?= htmlspecialchars($deleteToken) ?>">
                <input type="hidden" name="action" value="delete_bill">
                <?php if ($bill['group'] !== null): ?>
                <input type="hidden" name="bill_group" value="<?= htmlspecialchars($bill['group']) ?>">
                <?php else: ?>
                <input type="hidden" name="entry_id" value="<?= (int)$bill['first_id'] ?>">
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-danger" title="Delete bill"><i class="bi bi-trash"></i></button>
            </form>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="text-end">تعداد</th>
                    <th>جنس</th>
                    <th class="text-end">اصلي چیکو</th>
                    <th class="text-end">کافی</th>
                    <th class="text-end">Pes</th>
                    <th class="text-end">پلاستیکی</th>
                    <th class="text-end">مترانه</th>
                    <th class="text-end">نرخ</th>
                    <th class="text-end">جمله</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bill['rows'] as $entry): ?>
                <tr>
                    <td class="text-end fw-semibold"><?= number_format((float)$entry['quantity'], 2) ?></td>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($entry['item_name']) ?></div>
                        <?php if ($entry['notes']): ?><div class="text-muted small"><?= htmlspecialchars($entry['notes']) ?></div><?php endif; ?>
                        <?php if (!empty($entry['bill_photo'])): ?>
                        <a href="/uploads/accessory-bills/<?= htmlspecialchars($entry['bill_photo']) ?>" target="_blank" class="d-inline-block mt-1" title="View bill photo">
                            <img src="/uploads/accessory-bills/<?= htmlspecialchars($entry['bill_photo']) ?>" alt="bill" style="height:38px;width:38px;object-fit:cover;border-radius:6px;border:1px solid rgba(0,0,0,.1);">
                        </a>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= $entry['original_size'] !== null ? number_format((float)$entry['original_size'], 2) : '-' ?></td>
                    <td class="text-end"><?= $entry['coffee_size'] !== null ? number_format((float)$entry['coffee_size'], 2) : '-' ?></td>
                    <td class="text-end"><?= $entry['pes_size'] !== null ? number_format((float)$entry['pes_size'], 2) : '-' ?></td>
                    <td class="text-end"><?= $entry['plastic_size'] !== null ? number_format((float)$entry['plastic_size'], 2) : '-' ?></td>
                    <td class="text-end"><?= $entry['meterage'] !== null ? number_format((float)$entry['meterage'], 2) : '-' ?></td>
                    <td class="text-end"><?= $entry['rate'] !== null ? '؋ ' . number_format((float)$entry['rate'], 2) : '-' ?></td>
                    <td class="text-end fw-bold">؋ <?= number_format((float)$entry['total_amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-light fw-bold">
                    <td class="text-end"><?= number_format($bill['t']['quantity'], 2) ?></td>
                    <td>ټول</td>
                    <td class="text-end"><?= number_format($bill['t']['original'], 2) ?></td>
                    <td class="text-end"><?= number_format($bill['t']['coffee'], 2) ?></td>
                    <td class="text-end"><?= number_format($bill['t']['pes'], 2) ?></td>
                    <td class="text-end"><?= number_format($bill['t']['plastic'], 2) ?></td>
                    <td class="text-end"><?= number_format($bill['t']['meterage'], 2) ?></td>
                    <td></td>
                    <td class="text-end">؋ <?= number_format($bill['t']['amount'], 2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
            <input type="hidden" name="_form_token" value="<?= htmlspecialchars($paymentToken) ?>">
            <input type="hidden" name="action" value="payment">
            <div class="modal-header">
                <h5 class="modal-title">Payment — <?= htmlspecialchars($owner['name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Type</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="kind" id="kindPayment" value="payment" checked>
                            <label class="form-check-label fw-semibold text-danger" for="kindPayment">
                                <i class="bi bi-arrow-up-circle me-1"></i>Pay owner (decrease)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="kind" id="kindCharge" value="charge">
                            <label class="form-check-label fw-semibold text-primary" for="kindCharge">
                                <i class="bi bi-arrow-down-circle me-1"></i>Add previous amount
                            </label>
                        </div>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Amount (؋)</label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold"><i class="bi bi-receipt me-1 text-primary"></i>بل / Bill No</label>
                        <input type="text" name="bill_no" class="form-control" placeholder="Bill #">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold d-flex align-items-center gap-2">
                        <i class="bi bi-calendar3 me-1 text-primary"></i>تاریخ
                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:0.62rem;">Solar Hijri</span>
                    </label>
                    <div class="d-flex align-items-center gap-1">
                        <input type="number" id="payJYear" class="form-control form-control-sm text-center fw-semibold"
                               value="<?= $todayShamsi['y'] ?>" min="1300" max="1600" style="width:80px;" oninput="syncPayShamsi()">
                        <span class="text-muted">/</span>
                        <select id="payJMonth" class="form-select form-select-sm" style="width:140px;" onchange="syncPayShamsi()">
                            <?php foreach ($jMonths as $i => $nm): ?>
                            <option value="<?= $i+1 ?>" <?= $todayShamsi['m'] === $i+1 ? 'selected' : '' ?>><?= $nm ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="text-muted">/</span>
                        <input type="number" id="payJDay" class="form-control form-control-sm text-center fw-semibold"
                               value="<?= $todayShamsi['d'] ?>" min="1" max="31" style="width:64px;" oninput="syncPayShamsi()">
                    </div>
                    <input type="hidden" name="entry_date" id="payDateHidden" value="<?= date('Y-m-d') ?>">
                    <div id="payGregorianBadge" class="mt-1 text-muted" style="font-size:0.71rem;"></div>
                </div>
                <div class="mb-1">
                    <label class="form-label fw-semibold">Note</label>
                    <input type="text" name="note" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= __('btn_cancel') ?></button>
                <button class="btn btn-dark"><i class="bi bi-check2-circle me-2"></i><?= __('btn_save') ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="stockInModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
            <input type="hidden" name="_form_token" value="<?= htmlspecialchars($stockInToken) ?>">
            <input type="hidden" name="action" value="stock_in">
            <div class="modal-header">
                <h5 class="modal-title">Add Stock — <?= htmlspecialchars($owner['name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Stock Type</label>
                    <select name="category" class="form-select" required>
                        <option value="">— select —</option>
                        <?php foreach ($categories as $key => $cat): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($cat['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Quantity</label>
                        <input type="number" step="0.01" min="0.01" name="quantity" class="form-control" required autofocus>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold"><i class="bi bi-receipt me-1 text-primary"></i>بل / Bill No</label>
                        <input type="text" name="bill_no" class="form-control" placeholder="Bill #">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold d-flex align-items-center gap-2">
                        <i class="bi bi-calendar3 me-1 text-primary"></i>تاریخ
                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:0.62rem;">Solar Hijri</span>
                    </label>
                    <div class="d-flex align-items-center gap-1">
                        <input type="number" id="stockInJYear" class="form-control form-control-sm text-center fw-semibold"
                               value="<?= $todayShamsi['y'] ?>" min="1300" max="1600" style="width:80px;" oninput="syncStockInShamsi()">
                        <span class="text-muted">/</span>
                        <select id="stockInJMonth" class="form-select form-select-sm" style="width:140px;" onchange="syncStockInShamsi()">
                            <?php foreach ($jMonths as $i => $nm): ?>
                            <option value="<?= $i+1 ?>" <?= $todayShamsi['m'] === $i+1 ? 'selected' : '' ?>><?= $nm ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="text-muted">/</span>
                        <input type="number" id="stockInJDay" class="form-control form-control-sm text-center fw-semibold"
                               value="<?= $todayShamsi['d'] ?>" min="1" max="31" style="width:64px;" oninput="syncStockInShamsi()">
                    </div>
                    <input type="hidden" name="in_date" id="stockInDateHidden" value="<?= date('Y-m-d') ?>">
                    <div id="stockInGregorianBadge" class="mt-1 text-muted" style="font-size:0.71rem;"></div>
                </div>
                <div class="mb-1">
                    <label class="form-label fw-semibold">Note</label>
                    <input type="text" name="note" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= __('btn_cancel') ?></button>
                <button class="btn btn-success"><i class="bi bi-check2-circle me-2"></i><?= __('btn_save') ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="entryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="_form_token" value="<?= htmlspecialchars($formToken) ?>">
            <div class="modal-header">
                <h5 class="modal-title">Add Accessory Stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-3 flex-wrap">
                    <div class="d-flex gap-2">
                        <div style="max-width:200px;">
                            <label class="form-label fw-semibold">تاریخ</label>
                            <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div style="max-width:200px;">
                            <label class="form-label fw-semibold">بل / Bill No</label>
                            <input type="text" name="bill_no" class="form-control" placeholder="Bill #">
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addAccessoryRow">
                        <i class="bi bi-plus-lg me-1"></i>Add Row
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0" id="accessoryEntryTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:80px;">تعداد</th>
                                <th style="min-width:180px;">جنس</th>
                                <th style="width:95px;">اصلي چیکو</th>
                                <th style="width:90px;">کافی</th>
                                <th style="width:90px;">Pes</th>
                                <th style="width:95px;">پلاستیکی</th>
                                <th style="width:95px;">مترانه</th>
                                <th style="width:90px;">نرخ</th>
                                <th style="width:110px;">جمله</th>
                                <th style="width:120px;">بل عکس</th>
                                <th style="width:48px;"></th>
                            </tr>
                        </thead>
                        <tbody id="accessoryEntryRows">
                            <?php for ($i = 0; $i < 6; $i++): ?>
                            <tr>
                                <td><input type="number" step="0.01" min="0" name="quantity[]" class="form-control form-control-sm"></td>
                                <td><input type="text" name="item_name[]" class="form-control form-control-sm"></td>
                                <td><input type="number" step="0.01" min="0" name="original_size[]" class="form-control form-control-sm"></td>
                                <td><input type="number" step="0.01" min="0" name="coffee_size[]" class="form-control form-control-sm"></td>
                                <td><input type="number" step="0.01" min="0" name="pes_size[]" class="form-control form-control-sm"></td>
                                <td><input type="number" step="0.01" min="0" name="plastic_size[]" class="form-control form-control-sm"></td>
                                <td><input type="number" step="0.01" min="0" name="meterage[]" class="form-control form-control-sm calc-meterage"></td>
                                <td><input type="number" step="0.01" min="0" name="rate[]" class="form-control form-control-sm calc-rate"></td>
                                <td><input type="text" class="form-control form-control-sm row-total" value="0.00" readonly></td>
                                <td><input type="file" accept="image/*" name="bill_photo[]" class="form-control form-control-sm"></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-light remove-row" title="Remove row">
                                        <i class="bi bi-x"></i>
                                    </button>
                                    <input type="hidden" name="notes[]" value="">
                                </td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="8" class="text-end">ټول جمله</td>
                                <td><input type="text" id="entryGrandTotal" class="form-control form-control-sm fw-bold" value="0.00" readonly></td>
                                <td></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= __('btn_cancel') ?></button>
                <button class="btn btn-primary"><i class="bi bi-check2-circle me-2"></i><?= __('btn_save') ?></button>
            </div>
        </form>
    </div>
</div>

<template id="accessoryEntryRowTemplate">
    <tr>
        <td><input type="number" step="0.01" min="0" name="quantity[]" class="form-control form-control-sm"></td>
        <td><input type="text" name="item_name[]" class="form-control form-control-sm"></td>
        <td><input type="number" step="0.01" min="0" name="original_size[]" class="form-control form-control-sm"></td>
        <td><input type="number" step="0.01" min="0" name="coffee_size[]" class="form-control form-control-sm"></td>
        <td><input type="number" step="0.01" min="0" name="pes_size[]" class="form-control form-control-sm"></td>
        <td><input type="number" step="0.01" min="0" name="plastic_size[]" class="form-control form-control-sm"></td>
        <td><input type="number" step="0.01" min="0" name="meterage[]" class="form-control form-control-sm calc-meterage"></td>
        <td><input type="number" step="0.01" min="0" name="rate[]" class="form-control form-control-sm calc-rate"></td>
        <td><input type="text" class="form-control form-control-sm row-total" value="0.00" readonly></td>
        <td><input type="file" accept="image/*" name="bill_photo[]" class="form-control form-control-sm"></td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-light remove-row" title="Remove row">
                <i class="bi bi-x"></i>
            </button>
            <input type="hidden" name="notes[]" value="">
        </td>
    </tr>
</template>

<script>
(function () {
    const rows = document.getElementById('accessoryEntryRows');
    const addBtn = document.getElementById('addAccessoryRow');
    const template = document.getElementById('accessoryEntryRowTemplate');
    const grandTotal = document.getElementById('entryGrandTotal');

    function num(value) {
        const parsed = parseFloat(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function recalc() {
        let total = 0;
        rows.querySelectorAll('tr').forEach(row => {
            const meterage = num(row.querySelector('.calc-meterage')?.value);
            const rate = num(row.querySelector('.calc-rate')?.value);
            const rowTotal = meterage * rate;
            const out = row.querySelector('.row-total');
            if (out) out.value = rowTotal.toFixed(2);
            total += rowTotal;
        });
        grandTotal.value = total.toFixed(2);
    }

    addBtn?.addEventListener('click', () => {
        rows.appendChild(template.content.firstElementChild.cloneNode(true));
        recalc();
    });

    rows?.addEventListener('input', event => {
        if (event.target.matches('.calc-meterage, .calc-rate')) recalc();
    });

    rows?.addEventListener('click', event => {
        const btn = event.target.closest('.remove-row');
        if (!btn) return;
        if (rows.querySelectorAll('tr').length > 1) btn.closest('tr').remove();
        recalc();
    });

    recalc();
})();
</script>

<script>
function accShamsiToGregorian(jy, jm, jd) {
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
function syncStockInShamsi() {
    var jy = parseInt(document.getElementById('stockInJYear').value)  || 0;
    var jm = parseInt(document.getElementById('stockInJMonth').value) || 0;
    var jd = parseInt(document.getElementById('stockInJDay').value)   || 0;
    if (jy < 1300 || jy > 1600 || jm < 1 || jm > 12 || jd < 1 || jd > 31) return;
    var g = accShamsiToGregorian(jy, jm, jd);
    if (!g) return;
    var gStr = g.y + '-' + String(g.m).padStart(2,'0') + '-' + String(g.d).padStart(2,'0');
    document.getElementById('stockInDateHidden').value = gStr;
    document.getElementById('stockInGregorianBadge').textContent =
        '≡ ' + g.y + '/' + String(g.m).padStart(2,'0') + '/' + String(g.d).padStart(2,'0') + ' (Gregorian)';
}
function syncPayShamsi() {
    var jy = parseInt(document.getElementById('payJYear').value)  || 0;
    var jm = parseInt(document.getElementById('payJMonth').value) || 0;
    var jd = parseInt(document.getElementById('payJDay').value)   || 0;
    if (jy < 1300 || jy > 1600 || jm < 1 || jm > 12 || jd < 1 || jd > 31) return;
    var g = accShamsiToGregorian(jy, jm, jd);
    if (!g) return;
    var gStr = g.y + '-' + String(g.m).padStart(2,'0') + '-' + String(g.d).padStart(2,'0');
    document.getElementById('payDateHidden').value = gStr;
    document.getElementById('payGregorianBadge').textContent =
        '≡ ' + g.y + '/' + String(g.m).padStart(2,'0') + '/' + String(g.d).padStart(2,'0') + ' (Gregorian)';
}
syncStockInShamsi();
syncPayShamsi();
</script>

<?php require_once '../includes/footer.php'; ?>
