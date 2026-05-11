<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../includes/lang.php';
require_once '../config/db.php';

$pageTitle = __('admin_users_title');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username  = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $role      = $_POST['role'] ?? 'assistant';
        $password  = $_POST['password'] ?? '';

        if (!$username || !$full_name || !$password) {
            $_SESSION['error'] = __('fill_all_fields');
        } elseif (strlen($password) < 6) {
            $_SESSION['error'] = __('admin_new_password');
        } else {
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$username]);
            if ($check->fetch()) {
                $_SESSION['error'] = 'Username already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (username, password, full_name, role, status) VALUES (?,?,?,?,'active')")
                    ->execute([$username, $hash, $full_name, $role]);
                $_SESSION['success'] = htmlspecialchars($username);
            }
        }
    }

    if ($action === 'toggle') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === $_SESSION['user_id']) { $_SESSION['error'] = __('admin_del_self'); }
        else {
            $pdo->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([$uid]);
            $_SESSION['success'] = __('admin_toggle');
        }
    }

    if ($action === 'reset_password') {
        $uid      = (int)($_POST['user_id'] ?? 0);
        $password = $_POST['new_password'] ?? '';
        if (strlen($password) < 6) { $_SESSION['error'] = __('admin_new_password'); }
        else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $uid]);
            $_SESSION['success'] = __('admin_reset_pw');
        }
    }

    if ($action === 'delete') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === $_SESSION['user_id']) { $_SESSION['error'] = __('admin_del_self'); }
        else {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
            $_SESSION['success'] = __('btn_delete');
        }
    }

    header('Location: users.php');
    exit;
}

$users = $pdo->query("SELECT * FROM users ORDER BY role ASC, full_name ASC")->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('admin_users_title') ?></h4>
        <p class="text-muted small mb-0"><?= __('admin_users_sub') ?></p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-person-plus me-2"></i><?= __('admin_add_user') ?>
    </button>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= __('field_name') ?></th>
                    <th><?= __('username') ?></th>
                    <th><?= __('field_role') ?></th>
                    <th><?= __('field_status') ?></th>
                    <th><?= __('field_date') ?></th>
                    <th><?= __('field_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $i => $u): ?>
                <tr>
                    <td class="text-muted small"><?= $i + 1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="d-flex align-items-center justify-content-center text-white fw-bold"
                                 style="width:34px;height:34px;border-radius:8px;background:<?= $u['role']==='admin'?'linear-gradient(135deg,#C42B1C,#8B1E14)':'linear-gradient(135deg,#0067C0,#004A8F)' ?>;font-size:0.8rem;flex-shrink:0;">
                                <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                            </div>
                            <span class="fw-semibold"><?= htmlspecialchars($u['full_name']) ?></span>
                            <?php if ($u['id'] == $_SESSION['user_id']): ?>
                                <span class="badge bg-secondary" style="font-size:0.65rem;"><?= __('admin_you') ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><code><?= htmlspecialchars($u['username']) ?></code></td>
                    <td>
                        <span class="badge <?= $u['role'] === 'admin' ? 'bg-danger-subtle text-danger border border-danger-subtle' : 'bg-info-subtle text-info border border-info-subtle' ?>">
                            <?= ucfirst($u['role']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= $u['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                            <?= ucfirst($u['status']) ?>
                        </span>
                    </td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-light me-1" title="<?= __('admin_toggle') ?>"
                                    <?= $u['id'] == $_SESSION['user_id'] ? 'disabled' : '' ?>>
                                <i class="bi bi-toggle-<?= $u['status'] === 'active' ? 'on text-success' : 'off text-secondary' ?>"></i>
                            </button>
                        </form>
                        <button class="btn btn-sm btn-light me-1" title="<?= __('admin_reset_pw') ?>"
                                data-bs-toggle="modal" data-bs-target="#resetModal<?= $u['id'] ?>">
                            <i class="bi bi-key"></i>
                        </button>
                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('<?= htmlspecialchars(addslashes(sprintf(__('admin_del_confirm'), $u['username']))) ?>')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Reset-password modals (must be outside the table) -->
<?php foreach ($users as $u): ?>
<div class="modal fade" id="resetModal<?= $u['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><?= __('admin_reset_pw') ?> — <?= htmlspecialchars($u['username']) ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <input type="password" name="new_password" class="form-control" placeholder="<?= __('admin_new_password') ?>" required minlength="6">
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary btn-sm"><?= __('btn_update') ?></button>
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal"><?= __('btn_cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="row g-3 mt-2">
    <div class="col-md-6">
        <div class="card border-danger-subtle">
            <div class="card-header text-danger fw-semibold"><i class="bi bi-shield-fill me-2"></i><?= __('admin_perms_admin') ?></div>
            <div class="card-body small">
                <ul class="list-unstyled mb-0">
                    <li><i class="bi bi-check-circle text-success me-2"></i><?= __('admin_perm_full') ?></li>
                    <li><i class="bi bi-check-circle text-success me-2"></i><?= __('admin_perm_delete') ?></li>
                    <li><i class="bi bi-check-circle text-success me-2"></i><?= __('admin_perm_reports') ?></li>
                    <li><i class="bi bi-check-circle text-success me-2"></i><?= __('admin_perm_users') ?></li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-info-subtle">
            <div class="card-header text-info fw-semibold"><i class="bi bi-person-fill me-2"></i><?= __('admin_perms_asst') ?></div>
            <div class="card-body small">
                <ul class="list-unstyled mb-0">
                    <li><i class="bi bi-check-circle text-success me-2"></i><?= __('admin_perm_edit') ?></li>
                    <li><i class="bi bi-check-circle text-success me-2"></i><?= __('admin_perm_invoice') ?></li>
                    <li><i class="bi bi-check-circle text-success me-2"></i><?= __('admin_perm_stock') ?></li>
                    <li><i class="bi bi-x-circle text-danger me-2"></i><?= __('admin_perm_no_del') ?></li>
                    <li><i class="bi bi-x-circle text-danger me-2"></i><?= __('admin_perm_no_rep') ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= __('admin_add_modal') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('admin_full_name') ?></label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('username') ?></label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('admin_role') ?></label>
                        <select name="role" class="form-select">
                            <option value="assistant">Assistant</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('password') ?></label>
                        <input type="password" name="password" class="form-control" minlength="6" required>
                        <small class="text-muted"><?= __('admin_new_password') ?></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary"><?= __('admin_create_user') ?></button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= __('btn_cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
