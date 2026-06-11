<?php
// Public helper: shows this device's detected IP so it can be added to
// config/access.php (the device allow-list). Safe to leave accessible.
require_once __DIR__ . '/../includes/ip_guard.php';
$ip = htmlspecialchars(clientIp(), ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Your IP</title>
    <style>
        body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#0f172a;color:#e2e8f0;
             display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}
        .box{max-width:430px;padding:34px;background:#1e293b;border-radius:16px;text-align:center;
             box-shadow:0 12px 48px rgba(0,0,0,.45)}
        h1{font-size:1.1rem;margin:6px 0 10px;color:#94a3b8;font-weight:600}
        code{background:#334155;padding:6px 14px;border-radius:8px;color:#fbbf24;font-size:1.3rem;
             display:inline-block;margin-top:6px}
        p{color:#64748b;font-size:.82rem;line-height:1.5;margin-top:18px}
    </style>
</head>
<body>
    <div class="box">
        <div style="font-size:40px">📍</div>
        <h1>This device's IP address</h1>
        <code><?= $ip ?></code>
        <p>Send this to the developer to approve this device, then it will be added to the allow-list.</p>
    </div>
</body>
</html>
