<?php
/**
 * Telegram notifier. Sends short alert messages to the admin chat configured
 * in config/telegram.php. Fire-and-forget with a short timeout so a slow or
 * unreachable Telegram never blocks a save. Never throws.
 */

function tgConfig(): array {
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/../config/telegram.php';
        if (!is_file($path)) $path = __DIR__ . '/../config/telegram.example.php';
        $cfg = @include $path;
        if (!is_array($cfg)) $cfg = [];
    }
    return $cfg;
}

function tgEnabled(): bool {
    $c = tgConfig();
    return !empty($c['enabled']) && !empty($c['bot_token']) && !empty($c['chat_id']);
}

/** Escape dynamic values for Telegram HTML parse mode. */
function tgEsc($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Name of the logged-in user, for "by ..." lines. */
function tgActor(): string {
    return tgEsc($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Someone');
}

/** Low-level send. Returns true on a transport success. */
function tgSend(string $token, string $chatId, string $text): bool {
    $url  = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = http_build_query([
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res !== false;
    }

    // Fallback when cURL is unavailable.
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $data,
        'timeout' => 4,
    ]]);
    return @file_get_contents($url, false, $ctx) !== false;
}

/**
 * Send an alert. $event keys map to config 'events' switches; an unknown/empty
 * event always sends (when globally enabled). Silently no-ops if disabled.
 */
function tgNotify(string $text, ?string $event = null): void {
    try {
        $c = tgConfig();
        if (!tgEnabled()) return;
        if ($event !== null && array_key_exists($event, $c['events'] ?? []) && !$c['events'][$event]) return;
        tgSend((string)$c['bot_token'], (string)$c['chat_id'], $text);
    } catch (\Throwable $e) {
        // never let a notification break the app
    }
}
