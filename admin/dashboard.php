<?php
require_once __DIR__ . '/_guard.php';
require_admin();

$days = max(7, min(90, (int)($_GET['days'] ?? 30)));
$pdo  = db();
$q = function ($sql, $args = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($args); return $st; };

/* ---------- headline numbers ---------- */
$visitors   = $q('SELECT COUNT(*) c FROM visits WHERE first_seen > (NOW() - INTERVAL ? DAY)', [$days])->fetch()['c'];
$prevVis    = $q('SELECT COUNT(*) c FROM visits WHERE first_seen BETWEEN (NOW() - INTERVAL ? DAY) AND (NOW() - INTERVAL ? DAY)', [$days * 2, $days])->fetch()['c'];
$pageviews  = $q('SELECT COALESCE(SUM(pageviews),0) c FROM visits WHERE first_seen > (NOW() - INTERVAL ? DAY)', [$days])->fetch()['c'];
$leads      = $q('SELECT COUNT(*) c FROM leads WHERE created_at > (NOW() - INTERVAL ? DAY)', [$days])->fetch()['c'];
$prevLeads  = $q('SELECT COUNT(*) c FROM leads WHERE created_at BETWEEN (NOW() - INTERVAL ? DAY) AND (NOW() - INTERVAL ? DAY)', [$days * 2, $days])->fetch()['c'];
$hot        = $q('SELECT COUNT(*) c FROM leads WHERE lead_score >= 70 AND created_at > (NOW() - INTERVAL ? DAY)', [$days])->fetch()['c'];
$trials     = $q('SELECT COUNT(*) c FROM leads WHERE trial_days > 0 AND created_at > (NOW() - INTERVAL ? DAY)', [$days])->fetch()['c'];
$avgScore   = $q('SELECT COALESCE(ROUND(AVG(lead_score)),0) c FROM leads WHERE created_at > (NOW() - INTERVAL ? DAY)', [$days])->fetch()['c'];
$openNew    = $q("SELECT COUNT(*) c FROM leads WHERE status='new'")->fetch()['c'];
$conv       = $visitors ? round($leads / $visitors * 100, 1) : 0;

function delta($now, $prev) {
    if (!$prev) return $now ? '<span class="delta up">new</span>' : '';
    $d = round(($now - $prev) / $prev * 100);
    $cls = $d >= 0 ? 'up' : 'down';
    return '<span class="delta ' . $cls . '">' . ($d >= 0 ? '+' : '') . $d . '% vs previous</span>';
}

/* ---------- daily traffic ---------- */
$rows = $q('SELECT DATE(first_seen) d, COUNT(*) c FROM visits WHERE first_seen > (NOW() - INTERVAL ? DAY) GROUP BY DATE(first_seen)', [$days])->fetchAll();
$byDay = []; foreach ($rows as $r) $byDay[$r['d']] = (int)$r['c'];
$leadRows = $q('SELECT DATE(created_at) d, COUNT(*) c FROM leads WHERE created_at > (NOW() - INTERVAL ? DAY) GROUP BY DATE(created_at)', [$days])->fetchAll();
$leadByDay = []; foreach ($leadRows as $r) $leadByDay[$r['d']] = (int)$r['c'];
$series = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $series[] = ['d' => $d, 'v' => $byDay[$d] ?? 0, 'l' => $leadByDay[$d] ?? 0];
}
$maxV = max(1, max(array_column($series, 'v')));

/* ---------- funnel ---------- */
$fVisit  = (int)$visitors;
$fDemo   = (int)$q("SELECT COUNT(DISTINCT session_id) c FROM events WHERE type='demo_open' AND created_at > (NOW() - INTERVAL ? DAY)", [$days])->fetch()['c'];
$fWizard = (int)$q("SELECT COUNT(DISTINCT session_id) c FROM events WHERE type='wizard_step' AND created_at > (NOW() - INTERVAL ? DAY)", [$days])->fetch()['c'];
$fScope  = (int)$q("SELECT COUNT(DISTINCT session_id) c FROM events WHERE type='wizard_done' AND created_at > (NOW() - INTERVAL ? DAY)", [$days])->fetch()['c'];
$fLead   = (int)$leads;

/* ---------- lists ---------- */
$topPages  = $q("SELECT path, COUNT(*) c FROM events WHERE type='pageview' AND created_at > (NOW() - INTERVAL ? DAY) GROUP BY path ORDER BY c DESC LIMIT 8", [$days])->fetchAll();
$topDemos  = $q("SELECT label, COUNT(*) c FROM events WHERE type='demo_open' AND created_at > (NOW() - INTERVAL ? DAY) GROUP BY label ORDER BY c DESC LIMIT 6", [$days])->fetchAll();
$refs      = $q("SELECT COALESCE(NULLIF(referrer,''),'direct') r, COUNT(*) c FROM visits WHERE first_seen > (NOW() - INTERVAL ? DAY) GROUP BY r ORDER BY c DESC LIMIT 6", [$days])->fetchAll();
$recent    = $q('SELECT * FROM leads ORDER BY created_at DESC LIMIT 8')->fetchAll();
$maxPage   = max(1, max(array_merge([1], array_column($topPages, 'c'))));

admin_head('dashboard.php', 'Overview · ' . ADMIN_TITLE);
?>
<div class="top">
  <div><h1>Overview</h1><span class="muted">Last <?= $days ?> days · <?= date('d M Y, H:i') ?> PKT</span></div>
  <div class="sp">
    <?php foreach ([7, 30, 90] as $d): ?>
      <a class="btn sm <?= $days === $d ? 'p' : '' ?>" href="?days=<?= $d ?>"><?= $d ?> days</a>
    <?php endforeach; ?>
    <a class="btn sm" href="leads.php">Open leads<?= $openNew ? ' (' . $openNew . ' new)' : '' ?></a>
  </div>
</div>

<div class="cards">
  <div class="card big"><b><?= nf($visitors) ?></b><small>Visitors</small><?= delta($visitors, $prevVis) ?></div>
  <div class="card big"><b><?= nf($pageviews) ?></b><small>Page views</small></div>
  <div class="card big"><b><?= nf($leads) ?></b><small>Leads captured</small><?= delta($leads, $prevLeads) ?></div>
  <div class="card big"><b><?= $conv ?>%</b><small>Visitor → lead rate</small></div>
</div>
<div class="cards">
  <div class="card big"><b class="<?= $hot ? 'hot' : '' ?>"><?= nf($hot) ?></b><small>Hot leads (score 70+)</small></div>
  <div class="card big"><b><?= nf($trials) ?></b><small>Trial requests</small></div>
  <div class="card big"><b><?= nf($avgScore) ?></b><small>Average lead score</small></div>
  <div class="card big"><b><?= nf($openNew) ?></b><small>Waiting for a reply</small></div>
</div>

<div class="grid2" style="margin-bottom:14px">
  <div class="card">
    <h2>Visitors per day</h2>
    <div class="bars">
      <?php foreach ($series as $s): $h = round($s['v'] / $maxV * 100); ?>
        <div title="<?= e($s['d']) ?>: <?= $s['v'] ?> visitors, <?= $s['l'] ?> leads">
          <i style="height:<?= max(2, $h) ?>%;<?= $s['l'] ? 'background:linear-gradient(180deg,var(--mint),var(--cyan))' : '' ?>"></i>
          <?php if ($days <= 14 || date('j', strtotime($s['d'])) % 5 === 0): ?><small><?= date('j M', strtotime($s['d'])) ?></small><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="muted" style="font-size:.8rem;margin:10px 0 0">Green bars are days that produced a lead.</p>
  </div>
  <div class="card">
    <h2>Funnel</h2>
    <div class="funnel" style="margin-top:12px">
      <?php
      $stages = [['Visited the site', $fVisit, 100], ['Opened a demo', $fDemo, 82],
                 ['Started the scope builder', $fWizard, 66], ['Saw their estimate', $fScope, 52], ['Became a lead', $fLead, 40]];
      foreach ($stages as $i => [$label, $val, $w]):
      ?>
        <div class="fst" style="width:<?= $w ?>%;opacity:<?= 1 - $i * 0.12 ?>"><b><?= nf($val) ?></b><?= e($label) ?></div>
        <?php if ($i < count($stages) - 1):
          $next = $stages[$i + 1][1];
          $pct = $val ? round((1 - $next / $val) * 100) : 0; ?>
          <div class="drop">↓ <?= $pct ?>% drop-off</div>
        <?php endif; endforeach; ?>
    </div>
  </div>
</div>

<div class="grid3" style="margin-bottom:14px">
  <div class="card"><h2>Top pages</h2><div class="list" style="margin-top:12px">
    <?php foreach ($topPages as $p): ?>
      <div class="r"><span style="min-width:120px"><?= e($p['path'] ?: '/') ?></span>
        <span class="track"><i style="width:<?= round($p['c'] / $maxPage * 100) ?>%"></i></span>
        <span class="n"><?= nf($p['c']) ?></span></div>
    <?php endforeach; ?>
    <?php if (!$topPages): ?><div class="muted" style="font-size:.85rem">No page views recorded yet.</div><?php endif; ?>
  </div></div>

  <div class="card"><h2>Most opened demos</h2><div class="list" style="margin-top:12px">
    <?php foreach ($topDemos as $d): ?>
      <div class="r"><span><?= e($d['label']) ?></span><span class="n"><?= nf($d['c']) ?></span></div>
    <?php endforeach; ?>
    <?php if (!$topDemos): ?><div class="muted" style="font-size:.85rem">No demo opens yet.</div><?php endif; ?>
  </div></div>

  <div class="card"><h2>Where they came from</h2><div class="list" style="margin-top:12px">
    <?php foreach ($refs as $r): ?>
      <div class="r"><span><?= e($r['r']) ?></span><span class="n"><?= nf($r['c']) ?></span></div>
    <?php endforeach; ?>
    <?php if (!$refs): ?><div class="muted" style="font-size:.85rem">No sessions yet.</div><?php endif; ?>
  </div></div>
</div>

<div class="card">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
    <h2>Latest leads</h2><a class="btn sm" style="margin-left:auto" href="leads.php">See all</a>
  </div>
  <table>
    <thead><tr><th>When</th><th>Who</th><th>Need</th><th>Estimate</th><th>Score</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $l): ?>
      <tr onclick="location.href='leads.php?id=<?= (int)$l['id'] ?>'" style="cursor:pointer">
        <td class="muted"><?= date('d M, H:i', strtotime($l['created_at'])) ?></td>
        <td><b><?= e($l['name']) ?></b><?= $l['company'] ? '<br><span class="muted">' . e($l['company']) . '</span>' : '' ?></td>
        <td><?= e(mb_substr($l['service'] ?: $l['industry'] ?: '—', 0, 40)) ?></td>
        <td><?= e($l['estimate'] ?: '—') ?></td>
        <td class="score <?= $l['lead_score'] >= 70 ? 'hot' : ($l['lead_score'] >= 45 ? 'warm' : 'cold') ?>"><?= (int)$l['lead_score'] ?></td>
        <td><span class="pill s-<?= e($l['status']) ?>"><?= e($l['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="6" class="empty">No leads yet — they will appear here the moment the form is used.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php admin_foot();
