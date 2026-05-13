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
    SELECT si.*, COALESCE(p.name, si.custom_name, 'Custom Item') AS product_name, p.size, p.color
    FROM sale_items si LEFT JOIN products p ON p.id = si.product_id
    WHERE si.sale_id = ?
");
$items->execute([$id]);
$items = $items->fetchAll();

$allRates    = getAllRates($pdo);
$saleCur     = $sale['currency'] ?? 'AFN';
$saleCurRate = $allRates[$saleCur] ?? 1.0;

$pageTitle = '#' . str_pad($id, 4, '0', STR_PAD_LEFT);

$shareUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/sales/share.php?id=' . $id;
$waMsg    = "🧾 Invoice {$pageTitle}\n"
           . "👤 " . $sale['customer_name'] . ($sale['shop_name'] ? " — " . $sale['shop_name'] : '') . "\n"
           . "💰 Total: " . formatAFN($sale['total_amount']) . "\n"
           . ($sale['balance'] > 0 ? "⚠️ Balance due: " . formatAFN($sale['balance']) . "\n" : "✅ Fully paid\n")
           . "🔗 " . $shareUrl;
$waLink   = 'https://wa.me/?text=' . urlencode($waMsg);

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

// Compute the real total from the actual line items (guards against stale sales.total_amount)
$itemsTotal = array_sum(array_column($items, 'subtotal'));

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
    <div class="d-flex gap-2 flex-wrap">
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer me-1"></i><?= __('btn_print') ?>
        </button>

        <div class="dropdown">
            <button class="btn btn-sm btn-primary dropdown-toggle" id="shareDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-share me-1"></i>Share
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="shareDropdown">
                <li>
                    <a class="dropdown-item" href="<?= htmlspecialchars($waLink) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-whatsapp me-2 text-success"></i>Send on WhatsApp
                    </a>
                </li>
                <li>
                    <button class="dropdown-item" onclick="copyShareLink(this)">
                        <i class="bi bi-link-45deg me-2 text-primary"></i>Copy Link
                    </button>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <button class="dropdown-item" onclick="window.print()">
                        <i class="bi bi-file-earmark-pdf me-2 text-danger"></i>Save as PDF
                    </button>
                </li>
            </ul>
        </div>

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
                            <td class="text-end"><?php
                                $q = (float)$item['quantity'];
                                echo ($q == floor($q))
                                    ? number_format($q, 0)
                                    : rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
                            ?></td>
                            <td class="text-end">
                                <?php if ($saleCur !== 'AFN'): ?>
                                    <?= formatMoney(fromAFN((float)$item['unit_price'], $saleCurRate), $saleCur) ?>
                                    <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatAFN($item['unit_price']) ?></div>
                                <?php else: ?>
                                    <?= formatAFN($item['unit_price']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold">
                                <?php if ($saleCur !== 'AFN'): ?>
                                    <?= formatMoney(fromAFN((float)$item['subtotal'], $saleCurRate), $saleCur) ?>
                                    <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatAFN($item['subtotal']) ?></div>
                                <?php else: ?>
                                    <?= formatAFN($item['subtotal']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="6" class="text-end fw-bold"><?= __('field_total') ?></td>
                            <td class="text-end fw-bold fs-5">
                                <?php if ($saleCur !== 'AFN'): ?>
                                    <?= formatMoney(fromAFN($itemsTotal, $saleCurRate), $saleCur) ?>
                                    <div class="fw-normal text-muted" style="font-size:0.75rem;">≈ <?= formatAFN($itemsTotal) ?></div>
                                <?php else: ?>
                                    <?= formatAFN($itemsTotal) ?>
                                <?php endif; ?>
                            </td>
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
                        <?php if ($saleCur !== 'AFN'): ?>
                            <div class="fw-semibold"><?= formatMoney(fromAFN($itemsTotal, $saleCurRate), $saleCur) ?></div>
                            <div class="text-muted small">≈ <?= formatAFN($itemsTotal) ?></div>
                        <?php else: ?>
                            <div class="fw-semibold"><?= formatAFN($itemsTotal) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted"><?= __('sale_paid_at_sale') ?></span>
                    <div class="text-end">
                        <?php if ($saleCur !== 'AFN'): ?>
                            <div class="fw-semibold text-success"><?= formatMoney(fromAFN($sale['paid_amount'], $saleCurRate), $saleCur) ?></div>
                            <div class="text-muted small">≈ <?= formatAFN($sale['paid_amount']) ?></div>
                        <?php else: ?>
                            <div class="fw-semibold text-success"><?= formatAFN($sale['paid_amount']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php $viewBalance = max(0, $itemsTotal - (float)$sale['paid_amount']); ?>
                <hr>
                <div class="d-flex justify-content-between">
                    <span class="fw-semibold"><?= __('sale_balance_due') ?></span>
                    <div class="text-end">
                        <?php if ($saleCur !== 'AFN'): ?>
                            <div class="fw-bold <?= $viewBalance > 0 ? 'text-danger' : 'text-success' ?> fs-5">
                                <?= formatMoney(fromAFN($viewBalance, $saleCurRate), $saleCur) ?>
                            </div>
                            <?php if ($viewBalance > 0): ?>
                            <div class="text-muted small">≈ <?= formatAFN($viewBalance) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="fw-bold <?= $viewBalance > 0 ? 'text-danger' : 'text-success' ?> fs-5">
                                <?= formatAFN($viewBalance) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($viewBalance > 0): ?>
                <div class="mt-3">
                    <a href="/payments/add.php?customer_id=<?= $sale['customer_id'] ?>" class="btn btn-success w-100 btn-sm">
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
                <?php if ($saleCur !== 'AFN'): ?>
                <div class="mt-1">
                    <strong><?= __('sale_rate_at_view') ?>:</strong>
                    1 <?= htmlspecialchars($saleCur) ?> = <?= number_format($saleCurRate, 2) ?> ؋
                </div>
                <?php endif; ?>
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
                    <?php $imgUrl = '/uploads/sale-images/' . htmlspecialchars($img); ?>
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

<div id="copy-toast" style="
    position:fixed; bottom:1.5rem; left:50%; transform:translateX(-50%) translateY(80px);
    background:#1e1e1e; color:#fff; padding:.5rem 1.25rem; border-radius:2rem;
    font-size:.875rem; z-index:9999; opacity:0; transition:all .25s ease; pointer-events:none;">
    <i class="bi bi-check2 me-1"></i> Link copied!
</div>

<style>
@media print {
    .sidebar, .topbar, .btn, .page-header a, .alert, .dropdown { display: none !important; }
    .main-wrap { margin: 0 !important; }
    .content-area { padding: 0 !important; }
}
</style>

<script>
function copyShareLink(btn) {
    const url = <?= json_encode($shareUrl) ?>;
    navigator.clipboard.writeText(url).then(() => {
        const toast = document.getElementById('copy-toast');
        toast.style.opacity = '1';
        toast.style.transform = 'translateX(-50%) translateY(0)';
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(-50%) translateY(80px)';
        }, 2000);
    });
}
</script>

<?php require_once '../includes/footer.php'; ?>
