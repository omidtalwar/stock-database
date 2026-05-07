<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';
require_once '../includes/currency.php';

$id = (int)($_GET['id'] ?? 0);

$sale = $pdo->prepare("
    SELECT s.*, c.name AS customer_name, c.shop_name, c.phone
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    WHERE s.id = ?
");
$sale->execute([$id]);
$sale = $sale->fetch();
if (!$sale) { header('Location: index.php'); exit; }

$items = $pdo->prepare("
    SELECT si.*, p.name AS product_name, p.size, p.color
    FROM sale_items si JOIN products p ON p.id = si.product_id
    WHERE si.sale_id = ?
");
$items->execute([$id]);
$items = $items->fetchAll();

$settings = getSettings($pdo);

$shopNameDari = $settings['shop_name_dari']      ?? 'دوکان رخت فروشی FZL';
$shopNameEn   = $settings['shop_name_en']        ?? 'FZL Cloth House';
$shopPhone1   = $settings['shop_phone1']         ?? '';
$shopPhone2   = $settings['shop_phone2']         ?? '';
$shopManager  = $settings['shop_manager']        ?? '';
$shopAddress  = $settings['shop_address']        ?? '';
$footerNote   = $settings['invoice_footer_note'] ?? 'سوت خرچ شوی مال نه واپس کیری';

$invoiceNum  = str_pad($id, 4, '0', STR_PAD_LEFT);
$invoiceDate = date('Y/m/d', strtotime($sale['created_at']));
$minRows     = 14;
$emptyRows   = max(0, $minRows - count($items));
?>
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice #<?= $invoiceNum ?> — <?= htmlspecialchars($sale['customer_name']) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Noto+Naskh+Arabic:wght@400;700&display=swap');

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Noto Naskh Arabic', 'Segoe UI', 'Arial', sans-serif;
    direction: rtl;
    background: #e8e8e8;
    color: #111;
    font-size: 13px;
}

.page-wrap {
    width: 210mm;
    min-height: 297mm;
    margin: 12px auto;
    background: white;
    padding: 8mm 7mm 7mm;
    box-shadow: 0 4px 24px rgba(0,0,0,0.18);
    display: flex;
    flex-direction: column;
}

/* ── Print button bar ── */
.print-bar {
    text-align: center;
    padding: 8px 0 14px;
    font-family: Arial, sans-serif;
}
.print-bar button {
    background: #1563a8; color: white; border: none;
    padding: 8px 26px; border-radius: 6px; font-size: 14px; cursor: pointer;
}
.print-bar button:hover { background: #0d4a85; }
.print-bar a { margin-right: 14px; color: #555; font-size: 13px; text-decoration: none; }
.print-bar a:hover { color: #1563a8; }

/* ── Header ── */
.inv-header {
    background: linear-gradient(135deg, #1563a8 0%, #0a3d6e 60%, #1a3f70 100%);
    border-radius: 8px 8px 0 0;
    padding: 10px 14px 0;
    display: flex;
    align-items: stretch;
    gap: 0;
    overflow: hidden;
    min-height: 88px;
}

.inv-header-contact {
    color: rgba(255,255,255,0.92);
    font-size: 10.5px;
    min-width: 145px;
    padding-bottom: 8px;
}
.inv-header-contact .phones {
    font-size: 12.5px;
    font-weight: bold;
    color: white;
    font-family: Arial, sans-serif;
    direction: ltr;
    text-align: right;
    margin-top: 2px;
}
.inv-header-contact .contact-label {
    color: rgba(255,255,255,0.65);
    font-size: 10px;
}
.inv-header-contact .manager-line {
    margin-top: 4px;
}

.inv-header-center {
    flex: 1;
    text-align: center;
    padding-bottom: 8px;
}
.shop-name-dari {
    font-size: 22px;
    font-weight: 900;
    color: #ff3838;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.5), 0 0 10px rgba(255,180,0,0.25);
    line-height: 1.3;
    letter-spacing: 0.5px;
}
.shop-name-en {
    color: white;
    font-size: 12.5px;
    font-family: Arial, sans-serif;
    font-weight: bold;
    margin-top: 3px;
    letter-spacing: 0.3px;
}
.shop-address {
    background: rgba(0,0,0,0.28);
    padding: 3px 12px;
    font-size: 9.5px;
    color: rgba(255,255,255,0.82);
    margin-top: 6px;
    border-radius: 2px;
}

.inv-header-spacer {
    min-width: 145px;
}

/* ── Invoice meta ── */
.inv-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border: 1.5px solid #aaa;
    border-top: none;
    padding: 5px 14px;
    background: #f5f5f5;
    font-size: 12.5px;
    gap: 8px;
}
.inv-meta-item { display: flex; align-items: center; gap: 5px; }
.inv-meta-label { color: #555; font-size: 11px; }
.inv-meta-val {
    font-weight: bold;
    border-bottom: 1px solid #888;
    min-width: 50px;
    padding-bottom: 1px;
}
.inv-meta-val.wide { min-width: 160px; }

/* ── Table ── */
.inv-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
    border: 1.5px solid #aaa;
    border-top: none;
    flex: 1;
}
.inv-table thead tr {
    background: #c82000;
}
.inv-table thead th {
    color: white;
    padding: 7px 6px;
    text-align: center;
    border: 1px solid #a81a00;
    font-weight: bold;
    font-size: 13px;
}
.inv-table tbody tr:nth-child(odd)  { background: #fff; }
.inv-table tbody tr:nth-child(even) { background: #f5c8a0; }
.inv-table tbody td {
    padding: 5px 7px;
    border: 1px solid #ccc;
    text-align: center;
    height: 28px;
    vertical-align: middle;
}
.inv-table tbody td.name-col { text-align: right; padding-right: 10px; }

/* ── Totals ── */
.inv-totals {
    border: 1.5px solid #aaa;
    border-top: none;
    padding: 5px 14px;
    background: #f5f5f5;
    display: flex;
    gap: 28px;
    font-size: 12.5px;
    justify-content: flex-start;
}
.tot-item { display: flex; gap: 6px; align-items: center; }
.tot-label { color: #444; }
.tot-val { font-weight: bold; font-size: 14px; }
.tot-val.red { color: #c82000; }
.tot-val.green { color: #107c10; }

/* ── Notes row ── */
.inv-notes {
    border: 1.5px solid #aaa;
    border-top: none;
    padding: 4px 14px;
    font-size: 11.5px;
    color: #444;
    min-height: 22px;
    background: #fafafa;
}

/* ── Footer ── */
.inv-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 10px;
    gap: 16px;
}
.signature-area {
    flex: 1;
    font-size: 12.5px;
    color: #333;
    border-top: 1px solid #888;
    padding-top: 4px;
    padding-right: 4px;
}
.return-note {
    background: #c82000;
    color: white;
    padding: 7px 20px;
    border-radius: 30px;
    font-size: 12px;
    font-weight: bold;
    white-space: nowrap;
    text-align: center;
}

/* ── Print media ── */
@media print {
    @page { size: A4 portrait; margin: 8mm; }
    body { background: white; }
    .page-wrap { width: auto; margin: 0; padding: 0; box-shadow: none; }
    .print-bar { display: none !important; }
    * { print-color-adjust: exact !important; -webkit-print-color-adjust: exact !important; }
}

@media screen {
    .page-wrap { margin-bottom: 30px; }
}
</style>
</head>
<body>

<!-- Print controls (screen only) -->
<div class="print-bar">
    <button onclick="window.print()">🖨️ Print Invoice</button>
    <a href="view.php?id=<?= $id ?>">← Back to Invoice</a>
</div>

<div class="page-wrap">

    <!-- ══ Header ══ -->
    <div class="inv-header">
        <!-- Contact info -->
        <div class="inv-header-contact">
            <?php if ($shopPhone1 || $shopPhone2): ?>
            <div class="contact-label">شماره های تماس:</div>
            <div class="phones">
                <?= htmlspecialchars($shopPhone1) ?>
                <?php if ($shopPhone1 && $shopPhone2): ?> - <?php endif; ?>
                <?= htmlspecialchars($shopPhone2) ?>
            </div>
            <?php endif; ?>
            <?php if ($shopManager): ?>
            <div class="manager-line">مسؤل: <strong><?= htmlspecialchars($shopManager) ?></strong></div>
            <?php endif; ?>
        </div>

        <!-- Shop name -->
        <div class="inv-header-center">
            <div class="shop-name-dari"><?= htmlspecialchars($shopNameDari) ?></div>
            <div class="shop-name-en"><?= htmlspecialchars($shopNameEn) ?></div>
            <?php if ($shopAddress): ?>
            <div class="shop-address"><?= htmlspecialchars($shopAddress) ?></div>
            <?php endif; ?>
        </div>

        <div class="inv-header-spacer"></div>
    </div>

    <!-- ══ Invoice meta row ══ -->
    <div class="inv-meta">
        <div class="inv-meta-item">
            <span class="inv-meta-label">شماره:</span>
            <span class="inv-meta-val"><?= $invoiceNum ?></span>
        </div>
        <div class="inv-meta-item">
            <span class="inv-meta-label">اسم مشتری:</span>
            <span class="inv-meta-val wide"><?= htmlspecialchars($sale['customer_name']) ?><?php if ($sale['shop_name']): ?> — <?= htmlspecialchars($sale['shop_name']) ?><?php endif; ?></span>
        </div>
        <div class="inv-meta-item">
            <span class="inv-meta-label">تاریخ:</span>
            <span class="inv-meta-val"><?= $invoiceDate ?></span>
        </div>
    </div>

    <!-- ══ Items table ══ -->
    <table class="inv-table">
        <thead>
            <tr>
                <th style="width:40px;">شماره</th>
                <th>نوع جنس</th>
                <th style="width:75px;">گزانه</th>
                <th style="width:90px;">نرخ (؋)</th>
                <th style="width:100px;">جمله (؋)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $item):
                $label = $item['product_name'];
                if ($item['size'])  $label .= ' (' . $item['size'] . ')';
                if ($item['color']) $label .= ' — ' . $item['color'];
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td class="name-col"><?= htmlspecialchars($label) ?></td>
                <td><?= number_format($item['quantity']) ?></td>
                <td><?= number_format($item['unit_price'], 0) ?></td>
                <td><?= number_format($item['subtotal'], 0) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php for ($e = 0; $e < $emptyRows; $e++): ?>
            <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <!-- ══ Totals ══ -->
    <div class="inv-totals">
        <div class="tot-item">
            <span class="tot-label">ټول:</span>
            <span class="tot-val">؋ <?= number_format($sale['total_amount'], 0) ?></span>
        </div>
        <?php if ($sale['paid_amount'] > 0): ?>
        <div class="tot-item">
            <span class="tot-label">ورکړل شوی:</span>
            <span class="tot-val green">؋ <?= number_format($sale['paid_amount'], 0) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($sale['balance'] > 0): ?>
        <div class="tot-item">
            <span class="tot-label">پاتې پور:</span>
            <span class="tot-val red">؋ <?= number_format($sale['balance'], 0) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ Notes ══ -->
    <?php if ($sale['notes']): ?>
    <div class="inv-notes">یادښت: <?= htmlspecialchars($sale['notes']) ?></div>
    <?php else: ?>
    <div class="inv-notes"></div>
    <?php endif; ?>

    <!-- ══ Footer ══ -->
    <div class="inv-footer">
        <div class="signature-area">امضاء: ........................................</div>
        <?php if ($footerNote): ?>
        <div class="return-note"><?= htmlspecialchars($footerNote) ?></div>
        <?php endif; ?>
    </div>

</div><!-- /.page-wrap -->

</body>
</html>
