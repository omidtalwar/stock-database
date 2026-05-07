<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'ictswwsc_fzlstocks');
define('DB_PASS', 'Fzlstocks$529189');
define('DB_NAME', 'ictswwsc_fzlstocks');

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);
// Afghanistan Standard Time (UTC+4:30)
$pdo->exec("SET time_zone = '+04:30'");
