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

// ── Time-period filter (Shamsi) ──
$period = $_GET['period'] ?? 'all';
if (!in_array($period, ['all','today','week','month','year','custom'], true)) $period = 'all';
[$rangeStart, $rangeEnd, $rangeLabel] = wsResolvePeriod($period, $_GET);

// Date condition on entry_date (fallback to created_at). Two prefixes for the
// aliased transactions query vs. the plain aggregate queries.
$dateParams = [];
$dateCond   = '';   // bare column names
$dateCondW  = '';   // w.-prefixed
if ($rangeStart && $rangeEnd) {
    $dateCond   = "COALESCE(entry_date, DATE(created_at)) BETWEEN ? AND ?";
    $dateCondW  = "COALESCE(w.entry_date, DATE(w.created_at)) BETWEEN ? AND ?";
    $dateParams = [$rangeStart, $rangeEnd];
}

// Query-string of active filters to preserve across links (excludes page/location)
$keep = ['period' => $period];
if ($period === 'custom') {
    foreach (['cf_y','cf_m','cf_d','ct_y','ct_m','ct_d'] as $k) if (isset($_GET[$k])) $keep[$k] = $_GET[$k];
}
$periodQS = http_build_query($keep);

// ── Top dashboard totals (money) ──
$sqlTotals = "
    SELECT
        COALESCE(SUM(CASE WHEN type='in'  THEN total_value ELSE 0 END),0) AS money_in,
        COALESCE(SUM(CASE WHEN type='out' THEN total_value ELSE 0 END),0) AS money_out,
        COUNT(*) AS txn_count,
        COUNT(DISTINCT location) AS loc_count
    FROM wholesale_logs" . ($dateCond ? " WHERE $dateCond" : "");
$stmt = $pdo->prepare($sqlTotals);
$stmt->execute($dateParams);
$totals = $stmt->fetch();

$moneyIn   = (float)$totals['money_in'];
$moneyOut  = (float)$totals['money_out'];
$remaining = $moneyIn - $moneyOut;

// ── Per-location breakdown (money) ──
$sqlLoc = "
    SELECT
        location,
        COALESCE(SUM(CASE WHEN type='in'  THEN total_value ELSE 0 END),0) AS in_amt,
        COALESCE(SUM(CASE WHEN type='out' THEN total_value ELSE 0 END),0) AS out_amt,
        COUNT(*) AS txn_count,
        MAX(created_at) AS last_txn
    FROM wholesale_logs
    WHERE location IS NOT NULL AND location != ''" . ($dateCond ? " AND $dateCond" : "") . "
    GROUP BY location
    ORDER BY (COALESCE(SUM(CASE WHEN type='in' THEN total_value ELSE 0 END),0)
            - COALESCE(SUM(CASE WHEN type='out' THEN total_value ELSE 0 END),0)) DESC,
             last_txn DESC";
$stmt = $pdo->prepare($sqlLoc);
$stmt->execute($dateParams);
$locations = $stmt->fetchAll();

// ── Transactions (with optional location + date filters + pagination) ──
$conds  = [];
$params = [];
if ($filterLoc !== '') { $conds[] = 'w.location = ?'; $params[] = $filterLoc; }
if ($dateCondW)        { $conds[] = $dateCondW; foreach ($dateParams as $dp) $params[] = $dp; }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

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

// Custom-range picker defaults (Shamsi)
$todayShamsi = wsToShamsi((int)date('Y'), (int)date('n'), (int)date('j'));
$jMonths     = ['۱ حمل','۲ ثور','۳ جوزا','۴ سرطان','۵ اسد','۶ سنبله',
                '۷ میزان','۸ عقرب','۹ قوس','۱۰ جدی','۱۱ دلو','۱۲ حوت'];
$cf = ['y' => (int)($_GET['cf_y'] ?? $todayShamsi['y']), 'm' => (int)($_GET['cf_m'] ?? $todayShamsi['m']), 'd' => (int)($_GET['cf_d'] ?? 1)];
$ct = ['y' => (int)($_GET['ct_y'] ?? $todayShamsi['y']), 'm' => (int)($_GET['ct_m'] ?? $todayShamsi['m']), 'd' => (int)($_GET['ct_d'] ?? $todayShamsi['d'])];

require_once '../includes/header.php';
?>

<style>
.ws-stat { border-radius:14px; padding:18px 20px; border:none; position:relative; overflow:hidden; }
.ws-stat .v { font-size:1.4rem; font-weight:800; line-height:1.1; letter-spacing:-.5px; }
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
.ws-remain { font-size:1.4rem; font-weight:800; letter-spacing:-.5px; line-height:1; }
.ws-io { display:flex; gap:8px; margin-top:10px; }
.ws-io > div { flex:1; border-radius:9px; padding:6px 9px; font-size:.72rem; font-weight:700; }
.ws-io .in  { background:rgba(16,124,16,0.08); color:#107C10; }
.ws-io .out { background:rgba(196,43,28,0.07); color:#C42B1C; }
.ws-io .num { font-size:.9rem; display:block; }

.type-pill { font-size:.7rem; font-weight:700; padding:2px 9px; border-radius:20px; display:inline-flex; align-items:center; gap:4px; }
.type-pill.in  { background:rgba(16,124,16,0.1);  color:#107C10; }
.type-pill.out { background:rgba(196,43,28,0.09); color:#C42B1C; }

.loc-chip { display:inline-flex; align-items:center; gap:5px; font-size:.72rem; font-weight:700; padding:2px 9px; border-radius:20px; color:#fff; }

@media (max-width:576px){ .ws-loc-grid { grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; } }

/* Period filter */
.ws-filter { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
.ws-fbtn {
    border:1px solid var(--w11-border); background:#fff; border-radius:20px;
    padding:5px 14px; font-size:.8rem; font-weight:600; cursor:pointer; color:#555;
    text-decoration:none; display:inline-flex; align-items:center; gap:5px; transition:all .15s; white-space:nowrap;
}
.ws-fbtn:hover { border-color:#14B8A6; color:#0d9488; }
.ws-fbtn.active { background:#14B8A6; border-color:#14B8A6; color:#fff; }
.ws-range-cap { font-size:.72rem; color:#888; }
.ws-custom { background:rgba(20,184,166,0.05); border:1px solid rgba(20,184,166,0.2); border-radius:12px; }
</style>

<!-- Page header -->
<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h4 class="mb-1 d-flex align-items-center gap-2">
            <i class="bi bi-boxes" style="color:#14B8A6;"></i> <?= __('nav_wholesale') ?>
        </h4>
        <p class="text-muted small mb-0">Money in &amp; out by location — Kabul, China, Lahore, Gami &amp; more</p>
    </div>
    <a href="add.php<?= $filterLoc !== '' ? '?location='.urlencode($filterLoc) : '' ?>" class="btn btn-primary"
       style="background:#14B8A6 !important;border-color:#14B8A6 !important;">
        <i class="bi bi-plus-square me-2"></i>New Entry
    </a>
</div>

<!-- Period filter (Shamsi) -->
<?php
$locQS = $filterLoc !== '' ? '&location=' . urlencode($filterLoc) : '';
$pBtns = ['all' => 'All', 'today' => 'Today', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'];
?>
<div class="card mb-3" style="border:none;background:rgba(0,0,0,0.02);">
    <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="ws-filter">
            <i class="bi bi-funnel" style="color:#14B8A6;"></i>
            <?php foreach ($pBtns as $key => $lbl): ?>
            <a href="?period=<?= $key ?><?= $locQS ?>" class="ws-fbtn <?= $period === $key ? 'active' : '' ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
            <button type="button" class="ws-fbtn <?= $period === 'custom' ? 'active' : '' ?>" id="wsCustomToggle">
                <i class="bi bi-calendar-range"></i>Custom
            </button>
        </div>
        <span class="ws-range-cap">
            <i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars($rangeLabel) ?>
            <?php if ($rangeStart && $rangeEnd): ?>
                <?php $rs = wsToShamsi((int)date('Y',strtotime($rangeStart)),(int)date('n',strtotime($rangeStart)),(int)date('j',strtotime($rangeStart)));
                      $re = wsToShamsi((int)date('Y',strtotime($rangeEnd)),(int)date('n',strtotime($rangeEnd)),(int)date('j',strtotime($rangeEnd))); ?>
                — <?= $rs['y'] ?>/<?= str_pad((string)$rs['m'],2,'0',STR_PAD_LEFT) ?>/<?= str_pad((string)$rs['d'],2,'0',STR_PAD_LEFT) ?>
                → <?= $re['y'] ?>/<?= str_pad((string)$re['m'],2,'0',STR_PAD_LEFT) ?>/<?= str_pad((string)$re['d'],2,'0',STR_PAD_LEFT) ?>
            <?php endif; ?>
        </span>
    </div>
    <!-- Custom Shamsi range -->
    <div class="ws-custom mx-3 mb-3 px-3 py-3" id="wsCustomBox" style="display:<?= $period === 'custom' ? 'block' : 'none' ?>;">
        <form method="GET" class="d-flex flex-wrap align-items-end gap-3">
            <input type="hidden" name="period" value="custom">
            <?php if ($filterLoc !== ''): ?><input type="hidden" name="location" value="<?= htmlspecialchars($filterLoc) ?>"><?php endif; ?>
            <div>
                <label class="form-label fw-semibold mb-1 small">From <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.55rem;">Shamsi</span></label>
                <div class="d-flex align-items-center gap-1">
                    <input type="number" name="cf_y" class="form-control form-control-sm text-center fw-semibold" value="<?= $cf['y'] ?>" min="1300" max="1600" style="width:66px;">
                    <span class="text-muted">/</span>
                    <select name="cf_m" class="form-select form-select-sm" style="width:112px;">
                        <?php foreach ($jMonths as $i => $nm): ?><option value="<?= $i+1 ?>" <?= $cf['m'] === $i+1 ? 'selected' : '' ?>><?= $nm ?></option><?php endforeach; ?>
                    </select>
                    <span class="text-muted">/</span>
                    <input type="number" name="cf_d" class="form-control form-control-sm text-center fw-semibold" value="<?= $cf['d'] ?>" min="1" max="31" style="width:54px;">
                </div>
            </div>
            <div>
                <label class="form-label fw-semibold mb-1 small">To <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.55rem;">Shamsi</span></label>
                <div class="d-flex align-items-center gap-1">
                    <input type="number" name="ct_y" class="form-control form-control-sm text-center fw-semibold" value="<?= $ct['y'] ?>" min="1300" max="1600" style="width:66px;">
                    <span class="text-muted">/</span>
                    <select name="ct_m" class="form-select form-select-sm" style="width:112px;">
                        <?php foreach ($jMonths as $i => $nm): ?><option value="<?= $i+1 ?>" <?= $ct['m'] === $i+1 ? 'selected' : '' ?>><?= $nm ?></option><?php endforeach; ?>
                    </select>
                    <span class="text-muted">/</span>
                    <input type="number" name="ct_d" class="form-control form-control-sm text-center fw-semibold" value="<?= $ct['d'] ?>" min="1" max="31" style="width:54px;">
                </div>
            </div>
            <button type="submit" class="btn btn-sm fw-semibold" style="background:#14B8A6;color:#fff;"><i class="bi bi-check-lg me-1"></i>Apply</button>
        </form>
    </div>
</div>
<script>
document.getElementById('wsCustomToggle')?.addEventListener('click', function () {
    const box = document.getElementById('wsCustomBox');
    box.style.display = box.style.display === 'none' ? 'block' : 'none';
});
</script>

<!-- Top stats (money) -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card ws-stat" style="background:rgba(16,124,16,0.07);">
            <i class="bi bi-box-arrow-in-down ic text-success"></i>
            <div class="v text-success">$ <?= number_format($moneyIn, 0) ?></div>
            <div class="l" style="color:#107C10;">Total In $</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card ws-stat" style="background:rgba(196,43,28,0.07);">
            <i class="bi bi-box-arrow-up ic text-danger"></i>
            <div class="v text-danger">$ <?= number_format($moneyOut, 0) ?></div>
            <div class="l" style="color:#C42B1C;">Total Out $</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card ws-stat" style="background:rgba(20,184,166,0.1);">
            <i class="bi bi-cash-stack ic" style="color:#0d9488;"></i>
            <div class="v" style="color:<?= $remaining < 0 ? '#C42B1C' : '#0d9488' ?>;">$ <?= number_format($remaining, 0) ?></div>
            <div class="l" style="color:#0d9488;">Remaining (Left) $</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card ws-stat" style="background:rgba(0,103,192,0.07);">
            <i class="bi bi-geo-alt ic text-primary"></i>
            <div class="v text-primary"><?= number_format($totals['loc_count']) ?></div>
            <div class="l" style="color:#0067C0;">Locations  +</div>
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
    <a href="index.php?<?= $periodQS ?>" class="btn btn-sm btn-light">
        <i class="bi bi-x-circle me-1"></i>Clear filter: <strong class="ms-1"><?= htmlspecialchars($filterLoc) ?></strong>
    </a>
    <?php endif; ?>
</div>

<div class="ws-loc-grid mb-4">
    <?php foreach ($locations as $loc):
        $lc     = wsLocationColor($loc['location']);
        $active = ($filterLoc === $loc['location']);
    ?>
    <a class="ws-loc <?= $active ? 'active' : '' ?>" style="--lc:<?= $lc ?>;"
       href="index.php?location=<?= urlencode($loc['location']) ?>&<?= $periodQS ?>">
        <div class="d-flex align-items-start justify-content-between mb-2">
            <div class="ws-loc-name">
                <span class="ws-loc-pin" style="background:<?= $lc ?>;"><i class="bi bi-geo-alt-fill"></i></span>
                <?= htmlspecialchars($loc['location']) ?>
            </div>
            <span class="text-muted" style="font-size:.68rem;"><?= number_format($loc['txn_count']) ?> txn</span>
        </div>
        <div class="ws-remain" style="color:<?= $lc ?>;">
            $ <?= number_format($loc['in_amt'], 0) ?> <small style="font-size:.6rem;font-weight:600;color:#888;">in</small>
        </div>
        <div class="ws-io">
            <div class="in"><span class="num">▼ $<?= number_format($loc['in_amt'], 0) ?></span>Received</div>
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
                       placeholder="Search location, notes…" oninput="filterWs()">
            </div>
            <span id="wsCount" class="text-muted small" style="white-space:nowrap;"></span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="wsTable" style="font-size:0.82rem;">
            <thead class="table-light">
                <tr>
                    <th>Location</th>
                    <th>Type</th>
                    <th class="text-end">Amount</th>
                    <th><?= __('field_notes') ?></th>
                    <th><?= __('field_by') ?></th>
                    <th><?= __('field_date') ?></th>
                    <?php if (isAdmin()): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <?php $isFiltered = ($period !== 'all' || $filterLoc !== ''); ?>
                <tr><td colspan="<?= isAdmin() ? 7 : 6 ?>" class="text-center text-muted py-5">
                    <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>
                    <?php if ($isFiltered): ?>
                        No entries for this filter.
                        <div class="mt-2"><a href="index.php" class="btn btn-sm btn-light"><i class="bi bi-x-circle me-1"></i>Clear filters</a></div>
                    <?php else: ?>
                        No entries yet.
                        <div class="mt-2"><a href="add.php" class="btn btn-sm" style="background:#14B8A6;color:#fff;"><i class="bi bi-plus-lg me-1"></i>Add the first one</a></div>
                    <?php endif; ?>
                </td></tr>
                <?php else: foreach ($logs as $log):
                    $isIn   = $log['type'] === 'in';
                    $lc     = wsLocationColor($log['location']);
                    $search = strtolower(($log['location'] ?? '').' '.($log['notes'] ?? ''));
                ?>
                <tr data-search="<?= htmlspecialchars($search) ?>">
                    <td>
                        <?php if (!empty($log['location'])): ?>
                        <span class="loc-chip" style="background:<?= $lc ?>;">
                            <i class="bi bi-geo-alt-fill"></i><?= htmlspecialchars($log['location']) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:.72rem;"><i class="bi bi-dash-circle me-1"></i>No location</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="type-pill <?= $isIn ? 'in' : 'out' ?>">
                            <i class="bi bi-arrow-<?= $isIn ? 'down' : 'up' ?>-circle"></i><?= $isIn ? 'In' : 'Out' ?>
                        </span>
                    </td>
                    <td class="text-end fw-bold text-nowrap <?= $isIn ? 'text-success' : 'text-danger' ?>">
                        $ <?= number_format($log['total_value'], 2) ?>
                    </td>
                    <td class="text-muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
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
                    <td colspan="<?= isAdmin() ? 7 : 6 ?>" class="text-center text-muted py-4 small">
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
