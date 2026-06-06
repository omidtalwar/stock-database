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

// General payments (sale_id IS NULL) reduce customers.total_debt but not invoice paid_amount.
// So c.total_debt is the authoritative balance — use it directly for display.

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
/* ── Recent searches banner ── */
#rs-banner {
    display: none;
    padding: 10px 18px 12px;
    background: linear-gradient(135deg, rgba(0,103,192,0.04) 0%, rgba(67,56,202,0.05) 100%);
    border-bottom: 1px solid rgba(0,103,192,0.1);
}
#rs-banner .rs-label {
    font-size: .7rem; font-weight: 700; letter-spacing: .5px;
    text-transform: uppercase; color: #605E5C;
    display: flex; align-items: center; gap: 5px;
    margin-bottom: 8px;
}
#rs-chips { display: flex; flex-wrap: wrap; gap: 7px; }
.rs-chip {
    display: inline-flex; align-items: center; gap: 0;
    border-radius: 22px; overflow: hidden;
    border: 1px solid rgba(0,103,192,0.18);
    background: #fff;
    box-shadow: 0 1px 4px rgba(0,103,192,0.08);
    transition: box-shadow .15s, border-color .15s;
}
.rs-chip:hover { box-shadow: 0 2px 10px rgba(0,103,192,0.15); border-color: rgba(0,103,192,0.35); }
.rs-chip-search {
    display: flex; align-items: center; gap: 6px;
    padding: 5px 10px 5px 11px;
    color: #0067C0; font-size: .8rem; font-weight: 600;
    text-decoration: none; cursor: pointer;
    border: none; background: none;
    transition: background .12s;
    white-space: nowrap;
}
.rs-chip-search:hover { background: rgba(0,103,192,0.07); color: #0067C0; }
.rs-chip-search i { font-size: .7rem; color: #7B8FA1; }
.rs-chip-del {
    display: flex; align-items: center; justify-content: center;
    width: 26px; height: 100%; min-height: 30px;
    border: none; background: none; cursor: pointer;
    color: #aaa; font-size: .75rem;
    border-left: 1px solid rgba(0,103,192,0.1);
    transition: background .12s, color .12s;
}
.rs-chip-del:hover { background: rgba(196,43,28,0.07); color: #C42B1C; }
#rs-clear-all {
    font-size: .72rem; color: #888; border: none; background: none;
    cursor: pointer; padding: 0; margin-left: auto; align-self: flex-end;
    text-decoration: underline; text-underline-offset: 2px;
    transition: color .12s;
}
#rs-clear-all:hover { color: #C42B1C; }
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
            </div>
            <span class="text-muted small"><?= $totalRows ?> <?= __('nav_customers') ?></span>
        </form>
    </div>
    <!-- Recent searches banner — rendered by JS, always visible when searches exist -->
    <div id="rs-banner">
        <div class="rs-label"><i class="bi bi-clock-history"></i> Recent Searches</div>
        <div style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;">
            <div id="rs-chips"></div>
            <button id="rs-clear-all" type="button">Clear all</button>
        </div>
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
                        <?php $cDebtAfn = (float)$c['total_debt']; ?>
                        <?php if ($cDebtAfn > 0.01): ?>
                        <div class="fw-bold text-danger" style="font-size:0.85rem;"><?= formatAFN($cDebtAfn) ?></div>
                        <div class="text-muted" style="font-size:0.65rem;">≈ <?= formatMoney(fromAFN($cDebtAfn, $rateUSD), 'USD') ?></div>
                        <?php else: ?>
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
    const KEY    = 'fzl_cust_searches';
    const MAX    = 8;
    const form   = document.getElementById('custSearchForm');
    const inp    = document.getElementById('custSearchInput');
    const banner = document.getElementById('rs-banner');
    const chips  = document.getElementById('rs-chips');
    const clearBtn = document.getElementById('rs-clear-all');

    function esc(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function load()  { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch { return []; } }
    function save(a) { localStorage.setItem(KEY, JSON.stringify(a)); }

    function add(term) {
        if (!term.trim()) return;
        let a = load().filter(t => t.toLowerCase() !== term.toLowerCase());
        a.unshift(term.trim());
        save(a.slice(0, MAX));
    }
    function remove(term) { save(load().filter(t => t !== term)); render(); }

    function render() {
        const items = load();
        if (!items.length) { banner.style.display = 'none'; return; }

        chips.innerHTML = items.map(t => `
            <span class="rs-chip">
                <a class="rs-chip-search" href="/customers/index.php?search=${encodeURIComponent(t)}">
                    <i class="bi bi-clock-history"></i>${esc(t)}
                </a>
                <button type="button" class="rs-chip-del" data-term="${esc(t)}" title="Remove">
                    <i class="bi bi-x"></i>
                </button>
            </span>`
        ).join('');

        banner.style.display = '';

        chips.querySelectorAll('.rs-chip-del').forEach(btn => {
            btn.addEventListener('click', () => remove(btn.dataset.term));
        });
    }

    clearBtn.addEventListener('click', () => { save([]); render(); });
    form.addEventListener('submit', () => { const v = inp.value.trim(); if (v) add(v); });

    <?php if ($search): ?>
    add(<?= json_encode($search) ?>);
    <?php endif; ?>

    render();
})();
</script>

<?php require_once '../includes/footer.php'; ?>
