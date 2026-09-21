<?php
/* Visitor + behaviour tracking endpoint (first-party, no cookies shared with anyone) */
require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false], 405);
if (!rate_ok('track', 400, 3600)) json_out(['ok' => true]);   // silently drop floods

$d    = json_input();
$sid  = preg_replace('/[^a-zA-Z0-9_-]/', '', f($d, 'sid', 40));
if (!$sid) json_out(['ok' => false], 400);

$type = f($d, 'type', 40) ?: 'pageview';
$path = f($d, 'path', 160);
$label= f($d, 'label', 160);
$meta = f($d, 'meta', 255);
$ref  = ref_host(f($d, 'ref', 300));
$ua   = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$dev  = device_type($ua);
$reg  = f($d, 'region', 10);

try {
    $st = db()->prepare('INSERT INTO visits (session_id, referrer, landing, device, country, region, ip, user_agent, pageviews, events)
        VALUES (?,?,?,?,?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE last_seen = NOW(), events = events + 1,
        pageviews = pageviews + VALUES(pageviews), region = IF(CHAR_LENGTH(VALUES(region)) = 0, region, VALUES(region))');
    $st->execute([$sid, $ref, $path, $dev, country_code(), $reg, client_ip(), $ua, $type === 'pageview' ? 1 : 0]);

    db()->prepare('INSERT INTO events (session_id, type, path, label, meta) VALUES (?,?,?,?,?)')
        ->execute([$sid, $type, $path, $label, $meta]);
} catch (Exception $e) { json_out(['ok' => false]); }

json_out(['ok' => true]);
