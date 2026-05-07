<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = __('set_title');

$settings = getSettings($pdo);
$rate     = (float)($settings['exchange_rate']      ?? 90);
$secCur   = $settings['secondary_currency'] ?? 'USD';

// Rate history
$rateLog = $pdo->query("
    SELECT el.rate, el.currency, el.changed_at, u.full_name
    FROM exchange_rate_log el
    JOIN users u ON u.id = el.changed_by
    ORDER BY el.changed_at DESC LIMIT 20
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_rate') {
        $newRate = (float)($_POST['exchange_rate'] ?? 0);
        $newSec  = strtoupper(trim($_POST['secondary_currency'] ?? 'USD'));

        if ($newRate <= 0) {
            $_SESSION['error'] = 'Exchange rate must be greater than 0.';
        } elseif (!array_key_exists($newSec, CURRENCIES) || $newSec === 'AFN') {
            $_SESSION['error'] = 'Invalid secondary currency.';
        } else {
            // Save rate
            $pdo->prepare("INSERT INTO settings (`key`,`value`,updated_by) VALUES ('exchange_rate',?,?) ON DUPLICATE KEY UPDATE `value`=?, updated_by=?, updated_at=NOW()")
                ->execute([$newRate, $_SESSION['user_id'], $newRate, $_SESSION['user_id']]);
            // Save secondary currency
            $pdo->prepare("INSERT INTO settings (`key`,`value`,updated_by) VALUES ('secondary_currency',?,?) ON DUPLICATE KEY UPDATE `value`=?, updated_by=?, updated_at=NOW()")
                ->execute([$newSec, $_SESSION['user_id'], $newSec, $_SESSION['user_id']]);
            // Log the change
            if ($newRate != $rate || $newSec !== $secCur) {
                $pdo->prepare("INSERT INTO exchange_rate_log (rate, currency, changed_by) VALUES (?,?,?)")
                    ->execute([$newRate, $newSec, $_SESSION['user_id']]);
            }
            $_SESSION['success'] = 'Exchange rate updated: 1 ' . $newSec . ' = ' . number_format($newRate, 2) . ' ؋';
            header('Location: settings.php');
            exit;
        }
    }
    header('Location: settings.php');
    exit;
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <h4 class="mb-1"><?= __('set_title') ?></h4>
    <p class="text-muted small mb-0"><?= __('set_sub') ?></p>
</div>

<div class="row g-3">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="bi bi-currency-exchange me-2"></i><?= __('set_ex_rate') ?>
            </div>
            <div class="card-body">
                <div class="alert alert-info py-2 small mb-3">
                    <i class="bi bi-info-circle me-1"></i><?= __('set_rate_note') ?>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_rate">

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('set_sec_currency') ?></label>
                        <select name="secondary_currency" class="form-select" id="secCurSelect" onchange="updateLabel()">
                            <?php foreach (CURRENCIES as $code => $info): ?>
                                <?php if ($code === 'AFN') continue; ?>
                                <option value="<?= $code ?>" <?= $secCur === $code ? 'selected' : '' ?>>
                                    <?= $info['symbol'] ?> <?= $code ?> — <?= $info['name'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <?= __('set_rate_label') ?> <span id="secCurLabel"><?= htmlspecialchars($secCur) ?></span> =
                            <span class="text-primary">? ؋ AFN</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold" id="secSymbol"><?= htmlspecialchars(currencySymbol($secCur)) ?> 1</span>
                            <input type="number" name="exchange_rate" class="form-control" step="0.01" min="0.01"
                                   value="<?= $rate ?>" required id="rateInput" oninput="updatePreview()">
                            <span class="input-group-text">؋ AFN</span>
                        </div>
                        <div class="mt-2 text-muted small" id="ratePreview"></div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-2"></i><?= __('set_update_rate') ?>
                    </button>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header fw-semibold"><i class="bi bi-calculator me-2"></i><?= __('set_converter') ?></div>
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col">
                        <label class="form-label small">AFN ؋</label>
                        <input type="number" id="convAfn" class="form-control" placeholder="0" oninput="convFromAfn()">
                    </div>
                    <div class="col-auto pb-2 fw-bold text-muted">⇄</div>
                    <div class="col">
                        <label class="form-label small" id="convSecLabel"><?= htmlspecialchars($secCur) ?> <?= htmlspecialchars(currencySymbol($secCur)) ?></label>
                        <input type="number" id="convSec" class="form-control" placeholder="0" oninput="convFromSec()">
                    </div>
                </div>
                <p class="text-muted small mt-2 mb-0">
                    1 <?= htmlspecialchars($secCur) ?> = <strong><?= number_format($rate, 2) ?> ؋</strong>
                </p>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-clock-history me-2"></i><?= __('set_history') ?></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th><?= __('nav_exchange') ?></th>
                            <th><?= __('field_currency') ?></th>
                            <th><?= __('set_changed_by') ?></th>
                            <th><?= __('field_date') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rateLog)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4"><?= __('set_no_history') ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($rateLog as $i => $log): ?>
                        <tr>
                            <td class="fw-semibold">
                                1 <?= htmlspecialchars($log['currency']) ?> = <?= number_format($log['rate'], 2) ?> ؋
                            </td>
                            <td><?= htmlspecialchars($log['currency']) ?></td>
                            <td><?= htmlspecialchars($log['full_name']) ?></td>
                            <td class="text-muted"><?= date('d M Y H:i', strtotime($log['changed_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header fw-semibold"><?= __('set_how_work') ?></div>
            <div class="card-body small text-muted">
                <ul class="mb-0 ps-3">
                    <li class="mb-1"><?= __('set_info_1') ?></li>
                    <li class="mb-1"><?= __('set_info_2') ?></li>
                    <li class="mb-1"><?= __('set_info_3') ?></li>
                    <li><?= __('set_info_4') ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
$symbolsJson = json_encode(array_map(fn($c) => $c['symbol'], CURRENCIES));
$extraScript = <<<JS
<script>
const SYMBOLS = {$symbolsJson};

function updateLabel() {
    const sel = document.getElementById('secCurSelect');
    const cur = sel.value;
    document.getElementById('secCurLabel').textContent = cur;
    document.getElementById('secSymbol').textContent = (SYMBOLS[cur] || cur) + ' 1';
    document.getElementById('convSecLabel').textContent = cur + ' ' + (SYMBOLS[cur] || '');
    updatePreview();
}

function updatePreview() {
    const rate = parseFloat(document.getElementById('rateInput').value) || 0;
    const cur  = document.getElementById('secCurSelect').value;
    const sym  = SYMBOLS[cur] || cur;
    const prev = document.getElementById('ratePreview');
    if (rate > 0) {
        prev.innerHTML = `Example: ${sym} 100 = <strong>؋ ${(100 * rate).toLocaleString('en-AF')}</strong>`;
    } else {
        prev.textContent = '';
    }
}

function convFromAfn() {
    const rate = parseFloat(document.getElementById('rateInput').value) || 1;
    const afn  = parseFloat(document.getElementById('convAfn').value) || 0;
    document.getElementById('convSec').value = rate > 0 ? (afn / rate).toFixed(2) : '';
}
function convFromSec() {
    const rate = parseFloat(document.getElementById('rateInput').value) || 1;
    const sec  = parseFloat(document.getElementById('convSec').value) || 0;
    document.getElementById('convAfn').value = (sec * rate).toFixed(0);
}

document.addEventListener('DOMContentLoaded', updatePreview);
</script>
JS;
?>

<?php require_once '../includes/footer.php'; ?>
