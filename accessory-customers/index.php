<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/period.php';
require_once 'helpers.php';

ensureAccessoryCustomerTables($pdo);

$pageTitle = __('nav_acc_customers');

$q = trim($_GET['q'] ?? '');
$where = '';
$params = [];
if ($q !== '') {
    $where = "WHERE c.name LIKE ? OR c.phone LIKE ? OR c.shop_name LIKE ?";
    $params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
}

// ── Time-period filter (Today / Week / Month / All / Custom) ──
$pf    = periodResolve();                                             // default: All Time
$condT = periodCond("COALESCE(t.txn_date, DATE(t.created_at))", $pf); // accessory bills

// List the registered (main) customers, one row per NAME (duplicate customer
// records with the same name are merged); attach their accessory-bill stats.
$customersStmt = $pdo->prepare("
    SELECT MIN(c.id) AS id, c.name,
           MAX(c.phone) AS phone, MAX(c.shop_name) AS shop_name,
           COUNT(t.id) AS txn_count,
           COALESCE(SUM(t.cloth_weight), 0)  AS total_cloth,
           COALESCE(SUM(t.demand_weight), 0) AS total_demand,
           MAX(COALESCE(t.txn_date, DATE(t.created_at))) AS last_txn_date
    FROM customers c
    LEFT JOIN accessory_customer_txns t ON t.customer_id = c.id AND ($condT)
    $where
    GROUP BY c.name
    ORDER BY c.name ASC
");
$customersStmt->execute($params);
$customers = $customersStmt->fetchAll();

$summary = [
    'customers' => count($customers),
    'cloth'     => array_sum(array_map(fn($c) => (float)$c['total_cloth'], $customers)),
    'demand'    => array_sum(array_map(fn($c) => (float)$c['total_demand'], $customers)),
];

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('nav_acc_customers') ?></h4>
        <p class="text-muted small mb-0">Record accessory bills (cloth &amp; demand weight) for your registered customers</p>
    </div>
    <a href="/customers/add.php" class="btn btn-primary">
        <i class="bi bi-person-plus me-2"></i><?= __('cust_add') ?>
    </a>
</div>

<!-- Time-period filter -->
<?= periodBar($pf, ['q' => $q]) ?>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-4">
        <div class="card border-0" style="background:rgba(244,114,182,0.12);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Customers</div>
                <div class="fs-4 fw-bold" style="color:#DB2777;"><?= number_format($summary['customers']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card border-0" style="background:rgba(34,211,238,0.12);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Total Cloth Weight</div>
                <div class="fs-4 fw-bold" style="color:#0E7490;"><?= number_format($summary['cloth'], 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card border-0" style="background:rgba(124,58,237,0.12);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Total Demand Weight</div>
                <div class="fs-4 fw-bold" style="color:#7C3AED;"><?= number_format($summary['demand'], 2) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 d-flex align-items-center justify-content-between gap-2 flex-wrap">
        <span class="fw-semibold"><i class="bi bi-people me-2 text-primary"></i>Customers</span>
        <div class="d-flex align-items-center gap-2">
            <form method="GET" class="d-flex gap-1">
                <input type="text" name="q" class="form-control form-control-sm" style="min-width:200px;"
                       placeholder="Search name, phone or shop…" value="<?= htmlspecialchars($q) ?>">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-search"></i></button>
                <?php if ($q !== ''): ?>
                <a href="index.php" class="btn btn-sm btn-light" title="Clear"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </form>
            <span class="text-muted small"><?= count($customers) ?> shown</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th class="d-none d-md-table-cell">Shop</th>
                    <th class="d-none d-md-table-cell">Phone</th>
                    <th class="text-end">Bills</th>
                    <th class="text-end">Cloth Wt</th>
                    <th class="text-end">Demand Wt</th>
                    <th class="d-none d-lg-table-cell">Last Bill</th>
                    <th><?= __('field_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-5">
                        <?php if ($q !== ''): ?>
                        No customers match "<?= htmlspecialchars($q) ?>".
                        <?php else: ?>
                        No customers registered yet. <a href="/customers/add.php">Add a customer</a> first.
                        <?php endif; ?>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($customers as $customer): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="text-white fw-bold d-flex align-items-center justify-content-center"
                                 style="width:34px;height:34px;border-radius:8px;background:#DB2777;flex-shrink:0;">
                                <?= strtoupper(substr($customer['name'], 0, 1)) ?>
                            </div>
                            <div class="fw-semibold"><?= htmlspecialchars($customer['name']) ?></div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell"><?= htmlspecialchars($customer['shop_name'] ?? '') ?></td>
                    <td class="d-none d-md-table-cell"><?= htmlspecialchars($customer['phone'] ?? '') ?></td>
                    <td class="text-end"><?= number_format((int)$customer['txn_count']) ?></td>
                    <td class="text-end fw-semibold"><?= number_format((float)$customer['total_cloth'], 2) ?></td>
                    <td class="text-end fw-semibold" style="color:#7C3AED;"><?= number_format((float)$customer['total_demand'], 2) ?></td>
                    <td class="d-none d-lg-table-cell text-muted small">
                        <?= $customer['last_txn_date'] ? htmlspecialchars(accCustShamsiDate($customer['last_txn_date'])) : '-' ?>
                    </td>
                    <td>
                        <a href="customer.php?id=<?= $customer['id'] ?>" class="btn btn-sm btn-primary" title="Add / view accessory bills">
                            <i class="bi bi-receipt me-1"></i>Bills
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
