<?php
/* ============================================================
   SoftNexa — core config & helpers
   Edit the DB block below after creating the database in hPanel.
   ============================================================ */

define('DB_HOST', 'localhost');
define('DB_NAME', 'u000000000_softnexa');
define('DB_USER', 'u000000000_softnexa');
define('DB_PASS', 'CHANGE_ME');

define('NOTIFY_EMAIL', 'hello@softnexa.solutions');   // where new leads are emailed
define('SITE_NAME',    'SoftNexa');
define('ADMIN_TITLE',  'SoftNexa Control');

date_default_timezone_set('Asia/Karachi');

/* fallback for hosts without the mbstring extension */
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null) { return $len === null ? substr($s, $start) : substr($s, $start, $len); }
}

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}

/* ---------- request helpers ---------- */
function json_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;
    return is_array($data) ? $data : [];
}
function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data);
    exit;
}
function f($arr, $key, $max = 500) {
    $v = isset($arr[$key]) ? trim((string)$arr[$key]) : '';
    return mb_substr($v, 0, $max);
}
function client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', $_SERVER[$k])[0];
            return mb_substr(trim($ip), 0, 45);
        }
    }
    return '';
}
function country_code() {
    return !empty($_SERVER['HTTP_CF_IPCOUNTRY']) ? mb_substr($_SERVER['HTTP_CF_IPCOUNTRY'], 0, 2) : '';
}
function device_type($ua) {
    $ua = strtolower($ua);
    if (strpos($ua, 'ipad') !== false || strpos($ua, 'tablet') !== false) return 'tablet';
    if (strpos($ua, 'mobi') !== false || strpos($ua, 'android') !== false) return 'mobile';
    return 'desktop';
}
function ref_host($ref) {
    if (!$ref) return 'direct';
    $h = parse_url($ref, PHP_URL_HOST);
    if (!$h) return 'direct';
    $h = preg_replace('/^www\./', '', $h);
    if ($h === ($_SERVER['HTTP_HOST'] ?? '')) return 'direct';
    return mb_substr($h, 0, 120);
}

/* ---------- settings ---------- */
function setting($key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT k, v FROM settings') as $r) $cache[$r['k']] = $r['v'];
        } catch (Exception $e) { $cache = []; }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}
function set_setting($key, $val) {
    $st = db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $st->execute([$key, $val]);
}

/* ---------- lead scoring (server-side, never trust the browser) ---------- */
function score_lead($d) {
    $score = 0;
    $team = strtolower(f($d, 'team'));
    if (strpos($team, '200') !== false)      $score += 30;
    elseif (strpos($team, '51') !== false)   $score += 24;
    elseif (strpos($team, '11') !== false)   $score += 16;
    else                                     $score += 8;

    $urg = strtolower(f($d, 'urgency'));
    if (strpos($urg, 'soon as') !== false)   $score += 30;
    elseif (strpos($urg, '1') !== false)     $score += 18;
    else                                     $score += 6;

    $mods = array_filter(array_map('trim', explode(';', f($d, 'modules', 1000))));
    $score += min(20, count($mods) * 4);

    $probs = array_filter(array_map('trim', explode(';', f($d, 'problems', 1000))));
    $score += min(12, count($probs) * 3);

    $email = strtolower(f($d, 'email'));
    $free = ['gmail.', 'yahoo.', 'hotmail.', 'outlook.', 'proton.'];
    $isFree = false;
    foreach ($free as $x) if (strpos($email, $x) !== false) $isFree = true;
    if ($email && !$isFree) $score += 8;          // work email is a buying signal
    if (f($d, 'phone')) $score += 4;
    if (f($d, 'company')) $score += 4;

    return max(0, min(100, $score));
}

/* ---------- simple rate limiting ---------- */
function rate_ok($bucket, $limit, $seconds) {
    try {
        $ip = client_ip();
        $st = db()->prepare('SELECT COUNT(*) c FROM rate_hits WHERE bucket=? AND ip=? AND created_at > (NOW() - INTERVAL ? SECOND)');
        $st->execute([$bucket, $ip, $seconds]);
        $c = (int)$st->fetch()['c'];
        db()->prepare('INSERT INTO rate_hits (bucket, ip) VALUES (?,?)')->execute([$bucket, $ip]);
        if (rand(1, 50) === 1) db()->query('DELETE FROM rate_hits WHERE created_at < (NOW() - INTERVAL 1 DAY)');
        return $c < $limit;
    } catch (Exception $e) { return true; }
}
