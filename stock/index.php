<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';

$pageTitle = __('stock_title');

$filter = $_GET['type'] ?? '';
$where  = '';
$params = [];
if ($filter === 'in')  { $where = "WHERE sl.type = 'in'"; }
if ($filter === 'out') { $where = "WHERE sl.type = 'out'"; }

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_logs sl $where");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();

$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$logs = $pdo->prepare("
    SELECT sl.*, p.name AS product_name, p.size, p.color, u.full_name AS created_by
    FROM stock_logs sl
    JOIN products p ON p.id = sl.product_id
    JOIN users u ON u.id = sl.created_by
    $where
    ORDER BY sl.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$logs->execute($params);
$logs = $logs->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('stock_title') ?></h4>
        <p class="text-muted small mb-0"><?= __('stock_sub') ?></p>
    </div>
    <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-square me-2"></i><?= __('stock_in_btn') ?></a>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-sm <?= !$filter ? 'btn-dark' : 'btn-light' ?>"><?= __('btn_all') ?></a>
            <a href="?type=in" class="btn btn-sm <?= $filter === 'in' ? 'btn-success' : 'btn-light' ?>">
                <i class="bi bi-arrow-down-circle me-1"></i><?= __('stock_in') ?>
            </a>
            <a href="?type=out" class="btn btn-sm <?= $filter === 'out' ? 'btn-danger' : 'btn-light' ?>">
                <i class="bi bi-arrow-up-circle me-1"></i><?= __('stock_out') ?>
            </a>
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><?= __('prod_name') ?></th>
                    <th><?= __('field_size') ?></th>
                    <th><?= __('field_color') ?></th>
                    <th><?= __('stock_type') ?></th>
                    <th><?= __('field_quantity') ?></th>
                    <th><?= __('field_notes') ?></th>
                    <th><?= __('field_by') ?></th>
                    <th><?= __('field_date') ?></th>
                    <?php if (isAdmin()): ?><th><?= __('field_actions') ?></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="<?= isAdmin() ? 9 : 8 ?>" class="text-center text-muted py-5"><?= __('stock_no_data') ?></td></tr>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($log['product_name']) ?></td>
                    <td><?= htmlspecialchars($log['size'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($log['color'] ?: '—') ?></td>
                    <td>
                        <?php if ($log['type'] === 'in'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                <i class="bi bi-arrow-down-circle me-1"></i><?= __('stock_in') ?>
                            </span>
                        <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                <i class="bi bi-arrow-up-circle me-1"></i><?= __('stock_out') ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold"><?= $log['quantity'] ?> pcs</td>
                    <td class="text-muted small"><?= htmlspecialchars($log['notes'] ?: '—') ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($log['created_by']) ?></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($log['created_at'])) ?></td>
                    <?php if (isAdmin()): ?>
                    <td>
                        <a href="edit.php?id=<?= $log['id'] ?>" class="btn btn-sm btn-light me-1" title="<?= __('btn_edit') ?>">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="delete.php?id=<?= $log['id'] ?>"
                           class="btn btn-sm btn-outline-danger"
                           title="<?= __('btn_delete') ?>"
                           onclick="return confirm('<?= htmlspecialchars(addslashes(__('confirm_delete'))) ?>')">
                            <i class="bi bi-trash"></i>
                        </a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex align-items-center justify-content-between gap-2 flex-wrap py-2">
        <span class="text-muted small">
            <?= __('field_total') ?>: <?= $totalRows ?> &mdash;
            <?= __('period_showing') ?> <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?>
        </span>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&#8249;</a>
            </li>
            <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            if ($start > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
            for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
            </li>
            <?php endfor;
            if ($end < $totalPages): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">&#8250;</a>
            </li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
