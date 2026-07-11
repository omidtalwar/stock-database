<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';
require_once '_common.php';

$pageTitle = __('nav_wholesale');

// Optional location filter (drill-down from a location card)
$filterLoc = trim($_GET['location'] ?? '');

// ── Top dashboard totals ──
$totals = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN type='in'  THEN quantity ELSE 0 END),0) AS total_in,
        COALESCE(SUM(CASE WHEN type='out' THEN quantity ELSE 0 END),0) AS total_out,
        COALESCE(SUM(CASE WHEN type='in'  THEN total_value ELSE 0 END),0) AS value_in,
        COUNT(*) AS txn_count,
        COUNT(DISTINCT location) AS loc_count
    FROM wholesale_logs
")->fetch();

$totalIn   = (float)$totals['total_in'];
$totalOut  = (float)$totals['total_out'];
$remaining = $totalIn - $totalOut;

// ── Per-location breakdown (categories) ──
$locations = $pdo->query("
    SELECT
        location,
        COALESCE(SUM(CASE WHEN type='in'  THEN quantity ELSE 0 END),0) AS in_qty,
        COALESCE(SUM(CASE WHEN type='out' THEN quantity ELSE 0 END),0) AS out_qty,
        COALESCE(SUM(CASE WHEN type='in'  THEN total_value ELSE 0 END),0) AS in_value,
        COUNT(*) AS txn_count,
        MAX(created_at) AS last_txn
    FROM wholesale_logs
    GROUP BY location
    ORDER BY (COALESCE(SUM(CASE WHEN type='in' THEN quantity ELSE 0 END),0)
            - COALESCE(SUM(CASE WHEN type='out' THEN quantity ELSE 0 END),0)) DESC,
             last_txn DESC
")->fetchAll();

// ── Transactions (with optional location filter + pagination) ──
$where  = '';
$params = [];
if ($filterLoc !== '') { $where = 'WHERE w.location = ?'; $params[] = $filterLoc; }

$cnt = $pdo->prepare("SELECT COUNT(*) FROM wholesale_logs w $where");
$cnt->execute($params);
$totalRows = (int)$cnt->fetchColumn();

$perPage    = 12;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$logsStmt = $pdo->prepare("
    SELECT w.*, u.full_name AS created_by_name
    FROM wholesale_logs w
    LEFT JOIN users u ON u.id = w.created_by
    $where
    ORDER BY w.created_at DESC, w.id DESC
    LIMIT $perPage OFFSET $offset
");
$logsStmt->execute($params);
$logs = $logsStmt->fetchAll();

require_once '../includes/header.php';
?>

<style>
.ws-accent { --ws:#14B8A6; }
.ws-stat { border-radius:14px; padding:18px 20px; border:none; position:relative; overflow:hidden; }
.ws-stat .v { font-size:1.5rem; font-weight:800; line-height:1.1; letter-spacing:-.5px; }
.ws-stat .l { font-size:0.66rem; text-transform:uppercase; letter-spacing:.7px; font-weight:700; opacity:.7; margin-top:5px; }
.ws-stat .ic { position:absolute; top:14px; inset-inline-end:16px; font-size:1.5rem; opacity:.18; }

/* Location cards */
.ws-loc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:14px; }
.ws-loc {
    border:1px solid var(--w11-border); border-radius:14px; background:#fff;
    padding:16px 16px 14px; text-decoration:none; color:inherit; display:block;
    position:relative; overflow:hidden; transition:transform .15s, box-shadow .2s;
    box-shadow:var(--w11-shadow-sm);
}
.ws-loc:hover { transform:translateY(-3px); box-shadow:var(--w11-shadow-md); color:inherit; }
.ws-loc::before { content:''; position:absolute; inset-block:0; inset-inline-start:0; width:5px; background:var(--lc,#14B8A6); }
.ws-loc.active { outline:2px solid var(--lc,#14B8A6); outline-offset:-1px; }
.ws-loc-name { font-weight:700; font-size:0.98rem; display:flex; align-items:center; gap:7px; }
.ws-loc-pin { width:28px; height:28px; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:.9rem; flex-shrink:0; }
.ws-remain { font-size:1.55rem; font-weight:800; letter-spacing:-.5px; line-height:1; }
.ws-io { display:flex; gap:8px; margin-top:10px; }
.ws-io > div { flex:1; border-radius:9px; padding:6px 9px; font-size:.72rem; font-weight:700; }
.ws-io .in  { background:rgba(16,124,16,0.08); color:#107C10; }
.ws-io .out { background:rgba(196,43,28,0.07); color:#C42B1C; }
.ws-io .num { font-size:.95rem; display:block; }

.type-pill { font-size:.7rem; font-weight:700; padding:2px 9px; border-radius:20px; display:inline-flex; align-items:center; gap:4px; }
.type-pill.in  { background:rgba(16,124,16,0.1);  color:#107C10; }
.type-pill.out { background:rgba(196,43,28,0.09); color:#C42B1C; }

.loc-chip { display:inline-flex; align-items:center; gap:5px; font-size:.72rem; font-weight:700; padding:2px 9px; border-radius:20px; color:#fff; }

@media (max-width:576px){ .ws-loc-grid { grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; } }
</style>

<!-- Page header -->
<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h4 class="mb-1 d-flex align-items-center gap-2">
            <i class="bi bi-boxes" style="color:#14B8A6;"></i> <?= __('nav_wholesale') ?>
        </h4>
        <p class="text-muted small mb-0">Goods in &amp; out by location — Kabul, China, Peshawar &amp; more</p>
    </div>
    <a href="add.php<?= $filterLoc !== '' ? '?location='.urlencode($filterLoc) : '' ?>" class="btn btn-primary"
       style="background:#14B8A6 !important;border-color:#14B8A6 !important;">
        <i class="bi bi-plus-square me-2"></i>New Entry
    </a>
</div>

<!-- Top stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card ws-stat" style="background:rgba(16,124,16,0.07);">
            <i class="bi bi-box-arrow-in-down ic text-success"></i>
            <div class="v text-success"><?= number_format($totalIn) ?> <small style="font-size:.7rem;font-weight:600;">pcs</small></div>
            <div class="l" style="color:#107C10;">Total In</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card ws-stat" style="background:rgba(196,43,28,0.07);">
            <i class="bi bi-box-arrow-up ic text-danger"></i>
            <div class="v text-danger"><?= number_format($totalOut) ?> <small style="font-size:.7rem;font-weight:600;">pcs</small></div>
            <div class="l" style="color:#C42B1C;">Total Out</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card ws-stat" style="background:rgba(20,184,166,0.1);">
            <i class="bi bi-archive ic" style="color:#0d9488;"></i>
            <div class="v" style="color:#0d9488;"><?= number_format($remaining) ?> <small style="font-size:.7rem;font-weight:600;">pcs</small></div>
            <div class="l" style="color:#0d9488;">Remaining (Left)</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card ws-stat" style="background:rgba(0,103,192,0.07);">
            <i class="bi bi-geo-alt ic text-primary"></i>
            <div class="v text-primary"><?= number_format($totals['loc_count']) ?></div>
            <div class="l" style="color:#0067C0;">Locations</div>
        </div>
    </div>
</div>

<!-- Per-location breakdown -->
<?php if (!empty($locations)): ?>
<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
    <h5 class="mb-0 fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-geo-alt-fill" style="color:#14B8A6;"></i> By Location
    </h5>
    <?php if ($filterLoc !== ''): ?>
    <a href="index.php" class="btn btn-sm btn-light">
        <i class="bi bi-x-circle me-1"></i>Clear filter: <strong class="ms-1"><?= htmlspecialchars($filterLoc) ?></strong>
    </a>
    <?php endif; ?>
</div>

<div class="ws-loc-grid mb-4">
    <?php foreach ($locations as $loc):
        $lc     = wsLocationColor($loc['location']);
        $lRem   = (float)$loc['in_qty'] - (float)$loc['out_qty'];
        $active = ($filterLoc === $loc['location']);
    ?>
    <a class="ws-loc <?= $active ? 'active' : '' ?>" style="--lc:<?= $lc ?>;"
       href="index.php?location=<?= urlencode($loc['location']) ?>">
        <div class="d-flex align-items-start justify-content-between mb-2">
            <div class="ws-loc-name">
                <span class="ws-loc-pin" style="background:<?= $lc ?>;"><i class="bi bi-geo-alt-fill"></i></span>
                <?= htmlspecialchars($loc['location']) ?>
            </div>
            <span class="text-muted" style="font-size:.68rem;"><?= number_format($loc['txn_count']) ?> txn</span>
        </div>
        <div class="ws-remain" style="color:<?= $lRem < 0 ? '#C42B1C' : $lc ?>;">
            <?= number_format($lRem) ?> <small style="font-size:.62rem;font-weight:600;color:#888;">pcs left</small>
        </div>
        <div class="ws-io">
            <div class="in"><span class="num">▼ <?= number_format($loc['in_qty']) ?></span>In</div>
            <div class="out"><span class="num">▲ <?= number_format($loc['out_qty']) ?></span>Out</div>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Transactions table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span class="fw-semibold d-flex align-items-center gap-2">
            <i class="bi bi-list-ul" style="color:#14B8A6;"></i>
            <?= $filterLoc !== '' ? htmlspecialchars($filterLoc).' — Transactions' : 'All Transactions' ?>
        </span>
        <div class="d-flex align-items-center gap-2">
            <div class="input-group input-group-sm" style="max-width:230px;">
                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search" style="font-size:.78rem;"></i></span>
                <input type="text" id="wsSearch" class="form-control border-start-0 ps-0"
                       placeholder="Search item, location, notes…" oninput="filterWs()">
            </div>
            <span id="wsCount" class="text-muted small" style="white-space:nowrap;"></span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="wsTable" style="font-size:0.82rem;">
            <thead class="table-light">
                <tr>
                    <th>Item</th>
                    <th>Location</th>
                    <th>Type</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Bundles</th>
                    <th class="text-end">Value</th>
                    <th><?= __('field_notes') ?></th>
                    <th><?= __('field_by') ?></th>
                    <th><?= __('field_date') ?></th>
                    <?php if (isAdmin()): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="<?= isAdmin() ? 10 : 9 ?>" class="text-center text-muted py-5">
                    <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>No entries yet.
                    <div class="mt-2"><a href="add.php" class="btn btn-sm" style="background:#14B8A6;color:#fff;"><i class="bi bi-plus-lg me-1"></i>Add the first one</a></div>
                </td></tr>
                <?php else: foreach ($logs as $log):
                    $isIn   = $log['type'] === 'in';
                    $lc     = wsLocationColor($log['location']);
                    $curSym = CURRENCIES[$log['currency'] ?? 'USD']['symbol'] ?? '$';
                    $search = strtolower(($log['item_name'] ?? '').' '.($log['location'] ?? '').' '.($log['category'] ?? '').' '.($log['notes'] ?? ''));
                ?>
                <tr data-search="<?= htmlspecialchars($search) ?>">
                    <td style="max-width:180px;">
                        <span class="fw-semibold d-block text-truncate"><?= htmlspecialchars($log['item_name'] ?: 'Goods') ?></span>
                        <?php if (!empty($log['category'])): ?>
                        <span class="badge bg-secondary-subtle text-secondary border" style="font-size:.6rem;"><?= htmlspecialchars($log['category']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="loc-chip" style="background:<?= $lc ?>;">
                            <i class="bi bi-geo-alt-fill"></i><?= htmlspecialchars($log['location']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="type-pill <?= $isIn ? 'in' : 'out' ?>">
                            <i class="bi bi-arrow-<?= $isIn ? 'down' : 'up' ?>-circle"></i><?= $isIn ? 'In' : 'Out' ?>
                        </span>
                    </td>
                    <td class="text-end fw-semibold text-nowrap"><?= number_format($log['quantity']) ?> <span class="text-muted fw-normal">pcs</span></td>
                    <td class="text-end text-muted"><?= $log['bundle_count'] ? number_format($log['bundle_count']) : '—' ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ((float)$log['total_value'] > 0): ?>
                            <span class="fw-semibold"><?= $curSym ?> <?= number_format($log['total_value'], 2) ?></span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-muted" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?= htmlspecialchars($log['notes'] ?? '') ?>"><?= htmlspecialchars($log['notes'] ?: '—') ?></td>
                    <td class="text-muted text-nowrap" style="font-size:.75rem;"><?= htmlspecialchars($log['created_by_name'] ?? '—') ?></td>
                    <td class="text-muted text-nowrap" style="font-size:.75rem;">
                        <?= wsShamsiCell(!empty($log['entry_date']) ? $log['entry_date'] : $log['created_at']) ?>
                    </td>
                    <?php if (isAdmin()): ?>
                    <td class="text-nowrap">
                        <a href="edit.php?id=<?= $log['id'] ?>" class="btn btn-sm btn-light me-1" title="Edit"><i class="bi bi-pencil"></i></a>
                        <a href="delete.php?id=<?= $log['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete"
                           onclick="return confirm('Delete this entry?')"><i class="bi bi-trash"></i></a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; endif; ?>
                <tr id="wsNoResults" style="display:none;">
                    <td colspan="<?= isAdmin() ? 10 : 9 ?>" class="text-center text-muted py-4 small">
                        <i class="bi bi-search me-1"></i>No entries match your search.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex align-items-center justify-content-between gap-2 flex-wrap py-2">
        <span class="text-muted small"><?= $totalRows ?> records — Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?></span>
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

<style>
#wsTable .page-link { color:#0d9488; }
.pagination .active .page-link { background:#14B8A6 !important; border-color:#14B8A6 !important; }
</style>

<script>
function filterWs() {
    const q = document.getElementById('wsSearch').value.trim().toLowerCase();
    const rows = document.querySelectorAll('#wsTable tbody tr[data-search]');
    let visible = 0;
    rows.forEach(r => {
        const show = !q || r.dataset.search.includes(q);
        r.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('wsCount').textContent = q ? visible + ' of ' + rows.length : '';
    const nr = document.getElementById('wsNoResults');
    if (nr) nr.style.display = (q && visible === 0) ? '' : 'none';
}
</script>

<?php require_once '../includes/footer.php'; ?>
