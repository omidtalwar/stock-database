<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';
require_once '../includes/currency.php';
require_once '../includes/reminders.php';
require_once '../includes/telegram.php';

$cfg   = tgConfig();
$token = (string)($cfg['bot_token'] ?? '');
$flash = '';

// Send a test message.
if (($_GET['action'] ?? '') === 'test') {
    if (!tgEnabled()) {
        $flash = ['warn', 'Bot is not fully configured/enabled yet (need token, chat_id, and enabled = true).'];
    } else {
        $ok = false;
        foreach (tgAllowedChatIds() as $cid) {
            if (tgSend($token, $cid, "✅ <b>FZL test message</b>\nYour Telegram alerts are working.")) $ok = true;
        }
        $flash = $ok ? ['ok', 'Test message sent. Check your Telegram.'] : ['err', 'Send failed — check token/chat_id and outbound connectivity.'];
    }
}

// Send the daily reminders digest now (test).
if (($_GET['action'] ?? '') === 'daily_now') {
    if (!tgEnabled()) {
        $flash = ['warn', 'Bot is not enabled yet.'];
    } else {
        $msg  = reminderSummaryMessage($pdo, 15);
        $sent = 0;
        foreach (tgAllowedChatIds() as $cid) { if (tgReply($cid, $msg)) $sent++; }
        $flash = $sent ? ['ok', 'Reminders summary sent. Check Telegram.'] : ['err', 'Send failed.'];
    }
}

// Compute the webhook URL for this site.
$scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$webhookUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/telegram/webhook.php';

// Set / remove webhook (enables two-way commands).
if (($_GET['action'] ?? '') === 'set_webhook') {
    $r = tgSetWebhook($webhookUrl);
    $flash = !empty($r['ok'])
        ? ['ok', 'Webhook set. Message your bot a command like /help.']
        : ['err', 'setWebhook failed: ' . ($r['description'] ?? 'unknown error')];
}
if (($_GET['action'] ?? '') === 'remove_webhook') {
    $r = tgDeleteWebhook();
    $flash = !empty($r['ok']) ? ['ok', 'Webhook removed.'] : ['err', 'deleteWebhook failed.'];
}

// Fetch recent chats that messaged the bot (to discover chat IDs).
$updates = null; $updErr = null;
if (($_GET['action'] ?? '') === 'fetch') {
    if ($token === '') {
        $updErr = 'Set bot_token in config/telegram.php first.';
    } else {
        $raw = @file_get_contents("https://api.telegram.org/bot{$token}/getUpdates");
        $json = $raw ? json_decode($raw, true) : null;
        if (!$json || empty($json['ok'])) {
            $updErr = 'Could not read updates. Check the token, and make sure you have sent your bot a message.';
        } else {
            $seen = [];
            foreach ($json['result'] as $u) {
                $chat = $u['message']['chat'] ?? $u['channel_post']['chat'] ?? null;
                if (!$chat) continue;
                $seen[$chat['id']] = trim(($chat['title'] ?? '') . ($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? '') . ' @' . ($chat['username'] ?? ''));
            }
            $updates = $seen;
        }
    }
}

$pageTitle = 'Telegram Setup';
require_once '../includes/header.php';
?>

<div class="page-header">
    <h4 class="mb-1"><i class="bi bi-telegram me-2" style="color:#229ED9;"></i>Telegram Alerts</h4>
    <p class="text-muted small mb-0">Configure the bot that sends activity alerts to your phone.</p>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] === 'ok' ? 'success' : ($flash[0] === 'warn' ? 'warning' : 'danger') ?>"><?= htmlspecialchars($flash[1]) ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header py-3 fw-semibold">Status</div>
    <div class="card-body">
        <ul class="mb-0" style="line-height:2;">
            <li>Globally enabled: <strong class="<?= !empty($cfg['enabled']) ? 'text-success' : 'text-danger' ?>"><?= !empty($cfg['enabled']) ? 'YES' : 'NO' ?></strong></li>
            <li>Bot token set: <strong class="<?= $token !== '' ? 'text-success' : 'text-danger' ?>"><?= $token !== '' ? 'YES' : 'NO' ?></strong></li>
            <li>Chat ID set: <strong class="<?= !empty($cfg['chat_id']) ? 'text-success' : 'text-danger' ?>"><?= !empty($cfg['chat_id']) ? htmlspecialchars((string)$cfg['chat_id']) : 'NO' ?></strong></li>
        </ul>
        <div class="mt-3 d-flex gap-2">
            <a href="?action=fetch" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Fetch chat IDs</a>
            <a href="?action=test" class="btn btn-success btn-sm"><i class="bi bi-send me-1"></i>Send test message</a>
        </div>
    </div>
</div>

<?php
$whInfo = ($token !== '') ? tgWebhookInfo() : ['ok' => false];
$whSet  = !empty($whInfo['ok']) && !empty($whInfo['result']['url']);
?>
<div class="card mb-3">
    <div class="card-header py-3 fw-semibold">Commands (two-way) — webhook</div>
    <div class="card-body">
        <p class="small text-muted mb-2">Set the webhook to let you query data by messaging the bot, e.g. <code>/customer 134</code>, <code>/find ahmad</code>, <code>/debtors</code>. Only your configured chat ID can use it.</p>
        <ul class="mb-3" style="line-height:2;">
            <li>Webhook active: <strong class="<?= $whSet ? 'text-success' : 'text-danger' ?>"><?= $whSet ? 'YES' : 'NO' ?></strong></li>
            <li>URL: <code><?= htmlspecialchars($webhookUrl) ?></code></li>
            <?php if ($whSet && !empty($whInfo['result']['last_error_message'])): ?>
            <li class="text-danger small">Last error: <?= htmlspecialchars($whInfo['result']['last_error_message']) ?></li>
            <?php endif; ?>
        </ul>
        <div class="d-flex gap-2">
            <a href="?action=set_webhook" class="btn btn-primary btn-sm"><i class="bi bi-link-45deg me-1"></i>Set webhook</a>
            <a href="?action=remove_webhook" class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle me-1"></i>Remove webhook</a>
        </div>
        <div class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>Note: while the webhook is active, "Fetch chat IDs" above won't work (Telegram sends updates to the webhook instead).</div>
    </div>
</div>

<?php
$cronUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/telegram/daily_reminders.php';
?>
<div class="card mb-3">
    <div class="card-header py-3 fw-semibold">Daily reminders digest (08:00 Kabul)</div>
    <div class="card-body">
        <p class="small text-muted mb-2">Sends the list of customers unpaid 15+ days every morning. Set up a cron job in <b>cPanel → Cron Jobs</b> to run this <b>hourly</b> (the script self-guards to 08:00 Kabul and sends once per day):</p>
        <pre class="bg-light p-2 rounded small mb-2" style="white-space:pre-wrap;">/usr/local/bin/php /home/USERNAME/public_html/telegram/daily_reminders.php</pre>
        <p class="small text-muted mb-2">Replace the path with your real one (find it in File Manager). Suggested schedule: minute <code>0</code>, every hour.</p>
        <a href="?action=daily_now" class="btn btn-success btn-sm"><i class="bi bi-send me-1"></i>Send reminders summary now (test)</a>
    </div>
</div>

<?php if ($updErr): ?>
<div class="alert alert-warning"><?= htmlspecialchars($updErr) ?></div>
<?php elseif ($updates !== null): ?>
<div class="card mb-3">
    <div class="card-header py-3 fw-semibold">Chats that messaged the bot</div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Chat ID</th><th>Name / Title</th></tr></thead>
            <tbody>
                <?php if (empty($updates)): ?>
                <tr><td colspan="2" class="text-muted">None yet — send your bot a message in Telegram, then click "Fetch chat IDs" again.</td></tr>
                <?php else: foreach ($updates as $cid => $name): ?>
                <tr><td><code><?= htmlspecialchars((string)$cid) ?></code></td><td><?= htmlspecialchars($name) ?></td></tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">Copy your chat ID into <code>config/telegram.php</code> → <code>chat_id</code>, then set <code>enabled =&gt; true</code>.</div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header py-3 fw-semibold">How to set up</div>
    <div class="card-body small">
        <ol class="mb-0" style="line-height:1.9;">
            <li>In Telegram, message <strong>@BotFather</strong> → <code>/newbot</code> → copy the token.</li>
            <li>Put it in <code>config/telegram.php</code> → <code>bot_token</code>, save on the server.</li>
            <li>Send your new bot any message (or add it to a group and post there).</li>
            <li>Click <strong>Fetch chat IDs</strong> above, copy your chat ID into <code>chat_id</code>.</li>
            <li>Set <code>enabled =&gt; true</code>, then click <strong>Send test message</strong>.</li>
        </ol>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
