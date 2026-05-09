<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';

$supplierName = trim($_GET['name'] ?? '');
if ($supplierName === '') { header('Location: index.php'); exit; }

// Auto-migrate guard
foreach ([
    "ALTER TABLE stock_logs MODIFY product_id   INT           NULL",
    "ALTER TABLE stock_logs ADD COLUMN custom_product VARCHAR(255) NULL",
    "ALTER TABLE stock_logs ADD COLUMN supplier       VARCHAR(255) NULL",
    "ALTER TABLE stock_logs ADD COLUMN bundle_count   INT          NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN pricing_type   VARCHAR(20)  NULL DEFAULT 'per_pcs'",
    "ALTER TABLE stock_logs ADD COLUMN unit_price     DECIMAL(10,2) NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN total_amount   DECIMAL(10,2) NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN paid_amount    DECIMAL(10,2) NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN balance        DECIMAL(10,2) NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN bill_image     TEXT          NULL",
] as $_sql) { try { $pdo->exec($_sql); } catch (\PDOException $e) {} }

// Supplier aggregate stats
$stats = $pdo->prepare("
    SELECT
        COUNT(*)          AS txn_count,
        SUM(quantity)     AS total_qty,
        SUM(total_amount) AS total_purchased,
        SUM(paid_amount)  AS total_paid,
        SUM(balance)      AS total_unpaid,
        MIN(created_at)   AS first_txn,
        MAX(created_at)   AS last_txn
    FROM stock_logs
    WHERE supplier = ?
");
$stats->execute([$supplierName]);
$stats = $stats->fetch();

if (!$stats || !$stats['txn_count']) {
    $_SESSION['error'] = 'No records found for this supplier.';
    header('Location: index.php');
    exit;
}

// Running balance map: cumulative unpaid from oldest → newest transaction
$runningRows = $pdo->prepare(
    "SELECT id, balance FROM stock_logs WHERE supplier = ? ORDER BY created_at ASC, id ASC"
);
$runningRows->execute([$supplierName]);
$runningMap = [];
$cumulative = 0;
foreach ($runningRows->fetchAll() as $r) {
    $cumulative += (float)$r['balance'];
    $runningMap[(int)$r['id']] = $cumulative;
}

// Pagination
$totalRows  = (int)$stats['txn_count'];
$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$logs = $pdo->prepare("
    SELECT sl.*,
           COALESCE(p.name, sl.custom_product, '—') AS product_label,
           p.size, p.color,
           u.full_name AS created_by_name
    FROM stock_logs sl
    LEFT JOIN products p ON p.id = sl.product_id
    JOIN  users      u ON u.id = sl.created_by
    WHERE sl.supplier = ?
    ORDER BY sl.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$logs->execute([$supplierName]);
$logs = $logs->fetchAll();

$pageTitle = htmlspecialchars($supplierName) . ' — Supplier';

require_once '../includes/header.php';
?>

<style>
.stat-card { border-radius: 12px; padding: 18px 20px; border: none; }
.stat-card .stat-val { font-size: 1.45rem; font-weight: 700; line-height: 1.15; letter-spacing: -.5px; }
.stat-card .stat-lbl { font-size: 0.68rem; text-transform: uppercase; letter-spacing: .7px; font-weight: 700; opacity: .65; margin-top: 5px; }
.bill-preview-sm { width: 28px; height: 28px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; }
</style>

<!-- Page header -->
<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <a href="index.php" class="text-muted small d-block mb-1">
            <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_stock') ?>
        </a>
        <h4 class="mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-person-badge" style="color:#0067C0;"></i>
            <?= htmlspecialchars($supplierName) ?>
        </h4>
        <p class="text-muted small mb-0">
            <?= number_format($stats['txn_count']) ?> transactions
            &mdash; since <?= date('d M Y', strtotime($stats['first_txn'])) ?>
        </p>
    </div>
    <a href="add.php?supplier=<?= urlencode($supplierName) ?>"
       class="btn btn-primary">
        <i class="bi bi-plus-square me-2"></i>Add Stock from this Supplier
    </a>
</div>

<!-- Stats -->
<div class="row g-3 mb-3">
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(0,103,192,0.07);">
            <div class="stat-val text-primary"><?= number_format($stats['total_qty']) ?> <small style="font-size:.72rem;font-weight:500;">pcs</small></div>
            <div class="stat-lbl" style="color:#0067C0;">Total Received</div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(80,80,80,0.07);">
            <div class="stat-val">؋<?= number_format($stats['total_purchased'], 0) ?></div>
            <div class="stat-lbl">Total Purchased</div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(16,124,16,0.07);">
            <div class="stat-val text-success">؋<?= number_format($stats['total_paid'], 0) ?></div>
            <div class="stat-lbl" style="color:#107C10;">Total Paid</div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(196,43,28,0.07);">
            <div class="stat-val text-danger">؋<?= number_format($stats['total_unpaid'], 0) ?></div>
            <div class="stat-lbl" style="color:#C42B1C;">Unpaid / Balance</div>
        </div>
    </div>
</div>

<!-- Payment progress bar -->
<?php
$pct = $stats['total_purchased'] > 0
    ? min(100, round($stats['total_paid'] / $stats['total_purchased'] * 100))
    : 0;
?>
<div class="card mb-3" style="border:none;background:rgba(0,0,0,0.035);">
    <div class="card-body py-2 px-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small fw-semibold text-muted">Payment Progress</span>
            <span class="small fw-bold"><?= $pct ?>%</span>
        </div>
        <div class="progress" style="height:8px;border-radius:6px;">
            <div class="progress-bar bg-success" style="width:<?= $pct ?>%;border-radius:6px;"></div>
        </div>
        <div class="d-flex justify-content-between mt-1" style="font-size:0.72rem;color:#888;">
            <span>Paid ؋<?= number_format($stats['total_paid'], 0) ?></span>
            <span>Balance ؋<?= number_format($stats['total_unpaid'], 0) ?></span>
        </div>
    </div>
</div>

<!-- Transactions table -->
<div class="card">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-list-ul"></i>
        All Transactions
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.82rem;">
            <thead class="table-light">
                <tr>
                    <th><?= __('prod_name') ?></th>
                    <th>Type</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Bundles</th>
                    <th class="text-end">Unit Price</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Balance</th>
                    <th class="text-end">Ending Balance</th>
                    <th class="text-center">Bill</th>
                    <th><?= __('field_notes') ?></th>
                    <th><?= __('field_by') ?></th>
                    <th><?= __('field_date') ?></th>
                    <?php if (isAdmin()): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="<?= isAdmin() ? 13 : 12 ?>" class="text-center text-muted py-5">
                    <i class="bi bi-archive fs-3 d-block mb-2 opacity-25"></i>No records found.
                </td></tr>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="max-width:160px;">
                        <span class="fw-semibold d-block"><?= htmlspecialchars($log['product_label']) ?></span>
                        <?php if ($log['size'] || $log['color']): ?>
                        <span class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars(trim(($log['size'] ?? '').' '.($log['color'] ?? ''))) ?></span>
                        <?php endif; ?>
                        <?php if (!$log['product_id'] && !empty($log['custom_product'])): ?>
                        <span class="badge bg-secondary-subtle text-secondary border" style="font-size:0.62rem;">custom</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <?php if ($log['type'] === 'in'): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                            <i class="bi bi-arrow-down-circle me-1"></i>In
                        </span>
                        <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                            <i class="bi bi-arrow-up-circle me-1"></i>Out
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold text-end text-nowrap"><?= number_format($log['quantity']) ?> pcs</td>
                    <td class="text-end text-muted"><?= $log['bundle_count'] ? number_format($log['bundle_count']) : '—' ?></td>
                    <td class="text-end text-muted"><?= $log['unit_price'] ? '؋'.number_format($log['unit_price'], 0) : '—' ?></td>
                    <td class="text-end text-nowrap"><?= $log['total_amount'] ? '؋'.number_format($log['total_amount'], 0) : '—' ?></td>
                    <td class="text-end text-success fw-semibold text-nowrap"><?= $log['paid_amount'] ? '؋'.number_format($log['paid_amount'], 0) : '—' ?></td>
                    <td class="text-end fw-semibold text-nowrap <?= $log['balance'] > 0 ? 'text-danger' : 'text-muted' ?>">
                        <?= $log['balance'] > 0 ? '؋'.number_format($log['balance'], 0) : '—' ?>
                    </td>
                    <?php $ending = $runningMap[(int)$log['id']] ?? 0; ?>
                    <td class="text-end fw-bold text-nowrap <?= $ending > 0 ? 'text-danger' : 'text-success' ?>"
                        title="Total unpaid up to this transaction">
                        <?= $ending > 0 ? '؋'.number_format($ending, 0) : '<span class="text-success">Settled</span>' ?>
                    </td>
                    <td class="text-center">
                        <?php if ($log['bill_image']): ?>
                        <a href="/uploads/stock-bills/<?= htmlspecialchars($log['bill_image']) ?>"
                           target="_blank" title="View Bill">
                            <img src="/uploads/stock-bills/<?= htmlspecialchars($log['bill_image']) ?>"
                                 class="bill-preview-sm" alt="bill"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='inline';">
                            <i class="bi bi-file-earmark-image text-primary" style="display:none;font-size:1.1rem;"></i>
                        </a>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?= htmlspecialchars($log['notes'] ?? '') ?>">
                        <?= htmlspecialchars($log['notes'] ?: '—') ?>
                    </td>
                    <td class="text-muted text-nowrap" style="font-size:0.75rem;"><?= htmlspecialchars($log['created_by_name']) ?></td>
                    <td class="text-muted text-nowrap" style="font-size:0.75rem;"><?= date('d M Y', strtotime($log['created_at'])) ?></td>
                    <?php if (isAdmin()): ?>
                    <td class="text-nowrap">
                        <a href="edit.php?id=<?= $log['id'] ?>" class="btn btn-sm btn-light me-1" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="delete.php?id=<?= $log['id'] ?>"
                           class="btn btn-sm btn-outline-danger" title="Delete"
                           onclick="return confirm('Delete this record?')">
                            <i class="bi bi-trash"></i>
                        </a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex align-items-center justify-content-between gap-2 flex-wrap py-2">
        <span class="text-muted small">
            <?= $totalRows ?> records &mdash; Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?>
        </span>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&#8249;</a>
            </li>
            <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            if ($start > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
            for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
            </li>
            <?php endfor;
            if ($end < $totalPages): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">&#8250;</a>
            </li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
