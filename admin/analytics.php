<?php
require_once __DIR__ . '/_guard.php';
require_admin();
$pdo  = db();
$days = max(1, min(90, (int)($_GET['days'] ?? 30)));
$q = function ($sql, $args = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); };

$live     = $q('SELECT COUNT(*) c FROM visits WHERE last_seen > (NOW() - INTERVAL 5 MINUTE)')[0]['c'];
$today    = $q('SELECT COUNT(*) c FROM visits WHERE DATE(first_seen) = CURDATE()')[0]['c'];
$avgPages = $q('SELECT COALESCE(ROUND(AVG(pageviews),1),0) c FROM visits WHERE first_seen > (NOW() - INTERVAL ? DAY)', [$days])[0]['c'];
$bounce   = $q('SELECT COALESCE(ROUND(SUM(pageviews<=1)/COUNT(*)*100),0) c FROM visits WHERE first_seen > (NOW() - INTERVAL ? DAY)', [$days])[0]['c'];
$devices  = $q('SELECT device, COUNT(*) c FROM visits WHERE first_seen > (NOW() - INTERVAL ? DAY) GROUP BY device ORDER BY c DESC', [$days]);
$regions  = $q("SELECT COALESCE(NULLIF(region,''),'unknown') r, COUNT(*) c FROM visits WHERE first_seen > (NOW() - INTERVAL ? DAY) GROUP BY r ORDER BY c DESC", [$days]);
$countries= $q("SELECT COALESCE(NULLIF(country,''),'—') k, COUNT(*) c FROM visits WHERE first_seen > (NOW() - INTERVAL ? DAY) GROUP BY k ORDER BY c DESC LIMIT 8", [$days]);
$landing  = $q("SELECT landing, COUNT(*) c, SUM(converted) conv FROM visits WHERE first_seen > (NOW() - INTERVAL ? DAY) GROUP BY landing ORDER BY c DESC LIMIT 10", [$days]);
$types    = $q("SELECT type, COUNT(*) c FROM events WHERE created_at > (NOW() - INTERVAL ? DAY) GROUP BY type ORDER BY c DESC", [$days]);
$services = $q("SELECT path, COUNT(*) c FROM events WHERE type='pageview' AND path LIKE '/services/%' AND created_at > (NOW() - INTERVAL ? DAY) GROUP BY path ORDER BY c DESC", [$days]);
$steps    = $q("SELECT label, COUNT(DISTINCT session_id) c FROM events WHERE type='wizard_step' AND created_at > (NOW() - INTERVAL ? DAY) GROUP BY label ORDER BY label", [$days]);
$verify   = $q("SELECT label, COUNT(*) c FROM events WHERE type='verify_run' AND created_at > (NOW() - INTERVAL ? DAY) GROUP BY label ORDER BY c DESC", [$days]);
$ctas     = $q("SELECT label, COUNT(*) c FROM events WHERE type='cta_click' AND created_at > (NOW() - INTERVAL ? DAY) GROUP BY label ORDER BY c DESC LIMIT 8", [$days]);
$hours    = $q("SELECT HOUR(first_seen) h, DAYOFWEEK(first_seen) d, COUNT(*) c FROM visits WHERE first_seen > (NOW() - INTERVAL ? DAY) GROUP BY h, d", [$days]);
$recent   = $q('SELECT * FROM visits ORDER BY last_seen DESC LIMIT 15');
$popViews = $q("SELECT label, COUNT(*) c FROM events WHERE type='popup_view' AND created_at > (NOW() - INTERVAL ? DAY) GROUP BY label", [$days]);
$popClick = $q("SELECT label, COUNT(*) c FROM events WHERE type='popup_click' AND created_at > (NOW() - INTERVAL ? DAY) GROUP BY label", [$days]);
$popRows = [];
foreach ($popViews as $r) $popRows[$r['label']] = ['label' => $r['label'], 'views' => (int)$r['c'], 'clicks' => 0];
foreach ($popClick as $r) { if (!isset($popRows[$r['label']])) $popRows[$r['label']] = ['label' => $r['label'], 'views' => 0, 'clicks' => 0]; $popRows[$r['label']]['clicks'] = (int)$r['c']; }
usort($popRows, function ($a, $b) { return $b['clicks'] <=> $a['clicks'] ?: $b['views'] <=> $a['views']; });

$heat = []; $hmax = 1;
foreach ($hours as $r) { $heat[$r['d']][$r['h']] = (int)$r['c']; $hmax = max($hmax, (int)$r['c']); }

function listBlock($title, $rows, $keyA, $keyB = 'c', $empty = 'Nothing recorded yet.') {
    $max = 1; foreach ($rows as $r) $max = max($max, (int)$r[$keyB]);
    echo '<div class="card"><h2>' . e($title) . '</h2><div class="list" style="margin-top:12px">';
    foreach ($rows as $r) {
        echo '<div class="r"><span style="min-width:130px">' . e($r[$keyA] ?: '/') . '</span>'
           . '<span class="track"><i style="width:' . round($r[$keyB] / $max * 100) . '%"></i></span>'
           . '<span class="n">' . nf($r[$keyB]) . '</span></div>';
    }
    if (!$rows) echo '<div class="muted" style="font-size:.85rem">' . e($empty) . '</div>';
    echo '</div></div>';
}

admin_head('analytics.php', 'Analytics · ' . ADMIN_TITLE);
?>
<div class="top">
  <div><h1>Analytics</h1><span class="muted">First-party tracking · no third-party cookies · last <?= $days ?> days</span></div>
  <div class="sp"><?php foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days'] as $d => $l): ?>
    <a class="btn sm <?= $days === $d ? 'p' : '' ?>" href="?days=<?= $d ?>"><?= $l ?></a><?php endforeach; ?></div>
</div>

<div class="cards">
  <div class="card big"><b class="hot"><?= nf($live) ?></b><small>On the site right now</small></div>
  <div class="card big"><b><?= nf($today) ?></b><small>Visitors today</small></div>
  <div class="card big"><b><?= e($avgPages) ?></b><small>Pages per visit</small></div>
  <div class="card big"><b><?= e($bounce) ?>%</b><small>Left after one page</small></div>
</div>

<div class="grid3" style="margin-bottom:14px">
  <?php listBlock('Devices', $devices, 'device'); ?>
  <?php listBlock('Pricing region shown', $regions, 'r'); ?>
  <?php listBlock('Countries (via Cloudflare)', $countries, 'k', 'c', 'Country appears once the site is behind Cloudflare.'); ?>
</div>

<div class="grid2" style="margin-bottom:14px">
  <div class="card">
    <h2>Landing pages and what they convert</h2>
    <table style="margin-top:10px">
      <thead><tr><th>First page seen</th><th>Visits</th><th>Became leads</th><th>Rate</th></tr></thead>
      <tbody><?php foreach ($landing as $l): ?>
        <tr><td><?= e($l['landing'] ?: '/') ?></td><td><?= nf($l['c']) ?></td><td><?= nf($l['conv']) ?></td>
        <td class="score"><?= $l['c'] ? round($l['conv'] / $l['c'] * 100, 1) : 0 ?>%</td></tr>
      <?php endforeach; if (!$landing): ?><tr><td colspan="4" class="empty">No visits yet.</td></tr><?php endif; ?></tbody>
    </table>
  </div>
  <?php listBlock('Everything visitors did', $types, 'type'); ?>
</div>

<div class="grid3" style="margin-bottom:14px">
  <?php listBlock('Service pages viewed', $services, 'path'); ?>
  <?php listBlock('Scope builder — reached step', $steps, 'label'); ?>
  <?php listBlock('Problem checker — problems tested', $verify, 'label'); ?>
</div>

<div class="grid2" style="margin-bottom:14px">
  <div class="card">
    <h2>When visitors arrive</h2>
    <div style="overflow-x:auto;margin-top:12px">
      <table style="font-size:.7rem;min-width:620px">
        <tr><td></td><?php for ($h = 0; $h < 24; $h += 1): ?><td class="muted" style="padding:2px;text-align:center"><?= $h % 3 === 0 ? $h : '' ?></td><?php endfor; ?></tr>
        <?php $dn = [1 => 'Sun', 2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat'];
        foreach ([2, 3, 4, 5, 6, 7, 1] as $d): ?>
          <tr><td class="muted" style="padding:2px 8px 2px 0"><?= $dn[$d] ?></td>
          <?php for ($h = 0; $h < 24; $h++): $v = $heat[$d][$h] ?? 0; ?>
            <td title="<?= $dn[$d] ?> <?= $h ?>:00 — <?= $v ?>" style="padding:2px"><div style="height:18px;border-radius:4px;background:rgba(110,75,255,<?= $v ? round(0.15 + $v / $hmax * 0.85, 2) : 0.05 ?>)"></div></td>
          <?php endfor; ?></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <p class="muted" style="font-size:.8rem;margin:10px 0 0">Karachi time. Schedule posts and follow-up calls into the darkest cells.</p>
  </div>
  <?php listBlock('Buttons clicked', $ctas, 'label'); ?>
</div>

<div class="card" style="padding:0;overflow:auto;margin-bottom:14px">
  <div style="padding:16px 18px"><h2>Popup performance</h2></div>
  <table>
    <thead><tr><th>Popup</th><th>Shown</th><th>Clicked</th><th>Click rate</th></tr></thead>
    <tbody><?php foreach ($popRows as $p): ?>
      <tr><td><?= e($p['label']) ?></td><td><?= nf($p['views']) ?></td><td><?= nf($p['clicks']) ?></td>
      <td class="score"><?= $p['views'] ? round($p['clicks'] / $p['views'] * 100, 1) : 0 ?>%</td></tr>
    <?php endforeach; if (!$popRows): ?><tr><td colspan="4" class="empty">No popups shown yet.</td></tr><?php endif; ?></tbody>
  </table>
</div>

<div class="card" style="padding:0;overflow:auto">
  <div style="padding:16px 18px"><h2>Latest sessions</h2></div>
  <table>
    <thead><tr><th>Last seen</th><th>Landed on</th><th>From</th><th>Device</th><th>Region</th><th>Pages</th><th>Actions</th><th>Lead</th></tr></thead>
    <tbody><?php foreach ($recent as $v): ?>
      <tr><td class="muted"><?= date('d M, H:i', strtotime($v['last_seen'])) ?></td>
        <td><?= e($v['landing'] ?: '/') ?></td><td><?= e($v['referrer'] ?: 'direct') ?></td>
        <td><?= e($v['device']) ?></td><td><?= e($v['region'] ?: '—') ?></td>
        <td><?= (int)$v['pageviews'] ?></td><td><?= (int)$v['events'] ?></td>
        <td><?= $v['converted'] ? '<span class="pill s-won">yes</span>' : '<span class="muted">—</span>' ?></td></tr>
    <?php endforeach; if (!$recent): ?><tr><td colspan="8" class="empty">No sessions yet.</td></tr><?php endif; ?></tbody>
  </table>
</div>
<?php admin_foot();
