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

// Dashboard aggregate stats
$stats = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN type='in' THEN quantity     ELSE 0 END), 0)         AS total_in_qty,
        COALESCE(SUM(CASE WHEN type='in' THEN total_amount ELSE 0 END), 0)         AS total_purchased,
        COALESCE(SUM(CASE WHEN type='in' THEN paid_amount  ELSE 0 END), 0)         AS total_paid,
        COALESCE(SUM(CASE WHEN type='in' THEN balance      ELSE 0 END), 0)         AS total_unpaid,
        COUNT(DISTINCT CASE WHEN supplier IS NOT NULL AND supplier != '' THEN supplier END) AS total_suppliers
    FROM stock_logs
")->fetch();

// Supplier summary
$supplierStats = $pdo->query("
    SELECT
        supplier,
        COUNT(*)          AS txn_count,
        SUM(quantity)     AS total_qty,
        SUM(total_amount) AS total_purchased,
        SUM(paid_amount)  AS total_paid,
        SUM(balance)      AS total_unpaid,
        MAX(created_at)   AS last_txn
    FROM stock_logs
    WHERE supplier IS NOT NULL AND supplier != ''
    GROUP BY supplier
    ORDER BY total_unpaid DESC, last_txn DESC
")->fetchAll();

require_once '../includes/header.php';
?>

<style>
.stat-card { border-radius: 12px; padding: 18px 20px; border: none; }
.stat-card .stat-val { font-size: 1.55rem; font-weight: 700; line-height: 1.15; letter-spacing: -.5px; }
.stat-card .stat-lbl { font-size: 0.68rem; text-transform: uppercase; letter-spacing: .7px; font-weight: 700; opacity: .65; margin-top: 5px; }
</style>

<!-- Page header -->
<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('stock_title') ?></h4>
        <p class="text-muted small mb-0"><?= __('stock_sub') ?></p>
    </div>
    <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-square me-2"></i><?= __('stock_in_btn') ?></a>
</div>

<!-- Dashboard stats -->
<div class="row g-3 mb-2">
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(0,103,192,0.07);">
            <div class="stat-val text-primary"><?= number_format($stats['total_in_qty']) ?> <small style="font-size:.75rem;font-weight:500;">pcs</small></div>
            <div class="stat-lbl" style="color:#0067C0;">Stock Received</div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(196,43,28,0.07);">
            <div class="stat-val text-danger"><?= number_format($stats['total_suppliers']) ?></div>
            <div class="stat-lbl" style="color:#C42B1C;">Total Suppliers</div>
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
<div class="card mb-4" style="background:rgba(0,0,0,0.035);border:none;">
    <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between flex-wrap gap-1">
        <span class="small fw-semibold text-muted">Total Purchased from Wholesalers</span>
        <span class="fw-bold fs-6">؋ <?= number_format($stats['total_purchased'], 0) ?></span>
    </div>
</div>

<!-- Supplier / Wholesaler Accounts -->
<div class="d-flex align-items-center justify-content-between mb-2">
    <h5 class="mb-0 fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-people" style="color:#0067C0;"></i>
        Supplier / Wholesaler Accounts
    </h5>
</div>

<?php if (empty($supplierStats)): ?>
<div class="card border-0" style="background:rgba(0,0,0,0.03);">
    <div class="card-body text-center text-muted py-5">
        <i class="bi bi-people fs-2 d-block mb-2 opacity-25"></i>
        No suppliers recorded yet. Add a supplier name when logging incoming stock.
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
            <thead class="table-light">
                <tr>
                    <th>Supplier / Wholesaler</th>
                    <th class="text-end">Transactions</th>
                    <th class="text-end">Total Qty</th>
                    <th class="text-end">Total Purchased</th>
                    <th class="text-end">Total Paid</th>
                    <th class="text-end">Balance / Unpaid</th>
                    <th>Last Activity</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($supplierStats as $s): ?>
                <tr style="cursor:pointer;" onclick="location.href='supplier.php?name=<?= urlencode($s['supplier']) ?>'">
                    <td class="fw-semibold"><?= htmlspecialchars($s['supplier']) ?></td>
                    <td class="text-end text-muted"><?= number_format($s['txn_count']) ?></td>
                    <td class="text-end text-muted"><?= number_format($s['total_qty']) ?> pcs</td>
                    <td class="text-end fw-semibold"><?= $s['total_purchased'] ? '؋'.number_format($s['total_purchased'], 0) : '—' ?></td>
                    <td class="text-end text-success fw-semibold"><?= $s['total_paid'] ? '؋'.number_format($s['total_paid'], 0) : '—' ?></td>
                    <td class="text-end fw-bold <?= $s['total_unpaid'] > 0 ? 'text-danger' : 'text-muted' ?>">
                        <?= $s['total_unpaid'] > 0 ? '؋'.number_format($s['total_unpaid'], 0) : '—' ?>
                    </td>
                    <td class="text-muted" style="font-size:0.75rem;"><?= date('d M Y', strtotime($s['last_txn'])) ?></td>
                    <td class="text-nowrap">
                        <a href="supplier.php?name=<?= urlencode($s['supplier']) ?>"
                           class="btn btn-sm btn-light me-1"
                           onclick="event.stopPropagation()"
                           title="View Transactions">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="add.php?supplier=<?= urlencode($s['supplier']) ?>"
                           class="btn btn-sm btn-outline-primary"
                           onclick="event.stopPropagation()"
                           title="Add Stock from this Supplier">
                            <i class="bi bi-plus-circle"></i>
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
