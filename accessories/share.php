<?php
require_once '../config/db.php';
require_once '../includes/currency.php';
require_once 'helpers.php';

$id   = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM accessory_owners WHERE id = ?");
$stmt->execute([$id]);
$owner = $stmt->fetch();
if (!$owner) { http_response_code(404); exit('Owner not found.'); }

$categories = accessoryCategories();

$entriesStmt = $pdo->prepare("
    SELECT * FROM accessory_stock_entries
    WHERE owner_id = ?
    ORDER BY COALESCE(entry_date, DATE(created_at)) DESC, id DESC
");
$entriesStmt->execute([$id]);
$entries = $entriesStmt->fetchAll();

$issued      = ['original' => 0, 'coffee' => 0, 'pes' => 0, 'plastic' => 0];
$billsAmount = 0.0;
foreach ($entries as $e) {
    $issued['original'] += (float)$e['original_size'];
    $issued['coffee']   += (float)$e['coffee_size'];
    $issued['pes']      += (float)$e['pes_size'];
    $issued['plastic']  += (float)$e['plastic_size'];
    $billsAmount        += (float)$e['total_amount'];
}

$addStmt = $pdo->prepare("SELECT category, COALESCE(SUM(quantity),0) added FROM accessory_stock_ins WHERE owner_id = ? GROUP BY category");
$addStmt->execute([$id]);
$added = ['original' => 0, 'coffee' => 0, 'pes' => 0, 'plastic' => 0];
foreach ($addStmt->fetchAll() as $r) { if (isset($added[$r['category']])) $added[$r['category']] = (float)$r['added']; }

$payStmt = $pdo->prepare("SELECT kind, COALESCE(SUM(amount),0) t FROM accessory_payments WHERE owner_id = ? GROUP BY kind");
$payStmt->execute([$id]);
$charged = 0.0; $paid = 0.0;
foreach ($payStmt->fetchAll() as $r) { if ($r['kind'] === 'charge') $charged = (float)$r['t']; else $paid = (float)$r['t']; }

$balances = [
    'original' => ['label' => 'اصلي چیکو', 'opening' => (float)$owner['opening_original']],
    'coffee'   => ['label' => 'کافی',       'opening' => (float)$owner['opening_coffee']],
    'pes'      => ['label' => 'Pes',          'opening' => (float)$owner['opening_pes']],
    'plastic'  => ['label' => 'پلاستیکی',    'opening' => (float)$owner['opening_plastic']],
];
foreach ($balances as $k => &$b) {
    $b['added']     = $added[$k];
    $b['issued']    = $issued[$k];
    $b['remaining'] = $b['opening'] + $b['added'] - $b['issued'];
}
unset($b);

$payable   = $billsAmount + $charged;
$remaining = $payable - $paid;
$isCleared = $remaining <= 0.01;

// Group entry rows into individual bills.
$bills = [];
foreach ($entries as $e) {
    $key = !empty($e['bill_group']) ? 'g:' . $e['bill_group'] : 'r:' . $e['id'];
    if (!isset($bills[$key])) {
        $bills[$key] = ['date' => $e['entry_date'], 'bill_no' => $e['bill_no'], 'rows' => [], 'amount' => 0.0, 'qty' => 0.0];
    }
    $bills[$key]['rows'][] = $e;
    $bills[$key]['amount'] += (float)$e['total_amount'];
    $bills[$key]['qty']    += (float)$e['quantity'];
}

$shareUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . '/accessories/share.php?id=' . $id;
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($owner['name']) ?> — Statement — FZL Stocks</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', system-ui, -apple-system, sans-serif; font-size: 14px; color: #1a1a2e; background: #eef2f7; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.page { max-width: 820px; margin: 2rem auto; padding: 0 1rem 5rem; }
.invoice { background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 32px rgba(0,0,0,.10), 0 1px 4px rgba(0,0,0,.06); }
.accent-bar { height: 6px; background: linear-gradient(90deg,#7C3AED 0%,#A78BFA 100%); }
.inv-header { display:flex; justify-content:space-between; align-items:flex-start; padding:2rem 2.5rem 1.5rem; border-bottom:1px solid #f0f2f5; gap:1.5rem; flex-wrap:wrap; }
.company-name { font-size:1.5rem; font-weight:700; color:#7C3AED; letter-spacing:-.02em; line-height:1; }
.company-sub { font-size:.8rem; color:#888; margin-top:.3rem; }
.inv-meta { text-align:right; }
.inv-title { font-size:1.7rem; font-weight:700; color:#1a1a2e; letter-spacing:-.03em; line-height:1; }
.inv-number { font-size:.85rem; color:#7C3AED; font-weight:600; margin-top:.35rem; }
.bill-row { display:flex; justify-content:space-between; align-items:flex-start; padding:1.5rem 2.5rem; background:#f8fafc; border-bottom:1px solid #f0f2f5; gap:1rem; flex-wrap:wrap; }
.bill-label { font-size:.7rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#aaa; margin-bottom:.5rem; }
.bill-name { font-size:1.1rem; font-weight:700; color:#1a1a2e; }
.bill-phone { font-size:.8rem; color:#888; margin-top:.1rem; }
.status-pill { display:inline-flex; align-items:center; gap:.35rem; padding:.45rem 1rem; border-radius:2rem; font-size:.8rem; font-weight:700; }
.status-paid { background:#dcfce7; color:#15803d; }
.status-unpaid { background:#fee2e2; color:#b91c1c; }
.section { padding:1.5rem 2.5rem 0; }
.section-title { font-size:.7rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#aaa; margin-bottom:.75rem; }
table { width:100%; border-collapse:collapse; }
thead tr { background:#f1f5f9; }
thead th { padding:.6rem .8rem; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#64748b; white-space:nowrap; text-align:center; }
thead th:first-child { border-radius:8px 0 0 8px; text-align:left; }
thead th:last-child { border-radius:0 8px 8px 0; }
tbody tr { border-bottom:1px solid #f1f5f9; }
tbody td { padding:.6rem .8rem; font-size:.85rem; color:#374151; text-align:center; }
tbody td:first-child { text-align:left; }
tfoot td { padding:.6rem .8rem; font-weight:700; font-size:.85rem; background:#f8fafc; text-align:center; }
tfoot td:first-child { text-align:left; }
.pos { color:#15803d; } .neg { color:#b91c1c; } .muted { color:#94a3b8; }
.acct { display:flex; gap:1rem; padding:1.5rem 2.5rem; flex-wrap:wrap; }
.acct-box { flex:1 1 150px; background:#f8fafc; border:1px solid #eef2f7; border-radius:12px; padding:1rem 1.25rem; }
.acct-box .lbl { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#aaa; }
.acct-box .val { font-size:1.35rem; font-weight:700; margin-top:.25rem; font-variant-numeric:tabular-nums; }
.bill-card { border:1px solid #eef2f7; border-radius:12px; margin-bottom:1rem; overflow:hidden; }
.bill-card-head { display:flex; justify-content:space-between; align-items:center; padding:.6rem 1rem; background:#f8fafc; border-bottom:1px solid #eef2f7; font-size:.85rem; }
.inv-footer { background:#f8fafc; border-top:1px solid #f0f2f5; padding:1.25rem 2.5rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem; }
.footer-brand { font-size:.75rem; color:#94a3b8; }
.footer-brand span { color:#7C3AED; font-weight:600; }
.fab-bar { position:fixed; bottom:1.25rem; left:50%; transform:translateX(-50%); display:flex; gap:.5rem; background:rgba(255,255,255,.95); backdrop-filter:blur(12px); border:1px solid rgba(0,0,0,.1); border-radius:2rem; padding:.45rem .6rem; box-shadow:0 4px 24px rgba(0,0,0,.14); z-index:999; }
.fab-btn { display:inline-flex; align-items:center; gap:.35rem; border:none; border-radius:1.5rem; padding:.5rem 1.1rem; font-size:.82rem; font-weight:600; cursor:pointer; font-family:inherit; text-decoration:none; }
.fab-btn.primary { background:#7C3AED; color:#fff; } .fab-btn.green { background:#25D366; color:#fff; } .fab-btn.ghost { background:#f1f5f9; color:#374151; }
#toast { position:fixed; bottom:5rem; left:50%; transform:translateX(-50%) translateY(12px); background:#1a1a2e; color:#fff; padding:.5rem 1.25rem; border-radius:2rem; font-size:.82rem; opacity:0; transition:opacity .2s, transform .2s; pointer-events:none; z-index:1000; }
#toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
@page { size:A4; margin:1.5cm 1.8cm; }
@media print { body { background:#fff; } .page { margin:0; padding:0; max-width:100%; } .invoice { box-shadow:none; border-radius:0; } .fab-bar, #toast { display:none !important; } }
@media (max-width:600px) { .inv-header,.bill-row,.section,.acct,.inv-footer { padding-left:1.25rem; padding-right:1.25rem; } .inv-meta { text-align:left; } }
</style>
</head>
<body>
<div class="page">
<div class="invoice">
    <div class="accent-bar"></div>

    <div class="inv-header">
        <div>
            <div class="company-name">FZL Stocks</div>
            <div class="company-sub">دوکان رخت فروشی فضل الحق · Accessories</div>
        </div>
        <div class="inv-meta">
            <div class="inv-title">STATEMENT</div>
            <div class="inv-number"><?= date('d M Y') ?></div>
        </div>
    </div>

    <div class="bill-row">
        <div>
            <div class="bill-label">Account</div>
            <div class="bill-name"><?= htmlspecialchars($owner['name']) ?></div>
            <?php if (!empty($owner['phone'])): ?><div class="bill-phone">📞 <?= htmlspecialchars($owner['phone']) ?></div><?php endif; ?>
        </div>
        <div style="text-align:right;">
            <div class="bill-label">Status</div>
            <span class="status-pill <?= $isCleared ? 'status-paid' : 'status-unpaid' ?>">
                <?= $isCleared ? '✓ Cleared' : '⚠ ' . formatAFN($remaining) . ' due' ?>
            </span>
        </div>
    </div>

    <!-- Account summary -->
    <div class="acct">
        <div class="acct-box"><div class="lbl">Total Amount</div><div class="val"><?= formatAFN($payable) ?></div></div>
        <div class="acct-box"><div class="lbl">Paid</div><div class="val neg"><?= formatAFN($paid) ?></div></div>
        <div class="acct-box"><div class="lbl">Remaining</div><div class="val <?= $isCleared ? 'pos' : 'neg' ?>"><?= formatAFN($remaining) ?></div></div>
    </div>

    <!-- Stock balance per category -->
    <div class="section">
        <div class="section-title">Stock Balance per Category</div>
        <table>
            <thead>
                <tr>
                    <th></th>
                    <?php foreach ($balances as $b): ?><th><?= htmlspecialchars($b['label']) ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr><td>Opening</td><?php foreach ($balances as $b): ?><td><?= number_format($b['opening'], 2) ?></td><?php endforeach; ?></tr>
                <tr><td>Added</td><?php foreach ($balances as $b): ?><td class="pos"><?= number_format($b['added'], 2) ?></td><?php endforeach; ?></tr>
                <tr><td>Issued</td><?php foreach ($balances as $b): ?><td class="neg"><?= number_format($b['issued'], 2) ?></td><?php endforeach; ?></tr>
            </tbody>
            <tfoot>
                <tr><td>Remaining</td><?php foreach ($balances as $b): ?><td class="<?= $b['remaining'] < 0 ? 'neg' : 'pos' ?>"><?= number_format($b['remaining'], 2) ?></td><?php endforeach; ?></tr>
            </tfoot>
        </table>
    </div>

    <!-- Bills -->
    <div class="section" style="padding-bottom:1.5rem;">
        <div class="section-title">Bills (<?= count($bills) ?>)</div>
        <?php if (empty($bills)): ?>
        <div class="muted" style="padding:1rem 0;">No bills yet.</div>
        <?php else: foreach ($bills as $bill): ?>
        <div class="bill-card">
            <div class="bill-card-head">
                <div><strong>بل: <?= htmlspecialchars($bill['bill_no'] ?? '') ?: '—' ?></strong> <span class="muted"><?= htmlspecialchars(accessoryShamsiDate($bill['date'])) ?></span></div>
                <div class="pos" style="font-weight:700;"><?= formatAFN($bill['amount']) ?></div>
            </div>
            <table>
                <thead>
                    <tr><th>جنس</th><th>تعداد</th><th>اصلي چیکو</th><th>کافی</th><th>Pes</th><th>پلاستیکی</th><th>مترانه</th><th>جمله</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($bill['rows'] as $r): ?>
                    <tr>
                        <td style="font-weight:600;"><?= htmlspecialchars($r['item_name']) ?></td>
                        <td><?= number_format((float)$r['quantity'], 2) ?></td>
                        <td><?= $r['original_size'] !== null ? number_format((float)$r['original_size'], 2) : '-' ?></td>
                        <td><?= $r['coffee_size'] !== null ? number_format((float)$r['coffee_size'], 2) : '-' ?></td>
                        <td><?= $r['pes_size'] !== null ? number_format((float)$r['pes_size'], 2) : '-' ?></td>
                        <td><?= $r['plastic_size'] !== null ? number_format((float)$r['plastic_size'], 2) : '-' ?></td>
                        <td><?= $r['meterage'] !== null ? number_format((float)$r['meterage'], 2) : '-' ?></td>
                        <td style="font-weight:600;"><?= formatAFN((float)$r['total_amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="inv-footer">
        <div class="muted" style="font-style:italic; font-size:.8rem;">Statement generated <?= date('d M Y, H:i') ?></div>
        <div class="footer-brand">Generated by <span>FZL Stocks</span></div>
    </div>
</div>
</div>

<?php
$waMsg = "📋 Account — " . $owner['name'] . "\n"
       . "💰 Total: " . formatAFN($payable) . "\n"
       . "✅ Paid: " . formatAFN($paid) . "\n"
       . ($isCleared ? "✅ Cleared\n" : "⚠️ Remaining: " . formatAFN($remaining) . "\n")
       . "🔗 " . $shareUrl;
?>
<div class="fab-bar">
    <button class="fab-btn ghost" onclick="window.print()">🖨 Print / PDF</button>
    <a class="fab-btn green" href="https://wa.me/?text=<?= urlencode($waMsg) ?>" target="_blank" rel="noopener">WhatsApp</a>
    <button class="fab-btn primary" onclick="copyLink()">🔗 Copy Link</button>
</div>
<div id="toast">✓ Link copied</div>
<script>
function copyLink() {
    navigator.clipboard.writeText(<?= json_encode($shareUrl) ?>).then(() => {
        const t = document.getElementById('toast');
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 2000);
    });
}
</script>
</body>
</html>
