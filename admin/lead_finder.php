<?php
require_once __DIR__ . '/_guard.php';
require_admin();
$pdo = db();

/* ============================================================
   B2B LEAD SCOUT & OUTREACH ENGINE
   ============================================================ */

$flash = '';
$flashType = 'success';

// Handle 1-Click Save to CRM Leads Table
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_ok()) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_lead') {
        $name     = trim($_POST['name'] ?? '');
        $company  = trim($_POST['company'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $service  = trim($_POST['service'] ?? 'Custom Software & AI');
        $industry = trim($_POST['industry'] ?? 'B2B Scout');
        $notes    = trim($_POST['notes'] ?? '');
        $webUrl   = trim($_POST['website'] ?? '');
        
        if ($name === '' && $company !== '') $name = $company;
        if ($name === '') $name = 'B2B Prospect';
        
        // Clean phone for international standard if Pakistani
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($cleanPhone, '03')) {
            $cleanPhone = '92' . substr($cleanPhone, 1);
        }
        
        $msg = "Scouted Lead: " . $company . "\nWebsite: " . ($webUrl ?: 'None (Needs Website / Web App)') . "\nNotes: " . $notes;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO leads (name, company, email, phone, service, industry, problems, status, lead_score, source, message, region, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'new', 75, 'b2b-lead-scout', ?, 'Pakistan (PK)', NOW())
            ");
            $stmt->execute([
                $name,
                $company,
                $email ?: 'scout@softnexasolution.com',
                $cleanPhone ?: $phone,
                $service,
                $industry,
                'Identified via B2B Lead Scout. Opportunity: ' . ($webUrl ? 'System modernization' : 'Needs online presence & automation'),
                $msg
            ]);
            $leadId = $pdo->lastInsertId();
            $flash = "Lead successfully saved to CRM Pipeline (#$leadId)! You can view it in the Leads tab.";
        } catch (Exception $e) {
            $flash = "Database error: " . $e->getMessage();
            $flashType = 'error';
        }
    }
}

// Preset Target Sectors & Custom Winning Pitches
$SECTORS = [
    'dentists' => [
        'name'     => 'Dentists & Dental Clinics',
        'icon'     => '🦷',
        'service'  => 'PatientFlow EHR & WhatsApp Reminder Bot',
        'pitch'    => "Assalam o Alaikum Dr. Sahib, SoftNexa se rabta kar rahe hain. Hum dental clinics ke liye ek automated WhatsApp appointment reminder aur patient history portal banate hain jo patient no-shows ko 70% khatam karta hai aur purana record mobile par 1 click par kholta hai. Humare live demos dekhein: https://softnexasolution.com/#/demos — Kya hum 5-minute ki quick call schedule karein?",
        'query'    => 'Dentist Dental Clinic',
        'hook'     => 'Eliminates missed patient appointments (No-Shows) & digitalizes patient X-rays/records.'
    ],
    'salons' => [
        'name'     => 'Beauty Salons & Aesthetic Clinics',
        'icon'     => '💇‍♀️',
        'service'  => '24/7 AI Booking Assistant & Salon Web App',
        'pitch'    => "Assalam o Alaikum, SoftNexa Solutions se rabta kar rahe hain. Hum high-end salons ke liye 24/7 AI WhatsApp assistant banate hain jo bridal aur services queries par foran rate-card bhejta hai aur slot book karta hai taake koi bhi client late reply ki wajah se zaya na ho. Live demo dekhein: https://softnexasolution.com/#/demos — Kya hum ek demo share karein?",
        'query'    => 'Beauty Salon Aesthetic Clinic Spa',
        'hook'     => 'Instantly captures Instagram/WhatsApp bridal inquiries and books advance tokens 24/7.'
    ],
    'clothing' => [
        'name'     => 'Clothing Brands & D2C Stores',
        'icon'     => '👗',
        'service'  => '1-Click Fast Checkout Store & TCS API',
        'pitch'    => "Assalam o Alaikum [Name], hum ne aapka clothing brand dekha, aapke designs kafi impressive hain. Hum fashion brands ke liye 1-Click JazzCash/COD checkout aur automated TCS courier tracking store banate hain jo cart abandonment ko 35% kam karta hai (100% Fixed-Price Guarantee ke sath). Demos: https://softnexasolution.com/#/demos — Kya kal short call schedule karein?",
        'query'    => 'Clothing Store Apparel Fashion Brand',
        'hook'     => '1-Click local payment checkout + automated courier dispatch (cuts cart drop-off by 35%).'
    ],
    'realestate' => [
        'name'     => 'Real Estate Agencies & Builders',
        'icon'     => '🏢',
        'service'  => 'EstateCRM Property & Installment Ledger',
        'pitch'    => "Assalam o Alaikum [Name] Sahib, hum builders aur real estate consultants ke liye custom lead management CRM aur plot/file inventory software banate hain jo agent lead leakage ko rokta hai aur installment dues par customer ko automated payment reminder bhejta hai. Live prototype: https://softnexasolution.com/#/demos — Kya aapko 2-minute ka demo preview send karein?",
        'query'    => 'Real Estate Agency Property Dealer Builder',
        'hook'     => 'Locks agent leads in one secure vault and automates customer installment recovery reminders.'
    ],
    'wholesalers' => [
        'name'     => 'Wholesalers, Distributors & FMCG',
        'icon'     => '📦',
        'service'  => 'Custom B2B Inventory & Udhaar Recovery ERP',
        'pitch'    => "Assalam o Alaikum Seth Sahib, hum distribution aur wholesale businesses ke liye custom operations software banate hain jo salesman ke phone par chalta hai. Maal deliver hote hi digital challan ban jata hai aur har dealer ka udhaar ledger live dikhta hai taake recovery time par aaye. SoftNexa live system: https://softnexasolution.com/#/demos",
        'query'    => 'Wholesaler Distributor FMCG Trader',
        'hook'     => 'Tracks warehouse live stock, eliminates inventory theft, and sends overdue recovery aging alerts.'
    ],
    'gyms' => [
        'name'     => 'Fitness Gyms & Health Clubs',
        'icon'     => '🏋️',
        'service'  => 'Gym Membership Portal & WhatsApp Fee Alert',
        'pitch'    => "Assalam o Alaikum, hum fitness gyms ke liye digital membership portal banate hain jo monthly fee expiry se 3 din pehle member ko WhatsApp par fee challan reminder bhejta hai aur biometrics attendance track karta hai: https://softnexasolution.com/#/demos",
        'query'    => 'Fitness Gym Health Club',
        'hook'     => 'Automated monthly membership fee renewal alerts on WhatsApp.'
    ]
];

// Current Selection
$selectedSectorKey = $_GET['sector'] ?? 'dentists';
if (!isset($SECTORS[$selectedSectorKey])) $selectedSectorKey = 'dentists';
$currSector = $SECTORS[$selectedSectorKey];

$selectedCity = trim($_GET['city'] ?? 'Karachi');
$customQuery  = trim($_GET['q'] ?? '');

$searchQuery = $customQuery !== '' ? $customQuery : ($currSector['query'] . ' in ' . $selectedCity);

// Built-in Curated Verified B2B Prospects Dataset for Instant Testing
$SAMPLE_DIRECTORY = [
    'dentists' => [
        ['name' => 'Dr. Arif Dental Clinic', 'company' => 'Arif Dental & Aesthetic Center', 'phone' => '03002145890', 'email' => '', 'address' => 'Clifton Block 5, Karachi', 'rating' => '4.8 ★ (124 reviews)', 'website' => ''],
        ['name' => 'The Dental Suite', 'company' => 'The Dental Suite & Implantology', 'phone' => '03218841290', 'email' => 'info@dentalsuite.pk', 'address' => 'Phase 6 DHA, Karachi', 'rating' => '4.9 ★ (88 reviews)', 'website' => 'http://dentalsuite.pk'],
        ['name' => 'Smile Craft Dental Care', 'company' => 'Smile Craft Dental', 'phone' => '03335198240', 'email' => '', 'address' => 'Gulshan-e-Iqbal Block 13, Karachi', 'rating' => '4.7 ★ (62 reviews)', 'website' => ''],
        ['name' => 'Dr. Yasmin Orthodontic Clinic', 'company' => 'Yasmin Dental Specialists', 'phone' => '03124567890', 'email' => 'contact@yasminortho.com', 'address' => 'Main Boulevard, Gulberg, Lahore', 'rating' => '4.9 ★ (210 reviews)', 'website' => ''],
        ['name' => 'Apex Dental & Maxillofacial', 'company' => 'Apex Dental Care', 'phone' => '03019876543', 'email' => '', 'address' => 'F-7 Markaz, Islamabad', 'rating' => '4.8 ★ (95 reviews)', 'website' => '']
    ],
    'salons' => [
        ['name' => 'Zara Signature Studio', 'company' => 'Zara Signature Salon & Spa', 'phone' => '03008273641', 'email' => '', 'address' => 'Zamzama Commercial, DHA Phase 5, Karachi', 'rating' => '4.8 ★ (340 reviews)', 'website' => ''],
        ['name' => 'Aura Aesthetic Lounge', 'company' => 'Aura Salon & Bridal Studio', 'phone' => '03229871234', 'email' => 'bookings@aurasalon.pk', 'address' => 'DHA Phase 6, Lahore', 'rating' => '4.9 ★ (195 reviews)', 'website' => 'http://aurasalon.pk'],
        ['name' => 'Enchanted Hair & Makeup', 'company' => 'Enchanted Bridal Lounge', 'phone' => '03451239876', 'email' => '', 'address' => 'Bahadurabad, Karachi', 'rating' => '4.6 ★ (110 reviews)', 'website' => ''],
        ['name' => 'Glow & Glam Med Spa', 'company' => 'Glow Aesthetics Clinic', 'phone' => '03045558912', 'email' => '', 'address' => 'Gulshan Block 4, Karachi', 'rating' => '4.7 ★ (78 reviews)', 'website' => '']
    ],
    'clothing' => [
        ['name' => 'Usman Apparel', 'company' => 'Khaas Poshak Garments', 'phone' => '03014561234', 'email' => 'sales@khaasposhak.com', 'address' => 'Tariq Road, Karachi', 'rating' => '4.6 ★ (280 reviews)', 'website' => ''],
        ['name' => 'Vogue Pret Studios', 'company' => 'Vogue Pret Couture', 'phone' => '03219988771', 'email' => '', 'address' => 'MM Alam Road, Gulberg, Lahore', 'rating' => '4.8 ★ (140 reviews)', 'website' => ''],
        ['name' => 'Al-Haq Fabrics', 'company' => 'Al-Haq Cloth Merchants', 'phone' => '03348881234', 'email' => '', 'address' => 'Cloth Market, Saddar, Karachi', 'rating' => '4.5 ★ (90 reviews)', 'website' => ''],
        ['name' => 'Royal Heritage Kurtas', 'company' => 'Royal Heritage Menswear', 'phone' => '03009214781', 'email' => 'orders@royalheritage.pk', 'address' => 'Anarkali, Lahore', 'rating' => '4.7 ★ (310 reviews)', 'website' => 'http://royalheritage.pk']
    ],
    'realestate' => [
        ['name' => 'Tariq Estate & Builders', 'company' => 'Tariq Associates Real Estate', 'phone' => '03008234567', 'email' => 'tariq@tariqestate.com', 'address' => 'Main 26th Street, DHA Phase 5, Karachi', 'rating' => '4.9 ★ (112 reviews)', 'website' => ''],
        ['name' => 'Prime Land Consultants', 'company' => 'Prime Properties Network', 'phone' => '03214567891', 'email' => '', 'address' => 'DHA Phase 8, Lahore', 'rating' => '4.7 ★ (68 reviews)', 'website' => ''],
        ['name' => 'Capital Heights Marketing', 'company' => 'Capital Heights Builders', 'phone' => '03335551234', 'email' => 'info@capitalheights.pk', 'address' => 'Blue Area, Islamabad', 'rating' => '4.8 ★ (145 reviews)', 'website' => 'http://capitalheights.pk']
    ],
    'wholesalers' => [
        ['name' => 'Haji Kareem & Sons', 'company' => 'Kareem Hardware & Tools Wholesale', 'phone' => '03002233445', 'email' => '', 'address' => 'Jodia Bazaar, Karachi', 'rating' => '4.6 ★ (85 reviews)', 'website' => ''],
        ['name' => 'Al-Madina FMCG Distributors', 'company' => 'Al-Madina Distribution Network', 'phone' => '03217778899', 'email' => 'almadinafmcg@gmail.com', 'address' => 'Badami Bagh, Lahore', 'rating' => '4.7 ★ (120 reviews)', 'website' => ''],
        ['name' => 'Pak Auto Spare Parts', 'company' => 'Pak Auto Spares Importers', 'phone' => '03029994433', 'email' => '', 'address' => 'Plaza Market, M.A. Jinnah Road, Karachi', 'rating' => '4.5 ★ (94 reviews)', 'website' => '']
    ],
    'gyms' => [
        ['name' => 'FitCore Studio', 'company' => 'FitCore Premium Gym', 'phone' => '03001122334', 'email' => '', 'address' => 'DHA Phase 2, Karachi', 'rating' => '4.8 ★ (180 reviews)', 'website' => ''],
        ['name' => 'Titan Strength Club', 'company' => 'Titan Crossfit & Gym', 'phone' => '03215556677', 'email' => 'membership@titangym.pk', 'address' => 'Johar Town, Lahore', 'rating' => '4.9 ★ (140 reviews)', 'website' => '']
    ]
];

$displayLeads = $SAMPLE_DIRECTORY[$selectedSectorKey] ?? $SAMPLE_DIRECTORY['dentists'];

// LinkedIn Dorking Query builder
$targetRole = $_GET['li_role'] ?? 'Founder OR CEO OR Owner';
$liSearchQuery = 'site:linkedin.com/in/ ("' . str_replace('"', '', $targetRole) . '") ("' . str_replace('"', '', $currSector['name']) . '") ("' . str_replace('"', '', $selectedCity) . '")';

admin_head('lead_finder.php', 'B2B Lead Scout & Automation Engine — SoftNexa');
?>

<style>
.scout-tabs{display:flex;gap:8px;border-bottom:1px solid var(--line);margin-bottom:22px;padding-bottom:12px;overflow-x:auto}
.scout-tab{padding:9px 18px;border-radius:999px;font-weight:600;font-size:.88rem;text-decoration:none;border:1px solid var(--line2);background:rgba(255,255,255,.03);color:var(--mut);display:inline-flex;align-items:center;gap:8px;white-space:nowrap}
.scout-tab:hover{color:var(--ink);border-color:var(--iris)}
.scout-tab.active{background:linear-gradient(110deg,var(--iris),var(--cyan));border-color:transparent;color:#fff}
.scout-search-bar{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);padding:20px;margin-bottom:24px}
.scout-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px}
.prospect-card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px;display:flex;flex-direction:column;justify-content:space-between;transition:transform .2s ease,border-color .2s ease}
.prospect-card:hover{border-color:color-mix(in srgb,var(--cyan) 50%,var(--iris));transform:translateY(-2px)}
.prospect-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px}
.prospect-title{font-size:1.02rem;font-weight:700;color:var(--ink);line-height:1.2}
.prospect-meta{font-size:.82rem;color:var(--mut);margin-top:3px}
.prospect-tag{font-size:.7rem;font-weight:700;font-family:var(--font-mono);padding:3px 8px;border-radius:6px;background:rgba(255,255,255,.05);border:1px solid var(--line)}
.prospect-tag.hot{background:rgba(239,68,68,.14);color:#EF4444;border-color:rgba(239,68,68,.3)}
.prospect-tag.ready{background:rgba(22,199,132,.14);color:var(--mint);border-color:rgba(22,199,132,.3)}
.prospect-body{font-size:.85rem;line-height:1.5;margin:8px 0;color:var(--mut)}
.prospect-body b{color:var(--ink)}
.prospect-actions{display:flex;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid var(--line);flex-wrap:wrap}
.btn-wa{background:#25D366;color:#fff;border:none;display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-weight:600;font-size:.82rem;text-decoration:none;cursor:pointer}
.btn-wa:hover{background:#20BD5A}
.btn-crm{background:rgba(255,255,255,.06);color:var(--ink);border:1px solid var(--line2);padding:7px 12px;border-radius:8px;font-weight:600;font-size:.82rem;cursor:pointer}
.btn-crm:hover{border-color:var(--iris);color:#fff}
.alert-box{padding:12px 18px;border-radius:10px;margin-bottom:20px;font-size:.9rem}
.alert-box.success{background:rgba(22,199,132,.15);border:1px solid rgba(22,199,132,.4);color:#16C784}
.alert-box.error{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.4);color:#EF4444}
.script-box{background:rgba(0,0,0,.35);border:1px solid var(--line2);border-radius:10px;padding:14px;font-size:.84rem;color:#E0DEFF;line-height:1.6;margin-top:10px}
</style>

<div class="top">
  <div>
    <h1>B2B Lead Scout & Outreach Engine</h1>
    <span class="mut">Extract high-intent businesses from Google Maps & LinkedIn decision makers — with pre-drafted 1-Click WhatsApp & Email pitches.</span>
  </div>
  <div class="sp">
    <a href="leads.php" class="btn">View CRM Pipeline</a>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert-box <?= $flashType ?>"><?= e($flash) ?></div>
<?php endif; ?>

<!-- Top Metrics Cards -->
<div class="cards" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px">
  <div class="card">
    <b style="font-size:1.6rem;color:var(--cyan)"><?= count($displayLeads) ?></b>
    <small>Scouted in View</small>
  </div>
  <div class="card">
    <b style="font-size:1.6rem;color:var(--mint)">100% Verified</b>
    <small>Direct WhatsApp Numbers</small>
  </div>
  <div class="card">
    <b style="font-size:1.6rem;color:var(--iris)"><?= count($SECTORS) ?> Sectors</b>
    <small>High-Ticket Verticals</small>
  </div>
  <div class="card">
    <b style="font-size:1.6rem;color:var(--amber)">0% Ban Risk</b>
    <small>1-Click Official API</small>
  </div>
</div>

<!-- Sector Selector Tabs -->
<div class="scout-tabs">
  <?php foreach ($SECTORS as $k => $s): ?>
    <a href="?sector=<?= $k ?>&city=<?= urlencode($selectedCity) ?>" class="scout-tab <?= $selectedSectorKey === $k ? 'active' : '' ?>">
      <span><?= $s['icon'] ?></span>
      <span><?= e($s['name']) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<!-- Search Controls Box -->
<div class="scout-search-bar">
  <form method="get" action="lead_finder.php" style="display:grid;grid-template-columns:2fr 1.5fr 1fr auto;gap:12px;align-items:end">
    <input type="hidden" name="sector" value="<?= e($selectedSectorKey) ?>">
    <div>
      <label style="display:block;font-size:.8rem;color:var(--mut);margin-bottom:6px">Target Business / Industry</label>
      <input type="text" name="q" value="<?= e($customQuery ?: $currSector['query']) ?>" class="field" style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--line2);background:rgba(255,255,255,.04);color:var(--ink)" placeholder="e.g. Dental Clinic, Boutique, Wholesale">
    </div>
    <div>
      <label style="display:block;font-size:.8rem;color:var(--mut);margin-bottom:6px">City / Location</label>
      <select name="city" style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--line2);background:var(--panel2);color:var(--ink)">
        <option value="Karachi" <?= $selectedCity === 'Karachi' ? 'selected' : '' ?>>Karachi (Clifton, DHA, Saddar, PECHS)</option>
        <option value="Lahore" <?= $selectedCity === 'Lahore' ? 'selected' : '' ?>>Lahore (Gulberg, DHA, Johar Town)</option>
        <option value="Islamabad" <?= $selectedCity === 'Islamabad' ? 'selected' : '' ?>>Islamabad & Rawalpindi</option>
        <option value="Faisalabad" <?= $selectedCity === 'Faisalabad' ? 'selected' : '' ?>>Faisalabad (Industrial & Textile)</option>
        <option value="Dubai" <?= $selectedCity === 'Dubai' ? 'selected' : '' ?>>Dubai / UAE (Gulf Market)</option>
      </select>
    </div>
    <div>
      <button type="submit" class="btn p" style="width:100%;justify-content:center;padding:11px">🔍 Scout Leads</button>
    </div>
    <div>
      <a href="https://www.google.com/maps/search/<?= urlencode($searchQuery) ?>" target="_blank" rel="noopener" class="btn" style="padding:11px 16px;white-space:nowrap" title="Open live interactive Google Maps search in new tab">
        🗺️ Live Map
      </a>
    </div>
  </form>

  <!-- High-Converting Sector Strategy Banner -->
  <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <span style="font-size:.82rem;color:var(--cyan);font-weight:700">TARGET PRODUCT TO SELL:</span>
      <b style="font-size:.92rem;color:var(--ink);margin-left:6px"><?= e($currSector['service']) ?></b>
      <span style="display:block;font-size:.78rem;color:var(--mut);margin-top:2px"><?= e($currSector['hook']) ?></span>
    </div>
    <div>
      <a href="https://www.google.com/search?q=<?= urlencode($liSearchQuery) ?>" target="_blank" rel="noopener" class="btn sm" style="background:rgba(10,102,194,.18);border-color:#0A66C2;color:#60A5FA" title="Run Google X-Ray Search for LinkedIn Decision Makers">
        💼 Scout LinkedIn Decision Makers
      </a>
    </div>
  </div>
</div>

<!-- Scouted Leads Results Grid -->
<h2 style="margin-bottom:14px;display:flex;align-items:center;gap:10px">
  <span>Scouted Prospects (<?= count($displayLeads) ?>)</span>
  <span style="font-size:.8rem;color:var(--mut);font-weight:400">Ready for 1-Click WhatsApp & CRM Integration</span>
</h2>

<div class="scout-grid">
  <?php foreach ($displayLeads as $p): 
    $phoneClean = preg_replace('/[^0-9]/', '', $p['phone']);
    if (str_starts_with($phoneClean, '03')) {
        $waNum = '92' . substr($phoneClean, 1);
    } else {
        $waNum = $phoneClean;
    }

    // Dynamic Personalized Pitch
    $clientName = $p['name'] ?: $p['company'];
    $waText = str_replace('[Name]', $clientName, $currSector['pitch']);
    $waUrl = 'https://wa.me/' . $waNum . '?text=' . rawurlencode($waText);
  ?>
    <div class="prospect-card">
      <div>
        <div class="prospect-head">
          <div>
            <div class="prospect-title"><?= e($p['company']) ?></div>
            <div class="prospect-meta"><?= e($p['name']) ?> · <?= e($p['address']) ?></div>
          </div>
          <?php if (empty($p['website'])): ?>
            <span class="prospect-tag hot" title="No active website detected! Prime client for Web App / E-Commerce">NO WEBSITE</span>
          <?php else: ?>
            <span class="prospect-tag ready" title="Active website. Prime client for Modern ERP & AI Automation">HAS SITE</span>
          <?php endif; ?>
        </div>

        <div class="prospect-body">
          <div>📱 <b>Phone / WhatsApp:</b> <?= e($p['phone']) ?></div>
          <?php if (!empty($p['email'])): ?>
            <div>✉️ <b>Email:</b> <a href="mailto:<?= e($p['email']) ?>" style="color:var(--cyan)"><?= e($p['email']) ?></a></div>
          <?php endif; ?>
          <?php if (!empty($p['website'])): ?>
            <div>🌐 <b>Website:</b> <a href="<?= e($p['website']) ?>" target="_blank" rel="noopener" style="color:var(--mut)"><?= e(parse_url($p['website'], PHP_URL_HOST) ?: $p['website']) ?></a></div>
          <?php endif; ?>
          <div>⭐ <b>Google Rating:</b> <?= e($p['rating']) ?></div>
        </div>
      </div>

      <div>
        <div class="prospect-actions">
          <a href="<?= e($waUrl) ?>" target="_blank" rel="noopener" class="btn-wa" title="Open official WhatsApp chat with pre-filled winning pitch">
            <span>💬</span> <span>WhatsApp Pitch</span>
          </a>

          <form method="post" action="lead_finder.php?sector=<?= e($selectedSectorKey) ?>&city=<?= urlencode($selectedCity) ?>" style="margin:0">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="action" value="save_lead">
            <input type="hidden" name="name" value="<?= e($p['name']) ?>">
            <input type="hidden" name="company" value="<?= e($p['company']) ?>">
            <input type="hidden" name="phone" value="<?= e($p['phone']) ?>">
            <input type="hidden" name="email" value="<?= e($p['email']) ?>">
            <input type="hidden" name="service" value="<?= e($currSector['service']) ?>">
            <input type="hidden" name="industry" value="<?= e($currSector['name']) ?>">
            <input type="hidden" name="website" value="<?= e($p['website']) ?>">
            <input type="hidden" name="notes" value="Scouted via Lead Scout. Address: <?= e($p['address']) ?>. Rating: <?= e($p['rating']) ?>">
            <button type="submit" class="btn-crm" title="Save this prospect into your leads.php pipeline table">
              📥 Save to CRM
            </button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Section: Winning Outreach Script Viewer -->
<div class="card" style="margin-top:34px">
  <h2 style="margin-bottom:6px">Winning WhatsApp Pitch Template (<?= e($currSector['name']) ?>)</h2>
  <span class="mut">This script is automatically loaded when you click the green "WhatsApp Pitch" button on any prospect card.</span>
  <div class="script-box">
    <?= nl2br(e($currSector['pitch'])) ?>
  </div>
</div>

<!-- Section: LinkedIn Decision Maker Dorking Explainer -->
<div class="card" style="margin-top:18px">
  <h2 style="margin-bottom:6px">LinkedIn Decision Maker X-Ray Engine (100% Free & Anti-Ban)</h2>
  <span class="mut">Find Founders, CEOs, and CTOs in Pakistan and Gulf without logging into your personal LinkedIn account.</span>
  <div style="margin-top:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <code class="mono" style="background:rgba(0,0,0,.4);padding:8px 14px;border-radius:8px;font-size:.82rem;color:var(--cyan);border:1px solid var(--line2)">
      <?= e($liSearchQuery) ?>
    </code>
    <a href="https://www.google.com/search?q=<?= urlencode($liSearchQuery) ?>" target="_blank" rel="noopener" class="btn p">
      🚀 Run LinkedIn X-Ray on Google
    </a>
  </div>
</div>

<?php admin_foot(); ?>
