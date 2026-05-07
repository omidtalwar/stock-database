<?php
require_once '../includes/session.php';
session_destroy();
header('Location: /auth/login.php');
exit;
