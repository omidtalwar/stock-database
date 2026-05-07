<?php
session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: /fzl/dashboard.php');
} else {
    header('Location: /fzl/home.php');
}
exit;
