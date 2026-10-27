<?php
/* Client brief intake — the detailed requirement form on /#/brief */
require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'method'], 405);
if (!rate_ok('brief', 8, 3600)) json_out(['ok' => false, 'error' => 'too_many'], 429);

$d = json_input();
if (f($d, 'website_hp')) json_out(['ok' => true]);                 // honeypot

$name  = f($d, 'name', 120);
$email = f($d, 'email', 160);
$phone = f($d, 'phone', 60);
if ($name === '' || ($email === '' && $phone === '')) {
    json_out(['ok' => false, 'error' => 'invalid'], 422);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['ok' => false, 'error' => 'invalid_email'], 422);
}

$sid = preg_replace('/[^a-zA-Z0-9_-]/', '', f($d, 'sid', 40));
$ua  = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

/* score it the same way leads are scored, so both lists sort together */
$score = score_lead([
    'team'     => f($d, 'team'),
    'urgency'  => f($d, 'timeline'),
    'modules'  => f($d, 'features', 2000),
    'problems' => f($d, 'goal', 2000),
    'email'    => $email,
    'phone'    => $phone,
    'company'  => f($d, 'company')
]);
$budget = f($d, 'budget', 80);
if ($budget && stripos($budget, 'not decided') === false && stripos($budget, 'under') === false) $score = min(100, $score + 6);

$row = [
  'name'         => $name,
  'company'      => f($d, 'company', 160),
  'email'        => $email,
  'phone'        => $phone,
  'city'         => f($d, 'city', 80),
  'project_type' => f($d, 'project_type', 80),
  'features'     => f($d, 'features', 3000),
  'integrations' => f($d, 'integrations', 1000),
  'style'        => f($d, 'style', 160),
  'assets'       => f($d, 'assets', 500),
  'budget'       => $budget,
  'timeline'     => f($d, 'timeline', 80),
  'team'         => f($d, 'team', 60),
  'current_site' => f($d, 'current_site', 200),
  'ref_links'    => f($d, 'references', 500),
  'scope'        => f($d, 'scope', 200),
  'goal'         => f($d, 'goal', 4000),
  'notes'        => f($d, 'notes', 4000),
  'heard'        => f($d, 'heard', 80),
  'region'       => f($d, 'region', 40),
  'brief_score'  => $score,
  'session_id'   => $sid,
  'referrer'     => ref_host($_SERVER['HTTP_REFERER'] ?? ''),
  'country'      => country_code(),
  'device'       => device_type($ua),
  'ip'           => client_ip(),
  'user_agent'   => $ua
];

try {
    $cols  = '`' . implode('`,`', array_keys($row)) . '`';
    $marks = implode(',', array_fill(0, count($row), '?'));
    db()->prepare("INSERT INTO briefs ($cols) VALUES ($marks)")->execute(array_values($row));
    $id = db()->lastInsertId();
    if ($sid) db()->prepare('UPDATE visits SET converted = 1 WHERE session_id = ?')->execute([$sid]);
    db()->prepare('INSERT INTO events (session_id, type, path, label, meta) VALUES (?,?,?,?,?)')
        ->execute([$sid, 'brief', '/brief', $row['project_type'], 'score ' . $score]);
} catch (Exception $e) {
    json_out(['ok' => false, 'error' => 'db'], 500);
}

$to   = setting('notify_email', NOTIFY_EMAIL);
$isHot = $score >= 70;
$flag = $isHot ? '[HOT ' . $score . '] ' : '[' . $score . '] ';
$subj = $flag . 'Client Brief: ' . ($row['project_type'] ?: 'Project') . ' — ' . $name;

$items = [
    'Project Type' => $row['project_type'],
    'Budget'       => $row['budget'],
    'Timeline'     => $row['timeline'],
    'City/Region'  => $row['city'] . ($row['region'] ? ' (' . $row['region'] . ')' : ''),
    'Team Size'    => $row['team'],
    'Current Site' => $row['current_site'],
    'Key Features' => $row['features'],
    'Integrations' => $row['integrations'],
    'Style/Design' => $row['style'],
    'Brief Score'  => $score . ' / 100 ' . ($isHot ? '🔥' : '⚡'),
];

$host = $_SERVER['HTTP_HOST'] ?? 'softnexasolution.com';
$combinedMsg = trim(($row['goal'] ? "Primary Goal:\n" . $row['goal'] : '') . ($row['notes'] ? "\n\nAdditional Notes:\n" . $row['notes'] : ''));

$html = render_lead_email([
    'title'       => $subj,
    'badge'       => $isHot ? ('🔥 HOT BRIEF (' . $score . ')') : '⚡ CLIENT BRIEF',
    'badge_color' => '#10B981', // green emerald badge for detailed briefs
    'name'        => $name,
    'company'     => $row['company'],
    'email'       => $email,
    'phone'       => $row['phone'],
    'items'       => $items,
    'message'     => $combinedMsg,
    'cta_url'     => 'https://' . $host . '/admin/briefs.php?id=' . $id,
    'cta_text'    => 'Review Brief #' . $id . ' in Dashboard',
    'meta'        => [
        'Country' => $row['country'],
        'Device'  => $row['device'],
        'Ref'     => $row['referrer']
    ]
]);

send_html_email($to, $subj, $html, $email);

json_out(['ok' => true, 'id' => (int)$id, 'score' => $score]);
