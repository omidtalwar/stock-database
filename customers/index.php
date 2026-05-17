<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$rates   = getAllRates($pdo);
$rateUSD = $rates['USD'];
$ratePKR = $rates['PKR'];

$pageTitle = __('cust_title');

$search = trim($_GET['search'] ?? '');
$params = [];
$where  = '';
if ($search) {
    $where  = "WHERE c.name LIKE ? OR c.phone LIKE ? OR c.shop_name LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c $where");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();

$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$customers = $pdo->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM sales s WHERE s.customer_id = c.id) AS sale_count
    FROM customers c $where
    ORDER BY c.total_debt DESC, c.name ASC
    LIMIT $perPage OFFSET $offset
");
$customers->execute($params);
$customers = $customers->fetchAll();

// Per-currency debt breakdown for customers on this page
$debtMap = [];
if (!empty($customers)) {
    $ids = implode(',', array_map(fn($c) => (int)$c['id'], $customers));
    $debtRows = $pdo->query("
        SELECT s.customer_id, COALESCE(s.currency,'AFN') AS currency,
               SUM(s.total_amount - s.paid_amount) AS bal_afn,
               COUNT(*) AS cnt
        FROM sales s
        WHERE s.customer_id IN ($ids) AND s.total_amount > s.paid_amount + 0.01
        GROUP BY s.customer_id, COALESCE(s.currency,'AFN')
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($debtRows as $dr) {
        $cid  = (int)$dr['customer_id'];
        $cur  = $dr['currency'];
        $afn  = (float)$dr['bal_afn'];
        $rate = $rates[$cur] ?? 1.0;
        $orig = $cur === 'AFN' ? $afn : fromAFN($afn, $rate);
        $debtMap[$cid][$cur] = ['afn' => $afn, 'orig' => $orig, 'cnt' => (int)$dr['cnt']];
    }
}

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('cust_title') ?></h4>
        <p class="text-muted small mb-0"><?= __('cust_sub') ?></p>
    </div>
    <a href="/customers/add.php" class="btn btn-primary">
        <i class="bi bi-person-plus me-2"></i><?= __('cust_add') ?>
    </a>
</div>

<style>
.rs-drop {
    display:none;position:absolute;top:calc(100% + 6px);left:0;right:0;
    background:#fff;border:1px solid rgba(0,0,0,0.09);border-radius:12px;
    box-shadow:0 8px 32px rgba(0,0,0,0.13);z-index:1055;overflow:hidden;
    animation:rsFadeIn .15s ease;
}
@keyframes rsFadeIn { from{opacity:0;transform:translateY(-4px)} to{opacity:1;transform:translateY(0)} }
.rs-item {
    display:flex;align-items:center;gap:10px;padding:9px 14px;cursor:pointer;
    transition:background .12s;border-bottom:1px solid rgba(0,0,0,0.04);
}
.rs-item:last-of-type{border-bottom:none;}
.rs-item:hover{background:rgba(0,103,192,0.05);}
.rs-term {flex:1;font-size:.84rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#1C1C1C;}
.rs-del {
    width:22px;height:22px;border-radius:50%;border:none;background:transparent;
    color:#aaa;font-size:.8rem;display:flex;align-items:center;justify-content:center;
    cursor:pointer;flex-shrink:0;transition:background .12s,color .12s;
}
.rs-del:hover{background:rgba(196,43,28,0.1);color:#C42B1C;}
.rs-footer {
    display:flex;justify-content:space-between;align-items:center;
    padding:7px 14px;background:rgba(0,0,0,0.02);border-top:1px solid rgba(0,0,0,0.06);
}
</style>

<div class="card">
    <div class="card-header py-3">
        <form method="GET" id="custSearchForm" class="d-flex align-items-center gap-2 justify-content-between flex-wrap">
            <div class="position-relative" style="max-width:380px;flex:1 1 260px;">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted">
                        <i class="bi bi-search" style="font-size:.8rem;"></i>
                    </span>
                    <input type="text" name="search" id="custSearchInput"
                           class="form-control border-start-0 ps-0"
                           placeholder="<?= __('cust_search') ?>"
                           value="<?= htmlspecialchars($search) ?>"
                           autocomplete="off">
                    <?php if ($search): ?>
                    <a href="index.php" class="btn btn-outline-secondary border-start-0" title="Clear">
                        <i class="bi bi-x"></i>
                    </a>
                    <?php else: ?>
                    <button class="btn btn-outline-secondary border-start-0" type="submit">
                        <i class="bi bi-arrow-right" style="font-size:.8rem;"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <!-- Recent searches dropdown -->
                <div class="rs-drop" id="rsDrop">
                    <div id="rsList"></div>
                </div>
            </div>
            <span class="text-muted small"><?= $totalRows ?> <?= __('nav_customers') ?></span>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="d-none d-md-table-cell">#</th>
                    <th><?= __('field_name') ?></th>
                    <th class="d-none d-md-table-cell"><?= __('field_shop') ?></th>
                    <th class="d-none d-sm-table-cell"><?= __('field_phone') ?></th>
                    <th class="d-none d-sm-table-cell"><?= __('cust_invoices') ?></th>
                    <th><?= __('cust_debt') ?></th>
                    <th><?= __('field_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                <tr><td colspan="7" class="text-center text-muted py-5"><?= __('no_data') ?></td></tr>
                <?php else: ?>
                <?php foreach ($customers as $i => $c): ?>
                <tr>
                    <td class="text-muted small d-none d-md-table-cell"><?= $i + 1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                                 style="width:34px;height:34px;background:var(--w11-blue);font-size:0.8rem;flex-shrink:0;border-radius:8px!important;">
                                <?= strtoupper(substr($c['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($c['name']) ?></div>
                                <div class="text-muted d-md-none" style="font-size:0.72rem;">
                                    <?= htmlspecialchars($c['shop_name']) ?>
                                    <?php if ($c['phone']): ?> · <?= htmlspecialchars($c['phone']) ?><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell"><?= htmlspecialchars($c['shop_name']) ?></td>
                    <td class="d-none d-sm-table-cell"><?= htmlspecialchars($c['phone']) ?></td>
                    <td class="d-none d-sm-table-cell"><span class="badge bg-light text-dark"><?= $c['sale_count'] ?></span></td>
                    <td>
                        <?php
                        $cDebt = $debtMap[$c['id']] ?? [];
                        $curMeta = [
                            'AFN' => ['flag'=>'🇦🇫','col'=>'#C42B1C'],
                            'USD' => ['flag'=>'🇺🇸','col'=>'#C42B1C'],
                            'PKR' => ['flag'=>'🇵🇰','col'=>'#C42B1C'],
                        ];
                        if (!empty($cDebt)):
                            foreach (['AFN','USD','PKR'] as $dcur):
                                if (!isset($cDebt[$dcur])) continue;
                                $dd = $cDebt[$dcur];
                        ?>
                        <div class="d-flex align-items-center gap-1" style="line-height:1.3;">
                            <span style="font-size:0.72rem;"><?= $curMeta[$dcur]['flag'] ?></span>
                            <span class="fw-bold text-danger" style="font-size:0.85rem;"><?= formatMoney($dd['orig'], $dcur) ?></span>
                            <?php if ($dcur !== 'AFN'): ?>
                            <span class="text-muted" style="font-size:0.65rem;">≈ <?= formatAFN($dd['afn']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; else: ?>
                            <span class="text-success fw-semibold"><?= __('cust_cleared') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-light me-1" title="<?= __('btn_view') ?>">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-light me-1" title="<?= __('btn_edit') ?>">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <?php if (isAdmin()): ?>
                        <a href="delete.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('<?= htmlspecialchars(addslashes(__('confirm_delete'))) ?>')" title="<?= __('btn_delete') ?>">
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
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex align-items-center justify-content-between gap-2 flex-wrap py-2">
        <span class="text-muted small">
            <?= __('field_total') ?>: <?= $totalRows ?> &mdash;
            <?= __('period_showing') ?> <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?>
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

<script>
(function () {
    const KEY  = 'fzl_cust_searches';
    const MAX  = 7;
    const form = document.getElementById('custSearchForm');
    const inp  = document.getElementById('custSearchInput');
    const drop = document.getElementById('rsDrop');
    const list = document.getElementById('rsList');

    function esc(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function load()  { try { return JSON.parse(localStorage.getItem(KEY)||'[]'); } catch { return []; } }
    function save(a) { localStorage.setItem(KEY, JSON.stringify(a)); }

    function add(term) {
        if (!term) return;
        let a = load().filter(t => t.toLowerCase() !== term.toLowerCase());
        a.unshift(term);
        save(a.slice(0, MAX));
    }
    function remove(term) { save(load().filter(t => t !== term)); render(); }
    function clearAll()   { save([]); hide(); }

    function render() {
        const items = load();
        if (!items.length) { hide(); return; }

        list.innerHTML = items.map(t => `
            <div class="rs-item" data-term="${esc(t)}">
                <i class="bi bi-clock-history text-muted" style="font-size:.78rem;flex-shrink:0;"></i>
                <span class="rs-term">${esc(t)}</span>
                <a href="/customers/index.php?search=${encodeURIComponent(t)}"
                   class="btn btn-sm btn-light py-0 px-2 rs-action" style="font-size:.72rem;border-radius:6px;white-space:nowrap;"
                   title="Search">
                    <i class="bi bi-arrow-up-left"></i>
                </a>
                <button type="button" class="rs-del rs-remove" data-term="${esc(t)}" title="Remove">
                    <i class="bi bi-x"></i>
                </button>
            </div>`
        ).join('') + `
        <div class="rs-footer">
            <span style="font-size:.72rem;color:#888;">Recent searches</span>
            <button type="button" id="rsClearAll"
                    style="font-size:.72rem;border:none;background:none;color:#888;cursor:pointer;padding:0;">
                Clear all
            </button>
        </div>`;

        drop.style.display = '';

        list.querySelectorAll('.rs-item').forEach(el => {
            el.addEventListener('mousedown', e => {
                if (e.target.closest('.rs-remove') || e.target.closest('.rs-action')) return;
                e.preventDefault();
                inp.value = el.dataset.term;
                hide();
                add(el.dataset.term);
                form.submit();
            });
        });
        list.querySelectorAll('.rs-remove').forEach(btn => {
            btn.addEventListener('mousedown', e => { e.preventDefault(); remove(btn.dataset.term); });
        });
        const ca = document.getElementById('rsClearAll');
        if (ca) ca.addEventListener('mousedown', e => { e.preventDefault(); clearAll(); });
    }

    function show() { render(); }
    function hide() { drop.style.display = 'none'; }

    inp.addEventListener('focus', () => { if (!inp.value.trim()) show(); });
    inp.addEventListener('input', () => { inp.value.trim() ? hide() : show(); });
    inp.addEventListener('blur',  () => { setTimeout(hide, 180); });

    form.addEventListener('submit', () => { const v = inp.value.trim(); if (v) add(v); });

    <?php if ($search): ?>
    add(<?= json_encode($search) ?>);
    <?php endif; ?>
})();
</script>

<?php require_once '../includes/footer.php'; ?>
