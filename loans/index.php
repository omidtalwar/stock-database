<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = 'Loans';

// Auto-migrate: create tables if not exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS loans (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        borrower    VARCHAR(255) NOT NULL,
        phone       VARCHAR(60),
        amount      DECIMAL(15,2) NOT NULL,
        currency    VARCHAR(3) NOT NULL DEFAULT 'AFN',
        paid        DECIMAL(15,2) NOT NULL DEFAULT 0,
        description TEXT,
        due_date    DATE,
        status      ENUM('active','paid','overdue') NOT NULL DEFAULT 'active',
        created_by  INT,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS loan_payments (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        loan_id      INT NOT NULL,
        amount       DECIMAL(15,2) NOT NULL,
        currency     VARCHAR(3) NOT NULL DEFAULT 'AFN',
        payment_date DATE,
        notes        TEXT,
        created_by   INT,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// Auto-update overdue status
$pdo->exec("UPDATE loans SET status='overdue' WHERE due_date < CURDATE() AND status='active' AND paid < amount");

$rates   = getAllRates($pdo);

// ── Stats ──────────────────────────────────────────────────────────
$stats = $pdo->query("
    SELECT
        COUNT(*)                                              AS total_count,
        SUM(amount - paid)                                    AS total_remaining,
        SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END)       AS paid_count,
        SUM(CASE WHEN status='overdue' THEN 1 ELSE 0 END)    AS overdue_count,
        SUM(paid)                                             AS total_paid
    FROM loans
")->fetch(PDO::FETCH_ASSOC);

// ── Filters ────────────────────────────────────────────────────────
$status  = in_array($_GET['status'] ?? '', ['active','paid','overdue','all']) ? $_GET['status'] : 'all';
$search  = trim($_GET['search'] ?? '');
$where   = [];
$params  = [];

if ($status !== 'all') { $where[] = 'l.status = ?'; $params[] = $status; }
if ($search)           { $where[] = '(l.borrower LIKE ? OR l.phone LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalRows  = (int)$pdo->prepare("SELECT COUNT(*) FROM loans l $whereSQL")->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM loans l $whereSQL") : 0;
$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM loans l $whereSQL");
$cntStmt->execute($params);
$totalRows = (int)$cntStmt->fetchColumn();

$perPage    = 12;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT l.*, u.full_name AS created_by_name
    FROM loans l
    LEFT JOIN users u ON u.id = l.created_by
    $whereSQL
    ORDER BY
        CASE l.status WHEN 'overdue' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
        l.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$loans = $stmt->fetchAll();

require_once '../includes/header.php';

$statusLabels = ['active' => 'Active', 'paid' => 'Paid', 'overdue' => 'Overdue', 'all' => 'All'];
$statusColors = ['active' => '#F59E0B', 'paid' => '#4ADE80', 'overdue' => '#F87171'];
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><i class="bi bi-bank me-2" style="color:#F59E0B;"></i>Loans</h4>
        <p class="text-muted small mb-0">Track money lent out and repayments</p>
    </div>
    <a href="add.php" class="btn btn-primary">
        <i class="bi bi-plus-circle me-2"></i>New Loan
    </a>
</div>

<!-- ── Stat cards ── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:rgba(245,158,11,0.12);color:#F59E0B;"><i class="bi bi-bank"></i></div>
                <div>
                    <div class="text-muted" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Total Loans</div>
                    <div class="fw-bold" style="font-size:1.25rem;"><?= (int)$stats['total_count'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:rgba(248,113,113,0.12);color:#F87171;"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="text-muted" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Remaining</div>
                    <div class="fw-bold text-danger" style="font-size:1.15rem;">؋ <?= number_format((float)$stats['total_remaining'], 0) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:rgba(74,222,128,0.12);color:#4ADE80;"><i class="bi bi-check-circle-fill"></i></div>
                <div>
                    <div class="text-muted" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Fully Paid</div>
                    <div class="fw-bold text-success" style="font-size:1.25rem;"><?= (int)$stats['paid_count'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:rgba(248,113,113,0.12);color:#F87171;"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                    <div class="text-muted" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Overdue</div>
                    <div class="fw-bold" style="font-size:1.25rem;color:#F87171;"><?= (int)$stats['overdue_count'] ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Loan list ── -->
<div class="card">
    <div class="card-header py-3">
        <form method="GET" class="d-flex align-items-center gap-2 justify-content-between flex-wrap">
            <!-- Search -->
            <div class="input-group" style="max-width:320px;flex:1 1 200px;">
                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search" style="font-size:.8rem;"></i></span>
                <input type="text" name="search" class="form-control border-start-0 ps-0"
                       placeholder="Borrower or phone…"
                       value="<?= htmlspecialchars($search) ?>">
                <?php if ($search): ?>
                <a href="?status=<?= $status ?>" class="btn btn-outline-secondary border-start-0"><i class="bi bi-x"></i></a>
                <?php else: ?>
                <button class="btn btn-outline-secondary border-start-0" type="submit"><i class="bi bi-arrow-right" style="font-size:.8rem;"></i></button>
                <?php endif; ?>
            </div>
            <!-- Status filter -->
            <div class="d-flex gap-1 flex-wrap">
                <?php foreach (['all','active','overdue','paid'] as $s): ?>
                <a href="?status=<?= $s ?><?= $search ? '&search='.urlencode($search) : '' ?>"
                   class="btn btn-sm <?= $status === $s ? 'btn-primary' : 'btn-light' ?>"
                   style="border-radius:20px;font-size:.78rem;">
                    <?= $statusLabels[$s] ?>
                </a>
                <?php endforeach; ?>
            </div>
            <span class="text-muted small ms-auto"><?= $totalRows ?> loans</span>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="d-none d-md-table-cell">#</th>
                    <th>Borrower</th>
                    <th>Loan Amount</th>
                    <th>Paid</th>
                    <th>Remaining</th>
                    <th class="d-none d-md-table-cell">Due Date</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($loans)): ?>
                <tr><td colspan="8" class="text-center text-muted py-5">No loans found</td></tr>
                <?php else: foreach ($loans as $i => $l):
                    $remaining = (float)$l['amount'] - (float)$l['paid'];
                    $pct       = $l['amount'] > 0 ? min(100, round(($l['paid'] / $l['amount']) * 100)) : 0;
                    $sBadge    = match($l['status']) {
                        'paid'    => 'bg-success-subtle text-success border border-success-subtle',
                        'overdue' => 'bg-danger-subtle text-danger border border-danger-subtle',
                        default   => 'bg-warning-subtle text-warning border border-warning-subtle',
                    };
                ?>
                <tr>
                    <td class="text-muted small d-none d-md-table-cell"><?= $offset + $i + 1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div style="width:34px;height:34px;border-radius:8px;background:linear-gradient(135deg,#F59E0B,#D97706);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:.8rem;flex-shrink:0;">
                                <?= strtoupper(substr($l['borrower'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($l['borrower']) ?></div>
                                <?php if ($l['phone']): ?>
                                <div class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars($l['phone']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="fw-semibold"><?= formatMoney($l['amount'], $l['currency']) ?></td>
                    <td class="text-success fw-semibold">
                        <?= formatMoney($l['paid'], $l['currency']) ?>
                        <?php if ($pct > 0): ?>
                        <div style="height:3px;border-radius:2px;background:#e9ecef;margin-top:4px;width:60px;">
                            <div style="height:3px;border-radius:2px;background:#4ADE80;width:<?= $pct ?>%;"></div>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="fw-bold <?= $remaining > 0 ? 'text-danger' : 'text-success' ?>">
                        <?= $remaining > 0 ? formatMoney($remaining, $l['currency']) : '—' ?>
                    </td>
                    <td class="text-muted small d-none d-md-table-cell">
                        <?= $l['due_date'] ? date('d M Y', strtotime($l['due_date'])) : '—' ?>
                    </td>
                    <td>
                        <span class="badge <?= $sBadge ?>" style="font-size:.7rem;">
                            <?= ucfirst($l['status']) ?>
                        </span>
                    </td>
                    <td>
                        <a href="view.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-light me-1" title="View"><i class="bi bi-eye"></i></a>
                        <?php if ($l['status'] !== 'paid'): ?>
                        <a href="pay.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-success me-1" title="Record Payment"><i class="bi bi-cash-stack"></i></a>
                        <?php endif; ?>
                        <?php if (isAdmin()): ?>
                        <a href="delete.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('Delete this loan record?')" title="Delete">
                            <i class="bi bi-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex align-items-center justify-content-between gap-2 flex-wrap py-2">
        <span class="text-muted small">
            Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> of <?= $totalRows ?>
        </span>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&#8249;</a>
            </li>
            <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">&#8250;</a>
            </li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
