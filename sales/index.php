<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';

$pageTitle = __('sale_title');

require_once '../includes/currency.php';
$rates = getAllRates($pdo);

// Auto-migrate columns that the queries below rely on
foreach ([
    "ALTER TABLE sales ADD COLUMN bill_no   VARCHAR(100) NULL AFTER id",
    "ALTER TABLE sales ADD COLUMN sale_date DATE         NULL AFTER bill_no",
    "ALTER TABLE sales ADD COLUMN currency  VARCHAR(10)  NULL DEFAULT 'AFN'",
    "ALTER TABLE sale_items ADD COLUMN custom_name VARCHAR(255) NULL",
    "ALTER TABLE sale_items MODIFY product_id INT NULL",
] as $_sql) { try { $pdo->exec($_sql); } catch (\PDOException $e) {} }

// ── Period filter ──
$period = in_array($_GET['period'] ?? '', ['today','week','month','all','custom'])
    ? $_GET['period'] : 'all';

// Custom range dates (validated to Y-m-d; default = this month → today).
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-01');
$dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : date('Y-m-d');

// Filter on the effective sale date so backdated invoices land correctly.
$_sEx = "COALESCE(s.sale_date, DATE(s.created_at))";
$periodWhere = match($period) {
    'today'  => "AND $_sEx = CURDATE()",
    'week'   => "AND YEARWEEK($_sEx, 1) = YEARWEEK(CURDATE(), 1)",
    'month'  => "AND DATE_FORMAT($_sEx, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
    'custom' => "AND $_sEx BETWEEN '$dateFrom' AND '$dateTo'",
    default  => "",
};

// Query-string tail that carries the custom range across links.
$rangeQS = $period === 'custom' ? '&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) : '';

$search = trim($_GET['search'] ?? '');
$params = [];
$searchWhere = '';
if ($search) {
    $searchWhere = "AND (c.name LIKE ? OR c.shop_name LIKE ?)";
    $params      = ["%$search%", "%$search%"];
}

// Count total rows for pagination
$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM sales s
    JOIN customers c ON c.id = s.customer_id
    WHERE 1=1 $periodWhere $searchWhere
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$sales = $pdo->prepare("
    SELECT s.id, s.bill_no, s.total_amount, s.paid_amount, s.balance, s.created_at, s.sale_date, s.notes,
           s.currency,
           c.name AS customer_name, c.shop_name,
           u.full_name AS created_by
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    JOIN users u ON u.id = s.created_by
    WHERE 1=1 $periodWhere $searchWhere
    ORDER BY COALESCE(s.sale_date, DATE(s.created_at)) DESC, s.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$sales->execute($params);
$sales = $sales->fetchAll();

// Batch-fetch sale items for all visible sales
$itemsBySale = [];
if (!empty($sales)) {
    $saleIds      = array_column($sales, 'id');
    $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
    $itemRows     = $pdo->prepare("
        SELECT si.sale_id, si.quantity, si.unit_price, si.subtotal,
               COALESCE(p.name, si.custom_name, 'Custom Item') AS product_name,
               p.size, p.color
        FROM sale_items si
        LEFT JOIN products p ON p.id = si.product_id
        WHERE si.sale_id IN ($placeholders)
        ORDER BY si.sale_id, si.id
    ");
    $itemRows->execute($saleIds);
    foreach ($itemRows->fetchAll() as $item) {
        $itemsBySale[$item['sale_id']][] = $item;
    }
}

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('sale_title') ?></h4>
        <p class="text-muted small mb-0"><?= __('sale_sub') ?></p>
    </div>
    <a href="create.php" class="btn btn-primary"><i class="bi bi-plus-circle me-2"></i><?= __('sale_add') ?></a>
</div>

<?php
$salePeriodLabels = [
    'all'    => __('period_all'),
    'today'  => __('period_today'),
    'week'   => __('period_week'),
    'month'  => __('period_month'),
    'custom' => 'Custom',
];
?>

<div class="card">
    <div class="card-header py-3">
        <form method="GET" class="d-flex align-items-center gap-2 justify-content-between flex-wrap">
            <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
            <?php if ($period === 'custom'): ?>
            <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
            <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
            <?php endif; ?>
            <!-- Search -->
            <div class="input-group" style="max-width:340px;">
                <span class="input-group-text bg-white border-end-0 text-muted">
                    <i class="bi bi-search" style="font-size:.8rem;"></i>
                </span>
                <input type="text" name="search" class="form-control border-start-0 ps-0"
                       placeholder="Search customer or shop…"
                       value="<?= htmlspecialchars($search) ?>">
                <?php if ($search): ?>
                <a href="?period=<?= $period ?><?= $rangeQS ?>" class="btn btn-outline-secondary border-start-0" title="Clear">
                    <i class="bi bi-x"></i>
                </a>
                <?php else: ?>
                <button class="btn btn-outline-secondary border-start-0" type="submit">
                    <i class="bi bi-arrow-right" style="font-size:.8rem;"></i>
                </button>
                <?php endif; ?>
            </div>
            <!-- Period pills -->
            <div class="d-flex gap-1 flex-wrap">
                <?php
                $icons = ['all'=>'infinity','today'=>'sun','week'=>'calendar-week','month'=>'calendar-month','custom'=>'calendar-range'];
                foreach ($salePeriodLabels as $pk => $pl):
                    $active = $period === $pk;
                    $carry  = ($search ? '&search='.urlencode($search) : '') . ($pk === 'custom' ? $rangeQS : '');
                ?>
                <a href="?period=<?= $pk ?><?= $carry ?>"
                   class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-light' ?>"
                   style="border-radius:20px;font-size:.78rem;">
                    <i class="bi bi-<?= $icons[$pk] ?> me-1"></i><?= $pl ?>
                </a>
                <?php endforeach; ?>
            </div>
            <!-- Count -->
            <span class="text-muted small ms-auto"><?= $totalRows ?> <?= __('rep_invoices') ?></span>
        </form>
        <?php if ($period === 'custom'): ?>
        <!-- Custom date range -->
        <form method="GET" class="d-flex align-items-center gap-2 flex-wrap mt-2 pt-2 border-top">
            <input type="hidden" name="period" value="custom">
            <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
            <label class="small fw-semibold text-muted mb-0">From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"
                   class="form-control form-control-sm" style="width:150px;" required>
            <label class="small fw-semibold text-muted mb-0">To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"
                   class="form-control form-control-sm" style="width:150px;" required>
            <button class="btn btn-sm btn-primary"><i class="bi bi-arrow-right me-1"></i>Apply</button>
            <span class="text-muted small ms-2">
                <?= date('d M Y', strtotime($dateFrom)) ?> — <?= date('d M Y', strtotime($dateTo)) ?>
            </span>
        </form>
        <?php endif; ?>
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
                <?php foreach ($sales as $s):
                    $sItems  = $itemsBySale[$s['id']] ?? [];
                    $nItems  = count($sItems);
                    $cur     = $s['currency'] ?? 'AFN';
                    $curRate = $rates[$cur] ?? 1;
                ?>
                <tr style="cursor:pointer;" onclick="toggleItems(<?= $s['id'] ?>)">
                    <td>
                        <div class="fw-semibold" style="font-size:0.82rem;">
                            <?= $s['bill_no'] ? htmlspecialchars($s['bill_no']) : '#'.str_pad($s['id'], 4, '0', STR_PAD_LEFT) ?>
                        </div>
                        <div class="d-flex align-items-center gap-1 mt-1 flex-wrap">
                            <?php if ($cur !== 'AFN'): ?>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size:0.62rem;"><?= $cur ?></span>
                            <?php endif; ?>
                            <?php if ($nItems > 0): ?>
                            <span class="text-muted" style="font-size:0.7rem;">
                                <i class="bi bi-chevron-down me-1 toggle-icon-<?= $s['id'] ?>"></i><?= $nItems ?> item<?= $nItems > 1 ? 's' : '' ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="fw-semibold small"><?= htmlspecialchars($s['customer_name']) ?></div>
                        <div class="text-muted" style="font-size:0.75rem;"><?= htmlspecialchars($s['shop_name']) ?></div>
                    </td>
                    <td class="fw-semibold">
                        <?php if ($cur !== 'AFN'): ?>
                            <?= formatMoney(fromAFN($s['total_amount'], $curRate), $cur) ?>
                            <div class="text-muted" style="font-size:0.72rem;">≈ ؋ <?= number_format($s['total_amount'], 0) ?></div>
                        <?php else: ?>
                            ؋ <?= number_format($s['total_amount'], 0) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-success d-none d-sm-table-cell">
                        <?php if ($cur !== 'AFN'): ?>
                            <?= $s['paid_amount'] > 0 ? formatMoney(fromAFN($s['paid_amount'], $curRate), $cur) : formatMoney(0, $cur) ?>
                            <?php if ($s['paid_amount'] > 0): ?>
                            <div class="text-muted" style="font-size:0.72rem;">≈ ؋ <?= number_format($s['paid_amount'], 0) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            ؋ <?= number_format($s['paid_amount'], 0) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($s['balance'] > 0): ?>
                            <?php if ($cur !== 'AFN'): ?>
                                <div class="fw-bold text-danger"><?= formatMoney(fromAFN($s['balance'], $curRate), $cur) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;">≈ ؋ <?= number_format($s['balance'], 0) ?></div>
                            <?php else: ?>
                                <span class="fw-bold text-danger">؋ <?= number_format($s['balance'], 0) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle"><?= __('sale_fully_paid') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small d-none d-md-table-cell"><?= htmlspecialchars($s['created_by']) ?></td>
                    <td class="text-muted small d-none d-md-table-cell"><?= date('d M Y', strtotime($s['sale_date'] ?: $s['created_at'])) ?></td>
                    <td onclick="event.stopPropagation()">
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
                <?php if ($nItems > 0): ?>
                <tr id="items-<?= $s['id'] ?>" style="display:none;background:rgba(0,103,192,0.03);">
                    <td colspan="8" style="padding:0 0 0 36px;">
                        <table class="table table-sm mb-0" style="font-size:0.77rem;border:none;">
                            <thead style="background:rgba(0,0,0,0.03);">
                                <tr>
                                    <th class="border-0 py-1">Product</th>
                                    <th class="border-0 py-1 text-end" style="width:70px;">Qty</th>
                                    <th class="border-0 py-1 text-end" style="width:100px;">Unit Price</th>
                                    <th class="border-0 py-1 text-end" style="width:100px;">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sItems as $it): ?>
                            <tr>
                                <td class="border-0 py-1">
                                    <span class="fw-semibold"><?= htmlspecialchars($it['product_name']) ?></span>
                                    <?php if ($it['size'] || $it['color']): ?>
                                    <span class="text-muted ms-1" style="font-size:0.7rem;"><?= htmlspecialchars(trim(($it['size'] ?? '').' '.($it['color'] ?? ''))) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="border-0 py-1 text-end text-muted"><?php
                                    $q = (float)$it['quantity'];
                                    echo ($q == floor($q))
                                        ? number_format($q, 0)
                                        : rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
                                ?> pcs</td>
                                <td class="border-0 py-1 text-end text-muted">
                                    <?php if ($cur !== 'AFN'): ?>
                                        <?= formatMoney(fromAFN((float)$it['unit_price'], $curRate), $cur) ?>
                                        <div style="font-size:0.68rem;color:#aaa;">؋ <?= number_format($it['unit_price'], 0) ?></div>
                                    <?php else: ?>
                                        ؋ <?= number_format($it['unit_price'], 2) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="border-0 py-1 text-end fw-semibold">
                                    <?php if ($cur !== 'AFN'): ?>
                                        <?= formatMoney(fromAFN((float)$it['subtotal'], $curRate), $cur) ?>
                                        <div style="font-size:0.68rem;color:#aaa;">؋ <?= number_format($it['subtotal'], 0) ?></div>
                                    <?php else: ?>
                                        ؋ <?= number_format($it['subtotal'], 2) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>
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

<script>
function toggleItems(saleId) {
    const row  = document.getElementById('items-' + saleId);
    const icon = document.querySelector('.toggle-icon-' + saleId);
    if (!row) return;
    const open = row.style.display === 'none' || row.style.display === '';
    row.style.display = open ? 'table-row' : 'none';
    if (icon) {
        icon.classList.toggle('bi-chevron-down', !open);
        icon.classList.toggle('bi-chevron-up',   open);
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
