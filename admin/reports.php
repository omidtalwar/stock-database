<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = 'Reports';

$settings  = getSettings($pdo);
$rate      = (float)($settings['exchange_rate']      ?? 90);
$secCur    = $settings['secondary_currency'] ?? 'USD';

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

$salesData = $pdo->prepare("
    SELECT COUNT(*) AS count, COALESCE(SUM(total_amount),0) AS total,
           COALESCE(SUM(paid_amount),0) AS collected, COALESCE(SUM(balance),0) AS pending
    FROM sales WHERE DATE(created_at) BETWEEN ? AND ?
");
$salesData->execute([$from, $to]);
$salesData = $salesData->fetch();

$topCustomers = $pdo->prepare("
    SELECT c.name, c.shop_name, COUNT(s.id) AS invoices, SUM(s.total_amount) AS total
    FROM sales s JOIN customers c ON c.id = s.customer_id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY c.id ORDER BY total DESC LIMIT 10
");
$topCustomers->execute([$from, $to]);
$topCustomers = $topCustomers->fetchAll();

$topProducts = $pdo->prepare("
    SELECT p.name, p.size, p.color, SUM(si.quantity) AS qty_sold, SUM(si.subtotal) AS revenue
    FROM sale_items si JOIN products p ON p.id = si.product_id
    JOIN sales s ON s.id = si.sale_id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY p.id ORDER BY qty_sold DESC LIMIT 10
");
$topProducts->execute([$from, $to]);
$topProducts = $topProducts->fetchAll();

$totalDebt = (float)$pdo->query("SELECT COALESCE(SUM(total_debt),0) FROM customers")->fetchColumn();

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1">Reports</h4>
        <p class="text-muted small mb-0">Sales analytics</p>
    </div>
    <div class="small text-muted">
        Rate: <strong>1 <?= htmlspecialchars($secCur) ?> = <?= number_format($rate, 2) ?> ؋</strong>
        &nbsp;<a href="/fzl/admin/settings.php">Update</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body py-2">
        <form method="GET" class="d-flex align-items-center gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 small fw-semibold">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= $from ?>" style="width:150px;">
            </div>
            <div class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 small fw-semibold">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= $to ?>" style="width:150px;">
            </div>
            <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
            <a href="reports.php" class="btn btn-sm btn-light">This Month</a>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Total Sales</div>
                <div class="fw-bold"><?= formatAFN($salesData['total']) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($salesData['total'], $rate), $secCur) ?></div>
                <div class="text-muted" style="font-size:0.72rem;"><?= $salesData['count'] ?> invoices</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Collected</div>
                <div class="fw-bold text-success"><?= formatAFN($salesData['collected']) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($salesData['collected'], $rate), $secCur) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Pending (Period)</div>
                <div class="fw-bold text-danger"><?= formatAFN($salesData['pending']) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($salesData['pending'], $rate), $secCur) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Total Receivable</div>
                <div class="fw-bold text-warning"><?= formatAFN($totalDebt) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($totalDebt, $rate), $secCur) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header fw-semibold">Top Customers (by Sales)</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead><tr><th>#</th><th>Customer</th><th>Invoices</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php if (empty($topCustomers)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No data for this period</td></tr>
                        <?php else: ?>
                        <?php foreach ($topCustomers as $i => $c): ?>
                        <tr>
                            <td class="text-muted"><?= $i+1 ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($c['name']) ?></div>
                                <div class="text-muted"><?= htmlspecialchars($c['shop_name']) ?></div>
                            </td>
                            <td><?= $c['invoices'] ?></td>
                            <td>
                                <div class="fw-semibold"><?= formatAFN($c['total']) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatMoney(fromAFN($c['total'], $rate), $secCur) ?></div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header fw-semibold">Top Products (by Qty Sold)</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead><tr><th>#</th><th>Product</th><th>Qty</th><th>Revenue</th></tr></thead>
                    <tbody>
                        <?php if (empty($topProducts)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No data for this period</td></tr>
                        <?php else: ?>
                        <?php foreach ($topProducts as $i => $p): ?>
                        <tr>
                            <td class="text-muted"><?= $i+1 ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($p['name']) ?></div>
                                <div class="text-muted"><?= htmlspecialchars($p['size'] . ($p['color'] ? ' / '.$p['color'] : '')) ?></div>
                            </td>
                            <td><?= number_format($p['qty_sold']) ?> pcs</td>
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
</div>

<?php require_once '../includes/footer.php'; ?>
