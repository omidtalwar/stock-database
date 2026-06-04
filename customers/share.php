<?php
require_once '../config/db.php';
require_once '../includes/currency.php';

$token = trim($_GET['token'] ?? '');
if ($token === '') { http_response_code(404); die('Not found.'); }

$customer = $pdo->prepare("SELECT * FROM customers WHERE share_token = ? LIMIT 1");
$customer->execute([$token]);
$customer = $customer->fetch();
if (!$customer) { http_response_code(404); die('This link is no longer valid.'); }

$id      = (int)$customer['id'];
$rates   = getAllRates($pdo);
$rateUSD = $rates['USD'];
$ratePKR = $rates['PKR'];

$sales = $pdo->prepare("
    SELECT s.id, s.total_amount, s.paid_amount, s.balance, s.currency,
           s.created_at, s.sale_date, s.bill_no
    FROM sales s WHERE s.customer_id = ?
    ORDER BY COALESCE(s.sale_date, DATE(s.created_at)) DESC
");
$sales->execute([$id]);
$sales = $sales->fetchAll();

$payments = $pdo->prepare("
    SELECT p.amount, p.amount_afn, p.currency, p.notes, p.payment_date, p.created_at,
           s.id AS inv_id, s.bill_no AS inv_bill_no
    FROM payments p
    LEFT JOIN sales s ON s.id = p.sale_id
    WHERE p.customer_id = ?
    ORDER BY COALESCE(p.payment_date, DATE(p.created_at)) DESC, p.created_at DESC
");
$payments->execute([$id]);
$payments = $payments->fetchAll();

// Totals
$salesByCur = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];
$paysByCur  = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];
$debtByCur  = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];

foreach ($sales as $s) {
    $cur = $s['currency'] ?? 'AFN'; $afn = (float)$s['total_amount']; $rate = $rates[$cur] ?? 1.0;
    $orig = $cur === 'AFN' ? $afn : fromAFN($afn, $rate);
    if (isset($salesByCur[$cur])) { $salesByCur[$cur]['orig'] += $orig; $salesByCur[$cur]['afn'] += $afn; $salesByCur[$cur]['cnt']++; }
    $bal = max(0.0, $afn - (float)$s['paid_amount']);
    if ($bal > 0.01 && isset($debtByCur[$cur])) { $debtByCur[$cur]['orig'] += $cur==='AFN'?$bal:fromAFN($bal,$rate); $debtByCur[$cur]['afn'] += $bal; $debtByCur[$cur]['cnt']++; }
}
foreach ($payments as $p) {
    $cur = $p['currency'] ?? 'AFN'; $afn = (float)$p['amount_afn'] ?: (float)$p['amount'];
    if (isset($paysByCur[$cur])) { $paysByCur[$cur]['orig'] += (float)$p['amount']; $paysByCur[$cur]['afn'] += $afn; $paysByCur[$cur]['cnt']++; }
}

$cvCurMeta = [
    'AFN' => ['flag'=>'🇦🇫','col'=>'#107C10'],
    'USD' => ['flag'=>'🇺🇸','col'=>'#0067C0'],
    'PKR' => ['flag'=>'🇵🇰','col'=>'#7719AA'],
];
$anyDebt = array_sum(array_column($debtByCur,'cnt')) > 0;
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($customer['name']) ?> — Statement</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    font-size: 14px;
    background: #F3F3F3;
    background-image:
        radial-gradient(ellipse 80% 60% at 20% 10%, rgba(0,103,192,0.06) 0%, transparent 60%),
        radial-gradient(ellipse 60% 80% at 80% 90%, rgba(119,25,170,0.04) 0%, transparent 60%);
    min-height: 100vh;
    color: #1C1C1C;
}
.topbar {
    height: 48px;
    background: rgba(243,243,243,0.9);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid rgba(0,0,0,0.07);
    display: flex; align-items: center;
    padding: 0 24px; gap: 12px;
    position: sticky; top: 0; z-index: 100;
}
.brand-icon {
    width: 30px; height: 30px; border-radius: 7px;
    background: linear-gradient(135deg, #0067C0, #003E92);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 900; font-size: 0.75rem; letter-spacing: 1px;
}
.topbar-title { font-weight: 600; font-size: 0.88rem; color: #605E5C; }
.topbar-right { margin-left: auto; }
.view-only-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 20px;
    background: rgba(0,103,192,0.08); border: 1px solid rgba(0,103,192,0.2);
    color: #0067C0; font-size: 0.7rem; font-weight: 700;
}
.content { max-width: 900px; margin: 0 auto; padding: 24px 20px 60px; }
.page-hdr { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }
.page-hdr h4 { font-size: 1.25rem; font-weight: 700; margin: 0; }

/* Stat cards — same as view.php */
.cv-cur-row { display:flex; align-items:center; justify-content:space-between; padding:4px 0; border-bottom:1px solid rgba(0,0,0,0.06); }
.cv-cur-row:last-child { border-bottom:none; }
.cv-cur-lbl { font-size:0.72rem; font-weight:700; display:flex; align-items:center; gap:4px; }
.cv-cur-amt { font-weight:700; font-size:0.88rem; text-align:right; }
.cv-cur-eq  { font-size:0.65rem; color:#888; text-align:right; }
.cv-empty   { font-size:0.78rem; color:#888; padding:6px 0; }

/* Card overrides */
.card { border-radius: 10px; border: 1px solid rgba(0,0,0,0.07); box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
.card-header { font-weight: 600; font-size: 0.875rem; background: rgba(0,0,0,0.018); border-bottom: 1px solid rgba(0,0,0,0.07); padding: 12px 16px; }
.table thead th { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #605E5C; background: rgba(0,0,0,0.018); border-bottom: 1px solid rgba(0,0,0,0.07); padding: 10px 14px; }
.table tbody td { padding: 11px 14px; border-bottom: 1px solid rgba(0,0,0,0.04); vertical-align: middle; }
.table tbody tr:last-child td { border-bottom: none; }
.table-hover tbody tr:hover { background: rgba(0,103,192,0.03); }
</style>
</head>
<body>

<!-- Topbar (no links — view only) -->
<div class="topbar">
    <div class="brand-icon">FZL</div>
    <span class="topbar-title"><?= htmlspecialchars($customer['name']) ?></span>
    <div class="topbar-right">
        <span class="view-only-badge"><i class="bi bi-eye"></i> View Only</span>
    </div>
</div>

<div class="content">

    <!-- Page header — no action buttons -->
    <div class="page-hdr">
        <div>
            <h4><?= htmlspecialchars($customer['name']) ?></h4>
            <span class="text-muted small"><?= htmlspecialchars($customer['shop_name']) ?></span>
        </div>
        <div class="text-muted small" style="padding-top:6px;">
            Statement as of <?= date('d M Y') ?>
        </div>
    </div>

    <!-- Stat cards (same layout as view.php) -->
    <div class="row g-3 mb-4">

        <!-- Total Sales -->
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="text-muted small mb-2 fw-semibold">Total Sales</div>
                    <?php $anySales = false; foreach ($cvCurMeta as $cur => $m): $d = $salesByCur[$cur]; if (!$d['cnt']) continue; $anySales = true; ?>
                    <div class="cv-cur-row">
                        <span class="cv-cur-lbl"><?= $m['flag'] ?> <span style="color:<?= $m['col'] ?>;"><?= $cur ?></span></span>
                        <div>
                            <div class="cv-cur-amt" style="color:<?= $m['col'] ?>;"><?= formatMoney($d['orig'], $cur) ?></div>
                            <?php if ($cur !== 'AFN'): ?><div class="cv-cur-eq">≈ <?= formatAFN($d['afn']) ?></div><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; if (!$anySales): ?><div class="cv-empty">—</div><?php endif; ?>
                    <div class="text-muted mt-2" style="font-size:0.68rem;"><?= count($sales) ?> invoices total</div>
                </div>
            </div>
        </div>

        <!-- Total Paid -->
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="text-muted small mb-2 fw-semibold">Total Paid</div>
                    <?php $anyPaid = false; foreach ($cvCurMeta as $cur => $m): $d = $paysByCur[$cur]; if (!$d['cnt']) continue; $anyPaid = true; ?>
                    <div class="cv-cur-row">
                        <span class="cv-cur-lbl"><?= $m['flag'] ?> <span style="color:<?= $m['col'] ?>;"><?= $cur ?></span></span>
                        <div>
                            <div class="cv-cur-amt" style="color:#107C10;"><?= formatMoney($d['orig'], $cur) ?></div>
                            <?php if ($cur !== 'AFN'): ?><div class="cv-cur-eq">≈ <?= formatAFN($d['afn']) ?></div><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; if (!$anyPaid): ?><div class="cv-empty text-muted">No payments yet</div><?php endif; ?>
                    <div class="text-muted mt-2" style="font-size:0.68rem;"><?= count($payments) ?> payments total</div>
                </div>
            </div>
        </div>

        <!-- Current Debt -->
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="text-muted small mb-2 fw-semibold">Current Balance</div>
                    <?php $anyDebtCard = false; foreach ($cvCurMeta as $cur => $m): $d = $debtByCur[$cur]; if (!$d['cnt']) continue; $anyDebtCard = true; ?>
                    <div class="cv-cur-row">
                        <span class="cv-cur-lbl"><?= $m['flag'] ?> <span style="color:#C42B1C;"><?= $cur ?></span></span>
                        <div>
                            <div class="cv-cur-amt" style="color:#C42B1C;"><?= formatMoney($d['orig'], $cur) ?></div>
                            <?php if ($cur !== 'AFN'): ?><div class="cv-cur-eq">≈ <?= formatAFN($d['afn']) ?></div><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; if (!$anyDebtCard): ?>
                    <div class="cv-empty text-success fw-semibold">✓ Fully paid</div>
                    <?php endif; ?>
                    <div class="text-muted mt-2" style="font-size:0.68rem;"><?= $anyDebt ? array_sum(array_column($debtByCur,'cnt')).' open invoices' : 'All settled' ?></div>
                </div>
            </div>
        </div>

        <!-- Contact -->
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="text-muted small mb-1">Contact</div>
                    <div class="fw-semibold"><?= htmlspecialchars($customer['phone'] ?: '—') ?></div>
                    <?php if ($customer['notes']): ?>
                    <div class="text-muted small mt-1"><?= htmlspecialchars($customer['notes']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

    <!-- Invoice history + Payment history (same layout as view.php) -->
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">Invoice History</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Total</th>
                                <th>Balance</th>
                                <th>Date</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sales)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No invoices yet.</td></tr>
                            <?php else: ?>
                            <?php foreach ($sales as $s):
                                $sCur     = $s['currency'] ?? 'AFN';
                                $sCurRate = $rates[$sCur] ?? 1.0;
                                $sTotal   = (float)$s['total_amount'];
                                $sBalance = max(0, $sTotal - (float)$s['paid_amount']);
                                $dispDate = $s['sale_date'] ?: date('Y-m-d', strtotime($s['created_at']));
                                $curColors = ['USD'=>'#0067C0','PKR'=>'#7719AA'];
                            ?>
                            <tr>
                                <td>
                                    <a href="/sales/share.php?id=<?= $s['id'] ?>" class="badge bg-light text-dark text-decoration-none" style="font-family:monospace;">#<?= str_pad($s['id'], 4, '0', STR_PAD_LEFT) ?></a>
                                    <?php if ($sCur !== 'AFN'): ?>
                                    <span style="background:<?= $sCur==='USD'?'rgba(0,103,192,0.1)':'rgba(119,25,170,0.1)' ?>;color:<?= $curColors[$sCur]??'#666' ?>;font-size:0.62rem;font-weight:700;padding:1px 5px;border-radius:3px;margin-left:2px;"><?= $sCur ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($sCur !== 'AFN'): ?>
                                        <div class="fw-semibold"><?= formatMoney(fromAFN($sTotal, $sCurRate), $sCur) ?></div>
                                        <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatAFN($sTotal) ?></div>
                                    <?php else: ?>
                                        <div><?= formatAFN($sTotal) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($sBalance > 0.01): ?>
                                        <?php if ($sCur !== 'AFN'): ?>
                                            <div class="text-danger fw-semibold"><?= formatMoney(fromAFN($sBalance, $sCurRate), $sCur) ?></div>
                                            <div class="text-muted" style="font-size:0.7rem;">≈ <?= formatAFN($sBalance) ?></div>
                                        <?php else: ?>
                                            <div class="text-danger fw-semibold"><?= formatAFN($sBalance) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-success">✓ Fully paid</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?= date('d M Y', strtotime($dispDate)) ?></td>
                                <td><a href="/sales/share.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-light"><i class="bi bi-eye"></i></a></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">Payment History</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Amount</th>
                                <th>Invoice</th>
                                <th>Notes</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No payments yet.</td></tr>
                            <?php else: ?>
                            <?php foreach ($payments as $p):
                                $afn   = (float)$p['amount_afn'] ?: (float)$p['amount'];
                                $cur   = $p['currency'] ?? 'AFN';
                                $pDate = $p['payment_date'] ?: date('Y-m-d', strtotime($p['created_at']));
                            ?>
                            <tr>
                                <td class="fw-bold text-success">
                                    <?= formatMoney((float)$p['amount'], $cur) ?>
                                    <?php if ($cur !== 'AFN'): ?>
                                    <div class="text-muted fw-normal" style="font-size:0.7rem;">≈ <?= formatAFN($afn) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($p['inv_id'])): ?>
                                    <a href="/sales/share.php?id=<?= $p['inv_id'] ?>" class="fw-semibold text-decoration-none" style="font-size:0.82rem;">#<?= str_pad($p['inv_id'], 4, '0', STR_PAD_LEFT) ?></a>
                                    <?php if ($p['inv_bill_no']): ?>
                                    <div class="text-muted" style="font-size:0.68rem;"><?= htmlspecialchars($p['inv_bill_no']) ?></div>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-muted" style="font-size:0.75rem;">General</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?= htmlspecialchars($p['notes'] ?: '—') ?></td>
                                <td class="text-muted small"><?= date('d M Y', strtotime($pDate)) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>
</body>
</html>
