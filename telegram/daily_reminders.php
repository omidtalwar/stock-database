<?php
/**
 * Daily reminders digest → Telegram, at 08:00 Asia/Kabul.
 *
 * Schedule this in cPanel → Cron Jobs to run HOURLY (or every 30 min):
 *     /usr/local/bin/php /home/USER/public_html/telegram/daily_reminders.php
 *
 * It self-guards: it only sends when the Kabul hour is 08:00, and only once per
 * day (a marker file). So the exact server timezone of cron does not matter.
 *
 * Manual test (sends immediately, ignoring the time guard):
 *   CLI:  php telegram/daily_reminders.php now
 *   Web:  /telegram/daily_reminders.php?key=<webhook-secret>&force=1
 */
date_default_timezone_set('Asia/Kabul');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/reminders.php';
require_once __DIR__ . '/../includes/telegram.php';

$isCli = (php_sapi_name() === 'cli');
$force = $isCli
    ? in_array('now', $argv ?? [], true)
    : (($_GET['force'] ?? '') === '1');

// Web access requires the secret (cron via CLI is trusted).
if (!$isCli) {
    if (!hash_equals(tgWebhookSecret(), (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('forbidden');
    }
}

if (!tgEnabled()) { echo "telegram disabled\n"; exit; }

$targetHour = 8;                         // 08:00 Kabul
$markerDir  = __DIR__ . '/../uploads/.cron';
$marker     = $markerDir . '/reminders_' . date('Ymd') . '.sent';

if (!$force) {
    if ((int)date('G') !== $targetHour) { echo "not time (" . date('H:i') . " Kabul)\n"; exit; }
    if (is_file($marker))               { echo "already sent today\n"; exit; }
}

$msg  = reminderSummaryMessage($pdo, 15);
$sent = 0;
foreach (tgAllowedChatIds() as $cid) {
    if (tgReply($cid, $msg)) $sent++;
}

// Mark as sent today and clean up old markers.
if (!is_dir($markerDir)) @mkdir($markerDir, 0755, true);
@touch($marker);
foreach (glob($markerDir . '/reminders_*.sent') ?: [] as $f) {
    if ($f !== $marker) @unlink($f);
}

echo "sent to {$sent} chat(s)\n";
