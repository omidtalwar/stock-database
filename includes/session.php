<?php
date_default_timezone_set('Asia/Kabul');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
