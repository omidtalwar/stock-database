<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = 'Record Payment';

$settings    = getSettings($pdo);
$rate        = (float)($settings['exchange_rate']      ?? 90);
$secCur      = $settings['secondary_currency'] ?? 'USD';
$secSymbol   = currencySymbol($secCur);

$customers   = $pdo->query("SELECT id, name, shop_name, total_debt FROM customers WHERE total_debt > 0 ORDER BY name ASC")->fetchAll();
$preCustomer = (int)($_GET['customer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $amount      = (float)($_POST['amount'] ?? 0);
    $currency    = strtoupper(trim($_POST['currency'] ?? 'AFN'));
    $notes       = trim($_POST['notes'] ?? '');

    if (!array_key_exists($currency, CURRENCIES)) $currency = 'AFN';

    if (!$customer_id) {
        $_SESSION['error'] = 'Select a customer.';
    } elseif ($amount <= 0) {
        $_SESSION['error'] = 'Amount must be greater than 0.';
    } else {
        $usedRate  = $currency === 'AFN' ? 1.0 : $rate;
        $amountAfn = toAFN($amount, $currency, $rate);

        $stmt = $pdo->prepare("SELECT total_debt FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $debt = (float)$stmt->fetchColumn();

        if ($amountAfn > $debt + 0.01) {
            $_SESSION['error'] = 'Payment (' . formatAFN($amountAfn) . ') exceeds current debt (' . formatAFN($debt) . ').';
        } else {
            $pdo->prepare("INSERT INTO payments (customer_id, amount, currency, exchange_rate, amount_afn, notes, created_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$customer_id, $amount, $currency, $usedRate, $amountAfn, $notes, $_SESSION['user_id']]);
            $pdo->prepare("UPDATE customers SET total_debt = GREATEST(0, total_debt - ?) WHERE id = ?")
                ->execute([$amountAfn, $customer_id]);

            $_SESSION['success'] = 'Payment of ' . formatMoney($amount, $currency)
                . ($currency !== 'AFN' ? ' (' . formatAFN($amountAfn) . ')' : '')
                . ' recorded.';
            header("Location: /fzl/customers/view.php?id=$customer_id");
            exit;
        }
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <a href="index.php" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Payments</a>
    <h4 class="mt-1 mb-0">Record Payment</h4>
</div>

<!-- Exchange rate banner -->
<div class="alert alert-secondary py-2 small mb-3">
    <i class="bi bi-currency-exchange me-1"></i>
    Current rate: <strong>1 <?= htmlspecialchars($secCur) ?> = <?= number_format($rate, 2) ?> ؋</strong>
    &nbsp;—&nbsp;
    <a href="/fzl/admin/settings.php" class="<?= isAdmin() ? '' : 'd-none' ?>">Update rate</a>
    <?php if (!isAdmin()): ?><span class="text-muted">Ask admin to update if needed</span><?php endif; ?>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header fw-semibold">Payment Details</div>
            <div class="card-body">
                <form method="POST" id="payForm">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Customer <span class="text-danger">*</span></label>
                        <select name="customer_id" id="customerSelect" class="form-select" required onchange="updateDebt()">
                            <option value="">— Select Customer —</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" data-debt="<?= $c['total_debt'] ?>"
                                <?= ($preCustomer == $c['id'] || ($_POST['customer_id'] ?? 0) == $c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['shop_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($customers)): ?>
                            <small class="text-muted">No customers with outstanding debt.</small>
                        <?php endif; ?>
                    </div>

                    <div id="debtInfo" class="p-3 rounded mb-3" style="background:#fff8e1;display:none;">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small">Current Debt</span>
                            <span class="fw-bold text-danger" id="debtAfn">؋ 0</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-1">
                            <span class="text-muted small">Equivalent in <?= htmlspecialchars($secCur) ?></span>
                            <span class="fw-semibold text-muted" id="debtSec">—</span>
                        </div>
                    </div>

                    <!-- Currency selector + amount -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Currency & Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select name="currency" id="currencySelect" class="form-select" style="max-width:110px;" onchange="onCurrencyChange()">
                                <option value="AFN" <?= ($_POST['currency'] ?? 'AFN') === 'AFN' ? 'selected' : '' ?>>؋ AFN</option>
                                <option value="<?= htmlspecialchars($secCur) ?>" <?= ($_POST['currency'] ?? '') === $secCur ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($secSymbol) ?> <?= htmlspecialchars($secCur) ?>
                                </option>
                            </select>
                            <input type="number" name="amount" id="amountInput" class="form-control"
                                   step="0.01" min="0.01"
                                   value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>"
                                   placeholder="0.00" required oninput="updateConvert()">
                        </div>
                        <!-- Conversion hint -->
                        <div id="convertHint" class="text-muted small mt-1" style="display:none;"></div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Notes</label>
                        <input type="text" name="notes" class="form-control"
                               placeholder="e.g. Cash, Bank transfer, Hawala..."
                               value="<?= htmlspecialchars($_POST['notes'] ?? '') ?>">
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success px-4">
                            <i class="bi bi-check-lg me-2"></i>Save Payment
                        </button>
                        <a href="index.php" class="btn btn-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$extraScript = <<<JS
<script>
const RATE     = <?= $rate ?>;
const SEC_CUR  = <?= json_encode($secCur) ?>;
const SEC_SYM  = <?= json_encode($secSymbol) ?>;

function fmtAFN(v)  { return '؋ ' + parseFloat(v).toLocaleString('en-AF', {maximumFractionDigits:0}); }
function fmtSec(v)  { return SEC_SYM + ' ' + parseFloat(v).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}); }

function updateDebt() {
    const sel  = document.getElementById('customerSelect');
    const opt  = sel.options[sel.selectedIndex];
    const debt = parseFloat(opt?.dataset.debt || 0);
    const box  = document.getElementById('debtInfo');
    if (debt > 0 && opt?.value) {
        document.getElementById('debtAfn').textContent = fmtAFN(debt);
        document.getElementById('debtSec').textContent = RATE > 0 ? fmtSec(debt / RATE) : '—';
        box.style.display = '';
    } else {
        box.style.display = 'none';
    }
}

function onCurrencyChange() {
    updateConvert();
}

function updateConvert() {
    const cur    = document.getElementById('currencySelect').value;
    const amount = parseFloat(document.getElementById('amountInput').value) || 0;
    const hint   = document.getElementById('convertHint');
    if (cur === 'AFN' || amount <= 0) {
        hint.style.display = 'none';
        return;
    }
    const afn = amount * RATE;
    hint.style.display = '';
    hint.innerHTML = '<i class="bi bi-arrow-right-short"></i> ' + fmtSec(amount) + ' × ' + RATE.toLocaleString() + ' = <strong>' + fmtAFN(afn) + '</strong> will be deducted from debt';
}

document.addEventListener('DOMContentLoaded', () => { updateDebt(); updateConvert(); });
</script>
JS;
?>

<?php require_once '../includes/footer.php'; ?>
