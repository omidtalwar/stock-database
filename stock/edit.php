<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../includes/lang.php';
require_once '../config/db.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT sl.*,
           COALESCE(p.name, sl.custom_product) AS product_label,
           p.quantity AS current_stock
    FROM stock_logs sl
    LEFT JOIN products p ON p.id = sl.product_id
    WHERE sl.id = ?
");
$stmt->execute([$id]);
$log = $stmt->fetch();
if (!$log) { $_SESSION['error'] = 'Record not found.'; header('Location: index.php'); exit; }

$products = $pdo->query("SELECT id, name, size, color, quantity FROM products ORDER BY name ASC")->fetchAll();
$prodJs   = array_map(fn($p) => [
    'id'    => $p['id'],
    'label' => $p['name'].($p['size'] ? ' ('.$p['size'].')' : '').($p['color'] ? ' - '.$p['color'] : ''),
    'stock' => (int)$p['quantity'],
], $products);

$pageTitle = 'Edit Stock Record';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid         = (int)($_POST['product_id']    ?? 0);
    $customProd  = trim($_POST['custom_product'] ?? '');
    $type        = in_array($_POST['type'] ?? '', ['in','out']) ? $_POST['type'] : 'in';
    $qty         = (int)($_POST['quantity']      ?? 0);
    $bundleCount = (int)($_POST['bundle_count']  ?? 0);
    $pricingType = in_array($_POST['pricing_type'] ?? '', ['per_pcs','per_bundle']) ? $_POST['pricing_type'] : 'per_pcs';
    $unitPrice   = (float)($_POST['unit_price']  ?? 0);
    $totalAmount = (float)($_POST['total_amount'] ?? 0);
    $paidAmount  = (float)($_POST['paid_amount']  ?? 0);
    $balance     = max(0, $totalAmount - $paidAmount);
    $notes       = trim($_POST['notes']          ?? '');
    $supplier    = trim($_POST['supplier']       ?? '');
    $billImage   = trim($_POST['bill_image']      ?? $log['bill_image'] ?? '');

    $errors = [];
    if ($qty <= 0) $errors[] = 'Quantity must be greater than 0.';
    if (!$pid && !$customProd) $errors[] = 'Please select or enter a product name.';

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            // Reverse original product quantity change (only for tracked products)
            if ($log['product_id']) {
                $reverseOp = $log['type'] === 'in' ? 'quantity - ?' : 'quantity + ?';
                $pdo->prepare("UPDATE products SET quantity = $reverseOp WHERE id = ?")
                    ->execute([(int)$log['quantity'], (int)$log['product_id']]);
            }

            // Apply new product quantity change
            if ($pid) {
                $currStock = (int)$pdo->query("SELECT quantity FROM products WHERE id=$pid")->fetchColumn();
                if ($type === 'out' && $qty > $currStock) {
                    throw new \Exception("Not enough stock. Available: $currStock pcs.");
                }
                $applyOp = $type === 'in' ? 'quantity + ?' : 'quantity - ?';
                $pdo->prepare("UPDATE products SET quantity = $applyOp WHERE id = ?")
                    ->execute([$qty, $pid]);
            }

            $pdo->prepare("
                UPDATE stock_logs SET
                    product_id = ?, custom_product = ?, type = ?, quantity = ?,
                    bundle_count = ?, pricing_type = ?, unit_price = ?,
                    total_amount = ?, paid_amount = ?, balance = ?,
                    supplier = ?, notes = ?, bill_image = ?
                WHERE id = ?
            ")->execute([
                $pid ?: null, $customProd ?: null, $type, $qty,
                $bundleCount ?: null, $pricingType, $unitPrice,
                $totalAmount, $paidAmount, $balance,
                $supplier ?: null, $notes ?: null, $billImage ?: null,
                $id,
            ]);

            $pdo->commit();
            $_SESSION['success'] = 'Stock record updated.';
            header('Location: index.php');
            exit;
        } catch (\Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
            $_SESSION['error'] = implode('<br>', $errors);
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errors);
    }
}

// Field helpers — prefer POST on re-submit, otherwise original log value
function fv($key, $log) {
    return htmlspecialchars($_POST[$key] ?? $log[$key] ?? '');
}
function fcheck($key, $val, $log) {
    $v = $_POST[$key] ?? $log[$key] ?? '';
    return $v === $val ? 'checked' : '';
}
function fsel($key, $val, $log) {
    $v = $_POST[$key] ?? $log[$key] ?? '';
    return $v === $val ? 'selected' : '';
}

require_once '../includes/header.php';

// Build initial product input value
$initProdLabel = '';
if (!empty($_POST['custom_product'])) {
    $initProdLabel = $_POST['custom_product'];
} elseif ($log['product_id']) {
    foreach ($prodJs as $p) {
        if ($p['id'] == $log['product_id']) { $initProdLabel = $p['label']; break; }
    }
} elseif ($log['custom_product']) {
    $initProdLabel = $log['custom_product'];
}
$initProdId = $_POST['product_id'] ?? ($log['product_id'] ?? '');
?>

<style>
.bill-drop-zone {
    border: 2px dashed rgba(0,103,192,0.35); border-radius: 10px;
    padding: 22px 12px; text-align: center; cursor: pointer;
    transition: border-color .2s, background .2s;
    background: rgba(0,103,192,0.02); font-size: 0.83rem; user-select: none;
}
.bill-drop-zone:hover, .bill-drop-zone.drag-over { border-color:#0067C0; background:rgba(0,103,192,0.07); }
.bill-drop-zone.has-file { border-style: solid; border-color:rgba(16,124,16,0.4); background:rgba(16,124,16,0.04); }
.bill-thumb { width:100%; max-height:160px; object-fit:contain; border-radius:8px; margin-top:10px; display:none; }
.calc-field {
    background: rgba(0,103,192,0.04) !important;
    border-color: rgba(0,103,192,0.2) !important;
    color: var(--w11-blue) !important;
    font-weight: 700 !important;
}
</style>

<div class="page-header">
    <a href="index.php" class="text-muted small">
        <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_stock') ?>
    </a>
    <h4 class="mt-1 mb-0">Edit Stock Record <span class="text-muted fw-normal fs-6">#<?= $id ?></span></h4>
</div>

<form method="POST" id="stockForm">
<div class="row g-3">

    <!-- ── Left col ── -->
    <div class="col-lg-8">

        <!-- Stock Details -->
        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex align-items-center gap-2">
                <i class="bi bi-archive" style="color:#0067C0;"></i> Stock Details
            </div>
            <div class="card-body">

                <!-- Product -->
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= __('nav_products') ?></label>
                    <input list="prod-list"
                           name="custom_product"
                           id="prodInput"
                           class="form-control"
                           placeholder="Select from list or type a custom product…"
                           autocomplete="off"
                           oninput="matchProd(this)"
                           value="<?= htmlspecialchars($initProdLabel) ?>">
                    <datalist id="prod-list">
                        <?php foreach ($prodJs as $p): ?>
                        <option value="<?= htmlspecialchars($p['label']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="product_id" id="prodId"
                           value="<?= htmlspecialchars($initProdId) ?>">
                    <div id="prodInfo" class="mt-1 text-muted" style="font-size:0.72rem;"></div>
                </div>

                <!-- Supplier -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">
                        Supplier / Wholesaler
                        <span class="text-muted fw-normal ms-1 small">(optional)</span>
                    </label>
                    <input type="text" name="supplier" class="form-control"
                           placeholder="e.g. Ahmed Traders, Kabul Market…"
                           value="<?= fv('supplier', $log) ?>">
                </div>

                <!-- Type -->
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= __('field_type') ?></label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="type" id="typeIn" value="in"
                                   <?= fcheck('type', 'in', $log) ?> onchange="onTypeChange()">
                            <label class="form-check-label fw-semibold text-success" for="typeIn">
                                <i class="bi bi-arrow-down-circle me-1"></i><?= __('stock_in') ?> (Incoming)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="type" id="typeOut" value="out"
                                   <?= fcheck('type', 'out', $log) ?> onchange="onTypeChange()">
                            <label class="form-check-label fw-semibold text-danger" for="typeOut">
                                <i class="bi bi-arrow-up-circle me-1"></i><?= __('stock_out') ?> (Outgoing)
                            </label>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <!-- Quantity -->
                    <div class="col-sm-4">
                        <label class="form-label fw-semibold"><?= __('field_quantity') ?> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="quantity" id="qty" class="form-control"
                                   min="1" placeholder="0" oninput="calcTotal()"
                                   value="<?= fv('quantity', $log) ?>">
                            <span class="input-group-text">pcs</span>
                        </div>
                    </div>

                    <!-- Bundle -->
                    <div class="col-sm-4" id="bundleWrap">
                        <label class="form-label fw-semibold">
                            Bundles <span class="text-muted fw-normal small">(optional)</span>
                        </label>
                        <div class="input-group">
                            <input type="number" name="bundle_count" id="bundleCount" class="form-control"
                                   min="0" placeholder="0" oninput="calcTotal()"
                                   value="<?= fv('bundle_count', $log) ?>">
                            <span class="input-group-text">bundles</span>
                        </div>
                    </div>

                    <!-- Pricing type -->
                    <div class="col-sm-4" id="pricingWrap">
                        <label class="form-label fw-semibold">Pricing</label>
                        <select name="pricing_type" id="pricingType" class="form-select" onchange="calcTotal()">
                            <option value="per_pcs"    <?= fsel('pricing_type', 'per_pcs',    $log) ?>>Per pcs</option>
                            <option value="per_bundle" <?= fsel('pricing_type', 'per_bundle', $log) ?>>Per bundle</option>
                        </select>
                    </div>
                </div>

                <!-- Price row -->
                <div class="row g-3 mt-0" id="priceWrap">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Unit Price <span class="text-muted fw-normal small">(optional)</span></label>
                        <div class="input-group">
                            <span class="input-group-text">؋</span>
                            <input type="number" name="unit_price" id="unitPrice" class="form-control"
                                   min="0" step="1" placeholder="0" oninput="calcTotal()"
                                   value="<?= fv('unit_price', $log) ?>">
                            <span class="input-group-text small text-muted" id="pricingLabel">/ pcs</span>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Total Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">؋</span>
                            <input type="number" name="total_amount" id="totalAmount"
                                   class="form-control calc-field"
                                   min="0" step="1" placeholder="0" oninput="onTotalManual()"
                                   value="<?= fv('total_amount', $log) ?>">
                        </div>
                        <div class="form-text">Auto-calculated · or enter manually</div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Bill Upload -->
        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-image" style="color:#0067C0;"></i>
                Bill / Receipt
                <span class="text-muted fw-normal small ms-1">(optional)</span>
            </div>
            <div class="card-body">
                <?php if ($log['bill_image']): ?>
                <div class="mb-2 d-flex align-items-center gap-2 small text-muted">
                    <i class="bi bi-file-earmark-check text-success"></i>
                    Current bill:
                    <a href="/uploads/stock-bills/<?= htmlspecialchars($log['bill_image']) ?>" target="_blank">
                        <?= htmlspecialchars($log['bill_image']) ?>
                    </a>
                    &mdash; <span class="text-warning">Upload a new image below to replace it.</span>
                </div>
                <?php endif; ?>
                <div id="billDrop" class="bill-drop-zone">
                    <i class="bi bi-cloud-upload fs-4 text-primary d-block mb-1"></i>
                    <div id="billDropText"><?= $log['bill_image'] ? 'Drop new bill here or <strong>click to replace</strong>' : 'Drop bill image here or <strong>click to browse</strong>' ?></div>
                    <div id="billStatus" class="text-muted small mt-1"></div>
                    <img id="billThumb" class="bill-thumb" alt="Bill preview"
                         <?php if ($log['bill_image']): ?>
                         src="/uploads/stock-bills/<?= htmlspecialchars($log['bill_image']) ?>"
                         style="display:block;"
                         <?php endif; ?>>
                </div>
                <input type="file" id="billFileInput"
                       accept="image/jpeg,image/png,image/webp,image/gif"
                       style="display:none;">
                <input type="hidden" name="bill_image" id="billImageVal"
                       value="<?= htmlspecialchars($_POST['bill_image'] ?? $log['bill_image'] ?? '') ?>">
            </div>
        </div>

    </div><!-- /col-lg-8 -->

    <!-- ── Right col ── -->
    <div class="col-lg-4">

        <!-- Payment Summary -->
        <div class="card mb-3" id="paymentCard">
            <div class="card-header fw-semibold d-flex align-items-center gap-2">
                <i class="bi bi-cash-coin" style="color:#107C10;"></i> Payment
            </div>
            <div class="card-body">
                <div class="mb-1 d-flex justify-content-between align-items-center">
                    <span class="text-muted small">Total</span>
                    <span class="fw-bold" id="summTotal">؋ 0</span>
                </div>
                <hr class="my-2">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Paid Amount <span class="text-muted fw-normal small">(optional)</span></label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">؋</span>
                        <input type="number" name="paid_amount" id="paidAmount" class="form-control"
                               min="0" step="1" placeholder="0" oninput="calcBalance()"
                               value="<?= fv('paid_amount', $log) ?>">
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center p-2 rounded"
                     style="background:rgba(196,43,28,0.06);border:1px solid rgba(196,43,28,0.15);">
                    <span class="fw-semibold small">Unpaid / Balance</span>
                    <span class="fw-bold text-danger" id="summBalance">؋ 0</span>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex align-items-center gap-2">
                <i class="bi bi-sticky" style="color:#9D5D00;"></i>
                Notes <span class="text-muted fw-normal small ms-1">(optional)</span>
            </div>
            <div class="card-body">
                <textarea name="notes" class="form-control form-control-sm" rows="3"
                          placeholder="e.g. Bought from market, good quality…"><?= fv('notes', $log) ?></textarea>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 fw-semibold">
            <i class="bi bi-check-circle me-2"></i><?= __('btn_update') ?>
        </button>
        <a href="index.php" class="btn btn-light w-100 mt-2"><?= __('btn_cancel') ?></a>
    </div>

</div>
</form>

<script>
const PRODS   = <?= json_encode($prodJs) ?>;
const PROD_MAP = {};
PRODS.forEach(p => { PROD_MAP[p.label] = p; });

// Init product info display
(function() {
    const pid = parseInt(document.getElementById('prodId').value) || 0;
    if (pid) {
        const p = PRODS.find(x => x.id == pid);
        if (p) {
            document.getElementById('prodInfo').innerHTML =
                `<i class="bi bi-archive me-1"></i>Current stock: <b>${p.stock}</b> pcs`;
        }
    }
})();

function matchProd(input) {
    const val  = input.value.trim();
    const prod = PROD_MAP[val] || null;
    const idEl = document.getElementById('prodId');
    const info = document.getElementById('prodInfo');
    if (prod) {
        idEl.value = prod.id;
        info.innerHTML = `<i class="bi bi-archive me-1"></i>Current stock: <b>${prod.stock}</b> pcs`;
        info.style.color = '';
    } else {
        idEl.value = '';
        info.innerHTML = val ? '<i class="bi bi-pencil me-1"></i>Custom product — will not affect existing stock' : '';
        info.style.color = '#888';
    }
}

function onTypeChange() {
    const isIn      = document.getElementById('typeIn').checked;
    const priceWrap = document.getElementById('priceWrap');
    const bundleWrap= document.getElementById('bundleWrap');
    const payCard   = document.getElementById('paymentCard');
    priceWrap.style.display  = isIn ? '' : 'none';
    bundleWrap.style.display = isIn ? '' : 'none';
    payCard.style.display    = isIn ? '' : 'none';
    calcBalance();
}

document.getElementById('pricingType').addEventListener('change', function() {
    document.getElementById('pricingLabel').textContent = this.value === 'per_bundle' ? '/ bundle' : '/ pcs';
    calcTotal();
});

let manualTotal = false;
function calcTotal() {
    if (manualTotal) { calcBalance(); return; }
    const qty     = parseInt(document.getElementById('qty').value)        || 0;
    const bundles = parseInt(document.getElementById('bundleCount').value) || 0;
    const price   = parseFloat(document.getElementById('unitPrice').value) || 0;
    const pricing = document.getElementById('pricingType').value;

    let total = 0;
    if (pricing === 'per_bundle' && bundles > 0) total = bundles * price;
    else if (pricing === 'per_pcs' && qty > 0)   total = qty * price;

    document.getElementById('totalAmount').value = total > 0 ? total.toFixed(0) : '';
    calcBalance();
}

function onTotalManual() { manualTotal = true; calcBalance(); }

function calcBalance() {
    const total   = parseFloat(document.getElementById('totalAmount').value) || 0;
    const paid    = parseFloat(document.getElementById('paidAmount').value)  || 0;
    const balance = Math.max(0, total - paid);
    document.getElementById('summTotal').textContent   = '؋ ' + total.toLocaleString('en-AF', {maximumFractionDigits:0});
    document.getElementById('summBalance').textContent = '؋ ' + balance.toLocaleString('en-AF', {maximumFractionDigits:0});
}

document.getElementById('qty').addEventListener('input',        () => { manualTotal = false; calcTotal(); });
document.getElementById('bundleCount').addEventListener('input', () => { manualTotal = false; calcTotal(); });
document.getElementById('unitPrice').addEventListener('input',  () => { manualTotal = false; calcTotal(); });

// Init
onTypeChange();
calcBalance();

// Update pricing label from current select value
document.getElementById('pricingLabel').textContent =
    document.getElementById('pricingType').value === 'per_bundle' ? '/ bundle' : '/ pcs';

// ── Bill image upload ──
const billDrop  = document.getElementById('billDrop');
const billInput = document.getElementById('billFileInput');
const billThumb = document.getElementById('billThumb');
const billStatus= document.getElementById('billStatus');
const billVal   = document.getElementById('billImageVal');

<?php if ($log['bill_image']): ?>
billDrop.classList.add('has-file');
billThumb.style.display = 'block';
<?php endif; ?>

billDrop.addEventListener('click', () => billInput.click());
billDrop.addEventListener('dragover',  e => { e.preventDefault(); billDrop.classList.add('drag-over'); });
billDrop.addEventListener('dragleave', () => billDrop.classList.remove('drag-over'));
billDrop.addEventListener('drop', e => {
    e.preventDefault(); billDrop.classList.remove('drag-over');
    const f = e.dataTransfer.files[0];
    if (f && f.type.startsWith('image/')) uploadBill(f);
});
billInput.addEventListener('change', () => {
    if (billInput.files[0]) uploadBill(billInput.files[0]);
    billInput.value = '';
});

function uploadBill(file) {
    billStatus.textContent = 'Uploading…';
    billDrop.classList.remove('has-file');
    const fr = new FileReader();
    fr.onload = e => { billThumb.src = e.target.result; billThumb.style.display = 'block'; };
    fr.readAsDataURL(file);

    const fd = new FormData();
    fd.append('image', file);
    fetch('/stock/upload-bill.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                billVal.value = res.filename;
                billStatus.textContent = '✓ ' + file.name;
                billStatus.style.color = '#107C10';
                billDrop.classList.add('has-file');
            } else {
                billStatus.textContent = '✗ ' + (res.error || 'Upload failed');
                billStatus.style.color = '#C42B1C';
            }
        })
        .catch(() => { billStatus.textContent = '✗ Network error'; billStatus.style.color='#C42B1C'; });
}
</script>

<?php require_once '../includes/footer.php'; ?>
