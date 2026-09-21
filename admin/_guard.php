<?php
/* ============================================================
   Admin guard + shared layout (dark UI matching the site)
   ============================================================ */
require_once __DIR__ . '/../api/config.php';

session_start();

function admin_login($user, $pass) {
    $st = db()->prepare('SELECT * FROM admins WHERE username = ?');
    $st->execute([$user]);
    $row = $st->fetch();
    if ($row && password_verify($pass, $row['pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = $row['username'];
        $_SESSION['admin_id'] = $row['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        db()->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?')->execute([$row['id']]);
        return true;
    }
    return false;
}
function require_admin() {
    if (empty($_SESSION['admin'])) { header('Location: login.php'); exit; }
}
function csrf() { return $_SESSION['csrf'] ?? ''; }
function csrf_ok() { return isset($_POST['csrf']) && hash_equals(csrf(), $_POST['csrf']); }
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function nf($n) { return number_format((float)$n); }

function admin_head($active = '', $title = '') {
    $nav = [
        'dashboard.php' => 'Overview',
        'leads.php'     => 'Leads',
        'analytics.php' => 'Analytics',
        'settings.php'  => 'Settings'
    ];
    ?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?: ADMIN_TITLE) ?></title>
<link rel="icon" type="image/png" href="../assets/favicon-32.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Hanken+Grotesk:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{--void:#07061A;--panel:#0E0C24;--panel2:#151234;--ink:#F4F2FF;--mut:#A49DD4;--line:#252048;--line2:#332C63;
 --iris:#6E4BFF;--cyan:#1FD3F0;--pink:#FF4D8D;--mint:#16C784;--amber:#FFB020;--r:16px}
*{box-sizing:border-box}
body{margin:0;background:var(--void);color:var(--ink);font-family:"Hanken Grotesk",system-ui,sans-serif;font-size:15px}
a{color:inherit}
h1,h2,h3{font-family:"Bricolage Grotesque",sans-serif;letter-spacing:-.03em;margin:0}
h1{font-size:1.7rem;font-weight:800}h2{font-size:1.12rem;font-weight:600}h3{font-size:.98rem;font-weight:600}
.mono{font-family:"JetBrains Mono",monospace}
.shell{display:grid;grid-template-columns:230px 1fr;min-height:100vh}
aside{border-right:1px solid var(--line);padding:20px 16px;background:rgba(255,255,255,.02);display:flex;flex-direction:column;gap:6px}
.brand{display:flex;align-items:center;gap:11px;padding-bottom:18px;margin-bottom:8px;border-bottom:1px solid var(--line)}
.brand .sq{width:30px;height:30px;border-radius:10px;background:linear-gradient(135deg,var(--iris),var(--cyan))}
.brand b{font-family:"Bricolage Grotesque";font-size:1.05rem;letter-spacing:-.03em;display:block}
.brand small{color:var(--mut);font-size:.72rem}
nav a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:11px;text-decoration:none;
 color:var(--mut);font-size:.94rem;border:1px solid transparent}
nav a:hover{background:rgba(255,255,255,.05);color:var(--ink)}
nav a.on{background:color-mix(in srgb,var(--iris) 18%,transparent);border-color:color-mix(in srgb,var(--iris) 45%,transparent);color:var(--ink)}
nav a i{width:8px;height:8px;border-radius:3px;background:var(--line2);font-style:normal}
nav a.on i{background:linear-gradient(135deg,var(--iris),var(--cyan))}
aside .foot{margin-top:auto;padding-top:14px;border-top:1px solid var(--line);font-size:.8rem;color:var(--mut)}
main{padding:26px 28px 40px;min-width:0}
.top{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:22px}
.top .sp{margin-left:auto;display:flex;gap:9px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:999px;border:1px solid var(--line2);
 background:rgba(255,255,255,.04);color:var(--ink);text-decoration:none;cursor:pointer;font:inherit;font-size:.88rem;font-weight:600}
.btn:hover{border-color:var(--iris)}
.btn.p{background:linear-gradient(110deg,var(--iris),var(--cyan));border-color:transparent;color:#fff}
.btn.sm{padding:6px 12px;font-size:.8rem}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);padding:18px}
.card.big b{font-family:"Bricolage Grotesque";font-size:1.9rem;font-weight:800;letter-spacing:-.04em;display:block;line-height:1.05}
.card small{color:var(--mut);font-size:.8rem}
.card .delta{font-size:.76rem;margin-top:6px;display:inline-block}
.up{color:var(--mint)}.down{color:var(--pink)}
.grid2{display:grid;grid-template-columns:1.4fr .6fr;gap:14px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
table{width:100%;border-collapse:collapse;font-size:.88rem}
th{text-align:left;padding:11px 12px;color:var(--mut);font-size:.72rem;letter-spacing:.05em;text-transform:uppercase;
 border-bottom:1px solid var(--line);background:rgba(255,255,255,.03)}
td{padding:11px 12px;border-bottom:1px solid var(--line)}
tr:hover td{background:rgba(255,255,255,.02)}
.pill{display:inline-block;padding:4px 10px;border-radius:999px;font-size:.74rem;border:1px solid transparent}
.s-new{background:rgba(110,75,255,.18);color:#b9a8ff;border-color:rgba(110,75,255,.4)}
.s-contacted{background:rgba(31,211,240,.14);color:var(--cyan);border-color:rgba(31,211,240,.4)}
.s-qualified{background:rgba(255,176,32,.14);color:var(--amber);border-color:rgba(255,176,32,.4)}
.s-proposal{background:rgba(255,77,141,.14);color:var(--pink);border-color:rgba(255,77,141,.4)}
.s-won{background:rgba(22,199,132,.16);color:var(--mint);border-color:rgba(22,199,132,.4)}
.s-lost{background:rgba(255,255,255,.06);color:var(--mut);border-color:var(--line2)}
.score{font-family:"JetBrains Mono",monospace;font-weight:600}
.hot{color:var(--mint)}.warm{color:var(--amber)}.cold{color:var(--mut)}
.bars{display:flex;align-items:flex-end;gap:6px;height:170px;margin-top:10px}
.bars div{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:6px;height:100%}
.bars i{width:100%;border-radius:6px 6px 2px 2px;background:linear-gradient(180deg,var(--iris),var(--cyan));min-height:2px}
.bars small{font-size:.66rem;color:var(--mut)}
.list{display:grid;gap:9px}
.list .r{display:flex;align-items:center;gap:10px;font-size:.86rem}
.list .r span.n{margin-left:auto;color:var(--mut);font-size:.8rem}
.track{height:7px;border-radius:5px;background:var(--line);overflow:hidden;flex:1;max-width:200px}
.track i{display:block;height:100%;background:linear-gradient(90deg,var(--iris),var(--cyan))}
.funnel{display:grid;gap:8px}
.fst{padding:12px 14px;border-radius:12px;background:linear-gradient(110deg,var(--iris),var(--cyan));color:#fff;font-size:.86rem}
.fst b{font-family:"Bricolage Grotesque";font-size:1.15rem;display:block}
.drop{font-size:.74rem;color:var(--mut);text-align:center}
input,select,textarea{background:rgba(255,255,255,.04);border:1px solid var(--line);color:var(--ink);
 border-radius:11px;padding:9px 12px;font:inherit;font-size:.88rem;width:100%}
input:focus,select:focus,textarea:focus{outline:0;border-color:var(--iris)}
label{display:block;font-size:.78rem;color:var(--mut);margin-bottom:5px}
.filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.filters > div{min-width:150px}
.muted{color:var(--mut)}
.empty{padding:40px;text-align:center;color:var(--mut)}
@media(max-width:900px){.shell{grid-template-columns:1fr}aside{flex-direction:row;overflow-x:auto;border-right:0;border-bottom:1px solid var(--line)}
 aside nav{display:flex;gap:6px}.brand{border-bottom:0;padding:0 12px 0 0;margin:0;border-right:1px solid var(--line)}
 aside .foot{display:none}.cards,.grid2,.grid3{grid-template-columns:1fr}}
</style></head><body><div class="shell">
<aside>
  <div class="brand"><img src="../assets/logo-mark.png" alt="" style="height:32px;width:auto"><span><b>Soft<span style="background:linear-gradient(100deg,#1E6BFF,#12C8F0);-webkit-background-clip:text;background-clip:text;color:transparent">Nexa</span></b><small>Control panel</small></span></div>
  <nav>
  <?php foreach ($nav as $file => $label): ?>
    <a href="<?= $file ?>" class="<?= $active === $file ? 'on' : '' ?>"><i></i><?= e($label) ?></a>
  <?php endforeach; ?>
  </nav>
  <div class="foot">
    Signed in as <b><?= e($_SESSION['admin'] ?? '') ?></b><br>
    <a href="logout.php" style="color:var(--pink);text-decoration:none">Sign out</a>
  </div>
</aside><main>
<?php }

function admin_foot() { ?>
</main></div></body></html>
<?php }
