<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';

$pageTitle = __('stock_title');

// Auto-migrate so this page works even if add.php has never been opened
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

$filter = $_GET['type'] ?? '';
$where  = '';
$params = [];
if ($filter === 'in')  { $where = "WHERE sl.type = 'in'"; }
if ($filter === 'out') { $where = "WHERE sl.type = 'out'"; }

// Aggregate dashboard stats (always full dataset, not filtered)
$stats = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN type='in'  THEN quantity     ELSE 0 END), 0) AS total_in_qty,
        COALESCE(SUM(CASE WHEN type='out' THEN quantity     ELSE 0 END), 0) AS total_out_qty,
        COALESCE(SUM(CASE WHEN type='in'  THEN total_amount ELSE 0 END), 0) AS total_purchased,
        COALESCE(SUM(CASE WHEN type='in'  THEN paid_amount  ELSE 0 END), 0) AS total_paid,
        COALESCE(SUM(CASE WHEN type='in'  THEN balance      ELSE 0 END), 0) AS total_unpaid
    FROM stock_logs
")->fetch();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_logs sl $where");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();

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
    LEFT JOIN products p  ON p.id = sl.product_id
    JOIN  users      u  ON u.id = sl.created_by
    $where
    ORDER BY sl.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$logs->execute($params);
$logs = $logs->fetchAll();

require_once '../includes/header.php';
?>

<style>
.stat-card { border-radius: 12px; padding: 18px 20px; border: none; }
.stat-card .stat-val { font-size: 1.55rem; font-weight: 700; line-height: 1.15; letter-spacing: -.5px; }
.stat-card .stat-lbl { font-size: 0.68rem; text-transform: uppercase; letter-spacing: .7px; font-weight: 700; opacity: .65; margin-top: 5px; }
.stock-table td, .stock-table th { vertical-align: middle; }
.bill-preview-sm { width: 28px; height: 28px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; }
</style>

<!-- Page header -->
<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('stock_title') ?></h4>
        <p class="text-muted small mb-0"><?= __('stock_sub') ?></p>
    </div>
    <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-square me-2"></i><?= __('stock_in_btn') ?></a>
</div>

<!-- ── Dashboard summary ── -->
<div class="row g-3 mb-2">
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(0,103,192,0.07);">
            <div class="stat-val text-primary"><?= number_format($stats['total_in_qty']) ?> <small style="font-size:.75rem;font-weight:500;">pcs</small></div>
            <div class="stat-lbl" style="color:#0067C0;">Stock Received</div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(196,43,28,0.07);">
            <div class="stat-val text-danger"><?= number_format($stats['total_out_qty']) ?> <small style="font-size:.75rem;font-weight:500;">pcs</small></div>
            <div class="stat-lbl" style="color:#C42B1C;">Stock Dispatched</div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(16,124,16,0.07);">
            <div class="stat-val text-success">؋<?= number_format($stats['total_paid'], 0) ?></div>
            <div class="stat-lbl" style="color:#107C10;">Total Paid</div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(157,93,0,0.08);">
            <div class="stat-val" style="color:#9D5D00;">؋<?= number_format($stats['total_unpaid'], 0) ?></div>
            <div class="stat-lbl" style="color:#9D5D00;">Unpaid / Balance</div>
        </div>
    </div>
</div>

<!-- Total purchased banner -->
<div class="card mb-3" style="background:rgba(0,0,0,0.035);border:none;">
    <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between flex-wrap gap-1">
        <span class="small fw-semibold text-muted">Total Purchased from Wholesalers</span>
        <span class="fw-bold fs-6">؋ <?= number_format($stats['total_purchased'], 0) ?></span>
    </div>
</div>

<!-- Filter bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 flex-wrap">
            <a href="index.php" class="btn btn-sm <?= !$filter ? 'btn-dark' : 'btn-light' ?>"><?= __('btn_all') ?></a>
            <a href="?type=in" class="btn btn-sm <?= $filter === 'in' ? 'btn-success' : 'btn-light' ?>">
                <i class="bi bi-arrow-down-circle me-1"></i>Incoming
            </a>
            <a href="?type=out" class="btn btn-sm <?= $filter === 'out' ? 'btn-danger' : 'btn-light' ?>">
                <i class="bi bi-arrow-up-circle me-1"></i>Outgoing
            </a>
        </div>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 stock-table" style="font-size:0.82rem;">
            <thead class="table-light">
                <tr>
                    <th><?= __('prod_name') ?></th>
                    <th>Supplier</th>
                    <th>Type</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Bundles</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Balance</th>
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
                    <i class="bi bi-archive fs-3 d-block mb-2 opacity-25"></i>
                    No stock records found.
                </td></tr>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="max-width:170px;">
                        <span class="fw-semibold d-block"><?= htmlspecialchars($log['product_label']) ?></span>
                        <?php if ($log['size'] || $log['color']): ?>
                        <span class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars(trim(($log['size'] ?? '').' '.($log['color'] ?? ''))) ?></span>
                        <?php endif; ?>
                        <?php if (!$log['product_id'] && !empty($log['custom_product'])): ?>
                        <span class="badge bg-secondary-subtle text-secondary border" style="font-size:0.62rem;">custom</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted text-nowrap"><?= htmlspecialchars($log['supplier'] ?: '—') ?></td>
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
                    <td class="text-end text-nowrap"><?= $log['total_amount'] ? '؋'.number_format($log['total_amount'], 0) : '—' ?></td>
                    <td class="text-end text-success fw-semibold text-nowrap"><?= $log['paid_amount'] ? '؋'.number_format($log['paid_amount'], 0) : '—' ?></td>
                    <td class="text-end fw-semibold text-nowrap <?= $log['balance'] > 0 ? 'text-danger' : 'text-muted' ?>">
                        <?= $log['balance'] > 0 ? '؋'.number_format($log['balance'], 0) : '—' ?>
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
                    <td class="text-muted" style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
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
                           class="btn btn-sm btn-outline-danger"
                           title="Delete"
                           onclick="return confirm('Delete this stock record? This will reverse the quantity change on the product.')">
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
            <?= __('field_total') ?>: <?= $totalRows ?>
            &mdash; Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?>
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
