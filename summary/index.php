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

// ── Missions / Goals ─────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS missions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(10) NOT NULL DEFAULT 'sale',
    title VARCHAR(150) NULL,
    target_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'create_mission' && validateFormToken('mission_create')) {
        $mType   = in_array($_POST['m_type'] ?? '', ['sale','loan'], true) ? $_POST['m_type'] : 'sale';
        $mTarget = max(0, (float)($_POST['m_target'] ?? 0));
        $mTitle  = trim($_POST['m_title'] ?? '');
        $mPeriod = $_POST['m_period'] ?? 'week';
        if ($mPeriod === 'month') {
            $ms = date('Y-m-01'); $me = date('Y-m-t');
        } elseif ($mPeriod === 'custom') {
            $ms = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['m_start'] ?? '') ? $_POST['m_start'] : date('Y-m-d');
            $me = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['m_end'] ?? '') ? $_POST['m_end'] : date('Y-m-d');
            if ($me < $ms) { [$ms, $me] = [$me, $ms]; }
        } else { // week (Mon–Sun, matches the app's "This Week" filter)
            $ms = date('Y-m-d', strtotime('monday this week'));
            $me = date('Y-m-d', strtotime('sunday this week'));
        }
        if ($mTarget > 0) {
            $pdo->prepare("INSERT INTO missions (type,title,target_amount,start_date,end_date,created_by) VALUES (?,?,?,?,?,?)")
                ->execute([$mType, $mTitle ?: null, $mTarget, $ms, $me, $_SESSION['user_id'] ?? null]);
        }
        header('Location: /summary/index.php'); exit;
    }
    if ($act === 'delete_mission') {
        $mid = (int)($_POST['mission_id'] ?? 0);
        if ($mid > 0) $pdo->prepare("DELETE FROM missions WHERE id = ?")->execute([$mid]);
        header('Location: /summary/index.php'); exit;
    }
}

// Load missions and compute live progress (all in AFN).
$missions = $pdo->query("SELECT * FROM missions ORDER BY (end_date >= CURDATE()) DESC, end_date DESC, id DESC")->fetchAll();
foreach ($missions as &$mn) {
    if ($mn['type'] === 'loan') {
        // Money received via the Add Payment page (not invoice paid-at-sale).
        $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN amount_afn>0 THEN amount_afn ELSE amount END),0)
                             FROM payments
                             WHERE COALESCE(source,'manual')='manual'
                               AND COALESCE(payment_date, DATE(created_at)) BETWEEN ? AND ?");
    } else {
        // Sales invoiced in the window.
        $st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0)
                             FROM sales
                             WHERE COALESCE(sale_date, DATE(created_at)) BETWEEN ? AND ?");
    }
    $st->execute([$mn['start_date'], $mn['end_date']]);
    $mn['achieved'] = (float)$st->fetchColumn();
    $tgt            = (float)$mn['target_amount'];
    $mn['pct']      = $tgt > 0 ? min(100, round($mn['achieved'] / $tgt * 100)) : 0;
    $mn['pct_raw']  = $tgt > 0 ? round($mn['achieved'] / $tgt * 100) : 0;
    $today          = date('Y-m-d');
    $totalDays      = max(1, (int)((strtotime($mn['end_date']) - strtotime($mn['start_date'])) / 86400) + 1);
    $elapsedDays    = min($totalDays, max(0, (int)((strtotime($today) - strtotime($mn['start_date'])) / 86400) + 1));
    $mn['days_left'] = max(0, (int)((strtotime($mn['end_date']) - strtotime($today)) / 86400));
    $mn['expired']   = $today > $mn['end_date'];
    $mn['achieved_goal'] = $mn['achieved'] >= $tgt && $tgt > 0;
    // Status: achieved / expired / on-track / behind (vs time elapsed pace)
    $expectedPct = $totalDays > 0 ? ($elapsedDays / $totalDays) * 100 : 0;
    if ($mn['achieved_goal'])      $mn['status'] = ['Achieved',  '#10B981', 'bi-trophy-fill'];
    elseif ($mn['expired'])        $mn['status'] = ['Missed',    '#EF4444', 'bi-x-circle'];
    elseif ($mn['pct_raw'] >= $expectedPct) $mn['status'] = ['On track', '#0EA5E9', 'bi-graph-up-arrow'];
    else                           $mn['status'] = ['Behind',    '#F59E0B', 'bi-hourglass-split'];
}
unset($mn);
$missionToken = generateFormToken('mission_create');

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h4 class="mb-1"><i class="bi bi-clipboard-data me-2 text-primary"></i><?= __('nav_summary') ?></h4>
        <p class="text-muted small mb-0">Invoices, outstanding balances and money received for the selected range.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#missionModal">
        <i class="bi bi-bullseye me-2"></i>New Goal
    </button>
</div>

<!-- ── Missions / Goals ─────────────────────────────────────────────────────── -->
<?php if (!empty($missions)): ?>
<div class="mission-grid mb-4">
    <?php foreach ($missions as $mn):
        [$stLabel, $stColor, $stIcon] = $mn['status'];
        $isLoan  = $mn['type'] === 'loan';
        $accent  = $isLoan ? '#F59E0B' : '#0067C0';
        $tgt     = (float)$mn['target_amount'];
        $remain  = max(0, $tgt - (float)$mn['achieved']);
        $title   = $mn['title'] ?: ($isLoan ? 'Loan collection goal' : 'Sales goal');
    ?>
    <div class="mission-card" style="--accent:<?= $accent ?>;--status:<?= $stColor ?>;">
        <div class="mc-top">
            <div class="mc-type" style="background:<?= $accent ?>1a;color:<?= $accent ?>;">
                <i class="bi <?= $isLoan ? 'bi-cash-stack' : 'bi-receipt' ?>"></i>
                <?= $isLoan ? 'Loan' : 'Sale' ?>
            </div>
            <div class="mc-status" style="color:<?= $stColor ?>;">
                <i class="bi <?= $stIcon ?>"></i> <?= $stLabel ?>
            </div>
            <form method="POST" class="mc-del" onsubmit="return confirm('Delete this goal?');">
                <input type="hidden" name="action" value="delete_mission">
                <input type="hidden" name="mission_id" value="<?= (int)$mn['id'] ?>">
                <button type="submit" title="Delete"><i class="bi bi-x-lg"></i></button>
            </form>
        </div>

        <div class="mc-title"><?= htmlspecialchars($title) ?></div>
        <div class="mc-range">
            <i class="bi bi-calendar-range me-1"></i><?= $shortDate($mn['start_date']) ?> — <?= $shortDate($mn['end_date']) ?>
            <?php if (!$mn['expired'] && !$mn['achieved_goal']): ?>
                · <span style="color:<?= $stColor ?>;"><?= (int)$mn['days_left'] ?> day<?= $mn['days_left']==1?'':'s' ?> left</span>
            <?php endif; ?>
        </div>

        <div class="mc-figures">
            <span class="mc-achieved" data-count="<?= (float)$mn['achieved'] ?>">؋ 0</span>
            <span class="mc-of">/ ؋ <?= number_format($tgt, 0) ?></span>
        </div>

        <div class="mc-bar">
            <div class="mc-fill" style="width:0;" data-pct="<?= (int)$mn['pct'] ?>"></div>
        </div>
        <div class="mc-meta">
            <span class="mc-pct" data-pct="<?= (int)$mn['pct_raw'] ?>">0%</span>
            <span class="mc-remain">
                <?php if ($mn['achieved_goal']): ?>
                    <i class="bi bi-check-circle-fill text-success"></i> Goal reached!
                <?php else: ?>
                    ؋ <?= number_format($remain, 0) ?> to go
                <?php endif; ?>
            </span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card mb-4 border-0" style="background:rgba(0,103,192,0.04);">
    <div class="card-body text-center py-4">
        <i class="bi bi-bullseye d-block mb-2 text-primary" style="font-size:1.8rem;opacity:.6;"></i>
        <div class="fw-semibold mb-1">No goals yet</div>
        <div class="text-muted small mb-3">Set a weekly sales target or a loan-collection target and track your progress here.</div>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#missionModal">
            <i class="bi bi-plus-lg me-1"></i>Create your first goal
        </button>
    </div>
</div>
<?php endif; ?>

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

    <!-- ── Column 1: Invoices (total only) ── -->
    <div class="col-lg-3 col-md-6">
        <div class="card h-100">
            <div class="card-header py-3 d-flex align-items-center justify-content-between">
                <span class="fw-semibold"><i class="bi bi-receipt me-2 text-primary"></i>Invoices</span>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= count($invoices) ?></span>
            </div>
            <div class="list-group list-group-flush" style="max-height:480px;overflow-y:auto;">
                <?php if (empty($invoices)): ?>
                <div class="text-center text-muted py-5 small"><i class="bi bi-inbox d-block fs-3 mb-2 opacity-25"></i>No invoices in this range.</div>
                <?php else: foreach ($invoices as $s):
                    $cur  = $s['currency'];
                    $orig = toOrig((float)$s['total_amount'], $cur, $s['exchange_rate'], $rates); ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate"><?= htmlspecialchars($s['customer_name']) ?></div>
                            <div class="text-muted" style="font-size:0.72rem;">
                                #<?= $s['bill_no'] ?: str_pad($s['id'],4,'0',STR_PAD_LEFT) ?> · <?= $shortDate($s['d']) ?>
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
            </div>
        </div>
    </div>

    <!-- ── Column 2: Paid (paid amount per invoice) ── -->
    <?php $paidInvoices = array_values(array_filter($invoices, fn($s) => (float)$s['paid_amount'] > 0.01)); ?>
    <div class="col-lg-3 col-md-6">
        <div class="card h-100">
            <div class="card-header py-3 d-flex align-items-center justify-content-between">
                <span class="fw-semibold"><i class="bi bi-check2-circle me-2 text-success"></i>Paid</span>
                <span class="badge bg-success-subtle text-success border border-success-subtle"><?= count($paidInvoices) ?></span>
            </div>
            <div class="list-group list-group-flush" style="max-height:480px;overflow-y:auto;">
                <?php if (empty($paidInvoices)): ?>
                <div class="text-center text-muted py-5 small"><i class="bi bi-wallet2 d-block fs-3 mb-2 opacity-25"></i>No paid invoices in this range.</div>
                <?php else: foreach ($paidInvoices as $s):
                    $cur      = $s['currency'];
                    $paidOrig = toOrig((float)$s['paid_amount'], $cur, $s['exchange_rate'], $rates); ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate"><?= htmlspecialchars($s['customer_name']) ?></div>
                            <div class="text-muted" style="font-size:0.72rem;">
                                #<?= $s['bill_no'] ?: str_pad($s['id'],4,'0',STR_PAD_LEFT) ?> · <?= $shortDate($s['d']) ?>
                            </div>
                        </div>
                        <div class="text-end text-nowrap">
                            <div class="fw-bold text-success"><?= $fmt($paidOrig, $cur) ?></div>
                            <?php if ($cur !== 'AFN'): ?><div class="text-muted" style="font-size:0.68rem;">≈ <?= formatAFN((float)$s['paid_amount']) ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <div class="card-footer bg-white">
                <?= renderCurTotals($totInvPaid, $CURS, 'Total paid') ?>
            </div>
        </div>
    </div>

    <!-- ── Column 3: Unpaid balances ── -->
    <div class="col-lg-3 col-md-6">
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

    <!-- ── Column 4: Money received ── -->
    <div class="col-lg-3 col-md-6">
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
                ['Invoiced',       $totInv,     'text-primary'],
                ['Paid',           $totInvPaid, 'text-success'],
                ['Unpaid',         $totUnp,     'text-danger'],
                ['Received',       $totPaid,    'text-success'],
            ];
            foreach ($summaryCols as [$lbl, $tot, $clr]): ?>
            <div class="col-md-3">
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

<!-- ── Create Goal modal ── -->
<div class="modal fade" id="missionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_mission">
                <input type="hidden" name="_form_token" value="<?= htmlspecialchars($missionToken) ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-bullseye me-2 text-primary"></i>New Goal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Type -->
                    <label class="form-label fw-semibold">Goal type</label>
                    <div class="row g-2 mb-3" id="goalTypeRow">
                        <div class="col-6">
                            <input type="radio" class="btn-check" name="m_type" id="gtSale" value="sale" checked>
                            <label class="btn btn-outline-primary w-100 py-3" for="gtSale">
                                <i class="bi bi-receipt d-block fs-4 mb-1"></i>Sales target
                                <div class="small text-muted">tracked from invoices</div>
                            </label>
                        </div>
                        <div class="col-6">
                            <input type="radio" class="btn-check" name="m_type" id="gtLoan" value="loan">
                            <label class="btn btn-outline-warning w-100 py-3" for="gtLoan">
                                <i class="bi bi-cash-stack d-block fs-4 mb-1"></i>Loan collection
                                <div class="small text-muted">money via payment page</div>
                            </label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Target amount (؋ AFN)</label>
                        <input type="number" name="m_target" class="form-control form-control-lg" min="1" step="any" placeholder="e.g. 100000" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title <span class="text-muted fw-normal small">(optional)</span></label>
                        <input type="text" name="m_title" class="form-control" placeholder="e.g. This week's sales push">
                    </div>

                    <label class="form-label fw-semibold">Time frame</label>
                    <div class="row g-2 mb-2">
                        <div class="col-4">
                            <input type="radio" class="btn-check" name="m_period" id="gpWeek" value="week" checked onchange="toggleGoalCustom()">
                            <label class="btn btn-outline-secondary w-100" for="gpWeek">This week</label>
                        </div>
                        <div class="col-4">
                            <input type="radio" class="btn-check" name="m_period" id="gpMonth" value="month" onchange="toggleGoalCustom()">
                            <label class="btn btn-outline-secondary w-100" for="gpMonth">This month</label>
                        </div>
                        <div class="col-4">
                            <input type="radio" class="btn-check" name="m_period" id="gpCustom" value="custom" onchange="toggleGoalCustom()">
                            <label class="btn btn-outline-secondary w-100" for="gpCustom">Custom</label>
                        </div>
                    </div>
                    <div id="goalCustomRange" class="row g-2" style="display:none;">
                        <div class="col-6">
                            <label class="form-label small text-muted mb-1">From</label>
                            <input type="date" name="m_start" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-muted mb-1">To</label>
                            <input type="date" name="m_end" class="form-control form-control-sm" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-flag me-1"></i>Set goal</button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.mission-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:14px; }
.mission-card {
    position:relative; background:#fff; border:1px solid rgba(0,0,0,0.07);
    border-radius:16px; padding:16px 18px 18px; overflow:hidden;
    box-shadow:0 2px 10px rgba(0,0,0,0.04); transition:box-shadow .2s, transform .2s;
}
.mission-card::before { content:''; position:absolute; inset:0 auto 0 0; width:5px; background:var(--accent); }
.mission-card:hover { box-shadow:0 8px 26px rgba(0,0,0,0.10); transform:translateY(-3px); }
.mc-top { display:flex; align-items:center; gap:8px; margin-bottom:10px; }
.mc-type { display:inline-flex; align-items:center; gap:5px; font-size:0.72rem; font-weight:700; padding:3px 10px; border-radius:20px; }
.mc-status { font-size:0.72rem; font-weight:700; display:inline-flex; align-items:center; gap:4px; }
.mc-del { margin-inline-start:auto; }
.mc-del button { background:none; border:none; color:#c0c0c0; padding:2px 4px; border-radius:6px; transition:.15s; font-size:.8rem; }
.mc-del button:hover { color:#EF4444; background:rgba(239,68,68,0.1); }
.mc-title { font-weight:700; font-size:1rem; line-height:1.2; }
.mc-range { font-size:0.72rem; color:#8a8a8a; margin-top:3px; }
.mc-figures { display:flex; align-items:baseline; gap:6px; margin-top:12px; }
.mc-achieved { font-size:1.5rem; font-weight:800; color:var(--accent); letter-spacing:-.5px; }
.mc-of { font-size:0.82rem; color:#9a9a9a; font-weight:600; }
.mc-bar { height:12px; border-radius:20px; background:rgba(0,0,0,0.06); overflow:hidden; margin-top:10px; }
.mc-fill {
    height:100%; border-radius:20px; background:linear-gradient(90deg,var(--accent),var(--status));
    transition:width 1.1s cubic-bezier(.22,1,.36,1); position:relative;
}
.mc-fill::after {
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg,transparent,rgba(255,255,255,.45),transparent);
    background-size:200% 100%; animation:mcShine 2s linear infinite;
}
@keyframes mcShine { 0%{background-position:200% 0;} 100%{background-position:-200% 0;} }
.mc-meta { display:flex; align-items:center; justify-content:space-between; margin-top:8px; }
.mc-pct { font-weight:800; font-size:1rem; color:var(--status); }
.mc-remain { font-size:0.74rem; color:#7a7a7a; font-weight:600; }
</style>

<script>
function toggleGoalCustom() {
    var c = document.getElementById('gpCustom');
    document.getElementById('goalCustomRange').style.display = c && c.checked ? 'flex' : 'none';
}
document.addEventListener('DOMContentLoaded', function () {
    // Animate progress bars + count-up figures.
    document.querySelectorAll('.mission-card').forEach(function (card) {
        var fill = card.querySelector('.mc-fill');
        var pctEl = card.querySelector('.mc-pct');
        var amtEl = card.querySelector('.mc-achieved');
        var pct = parseInt(fill.getAttribute('data-pct')) || 0;
        var pctRaw = parseInt(pctEl.getAttribute('data-pct')) || 0;
        var amt = parseFloat(amtEl.getAttribute('data-count')) || 0;
        setTimeout(function () { fill.style.width = pct + '%'; }, 120);
        var steps = 40, i = 0;
        var t = setInterval(function () {
            i++;
            var k = i / steps;
            pctEl.textContent = Math.round(pctRaw * k) + '%';
            amtEl.textContent = '؋ ' + Math.round(amt * k).toLocaleString('en-US');
            if (i >= steps) { clearInterval(t); pctEl.textContent = pctRaw + '%'; amtEl.textContent = '؋ ' + Math.round(amt).toLocaleString('en-US'); }
        }, 22);
    });
});
</script>

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
