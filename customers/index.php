<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';

$pageTitle = 'Customers';

$search = trim($_GET['search'] ?? '');
$params = [];
$where  = '';
if ($search) {
    $where  = "WHERE c.name LIKE ? OR c.phone LIKE ? OR c.shop_name LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}

$customers = $pdo->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM sales s WHERE s.customer_id = c.id) AS sale_count
    FROM customers c $where
    ORDER BY c.total_debt DESC, c.name ASC
");
$customers->execute($params);
$customers = $customers->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1">Customers</h4>
        <p class="text-muted small mb-0">Manage shopkeepers and their credit</p>
    </div>
    <a href="/fzl/customers/add.php" class="btn btn-primary">
        <i class="bi bi-person-plus me-2"></i>Add Customer
    </a>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by name, phone, shop..." value="<?= htmlspecialchars($search) ?>" style="max-width:300px;">
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
            <?php if ($search): ?><a href="index.php" class="btn btn-sm btn-light">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Shop Name</th>
                    <th>Phone</th>
                    <th>Invoices</th>
                    <th>Total Debt</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">No customers found</td></tr>
                <?php else: ?>
                <?php foreach ($customers as $i => $c): ?>
                <tr>
                    <td class="text-muted small"><?= $i + 1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                                 style="width:34px;height:34px;background:#e94560;font-size:0.8rem;flex-shrink:0;">
                                <?= strtoupper(substr($c['name'], 0, 1)) ?>
                            </div>
                            <span class="fw-semibold"><?= htmlspecialchars($c['name']) ?></span>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($c['shop_name']) ?></td>
                    <td><?= htmlspecialchars($c['phone']) ?></td>
                    <td><span class="badge bg-light text-dark"><?= $c['sale_count'] ?></span></td>
                    <td>
                        <?php if ($c['total_debt'] > 0): ?>
                            <span class="fw-bold text-danger">؋ <?= number_format($c['total_debt'], 0) ?></span>
                        <?php else: ?>
                            <span class="text-success fw-semibold">Cleared</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-light me-1" title="View">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-light me-1" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <?php if (isAdmin()): ?>
                        <a href="delete.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('Delete <?= htmlspecialchars(addslashes($c['name'])) ?>? This cannot be undone.')" title="Delete">
                            <i class="bi bi-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
