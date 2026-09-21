<?php
require_once __DIR__ . '/_guard.php';
$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (admin_login(trim($_POST['username'] ?? ''), $_POST['password'] ?? '')) {
        header('Location: dashboard.php'); exit;
    }
    $err = 'Wrong username or password.';
    sleep(1);
}
if (!empty($_SESSION['admin'])) { header('Location: dashboard.php'); exit; }
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign in · <?= e(SITE_NAME) ?></title><link rel="icon" type="image/png" href="../assets/favicon-32.png">
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,800&family=Hanken+Grotesk:wght@400;500;600&display=swap" rel="stylesheet">
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#07061A;color:#F4F2FF;
 font-family:"Hanken Grotesk",system-ui,sans-serif;background-image:radial-gradient(60% 50% at 20% 10%,rgba(110,75,255,.28),transparent 60%),radial-gradient(50% 50% at 85% 85%,rgba(31,211,240,.2),transparent 60%)}
.box{width:min(380px,92vw);padding:30px;border-radius:22px;background:rgba(14,12,36,.92);border:1px solid #252048;
 box-shadow:0 40px 90px -40px rgba(0,0,0,.9)}
.sq{width:34px;height:34px;border-radius:11px;background:linear-gradient(135deg,#6E4BFF,#1FD3F0);margin-bottom:16px}
h1{font-family:"Bricolage Grotesque";font-size:1.5rem;letter-spacing:-.04em;margin:0 0 6px}
p{color:#A49DD4;font-size:.9rem;margin:0 0 20px}
label{display:block;font-size:.78rem;color:#A49DD4;margin:12px 0 5px}
input{width:100%;padding:11px 13px;border-radius:11px;border:1px solid #252048;background:rgba(255,255,255,.04);color:#fff;font:inherit}
input:focus{outline:0;border-color:#6E4BFF}
button{width:100%;margin-top:18px;padding:12px;border:0;border-radius:999px;cursor:pointer;font:inherit;font-weight:600;
 color:#fff;background:linear-gradient(110deg,#6E4BFF,#1FD3F0)}
.err{margin-top:14px;color:#FF4D8D;font-size:.86rem}
</style></head><body>
<form class="box" method="post">
  <img src="../assets/logo-mark.png" alt="SoftNexa" style="height:46px;width:auto;margin-bottom:16px">
  <h1>Soft<span style="background:linear-gradient(100deg,#1E6BFF,#12C8F0);-webkit-background-clip:text;background-clip:text;color:transparent">Nexa</span> Control</h1>
  <p>Leads, visitors and everything the site records.</p>
  <label for="u">Username</label><input id="u" name="username" autocomplete="username" required autofocus>
  <label for="p">Password</label><input id="p" name="password" type="password" autocomplete="current-password" required>
  <button type="submit">Sign in</button>
  <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
</form></body></html>
