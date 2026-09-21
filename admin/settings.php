<?php
require_once __DIR__ . '/_guard.php';
require_admin();
$msg = ''; $err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_ok()) { $err = 'Session expired — reload and try again.'; }
    elseif (($_POST['form'] ?? '') === 'site') {
        set_setting('trial_days',     (string) max(1, min(60, (int)$_POST['trial_days'])));
        set_setting('founding_on',    isset($_POST['founding_on']) ? '1' : '0');
        set_setting('popups_on',      isset($_POST['popups_on']) ? '1' : '0');
        set_setting('founding_pct',   (string) max(0, min(60, (int)$_POST['founding_pct'])));
        set_setting('founding_slots', (string) max(0, (int)$_POST['founding_slots']));
        set_setting('founding_left',  (string) max(0, min((int)$_POST['founding_slots'], (int)$_POST['founding_left'])));
        $mail = trim($_POST['notify_email'] ?? '');
        if (filter_var($mail, FILTER_VALIDATE_EMAIL)) set_setting('notify_email', $mail);
        $msg = 'Saved. The website picks these up on the next page load.';
    }
    elseif (($_POST['form'] ?? '') === 'password') {
        $st = db()->prepare('SELECT pass_hash FROM admins WHERE id = ?');
        $st->execute([$_SESSION['admin_id']]);
        $row = $st->fetch();
        $new = $_POST['new'] ?? '';
        if (!$row || !password_verify($_POST['current'] ?? '', $row['pass_hash'])) $err = 'Current password is wrong.';
        elseif (strlen($new) < 10) $err = 'Use at least 10 characters.';
        elseif ($new !== ($_POST['confirm'] ?? '')) $err = 'The two new passwords do not match.';
        else {
            db()->prepare('UPDATE admins SET pass_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['admin_id']]);
            $msg = 'Password changed.';
        }
    }
    elseif (($_POST['form'] ?? '') === 'purge') {
        $days = max(30, (int)$_POST['older']);
        db()->prepare('DELETE FROM events WHERE created_at < (NOW() - INTERVAL ? DAY)')->execute([$days]);
        db()->prepare('DELETE FROM visits WHERE last_seen < (NOW() - INTERVAL ? DAY) AND converted = 0')->execute([$days]);
        $msg = 'Tracking data older than ' . $days . ' days removed. Leads were not touched.';
    }
}

$S = function ($k, $d) { return setting($k, $d); };
$size = db()->query('SELECT (SELECT COUNT(*) FROM events) e, (SELECT COUNT(*) FROM visits) v, (SELECT COUNT(*) FROM leads) l')->fetch();

admin_head('settings.php', 'Settings · ' . ADMIN_TITLE);
?>
<div class="top"><div><h1>Settings</h1><span class="muted">Offers, notifications and access</span></div></div>
<?php if ($msg): ?><div class="card" style="border-color:var(--mint);margin-bottom:14px;color:var(--mint)"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="card" style="border-color:var(--pink);margin-bottom:14px;color:var(--pink)"><?= e($err) ?></div><?php endif; ?>

<div class="grid2">
  <form class="card" method="post" style="display:grid;gap:14px">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="form" value="site">
    <h2>Offers shown on the website</h2>
    <div class="grid3" style="grid-template-columns:1fr 1fr">
      <div><label>Free trial length (days)</label><input type="number" name="trial_days" value="<?= e($S('trial_days', 14)) ?>" min="1" max="60"></div>
      <div><label>Lead notification email</label><input type="email" name="notify_email" value="<?= e($S('notify_email', NOTIFY_EMAIL)) ?>"></div>
    </div>
    <label style="display:flex;gap:10px;align-items:center;font-size:.9rem;color:var(--ink)">
      <input type="checkbox" name="founding_on" value="1" style="width:auto" <?= $S('founding_on', 1) == 1 ? 'checked' : '' ?>>
      Founding client programme is running</label>
    <label style="display:flex;gap:10px;align-items:center;font-size:.9rem;color:var(--ink)">
      <input type="checkbox" name="popups_on" value="1" style="width:auto" <?= $S('popups_on', 1) == 1 ? 'checked' : '' ?>>
      Show trend popups, service offers and exit offer on the website</label>
    <div class="grid3">
      <div><label>Discount %</label><input type="number" name="founding_pct" value="<?= e($S('founding_pct', 25)) ?>" min="0" max="60"></div>
      <div><label>Total slots</label><input type="number" name="founding_slots" value="<?= e($S('founding_slots', 3)) ?>" min="0"></div>
      <div><label>Slots still open</label><input type="number" name="founding_left" value="<?= e($S('founding_left', 3)) ?>" min="0"></div>
    </div>
    <p class="muted" style="font-size:.82rem;margin:0">When you sign a founding client, lower "slots still open" by one. At zero, the site stops offering it automatically.</p>
    <div><button class="btn p">Save settings</button></div>
  </form>

  <div style="display:grid;gap:14px;align-content:start">
    <form class="card" method="post" style="display:grid;gap:10px">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="form" value="password">
      <h2>Change password</h2>
      <div><label>Current password</label><input type="password" name="current" required></div>
      <div><label>New password (10+ characters)</label><input type="password" name="new" required></div>
      <div><label>Repeat new password</label><input type="password" name="confirm" required></div>
      <div><button class="btn p">Update password</button></div>
    </form>
    <form class="card" method="post" style="display:grid;gap:10px" onsubmit="return confirm('Remove old tracking data?')">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="form" value="purge">
      <h2>Data housekeeping</h2>
      <p class="muted" style="font-size:.85rem;margin:0"><?= nf($size['v']) ?> sessions · <?= nf($size['e']) ?> events · <?= nf($size['l']) ?> leads stored.</p>
      <div><label>Remove tracking older than</label><select name="older">
        <option value="90">90 days</option><option value="180" selected>180 days</option><option value="365">1 year</option></select></div>
      <div><button class="btn">Clean up</button></div>
    </form>
  </div>
</div>
<?php admin_foot();
