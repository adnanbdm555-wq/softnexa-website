<?php
require_once __DIR__ . '/_guard.php';
require_admin();
$pdo = db();
$STATUSES = ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'];

/* ---------- actions ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_ok()) {
    $id = (int)($_POST['id'] ?? 0);
    if (isset($_POST['status']) && in_array($_POST['status'], $STATUSES, true)) {
        $pdo->prepare('UPDATE leads SET status = ? WHERE id = ?')->execute([$_POST['status'], $id]);
        $pdo->prepare('INSERT INTO lead_notes (lead_id, note) VALUES (?, ?)')
            ->execute([$id, 'Status changed to ' . $_POST['status'] . ' by ' . $_SESSION['admin']]);
    }
    if (!empty(trim($_POST['note'] ?? ''))) {
        $pdo->prepare('INSERT INTO lead_notes (lead_id, note) VALUES (?, ?)')
            ->execute([$id, mb_substr(trim($_POST['note']), 0, 2000)]);
    }
    if (isset($_POST['delete']) && $_POST['delete'] === 'yes') {
        $pdo->prepare('DELETE FROM lead_notes WHERE lead_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
        header('Location: leads.php'); exit;
    }
    header('Location: leads.php?id=' . $id); exit;
}

/* ---------- filters ---------- */
$where = []; $args = [];
$fs = $_GET['status'] ?? '';
$fq = trim($_GET['q'] ?? '');
$fmin = (int)($_GET['min'] ?? 0);
$fsrc = $_GET['source'] ?? '';
$freg = $_GET['region'] ?? '';
if (in_array($fs, $STATUSES, true)) { $where[] = 'status = ?'; $args[] = $fs; }
if ($fq !== '') { $where[] = '(name LIKE ? OR company LIKE ? OR email LIKE ? OR phone LIKE ?)'; for ($i = 0; $i < 4; $i++) $args[] = "%$fq%"; }
if ($fmin > 0) { $where[] = 'lead_score >= ?'; $args[] = $fmin; }
if (in_array($fsrc, ['scope-builder', 'contact-form'], true)) { $where[] = 'source = ?'; $args[] = $fsrc; }
if ($freg === 'pk') { $where[] = "region LIKE 'Pakistan%'"; }
if ($freg === 'intl') { $where[] = "region LIKE 'International%'"; }
$W = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ---------- CSV export ---------- */
if (isset($_GET['export'])) {
    $st = $pdo->prepare("SELECT * FROM leads $W ORDER BY created_at DESC");
    $st->execute($args);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="softnexa-leads-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                 // Excel-friendly UTF-8
    $first = true;
    while ($r = $st->fetch()) {
        unset($r['user_agent']);
        if ($first) { fputcsv($out, array_keys($r)); $first = false; }
        fputcsv($out, $r);
    }
    exit;
}

/* ---------- single lead ---------- */
$lead = null;
if (!empty($_GET['id'])) {
    $st = $pdo->prepare('SELECT * FROM leads WHERE id = ?');
    $st->execute([(int)$_GET['id']]);
    $lead = $st->fetch();
}

admin_head('leads.php', 'Leads · ' . ADMIN_TITLE);

if ($lead):
    $notes = $pdo->prepare('SELECT * FROM lead_notes WHERE lead_id = ? ORDER BY created_at DESC');
    $notes->execute([$lead['id']]); $notes = $notes->fetchAll();
    $journey = [];
    if ($lead['session_id']) {
        $j = $pdo->prepare('SELECT * FROM events WHERE session_id = ? ORDER BY created_at ASC LIMIT 60');
        $j->execute([$lead['session_id']]); $journey = $j->fetchAll();
    }
    $sc = (int)$lead['lead_score'];
    $wa = preg_replace('/\D/', '', $lead['phone']);
    if (strpos($wa, '0') === 0) $wa = '92' . substr($wa, 1);
?>
<div class="top">
  <div><a href="leads.php" class="muted" style="text-decoration:none;font-size:.86rem">← All leads</a>
    <h1 style="margin-top:6px"><?= e($lead['name']) ?></h1>
    <span class="muted"><?= e($lead['company'] ?: 'No company given') ?> · <?= date('d M Y, H:i', strtotime($lead['created_at'])) ?></span></div>
  <div class="sp">
    <a class="btn" href="mailto:<?= e($lead['email']) ?>?subject=Your%20Softnexa%20scope">Email</a>
    <?php if ($wa): ?><a class="btn p" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Assalam o Alaikum ' . $lead['name'] . ', this is Softnexa — thanks for building your scope on our site.') ?>">WhatsApp</a><?php endif; ?>
  </div>
</div>

<div class="grid2">
  <div>
    <div class="cards" style="grid-template-columns:repeat(3,1fr)">
      <div class="card big"><b class="<?= $sc >= 70 ? 'hot' : ($sc >= 45 ? 'warm' : 'cold') ?>"><?= $sc ?></b><small>Lead score</small></div>
      <div class="card big"><b style="font-size:1.25rem"><?= e($lead['estimate'] ?: '—') ?></b><small>Estimate shown</small></div>
      <div class="card big"><b style="font-size:1.25rem"><?= e($lead['timeline'] ?: '—') ?></b><small>Timeline shown</small></div>
    </div>
    <div class="card" style="margin-bottom:14px">
      <h2 style="margin-bottom:12px">What they told us</h2>
      <table>
        <?php
        $fields = ['email' => 'Email', 'phone' => 'Phone / WhatsApp', 'service' => 'Came in through', 'industry' => 'Sector',
                   'problems' => 'Problems', 'tools' => 'Current tools', 'team' => 'Team size', 'urgency' => 'Urgency',
                   'modules' => 'Modules', 'region' => 'Pricing region', 'founding' => 'Founding offer', 'trial_days' => 'Trial requested',
                   'message' => 'Message', 'source' => 'Source', 'referrer' => 'Referrer', 'device' => 'Device', 'country' => 'Country'];
        foreach ($fields as $k => $label):
            $v = $lead[$k];
            if ($v === '' || $v === null || ($k === 'trial_days' && !$v)) continue;
            if ($k === 'trial_days') $v = $v . ' days';
            if (in_array($k, ['problems', 'modules'])) $v = str_replace('; ', "\n", $v);
        ?>
          <tr><td class="muted" style="width:170px;vertical-align:top"><?= e($label) ?></td><td style="white-space:pre-line"><?= e($v) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <div class="card">
      <h2 style="margin-bottom:12px">Visitor journey before the form</h2>
      <?php if ($journey): ?>
        <table><?php foreach ($journey as $ev): ?>
          <tr><td class="muted mono" style="width:90px"><?= date('H:i:s', strtotime($ev['created_at'])) ?></td>
              <td><span class="pill s-<?= $ev['type'] === 'lead' ? 'won' : ($ev['type'] === 'pageview' ? 'lost' : 'contacted') ?>"><?= e($ev['type']) ?></span></td>
              <td><?= e($ev['path']) ?> <?= $ev['label'] ? '<span class="muted">· ' . e($ev['label']) . '</span>' : '' ?></td></tr>
        <?php endforeach; ?></table>
      <?php else: ?><div class="muted">No tracked session for this lead.</div><?php endif; ?>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:14px">
      <h2 style="margin-bottom:12px">Pipeline stage</h2>
      <form method="post" style="display:grid;gap:8px">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$lead['id'] ?>">
        <?php foreach ($STATUSES as $s): ?>
          <button class="btn <?= $lead['status'] === $s ? 'p' : '' ?>" name="status" value="<?= $s ?>" style="justify-content:flex-start">
            <span class="pill s-<?= $s ?>" style="margin-right:4px"><?= $s ?></span></button>
        <?php endforeach; ?>
      </form>
    </div>
    <div class="card" style="margin-bottom:14px">
      <h2 style="margin-bottom:12px">Notes</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$lead['id'] ?>">
        <textarea name="note" rows="3" placeholder="Called — wants a demo on Thursday…"></textarea>
        <button class="btn p sm" style="margin-top:8px">Add note</button>
      </form>
      <div class="list" style="margin-top:14px">
        <?php foreach ($notes as $n): ?>
          <div style="padding:10px 12px;border:1px solid var(--line);border-radius:11px">
            <div style="font-size:.88rem;white-space:pre-line"><?= e($n['note']) ?></div>
            <div class="muted" style="font-size:.74rem;margin-top:4px"><?= date('d M, H:i', strtotime($n['created_at'])) ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$notes): ?><div class="muted" style="font-size:.85rem">No notes yet.</div><?php endif; ?>
      </div>
    </div>
    <form method="post" onsubmit="return confirm('Delete this lead permanently?')">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$lead['id'] ?>">
      <button class="btn sm" name="delete" value="yes" style="color:var(--pink)">Delete lead</button>
    </form>
  </div>
</div>

<?php else:
    $st = $pdo->prepare("SELECT * FROM leads $W ORDER BY created_at DESC LIMIT 300");
    $st->execute($args); $rows = $st->fetchAll();
    $counts = [];
    foreach ($pdo->query('SELECT status, COUNT(*) c FROM leads GROUP BY status') as $r) $counts[$r['status']] = $r['c'];
    $qs = $_GET; unset($qs['export']);
?>
<div class="top">
  <div><h1>Leads</h1><span class="muted"><?= count($rows) ?> shown · every enquiry from the contact form and the scope builder</span></div>
  <div class="sp"><a class="btn" href="?<?= e(http_build_query($qs + ['export' => 1])) ?>">Export CSV</a></div>
</div>

<div class="cards" style="grid-template-columns:repeat(6,1fr)">
  <?php foreach ($STATUSES as $s): ?>
    <a class="card" href="?status=<?= $s ?>" style="text-decoration:none;<?= $fs === $s ? 'border-color:var(--iris)' : '' ?>">
      <b style="font-family:'Bricolage Grotesque';font-size:1.5rem"><?= (int)($counts[$s] ?? 0) ?></b><br>
      <span class="pill s-<?= $s ?>"><?= $s ?></span></a>
  <?php endforeach; ?>
</div>

<form class="filters" method="get">
  <div style="flex:1;min-width:220px"><label>Search</label><input name="q" value="<?= e($fq) ?>" placeholder="Name, company, email or phone"></div>
  <div><label>Status</label><select name="status"><option value="">Any</option>
    <?php foreach ($STATUSES as $s): ?><option <?= $fs === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
  <div><label>Minimum score</label><select name="min">
    <?php foreach ([0 => 'Any', 45 => '45+ warm', 70 => '70+ hot'] as $v => $l): ?><option value="<?= $v ?>" <?= $fmin === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div><label>Source</label><select name="source"><option value="">Any</option>
    <option value="scope-builder" <?= $fsrc === 'scope-builder' ? 'selected' : '' ?>>Scope builder</option>
    <option value="contact-form" <?= $fsrc === 'contact-form' ? 'selected' : '' ?>>Contact form</option></select></div>
  <div><label>Region</label><select name="region"><option value="">Any</option>
    <option value="pk" <?= $freg === 'pk' ? 'selected' : '' ?>>Pakistan</option>
    <option value="intl" <?= $freg === 'intl' ? 'selected' : '' ?>>International</option></select></div>
  <div style="align-self:flex-end;display:flex;gap:8px"><button class="btn p">Filter</button><a class="btn" href="leads.php">Clear</a></div>
</form>

<div class="card" style="padding:0;overflow:auto">
  <table>
    <thead><tr><th>When</th><th>Who</th><th>Contact</th><th>Sector / need</th><th>Estimate</th><th>Score</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $l): $sc = (int)$l['lead_score']; ?>
      <tr onclick="location.href='leads.php?id=<?= (int)$l['id'] ?>'" style="cursor:pointer">
        <td class="muted"><?= date('d M, H:i', strtotime($l['created_at'])) ?></td>
        <td><b><?= e($l['name']) ?></b><?= $l['company'] ? '<br><span class="muted">' . e($l['company']) . '</span>' : '' ?></td>
        <td><?= e($l['email']) ?><?= $l['phone'] ? '<br><span class="muted">' . e($l['phone']) . '</span>' : '' ?></td>
        <td><?= e($l['industry'] ?: $l['service'] ?: '—') ?><br><span class="muted"><?= e($l['source']) ?></span></td>
        <td><?= e($l['estimate'] ?: '—') ?></td>
        <td class="score <?= $sc >= 70 ? 'hot' : ($sc >= 45 ? 'warm' : 'cold') ?>"><?= $sc ?></td>
        <td><span class="pill s-<?= e($l['status']) ?>"><?= e($l['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">No leads match these filters.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif;
admin_foot();
