<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/period.php';
require_once '../accessories/helpers.php';
require_once 'helpers.php';

ensureAccessoriesTables($pdo);       // makes sure accessory_owners exists
ensureAccessoryCustomerTables($pdo);

$pageTitle = __('nav_acc_customers');

$q = trim($_GET['q'] ?? '');
$where = '';
$params = [];
if ($q !== '') {
    $where = "WHERE o.name LIKE ? OR o.phone LIKE ?";
    $params = ['%' . $q . '%', '%' . $q . '%'];
}

// ── Time-period filter (Today / Week / Month / All / Custom) ──
$pf    = periodResolve();                                             // default: All Time
$condT = periodCond("COALESCE(t.txn_date, DATE(t.created_at))", $pf); // accessory bills

// List the Accessory Owners; attach their cloth/demand-bill stats.
$ownersStmt = $pdo->prepare("
    SELECT o.id, o.name, o.phone,
           COUNT(t.id) AS txn_count,
           COALESCE(SUM(t.tan_quantity), 0)  AS total_tan,
           COALESCE(SUM(t.demand_weight), 0) AS total_demand,
           MAX(COALESCE(t.txn_date, DATE(t.created_at))) AS last_txn_date
    FROM accessory_owners o
    LEFT JOIN accessory_customer_txns t ON t.customer_id = o.id AND ($condT)
    $where
    GROUP BY o.id
    ORDER BY o.name ASC
");
$ownersStmt->execute($params);
$owners = $ownersStmt->fetchAll();

// Tan Qty shown = total tan − delivered back (same bill-no rule as customer.php).
$deliveredByOwner = accessoryCustomerDelivered($pdo, $condT);
foreach ($owners as &$o) {
    $o['delivered'] = $deliveredByOwner[(int)$o['id']] ?? 0;
    $o['tan_left']  = (float)$o['total_tan'] - $o['delivered'];
}
unset($o);

// Each owner's stock left in Accessories (all time), shown next to Tan Qty.
$ownerStock = accessoryOwnerStock($pdo);

$summary = [
    'stock'   => array_sum(array_map(fn($o) => $ownerStock[(int)$o['id']]['stock'] ?? 0, $owners)),
    'owners'  => count($owners),
    'bills'   => array_sum(array_map(fn($o) => (int)$o['txn_count'], $owners)),
    'tan'     => array_sum(array_map(fn($o) => (float)$o['tan_left'], $owners)),
    'tan_all' => array_sum(array_map(fn($o) => (float)$o['total_tan'], $owners)),
    'demand'  => array_sum(array_map(fn($o) => (float)$o['total_demand'], $owners)),
];

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('nav_acc_customers') ?></h4>
        <p class="text-muted small mb-0">Record cloth &amp; demand-weight bills for your accessory owners</p>
    </div>
    <a href="/accessories/index.php" class="btn btn-primary">
        <i class="bi bi-person-plus me-2"></i>Add Owner
    </a>
</div>

<!-- Time-period filter -->
<?= periodBar($pf, ['q' => $q]) ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0" style="background:rgba(244,114,182,0.12);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Owners</div>
                <div class="fs-4 fw-bold" style="color:#DB2777;"><?= number_format($summary['owners']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0" style="background:rgba(251,146,60,0.14);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Total Bills</div>
                <div class="fs-4 fw-bold" style="color:#C2410C;"><?= number_format($summary['bills']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0" style="background:rgba(34,211,238,0.12);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Remaining Tan Quantity</div>
                <div class="fs-4 fw-bold" style="color:#0E7490;"><?= number_format($summary['tan'], 2) ?></div>
                <?php if ($summary['tan_all'] != $summary['tan']): ?>
                <div class="text-muted small">of <?= number_format($summary['tan_all'], 2) ?> · <?= number_format($summary['tan_all'] - $summary['tan'], 2) ?> delivered</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0" style="background:rgba(124,58,237,0.12);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Total Demand Weight</div>
                <div class="fs-4 fw-bold" style="color:#7C3AED;"><?= number_format($summary['demand'], 2) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 d-flex align-items-center justify-content-between gap-2 flex-wrap">
        <span class="fw-semibold"><i class="bi bi-people me-2 text-primary"></i>Accessory Owners</span>
        <div class="d-flex align-items-center gap-2">
            <form method="GET" class="d-flex gap-1">
                <input type="text" name="q" class="form-control form-control-sm" style="min-width:200px;"
                       placeholder="Search name or phone…" value="<?= htmlspecialchars($q) ?>">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-search"></i></button>
                <?php if ($q !== ''): ?>
                <a href="index.php" class="btn btn-sm btn-light" title="Clear"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </form>
            <span class="text-muted small"><?= count($owners) ?> shown</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Owner</th>
                    <th class="d-none d-md-table-cell">Phone</th>
                    <th class="text-end">Bills</th>
                    <th class="text-end" title="Tan quantity still with the customer (total − delivered back)">Tan Qty Left</th>
                    <th class="text-end">Demand Wt</th>
                    <th class="text-end" title="Owner's stock left in Accessories (after tan returned)">Stock Left</th>
                    <th class="d-none d-lg-table-cell">Last Bill</th>
                    <th><?= __('field_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($owners)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-5">
                        <?php if ($q !== ''): ?>
                        No owners match "<?= htmlspecialchars($q) ?>".
                        <?php else: ?>
                        No accessory owners yet. <a href="/accessories/index.php">Register an owner</a> first.
                        <?php endif; ?>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($owners as $owner): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="text-white fw-bold d-flex align-items-center justify-content-center"
                                 style="width:34px;height:34px;border-radius:8px;background:#DB2777;flex-shrink:0;">
                                <?= strtoupper(substr($owner['name'], 0, 1)) ?>
                            </div>
                            <div class="fw-semibold"><?= htmlspecialchars($owner['name']) ?></div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell"><?= htmlspecialchars($owner['phone'] ?? '') ?></td>
                    <td class="text-end"><?= number_format((int)$owner['txn_count']) ?></td>
                    <td class="text-end fw-semibold <?= $owner['tan_left'] > 0.001 ? '' : ($owner['total_tan'] > 0 ? 'text-success' : '') ?>"
                        title="Total <?= number_format((float)$owner['total_tan'], 2) ?> − Delivered <?= number_format($owner['delivered'], 2) ?>">
                        <?= number_format($owner['tan_left'], 2) ?>
                        <?php if ($owner['delivered'] > 0): ?>
                        <div class="small fw-normal text-muted">of <?= number_format((float)$owner['total_tan'], 2) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold" style="color:#7C3AED;"><?= number_format((float)$owner['total_demand'], 2) ?></td>
                    <?php $st = $ownerStock[(int)$owner['id']] ?? null; ?>
                    <td class="text-end">
                        <?php if ($st): ?>
                        <a href="/accessories/owner.php?id=<?= $owner['id'] ?>" class="fw-bold text-decoration-none <?= $st['stock'] < 0 ? 'text-danger' : 'text-success' ?>"
                           title="Opening <?= number_format($st['opening'], 2) ?> + Added <?= number_format($st['added'], 2) ?> − Issued <?= number_format($st['issued'], 2) ?> − Tan returned <?= number_format($st['returned'], 2) ?>">
                            <?= number_format($st['stock'], 2) ?>
                        </a>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td class="d-none d-lg-table-cell text-muted small">
                        <?= $owner['last_txn_date'] ? htmlspecialchars(accCustShamsiDate($owner['last_txn_date'])) : '-' ?>
                    </td>
                    <td>
                        <a href="customer.php?id=<?= $owner['id'] ?>" class="btn btn-sm btn-primary" title="Add / view bills">
                            <i class="bi bi-receipt me-1"></i>Bills
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($owners)): ?>
            <tfoot>
                <tr class="table-light fw-bold">
                    <td>Total</td>
                    <td class="d-none d-md-table-cell"></td>
                    <td class="text-end"><?= number_format($summary['bills']) ?></td>
                    <td class="text-end"><?= number_format($summary['tan'], 2) ?></td>
                    <td class="text-end" style="color:#7C3AED;"><?= number_format($summary['demand'], 2) ?></td>
                    <td class="text-end text-success"><?= number_format($summary['stock'], 2) ?></td>
                    <td class="d-none d-lg-table-cell"></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
