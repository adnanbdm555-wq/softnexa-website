<?php
/* ============================================================
   SoftNexa — core config & helpers
   Edit the DB block below after creating the database in hPanel.
   ============================================================ */

define('DB_HOST', 'localhost');
define('DB_NAME', 'u142683284_softnexa');
define('DB_USER', 'u142683284_admin');
define('DB_PASS', '@Softnexa123');

define('NOTIFY_EMAIL', 'info@softnexasolution.com, adnanbdm555@gmail.com');   // where new leads are emailed
define('MAIL_FROM',    'marketing@softnexasolution.com');
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
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                 PDO::ATTR_EMULATE_PREPARES => false]
            );
            // Auto create tables if not exists
            static $checked = false;
            if (!$checked) {
                $checked = true;
                $res = $pdo->query("SHOW TABLES LIKE 'admins'");
                $hasAdmins = $res && $res->rowCount() > 0;
                $resBriefs = $pdo->query("SHOW TABLES LIKE 'briefs'");
                $hasBriefs = $resBriefs && $resBriefs->rowCount() > 0;
                if (!$hasAdmins || !$hasBriefs) {
                    $sqlFile = __DIR__ . '/db.sql';
                    if (file_exists($sqlFile)) {
                        $rawSql = file_get_contents($sqlFile);
                        $queries = preg_split('/;\s*[\r\n]+/', $rawSql);
                        foreach ($queries as $q) {
                            $q = trim($q);
                            if ($q !== '') {
                                try { $pdo->exec($q); } catch (Exception $ex) {}
                            }
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            if (php_sapi_name() !== 'cli' && (strpos($_SERVER['REQUEST_URI'] ?? '', '/admin') !== false || strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin') !== false)) {
                die('<div style="font-family:sans-serif;padding:30px;background:#151234;color:#fff;border-radius:16px;margin:40px auto;max-width:600px;border:1px solid #FF4D8D;box-shadow:0 20px 50px rgba(0,0,0,0.5)">
                    <h2 style="color:#FF4D8D;margin-top:0">Database Setup Notice</h2>
                    <p style="color:#F4F2FF;line-height:1.6">' . htmlspecialchars($e->getMessage()) . '</p>
                    <p style="color:#A49DD4;font-size:14px">Please check your database name and password in <code>api/config.php</code> or in Hostinger hPanel.</p>
                </div>');
            }
            throw $e;
        }
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
    $val = array_key_exists($key, $cache) ? $cache[$key] : $default;
    if ($key === 'notify_email') {
        if (empty($val) || strpos($val, 'softnexa.solutions') !== false) {
            $val = NOTIFY_EMAIL;
        } elseif (strpos($val, 'adnanbdm555@gmail.com') === false) {
            $val .= ', adnanbdm555@gmail.com';
        }
    }
    return $val;
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

/* ---------- Premium Responsive HTML Email Template ---------- */
function render_lead_email($data) {
    $title      = htmlspecialchars($data['title'] ?? 'New Notification');
    $badge      = htmlspecialchars($data['badge'] ?? 'New Lead');
    $badgeColor = $data['badge_color'] ?? '#6E4BFF';
    $name       = htmlspecialchars($data['name'] ?? 'Anonymous');
    $company    = htmlspecialchars($data['company'] ?? '');
    $email      = htmlspecialchars($data['email'] ?? '');
    $phone      = htmlspecialchars($data['phone'] ?? '');
    $items      = $data['items'] ?? [];
    $message    = $data['message'] ?? '';
    $ctaUrl     = htmlspecialchars($data['cta_url'] ?? '');
    $ctaText    = htmlspecialchars($data['cta_text'] ?? 'Open in Admin Dashboard');
    $meta       = $data['meta'] ?? [];
    $date       = date('d M Y, h:i A') . ' PKT';

    $itemsHtml = '';
    foreach ($items as $label => $val) {
        if ($val === '' || $val === null) continue;
        $labelEsc = htmlspecialchars($label);
        $valEsc   = nl2br(htmlspecialchars($val));
        $itemsHtml .= '
        <tr>
          <td style="padding:10px 14px;font-size:12px;color:#8B8AA5;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:34%;vertical-align:top;border-bottom:1px solid #1E1D36;">' . $labelEsc . '</td>
          <td style="padding:10px 14px;font-size:14px;color:#F0EEFF;font-weight:500;vertical-align:top;border-bottom:1px solid #1E1D36;">' . $valEsc . '</td>
        </tr>';
    }

    $messageBlock = '';
    if (!empty($message)) {
        $messageBlock = '
        <div style="margin-top:20px;padding:16px 18px;background:#15142E;border-left:4px solid #6E4BFF;border-radius:8px;">
          <div style="font-size:11px;font-weight:700;color:#9E99D4;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:6px;">Requirement / Message:</div>
          <div style="font-size:14px;line-height:1.6;color:#FFFFFF;white-space:pre-wrap;">' . nl2br(htmlspecialchars($message)) . '</div>
        </div>';
    }

    $ctaBlock = '';
    if ($ctaUrl !== '') {
        $ctaBlock = '
        <div style="text-align:center;margin-top:26px;margin-bottom:8px;">
          <a href="' . $ctaUrl . '" target="_blank" style="display:inline-block;background:linear-gradient(135deg,#6E4BFF 0%,#8A6BFF 100%);color:#FFFFFF;text-decoration:none;font-weight:600;font-size:14px;padding:12px 28px;border-radius:8px;box-shadow:0 4px 14px rgba(110,75,255,0.4);">
            ' . $ctaText . ' &rarr;
          </a>
        </div>';
    }

    $metaHtml = '';
    if (!empty($meta)) {
        $metaPieces = [];
        foreach ($meta as $k => $v) {
            if ($v) $metaPieces[] = htmlspecialchars($k . ': ' . $v);
        }
        $metaHtml = implode(' &nbsp;•&nbsp; ', $metaPieces);
    }

    return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>' . $title . '</title>
</head>
<body style="margin:0;padding:0;background-color:#070617;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#F0EEFF;-webkit-font-smoothing:antialiased;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#070617;padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#100F26;border:1px solid #23223D;border-radius:14px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,0.5);">
          
          <!-- Top Accent Bar -->
          <tr>
            <td height="4" style="background:linear-gradient(90deg,#6E4BFF 0%,#1FD3F0 50%,#6E4BFF 100%);"></td>
          </tr>

          <!-- Header -->
          <tr>
            <td style="padding:24px 28px 18px 28px;border-bottom:1px solid #1C1B33;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td>
                    <span style="font-size:22px;font-weight:800;color:#FFFFFF;letter-spacing:-0.5px;">Soft<span style="color:#1FD3F0;">Nexa</span></span>
                    <span style="display:inline-block;margin-left:8px;font-size:11px;color:#8B8AA5;font-weight:500;">Lead Notification</span>
                  </td>
                  <td align="right">
                    <span style="display:inline-block;padding:5px 12px;background:' . $badgeColor . ';color:#FFFFFF;font-size:11px;font-weight:700;border-radius:20px;text-transform:uppercase;letter-spacing:0.6px;">
                      ' . $badge . '
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Hero Name Block -->
          <tr>
            <td style="padding:22px 28px 12px 28px;">
              <h1 style="margin:0;font-size:22px;font-weight:700;color:#FFFFFF;line-height:1.3;">' . $name . ($company ? ' <span style="font-size:16px;font-weight:400;color:#9E99D4;">(' . $company . ')</span>' : '') . '</h1>
              <div style="margin-top:8px;font-size:13px;color:#8B8AA5;">
                ' . ($email ? '<a href="mailto:' . $email . '" style="color:#1FD3F0;text-decoration:none;margin-right:14px;font-weight:500;">✉ ' . $email . '</a>' : '') . '
                ' . ($phone ? '<a href="tel:' . $phone . '" style="color:#A799FF;text-decoration:none;font-weight:500;">📞 ' . $phone . '</a>' : '') . '
              </div>
            </td>
          </tr>

          <!-- Details Table -->
          <tr>
            <td style="padding:8px 28px 22px 28px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:8px;background:#0C0B1E;border-radius:8px;overflow:hidden;border:1px solid #232238;">
                ' . $itemsHtml . '
              </table>
              ' . $messageBlock . '
              ' . $ctaBlock . '
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding:18px 28px;background:#090818;border-top:1px solid #1C1B33;text-align:center;">
              <div style="font-size:12px;color:#787694;margin-bottom:6px;">
                ' . $metaHtml . '
              </div>
              <div style="font-size:11px;color:#5A5872;">
                Sent automatically by <strong>SoftNexa Lead Engine</strong> • ' . $date . '
              </div>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}

function send_html_email($to, $subject, $htmlBody, $replyTo = '') {
    $from = defined('MAIL_FROM') ? MAIL_FROM : 'marketing@softnexasolution.com';
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: SoftNexa <$from>\r\n";
    if ($replyTo) {
        $headers .= "Reply-To: $replyTo\r\n";
    }
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    $recipients = is_array($to) ? $to : array_map('trim', explode(',', $to));
    $success = true;
    foreach ($recipients as $recipient) {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $ok = @mail($recipient, $subject, $htmlBody, $headers);
            if (!$ok) $success = false;
        }
    }
    return $success;
}
