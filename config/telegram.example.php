<?php
/**
 * TEMPLATE — copy this to config/telegram.php (which is gitignored) and fill in
 * your real bot token + chat ID. Never commit the real token to a public repo.
 *
 * SETUP:
 *  1. In Telegram, message @BotFather → /newbot → copy the bot token.
 *  2. Create config/telegram.php (copy of this file) with the token in 'bot_token'.
 *  3. Send your bot any message, then open /telegram/setup.php → Fetch chat IDs.
 *  4. Put your chat ID in 'chat_id', set 'enabled' => true.
 */
return [
    'enabled'   => false,
    'bot_token' => '',   // 123456789:AAExampleTokenFromBotFather
    'chat_id'   => '',   // 123456789  (or -1001234567890 for a group)

    'events' => [
        'payment'   => true,  // customer payment recorded
        'invoice'   => true,  // new sale / invoice
        'stock'     => true,  // stock add (in/out) movement
        'supplier'  => true,  // supplier payment / activity
        'acc_stock' => true,  // accessories: add stock
        'acc_bill'  => true,  // accessories: add bill
        'acc_pay'   => true,  // accessories: payment / charge
        'acc_owner' => true,  // accessories: owner added / deleted
    ],
];
