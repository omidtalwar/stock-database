<?php
require_once '../includes/session.php';

$_SESSION = [];

// Delete the session cookie from the browser
setcookie(session_name(), '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_destroy();
header('Location: /auth/login.php');
exit;
