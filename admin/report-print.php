<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../config/db.php';
require_once '../includes/currency.php';

$settings = getSettings($pdo);
$rate     = (float)($settings['exchange_rate'] ?? 90);
$secCur   = $settings['secondary_currency'] ?? 'USD';

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

$fromLabel = date('d M Y', strtotime($from));
$toLabel   = date('d M Y', strtotime($to));

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
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt,
           SUM(total_amount) AS total, SUM(paid_amount) AS collected
    FROM sales
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY day DESC
");
$dailySales->execute([$from, $to]);
$dailySales = $dailySales->fetchAll();

// ── Top customers ──
$topCustomers = $pdo->prepare("
    SELECT c.name, c.shop_name, COUNT(s.id) AS invoices, SUM(s.total_amount) AS total
    FROM sales s JOIN customers c ON c.id = s.customer_id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY c.id ORDER BY total DESC LIMIT 15
");
$topCustomers->execute([$from, $to]);
$topCustomers = $topCustomers->fetchAll();

// ── Top products ──
$topProducts = $pdo->prepare("
    SELECT p.name, p.size, p.color, SUM(si.quantity) AS qty_sold, SUM(si.subtotal) AS revenue
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    JOIN sales s    ON s.id = si.sale_id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY p.id ORDER BY qty_sold DESC LIMIT 15
");
$topProducts->execute([$from, $to]);
$topProducts = $topProducts->fetchAll();

// ── Customer debts ──
$debtors = $pdo->query("
    SELECT name, shop_name, phone, total_debt
    FROM customers WHERE total_debt > 0
    ORDER BY total_debt DESC
")->fetchAll();
$totalDebt = array_sum(array_column($debtors, 'total_debt'));

// ── Payments in period ──
$paymentsData = $pdo->prepare("
    SELECT COUNT(*) AS count, COALESCE(SUM(GREATEST(amount_afn, amount)), 0) AS total
    FROM payments WHERE DATE(created_at) BETWEEN ? AND ?
");
$paymentsData->execute([$from, $to]);
$paymentsData = $paymentsData->fetch();

$shopName = $settings['shop_name_en'] ?? 'FZL System';
$generatedAt = date('d M Y, H:i');
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Report — <?= $fromLabel ?> to <?= $toLabel ?></title>
<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
    font-size: 12px;
    color: #1a1a1a;
    background: #e8eaed;
    line-height: 1.4;
}

/* ── Print bar (screen only) ── */
.print-bar {
    position: sticky; top: 0; z-index: 100;
    background: #1a1a2e; color: #fff;
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 24px; gap: 12px;
}
.print-bar h2 { font-size: 14px; font-weight: 600; }
.print-bar .actions { display: flex; gap: 10px; align-items: center; }
.btn-print {
    background: #0067C0; color: #fff; border: none;
    padding: 7px 20px; border-radius: 6px; font-size: 13px;
    font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px;
    transition: background .15s;
}
.btn-print:hover { background: #004A8F; }
.btn-back {
    color: rgba(255,255,255,0.7); text-decoration: none; font-size: 12px;
}
.btn-back:hover { color: #fff; }

/* ── Page wrapper ── */
.page {
    width: 210mm;
    min-height: 297mm;
    margin: 16px auto;
    background: #fff;
    box-shadow: 0 4px 30px rgba(0,0,0,0.18);
    padding: 14mm 12mm 10mm;
    display: flex;
    flex-direction: column;
    gap: 0;
}

/* ── Report header ── */
.rep-header {
    background: linear-gradient(135deg, #0a3d6e 0%, #0067C0 100%);
    border-radius: 10px;
    padding: 18px 22px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}
.rep-header-left .brand { font-size: 22px; font-weight: 900; letter-spacing: 2px; }
.rep-header-left .sub   { font-size: 11px; opacity: 0.75; margin-top: 2px; }
.rep-header-right       { text-align: right; }
.rep-header-right .period-title { font-size: 14px; font-weight: 700; }
.rep-header-right .period-dates {
    font-size: 20px; font-weight: 900;
    background: rgba(255,255,255,0.15);
    border-radius: 6px; padding: 4px 12px; margin-top: 4px; display: inline-block;
}
.rep-header-right .generated {
    font-size: 10px; opacity: 0.65; margin-top: 4px;
}

/* ── Section ── */
.section { margin-bottom: 18px; }
.section-title {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.8px;
    color: #0067C0; border-bottom: 2px solid #0067C0;
    padding-bottom: 4px; margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
}
.section-title svg { width: 13px; height: 13px; }

/* ── Metric cards ── */
.metric-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 18px;
}
.metric-card {
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 12px 14px;
    text-align: center;
}
.metric-card .label  { font-size: 9.5px; color: #666; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 4px; }
.metric-card .value  { font-size: 15px; font-weight: 800; line-height: 1.1; }
.metric-card .sub    { font-size: 9px; color: #888; margin-top: 2px; }
.metric-card .badge-count { font-size: 9px; color: #999; }
.c-blue   { border-top: 3px solid #0067C0; }
.c-green  { border-top: 3px solid #107C10; }
.c-amber  { border-top: 3px solid #9D5D00; }
.c-red    { border-top: 3px solid #C42B1C; }
.v-blue   { color: #0067C0; }
.v-green  { color: #107C10; }
.v-amber  { color: #9D5D00; }
.v-red    { color: #C42B1C; }

/* ── Tables ── */
.rep-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
}
.rep-table thead th {
    background: #0a3d6e;
    color: #fff;
    padding: 7px 10px;
    text-align: left;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.rep-table tbody td {
    padding: 6px 10px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}
.rep-table tbody tr:nth-child(even) td { background: #f8f9fb; }
.rep-table tbody tr:last-child td      { border-bottom: none; }
.rep-table tfoot td {
    padding: 7px 10px;
    background: #e8edf5;
    font-weight: 700;
    border-top: 2px solid #0a3d6e;
    font-size: 11px;
}
.text-right  { text-align: right !important; }
.text-center { text-align: center !important; }
.fw-bold     { font-weight: 700; }
.text-green  { color: #107C10; }
.text-red    { color: #C42B1C; }
.text-muted  { color: #777; }
.rank-gold   { color: #B8860B; font-weight: 800; }
.rank-silver { color: #666; font-weight: 700; }
.rank-bronze { color: #8B4513; font-weight: 700; }

/* ── Two-column grid for bottom tables ── */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

/* ── Page footer ── */
.rep-footer {
    margin-top: auto;
    padding-top: 12px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    font-size: 9px;
    color: #999;
}

/* ── Print media ── */
@media print {
    @page { size: A4 portrait; margin: 8mm; }
    body { background: white; }
    .print-bar { display: none !important; }
    .page { width: auto; margin: 0; padding: 8mm; box-shadow: none; }
    * { print-color-adjust: exact !important; -webkit-print-color-adjust: exact !important; }
    .section { page-break-inside: avoid; }
}
@media screen {
    .page { margin-bottom: 40px; }
}
</style>
</head>
<body>

<!-- Print controls -->
<div class="print-bar">
    <h2>📄 Business Report &nbsp;·&nbsp; <?= $fromLabel ?> – <?= $toLabel ?></h2>
    <div class="actions">
        <button class="btn-print" onclick="window.print()">
            🖨️ &nbsp;Print / Save as PDF
        </button>
        <a href="reports.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn-back">← Back to Reports</a>
    </div>
</div>

<div class="page">

    <!-- ══ Report header ══ -->
    <div class="rep-header">
        <div class="rep-header-left">
            <div class="brand">FZL</div>
            <div class="sub">Business Management System</div>
            <div style="font-size:10px;opacity:0.7;margin-top:6px;"><?= htmlspecialchars($shopName) ?></div>
        </div>
        <div class="rep-header-right">
            <div class="period-title">Business Report</div>
            <div class="period-dates"><?= $fromLabel ?> — <?= $toLabel ?></div>
            <div class="generated">Generated: <?= $generatedAt ?> (Kabul Time)</div>
        </div>
    </div>

    <!-- ══ Summary metrics ══ -->
    <div class="metric-grid">
        <div class="metric-card c-blue">
            <div class="label">Total Sales</div>
            <div class="value v-blue"><?= formatAFN($salesData['total']) ?></div>
            <div class="sub">≈ <?= formatMoney(fromAFN($salesData['total'], $rate), $secCur) ?></div>
            <div class="badge-count"><?= $salesData['count'] ?> invoices</div>
        </div>
        <div class="metric-card c-green">
            <div class="label">Collected</div>
            <div class="value v-green"><?= formatAFN($salesData['collected']) ?></div>
            <div class="sub">≈ <?= formatMoney(fromAFN($salesData['collected'], $rate), $secCur) ?></div>
            <div class="badge-count"><?= $paymentsData['count'] ?> payments</div>
        </div>
        <div class="metric-card c-amber">
            <div class="label">Pending (period)</div>
            <div class="value v-amber"><?= formatAFN($salesData['pending']) ?></div>
            <div class="sub">≈ <?= formatMoney(fromAFN($salesData['pending'], $rate), $secCur) ?></div>
            <div class="badge-count">&nbsp;</div>
        </div>
        <div class="metric-card c-red">
            <div class="label">Total Receivable</div>
            <div class="value v-red"><?= formatAFN($totalDebt) ?></div>
            <div class="sub">≈ <?= formatMoney(fromAFN($totalDebt, $rate), $secCur) ?></div>
            <div class="badge-count"><?= count($debtors) ?> customers</div>
        </div>
    </div>

    <!-- ══ Daily Breakdown ══ -->
    <?php if (!empty($dailySales)): ?>
    <div class="section">
        <div class="section-title">📅 &nbsp;Daily Sales Breakdown</div>
        <table class="rep-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="text-center">Invoices</th>
                    <th class="text-right">Total Sales (؋)</th>
                    <th class="text-right">Collected (؋)</th>
                    <th class="text-right">Uncollected (؋)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dailySales as $d): ?>
                <tr>
                    <td class="fw-bold"><?= date('D, d M Y', strtotime($d['day'])) ?></td>
                    <td class="text-center"><?= $d['cnt'] ?></td>
                    <td class="text-right fw-bold"><?= formatAFN($d['total']) ?></td>
                    <td class="text-right text-green"><?= formatAFN($d['collected']) ?></td>
                    <td class="text-right text-red"><?= formatAFN($d['total'] - $d['collected']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>TOTAL</td>
                    <td class="text-center"><?= $salesData['count'] ?></td>
                    <td class="text-right"><?= formatAFN($salesData['total']) ?></td>
                    <td class="text-right text-green"><?= formatAFN($salesData['collected']) ?></td>
                    <td class="text-right text-red"><?= formatAFN($salesData['pending']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>

    <!-- ══ Top Customers + Top Products side by side ══ -->
    <div class="two-col">

        <!-- Top Customers -->
        <div class="section">
            <div class="section-title">🏆 &nbsp;Top Customers</div>
            <table class="rep-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Customer</th>
                        <th class="text-center">Inv.</th>
                        <th class="text-right">Revenue (؋)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($topCustomers)): ?>
                    <tr><td colspan="4" class="text-muted text-center" style="padding:16px;">No data</td></tr>
                    <?php else: ?>
                    <?php foreach ($topCustomers as $i => $c): ?>
                    <tr>
                        <td class="<?= ['rank-gold','rank-silver','rank-bronze'][$i] ?? 'text-muted' ?>">
                            <?= $i + 1 ?>
                        </td>
                        <td>
                            <div class="fw-bold"><?= htmlspecialchars($c['name']) ?></div>
                            <div class="text-muted" style="font-size:9.5px;"><?= htmlspecialchars($c['shop_name']) ?></div>
                        </td>
                        <td class="text-center"><?= $c['invoices'] ?></td>
                        <td class="text-right fw-bold"><?= formatAFN($c['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Top Products -->
        <div class="section">
            <div class="section-title">📦 &nbsp;Top Products by Qty Sold</div>
            <table class="rep-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th class="text-center">Qty</th>
                        <th class="text-right">Revenue (؋)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($topProducts)): ?>
                    <tr><td colspan="4" class="text-muted text-center" style="padding:16px;">No data</td></tr>
                    <?php else: ?>
                    <?php foreach ($topProducts as $i => $p): ?>
                    <tr>
                        <td class="<?= ['rank-gold','rank-silver','rank-bronze'][$i] ?? 'text-muted' ?>">
                            <?= $i + 1 ?>
                        </td>
                        <td>
                            <div class="fw-bold"><?= htmlspecialchars($p['name']) ?></div>
                            <div class="text-muted" style="font-size:9.5px;">
                                <?= htmlspecialchars($p['size'] . ($p['color'] ? ' / ' . $p['color'] : '')) ?>
                            </div>
                        </td>
                        <td class="text-center fw-bold"><?= number_format($p['qty_sold']) ?></td>
                        <td class="text-right fw-bold"><?= formatAFN($p['revenue']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div><!-- /.two-col -->

    <!-- ══ Customer Debts ══ -->
    <?php if (!empty($debtors)): ?>
    <div class="section">
        <div class="section-title" style="color:#C42B1C;border-color:#C42B1C;">⚠️ &nbsp;Outstanding Customer Debts (All-Time)</div>
        <table class="rep-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Shop</th>
                    <th>Phone</th>
                    <th class="text-right">Debt (؋)</th>
                    <th class="text-right">Debt (<?= htmlspecialchars($secCur) ?>)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($debtors as $i => $d): ?>
                <tr>
                    <td class="text-muted"><?= $i + 1 ?></td>
                    <td class="fw-bold"><?= htmlspecialchars($d['name']) ?></td>
                    <td class="text-muted"><?= htmlspecialchars($d['shop_name']) ?></td>
                    <td class="text-muted"><?= htmlspecialchars($d['phone'] ?: '—') ?></td>
                    <td class="text-right fw-bold text-red"><?= formatAFN($d['total_debt']) ?></td>
                    <td class="text-right text-muted"><?= formatMoney(fromAFN($d['total_debt'], $rate), $secCur) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">TOTAL RECEIVABLE (<?= count($debtors) ?> customers)</td>
                    <td class="text-right text-red"><?= formatAFN($totalDebt) ?></td>
                    <td class="text-right"><?= formatMoney(fromAFN($totalDebt, $rate), $secCur) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>

    <!-- ══ Report footer ══ -->
    <div class="rep-footer">
        <span>FZL Business Management System</span>
        <span>Period: <?= $fromLabel ?> — <?= $toLabel ?></span>
        <span>Generated: <?= $generatedAt ?> (Kabul Time)</span>
    </div>

</div><!-- /.page -->

</body>
</html>
