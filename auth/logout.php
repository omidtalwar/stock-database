<?php
require_once '../includes/session.php';
session_destroy();
header('Location: /fzl/auth/login.php');
exit;
