<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = 'New Invoice';

$settings    = getSettings($pdo);
$rate        = (float)($settings['exchange_rate']      ?? 90);
$secCur      = $settings['secondary_currency'] ?? 'USD';
$secSymbol   = currencySymbol($secCur);

$customers = $pdo->query("SELECT id, name, shop_name FROM customers ORDER BY name ASC")->fetchAll();
$products  = $pdo->query("SELECT id, name, size, color, price, quantity FROM products WHERE quantity > 0 ORDER BY name ASC")->fetchAll();

// Pre-select customer if passed
$preCustomer = (int)($_GET['customer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id   = (int)($_POST['customer_id'] ?? 0);
    $paid_currency = strtoupper(trim($_POST['paid_currency'] ?? 'AFN'));
    if (!array_key_exists($paid_currency, CURRENCIES)) $paid_currency = 'AFN';
    $paid_amount_input = (float)($_POST['paid_amount'] ?? 0);
    $paid_amount       = toAFN($paid_amount_input, $paid_currency, $rate); // always store AFN
    $notes             = trim($_POST['notes'] ?? '');
    $items       = $_POST['items'] ?? [];

    $errors = [];
    if (!$customer_id) $errors[] = 'Select a customer.';
    if (empty($items))  $errors[] = 'Add at least one product.';

    $lineItems = [];
    $total     = 0;
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['quantity'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $prod = null;
        foreach ($products as $p) { if ($p['id'] == $pid) { $prod = $p; break; } }
        if (!$prod) { $errors[] = "Invalid product selected."; break; }
        if ($qty > $prod['quantity']) { $errors[] = "Not enough stock for {$prod['name']} ({$prod['size']}). Available: {$prod['quantity']}"; break; }
        $sub    = $qty * $prod['price'];
        $total += $sub;
        $lineItems[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $prod['price'], 'subtotal' => $sub];
    }

    if (empty($lineItems) && empty($errors)) $errors[] = 'Add at least one valid product.';

    if (empty($errors)) {
        $balance = max(0, $total - $paid_amount);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO sales (customer_id, total_amount, paid_amount, balance, notes, created_by) VALUES (?,?,?,?,?,?)")
                ->execute([$customer_id, $total, $paid_amount, $balance, $notes, $_SESSION['user_id']]);
            $saleId = $pdo->lastInsertId();

            foreach ($lineItems as $li) {
                $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?,?,?,?,?)")
                    ->execute([$saleId, $li['product_id'], $li['quantity'], $li['unit_price'], $li['subtotal']]);
                $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?")->execute([$li['quantity'], $li['product_id']]);
                $pdo->prepare("INSERT INTO stock_logs (product_id, type, quantity, notes, created_by) VALUES (?, 'out', ?, ?, ?)")
                    ->execute([$li['product_id'], $li['quantity'], "Sale #".str_pad($saleId,4,'0',STR_PAD_LEFT), $_SESSION['user_id']]);
            }

            // Update customer debt
            $pdo->prepare("UPDATE customers SET total_debt = total_debt + ? WHERE id = ?")->execute([$balance, $customer_id]);
            $pdo->commit();
            $_SESSION['success'] = "Invoice #".str_pad($saleId,4,'0',STR_PAD_LEFT)." created successfully.";
            header("Location: view.php?id=$saleId");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Failed to save invoice. Please try again.';
        }
    }
    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
    }
}

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <a href="index.php" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Sales</a>
        <h4 class="mt-1 mb-0">Create New Invoice</h4>
    </div>
</div>

<form method="POST" id="invoiceForm">
<div class="row g-3">
    <!-- Left: Invoice builder -->
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header fw-semibold">Select Customer</div>
            <div class="card-body">
                <select name="customer_id" class="form-select" required>
                    <option value="">— Choose a customer —</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= ($preCustomer == $c['id'] || ($_POST['customer_id'] ?? 0) == $c['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['shop_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span class="fw-semibold">Products</span>
                <button type="button" class="btn btn-sm btn-primary" id="addRow">
                    <i class="bi bi-plus-circle me-1"></i>Add Product
                </button>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle mb-0" id="itemsTable">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="width:110px;">Qty</th>
                            <th style="width:110px;">Unit Price</th>
                            <th style="width:110px;">Subtotal</th>
                            <th style="width:46px;"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        <tr id="emptyRow"><td colspan="5" class="text-center text-muted py-4 small">Click "Add Product" to start adding items</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right: Summary -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold">Invoice Summary</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Total (؋ AFN)</span>
                    <span class="fw-semibold" id="summaryTotal">؋ 0</span>
                </div>
                <div class="d-flex justify-content-between mb-3 text-muted small">
                    <span>≈ <?= htmlspecialchars($secCur) ?></span>
                    <span id="summaryTotalSec">—</span>
                </div>
                <hr>
                <div class="mb-2">
                    <label class="form-label fw-semibold">Amount Paid Now</label>
                    <div class="input-group input-group-sm">
                        <select name="paid_currency" id="paidCurrency" class="form-select" style="max-width:100px;" onchange="updateSummary()">
                            <option value="AFN" <?= ($_POST['paid_currency'] ?? 'AFN') === 'AFN' ? 'selected' : '' ?>>؋ AFN</option>
                            <option value="<?= htmlspecialchars($secCur) ?>" <?= ($_POST['paid_currency'] ?? '') === $secCur ? 'selected' : '' ?>>
                                <?= htmlspecialchars($secSymbol) ?> <?= htmlspecialchars($secCur) ?>
                            </option>
                        </select>
                        <input type="number" name="paid_amount" id="paidAmount" class="form-control"
                               min="0" step="0.01" value="<?= htmlspecialchars($_POST['paid_amount'] ?? '0') ?>"
                               oninput="updateSummary()">
                    </div>
                    <div class="text-muted small mt-1" id="paidConvert" style="display:none;"></div>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted small">Paid (AFN)</span>
                    <span class="text-success small fw-semibold" id="paidAfnDisplay">؋ 0</span>
                </div>
                <div class="d-flex justify-content-between mb-3">
                    <span class="fw-semibold">Remaining Debt</span>
                    <span class="fw-bold text-danger" id="summaryBalance">؋ 0</span>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-100 fw-semibold">
                    <i class="bi bi-check-circle me-2"></i>Save Invoice
                </button>
            </div>
        </div>

        <div class="card mt-2">
            <div class="card-body py-2 small text-muted">
                <i class="bi bi-currency-exchange me-1"></i>
                Rate: <strong>1 <?= htmlspecialchars($secCur) ?> = <?= number_format($rate, 2) ?> ؋</strong>
                <?php if (isAdmin()): ?>&nbsp;<a href="/fzl/admin/settings.php">Update</a><?php endif; ?>
            </div>
        </div>
    </div>
</div>
</form>

<!-- Product data for JS -->
<script>
const PRODUCTS = <?= json_encode(array_map(fn($p) => [
    'id'       => $p['id'],
    'label'    => $p['name'] . ($p['size'] ? ' ('.$p['size'].')' : '') . ($p['color'] ? ' - '.$p['color'] : ''),
    'price'    => (float)$p['price'],
    'stock'    => (int)$p['quantity'],
], $products)) ?>;

let rowCount = 0;

function buildProductOptions(selectedId = '') {
    let opts = '<option value="">— Select Product —</option>';
    PRODUCTS.forEach(p => {
        const sel = p.id == selectedId ? 'selected' : '';
        opts += `<option value="${p.id}" data-price="${p.price}" data-stock="${p.stock}" ${sel}>${p.label} [${p.stock} pcs]</option>`;
    });
    return opts;
}

function addRow(pid = '', qty = 1) {
    document.getElementById('emptyRow')?.remove();
    rowCount++;
    const idx = rowCount;
    const row = document.createElement('tr');
    row.id = `row_${idx}`;
    row.innerHTML = `
        <td>
            <select name="items[${idx}][product_id]" class="form-select form-select-sm product-select" onchange="updateRow(${idx})" required>
                ${buildProductOptions(pid)}
            </select>
        </td>
        <td><input type="number" name="items[${idx}][quantity]" class="form-control form-control-sm qty-input" value="${qty}" min="1" oninput="updateRow(${idx})" required></td>
        <td><input type="text" class="form-control form-control-sm price-display" readonly value="؋ 0"></td>
        <td><input type="text" class="form-control form-control-sm subtotal-display fw-semibold" readonly value="؋ 0"></td>
        <td><button type="button" class="btn btn-sm btn-light text-danger" onclick="removeRow(${idx})"><i class="bi bi-x-lg"></i></button></td>
    `;
    document.getElementById('itemsBody').appendChild(row);
    if (pid) updateRow(idx);
}

function updateRow(idx) {
    const row = document.getElementById(`row_${idx}`);
    const sel = row.querySelector('.product-select');
    const opt = sel.options[sel.selectedIndex];
    const price = parseFloat(opt?.dataset.price || 0);
    const stock = parseInt(opt?.dataset.stock || 0);
    const qty   = parseInt(row.querySelector('.qty-input').value || 0);
    if (qty > stock && stock > 0) row.querySelector('.qty-input').value = stock;
    const finalQty = Math.min(qty, stock);
    const sub = price * finalQty;
    row.querySelector('.price-display').value = '؋ ' + price.toLocaleString('en-AF', {maximumFractionDigits:0});
    row.querySelector('.subtotal-display').value = '؋ ' + sub.toLocaleString('en-AF', {maximumFractionDigits:0});
    updateSummary();
}

function removeRow(idx) {
    document.getElementById(`row_${idx}`)?.remove();
    if (!document.getElementById('itemsBody').querySelector('tr:not(#emptyRow)')) {
        const em = document.createElement('tr');
        em.id = 'emptyRow';
        em.innerHTML = '<td colspan="5" class="text-center text-muted py-4 small">Click "Add Product" to start adding items</td>';
        document.getElementById('itemsBody').appendChild(em);
    }
    updateSummary();
}

const RATE_INV   = <?= $rate ?>;
const SEC_CUR_INV = <?= json_encode($secCur) ?>;
const SEC_SYM_INV = <?= json_encode($secSymbol) ?>;

function fmtAFN_inv(v)  { return '؋ ' + parseFloat(v).toLocaleString('en-AF', {maximumFractionDigits:0}); }
function fmtSec_inv(v)  { return SEC_SYM_INV + ' ' + parseFloat(v).toLocaleString('en-US', {minimumFractionDigits:2,maximumFractionDigits:2}); }

function updateSummary() {
    let total = 0;
    document.querySelectorAll('.subtotal-display').forEach(el => {
        total += parseFloat(el.value.replace('؋ ', '').replace(/,/g, '')) || 0;
    });

    const cur       = document.getElementById('paidCurrency').value;
    const paidInput = parseFloat(document.getElementById('paidAmount').value || 0);
    const paidAfn   = cur === 'AFN' ? paidInput : paidInput * RATE_INV;
    const balance   = Math.max(0, total - paidAfn);

    document.getElementById('summaryTotal').textContent    = fmtAFN_inv(total);
    document.getElementById('summaryTotalSec').textContent = fmtSec_inv(RATE_INV > 0 ? total / RATE_INV : 0);
    document.getElementById('paidAfnDisplay').textContent  = fmtAFN_inv(paidAfn);
    document.getElementById('summaryBalance').textContent  = fmtAFN_inv(balance);

    const hint = document.getElementById('paidConvert');
    if (cur !== 'AFN' && paidInput > 0) {
        hint.style.display = '';
        hint.innerHTML = fmtSec_inv(paidInput) + ' × ' + RATE_INV.toLocaleString() + ' = <strong>' + fmtAFN_inv(paidAfn) + '</strong>';
    } else {
        hint.style.display = 'none';
    }
}

document.getElementById('addRow').addEventListener('click', () => addRow());

// Init with one row
addRow();
</script>

<?php require_once '../includes/footer.php'; ?>
