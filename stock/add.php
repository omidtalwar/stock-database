<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = __('stock_move_title');

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
    "ALTER TABLE stock_logs ADD COLUMN bill_no        VARCHAR(100)  NULL",
    "ALTER TABLE stock_logs ADD COLUMN currency       VARCHAR(10)   NULL DEFAULT 'AFN'",
    "ALTER TABLE stock_logs MODIFY quantity DECIMAL(10,3) NOT NULL DEFAULT 0",
] as $_sql) { try { $pdo->exec($_sql); } catch (\PDOException $e) {} }

$products       = $pdo->query("SELECT id, name, size, color, quantity FROM products ORDER BY name ASC")->fetchAll();
$knownSuppliers = $pdo->query("SELECT DISTINCT supplier FROM stock_logs WHERE supplier IS NOT NULL AND supplier != '' ORDER BY supplier ASC")->fetchAll(PDO::FETCH_COLUMN);
$preSupplier    = trim($_GET['supplier'] ?? '');
$secCur         = getSecondaryCurrency($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Duplicate-submission guard
    if (!validateFormToken('stock_add')) {
        $_SESSION['error'] = 'Duplicate submission detected. Your data was already saved.';
        header('Location: index.php'); exit;
    }

    $type      = in_array($_POST['type'] ?? '', ['in','out']) ? $_POST['type'] : 'in';
    $currency  = array_key_exists($_POST['currency'] ?? '', CURRENCIES) ? $_POST['currency'] : 'AFN';
    $supplier  = trim($_POST['supplier']   ?? '');
    $billNo    = trim($_POST['bill_no']    ?? '');
    $notes     = trim($_POST['notes']      ?? '');
    $billImage = trim($_POST['bill_image'] ?? '');
    $paidTotal = (float)($_POST['paid_amount'] ?? 0);

    $customProds  = $_POST['custom_product'] ?? [];
    $productIds   = $_POST['product_id']     ?? [];
    $quantities   = $_POST['quantity']       ?? [];
    $bundleCounts = $_POST['bundle_count']   ?? [];
    $pricingTypes = $_POST['pricing_type']   ?? [];
    $unitPrices   = $_POST['unit_price']     ?? [];
    $rowTotals    = $_POST['total_amount']   ?? [];

    $errors = [];
    $rows   = [];
    $n      = count($quantities);

    for ($i = 0; $i < $n; $i++) {
        $pid        = (int)($productIds[$i]  ?? 0);
        $customProd = trim($customProds[$i]  ?? '');
        $qty        = (float)($quantities[$i]  ?? 0);
        $bundle     = (int)($bundleCounts[$i] ?? 0);
        $pricing    = in_array($pricingTypes[$i] ?? '', ['per_pcs','per_bundle']) ? $pricingTypes[$i] : 'per_pcs';
        $unitPrice  = (float)($unitPrices[$i] ?? 0);
        $rowTotal   = (float)($rowTotals[$i]  ?? 0);

        if (!$pid && $customProd === '') $customProd = 'Stock';

        // Skip completely empty rows silently
        if ($qty <= 0 && $rowTotal <= 0 && $unitPrice <= 0) continue;

        if ($type === 'out' && $pid && $qty > 0) {
            $currQty = (int)$pdo->query("SELECT quantity FROM products WHERE id=$pid")->fetchColumn();
            if ($qty > $currQty) $errors[] = "Row ".($i+1).": not enough stock (available: $currQty pcs).";
        }

        $rows[] = compact('pid','customProd','qty','bundle','pricing','unitPrice','rowTotal');
    }

    if (empty($rows) && empty($errors)) $errors[] = 'Add at least one product row.';

    if (empty($errors)) {
        $grandTotal = array_sum(array_column($rows, 'rowTotal'));

        // Payment is tracked on the first row only (not split across rows).
        // All other rows get paid=0, balance=0.
        // Aggregates: SUM(total_amount)=grandTotal, SUM(paid)=paidTotal, SUM(balance)=grandTotal-paidTotal ✓
        $isFirst = true;

        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                if ($isFirst) {
                    $rowPaid    = $paidTotal;
                    $rowBalance = max(0, $grandTotal - $paidTotal);
                    $isFirst    = false;
                } else {
                    $rowPaid    = 0;
                    $rowBalance = 0;
                }

                if ($r['pid']) {
                    $op = $type === 'in' ? 'quantity + ?' : 'quantity - ?';
                    $pdo->prepare("UPDATE products SET quantity = $op WHERE id=?")->execute([$r['qty'], $r['pid']]);
                }

                $pdo->prepare("
                    INSERT INTO stock_logs
                        (product_id, custom_product, type, quantity, bundle_count, pricing_type,
                         unit_price, total_amount, paid_amount, balance,
                         supplier, bill_no, notes, bill_image, currency, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $r['pid'] ?: null, $r['customProd'] ?: null, $type, $r['qty'],
                    $r['bundle'] ?: null, $r['pricing'], $r['unitPrice'],
                    $r['rowTotal'], $rowPaid, $rowBalance,
                    $supplier ?: null, $billNo ?: null, $notes ?: null, $billImage ?: null, $currency, $_SESSION['user_id'],
                ]);
            }
            $pdo->commit();
            $_SESSION['success'] = count($rows).' stock record(s) saved.';
            header('Location: index.php'); exit;
        } catch (\Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Save failed: '.$e->getMessage();
        }
    }

    if (!empty($errors)) $_SESSION['error'] = implode('<br>', $errors);
}

$prodJs = array_map(fn($p) => [
    'id'    => $p['id'],
    'label' => $p['name'].($p['size'] ? ' ('.$p['size'].')' : '').($p['color'] ? ' - '.$p['color'] : ''),
    'stock' => (int)$p['quantity'],
], $products);

$formToken = generateFormToken('stock_add');
require_once '../includes/header.php';
?>

<style>
.row-total-auto {
    background: rgba(0,103,192,0.04) !important;
    border-color: rgba(0,103,192,0.2) !important;
}
#prodRows tr td { vertical-align: middle; padding: 6px 5px; }
#prodRows tr td:first-child { padding-left: 10px; }
.bill-drop-zone {
    border: 2px dashed rgba(0,103,192,0.3); border-radius: 10px;
    padding: 18px 10px; text-align: center; cursor: pointer;
    background: rgba(0,103,192,0.02); font-size: 0.8rem; user-select: none;
    transition: border-color .2s, background .2s;
}
.bill-drop-zone:hover, .bill-drop-zone.drag-over { border-color:#0067C0; background:rgba(0,103,192,0.07); }
.bill-drop-zone.has-file { border-style: solid; border-color:rgba(16,124,16,0.4); background:rgba(16,124,16,0.04); }
.bill-thumb { width:100%; max-height:120px; object-fit:contain; border-radius:6px; margin-top:8px; display:none; }
</style>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <a href="index.php" class="text-muted small"><i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_stock') ?></a>
        <h4 class="mt-1 mb-0"><?= __('stock_move_title') ?></h4>
    </div>
</div>

<form method="POST" id="stockForm">
<input type="hidden" name="_form_token" value="<?= htmlspecialchars($formToken) ?>">

    <!-- ── Supplier + Currency + Type ── -->
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-3 align-items-end">
                <div class="col-sm-4">
                    <label class="form-label fw-semibold mb-1">Supplier / Wholesaler <span class="text-muted fw-normal small">(optional)</span></label>
                    <input list="supplier-list" type="text" name="supplier" class="form-control"
                           placeholder="e.g. Ahmed Traders…" autocomplete="off"
                           value="<?= htmlspecialchars($_POST['supplier'] ?? $preSupplier) ?>">
                    <datalist id="supplier-list">
                        <?php foreach ($knownSuppliers as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-sm-3">
                    <label class="form-label fw-semibold mb-1"><i class="bi bi-receipt me-1 text-primary"></i>Bill No. <span class="text-muted fw-normal small">(optional)</span></label>
                    <input type="text" name="bill_no" class="form-control"
                           placeholder="e.g. INV-2024-001"
                           value="<?= htmlspecialchars($_POST['bill_no'] ?? '') ?>">
                </div>
                <div class="col-sm-2">
                    <label class="form-label fw-semibold mb-1"><i class="bi bi-currency-exchange me-1 text-primary"></i>Currency</label>
                    <select name="currency" id="currencySelect" class="form-select" onchange="onCurrencyChange()">
                        <?php foreach (CURRENCIES as $code => $cur): ?>
                        <option value="<?= $code ?>" <?= ($code === ($_POST['currency'] ?? 'AFN')) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cur['symbol'] . ' ' . $code) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label fw-semibold mb-1"><?= __('field_type') ?></label>
                    <div class="d-flex gap-4 pt-1">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="type" id="typeIn" value="in"
                                   <?= ($_POST['type'] ?? 'in') === 'in' ? 'checked' : '' ?> onchange="onTypeChange()">
                            <label class="form-check-label fw-semibold text-success" for="typeIn">
                                <i class="bi bi-arrow-down-circle me-1"></i>Incoming
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="type" id="typeOut" value="out"
                                   <?= ($_POST['type'] ?? '') === 'out' ? 'checked' : '' ?> onchange="onTypeChange()">
                            <label class="form-check-label fw-semibold text-danger" for="typeOut">
                                <i class="bi bi-arrow-up-circle me-1"></i>Outgoing
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Products ── -->
    <div class="card mb-3">
        <div class="card-header fw-semibold d-flex align-items-center justify-content-between py-2">
            <span><i class="bi bi-list-ul me-2" style="color:#0067C0;"></i>Products</span>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addRow()">
                <i class="bi bi-plus-lg me-1"></i>Add Product
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:0.82rem;min-width:700px;">
                <thead class="table-light">
                    <tr>
                        <th style="width:30%;">Product <span class="text-muted fw-normal" style="font-size:.75rem;">(optional — defaults to "Stock")</span></th>
                        <th style="width:11%;">Qty (pcs)</th>
                        <th style="width:10%;">Bundles</th>
                        <th style="width:12%;">Pricing</th>
                        <th style="width:14%;">Unit Price</th>
                        <th style="width:14%;">Total <span class="text-muted fw-normal" style="font-size:.72rem;">(auto or direct)</span></th>
                        <th style="width:36px;"></th>
                    </tr>
                </thead>
                <tbody id="prodRows"></tbody>
                <tfoot>
                    <tr style="background:rgba(0,103,192,0.04);">
                        <td colspan="5" class="text-end fw-semibold pe-3 py-2">Grand Total</td>
                        <td class="fw-bold text-primary py-2" id="grandTotalDisplay">؋ 0</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <input type="hidden" id="grandTotalHidden" name="_grand_total" value="0">
        <!-- Inline datalists (inside form for full browser compatibility) -->
        <datalist id="prod-list-inline">
            <?php foreach ($prodJs as $p): ?>
            <option value="<?= htmlspecialchars($p['label']) ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </div>

    <!-- ── Payment + Notes + Bill ── -->
    <div class="row g-3 mb-3">

        <!-- Payment -->
        <div class="col-md-4" id="paymentCard">
            <div class="card h-100">
                <div class="card-header fw-semibold py-2 d-flex align-items-center gap-2">
                    <i class="bi bi-cash-coin" style="color:#107C10;"></i> Payment
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Total</span>
                        <span class="fw-bold" id="summTotal">؋ 0</span>
                    </div>
                    <hr class="my-2">
                    <label class="form-label fw-semibold small mb-1">Paid Amount <span class="text-muted fw-normal">(optional)</span></label>
                    <div class="input-group input-group-sm mb-3">
                        <span class="input-group-text"><span class="cur-sym">؋</span></span>
                        <input type="number" name="paid_amount" id="paidAmount" class="form-control"
                               min="0" step="any" placeholder="0" oninput="calcBalance()"
                               value="<?= htmlspecialchars($_POST['paid_amount'] ?? '0') ?>">
                    </div>
                    <div class="d-flex justify-content-between align-items-center p-2 rounded"
                         style="background:rgba(196,43,28,0.06);border:1px solid rgba(196,43,28,0.15);">
                        <span class="fw-semibold small">Unpaid / Balance</span>
                        <span class="fw-bold text-danger" id="summBalance">؋ 0</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header fw-semibold py-2 d-flex align-items-center gap-2">
                    <i class="bi bi-sticky" style="color:#9D5D00;"></i>
                    Notes <span class="text-muted fw-normal small">(optional)</span>
                </div>
                <div class="card-body">
                    <textarea name="notes" class="form-control form-control-sm h-100" rows="4"
                              placeholder="e.g. Bought from market, good quality…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Bill upload -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header fw-semibold py-2 d-flex align-items-center gap-2">
                    <i class="bi bi-file-earmark-image" style="color:#0067C0;"></i>
                    Bill / Receipt <span class="text-muted fw-normal small">(optional)</span>
                </div>
                <div class="card-body d-flex flex-column">
                    <div id="billDrop" class="bill-drop-zone flex-grow-1 d-flex flex-column align-items-center justify-content-center">
                        <i class="bi bi-cloud-upload fs-4 text-primary mb-1"></i>
                        <div id="billDropText">Drop or <strong>click to browse</strong></div>
                        <div id="billStatus" class="text-muted small mt-1"></div>
                        <img id="billThumb" class="bill-thumb" alt="Bill preview">
                    </div>
                    <input type="file" id="billFileInput" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;">
                    <input type="hidden" name="bill_image" id="billImageVal" value="<?= htmlspecialchars($_POST['bill_image'] ?? '') ?>">
                </div>
            </div>
        </div>

    </div>

    <!-- Submit -->
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4 fw-semibold">
            <i class="bi bi-check-circle me-2"></i><?= __('stock_save') ?>
        </button>
        <a href="index.php" class="btn btn-light"><?= __('btn_cancel') ?></a>
    </div>

</form>

<script>
const PRODS    = <?= json_encode($prodJs) ?>;
const PROD_MAP = {};
PRODS.forEach(p => { PROD_MAP[p.label] = p; });

const CURRENCIES = <?= json_encode(array_map(fn($c) => ['symbol' => $c['symbol'], 'decimals' => $c['decimals']], CURRENCIES)) ?>;

let rowIdx = 0;

function curSym() {
    const sel = document.getElementById('currencySelect');
    return sel ? (CURRENCIES[sel.value]?.symbol || '؋') : '؋';
}
function curDec() {
    const sel = document.getElementById('currencySelect');
    return sel ? (CURRENCIES[sel.value]?.decimals ?? 0) : 0;
}
function fmtMoney(amount) {
    const dec = curDec();
    return curSym() + ' ' + amount.toLocaleString('en-US', {minimumFractionDigits: dec, maximumFractionDigits: dec});
}

function onCurrencyChange() {
    const sym = curSym();
    document.querySelectorAll('.cur-sym').forEach(el => el.textContent = sym);
    calcGrandTotal();
}

function rowHTML(idx) {
    const sym = curSym();
    return '<td style="min-width:200px;">'
        + '<input list="prod-list-inline" name="custom_product[]"'
        + ' class="form-control form-control-sm prod-input"'
        + ' placeholder="Product name (leave empty for Stock)"'
        + ' autocomplete="off" oninput="matchProd(this)">'
        + '<input type="hidden" name="product_id[]" class="prod-id" value="">'
        + '<div class="prod-info text-muted" style="font-size:0.7rem;min-height:14px;"></div>'
        + '</td>'
        + '<td style="min-width:100px;">'
        + '<div class="input-group input-group-sm">'
        + '<input type="number" name="quantity[]" class="form-control row-qty" min="0" step="any" placeholder="0" oninput="calcRowTotal(this.closest(\'tr\'))">'
        + '<span class="input-group-text" style="font-size:.72rem;">pcs</span>'
        + '</div></td>'
        + '<td style="min-width:80px;">'
        + '<input type="number" name="bundle_count[]" class="form-control form-control-sm row-bundles" min="0" placeholder="—" oninput="calcRowTotal(this.closest(\'tr\'))">'
        + '</td>'
        + '<td style="min-width:105px;">'
        + '<select name="pricing_type[]" class="form-select form-select-sm row-pricing" onchange="calcRowTotal(this.closest(\'tr\'))">'
        + '<option value="per_pcs">/ pcs</option>'
        + '<option value="per_bundle">/ bundle</option>'
        + '</select></td>'
        + '<td style="min-width:110px;">'
        + '<div class="input-group input-group-sm">'
        + '<span class="input-group-text"><span class="cur-sym">' + sym + '</span></span>'
        + '<input type="number" name="unit_price[]" class="form-control row-price" min="0" step="any" placeholder="0" oninput="calcRowTotal(this.closest(\'tr\'))">'
        + '</div></td>'
        + '<td style="min-width:120px;">'
        + '<div class="input-group input-group-sm">'
        + '<span class="input-group-text"><span class="cur-sym">' + sym + '</span></span>'
        + '<input type="number" name="total_amount[]" class="form-control row-total" min="0" step="any" placeholder="Total" oninput="onRowTotalManual(this.closest(\'tr\'))">'
        + '</div>'
        + '<div class="row-total-hint text-muted" style="font-size:.68rem;min-height:14px;margin-top:1px;"></div>'
        + '</td>'
        + '<td><button type="button" class="btn btn-sm btn-light text-danger px-2" onclick="removeRow(this)" title="Remove"><i class="bi bi-x-lg"></i></button></td>';
}

function addRow() {
    rowIdx++;
    document.getElementById('prodRows').insertAdjacentHTML('beforeend', '<tr>' + rowHTML(rowIdx) + '</tr>');
}

function removeRow(btn) {
    const rows = document.querySelectorAll('#prodRows tr');
    if (rows.length <= 1) return;
    btn.closest('tr').remove();
    calcGrandTotal();
}

function matchProd(input) {
    const tr   = input.closest('tr');
    const val  = input.value.trim();
    const prod = PROD_MAP[val] || null;
    const info = tr.querySelector('.prod-info');
    tr.querySelector('.prod-id').value = prod ? prod.id : '';
    info.innerHTML = prod
        ? `<i class="bi bi-archive me-1"></i>Stock: <b>${prod.stock}</b> pcs`
        : (val ? '<i class="bi bi-pencil me-1"></i>Custom product' : '');
    info.style.color = prod ? '' : '#888';
}

function calcRowTotal(tr) {
    if (tr.dataset.manualTotal) { calcGrandTotal(); return; }
    const qty     = parseFloat(tr.querySelector('.row-qty').value)     || 0;
    const bundles = parseFloat(tr.querySelector('.row-bundles').value)  || 0;
    const price   = parseFloat(tr.querySelector('.row-price').value)  || 0;
    const pricing = tr.querySelector('.row-pricing').value;

    let total = 0;
    if (pricing === 'per_bundle' && bundles > 0) total = bundles * price;
    else if (pricing === 'per_pcs' && qty > 0)   total = qty * price;

    const totalInput = tr.querySelector('.row-total');
    totalInput.value = total > 0 ? total.toFixed(curDec()) : '';
    totalInput.classList.toggle('row-total-auto', total > 0);
    const hint = tr.querySelector('.row-total-hint');
    if (hint) hint.innerHTML = total > 0 ? '<i class="bi bi-lightning-charge"></i> auto-calculated' : 'enter total directly';
    calcGrandTotal();
}

function onRowTotalManual(tr) {
    tr.dataset.manualTotal = '1';
    tr.querySelector('.row-total').classList.remove('row-total-auto');
    const hint = tr.querySelector('.row-total-hint');
    if (hint) hint.innerHTML = '<a href="#" class="text-primary" style="text-decoration:none;" onclick="resetRowTotal(this.closest(\'tr\'));return false;">↺ back to auto</a>';
    calcGrandTotal();
}

function resetRowTotal(tr) {
    delete tr.dataset.manualTotal;
    tr.querySelector('.row-total').value = '';
    calcRowTotal(tr);
}

function calcGrandTotal() {
    let grand = 0;
    document.querySelectorAll('.row-total').forEach(el => { grand += parseFloat(el.value) || 0; });
    document.getElementById('grandTotalDisplay').textContent = fmtMoney(grand);
    document.getElementById('grandTotalHidden').value = grand.toFixed(2);
    calcBalance();
}

function calcBalance() {
    const grand = parseFloat(document.getElementById('grandTotalHidden').value) || 0;
    const paid  = parseFloat(document.getElementById('paidAmount').value)       || 0;
    const bal   = Math.max(0, grand - paid);
    document.getElementById('summTotal').textContent   = fmtMoney(grand);
    document.getElementById('summBalance').textContent = fmtMoney(bal);
}

function onTypeChange() {
    const isIn = document.getElementById('typeIn').checked;
    document.getElementById('paymentCard').style.display = isIn ? '' : 'none';
}

// ── Bill upload ──
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
billInput.addEventListener('change', () => { if (billInput.files[0]) uploadBill(billInput.files[0]); billInput.value=''; });

function uploadBill(file) {
    billStatus.textContent = 'Uploading…'; billStatus.style.color = '';
    billDrop.classList.remove('has-file');
    const fr = new FileReader();
    fr.onload = e => { billThumb.src = e.target.result; billThumb.style.display = 'block'; };
    fr.readAsDataURL(file);
    const fd = new FormData(); fd.append('image', file);
    fetch('/stock/upload-bill.php', {method:'POST',body:fd})
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

// Init
onTypeChange();
addRow();
onCurrencyChange();

// Prevent duplicate submission
document.getElementById('stockForm').addEventListener('submit', function() {
    const btn = this.querySelector('[type=submit]');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Saving…';
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
