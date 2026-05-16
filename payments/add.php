<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = __('pay_add');

// Migrations
try { $pdo->exec("ALTER TABLE payments ADD COLUMN payment_date DATE NULL AFTER notes"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE payments ADD COLUMN sale_id INT NULL AFTER customer_id"); } catch (\PDOException $e) {}

$allRates    = getAllRates($pdo);
$settings    = getSettings($pdo);
$secCur      = $settings['secondary_currency'] ?? 'USD';

$customers   = $pdo->query("SELECT id, name, shop_name, total_debt FROM customers ORDER BY name ASC")->fetchAll();
$preCustomer = (int)($_GET['customer_id'] ?? 0);
$preSaleId   = (int)($_GET['sale_id']    ?? 0);

// Load open invoices for the invoice picker (embedded as JSON for JS)
$openInvoices = $pdo->query("
    SELECT s.id, s.customer_id, COALESCE(s.currency,'AFN') AS currency,
           s.total_amount, s.paid_amount, s.bill_no, s.created_at
    FROM sales s
    WHERE s.total_amount > s.paid_amount + 0.01
    ORDER BY s.customer_id, s.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$invoicesByCustomer = [];
foreach ($openInvoices as $inv) {
    $cid  = (int)$inv['customer_id'];
    $cur  = $inv['currency'];
    $rate = $allRates[$cur] ?? 1.0;
    $balAfn = max(0.0, (float)$inv['total_amount'] - (float)$inv['paid_amount']); // always AFN
    if ($balAfn < 0.01) continue;
    $balOrig = $cur === 'AFN' ? $balAfn : fromAFN($balAfn, $rate); // in invoice currency
    $invoicesByCustomer[$cid][] = [
        'id'       => (int)$inv['id'],
        'num'      => '#' . str_pad($inv['id'], 4, '0', STR_PAD_LEFT),
        'bill_no'  => $inv['bill_no'] ?: null,
        'cur'      => $cur,
        'total'    => (float)$inv['total_amount'],
        'paid'     => (float)$inv['paid_amount'],
        'bal'      => $balAfn,      // AFN — for server-side validation
        'bal_orig' => $balOrig,     // in invoice currency — for display & pre-fill
        'date'     => date('d M Y', strtotime($inv['created_at'])),
    ];
}

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

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id  = (int)($_POST['customer_id'] ?? 0);
    $sale_id      = (int)($_POST['sale_id']     ?? 0) ?: null;
    $amount       = (float)($_POST['amount']    ?? 0);
    $currency     = strtoupper(trim($_POST['currency'] ?? 'AFN'));
    $notes        = trim($_POST['notes'] ?? '');
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) $payment_date = date('Y-m-d');
    if (!array_key_exists($currency, CURRENCIES)) $currency = 'AFN';

    $usedRate  = $allRates[$currency] ?? 1.0;
    $amountAfn = toAFN($amount, $currency, $usedRate);

    $error = null;
    if (!$customer_id) {
        $error = __('pay_select_cust');
    } elseif ($amount <= 0) {
        $error = __('fill_all_fields');
    } elseif ($sale_id) {
        // Validate against the specific invoice balance
        $invRow = $pdo->prepare("SELECT total_amount, paid_amount FROM sales WHERE id = ? AND customer_id = ?");
        $invRow->execute([$sale_id, $customer_id]);
        $inv = $invRow->fetch();
        if (!$inv) {
            $error = 'Invoice not found or does not belong to this customer.';
            $sale_id = null;
        } else {
            $invBal = max(0.0, (float)$inv['total_amount'] - (float)$inv['paid_amount']);
            if ($amountAfn > $invBal + 0.01) {
                $error = 'Payment ' . formatAFN($amountAfn) . ' exceeds invoice balance ' . formatAFN($invBal) . '.';
            }
        }
    } else {
        // General payment: validate against total customer debt
        $debt = (float)$pdo->prepare("SELECT total_debt FROM customers WHERE id = ?")->execute([$customer_id]) && true
            ? (float)$pdo->query("SELECT total_debt FROM customers WHERE id = $customer_id")->fetchColumn()
            : 0;
        if ($amountAfn > $debt + 0.01) {
            $error = __('pay_exceeds');
        }
    }

    if ($error) {
        $_SESSION['error'] = $error;
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO payments (customer_id, sale_id, amount, currency, exchange_rate, amount_afn, notes, payment_date, created_by)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([$customer_id, $sale_id, $amount, $currency, $usedRate, $amountAfn, $notes ?: null, $payment_date, $_SESSION['user_id']]);

            // Reduce customer total debt
            $pdo->prepare("UPDATE customers SET total_debt = GREATEST(0, total_debt - ?) WHERE id = ?")
                ->execute([$amountAfn, $customer_id]);

            // If linked to an invoice, update its paid_amount directly
            if ($sale_id) {
                $pdo->prepare("UPDATE sales SET paid_amount = paid_amount + ? WHERE id = ?")
                    ->execute([$amountAfn, $sale_id]);
            }

            $pdo->commit();
            $_SESSION['success'] = formatMoney($amount, $currency)
                . ($currency !== 'AFN' ? ' (' . formatAFN($amountAfn) . ')' : '');
            header($sale_id
                ? "Location: /sales/view.php?id=$sale_id"
                : "Location: /customers/view.php?id=$customer_id");
            exit;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $_SESSION['error'] = 'Failed to save payment. Please try again.';
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
    <div class="col-md-7">
        <div class="card">
            <div class="card-header fw-semibold"><?= __('pay_details') ?></div>
            <div class="card-body">
                <form method="POST" id="payForm">
                    <input type="hidden" name="sale_id" id="saleIdInput" value="<?= $preSaleId ?>">

                    <!-- Customer -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('nav_customers') ?> <span class="text-danger">*</span></label>
                        <select name="customer_id" id="customerSelect" class="form-select" required onchange="onCustomerChange()">
                            <option value=""><?= __('pay_select_cust') ?></option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" data-debt="<?= $c['total_debt'] ?>"
                                <?= ($preCustomer == $c['id'] || ($_POST['customer_id'] ?? 0) == $c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                                <?= $c['shop_name'] ? '— ' . htmlspecialchars($c['shop_name']) : '' ?>
                                <?= $c['total_debt'] > 0 ? ' (؋' . number_format($c['total_debt'], 0) . ' due)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Invoice picker -->
                    <div id="invoiceSection" style="display:none;" class="mb-3">
                        <label class="form-label fw-semibold d-flex align-items-center justify-content-between gap-2 flex-wrap">
                            <span class="d-flex align-items-center gap-2">
                                <i class="bi bi-receipt text-primary"></i>
                                Select Invoice <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size:0.65rem;">Required</span>
                            </span>
                            <div class="input-group input-group-sm" style="max-width:200px;" id="invSearchWrap">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted" style="font-size:.75rem;"></i></span>
                                <input type="text" id="invSearch" class="form-control border-start-0 ps-0"
                                       placeholder="Search invoice…" oninput="filterInvoices()">
                            </div>
                        </label>
                        <div id="invoiceList" class="border rounded" style="max-height:280px;overflow-y:auto;"></div>
                        <div id="invNoResults" style="display:none;" class="text-muted small p-3 border rounded text-center">
                            <i class="bi bi-search me-1"></i> No invoices match your search.
                        </div>
                        <div id="noInvoices" style="display:none;" class="text-muted small p-3 border rounded text-center">
                            <i class="bi bi-check-circle text-success me-1"></i> No open invoices for this customer.
                        </div>
                    </div>

                    <!-- Selected invoice badge -->
                    <div id="selectedInvBadge" style="display:none;" class="mb-3 p-2 rounded d-flex align-items-center justify-content-between"
                         style="background:rgba(0,103,192,0.07);border:1px solid rgba(0,103,192,0.2);">
                        <div>
                            <i class="bi bi-receipt-cutoff text-primary me-1"></i>
                            <span class="fw-semibold" id="selectedInvLabel">—</span>
                            <span class="text-muted small ms-2" id="selectedInvBalance"></span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearInvoice()">
                            <i class="bi bi-x"></i> Change
                        </button>
                    </div>

                    <!-- Currency & Amount -->
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
                                   step="any" min="0.01"
                                   value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>"
                                   placeholder="0.00" required oninput="updateConvert()">
                        </div>
                        <div id="convertHint" class="text-muted small mt-1" style="display:none;"></div>
                    </div>

                    <!-- Notes -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('field_notes') ?></label>
                        <input type="text" name="notes" class="form-control"
                               placeholder="<?= __('pay_notes_hint') ?>"
                               value="<?= htmlspecialchars($_POST['notes'] ?? '') ?>">
                    </div>

                    <!-- Shamsi date picker -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold d-flex align-items-center gap-2">
                            <?= __('field_date') ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:0.62rem;">Solar Hijri</span>
                        </label>
                        <?php
                        $defJy = (int)($_POST['shamsi_y'] ?? $todayShamsi['y']);
                        $defJm = (int)($_POST['shamsi_m'] ?? $todayShamsi['m']);
                        $defJd = (int)($_POST['shamsi_d'] ?? $todayShamsi['d']);
                        $jMonths = ['۱ حمل','۲ ثور','۳ جوزا','۴ سرطان','۵ اسد','۶ سنبله',
                                    '۷ میزان','۸ عقرب','۹ قوس','۱۰ جدی','۱۱ دلو','۱۲ حوت'];
                        ?>
                        <div class="d-flex align-items-center gap-1">
                            <input type="number" id="jYear" name="shamsi_y" class="form-control form-control-sm text-center fw-semibold"
                                   value="<?= $defJy ?>" min="1300" max="1600" style="width:74px;" oninput="syncPayDate()">
                            <span class="text-muted">/</span>
                            <select id="jMonth" name="shamsi_m" class="form-select form-select-sm" style="width:132px;" onchange="syncPayDate()">
                                <?php foreach ($jMonths as $i => $nm): ?>
                                <option value="<?= $i+1 ?>" <?= $defJm === $i+1 ? 'selected' : '' ?>><?= $nm ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="text-muted">/</span>
                            <input type="number" id="jDay" name="shamsi_d" class="form-control form-control-sm text-center fw-semibold"
                                   value="<?= $defJd ?>" min="1" max="31" style="width:60px;" oninput="syncPayDate()">
                        </div>
                        <input type="hidden" name="payment_date" id="paymentDate"
                               value="<?= htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')) ?>">
                        <div id="gregBadge" class="mt-1 text-muted" style="font-size:0.71rem;"></div>
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
$_json_rates    = json_encode($allRates);
$_json_invoices = json_encode($invoicesByCustomer);
$extraScript = <<<JS
<script>
const ALL_RATES = {$_json_rates};
const INVOICES  = {$_json_invoices};   // keyed by customer_id
const SYMBOLS   = {AFN:'؋', USD:'\$', PKR:'₨'};

function sym(cur) { return SYMBOLS[cur] || cur; }

function fmtAFN(v) {
    return '؋ ' + parseFloat(v).toLocaleString('en-US', {maximumFractionDigits:0});
}
function fmtCur(v, cur) {
    const dec = cur === 'USD' ? 2 : 0;
    return sym(cur) + ' ' + parseFloat(v).toLocaleString('en-US', {minimumFractionDigits:dec, maximumFractionDigits:dec});
}

// ── Invoice picker ────────────────────────────────────────────────────────────
let selectedInv = null;

function onCustomerChange() {
    clearInvoice();
    const s = document.getElementById('invSearch');
    if (s) s.value = '';
    renderInvoices();
    updateConvert();
}

function filterInvoices() {
    const q = (document.getElementById('invSearch')?.value || '').trim().toLowerCase();
    const rows = document.querySelectorAll('#invoiceList .inv-pick-row');
    let visible = 0;
    rows.forEach(row => {
        const match = !q || row.textContent.toLowerCase().includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const noRes = document.getElementById('invNoResults');
    if (noRes) noRes.style.display = (q && visible === 0) ? '' : 'none';
}

function renderInvoices() {
    const cid  = parseInt(document.getElementById('customerSelect').value) || 0;
    const sec  = document.getElementById('invoiceSection');
    const list = document.getElementById('invoiceList');
    const none = document.getElementById('noInvoices');

    if (!cid) { sec.style.display = 'none'; return; }

    const invs = INVOICES[cid] || [];
    sec.style.display = '';

    if (!invs.length) {
        list.style.display = 'none';
        none.style.display = '';
        return;
    }

    list.style.display = '';
    none.style.display = 'none';

    list.innerHTML = invs.map((inv, idx) => {
        // bal_orig is in invoice currency; bal is in AFN
        const balLabel = fmtCur(inv.bal_orig, inv.cur)
            + (inv.cur !== 'AFN' ? ' <span style="font-size:.7rem;color:#888;">≈ ' + fmtAFN(inv.bal) + '</span>' : '');
        const billTag  = inv.bill_no ? ' <span style="font-size:.68rem;color:#888;">Bill: ' + inv.bill_no + '</span>' : '';
        const curColors = {USD:'#0067C0', PKR:'#7719AA', AFN:'#107C10'};
        const curColor  = curColors[inv.cur] || '#666';
        const curBadge  = '<span style="background:' + curColor.replace(')', ',0.1)').replace('rgb','rgba') + ';color:' + curColor + ';font-size:.65rem;font-weight:700;padding:1px 6px;border-radius:3px;margin-left:4px;">' + inv.cur + '</span>';
        return '<div class="inv-pick-row d-flex align-items-center justify-content-between px-3 py-2'
            + (idx > 0 ? ' border-top' : '')
            + '" style="cursor:pointer;transition:background .12s;" id="inv-row-' + inv.id + '"'
            + ' onclick="selectInvoice(' + idx + ',' + cid + ')"'
            + ' onmouseenter="this.style.background=\'rgba(0,103,192,0.04)\'"'
            + ' onmouseleave="this.style.background=selectedInv&&selectedInv.id==='+inv.id+'?\'rgba(0,103,192,0.08)\':\'\'">'
            + '<div>'
            +   '<span class="fw-semibold">' + inv.num + '</span>' + curBadge + billTag
            +   '<div class="text-muted" style="font-size:.72rem;">' + inv.date + '</div>'
            + '</div>'
            + '<div class="text-end">'
            +   '<div class="fw-bold text-danger" style="font-size:.9rem;">' + balLabel + '</div>'
            +   '<div style="font-size:.68rem;color:#aaa;">Due</div>'
            + '</div>'
            + '</div>';
    }).join('');
}

function selectInvoice(idx, cid) {
    const inv = (INVOICES[cid] || [])[idx];
    if (!inv) return;
    selectedInv = inv;

    document.getElementById('saleIdInput').value = inv.id;

    // Highlight row
    document.querySelectorAll('.inv-pick-row').forEach(r => r.style.background = '');
    const row = document.getElementById('inv-row-' + inv.id);
    if (row) row.style.background = 'rgba(0,103,192,0.08)';

    // Show selected badge, hide list
    document.getElementById('invoiceSection').style.display = 'none';
    const badge = document.getElementById('selectedInvBadge');
    badge.style.display = '';
    badge.style.background = 'rgba(0,103,192,0.07)';
    badge.style.border = '1px solid rgba(0,103,192,0.2)';
    badge.style.borderRadius = '8px';
    badge.style.padding = '10px 14px';
    document.getElementById('selectedInvLabel').textContent = inv.num + (inv.bill_no ? ' · Bill ' + inv.bill_no : '') + ' — ' + inv.date;
    // bal_orig is in invoice currency
    document.getElementById('selectedInvBalance').textContent = 'Balance: ' + fmtCur(inv.bal_orig, inv.cur)
        + (inv.cur !== 'AFN' ? ' ≈ ' + fmtAFN(inv.bal) : '');

    // Set currency to match invoice — visually indicate it's locked but keep it enabled so it submits
    const curSel = document.getElementById('currencySelect');
    if (curSel) {
        curSel.value = inv.cur;
        curSel.style.opacity = '0.7';
        curSel.title = 'Currency locked to invoice currency (' + inv.cur + ')';
    }
    // Pre-fill amount with balance in invoice currency (bal_orig)
    const amtInput = document.getElementById('amountInput');
    const dec = inv.cur === 'USD' ? 2 : 0;
    amtInput.value = parseFloat(inv.bal_orig).toFixed(dec);
    updateConvert();
}

function clearInvoice() {
    selectedInv = null;
    document.getElementById('saleIdInput').value = '';
    document.getElementById('selectedInvBadge').style.display = 'none';
    const curSel = document.getElementById('currencySelect');
    if (curSel) {
        curSel.style.opacity = '';
        curSel.title = '';
    }
    renderInvoices();
}

// ── Currency / amount ─────────────────────────────────────────────────────────
function onCurrencyChange() { updateConvert(); }

function updateConvert() {
    const cur    = document.getElementById('currencySelect').value;
    const amount = parseFloat(document.getElementById('amountInput').value) || 0;
    const hint   = document.getElementById('convertHint');
    if (cur === 'AFN' || amount <= 0) { hint.style.display = 'none'; return; }
    const rate = ALL_RATES[cur] || 1;
    const afn  = amount * rate;
    hint.style.display = '';
    hint.innerHTML = '<i class="bi bi-arrow-right-short"></i> '
        + fmtCur(amount, cur) + ' &times; ' + rate.toLocaleString('en-US', {maximumFractionDigits:4})
        + ' = <strong>' + fmtAFN(afn) + '</strong>';
}

document.getElementById('payForm').addEventListener('submit', function() {
    document.getElementById('submitBtn').disabled = true;
});

document.addEventListener('DOMContentLoaded', () => {
    renderInvoices();
    updateConvert();
    syncPayDate();
    // If a sale_id was pre-set via URL, auto-select that invoice
    const preSaleId = parseInt(document.getElementById('saleIdInput').value) || 0;
    if (preSaleId) {
        const cid = parseInt(document.getElementById('customerSelect').value) || 0;
        const invs = INVOICES[cid] || [];
        const idx  = invs.findIndex(i => i.id === preSaleId);
        if (idx >= 0) selectInvoice(idx, cid);
    }
});

// ── Shamsi date picker ────────────────────────────────────────────────────────
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
    if (jump - n < 6) n = n - jump + Math.floor((jump + 4) / 33) * 33;
    var dayOfYear = jm <= 6 ? (jm - 1) * 31 + jd : 186 + (jm - 7) * 30 + jd;
    var gDay = march + dayOfYear - 1, gMon = 3, gYr = gy;
    function dim(m, y) {
        if (m === 2) return ((y % 4 === 0 && y % 100 !== 0) || y % 400 === 0) ? 29 : 28;
        return [0,31,28,31,30,31,30,31,31,30,31,30,31][m];
    }
    while (gDay > dim(gMon, gYr)) { gDay -= dim(gMon, gYr); if (++gMon > 12) { gMon = 1; gYr++; } }
    return {y: gYr, m: gMon, d: gDay};
}

function syncPayDate() {
    var jy = parseInt(document.getElementById('jYear').value)  || 0;
    var jm = parseInt(document.getElementById('jMonth').value) || 0;
    var jd = parseInt(document.getElementById('jDay').value)   || 0;
    if (jy < 1300 || jy > 1600 || jm < 1 || jm > 12 || jd < 1 || jd > 31) return;
    var g = shamsiToGregorian(jy, jm, jd);
    if (!g) return;
    var gStr = g.y + '-' + String(g.m).padStart(2,'0') + '-' + String(g.d).padStart(2,'0');
    document.getElementById('paymentDate').value = gStr;
    document.getElementById('gregBadge').textContent =
        '≡ ' + g.y + '/' + String(g.m).padStart(2,'0') + '/' + String(g.d).padStart(2,'0') + ' (Gregorian)';
}
</script>
JS;
?>

<?php require_once '../includes/footer.php'; ?>
