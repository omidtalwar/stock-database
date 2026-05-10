<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = __('sale_create_title');

// ── Auto-migrate new columns ──
foreach ([
    "ALTER TABLE sales ADD COLUMN bill_no       VARCHAR(100) NULL AFTER id",
    "ALTER TABLE sales ADD COLUMN sale_date     DATE         NULL AFTER bill_no",
    "ALTER TABLE sales ADD COLUMN images        TEXT         NULL",
    "ALTER TABLE sales ADD COLUMN currency      VARCHAR(10)  NULL DEFAULT 'AFN'",
    "ALTER TABLE sale_items ADD COLUMN custom_name VARCHAR(255) NULL",
    "ALTER TABLE sale_items MODIFY product_id   INT          NULL",
] as $_sql) {
    try { $pdo->exec($_sql); } catch (\PDOException $e) {}
}

$allRates  = getAllRates($pdo);
$settings  = getSettings($pdo);
$secCur    = $settings['secondary_currency'] ?? 'USD';
$secSymbol = currencySymbol($secCur);
$rate      = $allRates[$secCur]; // for secondary display in summary

$customers   = $pdo->query("SELECT id, name, shop_name FROM customers ORDER BY name ASC")->fetchAll();
$products    = $pdo->query("SELECT id, name, size, color, price, quantity FROM products WHERE quantity > 0 ORDER BY name ASC")->fetchAll();
$preCustomer = (int)($_GET['customer_id'] ?? 0);

// Suggest bill number: BL-YYMMDD-NNN
$nextId          = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM sales")->fetchColumn();
$suggestedBillNo = 'BL-' . date('ymd') . '-' . str_pad($nextId, 3, '0', STR_PAD_LEFT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id       = (int)($_POST['customer_id'] ?? 0);
    $sale_currency     = strtoupper(trim($_POST['sale_currency'] ?? 'AFN'));
    if (!array_key_exists($sale_currency, CURRENCIES)) $sale_currency = 'AFN';
    $paid_currency     = strtoupper(trim($_POST['paid_currency'] ?? 'AFN'));
    if (!array_key_exists($paid_currency, CURRENCIES)) $paid_currency = 'AFN';
    $paid_amount_input = (float)($_POST['paid_amount'] ?? 0);
    $paid_amount       = toAFN($paid_amount_input, $paid_currency, $allRates[$paid_currency]);
    $notes             = trim($_POST['notes'] ?? '');
    $bill_no           = trim($_POST['bill_no'] ?? '');
    $sale_date         = $_POST['sale_date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sale_date)) $sale_date = date('Y-m-d');
    $raw_images  = array_values(array_filter(array_map('trim', $_POST['uploaded_images'] ?? [])));
    $images_json = !empty($raw_images) ? json_encode($raw_images) : null;
    $items       = $_POST['items'] ?? [];

    $errors    = [];
    $lineItems = [];
    $total     = 0;

    if (!$customer_id) $errors[] = __('sale_select_cust');
    if (empty($items))  $errors[] = __('sale_add_product');

    foreach ($items as $item) {
        $pid       = (int)($item['product_id'] ?? 0);
        $qty       = (int)($item['quantity']   ?? 0);
        $unitPrice = (float)($item['unit_price'] ?? 0);
        $custName  = trim($item['product_name'] ?? '');
        if ($qty <= 0 || $unitPrice <= 0) continue;

        if ($pid > 0) {
            // Known product — validate stock
            $prod = null;
            foreach ($products as $p) { if ($p['id'] == $pid) { $prod = $p; break; } }
            if (!$prod) { $errors[] = 'Invalid product.'; break; }
            if ($qty > (int)$prod['quantity']) { $errors[] = sprintf(__('stock_not_enough'), $qty, $prod['quantity']); break; }
        } else {
            // Custom item — no stock check, just needs a name
            if (!$custName) { $errors[] = 'Please enter a name for the custom item.'; break; }
        }

        // Convert prices from sale_currency to AFN for storage
        $saleRate     = $allRates[$sale_currency];
        $unitPriceAfn = $unitPrice * $saleRate;
        $sub          = $qty * $unitPriceAfn;
        $total       += $sub;
        $lineItems[] = [
            'product_id'  => $pid ?: null,
            'quantity'    => $qty,
            'unit_price'  => $unitPriceAfn,
            'subtotal'    => $sub,
            'custom_name' => $custName ?: null,
        ];
    }

    if (empty($lineItems) && empty($errors)) $errors[] = __('sale_click_add');

    if (empty($errors)) {
        $balance = max(0, $total - $paid_amount);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO sales (bill_no, sale_date, customer_id, total_amount, paid_amount, balance, notes, images, currency, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([$bill_no ?: null, $sale_date, $customer_id, $total, $paid_amount, $balance, $notes, $images_json, $sale_currency, $_SESSION['user_id']]);
            $saleId = $pdo->lastInsertId();

            foreach ($lineItems as $li) {
                $pdo->prepare("INSERT INTO sale_items (sale_id,product_id,quantity,unit_price,subtotal,custom_name) VALUES (?,?,?,?,?,?)")
                    ->execute([$saleId, $li['product_id'], $li['quantity'], $li['unit_price'], $li['subtotal'], $li['custom_name']]);
                if ($li['product_id']) {
                    $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?")->execute([$li['quantity'], $li['product_id']]);
                    $pdo->prepare("INSERT INTO stock_logs (product_id,type,quantity,notes,created_by) VALUES (?,'out',?,?,?)")
                        ->execute([$li['product_id'], $li['quantity'], 'Sale #'.str_pad($saleId,4,'0',STR_PAD_LEFT), $_SESSION['user_id']]);
                }
            }

            $pdo->prepare("UPDATE customers SET total_debt = total_debt + ? WHERE id = ?")->execute([$balance, $customer_id]);
            $pdo->commit();
            $_SESSION['success'] = '#'.str_pad($saleId,4,'0',STR_PAD_LEFT);
            header("Location: view.php?id=$saleId");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Failed to save. Please try again.';
        }
    }
    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
    }
}

// ── Gregorian → Shamsi (Solar Hijri) conversion ──
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
$todayShamsi = toShamsi((int)date('Y'), (int)date('n'), (int)date('j'));

require_once '../includes/header.php';
?>

<style>
/* ── Drop zone ── */
.inv-drop-zone {
    border: 2px dashed rgba(0,103,192,0.35);
    border-radius: 10px;
    padding: 20px 12px;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    background: rgba(0,103,192,0.02);
    font-size: 0.82rem;
    user-select: none;
}
.inv-drop-zone:hover,
.inv-drop-zone.drag-over {
    border-color: #0067C0;
    background: rgba(0,103,192,0.07);
}
.inv-drop-zone.maxed {
    border-color: rgba(0,0,0,0.12);
    background: rgba(0,0,0,0.02);
    cursor: not-allowed;
    opacity: 0.6;
}

/* ── Image preview cards ── */
.img-preview-grid { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }

.img-card {
    width: 96px;
    font-size: 0.68rem;
    color: #666;
}
.img-card-thumb {
    width: 96px; height: 96px;
    border-radius: 8px;
    border: 1px solid rgba(0,0,0,0.1);
    background: #f2f2f2;
    overflow: hidden;
    position: relative;
}
.img-card-thumb img {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
}
/* Progress overlay */
.img-progress-wrap {
    position: absolute; bottom: 0; left: 0; right: 0;
    height: 4px;
    background: rgba(255,255,255,0.4);
}
.img-progress-fill {
    height: 100%;
    background: #0067C0;
    border-radius: 0 0 0 0;
    transition: width .08s linear;
    width: 0%;
}
/* Uploading spinner ring */
.img-uploading-ring {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    background: rgba(0,0,0,0.28);
    border-radius: 7px;
}
.img-uploading-ring svg {
    animation: spin .8s linear infinite;
    width: 28px; height: 28px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Status badge */
.img-status-badge {
    position: absolute; top: 4px; left: 4px;
    width: 20px; height: 20px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem;
}
.img-status-badge.ok    { background: rgba(16,124,16,0.88); color: #fff; }
.img-status-badge.error { background: rgba(196,43,28,0.88); color: #fff; }

/* Remove button */
.img-remove-btn {
    position: absolute; top: 4px; right: 4px;
    width: 20px; height: 20px; border-radius: 50%;
    background: rgba(0,0,0,0.55);
    border: none; color: #fff; cursor: pointer;
    display: none; align-items: center; justify-content: center;
    font-size: 0.7rem; padding: 0;
    transition: background .15s;
}
.img-remove-btn:hover { background: rgba(196,43,28,0.9); }

.img-card-name {
    margin-top: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 96px;
}

/* ── Shamsi badge ── */
.shamsi-badge {
    display: inline-flex; align-items: center; gap: 4px;
    background: rgba(0,103,192,0.07);
    border: 1px solid rgba(0,103,192,0.2);
    border-radius: 20px;
    padding: 2px 10px;
    font-size: 0.72rem;
    color: #0067C0;
    font-weight: 600;
    margin-top: 4px;
}
</style>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <a href="index.php" class="text-muted small">
            <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_sales') ?>
        </a>
        <h4 class="mt-1 mb-0"><?= __('sale_create_title') ?></h4>
    </div>
</div>

<form method="POST" id="invoiceForm">
<div class="row g-3">

    <!-- ── Left column ── -->
    <div class="col-lg-8">

        <!-- ① Invoice Details -->
        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-text" style="color:#0067C0;"></i>
                Invoice Details
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <!-- Bill No -->
                    <div class="col-sm-4">
                        <label class="form-label small fw-semibold">Bill No</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="bi bi-hash"></i></span>
                            <input type="text" name="bill_no" class="form-control"
                                   value="<?= htmlspecialchars($_POST['bill_no'] ?? $suggestedBillNo) ?>"
                                   placeholder="e.g. BL-250506-001">
                        </div>
                        <div class="form-text">Auto-suggested — you can change it.</div>
                    </div>

                    <!-- Sale Currency -->
                    <div class="col-sm-2">
                        <label class="form-label small fw-semibold"><i class="bi bi-currency-exchange me-1 text-primary"></i>Currency</label>
                        <select name="sale_currency" id="saleCurrencySelect" class="form-select form-select-sm" onchange="onSaleCurrencyChange()">
                            <?php foreach (CURRENCIES as $code => $cur): ?>
                            <option value="<?= $code ?>" <?= ($code === ($_POST['sale_currency'] ?? 'AFN')) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cur['symbol'] . ' ' . $code) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Date — Solar Hijri -->
                    <div class="col-sm-6">
                        <label class="form-label small fw-semibold d-flex align-items-center gap-2">
                            Date
                            <span class="badge bg-success-subtle text-success border border-success-subtle"
                                  style="font-size:0.62rem;">Solar Hijri</span>
                        </label>
                        <?php
                        $defJy = (int)($_POST['shamsi_y'] ?? $todayShamsi['y']);
                        $defJm = (int)($_POST['shamsi_m'] ?? $todayShamsi['m']);
                        $defJd = (int)($_POST['shamsi_d'] ?? $todayShamsi['d']);
                        $jMonths = ['۱ حمل','۲ ثور','۳ جوزا','۴ سرطان','۵ اسد','۶ سنبله',
                                    '۷ میزان','۸ عقرب','۹ قوس','۱۰ جدی','۱۱ دلو','۱۲ حوت'];
                        ?>
                        <div class="d-flex align-items-center gap-1">
                            <input type="number" id="jYear" name="shamsi_y"
                                   class="form-control form-control-sm text-center fw-semibold"
                                   value="<?= $defJy ?>" min="1300" max="1600"
                                   style="width:74px;" oninput="syncShamsiDate()">
                            <span class="text-muted">/</span>
                            <select id="jMonth" name="shamsi_m"
                                    class="form-select form-select-sm" style="width:132px;"
                                    onchange="syncShamsiDate()">
                                <?php foreach ($jMonths as $i => $nm): ?>
                                <option value="<?= $i+1 ?>" <?= $defJm === $i+1 ? 'selected' : '' ?>><?= $nm ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="text-muted">/</span>
                            <input type="number" id="jDay" name="shamsi_d"
                                   class="form-control form-control-sm text-center fw-semibold"
                                   value="<?= $defJd ?>" min="1" max="31"
                                   style="width:60px;" oninput="syncShamsiDate()">
                        </div>
                        <input type="hidden" name="sale_date" id="saleDate"
                               value="<?= htmlspecialchars($_POST['sale_date'] ?? date('Y-m-d')) ?>">
                        <div id="gregorianBadge" class="mt-1 text-muted" style="font-size:0.71rem;"></div>
                    </div>

                    <!-- Customer -->
                    <div class="col-12">
                        <label class="form-label small fw-semibold"><?= __('sale_select_cust') ?> <span class="text-danger">*</span></label>
                        <select name="customer_id" class="form-select form-select-sm" required>
                            <option value=""><?= __('sale_choose_cust') ?></option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>"
                                <?= ($preCustomer == $c['id'] || ($_POST['customer_id'] ?? 0) == $c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['shop_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- ② Products -->
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span class="fw-semibold d-flex align-items-center gap-2">
                    <i class="bi bi-box-seam" style="color:#0067C0;"></i>
                    <?= __('sale_products') ?>
                </span>
                <button type="button" class="btn btn-sm btn-primary" id="addRow">
                    <i class="bi bi-plus-circle me-1"></i><?= __('sale_add_product') ?>
                </button>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0" id="itemsTable">
                    <thead>
                        <tr>
                            <th><?= __('nav_products') ?> / Size</th>
                            <th style="width:90px;"><?= __('field_quantity') ?></th>
                            <th style="width:130px;"><?= __('field_price') ?> (<span class="sale-cur-hdr">؋</span>)</th>
                            <th style="width:120px;"><?= __('field_total') ?> (<span class="sale-cur-hdr">؋</span>)</th>
                            <th style="width:46px;"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        <tr id="emptyRow">
                            <td colspan="5" class="text-center text-muted py-4 small"><?= __('sale_click_add') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ③ Attach Image -->
        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex align-items-center gap-2">
                <i class="bi bi-image" style="color:#0067C0;"></i>
                Attach Images
                <span class="text-muted fw-normal ms-1 small">(up to 3 · JPG, PNG, WebP · max 5 MB each)</span>
            </div>
            <div class="card-body">
                <div id="dropZone" class="inv-drop-zone">
                    <i class="bi bi-cloud-upload fs-5 text-primary mb-1"></i>
                    <div>Drop images here or <strong>click to browse</strong>
                        <span class="text-muted ms-1" id="dzRemaining">(3 remaining)</span>
                    </div>
                </div>
                <input type="file" id="imageFileInput"
                       accept="image/jpeg,image/png,image/webp,image/gif"
                       multiple style="display:none;">
                <div id="imgPreviewGrid" class="img-preview-grid"></div>
                <div id="imgHiddenInputs"></div>
            </div>
        </div>

    </div><!-- /col-lg-8 -->

    <!-- ── Right column (summary) ── -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold"><?= __('sale_summary') ?></div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted"><?= __('sale_subtotal') ?></span>
                    <span class="fw-semibold" id="summaryTotal">؋ 0</span>
                </div>
                <div class="d-flex justify-content-between mb-3 text-muted small">
                    <span>≈ ؋ AFN equivalent</span>
                    <span id="summaryTotalSec">—</span>
                </div>
                <hr>
                <div class="mb-2">
                    <label class="form-label fw-semibold"><?= __('sale_paid_now') ?></label>
                    <div class="input-group input-group-sm">
                        <select name="paid_currency" id="paidCurrency" class="form-select"
                                style="max-width:110px;" onchange="updateSummary()">
                            <?php foreach (CURRENCIES as $code => $cur): ?>
                            <option value="<?= $code ?>" <?= ($_POST['paid_currency'] ?? 'AFN') === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cur['symbol'] . ' ' . $code) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="paid_amount" id="paidAmount" class="form-control"
                               min="0" step="0.01"
                               value="<?= htmlspecialchars($_POST['paid_amount'] ?? '0') ?>"
                               oninput="updateSummary()">
                    </div>
                    <div class="text-muted small mt-1" id="paidConvert" style="display:none;"></div>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted small"><?= __('sale_paid_afn') ?></span>
                    <span class="text-success small fw-semibold" id="paidAfnDisplay">؋ 0</span>
                </div>
                <div class="d-flex justify-content-between mb-3">
                    <span class="fw-semibold"><?= __('sale_remaining') ?></span>
                    <span class="fw-bold text-danger" id="summaryBalance">؋ 0</span>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= __('field_notes') ?></label>
                    <textarea name="notes" class="form-control form-control-sm"
                              rows="2"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-100 fw-semibold">
                    <i class="bi bi-check-circle me-2"></i><?= __('sale_save') ?>
                </button>
            </div>
        </div>

        <div class="card mt-2">
            <div class="card-body py-2 small text-muted">
                <i class="bi bi-currency-exchange me-1"></i>
                <?= __('dash_rate_label') ?>: <strong>1 <?= htmlspecialchars($secCur) ?> = <?= number_format($rate, 2) ?> ؋</strong>
                <?php if (isAdmin()): ?>&nbsp;<a href="/admin/settings.php"><?= __('btn_update') ?></a><?php endif; ?>
            </div>
        </div>
    </div>

</div>
</form>

<script>
/* ── Products row logic ── */
const PRODUCTS = <?= json_encode(array_map(fn($p) => [
    'id'    => $p['id'],
    'label' => $p['name'].($p['size'] ? ' ('.$p['size'].')' : '').($p['color'] ? ' - '.$p['color'] : ''),
    'price' => (float)$p['price'],
    'stock' => (int)$p['quantity'],
], $products)) ?>;

const ALL_RATES_INV = <?= json_encode($allRates) ?>;
const CURRENCIES_INV = <?= json_encode(array_map(fn($c) => ['symbol' => $c['symbol'], 'decimals' => $c['decimals']], CURRENCIES)) ?>;
const SEC_CUR_INV = <?= json_encode($secCur) ?>;
const EMPTY_MSG   = <?= json_encode(__('sale_click_add')) ?>;

// Fast lookup by label
const PROD_MAP = {};
PRODUCTS.forEach(p => { PROD_MAP[p.label] = p; });

// Build the shared datalist once
const _dl = document.createElement('datalist');
_dl.id = 'prod-master-list';
PRODUCTS.forEach(p => {
    const o = document.createElement('option'); o.value = p.label; _dl.appendChild(o);
});
document.body.appendChild(_dl);

let rowCount = 0;

function saleCur()  { return document.getElementById('saleCurrencySelect')?.value || 'AFN'; }
function saleSym()  { return CURRENCIES_INV[saleCur()]?.symbol || '؋'; }
function saleDec()  { return CURRENCIES_INV[saleCur()]?.decimals ?? 0; }
function saleRate() { return ALL_RATES_INV[saleCur()] || 1; }

function fmtSale(v) {
    const dec = saleDec();
    return saleSym() + ' ' + parseFloat(v).toLocaleString('en-US', {minimumFractionDigits:dec, maximumFractionDigits:dec});
}
function fmtAFN_inv(v) { return '؋ ' + parseFloat(v).toLocaleString('en-US', {maximumFractionDigits:0}); }

function onSaleCurrencyChange() {
    const sym = saleSym();
    // Update table header symbols
    document.querySelectorAll('.sale-cur-hdr').forEach(el => el.textContent = sym);
    // Re-calc all rows (product prices stay as AFN, convert for display)
    document.querySelectorAll('#itemsBody tr[id^="row_"]').forEach(row => {
        const idx = row.id.replace('row_', '');
        const pid = row.querySelector('.prod-id-hidden').value;
        if (pid) {
            const prod = PRODUCTS.find(p => p.id == pid);
            if (prod) {
                const r = saleRate();
                row.querySelector('.price-input').value = r > 0 ? (prod.price / r).toFixed(saleDec()) : prod.price;
            }
        }
        updateRow(idx);
    });
    updateSummary();
}

function addRow() {
    document.getElementById('emptyRow')?.remove();
    rowCount++;
    const idx = rowCount;
    const sym = saleSym();
    const row = document.createElement('tr');
    row.id = `row_${idx}`;
    row.innerHTML = `
        <td style="min-width:200px;">
            <input list="prod-master-list"
                   name="items[${idx}][product_name]"
                   class="form-control form-control-sm prod-name-input"
                   placeholder="Select or type product / size…"
                   autocomplete="off"
                   oninput="matchProduct(${idx}, this)">
            <input type="hidden" name="items[${idx}][product_id]" class="prod-id-hidden" value="">
            <div class="prod-stock-info text-muted mt-1" style="font-size:0.7rem;"></div>
        </td>
        <td>
            <input type="number" name="items[${idx}][quantity]"
                   class="form-control form-control-sm qty-input"
                   value="1" min="1" oninput="updateRow(${idx})" required>
        </td>
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text sale-cur-sym" style="font-size:.8rem;">${sym}</span>
                <input type="number" name="items[${idx}][unit_price]"
                       class="form-control form-control-sm price-input"
                       value="" min="0" step="0.01"
                       oninput="updateRow(${idx})" placeholder="0">
            </div>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm subtotal-display fw-semibold"
                   readonly value="${sym} 0" tabindex="-1">
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-light text-danger"
                    onclick="removeRow(${idx})"><i class="bi bi-x-lg"></i></button>
        </td>`;
    document.getElementById('itemsBody').appendChild(row);
    row.querySelector('.prod-name-input').focus();
}

function matchProduct(idx, input) {
    const row       = document.getElementById(`row_${idx}`);
    const val       = input.value.trim();
    const prod      = PROD_MAP[val] || null;
    const pidInput  = row.querySelector('.prod-id-hidden');
    const priceEl   = row.querySelector('.price-input');
    const stockInfo = row.querySelector('.prod-stock-info');
    const qtyEl     = row.querySelector('.qty-input');

    if (prod) {
        pidInput.value  = prod.id;
        // Convert AFN price to selected sale currency
        const r = saleRate();
        priceEl.value   = r > 0 ? (prod.price / r).toFixed(saleDec()) : prod.price;
        qtyEl.max       = prod.stock;
        stockInfo.innerHTML = `<i class="bi bi-archive me-1"></i>Stock: <b>${prod.stock}</b> pcs`;
        stockInfo.style.color = prod.stock > 0 ? '' : 'var(--w11-red,#C42B1C)';
    } else {
        pidInput.value  = '';
        qtyEl.removeAttribute('max');
        stockInfo.innerHTML = val
            ? '<i class="bi bi-pencil me-1"></i>Custom item — set price manually'
            : '';
        stockInfo.style.color = '#888';
    }
    updateRow(idx);
}

function updateRow(idx) {
    const row  = document.getElementById(`row_${idx}`);
    const price = parseFloat(row.querySelector('.price-input').value) || 0;
    const qty   = parseInt(row.querySelector('.qty-input').value)     || 0;
    const pid   = row.querySelector('.prod-id-hidden').value;
    const prod  = pid ? PRODUCTS.find(p => p.id == pid) : null;

    if (prod && qty > prod.stock) row.querySelector('.qty-input').value = prod.stock;
    const finalQty = parseInt(row.querySelector('.qty-input').value) || 0;
    const sub = price * finalQty;
    // Store AFN subtotal in data attr for summary calc
    row.dataset.subAfn = (sub * saleRate()).toFixed(2);
    row.querySelector('.subtotal-display').value = fmtSale(sub);
    updateSummary();
}

function removeRow(idx) {
    document.getElementById(`row_${idx}`)?.remove();
    if (!document.getElementById('itemsBody').querySelector('tr[id^="row_"]')) {
        const em = document.createElement('tr');
        em.id = 'emptyRow';
        em.innerHTML = `<td colspan="5" class="text-center text-muted py-4 small">${EMPTY_MSG}</td>`;
        document.getElementById('itemsBody').appendChild(em);
    }
    updateSummary();
}

function updateSummary() {
    // Total in AFN (sum of row AFN subtotals)
    let totalAfn = 0;
    document.querySelectorAll('#itemsBody tr[id^="row_"]').forEach(row => {
        totalAfn += parseFloat(row.dataset.subAfn) || 0;
    });

    const paidCur   = document.getElementById('paidCurrency').value;
    const paidInput = parseFloat(document.getElementById('paidAmount').value) || 0;
    const paidAfn   = paidInput * (ALL_RATES_INV[paidCur] || 1);
    const balance   = Math.max(0, totalAfn - paidAfn);

    // Summary in sale currency
    const r = saleRate();
    const totalSale = r > 0 ? totalAfn / r : totalAfn;
    document.getElementById('summaryTotal').textContent    = fmtSale(totalSale);
    document.getElementById('summaryTotalSec').textContent = fmtAFN_inv(totalAfn) + ' AFN';
    document.getElementById('paidAfnDisplay').textContent  = fmtAFN_inv(paidAfn);
    document.getElementById('summaryBalance').textContent  = fmtAFN_inv(balance);

    const hint = document.getElementById('paidConvert');
    if (paidCur !== 'AFN' && paidInput > 0) {
        const paidR = ALL_RATES_INV[paidCur] || 1;
        const sym   = CURRENCIES_INV[paidCur]?.symbol || paidCur;
        hint.style.display = '';
        hint.innerHTML = `${sym} ${paidInput.toLocaleString()} × ${paidR} = <strong>${fmtAFN_inv(paidAfn)}</strong>`;
    } else {
        hint.style.display = 'none';
    }
}

document.getElementById('addRow').addEventListener('click', addRow);

/* ──────────────────────────────────────────
   Solar Hijri ↔ Gregorian conversion
   shamsiToGregorian: jalaali-js algorithm (proven correct)
────────────────────────────────────────── */
function shamsiToGregorian(jy, jm, jd) {
    var breaks = [-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];
    var gy = jy + 621, leapJ = -14, jp = breaks[0], jm2, jump, n, i;
    for (i = 1; i < 20; i++) {
        jm2 = breaks[i]; jump = jm2 - jp;
        if (jy < jm2) break;
        leapJ += Math.floor(jump / 33) * 8 + Math.floor((jump % 33) / 4);
        jp = jm2;
    }
    n = jy - jp;
    leapJ += Math.floor(n / 33) * 8 + Math.floor((n % 33 + 3) / 4);
    if ((jump % 33) === 4 && (jump - n) === 4) leapJ++;
    var leapG = Math.floor(gy / 4) - Math.floor((Math.floor(gy / 100) + 1) * 3 / 4) - 150;
    var march = 20 + leapJ - leapG;
    if (jump - n < 6) n = n - jump + Math.floor((jump + 4) / 33) * 33; // n unused after here
    var dayOfYear = jm <= 6 ? (jm - 1) * 31 + jd : 186 + (jm - 7) * 30 + jd;
    var gDay = march + dayOfYear - 1, gMon = 3, gYr = gy;
    function dim(m, y) {
        if (m === 2) return ((y % 4 === 0 && y % 100 !== 0) || y % 400 === 0) ? 29 : 28;
        return [0,31,28,31,30,31,30,31,31,30,31,30,31][m];
    }
    while (gDay > dim(gMon, gYr)) { gDay -= dim(gMon, gYr); if (++gMon > 12) { gMon = 1; gYr++; } }
    return {y: gYr, m: gMon, d: gDay};
}

function syncShamsiDate() {
    var jy = parseInt(document.getElementById('jYear').value)  || 0;
    var jm = parseInt(document.getElementById('jMonth').value) || 0;
    var jd = parseInt(document.getElementById('jDay').value)   || 0;
    if (jy < 1300 || jy > 1600 || jm < 1 || jm > 12 || jd < 1 || jd > 31) return;
    var g = shamsiToGregorian(jy, jm, jd);
    if (!g) return;
    var gStr = g.y + '-' + String(g.m).padStart(2,'0') + '-' + String(g.d).padStart(2,'0');
    document.getElementById('saleDate').value = gStr;
    document.getElementById('gregorianBadge').textContent =
        '≡ ' + g.y + '/' + String(g.m).padStart(2,'0') + '/' + String(g.d).padStart(2,'0') + ' (Gregorian)';
}
syncShamsiDate();

/* ──────────────────────────────────────────
   Image upload with progress
────────────────────────────────────────── */
const MAX_IMGS    = 3;
const confirmedIds = []; // tracks successfully uploaded image card IDs

const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('imageFileInput');

dropZone.addEventListener('click', () => {
    if (confirmedIds.length < MAX_IMGS) fileInput.click();
});
dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', ()  => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    handleFiles(e.dataTransfer.files);
});
fileInput.addEventListener('change', () => { handleFiles(fileInput.files); fileInput.value = ''; });

function handleFiles(files) {
    var slots = MAX_IMGS - confirmedIds.length;
    Array.from(files).slice(0, slots).forEach(f => {
        if (f.type.startsWith('image/')) startUpload(f);
    });
}

function startUpload(file) {
    var id = 'ic_' + Date.now() + '_' + Math.random().toString(36).slice(2,6);

    /* Build preview card */
    var card = document.createElement('div');
    card.id        = id;
    card.className = 'img-card';
    card.innerHTML = `
        <div class="img-card-thumb">
            <img src="" alt="">
            <!-- uploading overlay -->
            <div class="img-uploading-ring" id="ring_${id}">
                <svg viewBox="0 0 38 38" stroke="#fff" fill="none">
                    <g fill="none" fill-rule="evenodd">
                        <circle stroke-opacity=".4" cx="19" cy="19" r="16" stroke-width="4"/>
                        <path d="M35 19c0-8.837-7.163-16-16-16" stroke-width="4" stroke-linecap="round"/>
                    </g>
                </svg>
            </div>
            <!-- progress bar -->
            <div class="img-progress-wrap" id="pw_${id}">
                <div class="img-progress-fill" id="pf_${id}"></div>
            </div>
            <!-- status badge (shown after finish) -->
            <div class="img-status-badge" id="sb_${id}" style="display:none;"></div>
            <!-- remove button -->
            <button type="button" class="img-remove-btn" id="rm_${id}"
                    onclick="removeImage('${id}')"><i class="bi bi-x"></i></button>
        </div>
        <div class="img-card-name">${file.name}</div>
    `;

    /* Show thumbnail immediately via FileReader */
    var fr = new FileReader();
    fr.onload = e => card.querySelector('img').src = e.target.result;
    fr.readAsDataURL(file);

    document.getElementById('imgPreviewGrid').appendChild(card);

    /* AJAX upload */
    var fd = new FormData();
    fd.append('image', file);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/sales/upload-temp-image.php');

    xhr.upload.addEventListener('progress', function(e) {
        if (!e.lengthComputable) return;
        var pct = (e.loaded / e.total * 100).toFixed(1);
        document.getElementById('pf_' + id).style.width = pct + '%';
    });

    xhr.addEventListener('load', function() {
        /* hide ring */
        var ring = document.getElementById('ring_' + id);
        if (ring) ring.style.display = 'none';
        /* fill bar to 100% */
        var pf = document.getElementById('pf_' + id);
        if (pf) pf.style.width = '100%';

        try {
            var res = JSON.parse(xhr.responseText);
            if (res.success) {
                /* fade out progress bar */
                setTimeout(function() {
                    var pw = document.getElementById('pw_' + id);
                    if (pw) pw.style.display = 'none';
                }, 400);

                /* green check badge */
                var sb = document.getElementById('sb_' + id);
                sb.className = 'img-status-badge ok';
                sb.innerHTML = '<i class="bi bi-check-lg" style="font-size:0.7rem;"></i>';
                sb.style.display = 'flex';

                /* show remove button */
                document.getElementById('rm_' + id).style.display = 'flex';

                /* register hidden input */
                var hi = document.createElement('input');
                hi.type  = 'hidden';
                hi.name  = 'uploaded_images[]';
                hi.value = res.filename;
                hi.id    = 'hi_' + id;
                document.getElementById('imgHiddenInputs').appendChild(hi);

                confirmedIds.push(id);
                refreshDropZone();
            } else {
                showUploadError(id, res.error || 'Upload failed');
            }
        } catch(err) {
            showUploadError(id, 'Server error');
        }
    });

    xhr.addEventListener('error', function() { showUploadError(id, 'Network error'); });
    xhr.send(fd);
}

function showUploadError(id, msg) {
    var ring = document.getElementById('ring_' + id);
    if (ring) ring.style.display = 'none';
    var sb = document.getElementById('sb_' + id);
    if (sb) {
        sb.className     = 'img-status-badge error';
        sb.innerHTML     = '<i class="bi bi-x-lg" style="font-size:0.65rem;"></i>';
        sb.style.display = 'flex';
        sb.title         = msg;
    }
    /* still show remove so user can dismiss */
    var rm = document.getElementById('rm_' + id);
    if (rm) rm.style.display = 'flex';
}

function removeImage(id) {
    document.getElementById(id)?.remove();
    document.getElementById('hi_' + id)?.remove();
    var idx = confirmedIds.indexOf(id);
    if (idx > -1) confirmedIds.splice(idx, 1);
    refreshDropZone();
}

function refreshDropZone() {
    var dz        = document.getElementById('dropZone');
    var remaining = MAX_IMGS - confirmedIds.length;
    var dzRemEl   = document.getElementById('dzRemaining');
    if (remaining <= 0) {
        dz.classList.add('maxed');
        if (dzRemEl) dzRemEl.textContent = '(limit reached)';
    } else {
        dz.classList.remove('maxed');
        if (dzRemEl) dzRemEl.textContent = '(' + remaining + ' remaining)';
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
