<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';

$pageTitle = __('sale_title');

// ── Period filter ──
$period = in_array($_GET['period'] ?? '', ['today','week','month','all'])
    ? $_GET['period'] : 'all';

$periodWhere = match($period) {
    'today' => "AND DATE(s.created_at) = CURDATE()",
    'week'  => "AND YEARWEEK(s.created_at, 1) = YEARWEEK(CURDATE(), 1)",
    'month' => "AND DATE_FORMAT(s.created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
    default => "",
};

$search = trim($_GET['search'] ?? '');
$params = [];
$searchWhere = '';
if ($search) {
    $searchWhere = "AND (c.name LIKE ? OR c.shop_name LIKE ?)";
    $params      = ["%$search%", "%$search%"];
}

// Aggregate totals over the full filtered set (before pagination)
$totalsStmt = $pdo->prepare("
    SELECT COUNT(*) AS cnt,
           COALESCE(SUM(s.total_amount), 0) AS sum_total,
           COALESCE(SUM(s.paid_amount),  0) AS sum_paid,
           COALESCE(SUM(s.balance),      0) AS sum_balance,
           SUM(CASE WHEN s.balance = 0 THEN 1 ELSE 0 END) AS fully_paid
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    WHERE 1=1 $periodWhere $searchWhere
");
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch();

$totalRows  = (int)$totals['cnt'];
$sumTotal   = (float)$totals['sum_total'];
$sumPaid    = (float)$totals['sum_paid'];
$sumBalance = (float)$totals['sum_balance'];
$fullyPaid  = (int)$totals['fully_paid'];

$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$sales = $pdo->prepare("
    SELECT s.id, s.total_amount, s.paid_amount, s.balance, s.created_at, s.notes,
           c.name AS customer_name, c.shop_name,
           u.full_name AS created_by
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    JOIN users u ON u.id = s.created_by
    WHERE 1=1 $periodWhere $searchWhere
    ORDER BY s.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$sales->execute($params);
$sales = $sales->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('sale_title') ?></h4>
        <p class="text-muted small mb-0"><?= __('sale_sub') ?></p>
    </div>
    <a href="create.php" class="btn btn-primary"><i class="bi bi-plus-circle me-2"></i><?= __('sale_add') ?></a>
</div>

<!-- Period filter bar -->
<?php
$salePeriodLabels = [
    'all'   => __('period_all'),
    'today' => __('period_today'),
    'week'  => __('period_week'),
    'month' => __('period_month'),
];
$searchParam = $search ? '&search=' . urlencode($search) : '';
?>
<div class="d-flex align-items-center gap-2 flex-wrap mb-3">
    <span class="small fw-semibold text-muted"><?= __('period_showing') ?>:</span>
    <?php foreach ($salePeriodLabels as $pk => $pl): ?>
    <a href="?period=<?= $pk ?><?= $searchParam ?>" class="btn btn-sm <?= $period === $pk ? 'btn-primary' : 'btn-outline-secondary' ?>">
        <?php if ($pk === 'today'): ?><i class="bi bi-sun me-1"></i>
        <?php elseif ($pk === 'week'): ?><i class="bi bi-calendar-week me-1"></i>
        <?php elseif ($pk === 'month'): ?><i class="bi bi-calendar-month me-1"></i>
        <?php else: ?><i class="bi bi-infinity me-1"></i>
        <?php endif; ?>
        <?= $pl ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Summary totals -->
<?php if (!empty($sales)): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-2">
                <div class="text-muted small mb-1"><?= __('field_total') ?></div>
                <div class="fw-bold">؋ <?= number_format($sumTotal, 0) ?></div>
                <div class="text-muted" style="font-size:0.72rem;"><?= $totalRows ?> <?= __('rep_invoices') ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-2">
                <div class="text-muted small mb-1"><?= __('field_paid') ?></div>
                <div class="fw-bold text-success">؋ <?= number_format($sumPaid, 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-2">
                <div class="text-muted small mb-1"><?= __('field_balance') ?></div>
                <div class="fw-bold text-danger">؋ <?= number_format($sumBalance, 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-2">
                <div class="text-muted small mb-1"><?= __('sale_fully_paid') ?></div>
                <div class="fw-bold text-primary"><?= $fullyPaid ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2">
            <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="<?= __('btn_search') ?>..."
                   value="<?= htmlspecialchars($search) ?>" style="max-width:300px;">
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
            <?php if ($search): ?><a href="?period=<?= $period ?>" class="btn btn-sm btn-light"><?= __('btn_clear') ?></a><?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><?= __('sale_title') ?> #</th>
                    <th><?= __('nav_customers') ?></th>
                    <th><?= __('field_total') ?></th>
                    <th class="d-none d-sm-table-cell"><?= __('field_paid') ?></th>
                    <th><?= __('field_balance') ?></th>
                    <th class="d-none d-md-table-cell"><?= __('field_by') ?></th>
                    <th class="d-none d-md-table-cell"><?= __('field_date') ?></th>
                    <th><?= __('field_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sales)): ?>
                <tr><td colspan="8" class="text-center text-muted py-5"><?= __('sale_no_data') ?></td></tr>
                <?php else: ?>
                <?php foreach ($sales as $s): ?>
                <tr>
                    <td><span class="badge bg-light text-dark fw-semibold">#<?= str_pad($s['id'], 4, '0', STR_PAD_LEFT) ?></span></td>
                    <td>
                        <div class="fw-semibold small"><?= htmlspecialchars($s['customer_name']) ?></div>
                        <div class="text-muted" style="font-size:0.75rem;"><?= htmlspecialchars($s['shop_name']) ?></div>
                    </td>
                    <td class="fw-semibold">؋ <?= number_format($s['total_amount'], 0) ?></td>
                    <td class="text-success d-none d-sm-table-cell">؋ <?= number_format($s['paid_amount'], 0) ?></td>
                    <td>
                        <?php if ($s['balance'] > 0): ?>
                            <span class="fw-bold text-danger">؋ <?= number_format($s['balance'], 0) ?></span>
                        <?php else: ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle"><?= __('sale_fully_paid') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small d-none d-md-table-cell"><?= htmlspecialchars($s['created_by']) ?></td>
                    <td class="text-muted small d-none d-md-table-cell"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
                    <td>
                        <a href="view.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-light me-1" title="<?= __('btn_view') ?>">
                            <i class="bi bi-eye"></i>
                        </a>
                        <?php if (isAdmin()): ?>
                        <a href="delete.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('<?= htmlspecialchars(addslashes(__('sale_del_confirm2'))) ?>')">
                            <i class="bi bi-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
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
