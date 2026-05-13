<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = __('set_title');

// Auto-migrate email column
try { $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL AFTER full_name"); } catch (\Throwable $e) {}
// Fix users created without a status (NULL) — activate them so they can log in
try { $pdo->exec("UPDATE users SET status = 'active' WHERE status IS NULL"); } catch (\Throwable $e) {}
// Add PKR to payments currency ENUM
try { $pdo->exec("ALTER TABLE payments MODIFY COLUMN currency ENUM('AFN','USD','PKR') NOT NULL DEFAULT 'AFN'"); } catch (\Throwable $e) {}
// Ensure per-currency rate keys exist
try { $pdo->exec("INSERT IGNORE INTO settings (`key`,`value`) VALUES ('rate_usd','90.00'),('rate_pkr','0.25')"); } catch (\Throwable $e) {}
// Allow decimal quantities in sale items
try { $pdo->exec("ALTER TABLE sale_items MODIFY quantity DECIMAL(10,3) NOT NULL DEFAULT 1"); } catch (\Throwable $e) {}
// Allow decimal quantities in stock_logs
try { $pdo->exec("ALTER TABLE stock_logs MODIFY quantity DECIMAL(10,3) NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

$settings = getSettings($pdo);
$secCur   = $settings['secondary_currency'] ?? 'USD';
$rateUsd  = (float)($settings['rate_usd'] ?? $settings['exchange_rate'] ?? 90);
$ratePkr  = (float)($settings['rate_pkr'] ?? 0.25);

// Current admin profile
$meStmt = $pdo->prepare("SELECT id, username, full_name, email FROM users WHERE id = ?");
$meStmt->execute([$_SESSION['user_id']]);
$me = $meStmt->fetch();

// Rate history
$rateLog = [];
try {
    $rateLog = $pdo->query("
        SELECT el.rate, el.currency, el.changed_at, u.full_name
        FROM exchange_rate_log el
        JOIN users u ON u.id = el.changed_by
        ORDER BY el.changed_at DESC LIMIT 30
    ")->fetchAll();
} catch (\Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $newName  = trim($_POST['full_name'] ?? '');
        $newEmail = trim($_POST['email'] ?? '');
        $newUser  = trim($_POST['username'] ?? '');
        $curPw    = $_POST['current_password'] ?? '';
        $newPw    = $_POST['new_password'] ?? '';
        $confPw   = $_POST['confirm_password'] ?? '';

        $errors = [];
        if (!$newName)  $errors[] = 'Full name is required.';
        if (!$newUser)  $errors[] = 'Username is required.';

        // Check username uniqueness (exclude self)
        $dup = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $dup->execute([$newUser, $_SESSION['user_id']]);
        if ($dup->fetch()) $errors[] = 'Username already taken.';

        // Password change — only if any password field filled
        $changePw = ($curPw !== '' || $newPw !== '' || $confPw !== '');
        if ($changePw) {
            $pwRow = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $pwRow->execute([$_SESSION['user_id']]);
            $hash = $pwRow->fetchColumn();
            if (!password_verify($curPw, $hash))    $errors[] = 'Current password is incorrect.';
            if (strlen($newPw) < 6)                 $errors[] = 'New password must be at least 6 characters.';
            if ($newPw !== $confPw)                 $errors[] = 'New passwords do not match.';
        }

        if ($errors) {
            $_SESSION['error'] = implode(' ', $errors);
        } else {
            if ($changePw) {
                $pdo->prepare("UPDATE users SET full_name=?, email=?, username=?, password=? WHERE id=?")
                    ->execute([$newName, $newEmail ?: null, $newUser, password_hash($newPw, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            } else {
                $pdo->prepare("UPDATE users SET full_name=?, email=?, username=? WHERE id=?")
                    ->execute([$newName, $newEmail ?: null, $newUser, $_SESSION['user_id']]);
            }
            // Refresh session
            $_SESSION['full_name'] = $newName;
            $_SESSION['username']  = $newUser;
            $_SESSION['success']   = 'Profile updated successfully.';
        }
        header('Location: settings.php');
        exit;
    }

    if ($action === 'update_pin') {
        $newPin   = trim($_POST['dashboard_pin'] ?? '');
        $acctPw   = $_POST['account_password'] ?? '';
        $pwRow    = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $pwRow->execute([$_SESSION['user_id']]);
        $hash     = $pwRow->fetchColumn();
        if (!$acctPw || !password_verify($acctPw, $hash)) {
            $_SESSION['error'] = 'Incorrect account password.';
        } elseif (!preg_match('/^\d{4}$/', $newPin)) {
            $_SESSION['error'] = 'PIN must be exactly 4 digits.';
        } else {
            $pdo->prepare("INSERT INTO settings (`key`,`value`,updated_by) VALUES ('dashboard_pin',?,?) ON DUPLICATE KEY UPDATE `value`=?, updated_by=?, updated_at=NOW()")
                ->execute([$newPin, $_SESSION['user_id'], $newPin, $_SESSION['user_id']]);
            $_SESSION['success'] = 'Dashboard PIN updated.';
        }
        header('Location: settings.php'); exit;
    }

    if ($action === 'clear_pin') {
        $acctPw = $_POST['account_password'] ?? '';
        $pwRow  = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $pwRow->execute([$_SESSION['user_id']]);
        $hash   = $pwRow->fetchColumn();
        if (!$acctPw || !password_verify($acctPw, $hash)) {
            $_SESSION['error'] = 'Incorrect account password.';
        } else {
            $pdo->prepare("DELETE FROM settings WHERE `key` = 'dashboard_pin'")->execute();
            $_SESSION['success'] = 'Dashboard PIN removed.';
        }
        header('Location: settings.php'); exit;
    }

    if ($action === 'update_rate') {
        $newRateUsd = (float)($_POST['rate_usd'] ?? 0);
        $newRatePkr = (float)($_POST['rate_pkr'] ?? 0);
        $newSec     = strtoupper(trim($_POST['secondary_currency'] ?? 'USD'));

        $errors = [];
        if ($newRateUsd <= 0) $errors[] = 'USD rate must be greater than 0.';
        if ($newRatePkr <= 0) $errors[] = 'PKR rate must be greater than 0.';
        if (!array_key_exists($newSec, CURRENCIES) || $newSec === 'AFN') $errors[] = 'Invalid secondary currency.';

        if ($errors) {
            $_SESSION['error'] = implode(' ', $errors);
        } else {
            $secRate = $newSec === 'PKR' ? $newRatePkr : $newRateUsd;
            $toSave  = [
                'rate_usd'           => $newRateUsd,
                'rate_pkr'           => $newRatePkr,
                'secondary_currency' => $newSec,
                'exchange_rate'      => $secRate,
            ];
            foreach ($toSave as $k => $v) {
                $pdo->prepare("INSERT INTO settings (`key`,`value`,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `value`=?, updated_by=?, updated_at=NOW()")
                    ->execute([$k, $v, $_SESSION['user_id'], $v, $_SESSION['user_id']]);
            }
            foreach (['USD' => $newRateUsd, 'PKR' => $newRatePkr] as $cur => $r) {
                $pdo->prepare("INSERT INTO exchange_rate_log (rate, currency, changed_by) VALUES (?,?,?)")
                    ->execute([$r, $cur, $_SESSION['user_id']]);
            }
            $_SESSION['success'] = '$ 1 USD = ؋'.number_format($newRateUsd, 2).' &nbsp;·&nbsp; ₨ 1 PKR = ؋'.number_format($newRatePkr, 4);
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

        <!-- ── Exchange Rates Form ── -->
        <div class="card mb-3">
            <div class="card-header fw-semibold">
                <i class="bi bi-currency-exchange me-2"></i><?= __('set_ex_rate') ?>
            </div>
            <div class="card-body">

                <form method="POST">
                    <input type="hidden" name="action" value="update_rate">

                    <!-- Header badge currency -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold small text-muted text-uppercase" style="letter-spacing:.5px;">Header Badge Currency</label>
                        <select name="secondary_currency" class="form-select" id="secCurSelect">
                            <?php foreach (CURRENCIES as $code => $info): ?>
                                <?php if ($code === 'AFN') continue; ?>
                                <option value="<?= $code ?>" <?= $secCur === $code ? 'selected' : '' ?>>
                                    <?= $info['symbol'] ?> <?= $code ?> — <?= $info['name'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Which currency appears in the top bar next to the Afghan rate.</div>
                    </div>

                    <hr class="my-3">

                    <!-- USD rate -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <img src="https://flagcdn.com/16x12/us.png" class="me-1" alt="" style="vertical-align:middle;">
                            US Dollar (USD) Rate
                        </label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold">$ 1 USD =</span>
                            <input type="number" name="rate_usd" id="rateUsd"
                                   class="form-control" step="0.01" min="0.01"
                                   value="<?= $rateUsd ?>" required oninput="previewUsd()">
                            <span class="input-group-text">؋ AFN</span>
                        </div>
                        <div class="mt-1 text-muted small" id="usdPreview"></div>
                    </div>

                    <!-- PKR rate -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <img src="https://flagcdn.com/16x12/pk.png" class="me-1" alt="" style="vertical-align:middle;">
                            Pakistani Rupee (PKR) Rate
                        </label>
                        <div class="input-group mb-1">
                            <span class="input-group-text fw-bold">₨ 1000 PKR =</span>
                            <input type="number" id="ratePkr1000"
                                   class="form-control" step="0.01" min="0.01"
                                   value="<?= number_format($ratePkr * 1000, 2, '.', '') ?>"
                                   required oninput="syncPkr()">
                            <span class="input-group-text">؋ AFN</span>
                        </div>
                        <!-- hidden field stores the per-1-PKR rate -->
                        <input type="hidden" name="rate_pkr" id="ratePkr" value="<?= $ratePkr ?>">
                        <div class="text-muted small" id="pkrPreview"></div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 fw-semibold">
                        <i class="bi bi-check-lg me-2"></i><?= __('set_update_rate') ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- ── Currency Converter ── -->
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-calculator me-2"></i><?= __('set_converter') ?></div>
            <div class="card-body">
                <div class="mb-2">
                    <select id="convCurSel" class="form-select form-select-sm" onchange="convReset()">
                        <?php foreach (CURRENCIES as $code => $info): ?>
                        <?php if ($code === 'AFN') continue; ?>
                        <option value="<?= $code ?>" <?= $code === $secCur ? 'selected' : '' ?>>
                            <?= $info['symbol'] ?> <?= $code ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 align-items-end">
                    <div class="col">
                        <label class="form-label small">؋ AFN</label>
                        <input type="number" id="convAfn" class="form-control" placeholder="0" oninput="convFromAfn()">
                    </div>
                    <div class="col-auto pb-2 fw-bold text-muted">⇄</div>
                    <div class="col">
                        <label class="form-label small" id="convSecLabel"></label>
                        <input type="number" id="convSec" class="form-control" placeholder="0" oninput="convFromSec()">
                    </div>
                </div>
                <p class="text-muted small mt-2 mb-0" id="convNote"></p>
            </div>
        </div>

    </div>

    <div class="col-md-7">
        <!-- ── Rate History ── -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="bi bi-clock-history me-2"></i><?= __('set_history') ?></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>Rate</th>
                            <th><?= __('field_currency') ?></th>
                            <th><?= __('set_changed_by') ?></th>
                            <th><?= __('field_date') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rateLog)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4"><?= __('set_no_history') ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($rateLog as $log): ?>
                        <tr>
                            <td class="fw-semibold">
                                <?php
                                $sym = currencySymbol($log['currency']);
                                $dec = $log['currency'] === 'PKR' ? 4 : 2;
                                echo "1 {$sym} {$log['currency']} = " . number_format($log['rate'], $dec) . ' ؋';
                                ?>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($log['currency']) ?></span></td>
                            <td><?= htmlspecialchars($log['full_name']) ?></td>
                            <td class="text-muted"><?= date('d M Y H:i', strtotime($log['changed_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Current rates summary ── -->
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-info-circle me-2"></i>Current Rates</div>
            <div class="card-body">
                <div class="row g-3 text-center">
                    <div class="col-6">
                        <div class="p-3 rounded" style="background:rgba(0,103,192,0.07);">
                            <div style="font-size:1.5rem;font-weight:700;" class="text-primary">$ 1</div>
                            <div class="text-muted small">USD</div>
                            <div class="fw-bold mt-1">؋ <?= number_format($rateUsd, 2) ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded" style="background:rgba(16,124,16,0.07);">
                            <div style="font-size:1.5rem;font-weight:700;" class="text-success">₨ 1000</div>
                            <div class="text-muted small">PKR</div>
                            <div class="fw-bold mt-1">؋ <?= number_format($ratePkr * 1000, 2) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Profile & Security ── -->
<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="bi bi-person-gear me-2"></i>My Profile &amp; Security
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="row g-3">

                        <!-- Left: identity fields -->
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Full Name</label>
                                <input type="text" name="full_name" class="form-control"
                                       value="<?= htmlspecialchars($me['full_name'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Username</label>
                                <input type="text" name="username" class="form-control"
                                       value="<?= htmlspecialchars($me['username'] ?? '') ?>" required autocomplete="username">
                            </div>
                            <div class="mb-0">
                                <label class="form-label fw-semibold small">Email <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="email" name="email" class="form-control"
                                       value="<?= htmlspecialchars($me['email'] ?? '') ?>"
                                       placeholder="admin@example.com">
                            </div>
                        </div>

                        <!-- Right: password change -->
                        <div class="col-md-6">
                            <p class="text-muted small mb-2 fw-semibold">Change Password <span class="fw-normal">(leave blank to keep current)</span></p>
                            <div class="mb-2">
                                <label class="form-label small">Current Password</label>
                                <input type="password" name="current_password" class="form-control"
                                       placeholder="Enter current password" autocomplete="current-password">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">New Password</label>
                                <input type="password" name="new_password" id="newPwInput" class="form-control"
                                       placeholder="Min 6 characters" autocomplete="new-password"
                                       oninput="checkPwMatch()">
                            </div>
                            <div class="mb-0">
                                <label class="form-label small">Confirm New Password</label>
                                <input type="password" name="confirm_password" id="confPwInput" class="form-control"
                                       placeholder="Repeat new password" autocomplete="new-password"
                                       oninput="checkPwMatch()">
                                <div id="pwMatchMsg" class="form-text"></div>
                            </div>
                        </div>

                    </div>
                    <hr class="my-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>Save Profile
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ── Dashboard PIN ── -->
<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="bi bi-lock me-2"></i>Dashboard PIN Protection
            </div>
            <div class="card-body">
                <?php $currentPin = $settings['dashboard_pin'] ?? ''; ?>
                <p class="text-muted small mb-3">
                    When a PIN is set, financial data on the dashboard is blurred until the correct 4-digit PIN is entered.
                    Your account password is required to set, change, or remove the PIN.
                </p>

                <!-- Status -->
                <div class="mb-3">
                    <?php if ($currentPin): ?>
                    <div class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded" style="background:rgba(16,124,16,0.07);border:1px solid rgba(16,124,16,0.18);">
                        <i class="bi bi-shield-lock-fill text-success"></i>
                        <span class="small fw-semibold text-success">PIN is active</span>
                        <span class="text-muted small">(4-digit PIN set)</span>
                    </div>
                    <?php else: ?>
                    <div class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded" style="background:rgba(0,0,0,0.04);border:1px solid rgba(0,0,0,0.1);">
                        <i class="bi bi-shield text-muted"></i>
                        <span class="small fw-semibold text-muted">No PIN set — dashboard data is visible to all users</span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Set / Change PIN -->
                <form method="POST" class="mb-3">
                    <input type="hidden" name="action" value="update_pin">
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-auto">
                            <label class="form-label small mb-1 fw-semibold"><?= $currentPin ? 'New PIN' : 'Set PIN' ?></label>
                            <input type="text" name="dashboard_pin" class="form-control form-control-sm"
                                   inputmode="numeric" maxlength="4" pattern="\d{4}" required
                                   placeholder="4 digits" style="width:110px;letter-spacing:6px;font-weight:700;">
                        </div>
                        <div class="col-sm-auto">
                            <label class="form-label small mb-1 fw-semibold">Account Password</label>
                            <input type="password" name="account_password" class="form-control form-control-sm"
                                   placeholder="Your login password" required style="width:210px;"
                                   autocomplete="current-password">
                        </div>
                        <div class="col-sm-auto">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-lock me-1"></i><?= $currentPin ? 'Update PIN' : 'Set PIN' ?>
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Remove PIN -->
                <?php if ($currentPin): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="clear_pin">
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-auto">
                            <label class="form-label small mb-1 fw-semibold">Account Password to Remove PIN</label>
                            <input type="password" name="account_password" class="form-control form-control-sm"
                                   placeholder="Confirm your password" required style="width:230px;"
                                   autocomplete="current-password">
                        </div>
                        <div class="col-sm-auto">
                            <button type="submit" class="btn btn-outline-danger btn-sm"
                                    onclick="return confirm('Remove dashboard PIN protection?')">
                                <i class="bi bi-x-circle me-1"></i>Remove PIN
                            </button>
                        </div>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Backup shortcut ── -->
<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card border-0" style="background:rgba(13,110,253,0.05);">
            <div class="card-body d-flex align-items-center justify-content-between gap-3 py-3">
                <div>
                    <div class="fw-semibold"><i class="bi bi-shield-lock me-2 text-primary"></i>Backup &amp; Restore</div>
                    <div class="text-muted small">Download today's transactions or a full database backup, restore from a file, or reset the system to a clean state.</div>
                </div>
                <a href="backup.php" class="btn btn-primary btn-sm text-nowrap">
                    <i class="bi bi-database me-1"></i>Open Backup
                </a>
            </div>
        </div>
    </div>
</div>

<?php
$allRatesJson = json_encode(['AFN' => 1.0, 'USD' => $rateUsd, 'PKR' => $ratePkr]);
$symbolsJson  = json_encode(array_map(fn($c) => $c['symbol'], CURRENCIES));
$extraScript  = <<<JS
<script>
const ALL_RATES = {$allRatesJson};
const SYMBOLS   = {$symbolsJson};

function previewUsd() {
    const r = parseFloat(document.getElementById('rateUsd').value) || 0;
    document.getElementById('usdPreview').innerHTML = r > 0
        ? '$ 100 USD = <strong>؋ ' + (100 * r).toLocaleString('en-US', {maximumFractionDigits:0}) + '</strong>'
        : '';
    ALL_RATES.USD = r;
    convReset();
}

function syncPkr() {
    const v1000 = parseFloat(document.getElementById('ratePkr1000').value) || 0;
    const r = v1000 / 1000;
    document.getElementById('ratePkr').value = r.toFixed(6);
    document.getElementById('pkrPreview').innerHTML = v1000 > 0
        ? '1 PKR = <strong>؋ ' + r.toFixed(4) + '</strong> &nbsp;·&nbsp; 1 AFN ≈ ₨ ' + (r > 0 ? (1/r).toFixed(2) : '—')
        : '';
    ALL_RATES.PKR = r;
    convReset();
}

function convReset() {
    document.getElementById('convAfn').value = '';
    document.getElementById('convSec').value = '';
    updateConvLabel();
}

function updateConvLabel() {
    const cur = document.getElementById('convCurSel').value;
    const sym = SYMBOLS[cur] || cur;
    const r   = ALL_RATES[cur] || 1;
    document.getElementById('convSecLabel').textContent = sym + ' ' + cur;
    document.getElementById('convNote').innerHTML = '1 ' + sym + ' ' + cur + ' = <strong>؋ ' + r.toLocaleString('en-US', {maximumFractionDigits:4}) + '</strong>';
}

function convFromAfn() {
    const cur = document.getElementById('convCurSel').value;
    const r   = ALL_RATES[cur] || 1;
    const afn = parseFloat(document.getElementById('convAfn').value) || 0;
    document.getElementById('convSec').value = r > 0 ? (afn / r).toFixed(2) : '';
}
function convFromSec() {
    const cur = document.getElementById('convCurSel').value;
    const r   = ALL_RATES[cur] || 1;
    const sec = parseFloat(document.getElementById('convSec').value) || 0;
    document.getElementById('convAfn').value = (sec * r).toFixed(0);
}

document.addEventListener('DOMContentLoaded', function() {
    previewUsd();
    syncPkr();
    updateConvLabel();
});

function checkPwMatch() {
    const nw  = document.getElementById('newPwInput').value;
    const cf  = document.getElementById('confPwInput').value;
    const msg = document.getElementById('pwMatchMsg');
    if (!nw && !cf) { msg.textContent = ''; return; }
    if (nw === cf) {
        msg.className = 'form-text text-success';
        msg.textContent = 'Passwords match';
    } else {
        msg.className = 'form-text text-danger';
        msg.textContent = 'Passwords do not match';
    }
}
</script>
JS;
?>

<?php require_once '../includes/footer.php'; ?>
