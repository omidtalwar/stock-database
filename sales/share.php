<?php
require_once '../config/db.php';
require_once '../includes/currency.php';

ensureSaleRates($pdo); // freeze invoices to their sale-time rate

$id   = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT s.*, c.name AS customer_name, c.shop_name, c.phone
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$sale = $stmt->fetch();
if (!$sale) { http_response_code(404); exit('Invoice not found.'); }

$stmt2 = $pdo->prepare("
    SELECT si.*, COALESCE(p.name, si.custom_name, 'Item') AS product_name, p.size, p.color
    FROM sale_items si LEFT JOIN products p ON p.id = si.product_id
    WHERE si.sale_id = ?
");
$stmt2->execute([$id]);
$items = $stmt2->fetchAll();

$allRates   = getAllRates($pdo);
$saleCur    = $sale['currency'] ?? 'AFN';
// Use the rate stored on the sale (frozen at creation); fall back to live only if missing.
$saleCurRate = !empty($sale['exchange_rate']) ? (float)$sale['exchange_rate'] : ($allRates[$saleCur] ?? 1.0);

// Format an AFN-stored amount in the sale's original currency
function fmtSale(float $afn): string {
    global $saleCur, $saleCurRate;
    if ($saleCur === 'AFN') return formatAFN($afn);
    return formatMoney(fromAFN($afn, $saleCurRate), $saleCur);
}
// Secondary line (AFN equivalent) — only shown when sale is NOT in AFN
function fmtSaleSub(float $afn): string {
    global $saleCur;
    if ($saleCur === 'AFN') return '';
    return '≈ ' . formatAFN($afn);
}

$invoiceNo  = $sale['bill_no'] ?: ('INV-' . str_pad($id, 4, '0', STR_PAD_LEFT));
$saleImages = !empty($sale['images']) ? json_decode($sale['images'], true) : [];
if (!is_array($saleImages)) $saleImages = [];

function toShamsiShare(int $gy, int $gm, int $gd): string {
    $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
    if ($gy > 1600) { $jy = 979; $gy -= 1600; } else { $jy = 0; $gy -= 621; }
    $gy2  = $gm > 2 ? $gy + 1 : $gy;
    $days = 365*$gy + intdiv($gy2+3,4) - intdiv($gy2+99,100) + intdiv($gy2+399,400)
            - 80 + $gd + $g_d_m[$gm - 1];
    $jy  += 33 * intdiv($days, 12053); $days %= 12053;
    $jy  +=  4 * intdiv($days,  1461); $days %= 1461;
    if ($days > 365) { $jy += intdiv($days-1, 365); $days = ($days-1) % 365; }
    $jm = $days < 186 ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
    $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);
    return $jy . '/' . str_pad($jm,2,'0',STR_PAD_LEFT) . '/' . str_pad($jd,2,'0',STR_PAD_LEFT);
}

$dateDisplay = '';
if (!empty($sale['sale_date'])) {
    $dp = explode('-', $sale['sale_date']);
    if (count($dp) === 3) {
        $dateDisplay = toShamsiShare((int)$dp[0], (int)$dp[1], (int)$dp[2]);
    }
}
$isFullyPaid = $sale['balance'] <= 0;
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= htmlspecialchars($invoiceNo) ?> — FZL Stocks</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ── Base ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    font-size: 14px;
    color: #1a1a2e;
    background: #eef2f7;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* ── Page wrapper ── */
.page {
    max-width: 800px;
    margin: 2rem auto;
    padding: 0 1rem 5rem;
}

/* ── Invoice card ── */
.invoice {
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 32px rgba(0,0,0,.10), 0 1px 4px rgba(0,0,0,.06);
}

/* ── Top accent bar ── */
.accent-bar {
    height: 6px;
    background: linear-gradient(90deg, #0067C0 0%, #2196F3 100%);
}

/* ── Header: company ↔ invoice number ── */
.inv-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 2rem 2.5rem 1.5rem;
    border-bottom: 1px solid #f0f2f5;
    gap: 1.5rem;
    flex-wrap: wrap;
}
.company-name {
    font-size: 1.5rem;
    font-weight: 700;
    color: #0067C0;
    letter-spacing: -.02em;
    line-height: 1;
}
.company-sub {
    font-size: .8rem;
    color: #888;
    margin-top: .3rem;
}
.inv-meta { text-align: right; }
.inv-title {
    font-size: 1.9rem;
    font-weight: 700;
    color: #1a1a2e;
    letter-spacing: -.03em;
    line-height: 1;
}
.inv-number {
    font-size: .85rem;
    color: #0067C0;
    font-weight: 600;
    margin-top: .35rem;
    letter-spacing: .01em;
}
.inv-date {
    font-size: .78rem;
    color: #999;
    margin-top: .2rem;
}

/* ── Bill-to / status row ── */
.bill-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 1.5rem 2.5rem;
    background: #f8fafc;
    border-bottom: 1px solid #f0f2f5;
    gap: 1rem;
    flex-wrap: wrap;
}
.bill-label {
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: #aaa;
    margin-bottom: .5rem;
}
.bill-name {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1a1a2e;
}
.bill-shop { font-size: .875rem; color: #555; margin-top: .15rem; }
.bill-phone { font-size: .8rem; color: #888; margin-top: .1rem; }

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .45rem 1rem;
    border-radius: 2rem;
    font-size: .8rem;
    font-weight: 700;
    letter-spacing: .03em;
}
.status-paid   { background: #dcfce7; color: #15803d; }
.status-unpaid { background: #fee2e2; color: #b91c1c; }

/* ── Items table ── */
.items-section { padding: 1.75rem 2.5rem 0; }
.section-title {
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: #aaa;
    margin-bottom: .75rem;
}
table { width: 100%; border-collapse: collapse; }
thead tr {
    background: #f1f5f9;
    border-radius: 8px;
}
thead th {
    padding: .65rem .85rem;
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #64748b;
    white-space: nowrap;
}
thead th:first-child { border-radius: 8px 0 0 8px; }
thead th:last-child  { border-radius: 0 8px 8px 0; }
tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .1s; }
tbody tr:last-child { border-bottom: none; }
tbody td {
    padding: .75rem .85rem;
    font-size: .875rem;
    color: #374151;
}
td.num { color: #94a3b8; font-size: .8rem; }
td.product-name { font-weight: 600; color: #1a1a2e; }
td.right { text-align: right; }
td.mono { font-variant-numeric: tabular-nums; }

/* ── Totals ── */
.totals-section {
    display: flex;
    justify-content: flex-end;
    padding: 1.25rem 2.5rem 1.75rem;
}
.totals-box { width: 280px; }
.totals-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: .4rem 0;
    font-size: .875rem;
    color: #555;
    border-bottom: 1px solid #f1f5f9;
}
.totals-row:last-child { border-bottom: none; }
.totals-row.grand {
    padding: .65rem 0 .3rem;
    font-size: 1.05rem;
    font-weight: 700;
    color: #1a1a2e;
    border-top: 2px solid #e2e8f0;
    border-bottom: none;
    margin-top: .25rem;
}
.totals-row .lbl { font-weight: 500; }
.totals-row .val { font-variant-numeric: tabular-nums; font-weight: 600; }
.totals-row .val.paid    { color: #15803d; }
.totals-row .val.balance { color: #b91c1c; }
.totals-row .val.zero    { color: #15803d; }
.sub-val { font-size: .72rem; color: #94a3b8; text-align: right; margin-top: .05rem; }

/* ── Divider ── */
.inv-divider { height: 1px; background: #f0f2f5; margin: 0 2.5rem; }

/* ── Notes ── */
.notes-section {
    padding: 1.25rem 2.5rem;
    border-top: 1px solid #f0f2f5;
}
.notes-text {
    font-size: .875rem;
    color: #555;
    line-height: 1.6;
    margin-top: .4rem;
}

/* ── Images ── */
.images-section {
    padding: 1.25rem 2.5rem;
    border-top: 1px solid #f0f2f5;
}
.image-grid { display: flex; flex-wrap: wrap; gap: .6rem; margin-top: .6rem; }
.image-thumb {
    width: 90px; height: 90px;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
}
.image-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }

/* ── Footer ── */
.inv-footer {
    background: #f8fafc;
    border-top: 1px solid #f0f2f5;
    padding: 1.25rem 2.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: .5rem;
}
.footer-thanks {
    font-size: .8rem;
    color: #64748b;
    font-style: italic;
}
.footer-brand {
    font-size: .75rem;
    color: #94a3b8;
}
.footer-brand span { color: #0067C0; font-weight: 600; }

/* ── Floating action bar (screen only) ── */
.fab-bar {
    position: fixed;
    bottom: 1.25rem;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: .5rem;
    background: rgba(255,255,255,.95);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(0,0,0,.1);
    border-radius: 2rem;
    padding: .45rem .6rem;
    box-shadow: 0 4px 24px rgba(0,0,0,.14);
    z-index: 999;
}
.fab-btn {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    border: none;
    border-radius: 1.5rem;
    padding: .5rem 1.1rem;
    font-size: .82rem;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: opacity .15s, transform .1s;
    text-decoration: none;
}
.fab-btn:active { transform: scale(.96); }
.fab-btn.primary { background: #0067C0; color: #fff; }
.fab-btn.green   { background: #25D366; color: #fff; }
.fab-btn.ghost   { background: #f1f5f9; color: #374151; }

/* ── Toast ── */
#toast {
    position: fixed;
    bottom: 5rem;
    left: 50%;
    transform: translateX(-50%) translateY(12px);
    background: #1a1a2e;
    color: #fff;
    padding: .5rem 1.25rem;
    border-radius: 2rem;
    font-size: .82rem;
    font-weight: 500;
    opacity: 0;
    transition: opacity .2s, transform .2s;
    pointer-events: none;
    white-space: nowrap;
    z-index: 1000;
}
#toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* ── Print ── */
@page { size: A4; margin: 1.5cm 1.8cm; }
@media print {
    body { background: #fff; font-size: 12px; }
    .page { margin: 0; padding: 0; max-width: 100%; }
    .invoice { box-shadow: none; border-radius: 0; border: none; }
    .fab-bar, #toast { display: none !important; }
    .accent-bar { height: 4px; }
    thead tr { background: #f1f5f9 !important; }
    .bill-row { background: #f8fafc !important; }
    .inv-footer { background: #f8fafc !important; }
    a { text-decoration: none; color: inherit; }
}

/* ── Mobile ── */
@media (max-width: 600px) {
    .inv-header, .bill-row, .items-section,
    .totals-section, .notes-section, .images-section,
    .inv-footer, .inv-divider { padding-left: 1.25rem; padding-right: 1.25rem; }
    .inv-meta { text-align: left; }
    .inv-title { font-size: 1.5rem; }
    .totals-box { width: 100%; }
}
</style>
</head>
<body>

<div class="page">
<div class="invoice">

    <!-- Accent bar -->
    <div class="accent-bar"></div>

    <!-- Header: company + invoice ID -->
    <div class="inv-header">
        <div>
            <div class="company-name">FZL Stocks</div>
            <div class="company-sub">fzlstocks.shop</div>
        </div>
        <div class="inv-meta">
            <div class="inv-title">INVOICE</div>
            <div class="inv-number"><?= htmlspecialchars($invoiceNo) ?></div>
            <div class="inv-date">
                <?php if ($dateDisplay): ?>
                    <?= htmlspecialchars($dateDisplay) ?> &nbsp;·&nbsp;
                <?php endif; ?>
                <?= date('d M Y', strtotime($sale['created_at'])) ?>
            </div>
        </div>
    </div>

    <!-- Bill to + status -->
    <div class="bill-row">
        <div>
            <div class="bill-label">Bill To</div>
            <div class="bill-name"><?= htmlspecialchars($sale['customer_name']) ?></div>
            <?php if ($sale['shop_name']): ?>
            <div class="bill-shop"><?= htmlspecialchars($sale['shop_name']) ?></div>
            <?php endif; ?>
            <?php if ($sale['phone']): ?>
            <div class="bill-phone">📞 <?= htmlspecialchars($sale['phone']) ?></div>
            <?php endif; ?>
        </div>
        <div style="text-align:right;">
            <div class="bill-label">Status</div>
            <span class="status-pill <?= $isFullyPaid ? 'status-paid' : 'status-unpaid' ?>">
                <?= $isFullyPaid ? '✓ Fully Paid' : '⚠ Balance Due' ?>
            </span>
        </div>
    </div>

    <!-- Items -->
    <div class="items-section">
        <div class="section-title">Items</div>
        <table>
            <thead>
                <tr>
                    <th style="width:2rem;">#</th>
                    <th>Product</th>
                    <th>Size</th>
                    <th>Color</th>
                    <th style="text-align:right;">Qty</th>
                    <th style="text-align:right;">Unit Price</th>
                    <th style="text-align:right;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $i => $item): ?>
                <tr>
                    <td class="num"><?= $i + 1 ?></td>
                    <td class="product-name"><?= htmlspecialchars($item['product_name']) ?></td>
                    <td><?= htmlspecialchars($item['size'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($item['color'] ?: '—') ?></td>
                    <td class="right"><?php $q=(float)$item['quantity']; echo ($q==floor($q))?number_format($q,0):rtrim(rtrim(number_format($q,3,'.',''),'0'),'.'); ?></td>
                    <td class="right mono">
                        <?= fmtSale((float)$item['unit_price']) ?>
                        <?php $sub1=fmtSaleSub((float)$item['unit_price']); if($sub1): ?><div class="sub-val"><?= $sub1 ?></div><?php endif; ?>
                    </td>
                    <td class="right mono" style="font-weight:600;">
                        <?= fmtSale((float)$item['subtotal']) ?>
                        <?php $sub2=fmtSaleSub((float)$item['subtotal']); if($sub2): ?><div class="sub-val"><?= $sub2 ?></div><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Totals -->
    <div class="totals-section">
        <div class="totals-box">
            <div class="totals-row">
                <span class="lbl">Subtotal</span>
                <div>
                    <div class="val"><?= fmtSale((float)$sale['total_amount']) ?></div>
                    <?php $s1=fmtSaleSub((float)$sale['total_amount']); if($s1): ?><div class="sub-val"><?= $s1 ?></div><?php endif; ?>
                </div>
            </div>
            <div class="totals-row">
                <span class="lbl">Paid</span>
                <div>
                    <div class="val paid"><?= fmtSale((float)$sale['paid_amount']) ?></div>
                    <?php $s2=fmtSaleSub((float)$sale['paid_amount']); if($s2): ?><div class="sub-val"><?= $s2 ?></div><?php endif; ?>
                </div>
            </div>
            <div class="totals-row grand">
                <span>Balance Due</span>
                <div>
                    <div class="val <?= $isFullyPaid ? 'zero' : 'balance' ?>">
                        <?= fmtSale(max(0, (float)$sale['balance'])) ?>
                    </div>
                    <?php if (!$isFullyPaid): $s3=fmtSaleSub((float)$sale['balance']); if($s3): ?><div class="sub-val"><?= $s3 ?></div><?php endif; endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($sale['notes']): ?>
    <div class="notes-section">
        <div class="section-title">Notes</div>
        <div class="notes-text"><?= nl2br(htmlspecialchars($sale['notes'])) ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($saleImages)): ?>
    <div class="images-section">
        <div class="section-title">Attachments</div>
        <div class="image-grid">
            <?php foreach ($saleImages as $img): ?>
            <?php $imgUrl = '/uploads/sale-images/' . htmlspecialchars($img); ?>
            <a href="<?= $imgUrl ?>" target="_blank" rel="noopener" class="image-thumb">
                <img src="<?= $imgUrl ?>" alt="Attachment" onerror="this.parentElement.style.opacity='.3'">
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="inv-footer">
        <div class="footer-thanks">Thank you for your business!</div>
        <div class="footer-brand">Generated by <span>FZL Stocks</span> &nbsp;·&nbsp; fzlstocks.shop</div>
    </div>

</div><!-- /.invoice -->
</div><!-- /.page -->

<!-- Floating action bar -->
<?php
$waUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/sales/share.php?id=' . $id;
$waMsg = "🧾 Invoice {$invoiceNo}\n"
       . "👤 " . $sale['customer_name'] . ($sale['shop_name'] ? " — " . $sale['shop_name'] : '') . "\n"
       . "💰 Total: " . formatAFN($sale['total_amount']) . "\n"
       . ($isFullyPaid ? "✅ Fully paid\n" : "⚠️ Balance: " . formatAFN($sale['balance']) . "\n")
       . "🔗 " . $waUrl;
?>
<div class="fab-bar">
    <button class="fab-btn ghost" onclick="window.print()">🖨 Print / PDF</button>
    <a class="fab-btn green" href="https://wa.me/?text=<?= urlencode($waMsg) ?>" target="_blank" rel="noopener">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.124.558 4.117 1.534 5.836L.054 23.447a.5.5 0 0 0 .615.612l5.701-1.497A11.95 11.95 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.803 9.803 0 0 1-5.001-1.366l-.359-.214-3.721.976.993-3.63-.234-.374A9.818 9.818 0 0 1 2.182 12C2.182 6.57 6.57 2.182 12 2.182S21.818 6.57 21.818 12 17.43 21.818 12 21.818z"/></svg>
        WhatsApp
    </a>
    <button class="fab-btn primary" onclick="copyLink(this)">🔗 Copy Link</button>
</div>

<div id="toast">✓ Link copied to clipboard</div>

<script>
function copyLink(btn) {
    navigator.clipboard.writeText(<?= json_encode($waUrl) ?>).then(() => {
        const t = document.getElementById('toast');
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 2200);
    });
}
</script>

</body>
</html>
