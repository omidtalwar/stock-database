<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';

$pageTitle = 'Sales / Invoices';

$search = trim($_GET['search'] ?? '');
$params = [];
$where  = '';
if ($search) {
    $where  = "AND (c.name LIKE ? OR c.shop_name LIKE ?)";
    $params = ["%$search%", "%$search%"];
}

$sales = $pdo->prepare("
    SELECT s.id, s.total_amount, s.paid_amount, s.balance, s.created_at, s.notes,
           c.name AS customer_name, c.shop_name,
           u.full_name AS created_by
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    JOIN users u ON u.id = s.created_by
    WHERE 1=1 $where
    ORDER BY s.created_at DESC
");
$sales->execute($params);
$sales = $sales->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1">Sales / Invoices</h4>
        <p class="text-muted small mb-0">All invoices and their payment status</p>
    </div>
    <a href="create.php" class="btn btn-primary"><i class="bi bi-plus-circle me-2"></i>New Invoice</a>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search customer..." value="<?= htmlspecialchars($search) ?>" style="max-width:300px;">
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
            <?php if ($search): ?><a href="index.php" class="btn btn-sm btn-light">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Customer</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>By</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sales)): ?>
                <tr><td colspan="8" class="text-center text-muted py-5">No invoices yet</td></tr>
                <?php else: ?>
                <?php foreach ($sales as $s): ?>
                <tr>
                    <td><span class="badge bg-light text-dark fw-semibold">#<?= str_pad($s['id'], 4, '0', STR_PAD_LEFT) ?></span></td>
                    <td>
                        <div class="fw-semibold small"><?= htmlspecialchars($s['customer_name']) ?></div>
                        <div class="text-muted" style="font-size:0.75rem;"><?= htmlspecialchars($s['shop_name']) ?></div>
                    </td>
                    <td class="fw-semibold">؋ <?= number_format($s['total_amount'], 0) ?></td>
                    <td class="text-success">؋ <?= number_format($s['paid_amount'], 0) ?></td>
                    <td>
                        <?php if ($s['balance'] > 0): ?>
                            <span class="fw-bold text-danger">؋ <?= number_format($s['balance'], 0) ?></span>
                        <?php else: ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Paid</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= htmlspecialchars($s['created_by']) ?></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
                    <td>
                        <a href="view.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-light me-1"><i class="bi bi-eye"></i></a>
                        <?php if (isAdmin()): ?>
                        <a href="delete.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('Delete invoice #<?= str_pad($s['id'],4,'0',STR_PAD_LEFT) ?>? This will reverse stock and debt changes.')">
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
