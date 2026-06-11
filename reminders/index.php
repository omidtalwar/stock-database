<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';
require_once '../includes/reminders.php';

$rates   = getAllRates($pdo);
$rateUSD = $rates['USD'];

$days = (int)($_GET['days'] ?? 15);
if ($days < 1) $days = 15;

$customers = overdueCustomers($pdo, $days);

$totalDebt = array_sum(array_map(fn($c) => (float)$c['total_debt'], $customers));

$pageTitle = 'Reminders';
require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><i class="bi bi-bell me-2 text-danger"></i>Payment Reminders</h4>
        <p class="text-muted small mb-0">Customers with debt and no payment for <?= $days ?>+ days</p>
    </div>
    <form method="GET" class="d-flex align-items-center gap-2">
        <label class="text-muted small mb-0">Overdue by</label>
        <select name="days" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
            <?php foreach ([15, 30, 45, 60, 90] as $opt): ?>
            <option value="<?= $opt ?>" <?= $days === $opt ? 'selected' : '' ?>><?= $opt ?> days</option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (empty($customers)): ?>
<div class="alert alert-success d-flex align-items-center gap-2">
    <i class="bi bi-check-circle-fill"></i>
    No customers are overdue by <?= $days ?>+ days. All caught up.
</div>
<?php else: ?>

<div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2">
    <span><i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong><?= count($customers) ?></strong> customer(s) haven't paid in <?= $days ?>+ days.
    </span>
    <span class="fw-bold text-danger">Outstanding: <?= formatAFN($totalDebt) ?></span>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><?= __('field_name') ?></th>
                    <th class="d-none d-md-table-cell"><?= __('field_shop') ?></th>
                    <th class="d-none d-sm-table-cell"><?= __('field_phone') ?></th>
                    <th><?= __('cust_debt') ?></th>
                    <th class="d-none d-md-table-cell">Last Payment</th>
                    <th class="text-end">Overdue</th>
                    <th><?= __('field_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                                 style="width:34px;height:34px;background:#DC3545;font-size:0.8rem;flex-shrink:0;border-radius:8px!important;">
                                <?= strtoupper(substr($c['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($c['name']) ?></div>
                                <div class="text-muted d-md-none" style="font-size:0.72rem;">
                                    <?= htmlspecialchars($c['shop_name'] ?? '') ?>
                                    <?php if ($c['phone']): ?> · <?= htmlspecialchars($c['phone']) ?><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell"><?= htmlspecialchars($c['shop_name'] ?? '') ?></td>
                    <td class="d-none d-sm-table-cell"><?= htmlspecialchars($c['phone'] ?? '') ?></td>
                    <td>
                        <div class="fw-bold text-danger" style="font-size:0.85rem;"><?= formatAFN((float)$c['total_debt']) ?></div>
                        <div class="text-muted" style="font-size:0.65rem;">≈ <?= formatMoney(fromAFN((float)$c['total_debt'], $rateUSD), 'USD') ?></div>
                    </td>
                    <td class="d-none d-md-table-cell text-muted small">
                        <?= $c['last_payment'] ? htmlspecialchars($c['last_payment']) : '<span class="text-danger">Never</span>' ?>
                    </td>
                    <td class="text-end">
                        <span class="badge <?= (int)$c['days_since'] >= 30 ? 'bg-danger' : 'bg-warning text-dark' ?>">
                            <?= (int)$c['days_since'] ?> days
                        </span>
                    </td>
                    <td>
                        <a href="/payments/add.php?customer_id=<?= $c['id'] ?>" class="btn btn-sm btn-success me-1" title="Record payment">
                            <i class="bi bi-cash-coin"></i>
                        </a>
                        <a href="/customers/view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-light" title="<?= __('btn_view') ?>">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
