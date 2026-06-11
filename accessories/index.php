<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once 'helpers.php';

ensureAccessoriesTables($pdo);

$pageTitle = __('accessories_title');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateFormToken('accessory_owner')) {
        $_SESSION['error'] = 'Duplicate submission detected. Your data was already saved.';
        header('Location: index.php'); exit;
    }

    $name  = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $openingOriginal = max(0, (float)($_POST['opening_original'] ?? 0));
    $openingCoffee   = max(0, (float)($_POST['opening_coffee'] ?? 0));
    $openingPes      = max(0, (float)($_POST['opening_pes'] ?? 0));
    $openingPlastic  = max(0, (float)($_POST['opening_plastic'] ?? 0));

    if ($name === '') {
        $_SESSION['error'] = 'Owner name is required.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO accessory_owners
                    (name, phone, opening_original, opening_coffee, opening_pes, opening_plastic, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $phone ?: null,
                $openingOriginal, $openingCoffee, $openingPes, $openingPlastic,
                $_SESSION['user_id'] ?? null,
            ]);
            $ownerId = (int)$pdo->lastInsertId();
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Save failed: ' . $e->getMessage();
            header('Location: index.php'); exit;
        }

        $_SESSION['success'] = 'Accessory owner registered.';
        header('Location: owner.php?id=' . $ownerId); exit;
    }
}

$owners = $pdo->query("
    SELECT o.*,
           COUNT(e.id) AS entry_count,
           COALESCE(SUM(e.total_amount), 0) AS total_amount,
           COALESCE(SUM(e.original_size), 0) AS issued_original,
           COALESCE(SUM(e.coffee_size), 0)   AS issued_coffee,
           COALESCE(SUM(e.pes_size), 0)       AS issued_pes,
           COALESCE(SUM(e.plastic_size), 0)   AS issued_plastic,
           MAX(e.entry_date) AS last_entry_date
    FROM accessory_owners o
    LEFT JOIN accessory_stock_entries e ON e.owner_id = o.id
    GROUP BY o.id
    ORDER BY o.created_at DESC, o.name ASC
")->fetchAll();

// Remaining balance per owner = opening - issued, summed across the four categories.
foreach ($owners as &$o) {
    $o['remaining'] =
        ((float)$o['opening_original'] - (float)$o['issued_original']) +
        ((float)$o['opening_coffee']   - (float)$o['issued_coffee']) +
        ((float)$o['opening_pes']       - (float)$o['issued_pes']) +
        ((float)$o['opening_plastic']   - (float)$o['issued_plastic']);
}
unset($o);

$summary = [
    'owners' => count($owners),
    'quantity' => array_sum(array_map(fn($o) => (float)$o['remaining'], $owners)),
    'amount' => array_sum(array_map(fn($o) => (float)$o['total_amount'], $owners)),
];

$formToken = generateFormToken('accessory_owner');

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('accessories_title') ?></h4>
        <p class="text-muted small mb-0"><?= __('accessories_sub') ?></p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ownerModal">
        <i class="bi bi-person-plus me-2"></i>Add Owner
    </button>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4">
        <div class="card border-0" style="background:rgba(167,139,250,0.12);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Owners</div>
                <div class="fs-4 fw-bold" style="color:#7C3AED;"><?= number_format($summary['owners']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card border-0" style="background:rgba(34,211,238,0.12);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Remaining Stock</div>
                <div class="fs-4 fw-bold" style="color:#0E7490;"><?= number_format($summary['quantity'], 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card border-0" style="background:rgba(16,124,16,0.10);">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Total Amount</div>
                <div class="fs-4 fw-bold text-success">؋ <?= number_format($summary['amount'], 2) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <span class="fw-semibold"><i class="bi bi-people me-2 text-primary"></i>Accessory Owners</span>
        <span class="text-muted small"><?= count($owners) ?> registered</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Owner</th>
                    <th class="d-none d-md-table-cell">Phone</th>
                    <th class="text-end">Entries</th>
                    <th class="text-end">Remaining</th>
                    <th class="text-end">Amount</th>
                    <th class="d-none d-lg-table-cell">Last Entry</th>
                    <th><?= __('field_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($owners)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-5">
                        No accessory owners yet. Register the first owner to start tracking stock.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($owners as $owner): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="text-white fw-bold d-flex align-items-center justify-content-center"
                                 style="width:34px;height:34px;border-radius:8px;background:#7C3AED;flex-shrink:0;">
                                <?= strtoupper(substr($owner['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($owner['name']) ?></div>
                                <?php if ($owner['notes']): ?>
                                <div class="text-muted small"><?= htmlspecialchars($owner['notes']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell"><?= htmlspecialchars($owner['phone'] ?? '') ?></td>
                    <td class="text-end"><?= number_format((int)$owner['entry_count']) ?></td>
                    <td class="text-end fw-semibold <?= $owner['remaining'] < 0 ? 'text-danger' : '' ?>"><?= number_format((float)$owner['remaining'], 2) ?></td>
                    <td class="text-end fw-bold text-success">؋ <?= number_format((float)$owner['total_amount'], 2) ?></td>
                    <td class="d-none d-lg-table-cell text-muted small">
                        <?= $owner['last_entry_date'] ? htmlspecialchars($owner['last_entry_date']) : '-' ?>
                    </td>
                    <td>
                        <a href="owner.php?id=<?= $owner['id'] ?>" class="btn btn-sm btn-light" title="Dashboard">
                            <i class="bi bi-speedometer2"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="ownerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
            <input type="hidden" name="_form_token" value="<?= htmlspecialchars($formToken) ?>">
            <div class="modal-header">
                <h5 class="modal-title">Register Accessory Owner</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Owner Name</label>
                    <input type="text" name="name" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Phone</label>
                    <input type="text" name="phone" class="form-control">
                </div>
                <label class="form-label fw-semibold">Opening Stock per Category</label>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small text-muted mb-1">اصلي چیکو</label>
                        <input type="number" step="0.01" min="0" name="opening_original" class="form-control" value="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label small text-muted mb-1">کافی</label>
                        <input type="number" step="0.01" min="0" name="opening_coffee" class="form-control" value="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label small text-muted mb-1">Pes</label>
                        <input type="number" step="0.01" min="0" name="opening_pes" class="form-control" value="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label small text-muted mb-1">پلاستیکی</label>
                        <input type="number" step="0.01" min="0" name="opening_plastic" class="form-control" value="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= __('btn_cancel') ?></button>
                <button class="btn btn-primary"><i class="bi bi-check2-circle me-2"></i><?= __('btn_save') ?></button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
