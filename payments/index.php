<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = __('pay_title');

$settings  = getSettings($pdo);
$rate      = (float)($settings['exchange_rate'] ?? 90);
$secCur    = $settings['secondary_currency'] ?? 'USD';

// ── Period filter ──
$period = in_array($_GET['period'] ?? '', ['today','week','month','all'])
    ? $_GET['period'] : 'all';

$periodWhere = match($period) {
    'today' => "AND DATE(p.created_at) = CURDATE()",
    'week'  => "AND YEARWEEK(p.created_at, 1) = YEARWEEK(CURDATE(), 1)",
    'month' => "AND DATE_FORMAT(p.created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
    default => "",
};

$payments = $pdo->query("
    SELECT p.*, c.name AS customer_name, c.shop_name, u.full_name AS created_by
    FROM payments p
    JOIN customers c ON c.id = p.customer_id
    JOIN users u ON u.id = p.created_by
    WHERE 1=1 $periodWhere
    ORDER BY p.created_at DESC
")->fetchAll();

foreach ($payments as &$p) {
    if ((float)$p['amount_afn'] == 0 && (float)$p['amount'] > 0) {
        $p['amount_afn'] = $p['amount'];
    }
}
unset($p);
$totalAfn = array_sum(array_column($payments, 'amount_afn'));
$debt     = (float)$pdo->query("SELECT COALESCE(SUM(total_debt),0) FROM customers")->fetchColumn();

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
<div class="d-flex align-items-center gap-2 flex-wrap mb-3">
    <span class="small fw-semibold text-muted"><?= __('period_showing') ?>:</span>
    <?php foreach ($payPeriodLabels as $pk => $pl): ?>
    <a href="?period=<?= $pk ?>" class="btn btn-sm <?= $period === $pk ? 'btn-primary' : 'btn-outline-secondary' ?>">
        <?php if ($pk === 'today'): ?><i class="bi bi-sun me-1"></i>
        <?php elseif ($pk === 'week'): ?><i class="bi bi-calendar-week me-1"></i>
        <?php elseif ($pk === 'month'): ?><i class="bi bi-calendar-month me-1"></i>
        <?php else: ?><i class="bi bi-infinity me-1"></i>
        <?php endif; ?>
        <?= $pl ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="alert alert-secondary py-2 small mb-3">
    <i class="bi bi-currency-exchange me-1"></i>
    <?= __('pay_rate_label') ?>: <strong>1 <?= htmlspecialchars($secCur) ?> = <?= number_format($rate, 2) ?> ؋</strong>
    <?php if (isAdmin()): ?>
    &nbsp;—&nbsp;<a href="/fzl/admin/settings.php"><?= __('btn_update') ?></a>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">
                    <?= __('pay_total_collected') ?>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1" style="font-size:0.65rem;"><?= $payPeriodLabels[$period] ?></span>
                </div>
                <div class="fw-bold fs-5 text-success"><?= formatAFN($totalAfn) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($totalAfn, $rate), $secCur) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1"><?= __('pay_total_rec') ?></div>
                <div class="fw-bold fs-5 text-danger"><?= formatAFN($debt) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($debt, $rate), $secCur) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">
                    <?= __('pay_records') ?>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1" style="font-size:0.65rem;"><?= $payPeriodLabels[$period] ?></span>
                </div>
                <div class="fw-bold fs-5"><?= count($payments) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="d-none d-md-table-cell">#</th>
                    <th><?= __('nav_customers') ?></th>
                    <th><?= __('pay_paid_amount') ?></th>
                    <th class="d-none d-sm-table-cell"><?= __('pay_currency') ?></th>
                    <th class="d-none d-sm-table-cell"><?= __('pay_afn_equiv') ?></th>
                    <th class="d-none d-lg-table-cell"><?= __('field_notes') ?></th>
                    <th class="d-none d-md-table-cell"><?= __('field_by') ?></th>
                    <th class="d-none d-md-table-cell"><?= __('field_date') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                <tr><td colspan="8" class="text-center text-muted py-5"><?= __('pay_no_data') ?></td></tr>
                <?php else: ?>
                <?php foreach ($payments as $i => $p):
                    $amtAfn = (float)$p['amount_afn'] ?: (float)$p['amount'];
                    $cur    = $p['currency'] ?? 'AFN';
                    $exRate = (float)($p['exchange_rate'] ?? 1);
                ?>
                <tr>
                    <td class="text-muted small d-none d-md-table-cell"><?= $i + 1 ?></td>
                    <td>
                        <a href="/fzl/customers/view.php?id=<?= $p['customer_id'] ?>" class="fw-semibold text-decoration-none">
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
                        <div class="d-sm-none small text-muted"><?= date('d M Y', strtotime($p['created_at'])) ?></div>
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
                    <td class="text-muted small d-none d-lg-table-cell"><?= htmlspecialchars($p['notes'] ?: '—') ?></td>
                    <td class="text-muted small d-none d-md-table-cell"><?= htmlspecialchars($p['created_by']) ?></td>
                    <td class="text-muted small d-none d-md-table-cell"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
