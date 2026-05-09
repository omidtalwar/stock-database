<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';

$pageTitle = __('stock_move_title');

// ── Auto-migrate new columns ──
foreach ([
    "ALTER TABLE stock_logs MODIFY product_id   INT           NULL",
    "ALTER TABLE stock_logs ADD COLUMN custom_product VARCHAR(255) NULL",
    "ALTER TABLE stock_logs ADD COLUMN supplier       VARCHAR(255) NULL",
    "ALTER TABLE stock_logs ADD COLUMN bundle_count   INT          NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN pricing_type   VARCHAR(20)  NULL DEFAULT 'per_pcs'",
    "ALTER TABLE stock_logs ADD COLUMN unit_price     DECIMAL(10,2) NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN total_amount   DECIMAL(10,2) NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN paid_amount    DECIMAL(10,2) NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN balance        DECIMAL(10,2) NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN bill_image     TEXT          NULL",
] as $_sql) { try { $pdo->exec($_sql); } catch (\PDOException $e) {} }

$products    = $pdo->query("SELECT id, name, size, color, quantity FROM products ORDER BY name ASC")->fetchAll();
$preProduct  = (int)($_GET['product_id'] ?? 0);
$preSupplier = trim($_GET['supplier'] ?? '');
$knownSuppliers = $pdo->query(
    "SELECT DISTINCT supplier FROM stock_logs WHERE supplier IS NOT NULL AND supplier != '' ORDER BY supplier ASC"
)->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid          = (int)($_POST['product_id']   ?? 0);
    $customProd   = trim($_POST['custom_product'] ?? '');
    $type         = in_array($_POST['type'] ?? '', ['in','out']) ? $_POST['type'] : 'in';
    $qty          = (int)($_POST['quantity']      ?? 0);
    $bundleCount  = (int)($_POST['bundle_count']  ?? 0);
    $pricingType  = in_array($_POST['pricing_type'] ?? '', ['per_pcs','per_bundle']) ? $_POST['pricing_type'] : 'per_pcs';
    $unitPrice    = (float)($_POST['unit_price']  ?? 0);
    $totalAmount  = (float)($_POST['total_amount'] ?? 0);
    $paidAmount   = (float)($_POST['paid_amount'] ?? 0);
    $balance      = max(0, $totalAmount - $paidAmount);
    $notes        = trim($_POST['notes']          ?? '');
    $supplier     = trim($_POST['supplier']       ?? '');
    $billImage    = trim($_POST['bill_image']      ?? '');

    // Validation — only qty is truly required
    $errors = [];
    if ($qty <= 0) $errors[] = 'Quantity must be greater than 0.';
    if (!$pid && !$customProd) $errors[] = 'Please select or enter a product name.';

    if ($type === 'out' && $pid) {
        $curr = (int)$pdo->prepare("SELECT quantity FROM products WHERE id=?")->execute([$pid]) ? $pdo->prepare("SELECT quantity FROM products WHERE id=?")->execute([$pid]) : 0;
        $currQty = (int)$pdo->query("SELECT quantity FROM products WHERE id=$pid")->fetchColumn();
        if ($qty > $currQty) $errors[] = sprintf(__('stock_not_enough'), $qty, $currQty);
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            // Update product quantity only if known product
            if ($pid) {
                $op = $type === 'in' ? 'quantity + ?' : 'quantity - ?';
                $pdo->prepare("UPDATE products SET quantity = $op WHERE id=?")->execute([$qty, $pid]);
            }

            $pdo->prepare("
                INSERT INTO stock_logs
                    (product_id, custom_product, type, quantity, bundle_count, pricing_type,
                     unit_price, total_amount, paid_amount, balance,
                     supplier, notes, bill_image, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $pid ?: null, $customProd ?: null, $type, $qty, $bundleCount ?: null, $pricingType,
                $unitPrice, $totalAmount, $paidAmount, $balance,
                $supplier ?: null, $notes ?: null, $billImage ?: null, $_SESSION['user_id']
            ]);

            $pdo->commit();
            $_SESSION['success'] = ($type === 'in' ? __('stock_in') : __('stock_out')) . " — $qty pcs";
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Failed to save. Please try again.';
        }
    }

    if (!empty($errors)) $_SESSION['error'] = implode('<br>', $errors);
}

require_once '../includes/header.php';

// Build product map for JS
$prodJs = array_map(fn($p) => [
    'id'    => $p['id'],
    'label' => $p['name'].($p['size'] ? ' ('.$p['size'].')' : '').($p['color'] ? ' - '.$p['color'] : ''),
    'stock' => (int)$p['quantity'],
], $products);
?>

<style>
.bill-drop-zone {
    border: 2px dashed rgba(0,103,192,0.35); border-radius: 10px;
    padding: 28px 12px; text-align: center; cursor: pointer;
    transition: border-color .2s, background .2s;
    background: rgba(0,103,192,0.02); font-size: 0.83rem; user-select: none;
}
.bill-drop-zone:hover, .bill-drop-zone.drag-over { border-color:#0067C0; background:rgba(0,103,192,0.07); }
.bill-drop-zone.has-file { border-style: solid; border-color:rgba(16,124,16,0.4); background:rgba(16,124,16,0.04); }
.bill-thumb { width:100%; max-height:180px; object-fit:contain; border-radius:8px; margin-top:10px; display:none; }
.calc-field {
    background: rgba(0,103,192,0.04) !important;
    border-color: rgba(0,103,192,0.2) !important;
    color: var(--w11-blue) !important;
    font-weight: 700 !important;
}
.section-label {
    font-size: 0.68rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .7px; color: var(--w11-muted); margin-bottom: 8px;
}
</style>

<div class="page-header">
    <a href="index.php" class="text-muted small">
        <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_stock') ?>
    </a>
    <h4 class="mt-1 mb-0"><?= __('stock_move_title') ?></h4>
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
                           placeholder="Select from list or type a custom product / size…"
                           autocomplete="off"
                           oninput="matchProd(this)"
                           value="<?= htmlspecialchars($_POST['custom_product'] ?? '') ?>">
                    <datalist id="prod-list">
                        <?php foreach ($prodJs as $p): ?>
                        <option value="<?= htmlspecialchars($p['label']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="product_id" id="prodId"
                           value="<?= htmlspecialchars($_POST['product_id'] ?? '') ?>">
                    <div id="prodInfo" class="mt-1 text-muted" style="font-size:0.72rem;"></div>
                    <?php if ($preProduct): ?>
                    <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        const pre = <?= json_encode($prodJs) ?>.find(p => p.id == <?= $preProduct ?>);
                        if (pre) {
                            document.getElementById('prodInput').value = pre.label;
                            document.getElementById('prodId').value    = pre.id;
                            document.getElementById('prodInfo').innerHTML =
                                `<i class="bi bi-archive me-1"></i>Stock: <b>${pre.stock}</b> pcs`;
                        }
                    });
                    </script>
                    <?php endif; ?>
                </div>

                <!-- Supplier (optional) -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">
                        Supplier / Wholesaler
                        <span class="text-muted fw-normal ms-1 small">(optional)</span>
                    </label>
                    <input list="supplier-list"
                           type="text" name="supplier" class="form-control"
                           placeholder="e.g. Ahmed Traders, Kabul Market…"
                           autocomplete="off"
                           value="<?= htmlspecialchars($_POST['supplier'] ?? $preSupplier) ?>">
                    <datalist id="supplier-list">
                        <?php foreach ($knownSuppliers as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <!-- Type -->
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= __('field_type') ?></label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="type" id="typeIn" value="in"
                                   <?= ($_POST['type'] ?? 'in') === 'in' ? 'checked' : '' ?> onchange="onTypeChange()">
                            <label class="form-check-label fw-semibold text-success" for="typeIn">
                                <i class="bi bi-arrow-down-circle me-1"></i><?= __('stock_in') ?> (Incoming)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="type" id="typeOut" value="out"
                                   <?= ($_POST['type'] ?? '') === 'out' ? 'checked' : '' ?> onchange="onTypeChange()">
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
                                   value="<?= htmlspecialchars($_POST['quantity'] ?? '') ?>">
                            <span class="input-group-text">pcs</span>
                        </div>
                    </div>

                    <!-- Bundle -->
                    <div class="col-sm-4" id="bundleWrap">
                        <label class="form-label fw-semibold">
                            Bundles
                            <span class="text-muted fw-normal small">(optional)</span>
                        </label>
                        <div class="input-group">
                            <input type="number" name="bundle_count" id="bundleCount" class="form-control"
                                   min="0" placeholder="0" oninput="calcTotal()"
                                   value="<?= htmlspecialchars($_POST['bundle_count'] ?? '') ?>">
                            <span class="input-group-text">bundles</span>
                        </div>
                    </div>

                    <!-- Pricing type -->
                    <div class="col-sm-4" id="pricingWrap">
                        <label class="form-label fw-semibold">Pricing</label>
                        <select name="pricing_type" id="pricingType" class="form-select" onchange="calcTotal()">
                            <option value="per_pcs"    <?= ($_POST['pricing_type'] ?? 'per_pcs') === 'per_pcs'    ? 'selected' : '' ?>>Per pcs</option>
                            <option value="per_bundle" <?= ($_POST['pricing_type'] ?? '') === 'per_bundle' ? 'selected' : '' ?>>Per bundle</option>
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
                                   value="<?= htmlspecialchars($_POST['unit_price'] ?? '') ?>">
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
                                   value="<?= htmlspecialchars($_POST['total_amount'] ?? '') ?>">
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
                <div id="billDrop" class="bill-drop-zone">
                    <i class="bi bi-cloud-upload fs-4 text-primary d-block mb-1"></i>
                    <div id="billDropText">Drop bill image here or <strong>click to browse</strong></div>
                    <div id="billStatus" class="text-muted small mt-1"></div>
                    <img id="billThumb" class="bill-thumb" alt="Bill preview">
                </div>
                <input type="file" id="billFileInput"
                       accept="image/jpeg,image/png,image/webp,image/gif"
                       style="display:none;">
                <input type="hidden" name="bill_image" id="billImageVal"
                       value="<?= htmlspecialchars($_POST['bill_image'] ?? '') ?>">
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
                               value="<?= htmlspecialchars($_POST['paid_amount'] ?? '0') ?>">
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
                          placeholder="e.g. Bought from market, good quality…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 fw-semibold">
            <i class="bi bi-check-circle me-2"></i><?= __('stock_save') ?>
        </button>
        <a href="index.php" class="btn btn-light w-100 mt-2"><?= __('btn_cancel') ?></a>
    </div>

</div>
</form>

<script>
const PRODS = <?= json_encode($prodJs) ?>;
const PROD_MAP = {};
PRODS.forEach(p => { PROD_MAP[p.label] = p; });

// ── Product match ──
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

// ── Type change: show/hide payment card for outgoing ──
function onTypeChange() {
    const isIn      = document.getElementById('typeIn').checked;
    const priceWrap = document.getElementById('priceWrap');
    const bundleWrap= document.getElementById('bundleWrap');
    const payCard   = document.getElementById('paymentCard');
    priceWrap.style.display  = isIn ? '' : 'none';
    bundleWrap.style.display = isIn ? '' : 'none';
    payCard.style.display    = isIn ? '' : 'none';
    calcTotal();
}

// ── Pricing label ──
document.getElementById('pricingType').addEventListener('change', function() {
    document.getElementById('pricingLabel').textContent = this.value === 'per_bundle' ? '/ bundle' : '/ pcs';
    calcTotal();
});

// ── Auto-calculate total ──
let manualTotal = false;
function calcTotal() {
    if (manualTotal) { calcBalance(); return; }
    const qty     = parseInt(document.getElementById('qty').value)         || 0;
    const bundles = parseInt(document.getElementById('bundleCount').value)  || 0;
    const price   = parseFloat(document.getElementById('unitPrice').value)  || 0;
    const pricing = document.getElementById('pricingType').value;

    let total = 0;
    if (pricing === 'per_bundle' && bundles > 0) total = bundles * price;
    else if (pricing === 'per_pcs' && qty > 0)  total = qty * price;

    document.getElementById('totalAmount').value = total > 0 ? total.toFixed(0) : '';
    calcBalance();
}

function onTotalManual() {
    manualTotal = true;
    calcBalance();
}

function calcBalance() {
    const total   = parseFloat(document.getElementById('totalAmount').value) || 0;
    const paid    = parseFloat(document.getElementById('paidAmount').value)  || 0;
    const balance = Math.max(0, total - paid);
    document.getElementById('summTotal').textContent   = '؋ ' + total.toLocaleString('en-AF',{maximumFractionDigits:0});
    document.getElementById('summBalance').textContent = '؋ ' + balance.toLocaleString('en-AF',{maximumFractionDigits:0});
}

// Reset manual total when unit price or qty changes
document.getElementById('qty').addEventListener('input',       () => { manualTotal = false; calcTotal(); });
document.getElementById('bundleCount').addEventListener('input',() => { manualTotal = false; calcTotal(); });
document.getElementById('unitPrice').addEventListener('input', () => { manualTotal = false; calcTotal(); });

// Init
onTypeChange();
calcBalance();

// ── Bill image upload ──
const billDrop  = document.getElementById('billDrop');
const billInput = document.getElementById('billFileInput');
const billThumb = document.getElementById('billThumb');
const billStatus= document.getElementById('billStatus');
const billVal   = document.getElementById('billImageVal');

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

    // Show thumb immediately
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
                billThumb.style.display = 'none';
            }
        })
        .catch(() => { billStatus.textContent = '✗ Network error'; billStatus.style.color='#C42B1C'; });
}
</script>

<?php require_once '../includes/footer.php'; ?>
