<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';
require_once '../includes/currency.php';

$id = (int)($_GET['id'] ?? 0);
$sale = $pdo->prepare("
    SELECT s.*, c.name AS customer_name, c.shop_name, c.phone, c.id AS customer_id,
           u.full_name AS created_by_name
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    JOIN users u ON u.id = s.created_by
    WHERE s.id = ?
");
$sale->execute([$id]);
$sale = $sale->fetch();
if (!$sale) { $_SESSION['error'] = 'Invoice not found.'; header('Location: index.php'); exit; }

$items = $pdo->prepare("
    SELECT si.*, p.name AS product_name, p.size, p.color
    FROM sale_items si JOIN products p ON p.id = si.product_id
    WHERE si.sale_id = ?
");
$items->execute([$id]);
$items = $items->fetchAll();

$settings  = getSettings($pdo);
$rate      = (float)($settings['exchange_rate']      ?? 90);
$secCur    = $settings['secondary_currency'] ?? 'USD';

$pageTitle = 'Invoice #' . str_pad($id, 4, '0', STR_PAD_LEFT);

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-start justify-content-between">
    <div>
        <a href="index.php" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Sales</a>
        <h4 class="mt-1 mb-0">Invoice #<?= str_pad($id, 4, '0', STR_PAD_LEFT) ?></h4>
        <span class="text-muted small"><?= date('d F Y, h:i A', strtotime($sale['created_at'])) ?></span>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Print</button>
        <?php if (isAdmin()): ?>
        <a href="delete.php?id=<?= $id ?>" class="btn btn-sm btn-outline-danger"
           onclick="return confirm('Delete this invoice? Stock and debt will be reversed.')">
            <i class="bi bi-trash me-1"></i>Delete
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold">Billed To</div>
            <div class="card-body">
                <div class="fw-bold fs-5"><?= htmlspecialchars($sale['customer_name']) ?></div>
                <div class="text-muted"><?= htmlspecialchars($sale['shop_name']) ?></div>
                <?php if ($sale['phone']): ?><div class="text-muted small"><?= htmlspecialchars($sale['phone']) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header fw-semibold">Items</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th>Size</th>
                            <th>Color</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $i => $item): ?>
                        <tr>
                            <td class="text-muted small"><?= $i+1 ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($item['product_name']) ?></td>
                            <td><?= htmlspecialchars($item['size'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($item['color'] ?: '—') ?></td>
                            <td class="text-end"><?= $item['quantity'] ?></td>
                            <td class="text-end"><?= formatAFN($item['unit_price']) ?></td>
                            <td class="text-end fw-semibold"><?= formatAFN($item['subtotal']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="6" class="text-end fw-bold">Total</td>
                            <td class="text-end fw-bold fs-5"><?= formatAFN($sale['total_amount']) ?></td>
                        </tr>
                        <tr class="text-muted">
                            <td colspan="6" class="text-end small">≈ <?= htmlspecialchars($secCur) ?></td>
                            <td class="text-end small"><?= formatMoney(fromAFN($sale['total_amount'], $rate), $secCur) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold">Payment Summary</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Total</span>
                    <div class="text-end">
                        <div class="fw-semibold"><?= formatAFN($sale['total_amount']) ?></div>
                        <div class="text-muted small">≈ <?= formatMoney(fromAFN($sale['total_amount'], $rate), $secCur) ?></div>
                    </div>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Paid at Sale</span>
                    <div class="text-end">
                        <div class="fw-semibold text-success"><?= formatAFN($sale['paid_amount']) ?></div>
                        <div class="text-muted small">≈ <?= formatMoney(fromAFN($sale['paid_amount'], $rate), $secCur) ?></div>
                    </div>
                </div>
                <hr>
                <div class="d-flex justify-content-between">
                    <span class="fw-semibold">Balance Due</span>
                    <div class="text-end">
                        <div class="fw-bold <?= $sale['balance'] > 0 ? 'text-danger' : 'text-success' ?> fs-5">
                            <?= formatAFN($sale['balance']) ?>
                        </div>
                        <?php if ($sale['balance'] > 0): ?>
                        <div class="text-muted small">≈ <?= formatMoney(fromAFN($sale['balance'], $rate), $secCur) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($sale['balance'] > 0): ?>
                <div class="mt-3">
                    <a href="/fzl/payments/add.php?customer_id=<?= $sale['customer_id'] ?>" class="btn btn-success w-100 btn-sm">
                        <i class="bi bi-cash me-2"></i>Record Payment
                    </a>
                </div>
                <?php else: ?>
                <div class="alert alert-success py-2 mt-3 mb-0 text-center small">
                    <i class="bi bi-check-circle me-1"></i>Fully Paid
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body small text-muted">
                <div><strong>Created by:</strong> <?= htmlspecialchars($sale['created_by_name']) ?></div>
                <div><strong>Date:</strong> <?= date('d M Y H:i', strtotime($sale['created_at'])) ?></div>
                <div class="mt-1">
                    <strong>Rate at view:</strong> 1 <?= htmlspecialchars($secCur) ?> = <?= number_format($rate, 2) ?> ؋
                </div>
                <?php if ($sale['notes']): ?>
                <div class="mt-2"><strong>Notes:</strong> <?= htmlspecialchars($sale['notes']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .sidebar, .topbar, .btn, .page-header a, .alert { display: none !important; }
    .main-content { margin-left: 0 !important; }
    .content-area { padding: 0 !important; }
}
</style>

<?php require_once '../includes/footer.php'; ?>
