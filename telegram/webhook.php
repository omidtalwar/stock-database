<?php
/**
 * Telegram webhook — receives messages and replies to read-only commands.
 * Telegram calls this URL on every message. Authenticated by a secret token
 * header (set when registering the webhook) + an allow-list of chat IDs.
 *
 * Register it from /telegram/setup.php → "Set webhook".
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/telegram.php';

// Always answer 200 quickly so Telegram doesn't retry.
function whDone() { http_response_code(200); echo 'ok'; exit; }

// 1) Verify the request really comes from Telegram.
$hdr = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals(tgWebhookSecret(), (string)$hdr)) { http_response_code(403); exit; }

$update = json_decode(file_get_contents('php://input'), true);
$msg    = $update['message'] ?? $update['edited_message'] ?? null;
if (!$msg) whDone();

$chatId = (string)($msg['chat']['id'] ?? '');
$text   = trim((string)($msg['text'] ?? ''));
if ($chatId === '') whDone();

// 2) Only authorised chats may query data.
if (!in_array($chatId, tgAllowedChatIds(), true)) {
    tgReply($chatId, "⛔ Not authorised.\nYour chat id: <code>" . tgEsc($chatId) . "</code>\nAsk the admin to add it.");
    whDone();
}

try {
    $reply = tgHandleCommand($pdo, $text);
} catch (\Throwable $e) {
    $reply = "⚠️ Error: " . tgEsc($e->getMessage());
}
if ($reply !== '') tgReply($chatId, $reply);
whDone();


// ── Command handling ───────────────────────────────────────────────────────────

function tgHandleCommand(PDO $pdo, string $text): string {
    if ($text === '') return '';

    // Bare number → treat as a customer id.
    if (preg_match('/^\d+$/', $text)) {
        return tgCustomerCard($pdo, (int)$text);
    }

    if (preg_match('#^/(start|help)\b#i', $text)) {
        return tgHelpText();
    }
    if (preg_match('#^/(customer|userid|user|c)\s+(\d+)#i', $text, $m)) {
        return tgCustomerCard($pdo, (int)$m[2]);
    }
    if (preg_match('#^/(find|search|s)\s+(.+)#i', $text, $m)) {
        return tgCustomerSearch($pdo, trim($m[2]));
    }
    if (preg_match('#^/(debtors|top)\b#i', $text)) {
        return tgTopDebtors($pdo);
    }
    return "Unknown command. Send /help for the list.";
}

function tgHelpText(): string {
    return "🤖 <b>FZL Bot</b>\n\n"
        . "<b>/customer 134</b> — customer details (or just send <b>134</b>)\n"
        . "<b>/find ahmad</b> — search customers by name/phone/shop\n"
        . "<b>/debtors</b> — top 10 customers by debt\n"
        . "<b>/help</b> — this list";
}

function tgCustomerCard(PDO $pdo, int $cid): string {
    $c = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $c->execute([$cid]);
    $cust = $c->fetch();
    if (!$cust) return "No customer with id <b>$cid</b>.";

    $s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(total_amount),0) t FROM sales WHERE customer_id = ?");
    $s->execute([$cid]);
    $sa = $s->fetch();

    $p = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount_afn),0) t,
                               MAX(COALESCE(payment_date, DATE(created_at))) last
                        FROM payments WHERE customer_id = ?");
    $p->execute([$cid]);
    $pa = $p->fetch();

    $debt = (float)$cust['total_debt'];
    $out  = "👤 <b>" . tgEsc($cust['name']) . "</b>  <code>#$cid</code>\n";
    if (!empty($cust['shop_name'])) $out .= "🏪 " . tgEsc($cust['shop_name']) . "\n";
    if (!empty($cust['phone']))     $out .= "📞 " . tgEsc($cust['phone']) . "\n";
    $out .= "\n💰 Debt: <b>" . tgEsc(formatAFN($debt)) . "</b>\n";
    $out .= "🧾 Invoices: " . (int)$sa['n'] . "  (" . tgEsc(formatAFN((float)$sa['t'])) . ")\n";
    $out .= "💵 Payments: " . (int)$pa['n'] . "  (" . tgEsc(formatAFN((float)$pa['t'])) . ")\n";
    $out .= "🕒 Last payment: " . ($pa['last'] ? tgEsc($pa['last']) : '—');
    return $out;
}

function tgCustomerSearch(PDO $pdo, string $q): string {
    $st = $pdo->prepare("SELECT id, name, shop_name, total_debt
                         FROM customers
                         WHERE name LIKE ? OR phone LIKE ? OR shop_name LIKE ?
                         ORDER BY total_debt DESC, name ASC LIMIT 10");
    $like = "%$q%";
    $st->execute([$like, $like, $like]);
    $rows = $st->fetchAll();
    if (!$rows) return "No customers match \"" . tgEsc($q) . "\".";

    $out = "🔎 <b>Matches for \"" . tgEsc($q) . "\"</b>\n";
    foreach ($rows as $r) {
        $out .= "\n<code>#" . $r['id'] . "</code> " . tgEsc($r['name'])
              . (!empty($r['shop_name']) ? " — " . tgEsc($r['shop_name']) : '')
              . "  · " . tgEsc(formatAFN((float)$r['total_debt']))
              . "  → /customer " . $r['id'];
    }
    return $out;
}

function tgTopDebtors(PDO $pdo): string {
    $rows = $pdo->query("SELECT id, name, total_debt FROM customers
                         WHERE total_debt > 0.01 ORDER BY total_debt DESC LIMIT 10")->fetchAll();
    if (!$rows) return "No customers currently owe money. 🎉";
    $out = "📊 <b>Top debtors</b>\n";
    foreach ($rows as $r) {
        $out .= "\n<code>#" . $r['id'] . "</code> " . tgEsc($r['name'])
              . "  · <b>" . tgEsc(formatAFN((float)$r['total_debt'])) . "</b>";
    }
    return $out;
}
