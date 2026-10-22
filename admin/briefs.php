<?php
require_once __DIR__ . '/_guard.php';
require_admin();
$pdo = db();
$STATUSES = ['new', 'contacted', 'quoted', 'won', 'lost'];

/* ---------- actions ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_ok()) {
    $id = (int)($_POST['id'] ?? 0);
    if (isset($_POST['status']) && in_array($_POST['status'], $STATUSES, true)) {
        $pdo->prepare('UPDATE briefs SET status = ? WHERE id = ?')->execute([$_POST['status'], $id]);
        $pdo->prepare('INSERT INTO brief_notes (brief_id, note) VALUES (?, ?)')
            ->execute([$id, 'Status changed to ' . $_POST['status'] . ' by ' . $_SESSION['admin']]);
    }
    if (!empty(trim($_POST['note'] ?? ''))) {
        $pdo->prepare('INSERT INTO brief_notes (brief_id, note) VALUES (?, ?)')
            ->execute([$id, mb_substr(trim($_POST['note']), 0, 2000)]);
    }
    if (isset($_POST['delete']) && $_POST['delete'] === 'yes') {
        $pdo->prepare('DELETE FROM brief_notes WHERE brief_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM briefs WHERE id = ?')->execute([$id]);
        header('Location: briefs.php'); exit;
    }
    header('Location: briefs.php?id=' . $id); exit;
}

/* ---------- filters ---------- */
$where = []; $args = [];
$fs = $_GET['status'] ?? '';
$fq = trim($_GET['q'] ?? '');
$ft = $_GET['type'] ?? '';
if (in_array($fs, $STATUSES, true)) { $where[] = 'status = ?'; $args[] = $fs; }
if ($fq !== '') { $where[] = '(name LIKE ? OR company LIKE ? OR email LIKE ? OR phone LIKE ? OR goal LIKE ?)'; for ($i = 0; $i < 5; $i++) $args[] = "%$fq%"; }
if ($ft !== '') { $where[] = 'project_type = ?'; $args[] = $ft; }
$W = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ---------- CSV ---------- */
if (isset($_GET['export'])) {
    $st = $pdo->prepare("SELECT * FROM briefs $W ORDER BY created_at DESC");
    $st->execute($args);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="softnexa-briefs-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $first = true;
    while ($r = $st->fetch()) {
        unset($r['user_agent']);
        if ($first) { fputcsv($out, array_keys($r)); $first = false; }
        fputcsv($out, $r);
    }
    exit;
}

$brief = null;
if (!empty($_GET['id'])) {
    $st = $pdo->prepare('SELECT * FROM briefs WHERE id = ?');
    $st->execute([(int)$_GET['id']]);
    $brief = $st->fetch();
}

admin_head('briefs.php', 'Client briefs · ' . ADMIN_TITLE);

if ($brief):
    $notes = $pdo->prepare('SELECT * FROM brief_notes WHERE brief_id = ? ORDER BY created_at DESC');
    $notes->execute([$brief['id']]); $notes = $notes->fetchAll();
    $sc = (int)$brief['brief_score'];
    $wa = preg_replace('/\D/', '', $brief['phone']);
    if (strpos($wa, '0') === 0) $wa = '92' . substr($wa, 1);
?>
<div class="top">
  <div><a href="briefs.php" class="muted" style="text-decoration:none;font-size:.86rem">← All briefs</a>
    <h1 style="margin-top:6px"><?= e($brief['name']) ?></h1>
    <span class="muted"><?= e($brief['company'] ?: 'No company given') ?> · <?= e($brief['project_type']) ?> · <?= date('d M Y, H:i', strtotime($brief['created_at'])) ?></span></div>
  <div class="sp">
    <?php if ($brief['email']): ?><a class="btn" href="mailto:<?= e($brief['email']) ?>?subject=Your%20SoftNexa%20brief">Email</a><?php endif; ?>
    <?php if ($wa): ?><a class="btn p" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Assalam o Alaikum ' . $brief['name'] . ', this is SoftNexa — thanks for sending your project brief.') ?>">WhatsApp</a><?php endif; ?>
  </div>
</div>

<div class="grid2">
  <div>
    <div class="cards" style="grid-template-columns:repeat(3,1fr)">
      <div class="card big"><b class="<?= $sc >= 70 ? 'hot' : ($sc >= 45 ? 'warm' : 'cold') ?>"><?= $sc ?></b><small>Brief score</small></div>
      <div class="card big"><b style="font-size:1.15rem"><?= e($brief['budget'] ?: '—') ?></b><small>Budget range</small></div>
      <div class="card big"><b style="font-size:1.15rem"><?= e($brief['timeline'] ?: '—') ?></b><small>Timeline</small></div>
    </div>

    <?php if ($brief['goal']): ?>
    <div class="card" style="margin-bottom:14px">
      <h2 style="margin-bottom:10px">The problem, in their words</h2>
      <p style="white-space:pre-line;margin:0;line-height:1.6"><?= e($brief['goal']) ?></p>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:14px">
      <h2 style="margin-bottom:12px">The brief</h2>
      <table>
        <?php
        $fields = ['project_type' => 'Wants built', 'features' => 'Features ticked', 'scope' => 'Scope given',
                   'integrations' => 'Must connect to', 'style' => 'Design direction', 'assets' => 'Already has',
                   'team' => 'Team size', 'current_site' => 'Current website', 'ref_links' => 'Reference sites',
                   'notes' => 'Other notes', 'heard' => 'Heard about us via', 'region' => 'Pricing region',
                   'city' => 'City', 'email' => 'Email', 'phone' => 'Phone / WhatsApp',
                   'referrer' => 'Came from', 'device' => 'Device', 'country' => 'Country'];
        foreach ($fields as $k => $label):
            $v = $brief[$k] ?? '';
            if ($v === '' || $v === null) continue;
            if (in_array($k, ['features', 'integrations', 'assets'])) $v = str_replace('; ', "\n", $v);
        ?>
          <tr><td class="muted" style="width:170px;vertical-align:top"><?= e($label) ?></td>
              <td style="white-space:pre-line"><?= e($v) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:14px">
      <h2 style="margin-bottom:12px">Stage</h2>
      <form method="post" style="display:grid;gap:8px">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$brief['id'] ?>">
        <?php foreach ($STATUSES as $s): ?>
          <button class="btn <?= $brief['status'] === $s ? 'p' : '' ?>" name="status" value="<?= $s ?>" style="justify-content:flex-start">
            <span class="pill s-<?= $s === 'quoted' ? 'proposal' : $s ?>" style="margin-right:4px"><?= $s ?></span></button>
        <?php endforeach; ?>
      </form>
    </div>
    <div class="card" style="margin-bottom:14px">
      <h2 style="margin-bottom:12px">Notes</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$brief['id'] ?>">
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
    <form method="post" onsubmit="return confirm('Delete this brief permanently?')">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$brief['id'] ?>">
      <button class="btn sm" name="delete" value="yes" style="color:var(--pink)">Delete brief</button>
    </form>
  </div>
</div>

<?php else:
    $st = $pdo->prepare("SELECT * FROM briefs $W ORDER BY created_at DESC LIMIT 300");
    $st->execute($args); $rows = $st->fetchAll();
    $counts = [];
    foreach ($pdo->query('SELECT status, COUNT(*) c FROM briefs GROUP BY status') as $r) $counts[$r['status']] = $r['c'];
    $types = $pdo->query("SELECT project_type, COUNT(*) c FROM briefs WHERE project_type <> '' GROUP BY project_type ORDER BY c DESC")->fetchAll();
    $qs = $_GET; unset($qs['export']);
?>
<div class="top">
  <div><h1>Client briefs</h1><span class="muted"><?= count($rows) ?> shown · detailed requirement forms from the website</span></div>
  <div class="sp"><a class="btn" href="?<?= e(http_build_query($qs + ['export' => 1])) ?>">Export CSV</a></div>
</div>

<div class="cards" style="grid-template-columns:repeat(5,1fr)">
  <?php foreach ($STATUSES as $s): ?>
    <a class="card" href="?status=<?= $s ?>" style="text-decoration:none;<?= $fs === $s ? 'border-color:var(--iris)' : '' ?>">
      <b style="font-family:'Bricolage Grotesque';font-size:1.5rem"><?= (int)($counts[$s] ?? 0) ?></b><br>
      <span class="pill s-<?= $s === 'quoted' ? 'proposal' : $s ?>"><?= $s ?></span></a>
  <?php endforeach; ?>
</div>

<form class="filters" method="get">
  <div style="flex:1;min-width:220px"><label>Search</label><input name="q" value="<?= e($fq) ?>" placeholder="Name, company, contact or the problem text"></div>
  <div><label>Status</label><select name="status"><option value="">Any</option>
    <?php foreach ($STATUSES as $s): ?><option <?= $fs === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
  <div><label>Project type</label><select name="type"><option value="">Any</option>
    <?php foreach ($types as $t): ?><option <?= $ft === $t['project_type'] ? 'selected' : '' ?>><?= e($t['project_type']) ?></option><?php endforeach; ?></select></div>
  <div style="align-self:flex-end;display:flex;gap:8px"><button class="btn p">Filter</button><a class="btn" href="briefs.php">Clear</a></div>
</form>

<div class="card" style="padding:0;overflow:auto">
  <table>
    <thead><tr><th>When</th><th>Who</th><th>Wants</th><th>Budget</th><th>Timeline</th><th>Score</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $b): $sc = (int)$b['brief_score']; ?>
      <tr onclick="location.href='briefs.php?id=<?= (int)$b['id'] ?>'" style="cursor:pointer">
        <td class="muted"><?= date('d M, H:i', strtotime($b['created_at'])) ?></td>
        <td><b><?= e($b['name']) ?></b><?= $b['company'] ? '<br><span class="muted">' . e($b['company']) . '</span>' : '' ?></td>
        <td><?= e($b['project_type'] ?: '—') ?><?= $b['features'] ? '<br><span class="muted">' . count(array_filter(explode(';', $b['features']))) . ' features</span>' : '' ?></td>
        <td><?= e($b['budget'] ?: '—') ?></td>
        <td><?= e($b['timeline'] ?: '—') ?></td>
        <td class="score <?= $sc >= 70 ? 'hot' : ($sc >= 45 ? 'warm' : 'cold') ?>"><?= $sc ?></td>
        <td><span class="pill s-<?= $b['status'] === 'quoted' ? 'proposal' : e($b['status']) ?>"><?= e($b['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">No briefs yet — they appear the moment someone fills the form on /#/brief.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif;
admin_foot();
