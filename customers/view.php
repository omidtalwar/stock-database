<?php
require_once '../includes/session.php';

// Not logged in + has an ID → auto-generate token and redirect to public share view
if (!isset($_SESSION['user_id'])) {
    $guestId = (int)($_GET['id'] ?? 0);
    if ($guestId > 0) {
        require_once '../config/db.php';
        require_once '../includes/currency.php';
        try { $pdo->exec("ALTER TABLE customers ADD COLUMN share_token VARCHAR(64) NULL"); } catch (\PDOException $e) {}
        $gRow = $pdo->prepare("SELECT id, share_token FROM customers WHERE id = ?");
        $gRow->execute([$guestId]);
        $gRow = $gRow->fetch();
        if ($gRow) {
            $tok = $gRow['share_token'];
            if (empty($tok)) {
                $tok = bin2hex(random_bytes(24));
                $pdo->prepare("UPDATE customers SET share_token=? WHERE id=?")->execute([$tok, $guestId]);
            }
            header('Location: /customers/share.php?token=' . $tok);
            exit;
        }
    }
    header('Location: /auth/login.php');
    exit;
}

requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

// Auto-migrate: add share_token column
try { $pdo->exec("ALTER TABLE customers ADD COLUMN share_token VARCHAR(64) NULL"); } catch (\PDOException $e) {}

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();
if (!$customer) { $_SESSION['error'] = 'Customer not found.'; header('Location: index.php'); exit; }

// AJAX: get-or-create token (returns JSON, one-step share)
if (($_POST['_share_action'] ?? '') === 'get_link') {
    header('Content-Type: application/json');
    $tok = $customer['share_token'];
    if (empty($tok)) {
        $tok = bin2hex(random_bytes(24));
        $pdo->prepare("UPDATE customers SET share_token=? WHERE id=?")->execute([$tok, $id]);
    }
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    echo json_encode(['url' => $base . '/customers/share.php?token=' . $tok]);
    exit;
}
// Revoke
if (($_POST['_share_action'] ?? '') === 'revoke') {
    $pdo->prepare("UPDATE customers SET share_token=NULL WHERE id=?")->execute([$id]);
    header("Location: view.php?id=$id"); exit;
}

$pageTitle = htmlspecialchars($customer['name']);

$rates   = getAllRates($pdo);
$rateUSD = $rates['USD'];
$ratePKR = $rates['PKR'];

$sales = $pdo->prepare("
    SELECT s.*, u.full_name AS created_by
    FROM sales s JOIN users u ON u.id = s.created_by
    WHERE s.customer_id = ? ORDER BY s.created_at DESC
");
$sales->execute([$id]);
$sales = $sales->fetchAll();

$payments = $pdo->prepare("
    SELECT p.*, u.full_name AS created_by,
           s.id AS inv_id, s.bill_no AS inv_bill_no
    FROM payments p
    JOIN users u ON u.id = p.created_by
    LEFT JOIN sales s ON s.id = p.sale_id
    WHERE p.customer_id = ? ORDER BY COALESCE(p.payment_date, DATE(p.created_at)) DESC, p.created_at DESC
");
$payments->execute([$id]);
$payments = $payments->fetchAll();

$totalSales = array_sum(array_column($sales, 'total_amount'));
$totalPayments = 0;
$paysByCur  = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];
$salesByCur = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];
$debtByCur  = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];

// Build sales totals and invoice-level debt first
foreach ($sales as $s) {
    $sCur  = $s['currency'] ?? 'AFN';
    $sAfn  = (float)$s['total_amount'];
    $sRate = $rates[$sCur] ?? 1.0;
    $sOrig = $sCur === 'AFN' ? $sAfn : fromAFN($sAfn, $sRate);
    if (isset($salesByCur[$sCur])) {
        $salesByCur[$sCur]['orig'] += $sOrig;
        $salesByCur[$sCur]['afn']  += $sAfn;
        $salesByCur[$sCur]['cnt']  ++;
    }
    $sBal = max(0.0, $sAfn - (float)$s['paid_amount']);
    if ($sBal > 0.01 && isset($debtByCur[$sCur])) {
        $debtByCur[$sCur]['orig'] += $sCur === 'AFN' ? $sBal : fromAFN($sBal, $sRate);
        $debtByCur[$sCur]['afn']  += $sBal;
        $debtByCur[$sCur]['cnt']  ++;
    }
}

// Then process payments — general payments subtract from their currency's debt bucket
foreach ($payments as $p) {
    $afn = (float)$p['amount_afn'] ?: (float)$p['amount'];
    $totalPayments += $afn;
    $cur = $p['currency'] ?? 'AFN';
    if (isset($paysByCur[$cur])) {
        $paysByCur[$cur]['orig'] += (float)$p['amount'];
        $paysByCur[$cur]['afn']  += $afn;
        $paysByCur[$cur]['cnt']  ++;
    }
    if (empty($p['inv_id']) && isset($debtByCur[$cur])) {
        $debtByCur[$cur]['orig'] = max(0, $debtByCur[$cur]['orig'] - (float)$p['amount']);
        $debtByCur[$cur]['afn']  = max(0, $debtByCur[$cur]['afn']  - $afn);
        if ($debtByCur[$cur]['orig'] < 0.01) $debtByCur[$cur]['cnt'] = 0;
    }
}

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-start justify-content-between">
    <div>
        <a href="index.php" class="text-muted small">
            <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_customers') ?>
        </a>
        <h4 class="mt-1 mb-0"><?= htmlspecialchars($customer['name']) ?></h4>
        <span class="text-muted small"><?= htmlspecialchars($customer['shop_name']) ?></span>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <button type="button" id="shareBtn" data-id="<?= $id ?>"
            class="btn btn-sm btn-outline-info">
            <i class="bi bi-share me-1"></i>Share
        </button>
        <?php if (!empty($customer['share_token'])): ?>
        <form method="POST" style="display:inline;"
              onsubmit="return confirm('Revoke this link? The customer will no longer be able to open it.')">
            <input type="hidden" name="_share_action" value="revoke">
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Revoke share link">
                <i class="bi bi-x-circle"></i>
            </button>
        </form>
        <?php endif; ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i><?= __('btn_edit') ?></a>
        <a href="/payments/add.php?customer_id=<?= $id ?>" class="btn btn-sm btn-success"><i class="bi bi-cash me-1"></i><?= __('pay_add') ?></a>
        <a href="/sales/create.php?customer_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="bi bi-receipt me-1"></i><?= __('sale_add') ?></a>
    </div>
</div>

<script>
document.getElementById('shareBtn').addEventListener('click', async function () {
    const btn = this;
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Getting link…';
    try {
        const res  = await fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'_share_action=get_link' });
        const data = await res.json();
        await navigator.clipboard.writeText(data.url);
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Link Copied!';
        btn.classList.replace('btn-outline-info','btn-success');
        // Reload after short delay so revoke button appears if it wasn't there before
        setTimeout(() => location.reload(), 1800);
    } catch (e) {
        btn.innerHTML = orig; btn.disabled = false;
        const data = await fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'_share_action=get_link' }).then(r=>r.json()).catch(()=>null);
        if (data?.url) prompt('Copy this link:', data.url);
    }
});
</script>

<?php
$cvCurMeta = [
    'AFN' => ['flag'=>'🇦🇫','col'=>'#107C10'],
    'USD' => ['flag'=>'🇺🇸','col'=>'#0067C0'],
    'PKR' => ['flag'=>'🇵🇰','col'=>'#7719AA'],
];
?>
<style>
.cv-cur-row { display:flex; align-items:center; justify-content:space-between; padding:4px 0; border-bottom:1px solid rgba(0,0,0,0.06); }
.cv-cur-row:last-child { border-bottom:none; }
.cv-cur-lbl { font-size:0.72rem; font-weight:700; display:flex; align-items:center; gap:4px; }
.cv-cur-amt { font-weight:700; font-size:0.88rem; text-align:right; }
.cv-cur-eq  { font-size:0.65rem; color:#888; text-align:right; }
.cv-empty   { font-size:0.78rem; color:#888; padding:6px 0; }
</style>

<div class="row g-3 mb-4">

    <!-- Total Sales -->
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-2 fw-semibold"><?= __('cust_total_sales') ?></div>
                <?php $anySales = false; foreach ($cvCurMeta as $cur => $m): $d = $salesByCur[$cur]; if (!$d['cnt']) continue; $anySales = true; ?>
                <div class="cv-cur-row">
                    <span class="cv-cur-lbl"><?= $m['flag'] ?> <span style="color:<?= $m['col'] ?>;"><?= $cur ?></span></span>
                    <div>
                        <div class="cv-cur-amt" style="color:<?= $m['col'] ?>;"><?= formatMoney($d['orig'], $cur) ?></div>
                        <?php if ($cur !== 'AFN'): ?><div class="cv-cur-eq">≈ <?= formatAFN($d['afn']) ?></div><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; if (!$anySales): ?><div class="cv-empty">—</div><?php endif; ?>
                <div class="text-muted mt-2" style="font-size:0.68rem;"><?= count($sales) ?> invoices total</div>
            </div>
        </div>
    </div>

    <!-- Total Paid -->
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-2 fw-semibold"><?= __('cust_total_paid') ?></div>
                <?php $anyPaid = false; foreach ($cvCurMeta as $cur => $m): $d = $paysByCur[$cur]; if (!$d['cnt']) continue; $anyPaid = true; ?>
                <div class="cv-cur-row">
                    <span class="cv-cur-lbl"><?= $m['flag'] ?> <span style="color:<?= $m['col'] ?>;"><?= $cur ?></span></span>
                    <div>
                        <div class="cv-cur-amt" style="color:#107C10;"><?= formatMoney($d['orig'], $cur) ?></div>
                        <?php if ($cur !== 'AFN'): ?><div class="cv-cur-eq">≈ <?= formatAFN($d['afn']) ?></div><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; if (!$anyPaid): ?><div class="cv-empty text-muted">No payments yet</div><?php endif; ?>
                <div class="text-muted mt-2" style="font-size:0.68rem;"><?= count($payments) ?> payments total</div>
            </div>
        </div>
    </div>

    <!-- Current Debt -->
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-2 fw-semibold"><?= __('cust_current_debt') ?></div>
                <?php $anyDebt = false; foreach ($cvCurMeta as $cur => $m): $d = $debtByCur[$cur]; if ($d['orig'] < 0.01) continue; $anyDebt = true; ?>
                <div class="cv-cur-row">
                    <span class="cv-cur-lbl"><?= $m['flag'] ?> <span style="color:#C42B1C;"><?= $cur ?></span></span>
                    <div>
                        <div class="cv-cur-amt" style="color:#C42B1C;"><?= formatMoney($d['orig'], $cur) ?></div>
                        <?php if ($cur !== 'AFN'): ?><div class="cv-cur-eq">≈ <?= formatAFN($d['afn']) ?></div><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; if (!$anyDebt): ?>
                <div class="cv-empty text-success fw-semibold">✓ Fully paid</div>
                <?php endif; ?>
                <div class="text-muted mt-2" style="font-size:0.68rem;"><?= $anyDebt ? array_sum(array_column($debtByCur,'cnt')).' open invoices' : 'All settled' ?></div>
            </div>
        </div>
    </div>

    <!-- Contact -->
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body py-3">
                <div class="text-muted small mb-1"><?= __('cust_contact') ?></div>
                <div class="fw-semibold"><?= htmlspecialchars($customer['phone'] ?: '—') ?></div>
                <?php if ($customer['notes']): ?>
                <div class="text-muted small mt-1"><?= htmlspecialchars($customer['notes']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold"><?= __('cust_inv_history') ?></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= __('field_total') ?></th>
                            <th><?= __('field_balance') ?></th>
                            <th><?= __('field_date') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4"><?= __('cust_no_inv') ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($sales as $s):
                            $sCur     = $s['currency'] ?? 'AFN';
                            $sCurRate = $rates[$sCur] ?? 1.0;
                            $sTotal   = (float)$s['total_amount'];
                            $sBalance = max(0, $sTotal - (float)$s['paid_amount']);
                            $curColors = ['USD'=>'#0067C0','PKR'=>'#7719AA'];
                        ?>
                        <tr>
                            <td>
                                <span class="badge bg-light text-dark">#<?= str_pad($s['id'], 4, '0', STR_PAD_LEFT) ?></span>
                                <?php if ($sCur !== 'AFN'): ?>
                                <span style="background:<?= $sCur==='USD'?'rgba(0,103,192,0.1)':'rgba(119,25,170,0.1)' ?>;color:<?= $curColors[$sCur]??'#666' ?>;font-size:0.62rem;font-weight:700;padding:1px 5px;border-radius:3px;margin-left:2px;"><?= $sCur ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sCur !== 'AFN'): ?>
                                    <div class="fw-semibold"><?= formatMoney(fromAFN($sTotal, $sCurRate), $sCur) ?></div>
                                    <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatAFN($sTotal) ?></div>
                                <?php else: ?>
                                    <div><?= formatAFN($sTotal) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sBalance > 0.01): ?>
                                    <?php if ($sCur !== 'AFN'): ?>
                                        <div class="text-danger fw-semibold"><?= formatMoney(fromAFN($sBalance, $sCurRate), $sCur) ?></div>
                                        <div class="text-muted" style="font-size:0.7rem;">≈ <?= formatAFN($sBalance) ?></div>
                                    <?php else: ?>
                                        <div class="text-danger fw-semibold"><?= formatAFN($sBalance) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-success">✓ <?= __('sale_fully_paid') ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
                            <td><a href="/sales/view.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-light"><i class="bi bi-eye"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold"><?= __('cust_pay_history') ?></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= __('pay_paid_amount') ?></th>
                            <th>Invoice</th>
                            <th><?= __('field_notes') ?></th>
                            <th><?= __('field_date') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4"><?= __('cust_no_pay') ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($payments as $p):
                            $afn = (float)$p['amount_afn'] ?: (float)$p['amount'];
                            $cur = $p['currency'] ?? 'AFN';
                            $pDate = $p['payment_date'] ?? date('Y-m-d', strtotime($p['created_at']));
                        ?>
                        <tr>
                            <td class="fw-bold text-success">
                                <?= formatMoney((float)$p['amount'], $cur) ?>
                                <?php if ($cur !== 'AFN'): ?>
                                <div class="text-muted fw-normal" style="font-size:0.7rem;">≈ <?= formatAFN($afn) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['inv_id'])): ?>
                                <a href="/sales/view.php?id=<?= $p['inv_id'] ?>" class="fw-semibold text-decoration-none" style="font-size:0.82rem;">
                                    #<?= str_pad($p['inv_id'], 4, '0', STR_PAD_LEFT) ?>
                                </a>
                                <?php if ($p['inv_bill_no']): ?>
                                <div class="text-muted" style="font-size:0.68rem;"><?= htmlspecialchars($p['inv_bill_no']) ?></div>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-muted" style="font-size:0.75rem;">General</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= htmlspecialchars($p['notes'] ?: '—') ?></td>
                            <td class="text-muted small"><?= date('d M Y', strtotime($pDate)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
