<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/telegram.php';

$cfg   = tgConfig();
$token = (string)($cfg['bot_token'] ?? '');
$flash = '';

// Send a test message.
if (($_GET['action'] ?? '') === 'test') {
    if (!tgEnabled()) {
        $flash = ['warn', 'Bot is not fully configured/enabled yet (need token, chat_id, and enabled = true).'];
    } else {
        $ok = tgSend($token, (string)$cfg['chat_id'], "✅ <b>FZL test message</b>\nYour Telegram alerts are working.");
        $flash = $ok ? ['ok', 'Test message sent. Check your Telegram.'] : ['err', 'Send failed — check token/chat_id and outbound connectivity.'];
    }
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
