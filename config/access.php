<?php
/**
 * Device / network lock — IP allow-list.
 *
 * Only the public IP addresses listed here can open the management app
 * (dashboard, sales, accessories, etc.). Everyone else gets a "not authorized"
 * screen. Public customer pages such as sales/share.php are NOT affected.
 *
 * HOW TO FIND A DEVICE'S IP:
 *   Open  /auth/myip.php  on that device — it prints the IP to add here.
 *   (e.g. https://your-domain/auth/myip.php)
 *
 * You can list plain IPs or IPv4 CIDR ranges:
 *   '203.0.113.45'        a single device
 *   '203.0.113.0/24'      a whole range (useful when an ISP IP changes a bit)
 *
 * Leave 'allowed_ips' EMPTY to DISABLE the lock (app open to any logged-in user).
 * Loopback (127.0.0.1 / ::1) is always allowed so local development still works.
 */
return [
    'allowed_ips' => [
        // '1.2.3.4',      // shop laptop
        // '5.6.7.8',      // your laptop
        // '9.10.11.12',   // mobile
    ],

    /*
     * Set to true ONLY if the site sits behind Cloudflare or another reverse
     * proxy — then the real visitor IP comes from a proxy header instead of
     * REMOTE_ADDR. Tell-tale sign: /auth/myip.php shows the SAME IP on every
     * device. If so, set this true.
     */
    'trust_proxy' => false,
];
