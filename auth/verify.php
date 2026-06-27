<?php
require_once '../includes/session.php';
require_once '../includes/lang.php';
require_once '../includes/telegram.php';

if (isset($_SESSION['user_id'])) { header('Location: /dashboard.php'); exit; }
if (empty($_SESSION['pending_2fa'])) { header('Location: /auth/login.php'); exit; }

$p     = &$_SESSION['pending_2fa'];
$error = '';
$info  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'verify';
    $cfg    = tgConfig();
    $token  = (string)($cfg['bot_token'] ?? '');
    $chat   = (string)($cfg['chat_id'] ?? '');

    if ($action === 'resend') {
        if (time() - (int)($p['last_sent'] ?? 0) < 30) {
            $error = 'Please wait a few seconds before requesting a new code.';
        } elseif ($token === '' || $chat === '') {
            $error = 'Telegram is not configured.';
        } else {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $sent = tgSend($token, $chat,
                "🔐 <b>New login code:</b> <code>{$code}</code>\nUser: " . tgEsc($p['username']) . "\nExpires in 10 minutes.");
            if ($sent) {
                $p['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
                $p['expires']   = time() + 600;
                $p['attempts']  = 0;
                $p['last_sent'] = time();
                $info = 'A new code was sent.';
            } else {
                $error = 'Could not resend the code. Please try again.';
            }
        }
    } else {
        $entered = preg_replace('/\D/', '', $_POST['code'] ?? '');
        if (time() > (int)($p['expires'] ?? 0)) {
            $error = 'Code expired — request a new one.';
        } elseif ((int)($p['attempts'] ?? 0) >= 5) {
            unset($_SESSION['pending_2fa']);
            $_SESSION['error'] = 'Too many attempts. Please sign in again.';
            header('Location: /auth/login.php'); exit;
        } elseif ($entered !== '' && password_verify($entered, (string)$p['code_hash'])) {
            $role = $p['role'];
            $_SESSION['user_id']       = $p['uid'];
            $_SESSION['username']      = $p['username'];
            $_SESSION['full_name']     = $p['full_name'];
            $_SESSION['role']          = $role;
            $_SESSION['login_expires'] = loginExpiryTimestamp();
            unset($_SESSION['pending_2fa']);
            session_regenerate_id(true); // prevent session fixation
            header('Location: ' . ($role === 'admin' ? '/dashboard.php' : '/sales/index.php'));
            exit;
        } else {
            $p['attempts'] = (int)($p['attempts'] ?? 0) + 1;
            $left = max(0, 5 - $p['attempts']);
            $error = 'Incorrect code. ' . $left . ' attempt(s) left.';
        }
    }
}

$_dir = isRTL() ? 'rtl' : 'ltr';
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>" dir="<?= $_dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FZL — Verification</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{--blue:#0067C0;--blue2:#005BA4;--bg:#F3F3F3;--bd:rgba(0,0,0,.08);--text:#1C1C1C;--muted:#605E5C;--red:#C42B1C;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI Variable','Segoe UI',system-ui,sans-serif;font-size:14px;color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);background-image:radial-gradient(ellipse 70% 60% at 20% 15%,rgba(0,103,192,.09),transparent 60%),radial-gradient(ellipse 60% 70% at 80% 85%,rgba(119,25,170,.06),transparent 60%);}
.wrap{width:100%;max-width:400px;padding:20px;}
.brand{text-align:center;margin-bottom:24px;}
.icon{width:60px;height:60px;background:linear-gradient(135deg,#0067C0,#003E92);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;font-size:1.6rem;color:#fff;box-shadow:0 4px 20px rgba(0,103,192,.35);}
.card{background:rgba(255,255,255,.85);backdrop-filter:blur(30px);border:1px solid var(--bd);border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.06),0 16px 48px rgba(0,0,0,.09);padding:28px 30px 30px;}
.title{font-size:1rem;font-weight:700;margin-bottom:6px;}
.sub{font-size:.78rem;color:var(--muted);margin-bottom:20px;line-height:1.5;}
.box{display:flex;align-items:flex-start;gap:9px;padding:10px 14px;border-radius:10px;font-size:.82rem;margin-bottom:16px;}
.box.err{background:rgba(196,43,28,.07);border:1px solid rgba(196,43,28,.2);color:var(--red);}
.box.ok{background:rgba(16,124,16,.07);border:1px solid rgba(16,124,16,.2);color:#107C10;}
.code-input{width:100%;padding:14px;font-size:1.6rem;letter-spacing:.5rem;text-align:center;font-weight:700;border:1px solid rgba(0,0,0,.15);border-radius:10px;background:#fff;outline:none;font-family:inherit;}
.code-input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,103,192,.12);}
.btn{width:100%;padding:11px;background:var(--blue);color:#fff;border:none;border-radius:10px;font-size:.9rem;font-weight:600;cursor:pointer;margin-top:14px;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:8px;}
.btn:hover{background:var(--blue2);}
.links{display:flex;justify-content:space-between;margin-top:16px;font-size:.78rem;}
.links a,.links button{color:var(--muted);text-decoration:none;background:none;border:none;cursor:pointer;font-family:inherit;font-size:.78rem;}
.links a:hover,.links button:hover{color:var(--blue);text-decoration:underline;}
</style>
</head>
<body>
<div class="wrap">
    <div class="brand"><div class="icon"><i class="bi bi-shield-lock"></i></div></div>
    <div class="card">
        <div class="title">Verification</div>
        <div class="sub">A 6-digit code was sent to the authorised Telegram for <b><?= htmlspecialchars($p['username']) ?></b>. Enter it to finish signing in.</div>

        <?php if ($error): ?><div class="box err"><i class="bi bi-exclamation-circle-fill"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
        <?php if ($info): ?><div class="box ok"><i class="bi bi-check-circle-fill"></i><span><?= htmlspecialchars($info) ?></span></div><?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="verify">
            <input class="code-input" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                   placeholder="······" autocomplete="one-time-code" autofocus required>
            <button class="btn" type="submit"><i class="bi bi-check2-circle"></i> Verify &amp; sign in</button>
        </form>

        <div class="links">
            <form method="POST" style="display:inline;"><input type="hidden" name="action" value="resend"><button type="submit"><i class="bi bi-arrow-repeat"></i> Resend code</button></form>
            <a href="/auth/logout.php">Cancel</a>
        </div>
    </div>
</div>
</body>
</html>
