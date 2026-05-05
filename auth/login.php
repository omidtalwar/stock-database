<?php
require_once '../includes/session.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /fzl/dashboard.php');
    exit;
}

require_once '../config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];
            header('Location: /fzl/dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    } else {
        $error = 'Please enter username and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FZL - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); min-height: 100vh; }
        .login-card { border: none; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.4); }
        .login-header { background: linear-gradient(135deg, #e94560, #0f3460); border-radius: 16px 16px 0 0; }
        .brand-logo { font-size: 2.5rem; font-weight: 900; letter-spacing: 4px; }
        .btn-login { background: linear-gradient(135deg, #e94560, #c23152); border: none; border-radius: 8px; }
        .btn-login:hover { background: linear-gradient(135deg, #c23152, #a0273f); }
        .form-control:focus { border-color: #e94560; box-shadow: 0 0 0 0.2rem rgba(233,69,96,0.25); }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center">
    <div class="container" style="max-width: 420px;">
        <div class="card login-card">
            <div class="login-header text-white text-center py-4 px-4">
                <div class="brand-logo">FZL</div>
                <p class="mb-0 opacity-75 mt-1">Business Management System</p>
            </div>
            <div class="card-body p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" name="username" class="form-control" placeholder="Enter username"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-login btn-primary w-100 py-2 fw-semibold">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                    </button>
                </form>
            </div>
        </div>
        <p class="text-center text-white-50 mt-3 small">FZL &copy; <?= date('Y') ?></p>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
