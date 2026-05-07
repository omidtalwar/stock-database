<?php
// Language switching via URL param
if (isset($_GET['setlang']) && in_array($_GET['setlang'], ['en', 'ps'], true)) {
    $_SESSION['lang'] = $_GET['setlang'];
    $redirect = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $redirect);
    exit;
}

$_lang_code = $_SESSION['lang'] ?? 'en';
$_lang_file = __DIR__ . '/../lang/' . $_lang_code . '.php';
if (!file_exists($_lang_file)) {
    $_lang_file = __DIR__ . '/../lang/en.php';
}
$_lang = require $_lang_file;

// Fallback to English for missing keys
$_lang_en = null;

function __($key, ...$args): string {
    global $_lang, $_lang_en, $_lang_file;
    if (isset($_lang[$key])) {
        $str = $_lang[$key];
    } else {
        if ($_lang_en === null) {
            $_lang_en = require __DIR__ . '/../lang/en.php';
        }
        $str = $_lang_en[$key] ?? $key;
    }
    return $args ? vsprintf($str, $args) : $str;
}

function isRTL(): bool {
    global $_lang;
    return ($_lang['lang_dir'] ?? 'ltr') === 'rtl';
}

function currentLang(): string {
    global $_lang;
    return $_lang['lang_code'] ?? 'en';
}
