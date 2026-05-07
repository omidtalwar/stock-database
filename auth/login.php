<?php
require_once '../includes/session.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard.php');
    exit;
}

require_once '../includes/lang.php';
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
            header('Location: /dashboard.php');
            exit;
        } else {
            $error = __('incorrect_login');
        }
    } else {
        $error = __('fill_all_fields');
    }
}

$_dir   = isRTL() ? 'rtl' : 'ltr';
$_align = isRTL() ? 'right' : 'left';
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>" dir="<?= $_dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FZL — <?= __('sign_in') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root {
    --w11-blue:       #0067C0;
    --w11-blue-hover: #005BA4;
    --w11-bg:         #F3F3F3;
    --w11-border:     rgba(0,0,0,0.08);
    --w11-shadow:     0 2px 8px rgba(0,0,0,0.06), 0 16px 48px rgba(0,0,0,0.09);
    --w11-radius:     10px;
    --w11-radius-lg:  14px;
    --w11-text:       #1C1C1C;
    --w11-muted:      #605E5C;
    --w11-red:        #C42B1C;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI Variable', 'Segoe UI', system-ui, -apple-system, sans-serif;
    font-size: 14px;
    color: var(--w11-text);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--w11-bg);
    background-image:
        radial-gradient(ellipse 70% 60% at 20% 15%, rgba(0,103,192,0.09) 0%, transparent 60%),
        radial-gradient(ellipse 60% 70% at 80% 85%, rgba(119,25,170,0.06) 0%, transparent 60%);
}

<?php if (isRTL()): ?>
body { font-family: 'Segoe UI Variable', 'Noto Naskh Arabic', 'Segoe UI', system-ui, sans-serif; }
<?php endif; ?>

.login-wrap { width: 100%; max-width: 400px; padding: 20px; }

.app-brand { text-align: center; margin-bottom: 28px; }
.app-icon {
    width: 60px; height: 60px;
    background: linear-gradient(135deg, #0067C0, #003E92);
    border-radius: 16px;
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: 1.4rem; color: #fff; letter-spacing: 2px;
    box-shadow: 0 4px 20px rgba(0,103,192,0.35); margin-bottom: 14px;
}
.app-name { font-size: 1.35rem; font-weight: 700; letter-spacing: -.3px; }
.app-sub  { font-size: 0.8rem; color: var(--w11-muted); margin-top: 3px; }

.login-card {
    background: rgba(255,255,255,0.82);
    backdrop-filter: blur(30px) saturate(200%);
    -webkit-backdrop-filter: blur(30px) saturate(200%);
    border: 1px solid var(--w11-border);
    border-radius: var(--w11-radius-lg);
    box-shadow: var(--w11-shadow);
    padding: 28px 30px 30px;
}
.card-title { font-size: 1rem; font-weight: 700; margin-bottom: 6px; }
.card-sub   { font-size: 0.78rem; color: var(--w11-muted); margin-bottom: 22px; }

.error-box {
    display: flex; align-items: flex-start; gap: 9px;
    padding: 10px 14px;
    background: rgba(196,43,28,0.07);
    border: 1px solid rgba(196,43,28,0.2);
    border-radius: var(--w11-radius);
    color: var(--w11-red); font-size: 0.82rem; margin-bottom: 18px;
}
.error-box i { flex-shrink: 0; margin-top: 1px; }

.field { margin-bottom: 16px; }
.field label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 5px; }
.field-wrap { position: relative; }
.field-icon {
    position: absolute; <?= $_align ?>: 12px; top: 50%; transform: translateY(-50%);
    color: var(--w11-muted); font-size: 0.95rem; pointer-events: none;
}
.field input {
    width: 100%;
    padding: 9px 12px 9px <?= isRTL() ? '12px' : '36px' ?>;
    <?php if (isRTL()): ?>padding-right: 36px;<?php endif; ?>
    border: 1px solid rgba(0,0,0,0.15);
    border-radius: var(--w11-radius);
    font-size: 0.875rem; color: var(--w11-text);
    background: rgba(255,255,255,0.7);
    outline: none; font-family: inherit;
    transition: border-color .15s, box-shadow .15s, background .15s;
    text-align: <?= $_align ?>;
}
.field input:focus {
    border-color: var(--w11-blue);
    box-shadow: 0 0 0 3px rgba(0,103,192,0.12);
    background: #fff;
}
.field input::placeholder { color: #BEBEBE; }

.eye-toggle {
    position: absolute; <?= isRTL() ? 'left' : 'right' ?>: 10px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: var(--w11-muted); font-size: 0.9rem;
    padding: 4px; border-radius: 4px; transition: color .15s;
}
.eye-toggle:hover { color: var(--w11-text); }

.btn-signin {
    width: 100%; padding: 10px;
    background: var(--w11-blue); color: #fff;
    border: none; border-radius: var(--w11-radius);
    font-size: 0.875rem; font-weight: 600;
    cursor: pointer; margin-top: 6px; font-family: inherit;
    transition: background .15s, transform .1s, box-shadow .15s;
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-signin:hover  { background: var(--w11-blue-hover); box-shadow: 0 2px 12px rgba(0,103,192,0.3); }
.btn-signin:active { transform: scale(0.99); }

.login-footer {
    text-align: center; margin-top: 20px;
    font-size: 0.72rem; color: var(--w11-muted);
}

/* Language switcher on login */
.lang-switch-login {
    display: flex; justify-content: center; gap: 8px;
    margin-bottom: 20px;
}
.lang-btn-login {
    padding: 3px 12px; border-radius: 20px; font-size: 0.72rem; font-weight: 600;
    text-decoration: none; color: var(--w11-muted);
    border: 1px solid rgba(0,0,0,0.12);
    transition: background .15s, color .15s;
}
.lang-btn-login.active { background: var(--w11-blue); color: #fff; border-color: var(--w11-blue); }
.lang-btn-login:not(.active):hover { background: rgba(0,0,0,0.05); color: var(--w11-text); }
</style>
</head>
<body>

<div class="login-wrap">

    <div class="lang-switch-login">
        <a href="?setlang=en" class="lang-btn-login <?= currentLang() === 'en' ? 'active' : '' ?>">🇬🇧 English</a>
        <a href="?setlang=ps" class="lang-btn-login <?= currentLang() === 'ps' ? 'active' : '' ?>">🇦🇫 پښتو</a>
    </div>

    <div class="app-brand">
        <div><div class="app-icon">FZL</div></div>
        <div class="app-name">FZL Management</div>
        <div class="app-sub"><?= __('management_system') ?></div>
    </div>

    <div class="login-card">
        <div class="card-title"><?= __('sign_in') ?></div>
        <div class="card-sub"><?= __('enter_credentials') ?></div>

        <?php if ($error): ?>
        <div class="error-box">
            <i class="bi bi-exclamation-circle-fill"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            <div class="field">
                <label for="username"><?= __('username') ?></label>
                <div class="field-wrap">
                    <i class="bi bi-person field-icon"></i>
                    <input type="text" id="username" name="username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           placeholder="<?= __('username') ?>"
                           autocomplete="username" required autofocus>
                </div>
            </div>

            <div class="field">
                <label for="password"><?= __('password') ?></label>
                <div class="field-wrap">
                    <i class="bi bi-lock field-icon"></i>
                    <input type="password" id="password" name="password"
                           placeholder="<?= __('password') ?>"
                           autocomplete="current-password" required>
                    <button type="button" class="eye-toggle" id="eyeBtn" tabindex="-1">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-signin">
                <i class="bi bi-box-arrow-in-<?= isRTL() ? 'left' : 'right' ?>"></i>
                <?= __('sign_in') ?>
            </button>
        </form>
    </div>

    <div class="login-footer">FZL &copy; <?= date('Y') ?></div>
</div>

<script>
const eyeBtn   = document.getElementById('eyeBtn');
const pwdInput = document.getElementById('password');
const eyeIcon  = document.getElementById('eyeIcon');
eyeBtn.addEventListener('click', () => {
    const show = pwdInput.type === 'password';
    pwdInput.type = show ? 'text' : 'password';
    eyeIcon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
});
</script>
</body>
</html>
