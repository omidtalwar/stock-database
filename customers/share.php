<?php
require_once '../config/db.php';
require_once '../includes/currency.php';

$token = trim($_GET['token'] ?? '');
if ($token === '') { http_response_code(404); die('Not found.'); }

$customer = $pdo->prepare("SELECT * FROM customers WHERE share_token = ? LIMIT 1");
$customer->execute([$token]);
$customer = $customer->fetch();
if (!$customer) { http_response_code(404); die('This link is no longer valid.'); }

$id    = (int)$customer['id'];
$rates = getAllRates($pdo);

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

$salesByCur = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];
$paysByCur  = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];
$debtByCur  = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];

// Build invoice-level debt first, then subtract general payments per currency
foreach ($sales as $s) {
    $cur = $s['currency'] ?? 'AFN'; $afn = (float)$s['total_amount']; $rate = $rates[$cur] ?? 1.0;
    $orig = $cur === 'AFN' ? $afn : fromAFN($afn, $rate);
    if (isset($salesByCur[$cur])) { $salesByCur[$cur]['orig'] += $orig; $salesByCur[$cur]['afn'] += $afn; $salesByCur[$cur]['cnt']++; }
    $bal = max(0.0, $afn - (float)$s['paid_amount']);
    if ($bal > 0.01 && isset($debtByCur[$cur])) {
        $debtByCur[$cur]['orig'] += $cur==='AFN'?$bal:fromAFN($bal,$rate);
        $debtByCur[$cur]['afn']  += $bal;
        $debtByCur[$cur]['cnt']++;
    }
}
foreach ($payments as $p) {
    $cur = $p['currency'] ?? 'AFN'; $afn = (float)$p['amount_afn'] ?: (float)$p['amount'];
    if (isset($paysByCur[$cur])) { $paysByCur[$cur]['orig'] += (float)$p['amount']; $paysByCur[$cur]['afn'] += $afn; $paysByCur[$cur]['cnt']++; }
    if (empty($p['inv_id']) && isset($debtByCur[$cur])) {
        $debtByCur[$cur]['orig'] = max(0, $debtByCur[$cur]['orig'] - (float)$p['amount']);
        $debtByCur[$cur]['afn']  = max(0, $debtByCur[$cur]['afn']  - $afn);
        if ($debtByCur[$cur]['orig'] < 0.01) $debtByCur[$cur]['cnt'] = 0;
    }
}
$anyDebt = array_sum(array_column($debtByCur,'orig')) > 0.01;
$curMeta = ['AFN'=>['flag'=>'🇦🇫','col'=>'#16a34a'],'USD'=>['flag'=>'🇺🇸','col'=>'#1d4ed8'],'PKR'=>['flag'=>'🇵🇰','col'=>'#7c3aed']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account Statement — <?= htmlspecialchars($customer['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 14px; }
body {
    font-family: 'Inter', system-ui, sans-serif;
    background: #f0f2f5;
    color: #111827;
    min-height: 100vh;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* ── Wrapper ── */
.page { max-width: 860px; margin: 0 auto; padding: 32px 16px 64px; }

/* ── Statement card ── */
.stmt { background: #fff; border-radius: 4px; border: 1px solid #d1d5db; overflow: hidden; }

/* ── Header ── */
.stmt-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    padding: 32px 36px 24px; border-bottom: 2px solid #111827; gap: 24px; flex-wrap: wrap;
}
.brand-block .brand { font-size: 1.35rem; font-weight: 800; color: #111827; letter-spacing: -.5px; }
.brand-block .brand span { color: #2563eb; }
.brand-block .sub { font-size: .72rem; color: #6b7280; margin-top: 3px; }
.stmt-title-block { text-align: right; }
.stmt-title-block .title { font-size: 1.1rem; font-weight: 700; text-transform: uppercase; letter-spacing: 2px; color: #111827; }
.stmt-title-block .date { font-size: .72rem; color: #6b7280; margin-top: 4px; }

/* ── Customer info band ── */
.customer-band {
    display: flex; justify-content: space-between; align-items: center;
    padding: 16px 36px; background: #f9fafb; border-bottom: 1px solid #e5e7eb;
    gap: 16px; flex-wrap: wrap;
}
.customer-band .lbl { font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; margin-bottom: 3px; }
.customer-band .name { font-size: 1rem; font-weight: 700; color: #111827; }
.customer-band .shop { font-size: .8rem; color: #6b7280; }
.customer-band .phone { font-size: .78rem; color: #6b7280; margin-top: 2px; }

/* ── Balance status chip ── */
.status-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 4px; font-size: .78rem; font-weight: 700;
    white-space: nowrap;
}
.status-chip.owed { background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; }
.status-chip.clear { background: #f0fdf4; border: 1px solid #86efac; color: #15803d; }

/* ── Summary row ── */
.summary-row {
    display: grid; grid-template-columns: repeat(3, 1fr);
    border-bottom: 1px solid #e5e7eb;
}
.summary-cell {
    padding: 20px 28px; border-right: 1px solid #e5e7eb;
}
.summary-cell:last-child { border-right: none; }
.summary-cell .s-lbl { font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; margin-bottom: 8px; }
.summary-cell .s-val { font-size: 1.05rem; font-weight: 700; line-height: 1.2; }
.summary-cell .s-sub { font-size: .68rem; color: #9ca3af; margin-top: 2px; }
.summary-cell .s-count { font-size: .68rem; color: #9ca3af; margin-top: 6px; }
.cur-line { display: flex; align-items: baseline; gap: 6px; margin-bottom: 2px; }
.cur-flag { font-size: .75rem; }

/* ── Section ── */
.section-hdr {
    padding: 14px 36px 10px;
    display: flex; align-items: center; gap: 10px;
    border-bottom: 1px solid #e5e7eb;
    background: #f9fafb;
}
.section-hdr .s-title { font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px; color: #6b7280; }
.section-hdr .s-badge { font-size: .65rem; font-weight: 700; padding: 1px 7px; border-radius: 10px; background: #e5e7eb; color: #374151; }

/* ── Table ── */
.data-table { width: 100%; border-collapse: collapse; }
.data-table thead th {
    padding: 9px 16px 9px 36px; font-size: .65rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .8px; color: #9ca3af;
    background: #fff; border-bottom: 1px solid #e5e7eb; white-space: nowrap;
    text-align: left;
}
.data-table thead th:last-child { padding-right: 36px; }
.data-table tbody td { padding: 13px 16px 13px 36px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.data-table tbody td:last-child { padding-right: 36px; }
.data-table tbody tr:last-child td { border-bottom: none; }
.data-table tbody tr:hover { background: #fafafa; }

/* cells */
.inv-num { font-family: 'Courier New', monospace; font-size: .78rem; font-weight: 700; color: #374151; background: #f3f4f6; padding: 2px 7px; border-radius: 3px; text-decoration: none; }
.inv-num:hover { background: #e5e7eb; color: #111827; }
.cur-tag { font-size: .6rem; font-weight: 700; padding: 1px 5px; border-radius: 3px; margin-left: 4px; vertical-align: middle; }
.amt { font-weight: 600; font-size: .88rem; }
.amt-sub { font-size: .66rem; color: #9ca3af; margin-top: 1px; }
.bal-owed { font-weight: 700; color: #b91c1c; }
.bal-paid { display: inline-flex; align-items: center; gap: 4px; font-size: .78rem; color: #15803d; font-weight: 600; }
.date-cell { font-size: .8rem; color: #6b7280; white-space: nowrap; }
.view-link {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: .72rem; font-weight: 600; color: #2563eb;
    text-decoration: none; padding: 4px 10px; border-radius: 4px;
    border: 1px solid #bfdbfe; background: #eff6ff;
    transition: background .12s;
}
.view-link:hover { background: #dbeafe; color: #1d4ed8; }
.pay-amt { font-weight: 700; font-size: .9rem; color: #15803d; }
.pay-inv { font-size: .78rem; font-weight: 600; color: #2563eb; text-decoration: none; }
.pay-inv:hover { text-decoration: underline; }
.note { font-size: .75rem; color: #6b7280; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.empty-row td { text-align: center; padding: 32px; color: #9ca3af; font-size: .82rem; }

/* ── Footer ── */
.stmt-footer {
    padding: 16px 36px; border-top: 1px solid #e5e7eb; background: #f9fafb;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;
}
.stmt-footer .note-text { font-size: .72rem; color: #9ca3af; font-style: italic; }
.stmt-footer .brand-small { font-size: .7rem; color: #9ca3af; }
.stmt-footer .brand-small strong { color: #6b7280; }

/* ── Print ── */
@page { size: A4; margin: 1.5cm 2cm; }
@media print {
    body { background: #fff; }
    .page { padding: 0; max-width: 100%; }
    .stmt { border: none; border-radius: 0; box-shadow: none; }
    .no-print { display: none !important; }
    .view-link { display: none !important; }
}

/* ── Mobile ── */
@media (max-width: 600px) {
    .stmt-header, .customer-band, .section-hdr { padding-left: 20px; padding-right: 20px; }
    .summary-cell { padding: 16px 20px; }
    .data-table thead th, .data-table tbody td { padding-left: 12px; padding-right: 12px; }
    .data-table thead th:first-child, .data-table tbody td:first-child { padding-left: 20px; }
    .data-table thead th:last-child, .data-table tbody td:last-child { padding-right: 20px; }
    .stmt-footer { padding: 14px 20px; }
    .summary-row { grid-template-columns: 1fr; }
    .summary-cell { border-right: none; border-bottom: 1px solid #e5e7eb; }
    .summary-cell:last-child { border-bottom: none; }
}
</style>
</head>
<body>
<div class="page">
<div class="stmt">

    <!-- Header -->
    <div class="stmt-header">
        <div class="brand-block">
            <div class="brand">FZL <span>Stocks</span></div>
            <div class="sub">fzlstocks.shop</div>
        </div>
        <div class="stmt-title-block">
            <div class="title">Account Statement</div>
            <div class="date">Generated <?= date('d F Y') ?></div>
        </div>
    </div>

    <!-- Customer info band -->
    <div class="customer-band">
        <div>
            <div class="lbl">Bill To</div>
            <div class="name"><?= htmlspecialchars($customer['name']) ?></div>
            <?php if ($customer['shop_name']): ?>
            <div class="shop"><?= htmlspecialchars($customer['shop_name']) ?></div>
            <?php endif; ?>
            <?php if ($customer['phone']): ?>
            <div class="phone"><?= htmlspecialchars($customer['phone']) ?></div>
            <?php endif; ?>
        </div>
        <div>
            <?php if ($anyDebt): ?>
            <span class="status-chip owed">
                ⚠ Balance Due
            </span>
            <?php else: ?>
            <span class="status-chip clear">
                ✓ Account Settled
            </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary -->
    <div class="summary-row">

        <div class="summary-cell">
            <div class="s-lbl">Total Invoiced</div>
            <?php $any=false; foreach($curMeta as $cur=>$m): $d=$salesByCur[$cur]; if(!$d['cnt']) continue; $any=true; ?>
            <div class="s-val" style="color:<?= $m['col'] ?>;"><?= formatMoney($d['orig'],$cur) ?></div>
            <?php if($cur!=='AFN'): ?><div class="s-sub">≈ <?= formatAFN($d['afn']) ?></div><?php endif; ?>
            <?php endforeach; if(!$any): ?><div class="s-val" style="color:#9ca3af;">—</div><?php endif; ?>
            <div class="s-count"><?= count($sales) ?> invoice<?= count($sales)!==1?'s':'' ?></div>
        </div>

        <div class="summary-cell">
            <div class="s-lbl">Total Paid</div>
            <?php $any=false; foreach($curMeta as $cur=>$m): $d=$paysByCur[$cur]; if(!$d['cnt']) continue; $any=true; ?>
            <div class="s-val" style="color:#15803d;"><?= formatMoney($d['orig'],$cur) ?></div>
            <?php if($cur!=='AFN'): ?><div class="s-sub">≈ <?= formatAFN($d['afn']) ?></div><?php endif; ?>
            <?php endforeach; if(!$any): ?><div class="s-val" style="color:#9ca3af;">No payments yet</div><?php endif; ?>
            <div class="s-count"><?= count($payments) ?> payment<?= count($payments)!==1?'s':'' ?></div>
        </div>

        <div class="summary-cell">
            <div class="s-lbl">Balance Due</div>
            <?php $anyD=false; foreach($curMeta as $cur=>$m): $d=$debtByCur[$cur]; if($d['orig']<0.01) continue; $anyD=true; ?>
            <div class="s-val" style="color:#b91c1c;"><?= formatMoney($d['orig'],$cur) ?></div>
            <?php if($cur!=='AFN'): ?><div class="s-sub">≈ <?= formatAFN($d['afn']) ?></div><?php endif; ?>
            <?php endforeach; if(!$anyD): ?>
            <div class="s-val" style="color:#15803d;">Nil</div>
            <div class="s-sub" style="color:#15803d;">Fully settled</div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Invoice History -->
    <div class="section-hdr">
        <span class="s-title">Invoice History</span>
        <span class="s-badge"><?= count($sales) ?></span>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Date</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Balance</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($sales)): ?>
        <tr class="empty-row"><td colspan="6">No invoices on record.</td></tr>
        <?php else: ?>
        <?php foreach ($sales as $s):
            $sCur     = $s['currency'] ?? 'AFN';
            $sRate    = $rates[$sCur] ?? 1.0;
            $sTotal   = (float)$s['total_amount'];
            $sPaid    = (float)$s['paid_amount'];
            $sBal     = max(0, $sTotal - $sPaid);
            $dispDate = $s['sale_date'] ?: date('Y-m-d', strtotime($s['created_at']));
            $sTotalFmt = $sCur==='AFN' ? formatAFN($sTotal) : formatMoney(fromAFN($sTotal,$sRate),$sCur);
            $sPaidFmt  = $sCur==='AFN' ? formatAFN($sPaid)  : formatMoney(fromAFN($sPaid,$sRate),$sCur);
            $sBalFmt   = $sCur==='AFN' ? formatAFN($sBal)   : formatMoney(fromAFN($sBal,$sRate),$sCur);
            $curTagStyle = $sCur==='USD'?'background:#dbeafe;color:#1d4ed8;':($sCur==='PKR'?'background:#ede9fe;color:#6d28d9;':'');
        ?>
        <tr>
            <td>
                <a href="/sales/share.php?id=<?= $s['id'] ?>" class="inv-num">#<?= str_pad($s['id'],4,'0',STR_PAD_LEFT) ?></a>
                <?php if ($sCur !== 'AFN'): ?>
                <span class="cur-tag" style="<?= $curTagStyle ?>"><?= $sCur ?></span>
                <?php endif; ?>
                <?php if ($s['bill_no']): ?>
                <div style="font-size:.66rem;color:#9ca3af;margin-top:2px;"><?= htmlspecialchars($s['bill_no']) ?></div>
                <?php endif; ?>
            </td>
            <td class="date-cell"><?= date('d M Y', strtotime($dispDate)) ?></td>
            <td>
                <div class="amt"><?= $sTotalFmt ?></div>
                <?php if($sCur!=='AFN'): ?><div class="amt-sub">≈ <?= formatAFN($sTotal) ?></div><?php endif; ?>
            </td>
            <td>
                <div class="amt" style="color:#15803d;"><?= $sPaidFmt ?></div>
                <?php if($sCur!=='AFN' && $sPaid>0): ?><div class="amt-sub">≈ <?= formatAFN($sPaid) ?></div><?php endif; ?>
            </td>
            <td>
                <?php if ($sBal > 0.01): ?>
                <div class="bal-owed"><?= $sBalFmt ?></div>
                <?php if($sCur!=='AFN'): ?><div class="amt-sub">≈ <?= formatAFN($sBal) ?></div><?php endif; ?>
                <?php else: ?>
                <span class="bal-paid">✓ Paid</span>
                <?php endif; ?>
            </td>
            <td><a href="/sales/share.php?id=<?= $s['id'] ?>" class="view-link no-print">View ›</a></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- Payment History -->
    <div class="section-hdr" style="margin-top:0;border-top:2px solid #e5e7eb;">
        <span class="s-title">Payment History</span>
        <span class="s-badge"><?= count($payments) ?></span>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Amount</th>
                <th>Invoice</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($payments)): ?>
        <tr class="empty-row"><td colspan="4">No payments recorded yet.</td></tr>
        <?php else: ?>
        <?php foreach ($payments as $p):
            $cur   = $p['currency'] ?? 'AFN';
            $afn   = (float)$p['amount_afn'] ?: (float)$p['amount'];
            $pDate = $p['payment_date'] ?: date('Y-m-d', strtotime($p['created_at']));
        ?>
        <tr>
            <td class="date-cell"><?= date('d M Y', strtotime($pDate)) ?></td>
            <td>
                <div class="pay-amt"><?= formatMoney((float)$p['amount'], $cur) ?></div>
                <?php if ($cur !== 'AFN'): ?><div class="amt-sub">≈ <?= formatAFN($afn) ?></div><?php endif; ?>
            </td>
            <td>
                <?php if (!empty($p['inv_id'])): ?>
                <a href="/sales/share.php?id=<?= $p['inv_id'] ?>" class="pay-inv">#<?= str_pad($p['inv_id'],4,'0',STR_PAD_LEFT) ?></a>
                <?php if ($p['inv_bill_no']): ?><div class="amt-sub"><?= htmlspecialchars($p['inv_bill_no']) ?></div><?php endif; ?>
                <?php else: ?><span style="color:#9ca3af;font-size:.78rem;">General</span><?php endif; ?>
            </td>
            <td class="note" title="<?= htmlspecialchars($p['notes'] ?? '') ?>"><?= htmlspecialchars($p['notes'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- Footer -->
    <div class="stmt-footer">
        <div class="note-text">This statement is for informational purposes only.</div>
        <div class="brand-small">Generated by <strong>FZL Stocks</strong> · fzlstocks.shop</div>
    </div>

</div><!-- /.stmt -->
</div><!-- /.page -->
</body>
</html>
