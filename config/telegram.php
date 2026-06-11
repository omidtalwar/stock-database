<?php
/**
 * Telegram alert bot configuration.
 *
 * The SECRET values (token, chat id) live in config/secrets.php — the same
 * gitignored file that holds your DB credentials — so they are never committed.
 * This file only references those constants and is safe to commit.
 *
 * SETUP — add these lines to config/secrets.php on the server (cPanel File
 * Manager → config/secrets.php), then save:
 *
 *     define('TELEGRAM_ENABLED',   true);
 *     define('TELEGRAM_BOT_TOKEN', '123456789:AA...your-token-from-BotFather...');
 *     define('TELEGRAM_CHAT_ID',   '123456789');   // your chat or group id
 *
 * To find your chat id: message the bot in Telegram, then open
 * /telegram/setup.php → "Fetch chat IDs".
 */
@include_once __DIR__ . '/secrets.php';

return [
    'enabled'   => defined('TELEGRAM_ENABLED')   ? (bool)TELEGRAM_ENABLED   : false,
    'bot_token' => defined('TELEGRAM_BOT_TOKEN')  ? (string)TELEGRAM_BOT_TOKEN : '',
    'chat_id'   => defined('TELEGRAM_CHAT_ID')    ? (string)TELEGRAM_CHAT_ID  : '',
    'secret'    => defined('TELEGRAM_SECRET')     ? (string)TELEGRAM_SECRET   : '',  // optional; auto-derived from token if blank

    // Per-event switches — turn individual alerts on/off.
    'events' => [
        'payment'   => true,  // customer payment recorded
        'invoice'   => true,  // new sale / invoice
        'stock'     => true,  // stock add (in/out) movement
        'supplier'  => true,  // supplier payment / activity
        'acc_stock' => true,  // accessories: add stock
        'acc_bill'  => true,  // accessories: add bill
        'acc_pay'   => true,  // accessories: payment / charge
        'acc_owner' => true,  // accessories: owner added / deleted
        'delete'    => true,  // any record deleted (customer, invoice, payment, product, stock, accessory bill/payment/stock-in)
        'edit'      => true,  // a record edited (customer, product, stock record)
    ],
];
