<?php
/* ============================================================
   SOFTNEXA TAILORED SHORT PROPOSAL DISPATCHER API
   Sends customized short proposal via Email and prepares WhatsApp message
   ============================================================ */
require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

$d = json_input();
if (empty($d) && !empty($_GET)) $d = $_GET;

$leadId   = (int)($d['lead_id'] ?? 0);
$name     = trim((string)($d['name'] ?? ''));
$company  = trim((string)($d['company'] ?? ''));
$email    = trim((string)($d['email'] ?? ''));
$phone    = trim((string)($d['phone'] ?? ''));
$service  = trim((string)($d['service'] ?? ''));
$lang     = trim((string)($d['lang'] ?? ''));

// If lead_id provided, fetch details from DB
if ($leadId > 0 && ($name === '' || $email === '')) {
    try {
        $st = db()->prepare("SELECT * FROM leads WHERE id = ? LIMIT 1");
        $st->execute([$leadId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($name === '') $name = $row['name'] ?: $row['company'];
            if ($company === '') $company = $row['company'];
            if ($email === '') $email = $row['email'];
            if ($phone === '') $phone = $row['phone'];
            if ($service === '') $service = $row['service'] ?: $row['industry'];
            if ($lang === '') {
                $isPk = (stripos($row['region'] ?? '', 'pakistan') !== false || strpos(preg_replace('/\D/','',$phone), '92') === 0);
                $lang = $isPk ? 'ro' : 'en';
            }
        }
    } catch (Exception $e) {}
}

if ($name === '') $name = 'Prospective Partner';
if ($service === '') $service = 'Custom Enterprise Software & AI';

// Auto-detect language if not specified: International / Middle East defaults to English!
if ($lang === '') {
    $cleanP = preg_replace('/[^0-9]/', '', $phone);
    if (strpos($cleanP, '971') === 0 || strpos($cleanP, '966') === 0 || strpos($cleanP, '1') === 0 || strpos($cleanP, '44') === 0) {
        $lang = 'en';
    } elseif (strpos($cleanP, '92') === 0 || strpos($cleanP, '03') === 0) {
        $lang = 'ro';
    } else {
        $lang = 'en';
    }
}

// Clean phone for WhatsApp URL
$waNum = preg_replace('/[^0-9]/', '', $phone);
if (strpos($waNum, '03') === 0) $waNum = '92' . substr($waNum, 1);

/* ------------------------------------------------------------
   1. SELECT TAILORED MODULES BASED ON SERVICE / INDUSTRY
   ------------------------------------------------------------ */
$servLower = mb_strtolower($service);

if (stripos($servLower, 'real estate') !== false || stripos($servLower, 'property') !== false) {
    $servTitle = "EstateFlow CRM & 24/7 WhatsApp Property Automation";
    $modules = [
        "Lead Vault CRM: Instant capture & locking of leads from Meta Ads, Property Finder & Bayut.",
        "24/7 AI WhatsApp Bot: Automatically delivers off-plan PDF brochures & floorplans in 30 seconds.",
        "Agent Pipeline & Commission Ledger: Real-time deal stages and agent performance tracking.",
        "Automated Viewing Scheduler: Direct calendar sync with Google Meet and WhatsApp reminders."
    ];
} elseif (stripos($servLower, 'logistics') !== false || stripos($servLower, 'fleet') !== false || stripos($servLower, 'cargo') !== false) {
    $servTitle = "FleetTrack ERP & Driver WhatsApp Dispatch System";
    $modules = [
        "Live Fleet & Driver GPS Tracking: Real-time route optimization and vehicle manifest status.",
        "Digital Proof of Delivery (POD): Signature & photo capture via driver mobile app.",
        "Automated WhatsApp Tracking: Instant shipment milestone alerts sent directly to clients.",
        "B2B Invoicing & Digital Challans: Eliminates lost paperwork and accelerates payment cycles."
    ];
} elseif (stripos($servLower, 'commerce') !== false || stripos($servLower, 'store') !== false || stripos($servLower, 'retail') !== false) {
    $servTitle = "High-Scale Bilingual E-Commerce Platform (EN/AR)";
    $modules = [
        "Ultra-Fast Bilingual Store (English & Arabic) engineered for 10x traffic spikes.",
        "GCC Payment Gateways: Tabby, Tamara (Buy Now Pay Later), Mada, Credit Cards & COD.",
        "Automated WhatsApp Cart Recovery: Recovers 25-35% of abandoned checkout customers.",
        "Multi-Warehouse Inventory & Courier Dispatch: Instant courier API sync."
    ];
} elseif (stripos($servLower, 'clinic') !== false || stripos($servLower, 'dental') !== false || stripos($servLower, 'medical') !== false || stripos($servLower, 'derma') !== false) {
    $servTitle = "PatientFlow EHR & Multilingual WhatsApp Appointment System";
    $modules = [
        "Bilingual WhatsApp Appointment Scheduler: Cuts patient no-shows by up to 65%.",
        "Digital Patient EHR: Secure medical records, prescription history, and treatment charts.",
        "Multi-Counter POS & Billing: Seamless counter billing and daily cash reconciliation.",
        "Automated WhatsApp Follow-ups: Sends lab results and consultation recall reminders."
    ];
} elseif (stripos($servLower, 'contracting') !== false || stripos($servLower, 'construction') !== false || stripos($servLower, 'fitout') !== false) {
    $servTitle = "BuildPro Project Costing & BOQ Estimation ERP";
    $modules = [
        "BOQ & Project Costing Module: Eliminates material leakage and tracks site expenditures.",
        "Milestone Invoicing & Approvals: Generates client progress billings linked to site photos.",
        "Labor Attendance & Subcontractor Tracking: Daily site logs and wage reconciliations.",
        "Executive Financial Dashboards: Live project profitability and cash flow forecasting."
    ];
} elseif (stripos($servLower, 'marketing') !== false || stripos($servLower, 'ads') !== false || stripos($servLower, 'social media') !== false) {
    $servTitle = "360° ROI-Driven Digital & Performance Marketing";
    $modules = [
        "Meta & Google Ads Funnels: High-converting lead generation & targeted CAC optimization.",
        "Viral Video Production: High-retention TikTok, Reels & Shorts production and editing.",
        "Monthly Brand Management: Complete aesthetic graphics, copy, and posting calendars.",
        "Transparent Reporting Dashboard: Real-time conversion tracking and weekly analytics."
    ];
} else {
    $servTitle = "Custom Business Software & Cloud Architecture";
    $modules = [
        "Bespoke System Architecture: 100% custom-built around your exact operational workflows.",
        "Scalable Cloud Stack: High performance (React / Next.js / Node.js / PostgreSQL / AWS).",
        "Role-Based Access & Audit Logs: Complete staff permissions and operational visibility.",
        "API Integration & Mobile Sync: Seamless connection to existing spreadsheets and tools."
    ];
}

/* ------------------------------------------------------------
   2. GENERATE WHATSAPP SHORT PROPOSAL TEXT
   ------------------------------------------------------------ */
if ($lang === 'en') {
    $waText = "📄 *SOFTNEXA PROJECT PROPOSAL & SCOPE BLUEPRINT*\n"
        . "━━━━━━━━━━━━━━━━━━━━━\n"
        . "👤 *Prepared for:* " . $name . ($company ? " (" . $company . ")" : "") . "\n"
        . "🎯 *Proposed System:* " . $servTitle . "\n\n"
        . "✨ *CORE DELIVERABLES & MODULES:*\n";
    foreach ($modules as $m) {
        $waText .= "• " . $m . "\n";
    }
    $waText .= "\n⚡ *DELIVERY & SOFTNEXA GUARANTEES:*\n"
        . "• *Timeline:* 2 to 4 weeks (First staging prototype in 14 days)\n"
        . "• *Pricing Model:* 100% Fixed-Price Guarantee (Zero hourly surprise bills)\n"
        . "• *Ownership:* 100% Full Source Code & Database Ownership\n"
        . "• *Support:* 30 Days Post-Launch Technical Support included\n\n"
        . "📅 *NEXT STEP (15-MIN GOOGLE MEET CALL):*\n"
        . "Pick a slot that suits your schedule to review live interactive architecture screens:\n"
        . "👉 https://softnexasolution.com/#/schedule\n\n"
        . "Or reply right here to discuss custom features!\n"
        . "━━━━━━━━━━━━━━━━━━━━━\n"
        . "*SoftNexa Solutions* | Shahrah-e-Faisal, Karachi\n"
        . "🌐 https://softnexasolution.com";
} else {
    $waText = "📄 *SOFTNEXA PROJECT PROPOSAL & SCOPE BLUEPRINT*\n"
        . "━━━━━━━━━━━━━━━━━━━━━\n"
        . "👤 *Prepared for:* " . $name . ($company ? " (" . $company . ")" : "") . "\n"
        . "🎯 *Proposed System:* " . $servTitle . "\n\n"
        . "✨ *CORE DELIVERABLES & MODULES:*\n";
    foreach ($modules as $m) {
        $waText .= "• " . $m . "\n";
    }
    $waText .= "\n⚡ *DELIVERY & SOFTNEXA GUARANTEES:*\n"
        . "• *Timeline:* 2 se 4 weeks (14 days mein first working staging prototype)\n"
        . "• *Pricing:* 100% Fixed-Price Guarantee (Zero hourly surprise bills)\n"
        . "• *Code Ownership:* 100% Source Code & Database aapki malkiyat hoga\n"
        . "• *Support:* 30 Days Post-Launch Technical Support shamil hai\n\n"
        . "📅 *NEXT STEP (15-MIN GOOGLE MEET CALL):*\n"
        . "Apni sahulat ke mutabiq 15-minute ki Google Meet call schedule karein taake live demos review kar sakein:\n"
        . "👉 https://softnexasolution.com/#/schedule\n\n"
        . "Ya aap yahan WhatsApp par bhi reply kar sakte hain!\n"
        . "━━━━━━━━━━━━━━━━━━━━━\n"
        . "*SoftNexa Solutions* | Shahrah-e-Faisal, Karachi\n"
        . "🌐 https://softnexasolution.com";
}

$waUrl = 'https://wa.me/' . $waNum . '?text=' . rawurlencode($waText);

/* ------------------------------------------------------------
   3. GENERATE & SEND EXECUTIVE HTML EMAIL PROPOSAL
   ------------------------------------------------------------ */
$emailSent = false;
$emailSubj = "Project Scope Proposal: {$servTitle} — SoftNexa for " . ($company ?: $name);

$moduleHtml = '';
foreach ($modules as $m) {
    $parts = explode(':', $m, 2);
    $bTitle = htmlspecialchars($parts[0] ?? '');
    $bDesc  = htmlspecialchars($parts[1] ?? '');
    $moduleHtml .= '<tr><td style="padding:10px 14px;border-bottom:1px solid #1E1D36;vertical-align:top;width:24px;color:#1FD3F0;font-size:16px;">✓</td><td style="padding:10px 14px;border-bottom:1px solid #1E1D36;font-size:14px;line-height:1.6;color:#F0EEFF;"><strong style="color:#FFFFFF;">' . $bTitle . ':</strong> ' . $bDesc . '</td></tr>';
}

$emailHtml = '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>' . htmlspecialchars($emailSubj) . '</title>
</head>
<body style="margin:0;padding:0;background-color:#070617;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#F0EEFF;-webkit-font-smoothing:antialiased;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#070617;padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;background:#100F26;border:1px solid #23223D;border-radius:14px;overflow:hidden;box-shadow:0 14px 45px rgba(0,0,0,0.5);">
          
          <!-- Top Accent -->
          <tr><td height="4" style="background:linear-gradient(90deg,#6E4BFF 0%,#1FD3F0 50%,#6E4BFF 100%);"></td></tr>

          <!-- Header -->
          <tr>
            <td style="padding:24px 28px 18px 28px;border-bottom:1px solid #1C1B33;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td>
                    <span style="font-size:24px;font-weight:800;color:#FFFFFF;letter-spacing:-0.5px;">Soft<span style="color:#1FD3F0;">Nexa</span></span>
                    <span style="display:inline-block;margin-left:8px;font-size:11px;color:#8B8AA5;font-weight:500;">Solutions Engineering</span>
                  </td>
                  <td align="right">
                    <span style="display:inline-block;padding:5px 12px;background:rgba(18,200,240,0.15);color:#1FD3F0;border:1px solid rgba(18,200,240,0.3);font-size:11px;font-weight:700;border-radius:20px;text-transform:uppercase;letter-spacing:0.6px;">
                      Executive Scope Proposal
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Intro -->
          <tr>
            <td style="padding:26px 28px 16px 28px;">
              <h1 style="margin:0 0 10px 0;font-size:22px;font-weight:700;color:#FFFFFF;line-height:1.3;">
                Tailored Proposal for ' . htmlspecialchars($name) . ($company ? ' (' . htmlspecialchars($company) . ')' : '') . '
              </h1>
              <p style="margin:0 0 12px 0;font-size:14px;line-height:1.65;color:#D4D1F0;">
                Thank you for connecting with <strong>SoftNexa</strong>. Based on your business requirements, our software architects have drafted this executive scope proposal for:
              </p>
              <div style="background:rgba(110,75,255,0.14);border-left:4px solid #6E4BFF;padding:12px 16px;border-radius:8px;font-size:15px;font-weight:700;color:#FFFFFF;margin-bottom:18px;">
                ' . htmlspecialchars($servTitle) . '
              </div>
            </td>
          </tr>

          <!-- Core Modules -->
          <tr>
            <td style="padding:0 28px 20px 28px;">
              <div style="font-size:12px;font-weight:700;color:#1FD3F0;text-transform:uppercase;letter-spacing:0.7px;margin-bottom:8px;">
                Proposed System Modules & Architecture:
              </div>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#0C0B1E;border-radius:8px;overflow:hidden;border:1px solid #232238;">
                ' . $moduleHtml . '
              </table>
            </td>
          </tr>

          <!-- SoftNexa Guarantees Grid -->
          <tr>
            <td style="padding:0 28px 24px 28px;">
              <div style="font-size:12px;font-weight:700;color:#A78BFA;text-transform:uppercase;letter-spacing:0.7px;margin-bottom:8px;">
                SoftNexa Engineering Guarantees:
              </div>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0C0B1E;border:1px solid #232238;border-radius:8px;padding:14px;">
                <tr>
                  <td width="50%" style="padding:8px;font-size:13px;color:#D4D1F0;vertical-align:top;">
                    <strong style="color:#10B981;">⚡ 100% Fixed-Price Guarantee:</strong><br>
                    Zero unexpected hourly costs. Milestone-based delivery.
                  </td>
                  <td width="50%" style="padding:8px;font-size:13px;color:#D4D1F0;vertical-align:top;">
                    <strong style="color:#1FD3F0;">🚀 Fast Staging Preview:</strong><br>
                    Working interactive prototype on live staging in 14 days.
                  </td>
                </tr>
                <tr>
                  <td width="50%" style="padding:8px;font-size:13px;color:#D4D1F0;vertical-align:top;">
                    <strong style="color:#F59E0B;">🔒 100% Code Ownership:</strong><br>
                    Full source code, database & IP handed over. No hostage fees.
                  </td>
                  <td width="50%" style="padding:8px;font-size:13px;color:#D4D1F0;vertical-align:top;">
                    <strong style="color:#A78BFA;">🛡️ 30-Day Tech Support:</strong><br>
                    Post-launch bug warranty & dedicated technical monitoring.
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- CTAs -->
          <tr>
            <td style="padding:0 28px 28px 28px;text-align:center;">
              <div style="font-size:13px;color:#8B8AA5;margin-bottom:14px;">
                Next Step: Schedule a 15-minute Google Meet call to review interactive prototype screens:
              </div>
              <a href="https://softnexasolution.com/#/schedule" target="_blank" style="display:inline-block;background:linear-gradient(135deg,#6E4BFF 0%,#1FD3F0 100%);color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14px;padding:12px 26px;border-radius:8px;margin-right:8px;box-shadow:0 4px 16px rgba(110,75,255,0.4);">
                📅 Schedule Google Meet Call &rarr;
              </a>
              <a href="https://wa.me/923152989922?text=Hello%20SoftNexa,%20I%20reviewed%20your%20proposal%20for%20' . rawurlencode($servTitle) . '" target="_blank" style="display:inline-block;background:#25D366;color:#FFFFFF;text-decoration:none;font-weight:700;font-size:13px;padding:12px 22px;border-radius:8px;box-shadow:0 3px 12px rgba(37,211,102,0.35);">
                💬 Discuss on WhatsApp
              </a>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding:20px 28px;background:#090818;border-top:1px solid #1C1B33;text-align:center;">
              <div style="font-size:12px;color:#8B8AA5;margin-bottom:4px;">
                SoftNexa Solutions • Shahrah-e-Faisal, Karachi, Pakistan • WhatsApp: +92 315 2989922
              </div>
              <div style="font-size:11px;color:#5A5872;">
                Official Website: <a href="https://softnexasolution.com" style="color:#1FD3F0;text-decoration:none;">softnexasolution.com</a>
              </div>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $emailSent = send_html_email($email, $emailSubj, $emailHtml, 'info@softnexasolution.com');
}

// 4. Update CRM Lead Status to 'proposal'
if ($leadId > 0) {
    try {
        db()->prepare("UPDATE leads SET status = 'proposal' WHERE id = ?")->execute([$leadId]);
        db()->prepare("INSERT INTO lead_notes (lead_id, note) VALUES (?, ?)")
            ->execute([$leadId, "Tailored proposal dispatched for {$servTitle} (Email: " . ($emailSent ? 'Sent' : 'Skipped/Invalid') . ", WhatsApp Prepared)"]);
    } catch (Exception $e) {}
}

// 5. Attempt direct Meta Cloud API WhatsApp dispatch if clean number
$metaWaRes = null;
if (!empty($waNum)) {
    // If you have approved WhatsApp templates or within 24h window
    $metaWaRes = send_meta_whatsapp_message($waNum, $waText);
}

// Output JSON
json_out([
    'ok'          => true,
    'serv_title'  => $servTitle,
    'email_sent'  => $emailSent,
    'email'       => $email,
    'wa_num'      => $waNum,
    'wa_text'     => $waText,
    'wa_url'      => $waUrl,
    'meta_wa'     => ($metaWaRes['ok'] ?? false),
    'modules'     => $modules,
    'message'     => 'Tailored short proposal prepared and dispatched!'
]);
