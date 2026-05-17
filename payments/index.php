<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = __('pay_title');

// ── Period filter ──
$period = in_array($_GET['period'] ?? '', ['today','week','month','all'])
    ? $_GET['period'] : 'all';

$periodWhere = match($period) {
    'today' => "AND DATE(p.created_at) = CURDATE()",
    'week'  => "AND YEARWEEK(p.created_at, 1) = YEARWEEK(CURDATE(), 1)",
    'month' => "AND DATE_FORMAT(p.created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
    default => "",
};

$search      = trim($_GET['search'] ?? '');
$searchWhere = '';
$params      = [];
if ($search) {
    $searchWhere = "AND (c.name LIKE ? OR c.shop_name LIKE ?)";
    $params      = ["%$search%", "%$search%"];
}

$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM payments p
    JOIN customers c ON c.id = p.customer_id
    WHERE 1=1 $periodWhere $searchWhere
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Auto-migrate
try { $pdo->exec("ALTER TABLE payments ADD COLUMN payment_date DATE NULL AFTER notes"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE payments ADD COLUMN sale_id INT NULL AFTER customer_id"); } catch (\PDOException $e) {}

$stmt = $pdo->prepare("
    SELECT p.*, c.name AS customer_name, c.shop_name, u.full_name AS created_by,
           s.id AS inv_id, s.bill_no AS inv_bill_no
    FROM payments p
    JOIN customers c ON c.id = p.customer_id
    JOIN users u ON u.id = p.created_by
    LEFT JOIN sales s ON s.id = p.sale_id
    WHERE 1=1 $periodWhere $searchWhere
    ORDER BY COALESCE(p.payment_date, DATE(p.created_at)) DESC, p.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$payments = $stmt->fetchAll();

foreach ($payments as &$p) {
    if ((float)$p['amount_afn'] == 0 && (float)$p['amount'] > 0) {
        $p['amount_afn'] = $p['amount'];
    }
}
unset($p);

function toShamsi(int $gy, int $gm, int $gd): array {
    $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
    if ($gy > 1600) { $jy = 979; $gy -= 1600; } else { $jy = 0; $gy -= 621; }
    $gy2  = $gm > 2 ? $gy + 1 : $gy;
    $days = 365*$gy + intdiv($gy2+3,4) - intdiv($gy2+99,100) + intdiv($gy2+399,400)
            - 80 + $gd + $g_d_m[$gm - 1];
    $jy  += 33 * intdiv($days, 12053); $days %= 12053;
    $jy  +=  4 * intdiv($days,  1461); $days %= 1461;
    if ($days > 365) { $jy += intdiv($days-1, 365); $days = ($days-1) % 365; }
    $jm = $days < 186 ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
    $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);
    return ['y' => $jy, 'm' => $jm, 'd' => $jd];
}

function fmtPayDate(array $p): string {
    $d = $p['payment_date'] ?? null;
    $ts = $d ? strtotime($d) : strtotime($p['created_at']);
    $greg = date('d M Y', $ts);
    $s = toShamsi((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    $shamsi = $s['y'] . '/' . str_pad($s['m'], 2, '0', STR_PAD_LEFT) . '/' . str_pad($s['d'], 2, '0', STR_PAD_LEFT);
    return '<div>' . $greg . '</div><div class="text-muted" style="font-size:0.7rem;">' . $shamsi . '</div>';
}

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('pay_title') ?></h4>
        <p class="text-muted small mb-0"><?= __('pay_sub') ?></p>
    </div>
    <a href="add.php" class="btn btn-success"><i class="bi bi-cash me-2"></i><?= __('pay_add') ?></a>
</div>

<!-- Period filter bar -->
<?php
$payPeriodLabels = [
    'all'   => __('period_all'),
    'today' => __('period_today'),
    'week'  => __('period_week'),
    'month' => __('period_month'),
];
?>
<div class="card">
    <div class="card-header py-3">
        <form method="GET" class="d-flex align-items-center gap-2 justify-content-between flex-wrap">
            <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
            <!-- Search -->
            <div class="input-group" style="max-width:340px;">
                <span class="input-group-text bg-white border-end-0 text-muted">
                    <i class="bi bi-search" style="font-size:.8rem;"></i>
                </span>
                <input type="text" name="search" class="form-control border-start-0 ps-0"
                       placeholder="Search customer or shop…"
                       value="<?= htmlspecialchars($search) ?>">
                <?php if ($search): ?>
                <a href="?period=<?= $period ?>" class="btn btn-outline-secondary border-start-0" title="Clear">
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
                $icons = ['all'=>'infinity','today'=>'sun','week'=>'calendar-week','month'=>'calendar-month'];
                foreach ($payPeriodLabels as $pk => $pl):
                    $active = $period === $pk;
                ?>
                <a href="?period=<?= $pk ?><?= $search ? '&search='.urlencode($search) : '' ?>"
                   class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-light' ?>"
                   style="border-radius:20px;font-size:.78rem;">
                    <i class="bi bi-<?= $icons[$pk] ?> me-1"></i><?= $pl ?>
                </a>
                <?php endforeach; ?>
            </div>
            <!-- Count -->
            <span class="text-muted small ms-auto"><?= $totalRows ?> payments</span>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="d-none d-md-table-cell">#</th>
                    <th><?= __('nav_customers') ?></th>
                    <th><?= __('pay_paid_amount') ?></th>
                    <th class="d-none d-sm-table-cell"><?= __('pay_currency') ?></th>
                    <th class="d-none d-sm-table-cell"><?= __('pay_afn_equiv') ?></th>
                    <th class="d-none d-md-table-cell">Invoice</th>
                    <th class="d-none d-lg-table-cell"><?= __('field_notes') ?></th>
                    <th class="d-none d-md-table-cell"><?= __('field_by') ?></th>
                    <th class="d-none d-md-table-cell"><?= __('field_date') ?></th>
                    <?php if (isAdmin()): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                <tr><td colspan="<?= isAdmin() ? 9 : 8 ?>" class="text-center text-muted py-5"><?= __('pay_no_data') ?></td></tr>
                <?php else: ?>
                <?php foreach ($payments as $i => $p):
                    $amtAfn = (float)$p['amount_afn'] ?: (float)$p['amount'];
                    $cur    = $p['currency'] ?? 'AFN';
                    $exRate = (float)($p['exchange_rate'] ?? 1);
                ?>
                <tr>
                    <td class="text-muted small d-none d-md-table-cell"><?= $i + 1 ?></td>
                    <td>
                        <a href="/customers/view.php?id=<?= $p['customer_id'] ?>" class="fw-semibold text-decoration-none">
                            <?= htmlspecialchars($p['customer_name']) ?>
                        </a>
                        <div class="text-muted" style="font-size:0.75rem;">
                            <?= htmlspecialchars($p['shop_name']) ?>
                            <span class="d-sm-none ms-1">
                                <span class="badge <?= $cur === 'AFN' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-primary-subtle text-primary border border-primary-subtle' ?>" style="font-size:0.62rem;">
                                    <?= htmlspecialchars($cur) ?>
                                </span>
                            </span>
                        </div>
                        <div class="d-sm-none small text-muted"><?= fmtPayDate($p) ?></div>
                    </td>
                    <td class="fw-bold text-success"><?= formatMoney((float)$p['amount'], $cur) ?></td>
                    <td class="d-none d-sm-table-cell">
                        <span class="badge <?= $cur === 'AFN' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-primary-subtle text-primary border border-primary-subtle' ?>">
                            <?= htmlspecialchars($cur) ?>
                        </span>
                        <?php if ($cur !== 'AFN' && $exRate > 1): ?>
                        <div class="text-muted" style="font-size:0.7rem;"><?= __('set_rate_label') ?> <?= number_format($exRate, 2) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold d-none d-sm-table-cell"><?= formatAFN($amtAfn) ?></td>
                    <td class="d-none d-md-table-cell">
                        <?php if ($p['inv_id']): ?>
                        <a href="/sales/view.php?id=<?= $p['inv_id'] ?>" class="text-decoration-none fw-semibold" style="font-size:0.8rem;">
                            #<?= str_pad($p['inv_id'], 4, '0', STR_PAD_LEFT) ?>
                        </a>
                        <?php if ($p['inv_bill_no']): ?>
                        <div class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars($p['inv_bill_no']) ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:0.75rem;">General</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small d-none d-lg-table-cell"><?= htmlspecialchars($p['notes'] ?: '—') ?></td>
                    <td class="text-muted small d-none d-md-table-cell"><?= htmlspecialchars($p['created_by']) ?></td>
                    <td class="text-muted small d-none d-md-table-cell"><?= fmtPayDate($p) ?></td>
                    <?php if (isAdmin()): ?>
                    <td>
                        <a href="delete.php?id=<?= $p['id'] ?>"
                           class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('<?= htmlspecialchars(addslashes(__('confirm_delete'))) ?>')"
                           title="<?= __('btn_delete') ?>">
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
