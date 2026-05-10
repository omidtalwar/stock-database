<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = __('pay_add');

$allRates    = getAllRates($pdo);
$settings    = getSettings($pdo);
$secCur      = $settings['secondary_currency'] ?? 'USD';

$customers   = $pdo->query("SELECT id, name, shop_name, total_debt FROM customers WHERE total_debt > 0 ORDER BY name ASC")->fetchAll();
$preCustomer = (int)($_GET['customer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $amount      = (float)($_POST['amount'] ?? 0);
    $currency    = strtoupper(trim($_POST['currency'] ?? 'AFN'));
    $notes       = trim($_POST['notes'] ?? '');

    if (!array_key_exists($currency, CURRENCIES)) $currency = 'AFN';

    if (!$customer_id) {
        $_SESSION['error'] = __('pay_select_cust');
    } elseif ($amount <= 0) {
        $_SESSION['error'] = __('fill_all_fields');
    } else {
        $usedRate  = $allRates[$currency] ?? 1.0;
        $amountAfn = toAFN($amount, $currency, $usedRate);

        $stmt = $pdo->prepare("SELECT total_debt FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $debt = (float)$stmt->fetchColumn();

        if ($amountAfn > $debt + 0.01) {
            $_SESSION['error'] = __('pay_exceeds');
        } else {
            $pdo->prepare("INSERT INTO payments (customer_id, amount, currency, exchange_rate, amount_afn, notes, created_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$customer_id, $amount, $currency, $usedRate, $amountAfn, $notes, $_SESSION['user_id']]);
            $pdo->prepare("UPDATE customers SET total_debt = GREATEST(0, total_debt - ?) WHERE id = ?")
                ->execute([$amountAfn, $customer_id]);

            $_SESSION['success'] = formatMoney($amount, $currency)
                . ($currency !== 'AFN' ? ' (' . formatAFN($amountAfn) . ')' : '');
            header("Location: /customers/view.php?id=$customer_id");
            exit;
        }
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <a href="index.php" class="text-muted small">
        <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_payments') ?>
    </a>
    <h4 class="mt-1 mb-0"><?= __('pay_add') ?></h4>
</div>

<div class="alert alert-secondary py-2 small mb-3">
    <i class="bi bi-currency-exchange me-1"></i>
    <strong>$ 1 USD = ؋ <?= number_format($allRates['USD'], 2) ?></strong>
    &nbsp;·&nbsp;
    <strong>₨ 1000 PKR = ؋ <?= number_format($allRates['PKR'] * 1000, 2) ?></strong>
    <?php if (isAdmin()): ?>
    &nbsp;—&nbsp;<a href="/admin/settings.php"><?= __('btn_update') ?></a>
    <?php endif; ?>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header fw-semibold"><?= __('pay_details') ?></div>
            <div class="card-body">
                <form method="POST" id="payForm">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('nav_customers') ?> <span class="text-danger">*</span></label>
                        <select name="customer_id" id="customerSelect" class="form-select" required onchange="updateDebt()">
                            <option value=""><?= __('pay_select_cust') ?></option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" data-debt="<?= $c['total_debt'] ?>"
                                <?= ($preCustomer == $c['id'] || ($_POST['customer_id'] ?? 0) == $c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['shop_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($customers)): ?>
                            <small class="text-muted"><?= __('pay_no_cust_debt') ?></small>
                        <?php endif; ?>
                    </div>

                    <div id="debtInfo" class="p-3 rounded mb-3" style="background:#fff8e1;display:none;">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small"><?= __('pay_curr_debt') ?></span>
                            <span class="fw-bold text-danger" id="debtAfn">؋ 0</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-1 d-none" id="debtSecRow">
                            <span class="text-muted small" id="debtSecLabel"><?= __('pay_equiv') ?></span>
                            <span class="fw-semibold text-muted" id="debtSec">—</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('field_currency') ?> &amp; <?= __('field_amount') ?> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select name="currency" id="currencySelect" class="form-select" style="max-width:110px;" onchange="onCurrencyChange()">
                                <?php foreach (CURRENCIES as $code => $info): ?>
                                <option value="<?= $code ?>" <?= ($_POST['currency'] ?? 'AFN') === $code ? 'selected' : '' ?>>
                                    <?= $info['symbol'] ?> <?= $code ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="amount" id="amountInput" class="form-control"
                                   step="0.01" min="0.01"
                                   value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>"
                                   placeholder="0.00" required oninput="updateConvert()">
                        </div>
                        <div id="convertHint" class="text-muted small mt-1" style="display:none;"></div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold"><?= __('field_notes') ?></label>
                        <input type="text" name="notes" class="form-control"
                               placeholder="<?= __('pay_notes_hint') ?>"
                               value="<?= htmlspecialchars($_POST['notes'] ?? '') ?>">
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success px-4" id="submitBtn">
                            <i class="bi bi-check-lg me-2"></i><?= __('pay_save') ?>
                        </button>
                        <a href="index.php" class="btn btn-light"><?= __('btn_cancel') ?></a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$_json_rates = json_encode($allRates);
$_json_hint  = json_encode(__('pay_hint'));
$extraScript = <<<JS
<script>
const ALL_RATES = {$_json_rates};
const SYMBOLS   = {AFN:'؋', USD:'$', PKR:'₨'};
const HINT      = {$_json_hint};

function sym(cur) { return SYMBOLS[cur] || cur; }
function fmtAFN(v) { return '؋ ' + parseFloat(v).toLocaleString('en-US',{maximumFractionDigits:0}); }
function fmtCur(v, cur) {
    const dec = cur === 'USD' ? 2 : 0;
    return sym(cur) + ' ' + parseFloat(v).toLocaleString('en-US',{minimumFractionDigits:dec,maximumFractionDigits:dec});
}

function updateDebt() {
    const sel  = document.getElementById('customerSelect');
    const opt  = sel.options[sel.selectedIndex];
    const debt = parseFloat(opt?.dataset.debt || 0);
    const box  = document.getElementById('debtInfo');
    if (debt > 0 && opt?.value) {
        document.getElementById('debtAfn').textContent = fmtAFN(debt);
        const cur     = document.getElementById('currencySelect').value;
        const rate    = ALL_RATES[cur] || 1;
        const secRow  = document.getElementById('debtSecRow');
        if (cur !== 'AFN' && rate > 0) {
            document.getElementById('debtSecLabel').textContent = sym(cur) + ' ' + cur + ' equiv.';
            document.getElementById('debtSec').textContent = fmtCur(debt / rate, cur);
            secRow.classList.remove('d-none');
        } else {
            secRow.classList.add('d-none');
        }
        box.style.display = '';
    } else {
        box.style.display = 'none';
    }
}

function onCurrencyChange() { updateDebt(); updateConvert(); }

function updateConvert() {
    const cur    = document.getElementById('currencySelect').value;
    const amount = parseFloat(document.getElementById('amountInput').value) || 0;
    const hint   = document.getElementById('convertHint');
    if (cur === 'AFN' || amount <= 0) { hint.style.display = 'none'; return; }
    const rate = ALL_RATES[cur] || 1;
    const afn  = amount * rate;
    hint.style.display = '';
    hint.innerHTML = '<i class="bi bi-arrow-right-short"></i> '
        + fmtCur(amount, cur) + ' &times; ' + rate.toLocaleString('en-US',{maximumFractionDigits:4})
        + ' = <strong>' + fmtAFN(afn) + '</strong>'
        + (HINT ? ' &mdash; ' + HINT : '');
}

document.getElementById('payForm').addEventListener('submit', function() {
    document.getElementById('submitBtn').disabled = true;
});

document.addEventListener('DOMContentLoaded', () => { updateDebt(); updateConvert(); });
</script>
JS;
?>

<?php require_once '../includes/footer.php'; ?>
