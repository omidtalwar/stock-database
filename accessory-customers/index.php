<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/telegram.php';
require_once '../includes/period.php';
require_once 'helpers.php';

ensureAccessoryCustomerTables($pdo);

$pageTitle = __('nav_acc_customers');

// ── Delete customer (and all their transactions via ON DELETE CASCADE) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!validateFormToken('acc_customer_delete')) {
        $_SESSION['error'] = 'Duplicate submission detected.';
        header('Location: index.php'); exit;
    }
    $delId = (int)($_POST['customer_id'] ?? 0);
    if ($delId > 0) {
        $nameStmt = $pdo->prepare("SELECT name FROM accessory_customers WHERE id = ?");
        $nameStmt->execute([$delId]);
        $delName = (string)$nameStmt->fetchColumn();
        $stmt = $pdo->prepare("DELETE FROM accessory_customers WHERE id = ?");
        $stmt->execute([$delId]);
        tgNotify("🗑 <b>Accessory customer deleted</b>\nCustomer: " . tgEsc($delName) . "\nBy: " . tgActor(), 'acc_customer');
        $_SESSION['success'] = 'Customer and all their transactions deleted.';
    }
    header('Location: index.php'); exit;
}

// ── Register new customer ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateFormToken('acc_customer')) {
        $_SESSION['error'] = 'Duplicate submission detected. Your data was already saved.';
        header('Location: index.php'); exit;
    }

    $name  = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($name === '') {
        $_SESSION['error'] = 'Customer name is required.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO accessory_customers (name, phone, notes, created_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$name, $phone ?: null, $notes ?: null, $_SESSION['user_id'] ?? null]);
            $customerId = (int)$pdo->lastInsertId();
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Save failed: ' . $e->getMessage();
            header('Location: index.php'); exit;
        }

        tgNotify("👤 <b>Accessory customer added</b>\nName: " . tgEsc($name)
            . ($phone ? "\nPhone: " . tgEsc($phone) : '')
            . "\nBy: " . tgActor(), 'acc_customer');
        $_SESSION['success'] = 'Customer registered.';
        header('Location: customer.php?id=' . $customerId); exit;
    }
}

$q = trim($_GET['q'] ?? '');
$where = '';
$params = [];
if ($q !== '') {
    $where = "WHERE c.name LIKE ? OR c.phone LIKE ?";
    $params = ['%' . $q . '%', '%' . $q . '%'];
}

// ── Time-period filter (Today / Week / Month / All / Custom) ──
$pf    = periodResolve();                                             // default: All Time
$condT = periodCond("COALESCE(t.txn_date, DATE(t.created_at))", $pf); // transactions

$customersStmt = $pdo->prepare("
    SELECT c.*,
           COUNT(t.id) AS txn_count,
           COALESCE(SUM(t.cloth_weight), 0)  AS total_cloth,
           COALESCE(SUM(t.demand_weight), 0) AS total_demand,
           MAX(COALESCE(t.txn_date, DATE(t.created_at))) AS last_txn_date
    FROM accessory_customers c
    LEFT JOIN accessory_customer_txns t ON t.customer_id = c.id AND ($condT)
    $where
    GROUP BY c.id
    ORDER BY c.created_at DESC, c.name ASC
");
$customersStmt->execute($params);
$customers = $customersStmt->fetchAll();

$summary = [
    'customers' => count($customers),
    'cloth'     => array_sum(array_map(fn($c) => (float)$c['total_cloth'], $customers)),
    'demand'    => array_sum(array_map(fn($c) => (float)$c['total_demand'], $customers)),
];

$formToken = generateFormToken('acc_customer');
$deleteToken = generateFormToken('acc_customer_delete');

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('nav_acc_customers') ?></h4>
        <p class="text-muted small mb-0">Track per-customer bills: cloth weight &amp; demand weight</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#customerModal">
        <i class="bi bi-person-plus me-2"></i>Add Customer
    </button>
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
                       placeholder="Search name or phone…" value="<?= htmlspecialchars($q) ?>">
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
                    <td colspan="7" class="text-center text-muted py-5">
                        <?php if ($q !== ''): ?>
                        No customers match "<?= htmlspecialchars($q) ?>".
                        <?php else: ?>
                        No accessory customers yet. Register the first customer to start adding bills.
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
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($customer['name']) ?></div>
                                <?php if ($customer['notes']): ?>
                                <div class="text-muted small"><?= htmlspecialchars($customer['notes']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell"><?= htmlspecialchars($customer['phone'] ?? '') ?></td>
                    <td class="text-end"><?= number_format((int)$customer['txn_count']) ?></td>
                    <td class="text-end fw-semibold"><?= number_format((float)$customer['total_cloth'], 2) ?></td>
                    <td class="text-end fw-semibold" style="color:#7C3AED;"><?= number_format((float)$customer['total_demand'], 2) ?></td>
                    <td class="d-none d-lg-table-cell text-muted small">
                        <?= $customer['last_txn_date'] ? htmlspecialchars(accCustShamsiDate($customer['last_txn_date'])) : '-' ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="customer.php?id=<?= $customer['id'] ?>" class="btn btn-sm btn-light" title="Open">
                                <i class="bi bi-box-arrow-in-right"></i>
                            </a>
                            <form method="POST" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($customer['name'])) ?> and ALL their bills? This cannot be undone.');" class="d-inline">
                                <input type="hidden" name="_form_token" value="<?= htmlspecialchars($deleteToken) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="customer_id" value="<?= $customer['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete customer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="customerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
            <input type="hidden" name="_form_token" value="<?= htmlspecialchars($formToken) ?>">
            <div class="modal-header">
                <h5 class="modal-title">Register Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Customer Name</label>
                    <input type="text" name="name" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Phone</label>
                    <input type="text" name="phone" class="form-control">
                </div>
                <div class="mb-1">
                    <label class="form-label fw-semibold">Note</label>
                    <input type="text" name="notes" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= __('btn_cancel') ?></button>
                <button class="btn btn-primary"><i class="bi bi-check2-circle me-2"></i><?= __('btn_save') ?></button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
