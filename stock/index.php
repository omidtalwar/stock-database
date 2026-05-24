<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = __('stock_title');

function toShamsi(int $gy, int $gm, int $gd): array {
    $g_d_no = 365*$gy + (int)(($gy+3)/4) - (int)(($gy+99)/100) + (int)(($gy+399)/400);
    for ($i=0;$i<$gm-1;$i++) $g_d_no += [0,31,28,31,30,31,30,31,31,30,31,30,31][$i+1];
    if ($gm>2 && (($gy%4==0&&$gy%100!=0)||$gy%400==0)) $g_d_no++;
    $j_d_no = $g_d_no - 79;
    $j_np   = (int)($j_d_no / 12053); $j_d_no %= 12053;
    $jy     = 979 + 33*$j_np + 4*(int)($j_d_no/1461); $j_d_no %= 1461;
    if ($j_d_no >= 366) { $jy += (int)(($j_d_no-1)/365); $j_d_no = ($j_d_no-1) % 365; }
    $jm = 0; $jd = 0;
    for ($i=0; $i<11; $i++) {
        $j_mi = $i<6 ? 31 : 30;
        if ($j_d_no >= $j_mi) { $j_d_no -= $j_mi; $jm++; } else break;
    }
    $jd = $j_d_no + 1; $jm++;
    return [$jy, $jm, $jd];
}

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
    "ALTER TABLE stock_logs ADD COLUMN bill_no        VARCHAR(100)  NULL",
    "ALTER TABLE stock_logs ADD COLUMN currency       VARCHAR(10)   NULL DEFAULT 'AFN'",
] as $_sql) { try { $pdo->exec($_sql); } catch (\PDOException $e) {} }

$rates = getAllRates($pdo);

// ── PIN guard (same session as dashboard) ──────────────────────────────────
if (isset($_GET['lock'])) {
    unset($_SESSION['pin_verified'], $_SESSION['pin_verified_at']);
    header('Location: index.php');
    exit;
}
$_iSettings = getSettings($pdo);
$pinEnabled  = !empty(trim($_iSettings['dashboard_pin'] ?? ''));
if ($pinEnabled) {
    $lastSeen = $_SESSION['pin_verified_at'] ?? 0;
    if (!empty($_SESSION['pin_verified']) && (time() - $lastSeen) > 120) {
        unset($_SESSION['pin_verified'], $_SESSION['pin_verified_at']);
    }
    if (!empty($_SESSION['pin_verified'])) $_SESSION['pin_verified_at'] = time();
}
$pinVerified = !$pinEnabled || !empty($_SESSION['pin_verified']);
$bodyClass   = ($pinEnabled && !$pinVerified) ? 'pin-locked' : '';

// Dashboard aggregate stats
$stats = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN type='in' THEN quantity     ELSE 0 END), 0)         AS total_in_qty,
        COALESCE(SUM(CASE WHEN type='in' THEN bundle_count ELSE 0 END), 0)        AS total_in_bundles,
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
.stat-card .stat-val { font-size: 1.55rem; font-weight: 700; line-height: 1.15; letter-spacing: -.5px; transition: filter .4s ease; }
.stat-card .stat-lbl { font-size: 0.68rem; text-transform: uppercase; letter-spacing: .7px; font-weight: 700; opacity: .65; margin-top: 5px; }

/* ── PIN blur ── */
body.pin-locked .stat-val,
body.pin-locked .sup-fin { filter: blur(7px); user-select: none; pointer-events: none; transition: filter .4s ease; }

/* ── Compact PIN card ── */
#pinCard {
    display: none;
    position: fixed;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    z-index: 1060;
    background: #fff;
    border-radius: 22px;
    padding: 28px 22px 22px;
    width: 254px;
    box-shadow: 0 20px 70px rgba(0,0,0,0.18), 0 4px 18px rgba(0,0,0,0.09);
    text-align: center;
    border: 1px solid rgba(0,0,0,0.06);
}
#pinCard.show { display: block; animation: pinCardIn .3s cubic-bezier(0.34,1.56,0.64,1); }
@keyframes pinCardIn {
    from { opacity:0; transform:translate(-50%, calc(-50% + 18px)) scale(0.95); }
    to   { opacity:1; transform:translate(-50%, -50%) scale(1); }
}
@keyframes pinCardOut {
    from { opacity:1; transform:translate(-50%, -50%) scale(1); }
    to   { opacity:0; transform:translate(-50%, calc(-50% - 14px)) scale(0.95); }
}
@keyframes shake {
    0%,100% { transform:translate(-50%,-50%); }
    20% { transform:translate(calc(-50% - 6px),-50%); }
    40% { transform:translate(calc(-50% + 6px),-50%); }
    60% { transform:translate(calc(-50% - 4px),-50%); }
    80% { transform:translate(calc(-50% + 4px),-50%); }
}
@media (max-width:768px) { #pinCard { width:88vw; } }
.pin-dot { width:13px; height:13px; border-radius:50%; border:2px solid #0067C0; background:transparent; display:inline-block; transition:background .15s; }
.pin-dot.filled { background:#0067C0; }
.pin-key { padding:13px; border-radius:9px; border:1px solid rgba(0,0,0,0.09); background:#f8f9fa; font-size:1rem; font-weight:600; cursor:pointer; transition:background .12s; color:#1C1C1C; width:100%; }
.pin-key:hover { background:#e9ecef; }
.pin-key:active { background:#dee2e6; }
</style>

<?php if ($pinEnabled): ?>
<!-- Compact PIN card -->
<div id="pinCard"<?= !$pinVerified ? ' class="show"' : '' ?>>
    <div style="width:44px;height:44px;border-radius:11px;background:linear-gradient(135deg,#0067C0,#003E92);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.15rem;margin:0 auto 12px;">
        <i class="bi bi-lock-fill"></i>
    </div>
    <div style="font-weight:700;font-size:0.95rem;margin-bottom:3px;">Stock PIN</div>
    <div style="font-size:0.75rem;color:#888;margin-bottom:18px;">Enter your PIN to view financial data</div>
    <div id="pinDots" style="display:flex;justify-content:center;gap:12px;margin-bottom:18px;">
        <span class="pin-dot"></span>
        <span class="pin-dot"></span>
        <span class="pin-dot"></span>
        <span class="pin-dot"></span>
    </div>
    <div id="pinPad" style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:4px;"></div>
    <div id="pinError" style="display:none;margin-top:10px;color:#C42B1C;font-size:0.78rem;font-weight:600;">Wrong PIN. Try again.</div>
</div>
<?php endif; ?>

<!-- Page header -->
<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('stock_title') ?></h4>
        <p class="text-muted small mb-0"><?= __('stock_sub') ?></p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?php if ($pinEnabled && $pinVerified): ?>
        <button id="pinLockBtn" title="Lock financial data"
            style="display:flex;align-items:center;gap:5px;padding:6px 13px;border-radius:7px;border:1px solid rgba(196,43,28,0.35);background:rgba(196,43,28,0.06);color:#C42B1C;font-size:0.82rem;font-weight:600;cursor:pointer;">
            <i class="bi bi-lock-fill"></i> Lock
        </button>
        <?php endif; ?>
        <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-square me-2"></i><?= __('stock_in_btn') ?></a>
    </div>
</div>

<!-- Dashboard stats -->
<div class="row g-3 mb-2">
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(0,103,192,0.07);">
            <div class="stat-val text-primary"><?= number_format($stats['total_in_qty']) ?> <small style="font-size:.75rem;font-weight:500;"><?= __('unit_pcs') ?></small></div>
            <?php if ($stats['total_in_bundles'] > 0): ?>
            <div style="font-size:.82rem;font-weight:600;color:#0067C0;opacity:.8;margin-top:2px;">
                <?= number_format($stats['total_in_bundles']) ?> <span style="font-weight:500;"><?= __('unit_bundles') ?></span>
            </div>
            <?php endif; ?>
            <div class="stat-lbl" style="color:#0067C0;"><?= __('stock_received') ?></div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(196,43,28,0.07);">
            <div class="stat-val text-danger"><?= number_format($stats['total_suppliers']) ?></div>
            <div class="stat-lbl" style="color:#C42B1C;"><?= __('stock_total_suppliers') ?></div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(16,124,16,0.07);">
            <div class="stat-val text-success">$ <?= number_format($stats['total_paid'], 2) ?></div>
            <div class="text-muted sup-fin" style="font-size:0.72rem;margin-top:2px;">≈ <?= formatAFN(toAFN($stats['total_paid'], 'USD', $rates['USD'])) ?></div>
            <div class="stat-lbl" style="color:#107C10;"><?= __('stock_total_paid_stat') ?></div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(157,93,0,0.08);">
            <div class="stat-val" style="color:#9D5D00;">$ <?= number_format($stats['total_unpaid'], 2) ?></div>
            <div class="text-muted sup-fin" style="font-size:0.72rem;margin-top:2px;">≈ <?= formatAFN(toAFN($stats['total_unpaid'], 'USD', $rates['USD'])) ?></div>
            <div class="stat-lbl" style="color:#9D5D00;"><?= __('stock_unpaid_balance') ?></div>
        </div>
    </div>
</div>

<!-- Total purchased banner -->
<div class="card mb-4" style="background:rgba(0,0,0,0.035);border:none;">
    <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between flex-wrap gap-1">
        <span class="small fw-semibold text-muted"><?= __('stock_wholesalers_total') ?></span>
        <div class="text-end">
            <span class="fw-bold fs-6 sup-fin">$ <?= number_format($stats['total_purchased'], 2) ?></span>
            <div class="text-muted sup-fin" style="font-size:0.72rem;">≈ <?= formatAFN(toAFN($stats['total_purchased'], 'USD', $rates['USD'])) ?></div>
        </div>
    </div>
</div>

<!-- Supplier / Wholesaler Accounts -->
<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
    <h5 class="mb-0 fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-people" style="color:#0067C0;"></i>
        <?= __('stock_supplier_accts') ?>
    </h5>
    <div class="d-flex align-items-center gap-2">
        <div class="input-group input-group-sm" style="max-width:220px;">
            <span class="input-group-text bg-white border-end-0 text-muted" style="border-radius:8px 0 0 8px;">
                <i class="bi bi-search" style="font-size:0.78rem;"></i>
            </span>
            <input type="text" id="supplierSearch" class="form-control border-start-0 ps-0"
                   placeholder="Search supplier…" oninput="filterSuppliers()"
                   style="border-radius:0 8px 8px 0;">
        </div>
        <span id="supplierCount" class="text-muted small" style="white-space:nowrap;"></span>
    </div>
</div>

<?php if (empty($supplierStats)): ?>
<div class="card border-0" style="background:rgba(0,0,0,0.03);">
    <div class="card-body text-center text-muted py-5">
        <i class="bi bi-people fs-2 d-block mb-2 opacity-25"></i>
        <?= __('stock_no_suppliers') ?>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="supplierTable" style="font-size:0.85rem;">
            <thead class="table-light">
                <tr>
                    <th><?= __('stock_col_supplier') ?></th>
                    <th class="text-end"><?= __('stock_col_txns') ?></th>
                    <th class="text-end"><?= __('stock_col_qty') ?></th>
                    <th class="text-end"><?= __('stock_col_purchased') ?></th>
                    <th class="text-end"><?= __('stock_col_paid') ?></th>
                    <th class="text-end"><?= __('stock_col_balance') ?></th>
                    <th><?= __('stock_col_activity') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($supplierStats as $s):
                    $purchased = (float)$s['total_purchased'];
                    $paid      = (float)$s['total_paid'];
                    $unpaid    = (float)$s['total_unpaid'];
                    $usdRate   = $rates['USD'];
                ?>
                <tr style="cursor:pointer;" data-supplier="<?= strtolower(htmlspecialchars($s['supplier'])) ?>" onclick="location.href='supplier.php?name=<?= urlencode($s['supplier']) ?>'">
                    <td class="fw-semibold"><?= htmlspecialchars($s['supplier']) ?></td>
                    <td class="text-end text-muted"><?= number_format($s['txn_count']) ?></td>
                    <td class="text-end text-muted"><?= number_format($s['total_qty']) ?> <?= __('unit_pcs') ?></td>

                    <td class="text-end fw-semibold">
                        <?php if (!$purchased): ?>—<?php else: ?>
                            $ <?= number_format($purchased, 2) ?>
                            <div class="text-muted fw-normal" style="font-size:0.72rem;">≈ <?= formatAFN(toAFN($purchased, 'USD', $usdRate)) ?></div>
                        <?php endif; ?>
                    </td>

                    <td class="text-end text-success fw-semibold">
                        <?php if (!$paid): ?>—<?php else: ?>
                            $ <?= number_format($paid, 2) ?>
                            <div class="text-muted fw-normal" style="font-size:0.72rem;">≈ <?= formatAFN(toAFN($paid, 'USD', $usdRate)) ?></div>
                        <?php endif; ?>
                    </td>

                    <td class="text-end fw-bold <?= $unpaid > 0 ? 'text-danger' : 'text-muted' ?>">
                        <?php if ($unpaid <= 0): ?>—<?php else: ?>
                            $ <?= number_format($unpaid, 2) ?>
                            <div class="text-muted fw-normal" style="font-size:0.72rem;">≈ <?= formatAFN(toAFN($unpaid, 'USD', $usdRate)) ?></div>
                        <?php endif; ?>
                    </td>

                    <td class="text-muted" style="font-size:0.75rem;">
                        <?= date('d M Y', strtotime($s['last_txn'])) ?>
                        <?php [$sy,$sm,$sd] = toShamsi((int)date('Y',strtotime($s['last_txn'])), (int)date('n',strtotime($s['last_txn'])), (int)date('j',strtotime($s['last_txn']))); ?>
                        <div style="font-size:0.68rem;color:#aaa;"><?= $sy ?>/<?= str_pad($sm,2,'0',STR_PAD_LEFT) ?>/<?= str_pad($sd,2,'0',STR_PAD_LEFT) ?></div>
                    </td>
                    <td class="text-nowrap">
                        <a href="supplier.php?name=<?= urlencode($s['supplier']) ?>"
                           class="btn btn-sm btn-light me-1"
                           onclick="event.stopPropagation()"
                           title="<?= __('stock_view_txns') ?>">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="add.php?supplier=<?= urlencode($s['supplier']) ?>"
                           class="btn btn-sm btn-outline-primary"
                           onclick="event.stopPropagation()"
                           title="<?= __('stock_add_supplier') ?>">
                            <i class="bi bi-plus-circle"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="supplierNoResults" style="display:none;">
                    <td colspan="8" class="text-center text-muted py-4 small">
                        <i class="bi bi-search me-1"></i> No suppliers match your search.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($pinEnabled): ?>
<script>
(function () {
    const card    = document.getElementById('pinCard');
    const dots    = document.querySelectorAll('#pinDots .pin-dot');
    const pad     = document.getElementById('pinPad');
    const errEl   = document.getElementById('pinError');
    const lockBtn = document.getElementById('pinLockBtn');
    let pin = '';

    // Guarantee card is visible when body is locked (safety net for any CSS edge case)
    if (card && document.body.classList.contains('pin-locked')) {
        card.classList.add('show');
    }

    if (pad) {
        [1,2,3,4,5,6,7,8,9,'',0,'⌫'].forEach(k => {
            if (k === '') { pad.appendChild(document.createElement('span')); return; }
            const btn = document.createElement('button');
            btn.className = 'pin-key';
            btn.textContent = k;
            btn.addEventListener('click', () => handleKey(String(k)));
            pad.appendChild(btn);
        });
    }

    function updateDots() { dots.forEach((d,i) => d.classList.toggle('filled', i < pin.length)); }

    async function handleKey(k) {
        if (k === '⌫') { pin = pin.slice(0,-1); updateDots(); return; }
        if (pin.length >= 4) return;
        pin += k; updateDots();
        if (pin.length === 4) await verifyPin();
    }

    async function verifyPin() {
        try {
            const res  = await fetch('/ajax/pin-verify.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'pin='+encodeURIComponent(pin)});
            const data = await res.json();
            if (data.ok) {
                if (card) { card.style.animation = 'pinCardOut .3s ease forwards'; }
                document.body.classList.remove('pin-locked');
                setTimeout(() => location.reload(), 350);
            } else {
                if (errEl) errEl.style.display = 'block';
                pin = ''; updateDots();
                if (card) { card.style.animation = 'shake .35s ease'; }
                setTimeout(() => { if (errEl) errEl.style.display = 'none'; if (card) card.style.animation = ''; }, 2000);
            }
        } catch (e) {
            if (errEl) { errEl.textContent = 'Connection error.'; errEl.style.display = 'block'; }
            pin = ''; updateDots();
            setTimeout(() => { if (errEl) { errEl.style.display = 'none'; errEl.textContent = 'Wrong PIN. Try again.'; } }, 2500);
        }
    }

    lockBtn?.addEventListener('click', () => { window.location.href = 'index.php?lock=1'; });

    document.addEventListener('keydown', e => {
        if (!card?.classList.contains('show')) return;
        if (/^[0-9]$/.test(e.key)) handleKey(e.key);
        else if (e.key === 'Backspace') handleKey('⌫');
    });
})();
</script>
<?php endif; ?>

<script>
function filterSuppliers() {
    const q = document.getElementById('supplierSearch').value.trim().toLowerCase();
    const rows = document.querySelectorAll('#supplierTable tbody tr[data-supplier]');
    let visible = 0;
    rows.forEach(row => {
        const show = !q || row.dataset.supplier.includes(q);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const countEl = document.getElementById('supplierCount');
    countEl.textContent = q ? visible + ' of ' + rows.length : '';
    const noResults = document.getElementById('supplierNoResults');
    if (noResults) noResults.style.display = (q && visible === 0) ? '' : 'none';
}
</script>

<?php require_once '../includes/footer.php'; ?>
