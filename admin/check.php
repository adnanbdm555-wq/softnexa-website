<?php
/* ============================================================
   SoftNexa — setup check
   Open https://yourdomain.com/admin/check.php
   It tells you exactly which step of the setup is missing.
   DELETE THIS FILE once the dashboard works.
   ============================================================ */
error_reporting(0);
$rows = [];
function row($ok, $label, $detail = '', $fix = '') { global $rows; $rows[] = [$ok, $label, $detail, $fix]; return $ok; }

/* 1. PHP */
row(version_compare(PHP_VERSION, '7.4', '>='), 'PHP version', PHP_VERSION,
    'hPanel → Advanced → PHP Configuration → choose PHP 8.1 or newer.');
row(extension_loaded('pdo_mysql'), 'PDO MySQL extension', extension_loaded('pdo_mysql') ? 'loaded' : 'missing',
    'hPanel → Advanced → PHP Configuration → PHP extensions → enable pdo_mysql.');

/* 2. files */
$cfgPath = __DIR__ . '/../api/config.php';
$haveCfg = row(is_file($cfgPath), 'api/config.php found', is_file($cfgPath) ? realpath($cfgPath) : 'not found',
    'The api folder must sit next to the admin folder, both directly inside public_html.');
row(is_file(__DIR__ . '/../index.html'), 'Website index.html next to admin/', '',
    'Move the contents of the softnexa folder up into public_html.');

$dbOk = false;
if ($haveCfg) {
    require_once $cfgPath;
    $placeholder = (DB_PASS === 'CHANGE_ME' || strpos(DB_NAME, 'u000000000') !== false || strpos(DB_USER, 'u000000000') !== false);
    row(!$placeholder, 'config.php has your real database details',
        $placeholder ? 'still the sample values' : 'DB ' . DB_NAME . ' · user ' . DB_USER,
        'Edit api/config.php: set DB_NAME, DB_USER and DB_PASS exactly as shown in hPanel → Databases.');

    /* 3. database connection */
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $dbOk = row(true, 'Connected to the database', DB_NAME);
    } catch (Exception $e) {
        $msg = $e->getMessage();
        $hint = 'Check DB_NAME, DB_USER and DB_PASS in api/config.php. On Hostinger the name and user both start with u followed by numbers.';
        if (stripos($msg, 'Access denied') !== false) $hint = 'Wrong username or password in api/config.php. You can reset the password in hPanel → Databases.';
        if (stripos($msg, 'Unknown database') !== false) $hint = 'DB_NAME in api/config.php does not match any database. Copy the full name from hPanel → Databases.';
        if (stripos($msg, 'getaddrinfo') !== false || stripos($msg, 'No such file') !== false || stripos($msg, 'Connection refused') !== false) $hint = 'Keep DB_HOST as localhost on Hostinger shared hosting.';
        row(false, 'Connected to the database', preg_replace('/\(using password: \w+\)/', '', $msg), $hint);
    }

    /* 4. tables + admin user */
    if ($dbOk) {
        $need = ['admins', 'settings', 'leads', 'lead_notes', 'visits', 'events', 'rate_hits'];
        $have = [];
        foreach ($pdo->query('SHOW TABLES') as $t) $have[] = array_values($t)[0];
        $missing = array_diff($need, $have);
        row(!$missing, 'All 7 tables exist', $missing ? 'missing: ' . implode(', ', $missing) : 'ok',
            'phpMyAdmin → select this database → Import → choose api/db.sql → Go.');
        if (in_array('admins', $have)) {
            $n = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
            row($n > 0, 'Admin account exists', $n . ' account(s)', 'Re-import api/db.sql — it creates the admin user.');
        }
    }
}

/* 5. sessions (needed for staying logged in) */
$sp = session_save_path() ?: sys_get_temp_dir();
row(@is_writable($sp), 'PHP sessions can be saved', $sp, 'Contact Hostinger support: the PHP session folder is not writable.');

$allOk = !array_filter($rows, function ($r) { return !$r[0]; });
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup check · SoftNexa</title>
<style>
body{margin:0;background:#07061A;color:#F4F2FF;font-family:system-ui,-apple-system,Segoe UI,sans-serif;padding:32px 18px}
.box{max-width:760px;margin:0 auto}
h1{font-size:1.5rem;margin:0 0 6px}p.s{color:#A49DD4;margin:0 0 22px}
.r{display:flex;gap:14px;padding:14px 16px;border:1px solid #252048;border-radius:14px;margin-bottom:10px;background:#0E0C24}
.i{width:26px;height:26px;border-radius:8px;display:grid;place-items:center;font-weight:700;flex:none}
.ok .i{background:rgba(22,199,132,.18);color:#16C784}.bad .i{background:rgba(255,77,141,.18);color:#FF4D8D}
.bad{border-color:rgba(255,77,141,.45)}
b{display:block}small{color:#A49DD4;word-break:break-all}.fix{margin-top:6px;color:#FFB020;font-size:.9rem}
.sum{padding:16px;border-radius:14px;margin:18px 0;font-weight:600}
.sum.good{background:rgba(22,199,132,.12);color:#16C784}.sum.no{background:rgba(255,77,141,.12);color:#FF4D8D}
a{color:#1FD3F0}
</style></head><body><div class="box">
<h1>SoftNexa setup check</h1>
<p class="s">Each line is one step of the setup. Fix the first red one, then reload this page.</p>
<?php foreach ($rows as [$ok, $label, $detail, $fix]): ?>
  <div class="r <?= $ok ? 'ok' : 'bad' ?>"><span class="i"><?= $ok ? '✓' : '✕' ?></span>
    <div><b><?= htmlspecialchars($label) ?></b><?php if ($detail !== ''): ?><small><?= htmlspecialchars($detail) ?></small><?php endif; ?>
    <?php if (!$ok && $fix): ?><div class="fix">Fix: <?= htmlspecialchars($fix) ?></div><?php endif; ?></div></div>
<?php endforeach; ?>
<?php if ($allOk): ?>
  <div class="sum good">Everything is ready. <a href="login.php">Open the dashboard login →</a><br>
  Then delete admin/check.php from File Manager — it is only for setup.</div>
<?php else: ?>
  <div class="sum no">Setup is not finished yet — fix the red line(s) above and reload.</div>
<?php endif; ?>
</div></body></html>
