<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '_common.php';

$pageTitle = __('nav_warehouse');

// ── Stock per category + top totals ──
$stock = whStockByCategory($pdo);

$totalTan = 0.0; $totalGaz = 0.0; $activeCats = 0;
foreach ($stock as $s) {
    $totalTan += (float)$s['tan'];
    $totalGaz += (float)$s['gaz'];
    if ((float)$s['tan'] > 0 || (float)$s['gaz'] > 0) $activeCats++;
}

$counts = $pdo->query("
    SELECT COALESCE(SUM(type='in'),0) AS in_c, COALESCE(SUM(type='out'),0) AS out_c, COUNT(*) AS all_c
    FROM warehouse_logs
")->fetch(PDO::FETCH_ASSOC);

// ── Transactions (type filter + pagination) ──
$filterType = in_array($_GET['type'] ?? '', ['in','out'], true) ? $_GET['type'] : '';
$where = $filterType ? "WHERE w.type = " . ($filterType === 'in' ? "'in'" : "'out'") : '';

$totalRows  = (int)$pdo->query("SELECT COUNT(*) FROM warehouse_logs w $where")->fetchColumn();
$perPage    = 15;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$logs = $pdo->query("
    SELECT w.*, u.full_name AS by_name
    FROM warehouse_logs w
    LEFT JOIN users u ON u.id = w.created_by
    $where
    ORDER BY w.created_at DESC, w.id DESC
    LIMIT $perPage OFFSET $offset
")->fetchAll(PDO::FETCH_ASSOC);

$knownCategories = whKnownCategories($pdo);

// Availability map for the distribute form (client-side hint + guard)
$availMap = [];
foreach ($stock as $s) $availMap[$s['category']] = ['tan' => (float)$s['tan'], 'gaz' => (float)$s['gaz']];

// Chart data — only categories that currently hold stock
$chartLabels = []; $chartTan = []; $chartGaz = []; $chartColors = [];
foreach ($stock as $s) {
    if ((float)$s['tan'] <= 0 && (float)$s['gaz'] <= 0) continue;
    $chartLabels[] = $s['category'];
    $chartTan[]    = round((float)$s['tan'], 2);
    $chartGaz[]    = round((float)$s['gaz'], 2);
    $chartColors[] = whCategoryColor($s['category']);
}

$todayShamsi = whToShamsi((int)date('Y'), (int)date('n'), (int)date('j'));
$jMonths = ['۱ حمل','۲ ثور','۳ جوزا','۴ سرطان','۵ اسد','۶ سنبله','۷ میزان','۸ عقرب','۹ قوس','۱۰ جدی','۱۱ دلو','۱۲ حوت'];
$csrf = generateFormToken('warehouse');

require_once '../includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap');
.font-pashto { font-family:'Vazirmatn', 'Segoe UI', Tahoma, sans-serif; }
.wh-accent { color:#6366F1; }
.wh-stat { border-radius:14px; padding:18px 20px; border:none; position:relative; overflow:hidden; }
.wh-stat .v { font-size:1.5rem; font-weight:800; line-height:1.1; letter-spacing:-.5px; }
.wh-stat .l { font-size:.66rem; text-transform:uppercase; letter-spacing:.7px; font-weight:700; opacity:.7; margin-top:5px; }
.wh-stat .ic { position:absolute; top:14px; inset-inline-end:16px; font-size:1.6rem; opacity:.16; }

.wh-cat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:14px; }
.wh-cat {
    border:1px solid var(--w11-border); border-radius:14px; background:#fff;
    padding:15px 15px 12px; position:relative; overflow:hidden;
    box-shadow:var(--w11-shadow-sm); transition:transform .15s, box-shadow .2s;
}
.wh-cat:hover { transform:translateY(-3px); box-shadow:var(--w11-shadow-md); }
.wh-cat::before { content:''; position:absolute; inset-block:0; inset-inline-start:0; width:5px; background:var(--cc,#6366F1); }
.wh-cat-name { font-weight:700; font-size:1.02rem; direction:rtl; text-align:start; margin-bottom:10px; min-height:1.5em; }
.wh-cat-pin { width:30px; height:30px; border-radius:9px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:.95rem; flex-shrink:0; }
.wh-units { display:flex; align-items:flex-end; gap:14px; }
.wh-units .num { font-size:1.5rem; font-weight:800; letter-spacing:-.5px; line-height:1; }
.wh-units .u { font-size:.6rem; text-transform:uppercase; letter-spacing:.5px; color:#888; font-weight:700; margin-top:3px; }
.wh-units .sep { width:1px; align-self:stretch; background:var(--w11-border); }
.wh-distribute-btn {
    margin-top:12px; width:100%; border:none; border-radius:9px; padding:7px;
    font-size:.78rem; font-weight:700; background:rgba(99,102,241,.08); color:#4f46e5; transition:all .15s;
}
.wh-distribute-btn:hover { background:rgba(99,102,241,.16); }

.type-pill { font-size:.7rem; font-weight:700; padding:2px 9px; border-radius:20px; display:inline-flex; align-items:center; gap:4px; }
.type-pill.in  { background:rgba(16,124,16,0.1);  color:#107C10; }
.type-pill.out { background:rgba(79,70,229,0.1);  color:#4f46e5; }
.cat-chip { display:inline-flex; align-items:center; gap:6px; font-weight:600; direction:rtl; }
.cat-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }

.wh-ftab {
    border:1px solid var(--w11-border); background:#fff; border-radius:20px;
    padding:4px 14px; font-size:.8rem; font-weight:600; cursor:pointer; color:#555;
    text-decoration:none; transition:all .15s;
}
.wh-ftab:hover { border-color:#6366F1; color:#4f46e5; }
.wh-ftab.active { background:#6366F1; border-color:#6366F1; color:#fff; }

.bill-thumb { width:38px; height:38px; border-radius:8px; object-fit:cover; border:1px solid var(--w11-border); transition:transform .12s; }
.bill-thumb:hover { transform:scale(1.06); }

/* Collect / Distribute segmented control in modal */
.mode-badge { font-size:.8rem; font-weight:700; padding:4px 12px; border-radius:20px; }
.rec-btn { width:44px; height:44px; border-radius:50%; border:none; color:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
#whTable .page-link { color:#4f46e5; }
.pagination .active .page-link { background:#6366F1 !important; border-color:#6366F1 !important; }
@media (max-width:576px){ .wh-cat-grid { grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; } }
</style>

<!-- Page header -->
<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h4 class="mb-1 d-flex align-items-center gap-2">
            <i class="bi bi-basket3 wh-accent"></i> <?= __('nav_warehouse') ?>
        </h4>
        <p class="text-muted small mb-0 font-pashto">د کالا ذخیره — کتان او بخمل په تھان او ګز</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn text-white fw-semibold" style="background:#10B981;border-color:#10B981;" onclick="openWhModal('collect')">
            <i class="bi bi-plus-square me-2"></i>Collect
        </button>
        <button class="btn text-white fw-semibold" style="background:#6366F1;border-color:#6366F1;" onclick="openWhModal('distribute')">
            <i class="bi bi-box-arrow-up me-2"></i>Distribute
        </button>
    </div>
</div>

<?= flashMessage() ?>

<!-- Top stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card wh-stat" style="background:rgba(99,102,241,0.08);">
            <i class="bi bi-layers ic" style="color:#6366F1;"></i>
            <div class="v" style="color:#4f46e5;"><?= whNum($totalTan) ?> <small style="font-size:.7rem;color:#888;">Tan</small></div>
            <div class="l" style="color:#4f46e5;">In Stock · تھان</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card wh-stat" style="background:rgba(236,72,153,0.08);">
            <i class="bi bi-rulers ic" style="color:#EC4899;"></i>
            <div class="v" style="color:#db2777;"><?= whNum($totalGaz) ?> <small style="font-size:.7rem;color:#888;">Gaz</small></div>
            <div class="l" style="color:#db2777;">In Stock · ګز</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card wh-stat" style="background:rgba(6,182,212,0.08);">
            <i class="bi bi-grid ic" style="color:#06B6D4;"></i>
            <div class="v" style="color:#0891b2;"><?= $activeCats ?> <small style="font-size:.7rem;color:#888;">/ <?= count($stock) ?></small></div>
            <div class="l" style="color:#0891b2;">Categories</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card wh-stat" style="background:rgba(16,124,16,0.07);">
            <i class="bi bi-arrow-left-right ic text-success"></i>
            <div class="v text-success"><?= (int)$counts['all_c'] ?></div>
            <div class="l text-success"><?= (int)$counts['in_c'] ?> in · <?= (int)$counts['out_c'] ?> out</div>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header fw-semibold d-flex align-items-center gap-2">
                <i class="bi bi-bar-chart-fill wh-accent"></i> Stock by Category
            </div>
            <div class="card-body"><div style="height:280px;"><canvas id="barChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header fw-semibold d-flex align-items-center gap-2">
                <i class="bi bi-pie-chart-fill" style="color:#EC4899;"></i> Gaz Share
            </div>
            <div class="card-body d-flex align-items-center justify-content-center"><div style="height:280px;width:100%;"><canvas id="doughnutChart"></canvas></div></div>
        </div>
    </div>
</div>

<!-- Category stock cards -->
<h5 class="mb-2 fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-boxes wh-accent"></i> Warehouse Stock
</h5>
<div class="wh-cat-grid mb-4">
    <?php foreach ($stock as $s): $c = whCategoryColor($s['category']); $empty = ((float)$s['tan'] <= 0 && (float)$s['gaz'] <= 0); ?>
    <div class="wh-cat" style="--cc:<?= $c ?>;">
        <div class="d-flex align-items-start justify-content-between mb-2">
            <span class="wh-cat-pin" style="background:<?= $c ?>;"><i class="bi bi-basket3"></i></span>
            <span class="text-muted" style="font-size:.68rem;"><?= (int)$s['txn_count'] ?> txn</span>
        </div>
        <div class="wh-cat-name font-pashto"><?= htmlspecialchars($s['category']) ?></div>
        <div class="wh-units">
            <div>
                <div class="num" style="color:<?= $empty ? '#9ca3af' : $c ?>;"><?= whNum($s['tan']) ?></div>
                <div class="u">Tan</div>
            </div>
            <div class="sep"></div>
            <div>
                <div class="num" style="color:<?= $empty ? '#9ca3af' : $c ?>;"><?= whNum($s['gaz']) ?></div>
                <div class="u">Gaz</div>
            </div>
        </div>
        <button class="wh-distribute-btn" onclick='openWhModal("distribute", <?= json_encode($s['category'], JSON_UNESCAPED_UNICODE) ?>)'>
            <i class="bi bi-box-arrow-up me-1"></i>Distribute
        </button>
    </div>
    <?php endforeach; ?>
</div>

<!-- Movement history -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span class="fw-semibold d-flex align-items-center gap-2">
            <i class="bi bi-clock-history wh-accent"></i> Movement History
        </span>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="d-flex gap-1">
                <a href="?" class="wh-ftab <?= $filterType===''?'active':'' ?>">All</a>
                <a href="?type=in" class="wh-ftab <?= $filterType==='in'?'active':'' ?>">In</a>
                <a href="?type=out" class="wh-ftab <?= $filterType==='out'?'active':'' ?>">Out</a>
            </div>
            <div class="input-group input-group-sm" style="max-width:220px;">
                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search" style="font-size:.78rem;"></i></span>
                <input type="text" id="whSearch" class="form-control border-start-0 ps-0" placeholder="Search…" oninput="filterWh()">
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="whTable" style="font-size:.82rem;">
            <thead class="table-light">
                <tr>
                    <th>Type</th>
                    <th>Category</th>
                    <th class="text-end">Tan</th>
                    <th class="text-end">Gaz</th>
                    <th>Name</th>
                    <th>Bill</th>
                    <th>Voice</th>
                    <th><?= __('field_date') ?? 'Date' ?></th>
                    <th>By</th>
                    <?php if (isAdmin()): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="<?= isAdmin()?10:9 ?>" class="text-center text-muted py-5">
                    <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>No movements yet.
                    <div class="mt-2"><button class="btn btn-sm text-white" style="background:#10B981;" onclick="openWhModal('collect')"><i class="bi bi-plus-lg me-1"></i>Collect the first cloths</button></div>
                </td></tr>
                <?php else: foreach ($logs as $l):
                    $isIn = $l['type'] === 'in';
                    $c = whCategoryColor($l['category']);
                    $hay = strtolower($l['category'].' '.($l['party_name']??'').' '.($l['bill_number']??'').' '.($l['notes']??''));
                ?>
                <tr data-search="<?= htmlspecialchars($hay) ?>">
                    <td>
                        <span class="type-pill <?= $isIn?'in':'out' ?>">
                            <i class="bi bi-arrow-<?= $isIn?'down':'up' ?>-circle"></i><?= $isIn?'In':'Out' ?>
                        </span>
                    </td>
                    <td>
                        <span class="cat-chip font-pashto"><span class="cat-dot" style="background:<?= $c ?>;"></span><?= htmlspecialchars($l['category']) ?></span>
                    </td>
                    <td class="text-end fw-bold text-nowrap <?= $isIn?'text-success':'' ?>" style="<?= $isIn?'':'color:#4f46e5;' ?>">
                        <?= (float)$l['tan']>0 ? ($isIn?'+':'−').whNum($l['tan']) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-end fw-bold text-nowrap <?= $isIn?'text-success':'' ?>" style="<?= $isIn?'':'color:#4f46e5;' ?>">
                        <?= (float)$l['gaz']>0 ? ($isIn?'+':'−').whNum($l['gaz']) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-muted" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($l['party_name'] ?? '') ?>">
                        <?= $l['party_name'] ? htmlspecialchars($l['party_name']) : '—' ?>
                    </td>
                    <td>
                        <?php if (!empty($l['bill_image'])): ?>
                        <a href="/uploads/warehouse-bills/<?= rawurlencode($l['bill_image']) ?>" target="_blank">
                            <img src="/uploads/warehouse-bills/<?= rawurlencode($l['bill_image']) ?>" class="bill-thumb" alt="bill">
                        </a>
                        <?php if (!empty($l['bill_number'])): ?><div class="text-muted" style="font-size:.66rem;">#<?= htmlspecialchars($l['bill_number']) ?></div><?php endif; ?>
                        <?php elseif (!empty($l['bill_number'])): ?>
                        <span class="text-muted" style="font-size:.75rem;">#<?= htmlspecialchars($l['bill_number']) ?></span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($l['voice_note'])): ?>
                        <audio controls preload="none" style="height:32px;width:150px;"><source src="/uploads/warehouse-voice/<?= rawurlencode($l['voice_note']) ?>"></audio>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-muted text-nowrap" style="font-size:.74rem;">
                        <div class="font-pashto"><?= whShamsiText($l['entry_date'] ?: $l['created_at']) ?></div>
                        <div style="font-size:.66rem;color:#bbb;"><?= date('d M Y', strtotime($l['entry_date'] ?: $l['created_at'])) ?></div>
                    </td>
                    <td class="text-muted text-nowrap" style="font-size:.74rem;"><?= htmlspecialchars($l['by_name'] ?? '—') ?></td>
                    <?php if (isAdmin()): ?>
                    <td class="text-nowrap">
                        <a href="delete.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete"
                           onclick="return confirm('Delete this entry?')"><i class="bi bi-trash"></i></a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; endif; ?>
                <tr id="whNoResults" style="display:none;"><td colspan="<?= isAdmin()?10:9 ?>" class="text-center text-muted py-4 small"><i class="bi bi-search me-1"></i>No rows match your search.</td></tr>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex align-items-center justify-content-between gap-2 flex-wrap py-2">
        <span class="text-muted small"><?= $totalRows ?> records — Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$totalRows) ?></span>
        <?php $qs = $filterType ? "type=$filterType&" : ''; ?>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= $qs ?>page=<?= $page-1 ?>">&#8249;</a></li>
            <?php $st=max(1,$page-2); $en=min($totalPages,$page+2);
            if ($st>1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            for ($i=$st;$i<=$en;$i++): ?>
            <li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="?<?= $qs ?>page=<?= $i ?>"><?= $i ?></a></li>
            <?php endfor;
            if ($en<$totalPages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; ?>
            <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?<?= $qs ?>page=<?= $page+1 ?>">&#8250;</a></li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════ Collect / Distribute Modal ══════════════ -->
<div class="modal fade" id="whModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:16px;">
      <form id="whForm">
        <input type="hidden" name="entry_date" id="f_entry_date" value="<?= date('Y-m-d') ?>">
        <div class="modal-header">
            <h5 class="modal-title d-flex align-items-center gap-2" id="whModalTitle"></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <!-- Category -->
            <label class="form-label fw-semibold mb-1">Category · <span class="font-pashto">کټګورۍ</span></label>
            <select id="f_category_select" class="form-select font-pashto" dir="rtl" onchange="onCatChange()">
                <?php foreach ($knownCategories as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
                <option value="__custom__" dir="ltr">＋ Custom / نوی…</option>
            </select>
            <input type="text" id="f_category" class="form-control mt-2 d-none font-pashto" dir="rtl" placeholder="Type category name…">
            <div id="availHint" class="d-none mt-2 small rounded px-2 py-1"></div>

            <!-- Tan / Gaz -->
            <div class="row g-2 mt-1">
                <div class="col-6">
                    <label class="form-label fw-semibold mb-1">Tan · <span class="font-pashto">تھان</span></label>
                    <input type="number" id="f_tan" class="form-control form-control-lg fw-bold" min="0" step="any" value="0" oninput="checkAvail()">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold mb-1">Gaz · <span class="font-pashto">ګز</span></label>
                    <input type="number" id="f_gaz" class="form-control form-control-lg fw-bold" min="0" step="any" value="0" oninput="checkAvail()">
                </div>
            </div>

            <!-- Name -->
            <div class="mt-3">
                <label class="form-label fw-semibold mb-1" id="nameLabel">Name</label>
                <input type="text" id="f_party_name" class="form-control" placeholder="Name…">
            </div>

            <!-- Date (Shamsi) -->
            <div class="mt-3">
                <label class="form-label fw-semibold mb-1">Date · <span class="font-pashto">نیټه</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.55rem;">Shamsi</span></label>
                <div class="d-flex align-items-center gap-1">
                    <input type="number" id="d_y" class="form-control form-control-sm text-center fw-semibold" value="<?= $todayShamsi['y'] ?>" min="1300" max="1600" style="width:70px;" oninput="syncDate()">
                    <span class="text-muted">/</span>
                    <select id="d_m" class="form-select form-select-sm font-pashto" style="width:118px;" onchange="syncDate()">
                        <?php foreach ($jMonths as $i=>$nm): ?><option value="<?= $i+1 ?>" <?= $todayShamsi['m']===$i+1?'selected':'' ?>><?= $nm ?></option><?php endforeach; ?>
                    </select>
                    <span class="text-muted">/</span>
                    <input type="number" id="d_d" class="form-control form-control-sm text-center fw-semibold" value="<?= $todayShamsi['d'] ?>" min="1" max="31" style="width:56px;" oninput="syncDate()">
                </div>
                <div id="gregHint" class="text-muted mt-1" style="font-size:.71rem;"></div>
            </div>

            <!-- Bill number -->
            <div class="mt-3">
                <label class="form-label fw-semibold mb-1">Bill Number · <span class="font-pashto">بل نمبر</span></label>
                <input type="text" id="f_bill_number" class="form-control" placeholder="e.g. 1042">
            </div>

            <!-- Bill image -->
            <div class="mt-3">
                <label class="form-label fw-semibold mb-1">Bill Image · <span class="font-pashto">د بل انځور</span></label>
                <input type="file" id="f_bill_image" class="form-control" accept="image/*" onchange="previewBill(this)">
                <img id="billPreview" class="d-none mt-2 rounded" style="max-height:160px;border:1px solid var(--w11-border);" alt="preview">
            </div>

            <!-- Voice note -->
            <div class="mt-3">
                <label class="form-label fw-semibold mb-1">Voice Note · <span class="font-pashto">غږیز یادښت</span></label>
                <div class="border rounded p-2" style="background:var(--w11-bg);">
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" id="recBtn" class="rec-btn" style="background:#EF4444;" onclick="toggleRec()"><i class="bi bi-mic-fill"></i></button>
                        <div class="flex-grow-1">
                            <div id="recStatus" class="small fw-semibold">Tap to record</div>
                            <div id="recTimer" class="text-muted font-monospace d-none" style="font-size:.72rem;">0:00</div>
                        </div>
                        <button type="button" id="recClear" class="btn btn-sm btn-link text-danger text-decoration-none d-none" onclick="clearRec()">Clear</button>
                    </div>
                    <audio id="recPlayback" controls class="d-none w-100 mt-2" style="height:34px;"></audio>
                    <div class="text-muted mt-2" style="font-size:.72rem;">Or upload an audio file:
                        <input type="file" id="f_voice_file" accept="audio/*" class="form-control form-control-sm mt-1" onchange="onVoiceFile(this)"></div>
                </div>
            </div>

            <!-- Notes -->
            <div class="mt-3">
                <label class="form-label fw-semibold mb-1">Notes</label>
                <textarea id="f_notes" class="form-control" rows="2" placeholder="Optional remarks…"></textarea>
            </div>

            <div id="formError" class="alert alert-danger d-none mt-3 mb-0 py-2 small"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= __('btn_cancel') ?? 'Cancel' ?></button>
            <button type="submit" class="btn text-white fw-semibold" id="whSubmit"><i class="bi bi-check-circle me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// ── Data from PHP ──
const AVAIL = <?= json_encode($availMap, JSON_UNESCAPED_UNICODE) ?>;
const chartLabels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
const chartTan    = <?= json_encode($chartTan) ?>;
const chartGaz    = <?= json_encode($chartGaz) ?>;
const chartColors = <?= json_encode($chartColors) ?>;

let whMode = 'collect';
function whModalEl(){ return document.getElementById('whModal'); }

function openWhModal(mode, presetCat) {
    whMode = mode;
    const isDist = mode === 'distribute';
    document.getElementById('whModalTitle').innerHTML = isDist
        ? '<i class="bi bi-box-arrow-up" style="color:#4f46e5;"></i> Distribute from Warehouse'
        : '<i class="bi bi-plus-square text-success"></i> Collect into Warehouse';
    const btn = document.getElementById('whSubmit');
    btn.style.background = isDist ? '#6366F1' : '#10B981';
    btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>' + (isDist ? 'Distribute' : 'Collect');
    document.getElementById('nameLabel').innerHTML = isDist
        ? 'Recipient Name · <span class="font-pashto">نوم</span> <span class="text-danger">*</span>'
        : 'From / Supplier · <span class="font-pashto">نوم</span> <span class="text-muted small">(optional)</span>';
    document.getElementById('f_party_name').placeholder = isDist ? 'Who is it going to?' : 'Where did it come from?';

    if (presetCat) {
        const sel = document.getElementById('f_category_select');
        if ([...sel.options].some(o => o.value === presetCat)) sel.value = presetCat;
    }
    onCatChange();
    checkAvail();
    document.getElementById('formError').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(whModalEl()).show();
}

function currentCategory() {
    const sel = document.getElementById('f_category_select');
    return sel.value === '__custom__' ? document.getElementById('f_category').value.trim() : sel.value;
}
function onCatChange() {
    const sel = document.getElementById('f_category_select');
    const custom = document.getElementById('f_category');
    if (sel.value === '__custom__') { custom.classList.remove('d-none'); custom.focus(); }
    else { custom.classList.add('d-none'); custom.value = sel.value; }
    checkAvail();
}
function checkAvail() {
    const hint = document.getElementById('availHint');
    if (whMode !== 'distribute') { hint.classList.add('d-none'); return; }
    const a = AVAIL[currentCategory()];
    if (!a) { hint.classList.add('d-none'); return; }
    const tan = +document.getElementById('f_tan').value||0, gaz = +document.getElementById('f_gaz').value||0;
    const over = tan > a.tan + 1e-9 || gaz > a.gaz + 1e-9;
    hint.classList.remove('d-none');
    hint.style.background = over ? 'rgba(196,43,28,.09)' : 'rgba(99,102,241,.08)';
    hint.style.color = over ? '#C42B1C' : '#4f46e5';
    hint.innerHTML = (over ? '<i class="bi bi-exclamation-triangle me-1"></i>Not enough in stock. ' : '<i class="bi bi-box-seam me-1"></i>')
        + 'Available: <b>' + fmt(a.tan) + '</b> Tan · <b>' + fmt(a.gaz) + '</b> Gaz';
}
function fmt(n){ return (Math.round(n*100)/100).toLocaleString('en-US'); }

// ── Shamsi → Gregorian ──
function shamsiToGregorian(jy, jm, jd) {
    var breaks = [-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];
    var gy = jy + 621, leapJ = -14, jp = breaks[0], jm2, jump, n, i;
    for (i = 1; i < 20; i++) { jm2 = breaks[i]; jump = jm2 - jp; if (jy < jm2) break; leapJ += Math.floor(jump/33)*8 + Math.floor((jump%33)/4); jp = jm2; }
    n = jy - jp;
    leapJ += Math.floor(n/33)*8 + Math.floor((n%33+3)/4);
    if ((jump % 33) === 4 && (jump - n) === 4) leapJ++;
    var leapG = Math.floor(gy/4) - Math.floor((Math.floor(gy/100)+1)*3/4) - 150;
    var march = 20 + leapJ - leapG;
    var dayOfYear = jm <= 6 ? (jm-1)*31 + jd : 186 + (jm-7)*30 + jd;
    var gDay = march + dayOfYear - 1, gMon = 3, gYr = gy;
    function dim(m,y){ if(m===2) return ((y%4===0&&y%100!==0)||y%400===0)?29:28; return [0,31,28,31,30,31,30,31,31,30,31,30,31][m]; }
    while (gDay > dim(gMon,gYr)) { gDay -= dim(gMon,gYr); if (++gMon>12){ gMon=1; gYr++; } }
    return {y:gYr, m:gMon, d:gDay};
}
function syncDate() {
    const jy = +document.getElementById('d_y').value||0, jm = +document.getElementById('d_m').value||0, jd = +document.getElementById('d_d').value||0;
    if (jy<1300||jy>1600||jm<1||jm>12||jd<1||jd>31) return;
    const g = shamsiToGregorian(jy,jm,jd);
    const iso = g.y+'-'+String(g.m).padStart(2,'0')+'-'+String(g.d).padStart(2,'0');
    document.getElementById('f_entry_date').value = iso;
    document.getElementById('gregHint').textContent = '≡ ' + iso + ' (Gregorian)';
}

// ── Bill preview ──
function previewBill(input) {
    const f = input.files[0];
    const img = document.getElementById('billPreview');
    if (f) { img.src = URL.createObjectURL(f); img.classList.remove('d-none'); }
    else img.classList.add('d-none');
}

// ── Voice recording ──
let mediaRecorder, chunks = [], recBlob = null, recTimerInt, recSeconds = 0;
function onVoiceFile(input) {
    const f = input.files[0]; if (!f) return;
    recBlob = f;
    const pb = document.getElementById('recPlayback');
    pb.src = URL.createObjectURL(f); pb.classList.remove('d-none');
    document.getElementById('recStatus').textContent = 'Audio file selected';
    document.getElementById('recClear').classList.remove('d-none');
}
async function toggleRec() {
    if (mediaRecorder && mediaRecorder.state === 'recording') { mediaRecorder.stop(); return; }
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        chunks = [];
        mediaRecorder.ondataavailable = e => chunks.push(e.data);
        mediaRecorder.onstop = () => {
            recBlob = new Blob(chunks, { type: mediaRecorder.mimeType || 'audio/webm' });
            const pb = document.getElementById('recPlayback');
            pb.src = URL.createObjectURL(recBlob); pb.classList.remove('d-none');
            stream.getTracks().forEach(t => t.stop());
            clearInterval(recTimerInt);
            document.getElementById('recStatus').textContent = 'Recording ready';
            document.getElementById('recClear').classList.remove('d-none');
            recUI(false);
            document.getElementById('f_voice_file').value = '';
        };
        mediaRecorder.start();
        recSeconds = 0;
        const t = document.getElementById('recTimer'); t.classList.remove('d-none'); t.textContent = '0:00';
        recTimerInt = setInterval(() => { recSeconds++; t.textContent = Math.floor(recSeconds/60)+':'+String(recSeconds%60).padStart(2,'0'); }, 1000);
        document.getElementById('recStatus').textContent = 'Recording… tap to stop';
        recUI(true);
    } catch (err) { alert('Microphone not available.'); }
}
function recUI(on) {
    const b = document.getElementById('recBtn');
    b.style.background = on ? '#6b7280' : '#EF4444';
    b.innerHTML = on ? '<i class="bi bi-stop-fill"></i>' : '<i class="bi bi-mic-fill"></i>';
}
function clearRec() {
    recBlob = null;
    const pb = document.getElementById('recPlayback'); pb.src=''; pb.classList.add('d-none');
    document.getElementById('recStatus').textContent = 'Tap to record';
    document.getElementById('recTimer').classList.add('d-none');
    document.getElementById('recClear').classList.add('d-none');
    document.getElementById('f_voice_file').value = '';
}

// ── Submit via fetch (multipart) ──
document.getElementById('whForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const err = document.getElementById('formError'); err.classList.add('d-none');
    const cat = currentCategory();
    const tan = +document.getElementById('f_tan').value||0, gaz = +document.getElementById('f_gaz').value||0;
    if (!cat) return showErr('Please choose or type a category.');
    if (tan <= 0 && gaz <= 0) return showErr('Enter a Tan and/or Gaz amount.');
    if (whMode === 'distribute' && !document.getElementById('f_party_name').value.trim())
        return showErr('Recipient name is required for distribution.');

    const fd = new FormData();
    fd.append('action', whMode);
    fd.append('category', cat);
    fd.append('tan', tan);
    fd.append('gaz', gaz);
    fd.append('party_name', document.getElementById('f_party_name').value.trim());
    fd.append('bill_number', document.getElementById('f_bill_number').value.trim());
    fd.append('notes', document.getElementById('f_notes').value.trim());
    fd.append('entry_date', document.getElementById('f_entry_date').value);
    const billFile = document.getElementById('f_bill_image').files[0];
    if (billFile) fd.append('bill_image', billFile);
    if (recBlob) fd.append('voice_note', recBlob, 'voice.webm');

    const btn = document.getElementById('whSubmit'); const html = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving…';
    try {
        const res = await fetch('process.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || 'Save failed.');
        location.reload();
    } catch (ex) {
        showErr(ex.message);
        btn.disabled = false; btn.innerHTML = html;
    }
});
function showErr(msg) {
    const err = document.getElementById('formError');
    err.textContent = msg; err.classList.remove('d-none');
}

// ── Search ──
function filterWh() {
    const q = document.getElementById('whSearch').value.trim().toLowerCase();
    let vis = 0;
    document.querySelectorAll('#whTable tbody tr[data-search]').forEach(r => {
        const show = !q || r.dataset.search.includes(q);
        r.style.display = show ? '' : 'none'; if (show) vis++;
    });
    document.getElementById('whNoResults').style.display = (q && vis===0) ? '' : 'none';
}

// ── Charts (after DOM + Chart.js parsed) ──
window.addEventListener('DOMContentLoaded', function () {
    syncDate();
    if (typeof Chart === 'undefined' || !chartLabels.length) {
        document.getElementById('barChart').closest('.card-body').innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-bar-chart fs-3 d-block mb-2 opacity-25"></i>No stock to chart yet</div>';
        document.getElementById('doughnutChart').closest('.card-body').innerHTML = '<div class="text-center text-muted py-5">—</div>';
        return;
    }
    Chart.defaults.color = '#605E5C';
    Chart.defaults.font.family = 'inherit';
    new Chart(document.getElementById('barChart'), {
        type: 'bar',
        data: { labels: chartLabels, datasets: [
            { label:'Tan', data: chartTan, backgroundColor: chartColors.map(c=>c+'dd'), borderRadius:8, borderSkipped:false },
            { label:'Gaz', data: chartGaz, backgroundColor: chartColors.map(c=>c+'55'), borderRadius:8, borderSkipped:false },
        ]},
        options: { responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ position:'bottom', labels:{ usePointStyle:true, boxWidth:10 } } },
            scales:{ x:{ grid:{ display:false } }, y:{ beginAtZero:true, grid:{ color:'rgba(0,0,0,.05)' } } } },
    });
    new Chart(document.getElementById('doughnutChart'), {
        type: 'doughnut',
        data: { labels: chartLabels, datasets:[{ data: chartGaz, backgroundColor: chartColors, borderColor:'#fff', borderWidth:2 }] },
        options: { responsive:true, maintainAspectRatio:false, cutout:'60%',
            plugins:{ legend:{ position:'bottom', labels:{ usePointStyle:true, boxWidth:10, font:{ size:10 } } } } },
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
