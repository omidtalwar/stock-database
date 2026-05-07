<?php
require_once '../config/db.php';
require_once '../includes/currency.php';

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
    SELECT si.*, p.name AS product_name, p.size, p.color
    FROM sale_items si JOIN products p ON p.id = si.product_id
    WHERE si.sale_id = ?
");
$stmt2->execute([$id]);
$items = $stmt2->fetchAll();

$settings = getSettings($pdo);
$rate     = (float)($settings['exchange_rate'] ?? 90);
$secCur   = $settings['secondary_currency'] ?? 'USD';

$invoiceNo  = $sale['bill_no'] ?: ('#' . str_pad($id, 4, '0', STR_PAD_LEFT));
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
    if (count($dp) === 3) $dateDisplay = toShamsiShare((int)$dp[0], (int)$dp[1], (int)$dp[2]);
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= htmlspecialchars($invoiceNo) ?> — FZL Stocks</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background: #f5f5f5; font-family: system-ui, -apple-system, sans-serif; }
.invoice-wrap { max-width: 720px; margin: 2rem auto; padding: 0 1rem 3rem; }
.invoice-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 16px rgba(0,0,0,0.08); overflow: hidden; }
.invoice-header { background: #0067C0; color: #fff; padding: 2rem 2rem 1.5rem; }
.invoice-header h2 { font-size: 1.5rem; font-weight: 700; margin: 0; }
.invoice-header .sub { opacity: .8; font-size: .875rem; margin-top: .25rem; }
.invoice-body { padding: 1.75rem 2rem; }
.label { font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #888; font-weight: 600; }
.value { font-size: 1rem; color: #1c1c1c; font-weight: 500; margin-top: .1rem; }
table thead th { font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: #888; font-weight: 600; border-bottom: 2px solid #f0f0f0; padding: .6rem .75rem; }
table tbody td { padding: .65rem .75rem; border-bottom: 1px solid #f7f7f7; font-size: .95rem; }
.total-row td { font-weight: 700; font-size: 1.05rem; border-top: 2px solid #f0f0f0; border-bottom: none; }
.summary-box { background: #f8f9fa; border-radius: 10px; padding: 1.25rem 1.5rem; }
.status-paid   { background: #d1fae5; color: #065f46; }
.status-unpaid { background: #fee2e2; color: #991b1b; }
.status-badge { display: inline-block; padding: .3rem .85rem; border-radius: 2rem; font-size: .8rem; font-weight: 600; }
.print-btn { position: fixed; bottom: 1.5rem; right: 1.5rem; }
@media print {
    body { background: #fff; }
    .invoice-wrap { margin: 0; padding: 0; }
    .invoice-card { box-shadow: none; border-radius: 0; }
    .print-btn { display: none; }
}
@media (max-width: 576px) {
    .invoice-body { padding: 1.25rem 1rem; }
    .invoice-header { padding: 1.5rem 1rem 1rem; }
}
</style>
</head>
<body>

<div class="invoice-wrap">
    <div class="invoice-card">

        <!-- Header -->
        <div class="invoice-header">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h2><i class="bi bi-receipt me-2"></i>Invoice <?= htmlspecialchars($invoiceNo) ?></h2>
                    <div class="sub">
                        <?= $dateDisplay ?: date('d M Y', strtotime($sale['created_at'])) ?>
                    </div>
                </div>
                <div class="text-end">
                    <span class="status-badge <?= $sale['balance'] > 0 ? 'status-unpaid' : 'status-paid' ?>">
                        <?= $sale['balance'] > 0 ? 'Balance Due' : '✓ Fully Paid' ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="invoice-body">

            <!-- Customer -->
            <div class="mb-4">
                <div class="label">Billed To</div>
                <div class="value fw-bold fs-5 mt-1"><?= htmlspecialchars($sale['customer_name']) ?></div>
                <?php if ($sale['shop_name']): ?>
                <div class="text-muted"><?= htmlspecialchars($sale['shop_name']) ?></div>
                <?php endif; ?>
                <?php if ($sale['phone']): ?>
                <div class="text-muted small"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($sale['phone']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Items -->
            <div class="table-responsive mb-4">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th>Size</th>
                            <th>Color</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $i => $item): ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($item['product_name']) ?></td>
                            <td><?= htmlspecialchars($item['size'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($item['color'] ?: '—') ?></td>
                            <td class="text-end"><?= $item['quantity'] ?></td>
                            <td class="text-end"><?= formatAFN($item['unit_price']) ?></td>
                            <td class="text-end"><?= formatAFN($item['subtotal']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td colspan="6" class="text-end">Total</td>
                            <td class="text-end"><?= formatAFN($sale['total_amount']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Payment Summary -->
            <div class="summary-box">
                <div class="row g-3">
                    <div class="col-4 text-center">
                        <div class="label">Total</div>
                        <div class="value"><?= formatAFN($sale['total_amount']) ?></div>
                        <div class="text-muted" style="font-size:.75rem;">≈ <?= formatMoney(fromAFN($sale['total_amount'], $rate), $secCur) ?></div>
                    </div>
                    <div class="col-4 text-center border-start border-end">
                        <div class="label">Paid</div>
                        <div class="value text-success"><?= formatAFN($sale['paid_amount']) ?></div>
                        <div class="text-muted" style="font-size:.75rem;">≈ <?= formatMoney(fromAFN($sale['paid_amount'], $rate), $secCur) ?></div>
                    </div>
                    <div class="col-4 text-center">
                        <div class="label">Balance</div>
                        <div class="value <?= $sale['balance'] > 0 ? 'text-danger' : 'text-success' ?> fw-bold fs-5">
                            <?= formatAFN($sale['balance']) ?>
                        </div>
                        <?php if ($sale['balance'] > 0): ?>
                        <div class="text-muted" style="font-size:.75rem;">≈ <?= formatMoney(fromAFN($sale['balance'], $rate), $secCur) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($sale['notes']): ?>
            <div class="mt-4">
                <div class="label">Notes</div>
                <div class="value mt-1"><?= nl2br(htmlspecialchars($sale['notes'])) ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($saleImages)): ?>
            <div class="mt-4">
                <div class="label mb-2">Attachments</div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($saleImages as $img): ?>
                    <?php $imgUrl = '/uploads/sale-images/' . htmlspecialchars($img); ?>
                    <a href="<?= $imgUrl ?>" target="_blank" rel="noopener"
                       style="display:block;width:96px;height:96px;border-radius:8px;overflow:hidden;border:1px solid #e0e0e0;">
                        <img src="<?= $imgUrl ?>" alt="" style="width:100%;height:100%;object-fit:cover;"
                             onerror="this.parentElement.style.opacity='.3'">
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.invoice-body -->
    </div><!-- /.invoice-card -->

    <div class="text-center text-muted mt-3" style="font-size:.78rem;">
        FZL Stocks &nbsp;·&nbsp; fzlstocks.shop
    </div>
</div>

<!-- Floating print button -->
<button onclick="window.print()" class="print-btn btn btn-primary btn-sm shadow">
    <i class="bi bi-printer me-1"></i>Print / Save PDF
</button>

</body>
</html>
