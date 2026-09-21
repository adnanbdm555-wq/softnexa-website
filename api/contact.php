<?php
/* Lead intake — plain contact form and the scope builder both post here */
require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'method'], 405);
if (!rate_ok('lead', 8, 3600)) json_out(['ok' => false, 'error' => 'too_many'], 429);

$d = json_input();
if (f($d, 'website')) json_out(['ok' => true]);                       // honeypot

$name  = f($d, 'name', 120);
$email = f($d, 'email', 160);
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['ok' => false, 'error' => 'invalid'], 422);
}

$sid   = preg_replace('/[^a-zA-Z0-9_-]/', '', f($d, 'sid', 40));
$score = score_lead($d);
$ua    = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

$row = [
  'name'      => $name,
  'company'   => f($d, 'company', 160),
  'email'     => $email,
  'phone'     => f($d, 'phone', 60),
  'service'   => f($d, 'service', 160),
  'industry'  => f($d, 'industry', 80),
  'problems'  => f($d, 'problems', 2000),
  'tools'     => f($d, 'tools', 255),
  'team'      => f($d, 'team', 60),
  'urgency'   => f($d, 'urgency', 60),
  'modules'   => f($d, 'modules', 2000),
  'estimate'  => f($d, 'estimate', 80),
  'timeline'  => f($d, 'timeline', 60),
  'trial_days'=> (int) f($d, 'trial_days', 5),
  'founding'  => f($d, 'founding', 40),
  'region'    => f($d, 'region', 40),
  'message'   => f($d, 'message', 4000),
  'lead_score'=> $score,
  'source'    => f($d, 'modules') ? 'scope-builder' : 'contact-form',
  'session_id'=> $sid,
  'referrer'  => ref_host($_SERVER['HTTP_REFERER'] ?? ''),
  'country'   => country_code(),
  'device'    => device_type($ua),
  'ip'        => client_ip(),
  'user_agent'=> $ua
];

try {
    $cols = implode(',', array_keys($row));
    $marks= implode(',', array_fill(0, count($row), '?'));
    db()->prepare("INSERT INTO leads ($cols) VALUES ($marks)")->execute(array_values($row));
    $id = db()->lastInsertId();
    if ($sid) db()->prepare('UPDATE visits SET converted = 1 WHERE session_id = ?')->execute([$sid]);
    db()->prepare('INSERT INTO events (session_id, type, path, label, meta) VALUES (?,?,?,?,?)')
        ->execute([$sid, 'lead', '/lead', $row['source'], 'score ' . $score]);
} catch (Exception $e) {
    json_out(['ok' => false, 'error' => 'db'], 500);
}

/* email notification — hot leads get flagged in the subject */
$to   = setting('notify_email', NOTIFY_EMAIL);
$flag = $score >= 70 ? '[HOT ' . $score . '] ' : '[' . $score . '] ';
$subj = $flag . 'New lead: ' . $name . ($row['company'] ? ' · ' . $row['company'] : '');
$body = "New lead on " . SITE_NAME . "\n\n";
foreach ($row as $k => $v) { if ($v !== '' && !in_array($k, ['user_agent','ip'])) $body .= str_pad($k, 12) . ": $v\n"; }
$body .= "\nOpen the dashboard: https://" . ($_SERVER['HTTP_HOST'] ?? '') . "/admin/leads.php?id=$id\n";
@mail($to, $subj, $body, 'From: no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\nReply-To: $email");

json_out(['ok' => true, 'id' => (int)$id, 'score' => $score]);
