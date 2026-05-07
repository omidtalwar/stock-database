<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$id = (int)($_GET['id'] ?? 0);
$sale = $pdo->prepare("
    SELECT s.*, c.name AS customer_name, c.shop_name, c.phone, c.id AS customer_id,
           u.full_name AS created_by_name
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    JOIN users u ON u.id = s.created_by
    WHERE s.id = ?
");
$sale->execute([$id]);
$sale = $sale->fetch();
if (!$sale) { $_SESSION['error'] = 'Invoice not found.'; header('Location: index.php'); exit; }

$items = $pdo->prepare("
    SELECT si.*, p.name AS product_name, p.size, p.color
    FROM sale_items si JOIN products p ON p.id = si.product_id
    WHERE si.sale_id = ?
");
$items->execute([$id]);
$items = $items->fetchAll();

$settings = getSettings($pdo);
$rate     = (float)($settings['exchange_rate'] ?? 90);
$secCur   = $settings['secondary_currency'] ?? 'USD';

$pageTitle = '#' . str_pad($id, 4, '0', STR_PAD_LEFT);

function toShamsi(int $gy, int $gm, int $gd): array {
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
    return ['y' => $jy, 'm' => $jm, 'd' => $jd];
}

$saleImages = !empty($sale['images']) ? json_decode($sale['images'], true) : [];
if (!is_array($saleImages)) $saleImages = [];

$saleDateShamsi = null;
if (!empty($sale['sale_date'])) {
    $dp = explode('-', $sale['sale_date']);
    if (count($dp) === 3) $saleDateShamsi = toShamsi((int)$dp[0], (int)$dp[1], (int)$dp[2]);
}

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-start justify-content-between">
    <div>
        <a href="index.php" class="text-muted small">
            <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_sales') ?>
        </a>
        <h4 class="mt-1 mb-0"><?= __('sale_title') ?> #<?= str_pad($id, 4, '0', STR_PAD_LEFT) ?></h4>
        <span class="text-muted small"><?= date('d F Y, h:i A', strtotime($sale['created_at'])) ?></span>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer me-1"></i><?= __('btn_print') ?>
        </button>
        <?php if (isAdmin()): ?>
        <a href="delete.php?id=<?= $id ?>" class="btn btn-sm btn-outline-danger"
           onclick="return confirm('<?= htmlspecialchars(addslashes(__('sale_del_confirm'))) ?>')">
            <i class="bi bi-trash me-1"></i><?= __('btn_delete') ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold"><?= __('sale_billed_to') ?></div>
            <div class="card-body">
                <div class="fw-bold fs-5"><?= htmlspecialchars($sale['customer_name']) ?></div>
                <div class="text-muted"><?= htmlspecialchars($sale['shop_name']) ?></div>
                <?php if ($sale['phone']): ?><div class="text-muted small"><?= htmlspecialchars($sale['phone']) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header fw-semibold"><?= __('sale_items') ?></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= __('prod_name') ?></th>
                            <th><?= __('field_size') ?></th>
                            <th><?= __('field_color') ?></th>
                            <th class="text-end"><?= __('field_quantity') ?></th>
                            <th class="text-end"><?= __('field_price') ?></th>
                            <th class="text-end"><?= __('field_total') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $i => $item): ?>
                        <tr>
                            <td class="text-muted small"><?= $i+1 ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($item['product_name']) ?></td>
                            <td><?= htmlspecialchars($item['size'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($item['color'] ?: '—') ?></td>
                            <td class="text-end"><?= $item['quantity'] ?></td>
                            <td class="text-end"><?= formatAFN($item['unit_price']) ?></td>
                            <td class="text-end fw-semibold"><?= formatAFN($item['subtotal']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="6" class="text-end fw-bold"><?= __('field_total') ?></td>
                            <td class="text-end fw-bold fs-5"><?= formatAFN($sale['total_amount']) ?></td>
                        </tr>
                        <tr class="text-muted">
                            <td colspan="6" class="text-end small">≈ <?= htmlspecialchars($secCur) ?></td>
                            <td class="text-end small"><?= formatMoney(fromAFN($sale['total_amount'], $rate), $secCur) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold"><?= __('sale_pay_summary') ?></div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted"><?= __('field_total') ?></span>
                    <div class="text-end">
                        <div class="fw-semibold"><?= formatAFN($sale['total_amount']) ?></div>
                        <div class="text-muted small">≈ <?= formatMoney(fromAFN($sale['total_amount'], $rate), $secCur) ?></div>
                    </div>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted"><?= __('sale_paid_at_sale') ?></span>
                    <div class="text-end">
                        <div class="fw-semibold text-success"><?= formatAFN($sale['paid_amount']) ?></div>
                        <div class="text-muted small">≈ <?= formatMoney(fromAFN($sale['paid_amount'], $rate), $secCur) ?></div>
                    </div>
                </div>
                <hr>
                <div class="d-flex justify-content-between">
                    <span class="fw-semibold"><?= __('sale_balance_due') ?></span>
                    <div class="text-end">
                        <div class="fw-bold <?= $sale['balance'] > 0 ? 'text-danger' : 'text-success' ?> fs-5">
                            <?= formatAFN($sale['balance']) ?>
                        </div>
                        <?php if ($sale['balance'] > 0): ?>
                        <div class="text-muted small">≈ <?= formatMoney(fromAFN($sale['balance'], $rate), $secCur) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($sale['balance'] > 0): ?>
                <div class="mt-3">
                    <a href="/fzl/payments/add.php?customer_id=<?= $sale['customer_id'] ?>" class="btn btn-success w-100 btn-sm">
                        <i class="bi bi-cash me-2"></i><?= __('pay_add') ?>
                    </a>
                </div>
                <?php else: ?>
                <div class="alert alert-success py-2 mt-3 mb-0 text-center small">
                    <i class="bi bi-check-circle me-1"></i><?= __('sale_fully_paid') ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body small text-muted">
                <?php if (!empty($sale['bill_no'])): ?>
                <div><strong>Bill No:</strong>
                    <span class="fw-semibold text-dark"><?= htmlspecialchars($sale['bill_no']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($saleDateShamsi): ?>
                <div><strong>Sale Date:</strong>
                    <span class="fw-semibold text-dark">
                        <?= $saleDateShamsi['y'] ?>/<?= str_pad($saleDateShamsi['m'],2,'0',STR_PAD_LEFT) ?>/<?= str_pad($saleDateShamsi['d'],2,'0',STR_PAD_LEFT) ?>
                    </span>
                    <span class="text-muted ms-1" style="font-size:0.7rem;">(Solar Hijri)</span>
                </div>
                <?php endif; ?>
                <div class="<?= (!empty($sale['bill_no']) || $saleDateShamsi) ? 'mt-2' : '' ?>">
                    <strong><?= __('sale_created_by') ?>:</strong> <?= htmlspecialchars($sale['created_by_name']) ?>
                </div>
                <div><strong><?= __('field_date') ?>:</strong> <?= date('d M Y H:i', strtotime($sale['created_at'])) ?></div>
                <div class="mt-1">
                    <strong><?= __('sale_rate_at_view') ?>:</strong> 1 <?= htmlspecialchars($secCur) ?> = <?= number_format($rate, 2) ?> ؋
                </div>
                <?php if ($sale['notes']): ?>
                <div class="mt-2"><strong><?= __('field_notes') ?>:</strong> <?= htmlspecialchars($sale['notes']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($saleImages)): ?>
        <div class="card mt-3">
            <div class="card-header fw-semibold d-flex align-items-center gap-2">
                <i class="bi bi-images" style="color:#0067C0;"></i>
                Attached Images
                <span class="badge bg-secondary ms-auto"><?= count($saleImages) ?></span>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($saleImages as $img): ?>
                    <?php $imgUrl = '/fzl/uploads/sale-images/' . htmlspecialchars($img); ?>
                    <a href="<?= $imgUrl ?>" target="_blank" rel="noopener"
                       style="display:block;width:96px;height:96px;border-radius:8px;overflow:hidden;border:1px solid rgba(0,0,0,0.1);background:#f2f2f2;">
                        <img src="<?= $imgUrl ?>" alt="Sale image"
                             style="width:100%;height:100%;object-fit:cover;display:block;"
                             onerror="this.parentElement.style.opacity='0.4'">
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<style>
@media print {
    .sidebar, .topbar, .btn, .page-header a, .alert { display: none !important; }
    .main-wrap { margin: 0 !important; }
    .content-area { padding: 0 !important; }
}
</style>

<?php require_once '../includes/footer.php'; ?>
