<?php
// TEMPORARY connectivity test — open /tg_test.php on the LIVE site, then delete.
// Confirms the Namecheap server can reach Telegram over HTTPS (no token needed).
header('Content-Type: text/plain; charset=utf-8');

echo "PHP version      : " . PHP_VERSION . "\n";
echo "cURL extension   : " . (function_exists('curl_init') ? "available" : "MISSING") . "\n";
echo "allow_url_fopen  : " . (ini_get('allow_url_fopen') ? "on" : "off") . "\n\n";

if (!function_exists('curl_init')) {
    echo "RESULT: cURL is not available — would need allow_url_fopen / file_get_contents fallback.\n";
    exit;
}

$ch = curl_init('https://api.telegram.org/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo "HTTP status      : " . $code . "\n";
echo "cURL error       : " . ($err ?: "none") . "\n\n";

if ($code > 0) {
    echo "RESULT: ✅ Outbound HTTPS to Telegram WORKS. (Any status code, even 404, means the server reached api.telegram.org.)\n";
} else {
    echo "RESULT: ❌ Could not reach Telegram. Outbound connections may be blocked — contact Namecheap support to allow outbound HTTPS, or we use a fallback.\n";
}
