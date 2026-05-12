<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$rates   = getAllRates($pdo);
$rateUSD = $rates['USD'];
$ratePKR = $rates['PKR'];

$pageTitle = __('cust_title');

$search = trim($_GET['search'] ?? '');
$params = [];
$where  = '';
if ($search) {
    $where  = "WHERE c.name LIKE ? OR c.phone LIKE ? OR c.shop_name LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c $where");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();

$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$customers = $pdo->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM sales s WHERE s.customer_id = c.id) AS sale_count
    FROM customers c $where
    ORDER BY c.total_debt DESC, c.name ASC
    LIMIT $perPage OFFSET $offset
");
$customers->execute($params);
$customers = $customers->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('cust_title') ?></h4>
        <p class="text-muted small mb-0"><?= __('cust_sub') ?></p>
    </div>
    <a href="/customers/add.php" class="btn btn-primary">
        <i class="bi bi-person-plus me-2"></i><?= __('cust_add') ?>
    </a>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="<?= __('cust_search') ?>"
                   value="<?= htmlspecialchars($search) ?>" style="max-width:300px;">
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
            <?php if ($search): ?>
                <a href="index.php" class="btn btn-sm btn-light"><?= __('btn_clear') ?></a>
            <?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="d-none d-md-table-cell">#</th>
                    <th><?= __('field_name') ?></th>
                    <th class="d-none d-md-table-cell"><?= __('field_shop') ?></th>
                    <th class="d-none d-sm-table-cell"><?= __('field_phone') ?></th>
                    <th class="d-none d-sm-table-cell"><?= __('cust_invoices') ?></th>
                    <th><?= __('cust_debt') ?></th>
                    <th><?= __('field_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                <tr><td colspan="7" class="text-center text-muted py-5"><?= __('no_data') ?></td></tr>
                <?php else: ?>
                <?php foreach ($customers as $i => $c): ?>
                <tr>
                    <td class="text-muted small d-none d-md-table-cell"><?= $i + 1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                                 style="width:34px;height:34px;background:var(--w11-blue);font-size:0.8rem;flex-shrink:0;border-radius:8px!important;">
                                <?= strtoupper(substr($c['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($c['name']) ?></div>
                                <div class="text-muted d-md-none" style="font-size:0.72rem;">
                                    <?= htmlspecialchars($c['shop_name']) ?>
                                    <?php if ($c['phone']): ?> · <?= htmlspecialchars($c['phone']) ?><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell"><?= htmlspecialchars($c['shop_name']) ?></td>
                    <td class="d-none d-sm-table-cell"><?= htmlspecialchars($c['phone']) ?></td>
                    <td class="d-none d-sm-table-cell"><span class="badge bg-light text-dark"><?= $c['sale_count'] ?></span></td>
                    <td>
                        <?php if ($c['total_debt'] > 0): ?>
                            <div class="fw-bold text-danger">؋ <?= number_format($c['total_debt'], 0) ?></div>
                            <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatMoney(fromAFN($c['total_debt'], $rateUSD), 'USD') ?> · <?= formatMoney(fromAFN($c['total_debt'], $ratePKR), 'PKR') ?></div>
                        <?php else: ?>
                            <span class="text-success fw-semibold"><?= __('cust_cleared') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-light me-1" title="<?= __('btn_view') ?>">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-light me-1" title="<?= __('btn_edit') ?>">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <?php if (isAdmin()): ?>
                        <a href="delete.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('<?= htmlspecialchars(addslashes(__('confirm_delete'))) ?>')" title="<?= __('btn_delete') ?>">
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
