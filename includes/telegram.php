<?php
/**
 * Telegram notifier. Sends short alert messages to the admin chat configured
 * in config/telegram.php. Fire-and-forget with a short timeout so a slow or
 * unreachable Telegram never blocks a save. Never throws.
 */

function tgConfig(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = @include __DIR__ . '/../config/telegram.php';
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

/** Generic Telegram Bot API call. Returns decoded response (['ok'=>bool, ...]). */
function tgApi(string $token, string $method, array $params = [], int $timeout = 6): array {
    $url  = "https://api.telegram.org/bot{$token}/{$method}";
    $data = http_build_query($params);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $data,
            'timeout' => $timeout,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
    }
    $j = $res ? json_decode($res, true) : null;
    return is_array($j) ? $j : ['ok' => false];
}

/** Low-level send. Returns true on a transport success. */
function tgSend(string $token, string $chatId, string $text): bool {
    $r = tgApi($token, 'sendMessage', [
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ], 4);
    return !empty($r['ok']);
}

/** Reply using the configured bot token. */
function tgReply(string $chatId, string $text): bool {
    $c = tgConfig();
    if (empty($c['bot_token'])) return false;
    return tgSend((string)$c['bot_token'], $chatId, $text);
}

/** Secret token used to authenticate incoming webhook requests from Telegram. */
function tgWebhookSecret(): string {
    $c = tgConfig();
    if (!empty($c['secret'])) return (string)$c['secret'];
    return substr(hash('sha256', 'fzl|' . (string)($c['bot_token'] ?? '')), 0, 40);
}

/** Chat IDs allowed to query data via the bot (the configured chat_id, comma-list ok). */
function tgAllowedChatIds(): array {
    $c = tgConfig();
    return array_values(array_filter(array_map('trim', explode(',', (string)($c['chat_id'] ?? '')))));
}

function tgSetWebhook(string $url): array {
    $c = tgConfig();
    return tgApi((string)$c['bot_token'], 'setWebhook', [
        'url'             => $url,
        'secret_token'    => tgWebhookSecret(),
        'allowed_updates' => json_encode(['message', 'edited_message']),
        'drop_pending_updates' => 'true',
    ]);
}

function tgDeleteWebhook(): array {
    $c = tgConfig();
    return tgApi((string)$c['bot_token'], 'deleteWebhook', ['drop_pending_updates' => 'true']);
}

function tgWebhookInfo(): array {
    $c = tgConfig();
    return tgApi((string)$c['bot_token'], 'getWebhookInfo');
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
        // Broadcast to every authorised chat id (TELEGRAM_CHAT_ID may be a comma-separated list).
        foreach (tgAllowedChatIds() as $chatId) {
            tgSend((string)$c['bot_token'], $chatId, $text);
        }
    } catch (\Throwable $e) {
        // never let a notification break the app
    }
}
