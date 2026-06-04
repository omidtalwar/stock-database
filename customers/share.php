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
    FROM sales s WHERE s.customer_id = ? ORDER BY COALESCE(s.sale_date, DATE(s.created_at)) DESC
");
$sales->execute([$id]);
$sales = $sales->fetchAll();

$payments = $pdo->prepare("
    SELECT p.amount, p.amount_afn, p.currency, p.notes, p.payment_date, p.created_at,
           s.id AS inv_id, s.bill_no AS inv_bill_no
    FROM payments p
    LEFT JOIN sales s ON s.id = p.sale_id
    WHERE p.customer_id = ? ORDER BY COALESCE(p.payment_date, DATE(p.created_at)) DESC, p.created_at DESC
");
$payments->execute([$id]);
$payments = $payments->fetchAll();

// Totals by currency
$salesByCur = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];
$paysByCur  = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];
$debtByCur  = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];

foreach ($sales as $s) {
    $cur  = $s['currency'] ?? 'AFN';
    $afn  = (float)$s['total_amount'];
    $rate = $rates[$cur] ?? 1.0;
    $orig = $cur === 'AFN' ? $afn : fromAFN($afn, $rate);
    if (isset($salesByCur[$cur])) { $salesByCur[$cur]['orig'] += $orig; $salesByCur[$cur]['afn'] += $afn; $salesByCur[$cur]['cnt']++; }
    $bal = max(0.0, $afn - (float)$s['paid_amount']);
    if ($bal > 0.01 && isset($debtByCur[$cur])) { $debtByCur[$cur]['orig'] += $cur==='AFN'?$bal:fromAFN($bal,$rate); $debtByCur[$cur]['afn'] += $bal; $debtByCur[$cur]['cnt']++; }
}
foreach ($payments as $p) {
    $cur = $p['currency'] ?? 'AFN';
    $afn = (float)$p['amount_afn'] ?: (float)$p['amount'];
    if (isset($paysByCur[$cur])) { $paysByCur[$cur]['orig'] += (float)$p['amount']; $paysByCur[$cur]['afn'] += $afn; $paysByCur[$cur]['cnt']++; }
}

$curMeta = [
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
<title><?= htmlspecialchars($customer['name']) ?> — Account Statement</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root {
    --blue: #0067C0;
    --green: #107C10;
    --red: #C42B1C;
    --amber: #9D5D00;
    --muted: #6c757d;
    --border: rgba(0,0,0,0.08);
}
body { background: #f4f6fa; font-family: 'Segoe UI', system-ui, sans-serif; font-size: 14px; color: #1c1c1c; }
.top-bar { background: linear-gradient(135deg, #0067C0, #003E92); color: #fff; padding: 18px 20px 16px; }
.top-bar .brand { font-weight: 900; font-size: 1rem; letter-spacing: 1px; opacity: .9; }
.top-bar .cust-name { font-weight: 700; font-size: 1.25rem; margin-top: 6px; }
.top-bar .cust-shop { font-size: 0.82rem; opacity: .8; }
.top-bar .as-of { font-size: 0.72rem; opacity: .65; margin-top: 4px; }
.wrap { max-width: 760px; margin: 0 auto; padding: 20px 16px 48px; }
.stat-card { background: #fff; border-radius: 14px; padding: 16px 18px; border: 1px solid var(--border); }
.stat-lbl { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); margin-bottom: 8px; }
.cur-row { display: flex; align-items: center; justify-content: space-between; padding: 3px 0; border-bottom: 1px solid rgba(0,0,0,0.05); }
.cur-row:last-child { border-bottom: none; }
.cur-lbl { font-size: 0.72rem; font-weight: 700; display: flex; align-items: center; gap: 4px; }
.cur-amt { font-weight: 700; font-size: 0.88rem; text-align: right; }
.cur-eq  { font-size: 0.64rem; color: var(--muted); text-align: right; }
.section-title { font-weight: 700; font-size: 0.82rem; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 10px; margin-top: 28px; }
.inv-card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; margin-bottom: 8px; }
.inv-num { font-size: 0.72rem; font-weight: 700; font-family: monospace; background: rgba(0,0,0,0.06); border-radius: 4px; padding: 1px 6px; color: #444; }
.inv-total { font-weight: 700; font-size: 0.9rem; }
.inv-bal-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 0.73rem; font-weight: 700; padding: 2px 9px; border-radius: 20px; }
.badge-owed { background: rgba(196,43,28,0.1); color: var(--red); }
.badge-paid { background: rgba(16,124,16,0.1); color: var(--green); }
.pay-card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 12px 16px; margin-bottom: 8px; display: flex; align-items: center; gap: 12px; }
.pay-icon { width: 34px; height: 34px; border-radius: 8px; background: rgba(16,124,16,0.1); color: var(--green); display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
.pay-amt { font-weight: 700; font-size: 0.9rem; color: var(--green); }
.pay-meta { font-size: 0.72rem; color: var(--muted); }
.balance-banner { border-radius: 14px; padding: 18px 20px; margin-bottom: 4px; }
.balance-banner.owed { background: rgba(196,43,28,0.07); border: 1px solid rgba(196,43,28,0.15); }
.balance-banner.clear { background: rgba(16,124,16,0.07); border: 1px solid rgba(16,124,16,0.15); }
.powered { text-align: center; font-size: 0.68rem; color: #bbb; margin-top: 40px; }
</style>
</head>
<body>

<div class="top-bar">
    <div class="brand">FZL</div>
    <div class="cust-name"><?= htmlspecialchars($customer['name']) ?></div>
    <?php if ($customer['shop_name']): ?>
    <div class="cust-shop"><?= htmlspecialchars($customer['shop_name']) ?></div>
    <?php endif; ?>
    <div class="as-of">Statement as of <?= date('d M Y') ?></div>
</div>

<div class="wrap">

    <!-- Balance banner -->
    <div class="balance-banner <?= $anyDebt ? 'owed' : 'clear' ?> mb-4">
        <?php if ($anyDebt): ?>
        <div class="text-muted small mb-1 fw-semibold">Outstanding Balance</div>
        <?php foreach ($curMeta as $cur => $m): $d = $debtByCur[$cur]; if (!$d['cnt']) continue; ?>
        <div style="font-size:1.3rem;font-weight:800;color:var(--red);"><?= formatMoney($d['orig'], $cur) ?></div>
        <?php if ($cur !== 'AFN'): ?><div style="font-size:0.75rem;color:#999;">≈ <?= formatAFN($d['afn']) ?></div><?php endif; ?>
        <?php endforeach; ?>
        <?php else: ?>
        <div style="font-size:1.05rem;font-weight:700;color:var(--green);"><i class="bi bi-check-circle-fill me-2"></i>All invoices fully paid</div>
        <div class="text-muted small mt-1">No outstanding balance</div>
        <?php endif; ?>
    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-2">
        <div class="col-6">
            <div class="stat-card">
                <div class="stat-lbl">Total Invoiced</div>
                <?php $any=false; foreach ($curMeta as $cur => $m): $d=$salesByCur[$cur]; if(!$d['cnt']) continue; $any=true; ?>
                <div class="cur-row">
                    <span class="cur-lbl"><?= $m['flag'] ?> <span style="color:<?= $m['col'] ?>;"><?= $cur ?></span></span>
                    <div>
                        <div class="cur-amt" style="color:<?= $m['col'] ?>;"><?= formatMoney($d['orig'],$cur) ?></div>
                        <?php if($cur!=='AFN'): ?><div class="cur-eq">≈ <?= formatAFN($d['afn']) ?></div><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; if(!$any): ?><div class="text-muted small">—</div><?php endif; ?>
                <div class="text-muted mt-2" style="font-size:0.65rem;"><?= count($sales) ?> invoices</div>
            </div>
        </div>
        <div class="col-6">
            <div class="stat-card">
                <div class="stat-lbl">Total Paid</div>
                <?php $any=false; foreach ($curMeta as $cur => $m): $d=$paysByCur[$cur]; if(!$d['cnt']) continue; $any=true; ?>
                <div class="cur-row">
                    <span class="cur-lbl"><?= $m['flag'] ?> <span style="color:<?= $m['col'] ?>;"><?= $cur ?></span></span>
                    <div>
                        <div class="cur-amt" style="color:var(--green);"><?= formatMoney($d['orig'],$cur) ?></div>
                        <?php if($cur!=='AFN'): ?><div class="cur-eq">≈ <?= formatAFN($d['afn']) ?></div><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; if(!$any): ?><div class="text-muted small">No payments yet</div><?php endif; ?>
                <div class="text-muted mt-2" style="font-size:0.65rem;"><?= count($payments) ?> payments</div>
            </div>
        </div>
    </div>

    <!-- Invoice history -->
    <div class="section-title"><i class="bi bi-receipt me-1"></i>Invoice History</div>
    <?php if (empty($sales)): ?>
    <div class="text-center text-muted py-4" style="font-size:0.85rem;">No invoices yet.</div>
    <?php else: ?>
    <?php foreach ($sales as $s):
        $cur     = $s['currency'] ?? 'AFN';
        $rate    = $rates[$cur] ?? 1.0;
        $total   = (float)$s['total_amount'];
        $balance = max(0, $total - (float)$s['paid_amount']);
        $dispTotal = $cur === 'AFN' ? formatAFN($total) : formatMoney(fromAFN($total,$rate),$cur);
        $dispDate = $s['sale_date'] ?: date('Y-m-d', strtotime($s['created_at']));
    ?>
    <div class="inv-card">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <div>
                <span class="inv-num">#<?= str_pad($s['id'],4,'0',STR_PAD_LEFT) ?></span>
                <?php if ($s['bill_no']): ?>
                <span class="text-muted ms-1" style="font-size:0.7rem;"><?= htmlspecialchars($s['bill_no']) ?></span>
                <?php endif; ?>
                <div class="inv-total mt-1"><?= $dispTotal ?></div>
                <?php if ($cur !== 'AFN'): ?>
                <div style="font-size:0.7rem;color:var(--muted);">≈ <?= formatAFN($total) ?></div>
                <?php endif; ?>
            </div>
            <div class="text-end flex-shrink-0">
                <?php if ($balance > 0.01): ?>
                <span class="inv-bal-badge badge-owed">
                    <i class="bi bi-dot"></i>
                    <?= $cur==='AFN' ? formatAFN($balance) : formatMoney(fromAFN($balance,$rate),$cur) ?>
                </span>
                <?php else: ?>
                <span class="inv-bal-badge badge-paid"><i class="bi bi-check2"></i> Paid</span>
                <?php endif; ?>
                <div class="text-muted mt-1" style="font-size:0.68rem;"><?= date('d M Y', strtotime($dispDate)) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Payment history -->
    <div class="section-title"><i class="bi bi-cash-coin me-1"></i>Payment History</div>
    <?php if (empty($payments)): ?>
    <div class="text-center text-muted py-4" style="font-size:0.85rem;">No payments recorded yet.</div>
    <?php else: ?>
    <?php foreach ($payments as $p):
        $cur   = $p['currency'] ?? 'AFN';
        $afn   = (float)$p['amount_afn'] ?: (float)$p['amount'];
        $pDate = $p['payment_date'] ?: date('Y-m-d', strtotime($p['created_at']));
    ?>
    <div class="pay-card">
        <div class="pay-icon"><i class="bi bi-check2-circle"></i></div>
        <div style="flex:1;min-width:0;">
            <div class="pay-amt"><?= formatMoney((float)$p['amount'], $cur) ?></div>
            <?php if ($cur !== 'AFN'): ?><div class="pay-meta">≈ <?= formatAFN($afn) ?></div><?php endif; ?>
            <?php if ($p['notes']): ?><div class="pay-meta"><?= htmlspecialchars($p['notes']) ?></div><?php endif; ?>
        </div>
        <div class="text-muted text-end flex-shrink-0" style="font-size:0.72rem;">
            <?= date('d M Y', strtotime($pDate)) ?>
            <?php if ($p['inv_id']): ?>
            <div>Invoice #<?= str_pad($p['inv_id'],4,'0',STR_PAD_LEFT) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="powered">Powered by FZL Management System</div>
</div>

</body>
</html>
