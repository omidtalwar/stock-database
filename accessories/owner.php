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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateFormToken('accessory_entry_' . $id)) {
        $_SESSION['error'] = 'Duplicate submission detected. Your data was already saved.';
        header('Location: owner.php?id=' . $id); exit;
    }

    $itemName = trim($_POST['item_name'] ?? '');
    $quantity = (float)($_POST['quantity'] ?? 0);
    $rate     = decimalOrNull($_POST['rate'] ?? null);
    $total    = accessoryAmount($quantity, $rate, decimalOrNull($_POST['total_amount'] ?? null));
    $date     = trim($_POST['entry_date'] ?? '') ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    if ($itemName === '' || $quantity <= 0) {
        $_SESSION['error'] = 'Item name and quantity are required.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO accessory_stock_entries
                (owner_id, entry_date, item_name, quantity, original_size, coffee_size,
                 pes_size, plastic_size, meterage, rate, total_amount, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $id, $date, $itemName, $quantity,
            decimalOrNull($_POST['original_size'] ?? null),
            decimalOrNull($_POST['coffee_size'] ?? null),
            decimalOrNull($_POST['pes_size'] ?? null),
            decimalOrNull($_POST['plastic_size'] ?? null),
            decimalOrNull($_POST['meterage'] ?? null),
            $rate, $total, trim($_POST['notes'] ?? '') ?: null, $_SESSION['user_id'] ?? null,
        ]);
        $_SESSION['success'] = 'Accessory stock entry saved.';
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
$avgRate = $totals['quantity'] > 0 ? $totals['amount'] / $totals['quantity'] : 0;
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
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#entryModal">
        <i class="bi bi-plus-square me-2"></i>Add Stock
    </button>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0" style="background:rgba(0,103,192,0.08);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Rows</div>
                <div class="fs-4 fw-bold text-primary"><?= number_format($totals['rows']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0" style="background:rgba(34,211,238,0.12);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Stock Qty</div>
                <div class="fs-4 fw-bold" style="color:#0E7490;"><?= number_format($totals['quantity'], 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0" style="background:rgba(16,124,16,0.10);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Total Amount</div>
                <div class="fs-4 fw-bold text-success">؋ <?= number_format($totals['amount'], 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0" style="background:rgba(157,93,0,0.10);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Avg Rate</div>
                <div class="fs-4 fw-bold" style="color:#9D5D00;">؋ <?= number_format($avgRate, 2) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header py-3 fw-semibold">
        <i class="bi bi-calculator me-2 text-primary"></i>Category Calculation
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-6 col-md"><div class="text-muted small">Original</div><div class="fw-bold"><?= number_format($totals['original'], 2) ?></div></div>
            <div class="col-6 col-md"><div class="text-muted small">Coffee</div><div class="fw-bold"><?= number_format($totals['coffee'], 2) ?></div></div>
            <div class="col-6 col-md"><div class="text-muted small">Pes</div><div class="fw-bold"><?= number_format($totals['pes'], 2) ?></div></div>
            <div class="col-6 col-md"><div class="text-muted small">Plastic</div><div class="fw-bold"><?= number_format($totals['plastic'], 2) ?></div></div>
            <div class="col-6 col-md"><div class="text-muted small">Meterage</div><div class="fw-bold"><?= number_format($totals['meterage'], 2) ?></div></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 fw-semibold">
        <i class="bi bi-table me-2 text-primary"></i>Accessory Stock Ledger
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="text-end">Qty</th>
                    <th>Item / Type</th>
                    <th class="text-end">Original</th>
                    <th class="text-end">Coffee</th>
                    <th class="text-end">Pes</th>
                    <th class="text-end">Plastic</th>
                    <th class="text-end">Meterage</th>
                    <th class="text-end">Rate</th>
                    <th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($entries)): ?>
                <tr><td colspan="10" class="text-center text-muted py-5">No stock entries yet.</td></tr>
                <?php else: ?>
                <?php foreach ($entries as $entry): ?>
                <tr>
                    <td class="text-muted small"><?= htmlspecialchars($entry['entry_date'] ?? '') ?></td>
                    <td class="text-end fw-semibold"><?= number_format((float)$entry['quantity'], 2) ?></td>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($entry['item_name']) ?></div>
                        <?php if ($entry['notes']): ?><div class="text-muted small"><?= htmlspecialchars($entry['notes']) ?></div><?php endif; ?>
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
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="entryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <form method="POST" class="modal-content">
            <input type="hidden" name="_form_token" value="<?= htmlspecialchars($formToken) ?>">
            <div class="modal-header">
                <h5 class="modal-title">Add Accessory Stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Date</label>
                        <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Quantity</label>
                        <input type="number" step="0.01" min="0" name="quantity" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Item / Type</label>
                        <input type="text" name="item_name" class="form-control" required>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label fw-semibold">Original</label>
                        <input type="number" step="0.01" min="0" name="original_size" class="form-control">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label fw-semibold">Coffee</label>
                        <input type="number" step="0.01" min="0" name="coffee_size" class="form-control">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label fw-semibold">Pes</label>
                        <input type="number" step="0.01" min="0" name="pes_size" class="form-control">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label fw-semibold">Plastic</label>
                        <input type="number" step="0.01" min="0" name="plastic_size" class="form-control">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label fw-semibold">Meterage</label>
                        <input type="number" step="0.01" min="0" name="meterage" class="form-control">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label fw-semibold">Rate</label>
                        <input type="number" step="0.01" min="0" name="rate" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Total Amount</label>
                        <input type="number" step="0.01" min="0" name="total_amount" class="form-control" placeholder="Auto: quantity x rate">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Notes</label>
                        <input type="text" name="notes" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= __('btn_cancel') ?></button>
                <button class="btn btn-primary"><i class="bi bi-check2-circle me-2"></i><?= __('btn_save') ?></button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
