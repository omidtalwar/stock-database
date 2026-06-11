<?php
/**
 * IP allow-list guard. See config/access.php for configuration.
 */

function accessConfig(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = @include __DIR__ . '/../config/access.php';
        if (!is_array($cfg)) $cfg = [];
    }
    return $cfg;
}

/** Best-effort detection of the real visitor IP. */
function clientIp(): string {
    $cfg = accessConfig();
    if (!empty($cfg['trust_proxy'])) {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/** True if $ip matches a single IPv4/IPv6 address or an IPv4 CIDR range. */
function ipMatches(string $ip, string $entry): bool {
    $entry = trim($entry);
    if ($entry === '') return false;
    if (strpos($entry, '/') === false) {
        return $ip === $entry;
    }
    // IPv4 CIDR
    [$subnet, $bits] = explode('/', $entry, 2);
    $bits = (int)$bits;
    $ipL  = ip2long($ip);
    $subL = ip2long($subnet);
    if ($ipL === false || $subL === false || $bits < 0 || $bits > 32) return false;
    $mask = $bits === 0 ? 0 : ((~0 << (32 - $bits)) & 0xFFFFFFFF);
    return (($ipL & $mask) === ($subL & $mask));
}

function ipAllowed(string $ip, array $list): bool {
    foreach ($list as $entry) {
        if (ipMatches($ip, (string)$entry)) return true;
    }
    return false;
}

/**
 * Block the request with a 403 screen if the visitor IP is not approved.
 * No-op when the allow-list is empty (lock disabled) or from loopback.
 */
function enforceIpAllowlist(): void {
    $cfg  = accessConfig();
    $list = $cfg['allowed_ips'] ?? [];
    if (empty($list)) return; // lock disabled

    $ip = clientIp();
    if ($ip === '127.0.0.1' || $ip === '::1') return; // local dev
    if (ipAllowed($ip, $list)) return;

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $safeIp = htmlspecialchars($ip, ENT_QUOTES);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Not authorized</title><style>'
       . 'body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#0f172a;color:#e2e8f0;'
       . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}'
       . '.box{max-width:430px;padding:34px;background:#1e293b;border-radius:16px;text-align:center;'
       . 'box-shadow:0 12px 48px rgba(0,0,0,.45)}h1{font-size:1.25rem;margin:6px 0 10px}'
       . 'p{color:#94a3b8;font-size:.9rem;line-height:1.55}'
       . 'code{background:#334155;padding:3px 10px;border-radius:7px;color:#fbbf24;font-size:.95rem}'
       . '</style></head><body><div class="box"><div style="font-size:44px">🔒</div>'
       . '<h1>This device is not authorized</h1>'
       . '<p>This system can only be opened on approved devices. '
       . 'If you are the owner, add the address below to the allow-list.</p>'
       . '<p>Your IP: <code>' . $safeIp . '</code></p></div></body></html>';
    exit;
}
