<?php
date_default_timezone_set('Asia/Kabul');

// Weekly re-login checkpoint: a session is valid until the next Saturday 00:00
// (Asia/Kabul). So every Saturday the user must sign in (and verify) again.
function loginExpiryTimestamp(): int {
    $ts = strtotime('next saturday 00:00:00');
    return $ts ?: (time() + 7 * 24 * 60 * 60);
}

// Device/network lock — only approved IPs may open the management app.
// Public pages (e.g. sales/share.php) do not include this file, so they stay open.
require_once __DIR__ . '/ip_guard.php';
enforceIpAllowlist();

if (session_status() === PHP_SESSION_NONE) {
    $__lifetime = 7 * 24 * 60 * 60; // up to a week

    ini_set('session.gc_maxlifetime', $__lifetime);

    session_set_cookie_params([
        'lifetime' => $__lifetime,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (isset($_SESSION['user_id'])) {
        // Migrate older sessions that predate the weekly checkpoint.
        if (empty($_SESSION['login_expires'])) {
            $_SESSION['login_expires'] = loginExpiryTimestamp();
        }
        if (time() >= (int)$_SESSION['login_expires']) {
            // Saturday checkpoint reached → force a fresh login + verification.
            $_SESSION = [];
        } else {
            // Keep the browser cookie aligned with the expiry.
            setcookie(session_name(), session_id(), [
                'expires'  => (int)$_SESSION['login_expires'],
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}
// Default language
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en';
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /auth/login.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        $_SESSION['error'] = 'Access denied. Admin only.';
        header('Location: /dashboard.php');
        exit;
    }
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function currentUser() {
    return [
        'id'        => $_SESSION['user_id'] ?? null,
        'username'  => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role'] ?? '',
    ];
}

function flashMessage() {
    $html = '';
    if (!empty($_SESSION['success'])) {
        $html = '<div class="alert alert-success alert-dismissible fade show" role="alert">'
              . htmlspecialchars($_SESSION['success'])
              . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        unset($_SESSION['success']);
    }
    if (!empty($_SESSION['error'])) {
        $html .= '<div class="alert alert-danger alert-dismissible fade show" role="alert">'
               . htmlspecialchars($_SESSION['error'])
               . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        unset($_SESSION['error']);
    }
    return $html;
}

// ── Duplicate-submission prevention ──
function generateFormToken(string $key = 'form_token'): string {
    $token = bin2hex(random_bytes(16));
    $_SESSION['_tok_' . $key] = $token;
    return $token;
}

function validateFormToken(string $key = 'form_token'): bool {
    $submitted = $_POST['_form_token'] ?? '';
    $stored    = $_SESSION['_tok_' . $key] ?? '';
    if (!$submitted || !$stored || !hash_equals($stored, $submitted)) {
        return false;
    }
    unset($_SESSION['_tok_' . $key]); // consume — second submit will fail
    return true;
}
