<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = __('nav_summary');

// Ensure columns this page relies on exist.
try { $pdo->exec("ALTER TABLE payments ADD COLUMN is_loan TINYINT(1) NOT NULL DEFAULT 0 AFTER notes"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE payments ADD COLUMN source VARCHAR(20) NULL"); } catch (\PDOException $e) {}
// Tag payment origin: 'sale' = auto-recorded when the invoice was created,
// 'manual' = entered on the Add Payment page. Backfill existing rows once.
try {
    $pdo->exec("
        UPDATE payments p JOIN sales s ON s.id = p.sale_id
        SET p.source = 'sale'
        WHERE p.source IS NULL AND ABS(TIMESTAMPDIFF(SECOND, p.created_at, s.created_at)) <= 5
    ");
    $pdo->exec("UPDATE payments SET source = 'manual' WHERE source IS NULL");
} catch (\PDOException $e) {}
ensureSaleRates($pdo); // freeze invoices to their sale-time rate

$rates = getAllRates($pdo);
$CURS  = ['AFN', 'USD', 'PKR'];

// ── Period filter: Today / Week / Month / Year / Custom ──────────────────────
$period = in_array($_GET['period'] ?? '', ['today','week','month','year','custom'], true)
    ? $_GET['period'] : 'month';
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-01');
$dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : date('Y-m-d');

/** SQL boolean restricting a date column/expression to the selected period. */
function periodCondFor(string $expr, string $period, string $from, string $to): string {
    switch ($period) {
        case 'today':  return "$expr = CURDATE()";
        case 'week':   return "YEARWEEK($expr,1) = YEARWEEK(CURDATE(),1)";
        case 'month':  return "DATE_FORMAT($expr,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')";
        case 'year':   return "YEAR($expr) = YEAR(CURDATE())";
        case 'custom': return "$expr BETWEEN '$from' AND '$to'";
        default:       return "1=1";
    }
}

$invExpr  = "COALESCE(s.sale_date, DATE(s.created_at))";
$payExpr  = "COALESCE(p.payment_date, DATE(p.created_at))";
$invCond  = periodCondFor($invExpr, $period, $dateFrom, $dateTo);
$payCond  = periodCondFor($payExpr, $period, $dateFrom, $dateTo);

// ── Column 1: all invoices in range ──
$invoices = $pdo->query("
    SELECT s.id, s.bill_no, COALESCE(s.currency,'AFN') AS currency, s.exchange_rate,
           s.total_amount, s.paid_amount, s.balance, $invExpr AS d,
           c.name AS customer_name, c.shop_name
    FROM sales s JOIN customers c ON c.id = s.customer_id
    WHERE $invCond
    ORDER BY d DESC, s.id DESC
")->fetchAll();

// ── Column 2: invoices in range that still have an unpaid balance ──
$unpaid = array_values(array_filter($invoices, fn($s) => (float)$s['balance'] > 0.01));

// ── Column 3: money entered via the Add Payment page (excludes paid-at-sale) ──
$payments = $pdo->query("
    SELECT p.id, p.customer_id, p.amount, COALESCE(p.currency,'AFN') AS currency, p.amount_afn,
           COALESCE(p.is_loan,0) AS is_loan, $payExpr AS d, p.sale_id,
           c.name AS customer_name, c.shop_name
    FROM payments p JOIN customers c ON c.id = p.customer_id
    WHERE $payCond AND COALESCE(p.source,'manual') = 'manual'
    ORDER BY d DESC, p.id DESC
")->fetchAll();

/** Original-currency value of an AFN-stored amount, using the frozen rate. */
function toOrig(float $afn, string $cur, $rate, array $rates): float {
    if ($cur === 'AFN') return $afn;
    $r = !empty($rate) ? (float)$rate : ($rates[$cur] ?? 1.0);
    return $r > 0 ? $afn / $r : 0.0;
}

// Totals per currency for each column.
$totInv     = ['AFN'=>0.0,'USD'=>0.0,'PKR'=>0.0];
$totInvPaid = ['AFN'=>0.0,'USD'=>0.0,'PKR'=>0.0];
$totUnp     = ['AFN'=>0.0,'USD'=>0.0,'PKR'=>0.0];
$totPaid = ['AFN'=>0.0,'USD'=>0.0,'PKR'=>0.0];
$totLoan = ['AFN'=>0.0,'USD'=>0.0,'PKR'=>0.0];

foreach ($invoices as $s) {
    $cur = $s['currency'];
    $totInv[$cur]     = ($totInv[$cur] ?? 0)     + toOrig((float)$s['total_amount'], $cur, $s['exchange_rate'], $rates);
    $totInvPaid[$cur] = ($totInvPaid[$cur] ?? 0) + toOrig((float)$s['paid_amount'],  $cur, $s['exchange_rate'], $rates);
}
foreach ($unpaid as $s) {
    $cur = $s['currency'];
    $totUnp[$cur] = ($totUnp[$cur] ?? 0) + toOrig((float)$s['balance'], $cur, $s['exchange_rate'], $rates);
}
foreach ($payments as $p) {
    $cur = $p['currency'];
    $totPaid[$cur] = ($totPaid[$cur] ?? 0) + (float)$p['amount'];
    if ((int)$p['is_loan'] === 1) $totLoan[$cur] = ($totLoan[$cur] ?? 0) + (float)$p['amount'];
}

$periodLabels = [
    'today'  => ['bi-sun',            __('period_today')],
    'week'   => ['bi-calendar-week',  __('period_week')],
    'month'  => ['bi-calendar-month', __('period_month')],
    'year'   => ['bi-calendar4',      'This Year'],
    'custom' => ['bi-calendar-range', 'Custom'],
];
$rangeQS = $period === 'custom' ? '&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) : '';

// Small helpers for the view.
$fmt      = fn(float $a, string $c) => formatMoney($a, $c);
$shortDate = fn($d) => $d ? date('d M Y', strtotime($d)) : '—';

require_once '../includes/header.php';
?>

<div class="page-header">
    <h4 class="mb-1"><i class="bi bi-clipboard-data me-2 text-primary"></i><?= __('nav_summary') ?></h4>
    <p class="text-muted small mb-0">Invoices, outstanding balances and money received for the selected range.</p>
</div>

<!-- Period filter -->
<div class="d-flex align-items-center gap-1 flex-wrap mb-2">
    <?php foreach ($periodLabels as $pk => [$icon, $lbl]):
        $keep = $pk === 'custom' ? $rangeQS : ''; ?>
    <a href="?period=<?= $pk ?><?= $keep ?>" class="btn btn-sm <?= $period === $pk ? 'btn-primary' : 'btn-light border' ?>">
        <i class="bi <?= $icon ?> me-1"></i><?= htmlspecialchars($lbl) ?>
    </a>
    <?php endforeach; ?>
</div>
<?php if ($period === 'custom'): ?>
<form method="GET" class="d-flex align-items-center gap-2 flex-wrap mb-3">
    <input type="hidden" name="period" value="custom">
    <label class="small fw-semibold text-muted mb-0">From</label>
    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="form-control form-control-sm" style="width:150px;" required>
    <label class="small fw-semibold text-muted mb-0">To</label>
    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="form-control form-control-sm" style="width:150px;" required>
    <button class="btn btn-sm btn-primary"><i class="bi bi-arrow-right me-1"></i>Apply</button>
    <span class="text-muted small ms-1"><?= $shortDate($dateFrom) ?> — <?= $shortDate($dateTo) ?></span>
</form>
<?php else: ?><div class="mb-3"></div><?php endif; ?>

<div class="row g-3">

    <!-- ── Column 1: Invoices ── -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header py-3 d-flex align-items-center justify-content-between">
                <span class="fw-semibold"><i class="bi bi-receipt me-2 text-primary"></i>Invoices</span>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= count($invoices) ?></span>
            </div>
            <div class="list-group list-group-flush" style="max-height:480px;overflow-y:auto;">
                <?php if (empty($invoices)): ?>
                <div class="text-center text-muted py-5 small"><i class="bi bi-inbox d-block fs-3 mb-2 opacity-25"></i>No invoices in this range.</div>
                <?php else: foreach ($invoices as $s):
                    $cur      = $s['currency'];
                    $orig     = toOrig((float)$s['total_amount'], $cur, $s['exchange_rate'], $rates);
                    $paidOrig = toOrig((float)$s['paid_amount'],  $cur, $s['exchange_rate'], $rates); ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate"><?= htmlspecialchars($s['customer_name']) ?></div>
                            <div class="text-muted" style="font-size:0.72rem;">
                                #<?= $s['bill_no'] ?: str_pad($s['id'],4,'0',STR_PAD_LEFT) ?> · <?= $shortDate($s['d']) ?>
                            </div>
                            <div style="font-size:0.72rem;">
                                <span class="text-success">Paid: <?= $fmt($paidOrig, $cur) ?></span>
                            </div>
                        </div>
                        <div class="text-end text-nowrap">
                            <div class="fw-bold"><?= $fmt($orig, $cur) ?></div>
                            <?php if ($cur !== 'AFN'): ?><div class="text-muted" style="font-size:0.68rem;">≈ <?= formatAFN((float)$s['total_amount']) ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <div class="card-footer bg-white">
                <?= renderCurTotals($totInv, $CURS, 'Total invoiced') ?>
                <div class="mt-2 pt-2 border-top">
                    <?= renderCurTotals($totInvPaid, $CURS, 'Total paid') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Column 2: Unpaid balances ── -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header py-3 d-flex align-items-center justify-content-between">
                <span class="fw-semibold"><i class="bi bi-exclamation-circle me-2 text-danger"></i>Remaining (unpaid)</span>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= count($unpaid) ?></span>
            </div>
            <div class="list-group list-group-flush" style="max-height:480px;overflow-y:auto;">
                <?php if (empty($unpaid)): ?>
                <div class="text-center text-muted py-5 small"><i class="bi bi-check2-circle d-block fs-3 mb-2 opacity-25"></i>No unpaid invoices in this range.</div>
                <?php else: foreach ($unpaid as $s):
                    $cur  = $s['currency'];
                    $orig = toOrig((float)$s['balance'], $cur, $s['exchange_rate'], $rates); ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate"><?= htmlspecialchars($s['customer_name']) ?></div>
                            <div class="text-muted" style="font-size:0.72rem;">
                                #<?= $s['bill_no'] ?: str_pad($s['id'],4,'0',STR_PAD_LEFT) ?> · <?= $shortDate($s['d']) ?>
                            </div>
                        </div>
                        <div class="text-end text-nowrap">
                            <div class="fw-bold text-danger"><?= $fmt($orig, $cur) ?></div>
                            <?php if ($cur !== 'AFN'): ?><div class="text-muted" style="font-size:0.68rem;">≈ <?= formatAFN((float)$s['balance']) ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <div class="card-footer bg-white">
                <?= renderCurTotals($totUnp, $CURS, 'Total unpaid') ?>
            </div>
        </div>
    </div>

    <!-- ── Column 3: Money received ── -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header py-3 d-flex align-items-center justify-content-between">
                <span class="fw-semibold"><i class="bi bi-cash-coin me-2 text-success"></i>Money received</span>
                <span class="badge bg-success-subtle text-success border border-success-subtle"><?= count($payments) ?></span>
            </div>
            <div class="list-group list-group-flush" style="max-height:480px;overflow-y:auto;">
                <?php if (empty($payments)): ?>
                <div class="text-center text-muted py-5 small"><i class="bi bi-wallet2 d-block fs-3 mb-2 opacity-25"></i>No payments in this range.</div>
                <?php else: foreach ($payments as $p):
                    $cur = $p['currency']; ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate">
                                <?= htmlspecialchars($p['customer_name']) ?>
                                <?php if ((int)$p['is_loan'] === 1): ?>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle" style="font-size:0.6rem;">قرض loan</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted" style="font-size:0.72rem;">
                                <?= $p['sale_id'] ? '#'.$p['sale_id'].' · ' : '' ?><?= $shortDate($p['d']) ?>
                            </div>
                        </div>
                        <div class="text-end text-nowrap">
                            <div class="fw-bold text-success"><?= $fmt((float)$p['amount'], $cur) ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <div class="card-footer bg-white">
                <?= renderCurTotals($totPaid, $CURS, 'Total received') ?>
                <?php if (array_sum($totLoan) > 0.01): ?>
                <div class="mt-2 pt-2 border-top small">
                    <div class="text-muted mb-1 fw-semibold">of which loan (قرض)</div>
                    <?php foreach ($CURS as $c): if ($totLoan[$c] <= 0.01) continue; ?>
                    <div class="d-flex justify-content-between"><span class="text-warning"><?= $c ?></span><span class="fw-semibold"><?= $fmt($totLoan[$c], $c) ?></span></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Range grand summary ── -->
<div class="card mt-3">
    <div class="card-header py-3 fw-semibold"><i class="bi bi-calculator me-2"></i>Range totals</div>
    <div class="card-body">
        <div class="row g-3 text-center">
            <?php
            $summaryCols = [
                ['Invoiced',       $totInv,  'text-primary'],
                ['Unpaid',         $totUnp,  'text-danger'],
                ['Received',       $totPaid, 'text-success'],
            ];
            foreach ($summaryCols as [$lbl, $tot, $clr]): ?>
            <div class="col-md-4">
                <div class="p-3 rounded" style="background:rgba(0,0,0,0.02);border:1px solid rgba(0,0,0,0.06);">
                    <div class="text-muted small fw-semibold text-uppercase mb-2"><?= $lbl ?></div>
                    <?php foreach ($CURS as $c): ?>
                    <div class="d-flex justify-content-between px-2 py-1">
                        <span class="text-muted small"><?= $c ?></span>
                        <span class="fw-bold <?= $clr ?>"><?= $fmt($tot[$c] ?? 0, $c) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
/** Footer block: one line per currency (AFN/USD/PKR). */
function renderCurTotals(array $tot, array $curs, string $label): string {
    $h = '<div class="small text-muted fw-semibold mb-1">' . htmlspecialchars($label) . '</div>';
    foreach ($curs as $c) {
        $h .= '<div class="d-flex justify-content-between align-items-center">'
            . '<span class="text-muted small">' . $c . '</span>'
            . '<span class="fw-bold">' . formatMoney($tot[$c] ?? 0, $c) . '</span></div>';
    }
    return $h;
}

require_once '../includes/footer.php';
