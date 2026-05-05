<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../config/db.php';

$pageTitle = 'User Management';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username  = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $role      = $_POST['role'] ?? 'assistant';
        $password  = $_POST['password'] ?? '';

        if (!$username || !$full_name || !$password) {
            $_SESSION['error'] = 'All fields are required.';
        } elseif (strlen($password) < 6) {
            $_SESSION['error'] = 'Password must be at least 6 characters.';
        } else {
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$username]);
            if ($check->fetch()) {
                $_SESSION['error'] = 'Username already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?,?,?,?)")
                    ->execute([$username, $hash, $full_name, $role]);
                $_SESSION['success'] = "User \"$username\" created.";
            }
        }
    }

    if ($action === 'toggle') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === $_SESSION['user_id']) { $_SESSION['error'] = 'Cannot deactivate yourself.'; }
        else {
            $pdo->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([$uid]);
            $_SESSION['success'] = 'User status updated.';
        }
    }

    if ($action === 'reset_password') {
        $uid      = (int)($_POST['user_id'] ?? 0);
        $password = $_POST['new_password'] ?? '';
        if (strlen($password) < 6) { $_SESSION['error'] = 'Password must be at least 6 characters.'; }
        else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $uid]);
            $_SESSION['success'] = 'Password updated.';
        }
    }

    if ($action === 'delete') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === $_SESSION['user_id']) { $_SESSION['error'] = 'Cannot delete yourself.'; }
        else {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
            $_SESSION['success'] = 'User deleted.';
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
        <h4 class="mb-1">User Management</h4>
        <p class="text-muted small mb-0">Manage admin and assistant accounts</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-person-plus me-2"></i>Add User
    </button>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>#</th><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $i => $u): ?>
                <tr>
                    <td class="text-muted small"><?= $i + 1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                                 style="width:34px;height:34px;background:<?= $u['role']==='admin'?'#e94560':'#0f3460' ?>;font-size:0.8rem;flex-shrink:0;">
                                <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                            </div>
                            <span class="fw-semibold"><?= htmlspecialchars($u['full_name']) ?></span>
                            <?php if ($u['id'] == $_SESSION['user_id']): ?>
                                <span class="badge bg-secondary" style="font-size:0.65rem;">You</span>
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
                        <!-- Toggle status -->
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-light me-1" title="Toggle Status"
                                    <?= $u['id'] == $_SESSION['user_id'] ? 'disabled' : '' ?>>
                                <i class="bi bi-toggle-<?= $u['status'] === 'active' ? 'on text-success' : 'off text-secondary' ?>"></i>
                            </button>
                        </form>
                        <!-- Reset password -->
                        <button class="btn btn-sm btn-light me-1" title="Reset Password"
                                data-bs-toggle="modal" data-bs-target="#resetModal<?= $u['id'] ?>">
                            <i class="bi bi-key"></i>
                        </button>
                        <!-- Delete -->
                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('Delete user <?= htmlspecialchars(addslashes($u['username'])) ?>?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Reset password modal per user -->
                <div class="modal fade" id="resetModal<?= $u['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-sm">
                        <div class="modal-content">
                            <div class="modal-header"><h6 class="modal-title">Reset Password — <?= htmlspecialchars($u['username']) ?></h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <input type="password" name="new_password" class="form-control" placeholder="New password (min 6 chars)" required minlength="6">
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn btn-primary btn-sm">Update</button>
                                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Role permissions info -->
<div class="row g-3 mt-2">
    <div class="col-md-6">
        <div class="card border-danger-subtle">
            <div class="card-header text-danger fw-semibold"><i class="bi bi-shield-fill me-2"></i>Admin Permissions</div>
            <div class="card-body small">
                <ul class="list-unstyled mb-0">
                    <li><i class="bi bi-check-circle text-success me-2"></i>Full access to all modules</li>
                    <li><i class="bi bi-check-circle text-success me-2"></i>Delete customers, products, invoices</li>
                    <li><i class="bi bi-check-circle text-success me-2"></i>View reports and analytics</li>
                    <li><i class="bi bi-check-circle text-success me-2"></i>Manage users (add/edit/delete)</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-info-subtle">
            <div class="card-header text-info fw-semibold"><i class="bi bi-person-fill me-2"></i>Assistant Permissions</div>
            <div class="card-body small">
                <ul class="list-unstyled mb-0">
                    <li><i class="bi bi-check-circle text-success me-2"></i>Add/edit customers and products</li>
                    <li><i class="bi bi-check-circle text-success me-2"></i>Create invoices and record payments</li>
                    <li><i class="bi bi-check-circle text-success me-2"></i>Manage stock movements</li>
                    <li><i class="bi bi-x-circle text-danger me-2"></i>Cannot delete records</li>
                    <li><i class="bi bi-x-circle text-danger me-2"></i>Cannot access reports or user management</li>
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
                <h5 class="modal-title">Add New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Role</label>
                        <select name="role" class="form-select">
                            <option value="assistant">Assistant</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password</label>
                        <input type="password" name="password" class="form-control" minlength="6" required>
                        <small class="text-muted">Minimum 6 characters</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Create User</button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
