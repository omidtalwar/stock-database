<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';

$pageTitle = 'Stock Log';

$filter = $_GET['type'] ?? '';
$where  = '';
$params = [];
if ($filter === 'in') { $where = "WHERE sl.type = 'in'"; }
if ($filter === 'out') { $where = "WHERE sl.type = 'out'"; }

$logs = $pdo->prepare("
    SELECT sl.*, p.name AS product_name, p.size, p.color, u.full_name AS created_by
    FROM stock_logs sl
    JOIN products p ON p.id = sl.product_id
    JOIN users u ON u.id = sl.created_by
    $where
    ORDER BY sl.created_at DESC
");
$logs->execute($params);
$logs = $logs->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1">Stock Log</h4>
        <p class="text-muted small mb-0">All stock movements — in and out</p>
    </div>
    <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-square me-2"></i>Stock In</a>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-sm <?= !$filter ? 'btn-dark' : 'btn-light' ?>">All</a>
            <a href="?type=in" class="btn btn-sm <?= $filter === 'in' ? 'btn-success' : 'btn-light' ?>">
                <i class="bi bi-arrow-down-circle me-1"></i>Stock In
            </a>
            <a href="?type=out" class="btn btn-sm <?= $filter === 'out' ? 'btn-danger' : 'btn-light' ?>">
                <i class="bi bi-arrow-up-circle me-1"></i>Stock Out
            </a>
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Size</th>
                    <th>Color</th>
                    <th>Type</th>
                    <th>Qty</th>
                    <th>Notes</th>
                    <th>By</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="8" class="text-center text-muted py-5">No stock movements yet</td></tr>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($log['product_name']) ?></td>
                    <td><?= htmlspecialchars($log['size'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($log['color'] ?: '—') ?></td>
                    <td>
                        <?php if ($log['type'] === 'in'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                <i class="bi bi-arrow-down-circle me-1"></i>IN
                            </span>
                        <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                <i class="bi bi-arrow-up-circle me-1"></i>OUT
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold"><?= $log['quantity'] ?> pcs</td>
                    <td class="text-muted small"><?= htmlspecialchars($log['notes'] ?: '—') ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($log['created_by']) ?></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($log['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
