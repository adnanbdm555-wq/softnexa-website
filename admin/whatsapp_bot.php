<?php
/* ============================================================
   SoftNexa — Official WhatsApp AI Cloud API Bot Control Center
   Configure Meta Cloud API, Monitor Live Transcripts, Test Sender
   ============================================================ */

require_once __DIR__ . '/_guard.php';
require_admin();

init_whatsapp_tables();

$msg = '';
$err = '';
$testResult = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_ok()) {
        $err = 'Session expired — please reload and try again.';
    } elseif (($_POST['action'] ?? '') === 'save_settings') {
        set_setting('wa_bot_enabled', isset($_POST['wa_bot_enabled']) ? '1' : '0');
        set_setting('wa_verify_token', trim((string)($_POST['wa_verify_token'] ?? 'softnexa_wa_bot_2026')));
        set_setting('wa_phone_id', trim((string)($_POST['wa_phone_id'] ?? '')));
        set_setting('wa_waba_id', trim((string)($_POST['wa_waba_id'] ?? '')));
        set_setting('wa_token', trim((string)($_POST['wa_token'] ?? '')));
        if (isset($_POST['gemini_api_key'])) {
            set_setting('gemini_api_key', trim((string)$_POST['gemini_api_key']));
        }
        $msg = 'WhatsApp Cloud API settings saved successfully!';
    } elseif (($_POST['action'] ?? '') === 'send_test') {
        $testPhone = trim((string)($_POST['test_phone'] ?? ''));
        $testText  = trim((string)($_POST['test_message'] ?? ''));
        if (empty($testPhone) || empty($testText)) {
            $err = 'Please provide both destination phone and test message.';
        } else {
            $testResult = send_meta_whatsapp_message($testPhone, $testText);
            if (!empty($testResult['ok'])) {
                $msg = '✅ Test WhatsApp message delivered successfully to ' . htmlspecialchars($testPhone) . '!';
            } else {
                $err = '❌ Failed to send WhatsApp message. Meta API error: ' . htmlspecialchars(json_encode($testResult['response'] ?? $testResult['error'] ?? 'Unknown error'));
            }
        }
    }
}

// Current settings
$botEnabled   = (int)setting('wa_bot_enabled', 1);
$verifyToken  = setting('wa_verify_token', 'softnexa_wa_bot_2026');
$phoneId      = setting('wa_phone_id', '');
$wabaId       = setting('wa_waba_id', '');
$waToken      = setting('wa_token', '');
$geminiKey    = setting('gemini_api_key', '');

// Webhook full URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'https://';
$host = $_SERVER['HTTP_HOST'] ?? 'softnexasolution.com';
$webhookUrl = $protocol . $host . '/api/whatsapp_webhook.php';

// Stats
$totalIn = 0;
$totalOut = 0;
$recentChats = [];
try {
    $totalIn = (int)db()->query("SELECT COUNT(*) FROM wa_messages WHERE direction = 'in'")->fetchColumn();
    $totalOut = (int)db()->query("SELECT COUNT(*) FROM wa_messages WHERE direction = 'out'")->fetchColumn();
    $st = db()->query("SELECT * FROM wa_messages ORDER BY id DESC LIMIT 50");
    $recentChats = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

admin_head('whatsapp_bot.php', 'WhatsApp AI Bot · ' . ADMIN_TITLE);
?>

<div class="top">
  <div>
    <h1>WhatsApp AI Cloud API Bot</h1>
    <span class="muted">24/7 Autonomous AI Consultant for Meta WhatsApp Business</span>
  </div>
  <div style="display:flex;gap:10px;align-items:center">
    <span class="pill <?= $botEnabled ? 's-won' : 's-lost' ?>" style="font-weight:700;font-size:.85rem;padding:6px 14px">
      <?= $botEnabled ? '🟢 Bot Active & Listening' : '🔴 Bot Paused (Human Takeover)' ?>
    </span>
    <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener" class="btn" style="background:rgba(255,255,255,.07)">
      Meta App Dashboard ↗
    </a>
  </div>
</div>

<?php if ($msg): ?>
  <div class="card" style="border-color:var(--mint);background:rgba(22,199,132,.1);margin-bottom:14px;color:var(--mint);font-weight:600">
    <?= e($msg) ?>
  </div>
<?php endif; ?>

<?php if ($err): ?>
  <div class="card" style="border-color:var(--pink);background:rgba(255,77,141,.1);margin-bottom:14px;color:var(--pink);font-weight:600">
    <?= e($err) ?>
  </div>
<?php endif; ?>

<!-- Quick Metrics KPI Row -->
<div class="cards" style="margin-bottom:18px">
  <div class="card stat">
    <h3>Incoming WhatsApp Messages</h3>
    <div class="v" style="color:var(--cyan)"><?= nf($totalIn) ?></div>
    <div class="sub">Inquiries from clients</div>
  </div>
  <div class="card stat">
    <h3>AI Bot Automated Replies</h3>
    <div class="v" style="color:var(--mint)"><?= nf($totalOut) ?></div>
    <div class="sub">Delivered via Meta Cloud API</div>
  </div>
  <div class="card stat">
    <h3>Official Business Phone</h3>
    <div class="v" style="font-size:1.4rem;color:#fff">+92 315 2989922</div>
    <div class="sub">Direct SoftNexa line</div>
  </div>
</div>

<div class="grid2" style="grid-template-columns:1.2fr .8fr;gap:18px">
  
  <!-- Left Column: Settings & Configuration -->
  <div style="display:grid;gap:18px">
    
    <!-- Meta Webhook Configuration -->
    <div class="card">
      <h2 style="margin-bottom:6px">1. Meta Webhook Connection</h2>
      <p class="muted" style="font-size:.82rem;margin-top:0">
        Copy these two values and paste them into your <b>Meta Developer Portal &rarr; WhatsApp &rarr; Configuration &rarr; Webhook</b>.
      </p>

      <div style="display:grid;gap:12px;margin-top:14px">
        <div>
          <label style="font-weight:600">Callback URL (Webhook Endpoint)</label>
          <div style="display:flex;gap:8px">
            <input type="text" id="wh-url" value="<?= e($webhookUrl) ?>" readonly style="background:#07061A;color:#1FD3F0;font-family:monospace;font-weight:600">
            <button type="button" class="btn" onclick="copyField('wh-url', this)" style="white-space:nowrap">Copy URL</button>
          </div>
        </div>

        <div>
          <label style="font-weight:600">Verify Token</label>
          <div style="display:flex;gap:8px">
            <input type="text" id="wh-token" value="<?= e($verifyToken) ?>" readonly style="background:#07061A;color:var(--mint);font-family:monospace;font-weight:600">
            <button type="button" class="btn" onclick="copyField('wh-token', this)" style="white-space:nowrap">Copy Token</button>
          </div>
        </div>
      </div>
    </div>

    <!-- API Credentials Form -->
    <form class="card" method="post" style="display:grid;gap:14px">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="action" value="save_settings">

      <h2 style="margin-bottom:4px">2. Meta Cloud API Credentials</h2>
      <span class="muted" style="font-size:.82rem">Required for the bot to send automated replies back to clients via WhatsApp Cloud API.</span>

      <label style="display:flex;gap:10px;align-items:center;background:rgba(255,255,255,.04);padding:10px 14px;border-radius:10px;border:1px solid var(--line);cursor:pointer">
        <input type="checkbox" name="wa_bot_enabled" value="1" style="width:auto" <?= $botEnabled ? 'checked' : '' ?>>
        <span style="font-weight:600;color:var(--ink)">Enable Autonomous AI WhatsApp Bot (Uncheck to pause and handle manually)</span>
      </label>

      <div class="grid3" style="grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label>WhatsApp Phone Number ID</label>
          <input type="text" name="wa_phone_id" value="<?= e($phoneId) ?>" placeholder="e.g. 109384729104859" style="font-family:monospace">
          <small class="muted" style="font-size:.72rem">Found in Meta &rarr; WhatsApp &rarr; API Setup</small>
        </div>
        <div>
          <label>WhatsApp Business Account ID (WABA)</label>
          <input type="text" name="wa_waba_id" value="<?= e($wabaId) ?>" placeholder="e.g. 103948572910482" style="font-family:monospace">
          <small class="muted" style="font-size:.72rem">From your Meta Business Manager</small>
        </div>
      </div>

      <div>
        <label>Meta Permanent System User Access Token</label>
        <textarea name="wa_token" rows="3" placeholder="EAABw..." style="font-family:monospace;font-size:.82rem"><?= e($waToken) ?></textarea>
        <small class="muted" style="font-size:.72rem">Generated from Business Settings &rarr; System Users with <code>whatsapp_business_messaging</code> permission.</small>
      </div>

      <div>
        <label>Google Gemini API Key (For Smart AI Reasoning)</label>
        <input type="password" name="gemini_api_key" value="<?= e($geminiKey) ?>" placeholder="AIzaSy..." style="font-family:monospace">
        <small class="muted" style="font-size:.72rem">Bot will automatically fallback to high-conversion rules if empty.</small>
      </div>

      <div>
        <label>Custom Webhook Verify Token (Optional)</label>
        <input type="text" name="wa_verify_token" value="<?= e($verifyToken) ?>" style="font-family:monospace">
      </div>

      <div>
        <button class="btn p" style="padding:10px 24px;font-weight:700">💾 Save WhatsApp Settings</button>
      </div>
    </form>

  </div>

  <!-- Right Column: Live Test Sender & Quick Walkthrough -->
  <div style="display:grid;gap:18px">

    <!-- 1-Click WhatsApp Live Test Tool -->
    <form class="card" method="post" style="display:grid;gap:12px;border:1px solid rgba(37,211,102,.35)">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="action" value="send_test">

      <h2 style="margin-bottom:2px;display:flex;align-items:center;gap:8px">
        <span>🚀</span> <span>Live WhatsApp Message Test</span>
      </h2>
      <p class="muted" style="font-size:.82rem;margin-top:0">
        Send a real-time WhatsApp message to verify your Meta Access Token and Phone Number ID.
      </p>

      <div>
        <label>Recipient Phone Number (with Country Code)</label>
        <input type="text" name="test_phone" value="923152989922" placeholder="e.g. 923152989922" style="font-weight:600;color:var(--mint)">
      </div>

      <div>
        <label>Message Text</label>
        <textarea name="test_message" rows="3" style="font-size:.85rem">Assalam o Alaikum! This is a live automated test message from SoftNexa AI Cloud API Bot. ⚡</textarea>
      </div>

      <div>
        <button class="btn" style="background:#25D366;color:#fff;font-weight:700;width:100%;padding:10px 16px;border:0">
          💬 Send Test Message Now
        </button>
      </div>

      <?php if ($testResult): ?>
        <div style="background:rgba(0,0,0,.3);border-radius:8px;padding:10px;font-size:.75rem;font-family:monospace;max-height:160px;overflow-y:auto">
          <?= e(json_encode($testResult, JSON_PRETTY_PRINT)) ?>
        </div>
      <?php endif; ?>
    </form>

    <!-- Meta Cloud API Setup Walkthrough -->
    <div class="card">
      <h2 style="margin-bottom:10px">4-Step Setup Guide (Roman Urdu)</h2>
      
      <div class="list" style="gap:14px">
        <div style="display:flex;gap:10px;align-items:flex-start">
          <span style="background:var(--iris);color:#fff;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:.75rem;flex:none">1</span>
          <div style="font-size:.82rem;line-height:1.4">
            <b>Meta Developer Account:</b> <a href="https://developers.facebook.com" target="_blank" rel="noopener" style="color:var(--cyan)">developers.facebook.com</a> par ja kar <b>"Create App"</b> karein aur Type <b>"Business"</b> select karein.
          </div>
        </div>

        <div style="display:flex;gap:10px;align-items:flex-start">
          <span style="background:var(--iris);color:#fff;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:.75rem;flex:none">2</span>
          <div style="font-size:.82rem;line-height:1.4">
            <b>WhatsApp Product Add Karein:</b> App dashboard mein WhatsApp add karein. <b>"API Setup"</b> page par aapko <b>Phone Number ID</b> mil jaye gi (yahan paste karein).
          </div>
        </div>

        <div style="display:flex;gap:10px;align-items:flex-start">
          <span style="background:var(--iris);color:#fff;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:.75rem;flex:none">3</span>
          <div style="font-size:.82rem;line-height:1.4">
            <b>Webhook Subscribe:</b> WhatsApp &rarr; <b>Configuration</b> mein ja kar hamara <b>Callback URL</b> aur <b>Verify Token</b> paste karein aur <code>messages</code> field ko subscribe tick kar dein.
          </div>
        </div>

        <div style="display:flex;gap:10px;align-items:flex-start">
          <span style="background:var(--iris);color:#fff;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:.75rem;flex:none">4</span>
          <div style="font-size:.82rem;line-height:1.4">
            <b>Permanent Token:</b> Meta Business Settings mein <b>System Users</b> create karein aur <code>whatsapp_business_messaging</code> permission ke sath permanent token generate kar ke yahan save kar dein!
          </div>
        </div>
      </div>
    </div>

  </div>

</div>

<!-- Live WhatsApp Conversation Transcripts -->
<div class="card" style="margin-top:20px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0 0 4px">Recent WhatsApp Conversations</h2>
      <span class="muted" style="font-size:.82rem">Real-time log of incoming queries and AI bot replies</span>
    </div>
    <button onclick="location.reload()" class="btn" style="padding:6px 14px;font-size:.82rem">🔄 Refresh Log</button>
  </div>

  <?php if (empty($recentChats)): ?>
    <div class="empty">
      No WhatsApp messages received yet. Once clients message your WhatsApp number, live chat logs will appear here!
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <thead>
          <tr>
            <th>Time</th>
            <th>Client</th>
            <th>Phone</th>
            <th>Type</th>
            <th>Message</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentChats as $c): ?>
            <tr>
              <td style="white-space:nowrap;font-size:.78rem;color:var(--mut)">
                <?= e(date('M j, g:i a', strtotime($c['created_at']))) ?>
              </td>
              <td><b><?= e($c['name'] ?: 'WhatsApp Client') ?></b></td>
              <td>
                <a href="https://wa.me/<?= e($c['phone']) ?>" target="_blank" rel="noopener" style="color:var(--cyan);text-decoration:none;font-family:monospace;font-weight:600">
                  +<?= e($c['phone']) ?>
                </a>
              </td>
              <td>
                <span class="pill <?= $c['direction'] === 'in' ? 's-contacted' : 's-won' ?>">
                  <?= $c['direction'] === 'in' ? '📥 Incoming' : '📤 AI Bot' ?>
                </span>
              </td>
              <td style="max-width:440px;line-height:1.4;font-size:.85rem">
                <?= nl2br(e($c['message'])) ?>
              </td>
              <td style="white-space:nowrap">
                <a href="https://wa.me/<?= e($c['phone']) ?>" target="_blank" rel="noopener" class="btn" style="padding:4px 10px;font-size:.76rem;background:#25D366;color:#fff;border:0" title="Take over chat on WhatsApp Web">
                  💬 Chat Live
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
function copyField(id, btn) {
  var el = document.getElementById(id);
  if (!el) return;
  el.select();
  navigator.clipboard.writeText(el.value).then(function() {
    var orig = btn.textContent;
    btn.textContent = 'Copied! ✓';
    btn.style.color = '#10B981';
    setTimeout(function() {
      btn.textContent = orig;
      btn.style.color = '';
    }, 2000);
  });
}
</script>

<?php admin_foot(); ?>
