<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = __('rep_title');

$settings = getSettings($pdo);
$rate     = (float)($settings['exchange_rate'] ?? 90);
$secCur   = $settings['secondary_currency'] ?? 'USD';

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

// ── Sales summary ──
$salesData = $pdo->prepare("
    SELECT COUNT(*) AS count,
           COALESCE(SUM(total_amount),0) AS total,
           COALESCE(SUM(paid_amount),0)  AS collected,
           COALESCE(SUM(balance),0)      AS pending
    FROM sales WHERE DATE(created_at) BETWEEN ? AND ?
");
$salesData->execute([$from, $to]);
$salesData = $salesData->fetch();

// ── Daily breakdown ──
$dailySales = $pdo->prepare("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt, SUM(total_amount) AS total, SUM(paid_amount) AS collected
    FROM sales
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY day DESC
");
$dailySales->execute([$from, $to]);
$dailySales = $dailySales->fetchAll();

// ── Top customers ──
$topCustomers = $pdo->prepare("
    SELECT c.id, c.name, c.shop_name, COUNT(s.id) AS invoices, SUM(s.total_amount) AS total
    FROM sales s JOIN customers c ON c.id = s.customer_id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY c.id ORDER BY total DESC LIMIT 10
");
$topCustomers->execute([$from, $to]);
$topCustomers = $topCustomers->fetchAll();

// ── Top products ──
$topProducts = $pdo->prepare("
    SELECT p.name, p.size, p.color, SUM(si.quantity) AS qty_sold, SUM(si.subtotal) AS revenue
    FROM sale_items si
    JOIN products p  ON p.id  = si.product_id
    JOIN sales s     ON s.id  = si.sale_id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY p.id ORDER BY qty_sold DESC LIMIT 10
");
$topProducts->execute([$from, $to]);
$topProducts = $topProducts->fetchAll();

// ── Customer debts (all-time) ──
$debtors = $pdo->query("
    SELECT id, name, shop_name, phone, total_debt
    FROM customers WHERE total_debt > 0
    ORDER BY total_debt DESC
")->fetchAll();
$totalDebt   = array_sum(array_column($debtors, 'total_debt'));
$debtorCount = count($debtors);

// ── Payments received in period ──
$paymentsData = $pdo->prepare("
    SELECT COUNT(*) AS count, COALESCE(SUM(GREATEST(amount_afn, amount)), 0) AS total
    FROM payments WHERE DATE(created_at) BETWEEN ? AND ?
");
$paymentsData->execute([$from, $to]);
$paymentsData = $paymentsData->fetch();

require_once '../includes/header.php';
?>

<!-- Page header -->
<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h4 class="mb-1"><?= __('rep_title') ?></h4>
        <p class="text-muted small mb-0"><?= __('rep_sub') ?></p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span class="text-muted small"><?= __('set_rate_label') ?> 1 <?= htmlspecialchars($secCur) ?> = <?= number_format($rate, 2) ?> ؋
            <?php if (isAdmin()): ?>&nbsp;<a href="/admin/settings.php" class="small"><?= __('btn_update') ?></a><?php endif; ?>
        </span>
        <a href="report-print.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"
           target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer me-1"></i>Print / PDF
        </a>
    </div>
</div>

<!-- ── Filter card ── -->
<div class="card mb-4">
    <div class="card-body py-2">
        <div class="d-flex gap-2 flex-wrap mb-2">
            <span class="small fw-semibold text-muted d-flex align-items-center"><?= __('period_showing') ?>:</span>
            <button type="button" class="btn btn-sm btn-outline-secondary period-preset" data-period="today">
                <i class="bi bi-sun me-1"></i><?= __('period_today') ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary period-preset" data-period="week">
                <i class="bi bi-calendar-week me-1"></i><?= __('period_week') ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary period-preset" data-period="month">
                <i class="bi bi-calendar-month me-1"></i><?= __('period_month') ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary period-preset" data-period="year">
                <i class="bi bi-calendar-range me-1"></i><?= date('Y') ?>
            </button>
        </div>
        <form method="GET" id="rep-form" class="d-flex align-items-center gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 small fw-semibold"><?= __('rep_from') ?></label>
                <input type="date" name="from" id="rep-from" class="form-control form-control-sm"
                       value="<?= $from ?>" style="width:150px;">
            </div>
            <div class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 small fw-semibold"><?= __('rep_to') ?></label>
                <input type="date" name="to" id="rep-to" class="form-control form-control-sm"
                       value="<?= $to ?>" style="width:150px;">
            </div>
            <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i><?= __('rep_apply') ?></button>
            <a href="reports.php" class="btn btn-sm btn-light"><?= __('rep_this_month') ?></a>
        </form>
    </div>
</div>

<!-- ── Summary metric cards ── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div class="stat-icon ic-blue mb-2 mx-auto"><i class="bi bi-receipt"></i></div>
                <div class="text-muted small mb-1"><?= __('rep_total_sales') ?></div>
                <div class="fw-bold fs-6"><?= formatAFN($salesData['total']) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($salesData['total'], $rate), $secCur) ?></div>
                <div class="text-muted" style="font-size:0.72rem;"><?= $salesData['count'] ?> <?= __('rep_invoices') ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div class="stat-icon ic-green mb-2 mx-auto"><i class="bi bi-cash-stack"></i></div>
                <div class="text-muted small mb-1"><?= __('rep_collected') ?></div>
                <div class="fw-bold fs-6 text-success"><?= formatAFN($salesData['collected']) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($salesData['collected'], $rate), $secCur) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div class="stat-icon ic-amber mb-2 mx-auto"><i class="bi bi-hourglass-split"></i></div>
                <div class="text-muted small mb-1"><?= __('rep_pending') ?></div>
                <div class="fw-bold fs-6 text-warning"><?= formatAFN($salesData['pending']) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($salesData['pending'], $rate), $secCur) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div class="stat-icon ic-red mb-2 mx-auto"><i class="bi bi-exclamation-triangle"></i></div>
                <div class="text-muted small mb-1"><?= __('rep_receivable') ?></div>
                <div class="fw-bold fs-6 text-danger"><?= formatAFN($totalDebt) ?></div>
                <div class="text-muted small"><?= $debtorCount ?> customers</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Tabs ── -->
<ul class="nav nav-tabs mb-0" id="repTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-daily" type="button">
            <i class="bi bi-calendar3 me-1"></i>Daily Breakdown
            <?php if ($salesData['count']): ?>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1"><?= $salesData['count'] ?></span>
            <?php endif; ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-customers" type="button">
            <i class="bi bi-people me-1"></i><?= __('rep_top_cust') ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-products" type="button">
            <i class="bi bi-box-seam me-1"></i><?= __('rep_top_prod') ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-debts" type="button">
            <i class="bi bi-exclamation-circle me-1"></i>Customer Debts
            <?php if ($debtorCount): ?>
            <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1"><?= $debtorCount ?></span>
            <?php endif; ?>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- Tab 1: Daily Breakdown -->
    <div class="tab-pane fade show active" id="tab-daily" role="tabpanel">
        <div class="card" style="border-top-left-radius:0;">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th><?= __('field_date') ?></th>
                            <th><?= __('rep_invoices') ?></th>
                            <th><?= __('rep_total_sales') ?></th>
                            <th><?= __('rep_collected') ?></th>
                            <th>Uncollected</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dailySales)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4"><?= __('rep_no_data') ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($dailySales as $d): ?>
                        <tr>
                            <td class="fw-semibold"><?= date('D, d M Y', strtotime($d['day'])) ?></td>
                            <td><?= $d['cnt'] ?></td>
                            <td class="fw-semibold"><?= formatAFN($d['total']) ?></td>
                            <td class="text-success fw-semibold"><?= formatAFN($d['collected']) ?></td>
                            <td class="text-danger"><?= formatAFN($d['total'] - $d['collected']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($dailySales)): ?>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td>Total</td>
                            <td><?= $salesData['count'] ?></td>
                            <td><?= formatAFN($salesData['total']) ?></td>
                            <td class="text-success"><?= formatAFN($salesData['collected']) ?></td>
                            <td class="text-danger"><?= formatAFN($salesData['pending']) ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Tab 2: Top Customers -->
    <div class="tab-pane fade" id="tab-customers" role="tabpanel">
        <div class="card" style="border-top-left-radius:0;">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= __('nav_customers') ?></th>
                            <th><?= __('rep_invoices') ?></th>
                            <th><?= __('rep_revenue') ?></th>
                            <th><?= __('field_actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topCustomers)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4"><?= __('rep_no_data') ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($topCustomers as $i => $c): ?>
                        <tr>
                            <td>
                                <?php if ($i < 3): ?>
                                <span class="badge <?= ['bg-warning text-dark','bg-secondary','bg-secondary bg-opacity-50'][$i] ?>">
                                    <?= ['🥇','🥈','🥉'][$i] ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted"><?= $i + 1 ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($c['name']) ?></div>
                                <div class="text-muted" style="font-size:0.75rem;"><?= htmlspecialchars($c['shop_name']) ?></div>
                            </td>
                            <td><?= $c['invoices'] ?></td>
                            <td>
                                <div class="fw-semibold"><?= formatAFN($c['total']) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatMoney(fromAFN($c['total'], $rate), $secCur) ?></div>
                            </td>
                            <td>
                                <a href="/customers/view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-light">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Tab 3: Top Products -->
    <div class="tab-pane fade" id="tab-products" role="tabpanel">
        <div class="card" style="border-top-left-radius:0;">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= __('nav_products') ?></th>
                            <th><?= __('rep_qty') ?></th>
                            <th><?= __('rep_revenue') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topProducts)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4"><?= __('rep_no_data') ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($topProducts as $i => $p): ?>
                        <tr>
                            <td>
                                <?php if ($i < 3): ?>
                                <span class="badge <?= ['bg-warning text-dark','bg-secondary','bg-secondary bg-opacity-50'][$i] ?>">
                                    <?= ['🥇','🥈','🥉'][$i] ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted"><?= $i + 1 ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($p['name']) ?></div>
                                <div class="text-muted" style="font-size:0.75rem;">
                                    <?= htmlspecialchars($p['size'] . ($p['color'] ? ' / ' . $p['color'] : '')) ?>
                                </div>
                            </td>
                            <td>
                                <span class="fw-semibold"><?= number_format($p['qty_sold']) ?></span>
                                <span class="text-muted"> pcs</span>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= formatAFN($p['revenue']) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatMoney(fromAFN($p['revenue'], $rate), $secCur) ?></div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Tab 4: Customer Debts -->
    <div class="tab-pane fade" id="tab-debts" role="tabpanel">
        <div class="card" style="border-top-left-radius:0;">
            <?php if (!empty($debtors)): ?>
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Outstanding Receivables</span>
                <span class="fw-bold text-danger"><?= __('rep_receivable') ?>: <?= formatAFN($totalDebt) ?> &nbsp;<span class="text-muted fw-normal small">≈ <?= formatMoney(fromAFN($totalDebt, $rate), $secCur) ?></span></span>
            </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= __('nav_customers') ?></th>
                            <th><?= __('field_phone') ?></th>
                            <th>Debt (؋)</th>
                            <th>Debt (<?= htmlspecialchars($secCur) ?>)</th>
                            <th><?= __('field_actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($debtors)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4"><?= __('dash_no_debts') ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($debtors as $i => $d): ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($d['name']) ?></div>
                                <div class="text-muted" style="font-size:0.75rem;"><?= htmlspecialchars($d['shop_name']) ?></div>
                            </td>
                            <td class="text-muted"><?= htmlspecialchars($d['phone'] ?: '—') ?></td>
                            <td class="fw-bold text-danger">؋ <?= number_format($d['total_debt'], 0) ?></td>
                            <td class="text-muted"><?= formatMoney(fromAFN($d['total_debt'], $rate), $secCur) ?></td>
                            <td>
                                <a href="/customers/view.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-light me-1">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="/payments/add.php?customer=<?= $d['id'] ?>" class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-cash me-1"></i>Pay
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /.tab-content -->

<style>
.stat-icon { width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.05rem; }
.ic-blue   { background:linear-gradient(135deg,#60b0ff,#0067C0);color:#fff; }
.ic-green  { background:linear-gradient(135deg,#54D47A,#107C10);color:#fff; }
.ic-amber  { background:linear-gradient(135deg,#FFBE57,#FF9800);color:#fff; }
.ic-red    { background:linear-gradient(135deg,#FF7C98,#C42B1C);color:#fff; }
.nav-tabs .nav-link { font-size:0.83rem; font-weight:600; }
.tab-pane .card { border-top-left-radius: 0 !important; }
</style>

<script>
(function () {
    const fmt = d => d.toISOString().slice(0, 10);
    document.querySelectorAll('.period-preset').forEach(btn => {
        btn.addEventListener('click', function () {
            const today = new Date();
            let from, to = fmt(today);
            switch (this.dataset.period) {
                case 'today': from = to; break;
                case 'week': {
                    const d = new Date(today);
                    d.setDate(today.getDate() - ((today.getDay() + 6) % 7));
                    from = fmt(d); break;
                }
                case 'month':
                    from = fmt(new Date(today.getFullYear(), today.getMonth(), 1)); break;
                case 'year':
                    from = fmt(new Date(today.getFullYear(), 0, 1)); break;
            }
            document.getElementById('rep-from').value = from;
            document.getElementById('rep-to').value   = to;
            document.getElementById('rep-form').submit();
        });
    });
}());
</script>

<?php require_once '../includes/footer.php'; ?>
