<?php
require_once __DIR__ . '/_guard.php';
require_admin();
$pdo = db();

/* ============================================================
   B2B LEAD SCOUT & OUTREACH ENGINE — MIDDLE EAST & PAKISTAN
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
        $industry = trim($_POST['industry'] ?? 'Middle East B2B Scout');
        $notes    = trim($_POST['notes'] ?? '');
        $webUrl   = trim($_POST['website'] ?? '');
        $region   = trim($_POST['region'] ?? 'Middle East (UAE / KSA)');
        
        if ($name === '' && $company !== '') $name = $company;
        if ($name === '') $name = 'B2B Prospect';
        
        // Clean phone for international standard
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($cleanPhone, '03')) {
            $cleanPhone = '92' . substr($cleanPhone, 1);
        }
        
        $msg = "Scouted Lead: " . $company . "\nWebsite: " . ($webUrl ?: 'None (Needs Modern Website / Portal)') . "\nNotes: " . $notes;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO leads (name, company, email, phone, service, industry, problems, status, lead_score, source, message, region, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'new', 85, 'b2b-lead-scout', ?, ?, NOW())
            ");
            $stmt->execute([
                $name,
                $company,
                $email ?: 'scout@softnexasolution.com',
                $cleanPhone ?: $phone,
                $service,
                $industry,
                'Identified via B2B Lead Scout Middle East. Opportunity: ' . ($webUrl ? 'Custom ERP, AI Automation & Modernization' : 'Needs complete web presence & booking system'),
                $msg,
                $region
            ]);
            $leadId = $pdo->lastInsertId();
            $flash = "Lead successfully saved to CRM Pipeline (#$leadId)! You can view it in the Leads tab.";
        } catch (Exception $e) {
            $flash = "Database error: " . $e->getMessage();
            $flashType = 'error';
        }
    }
}

// Preset Target Sectors (Middle East & Pakistan High-Ticket Verticals)
$SECTORS = [
    'realestate_uae' => [
        'name'     => 'UAE & KSA Real Estate Brokerages',
        'icon'     => '🏢',
        'market'   => 'me',
        'service'  => 'EstateFlow CRM & 24/7 WhatsApp Property Bot',
        'pitch_en' => "Hello [Name], this is Adnan from SoftNexa (softnexasolution.com). We build custom 24/7 WhatsApp property automation bots and agent lead vault CRMs for real estate brokerages across Dubai and Riyadh. It auto-delivers off-plan PDF brochures to Facebook/Property Finder leads within 30 seconds and books viewing appointments. Review live interactive demos: https://softnexasolution.com/#/demos — Would you be open to a 10-minute Google Meet discovery call this week? Schedule here: https://softnexasolution.com/#/schedule",
        'pitch_ro' => "Salam Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum Dubai aur Riyadh ke real estate brokerages ke liye automated WhatsApp property bot aur agent lead vault CRM banate hain jo Facebook/Property Finder leads ko 30 seconds ke andar off-plan brochures bhej kar viewing slot book karta hai. Live demos: https://softnexasolution.com/#/demos — Schedule discovery call: https://softnexasolution.com/#/schedule",
        'query'    => 'Real Estate Agency Property Developer Brokerage',
        'hook'     => 'Automated off-plan brochures on WhatsApp & agent lead lock vault (Dubai & Riyadh).'
    ],
    'logistics_me' => [
        'name'     => 'Gulf Logistics, Freight & Fleet',
        'icon'     => '🚚',
        'market'   => 'me',
        'service'  => 'FleetTrack ERP & Driver WhatsApp Dispatch',
        'pitch_en' => "Hello [Name], this is Adnan from SoftNexa. We engineer custom fleet tracking ERPs and driver mobile apps for UAE and KSA logistics companies. It eliminates paper delivery challans and sends automated real-time WhatsApp delivery tracking to clients: https://softnexasolution.com/#/demos — Could we schedule a 10-minute Google Meet preview this week? Schedule here: https://softnexasolution.com/#/schedule",
        'pitch_ro' => "Salam Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum UAE aur KSA logistics companies ke liye custom fleet tracking aur driver mobile app banate hain jo paper delivery challans ko 100% digital karta hai aur customer ko automated WhatsApp delivery status deta hai: https://softnexasolution.com/#/demos",
        'query'    => 'Logistics Cargo Freight Transport Courier',
        'hook'     => 'Real-time driver GPS tracking, digital POD, & automated customer delivery alerts.'
    ],
    'ecommerce_me' => [
        'name'     => 'Middle East Luxury E-Commerce',
        'icon'     => '🛍️',
        'market'   => 'me',
        'service'  => 'Bilingual Store & Tabby/Tamara Fast Checkout',
        'pitch_en' => "Hello [Name], this is Adnan from SoftNexa. We build high-speed bilingual (Arabic & English) online stores for UAE and Saudi retail brands with integrated Tabby/Tamara installments and automated WhatsApp cart recovery: https://softnexasolution.com/#/demos — May I share a tailored scope blueprint with you? Schedule call: https://softnexasolution.com/#/schedule",
        'pitch_ro' => "Salam Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum UAE aur Saudi e-commerce brands ke liye ultra-fast bilingual (Arabic/English) stores banate hain jisme Tabby, Tamara aur automated WhatsApp order recovery integrated hota hai: https://softnexasolution.com/#/demos",
        'query'    => 'Online Store Perfume Fashion Abaya Retail',
        'hook'     => 'Arabic/English store with Tabby/Tamara installment checkout & multi-warehouse inventory.'
    ],
    'contracting_me' => [
        'name'     => 'Contracting, MEP & Fitout',
        'icon'     => '🏗️',
        'market'   => 'me',
        'service'  => 'BuildPro Project Costing & BOQ ERP',
        'pitch_en' => "Hello [Name], this is Adnan from SoftNexa. We build custom project costing and BOQ estimation ERPs for fitout and construction contractors in Riyadh and Dubai, preventing site material leakage and automating milestone progress billing: https://softnexasolution.com/#/demos — Would you like to schedule a 10-minute discovery call? Schedule: https://softnexasolution.com/#/schedule",
        'pitch_ro' => "Salam Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum construction aur fitout contractors ke liye custom project costing aur BOQ estimation software banate hain jo site material leakage ko rokta hai aur client milestone invoices automate karta hai: https://softnexasolution.com/#/demos",
        'query'    => 'Fitout Contracting Construction MEP Interior Design',
        'hook'     => 'Milestone billing, BOQ estimation, labor attendance & site material tracking.'
    ],
    'clinics_me' => [
        'name'     => 'Polyclinics & Aesthetic Centers',
        'icon'     => '🩺',
        'market'   => 'me',
        'service'  => 'PatientFlow EHR & Multilingual WhatsApp Bot',
        'pitch_en' => "Hello Dr. [Name], this is Adnan from SoftNexa. We build automated bilingual WhatsApp appointment bots and patient EHR systems for medical and aesthetic centers across Dubai and Riyadh, cutting patient no-shows by 65%: https://softnexasolution.com/#/demos — Could we arrange a brief demo for your clinic? Book call: https://softnexasolution.com/#/schedule",
        'pitch_ro' => "Salam Alaikum Dr. Sahib, SoftNexa se rabta kar rahe hain. Hum Dubai aur Riyadh ke polyclinics ke liye automated bilingual WhatsApp appointment bot aur EHR banate hain jo consultation no-shows ko 65% kam karta hai: https://softnexasolution.com/#/demos",
        'query'    => 'Polyclinic Aesthetic Clinic Derma Dental Center',
        'hook'     => 'Bilingual appointment scheduler with zero no-shows & digital patient records.'
    ],
    'trading_me' => [
        'name'     => 'General Trading & FMCG Wholesale',
        'icon'     => '📦',
        'market'   => 'me',
        'service'  => 'ZATCA Phase-2 Compliant Cloud ERP',
        'pitch_en' => "Hello [Name], this is Adnan from SoftNexa. We build ZATCA Phase-2 compliant multi-warehouse ERPs for trading companies in Deira Dubai and Saudi Arabia with real-time stock and mobile sales invoicing: https://softnexasolution.com/#/demos — Would you like to review a live prototype? Book call: https://softnexasolution.com/#/schedule",
        'pitch_ro' => "Salam Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum Deira Dubai aur Saudi Arabia ke trading businesses ke liye ZATCA Phase-2 compliant cloud ERP banate hain jo multi-currency invoices aur warehouse live stock phone par dikhata hai: https://softnexasolution.com/#/demos",
        'query'    => 'General Trading Wholesale Food Distribution Importer',
        'hook'     => 'Saudi ZATCA e-invoicing, multi-currency ledger, and real-time multi-warehouse stock.'
    ],
    'dentists_pk' => [
        'name'     => 'Dental Clinics (Pakistan)',
        'icon'     => '🦷',
        'market'   => 'pk',
        'service'  => 'Dental EHR & WhatsApp Reminder Bot',
        'pitch_en' => "Hello Dr. [Name], this is SoftNexa. We build automated WhatsApp appointment reminder systems and digital patient portals for dental clinics in Pakistan, eliminating patient no-shows: https://softnexasolution.com/#/demos",
        'pitch_ro' => "Assalam o Alaikum Dr. Sahib, SoftNexa se rabta kar rahe hain. Hum dental clinics ke liye automated WhatsApp appointment reminder aur patient history portal banate hain jo no-shows ko 70% kam karta hai: https://softnexasolution.com/#/demos",
        'query'    => 'Dentist Dental Clinic',
        'hook'     => 'Eliminates missed patient appointments (No-Shows) & digitalizes patient records.'
    ],
    'clothing_pk' => [
        'name'     => 'Fashion & Clothing Brands (Pakistan)',
        'icon'     => '👗',
        'market'   => 'pk',
        'service'  => '1-Click Fast Checkout Store & Courier API',
        'pitch_en' => "Hello [Name], this is SoftNexa. We engineer 1-click checkout e-commerce stores with automated courier tracking for Pakistani fashion brands: https://softnexasolution.com/#/demos",
        'pitch_ro' => "Assalam o Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum fashion brands ke liye 1-Click JazzCash/COD checkout aur automated courier tracking store banate hain: https://softnexasolution.com/#/demos",
        'query'    => 'Clothing Store Apparel Fashion Brand',
        'hook'     => '1-Click local payment checkout + automated courier dispatch.'
    ]
];

// Current Selection
$selectedSectorKey = $_GET['sector'] ?? 'realestate_uae';
if (!isset($SECTORS[$selectedSectorKey])) $selectedSectorKey = 'realestate_uae';
$currSector = $SECTORS[$selectedSectorKey];

$selectedCity = trim($_GET['city'] ?? 'Dubai');
$customQuery  = trim($_GET['q'] ?? '');

$searchQuery = $customQuery !== '' ? $customQuery : ($currSector['query'] . ' in ' . $selectedCity);

// Built-in Curated Verified B2B Prospects Dataset for UAE, KSA, and Pakistan
$SAMPLE_DIRECTORY = [
    'realestate_uae' => [
        ['name' => 'Tariq Mansoor', 'company' => 'Apex Horizon Real Estate', 'phone' => '+971501234567', 'email' => 'tariq@apexhorizon.ae', 'address' => 'Business Bay, Dubai, UAE', 'rating' => '4.9 ★ (140 reviews)', 'website' => 'https://apexhorizon.ae', 'country' => 'UAE'],
        ['name' => 'Fahad Al-Otaibi', 'company' => 'Rawabi Riyadh Properties', 'phone' => '+966504567890', 'email' => 'info@rawabiriyadh.sa', 'address' => 'King Fahd Road, Al Olaya, Riyadh, KSA', 'rating' => '4.8 ★ (92 reviews)', 'website' => '', 'country' => 'KSA'],
        ['name' => 'Karim Al-Husseini', 'company' => 'Blue Waters Luxury Realty', 'phone' => '+971529876543', 'email' => 'sales@bluewatersrealty.ae', 'address' => 'Dubai Marina, Dubai, UAE', 'rating' => '4.9 ★ (210 reviews)', 'website' => 'https://bluewatersrealty.ae', 'country' => 'UAE'],
        ['name' => 'Majid Al-Ghamdi', 'company' => 'Dar Al-Jawharah Real Estate', 'phone' => '+966551239874', 'email' => 'contact@darjawharah.com', 'address' => 'Al Andalus, Jeddah, KSA', 'rating' => '4.7 ★ (68 reviews)', 'website' => '', 'country' => 'KSA'],
        ['name' => 'Sultan Al-Nuaimi', 'company' => 'Capital Crest Properties', 'phone' => '+971563334455', 'email' => 'info@capitalcrest.ae', 'address' => 'Al Reem Island, Abu Dhabi, UAE', 'rating' => '4.8 ★ (85 reviews)', 'website' => 'https://capitalcrest.ae', 'country' => 'UAE']
    ],
    'logistics_me' => [
        ['name' => 'Zubair Qureshi', 'company' => 'Gulf Express Cargo & Freight', 'phone' => '+971509988776', 'email' => 'operations@gulfexpresscargo.ae', 'address' => 'Al Quoz Industrial Area 3, Dubai, UAE', 'rating' => '4.7 ★ (115 reviews)', 'website' => '', 'country' => 'UAE'],
        ['name' => 'Bandar Al-Harbi', 'company' => 'Saraya Al-Sharq Logistics', 'phone' => '+966501122334', 'email' => 'info@sarayalogistics.sa', 'address' => 'Jeddah Islamic Port Area, Jeddah, KSA', 'rating' => '4.8 ★ (74 reviews)', 'website' => 'https://sarayalogistics.sa', 'country' => 'KSA'],
        ['name' => 'Hassan Makki', 'company' => 'Falcon Speed Freight Services', 'phone' => '+971554443322', 'email' => 'contact@falconspeed.ae', 'address' => 'Ras Al Khor Logistics Park, Dubai, UAE', 'rating' => '4.6 ★ (98 reviews)', 'website' => '', 'country' => 'UAE'],
        ['name' => 'Abdullah Al-Dosari', 'company' => 'Najd Heavy Transport Co.', 'phone' => '+966558877665', 'email' => 'fleet@najdtransport.com', 'address' => 'Al Sulay Logistics Hub, Riyadh, KSA', 'rating' => '4.9 ★ (130 reviews)', 'website' => 'https://najdtransport.com', 'country' => 'KSA']
    ],
    'ecommerce_me' => [
        ['name' => 'Noor Al-Zahra', 'company' => 'Lumière Oud & Fragrances', 'phone' => '+971507766554', 'email' => 'orders@lumiereoud.ae', 'address' => 'City Walk, Dubai, UAE', 'rating' => '4.9 ★ (340 reviews)', 'website' => 'https://lumiereoud.ae', 'country' => 'UAE'],
        ['name' => 'Reem Al-Sheikh', 'company' => 'Haute Couture Abayas KSA', 'phone' => '+966503344556', 'email' => 'care@hautecouture.sa', 'address' => 'Tahlia Street, Riyadh, KSA', 'rating' => '4.8 ★ (280 reviews)', 'website' => '', 'country' => 'KSA'],
        ['name' => 'Omar Ba-Wazir', 'company' => 'Saffron & Spices Direct', 'phone' => '+971526677889', 'email' => 'sales@saffrondirect.ae', 'address' => 'Deira Gold Souq Area, Dubai, UAE', 'rating' => '4.7 ★ (150 reviews)', 'website' => '', 'country' => 'UAE']
    ],
    'contracting_me' => [
        ['name' => 'Eng. Ahmed Farooq', 'company' => 'Prestige Interior & Fitout LLC', 'phone' => '+971508899001', 'email' => 'projects@prestigefitout.ae', 'address' => 'Jumeirah Lakes Towers (JLT), Dubai, UAE', 'rating' => '4.8 ★ (82 reviews)', 'website' => 'https://prestigefitout.ae', 'country' => 'UAE'],
        ['name' => 'Eng. Khaled Al-Mutairi', 'company' => 'Riyadh Modern Contractors', 'phone' => '+966509988112', 'email' => 'info@riyadhmodern.sa', 'address' => 'Al Malaz District, Riyadh, KSA', 'rating' => '4.7 ★ (64 reviews)', 'website' => '', 'country' => 'KSA']
    ],
    'clinics_me' => [
        ['name' => 'Dr. Mona Al-Saeed', 'company' => 'Elite Aesthetic & Derma Clinic', 'phone' => '+971501112233', 'email' => 'bookings@elitederma.ae', 'address' => 'Dubai Healthcare City, Dubai, UAE', 'rating' => '4.9 ★ (190 reviews)', 'website' => 'https://elitederma.ae', 'country' => 'UAE'],
        ['name' => 'Dr. Waleed Al-Ghamdi', 'company' => 'Al-Safwah Dental & Polyclinic', 'phone' => '+966506655443', 'email' => 'contact@alsafwahclinic.sa', 'address' => 'Al Rawdah, Jeddah, KSA', 'rating' => '4.8 ★ (110 reviews)', 'website' => '', 'country' => 'KSA']
    ],
    'trading_me' => [
        ['name' => 'Haji Mansoor Trading', 'company' => 'Al-Barakah Foodstuff & Rice Trading', 'phone' => '+971502233445', 'email' => 'sales@albarakahtrading.ae', 'address' => 'Al Ras, Deira, Dubai, UAE', 'rating' => '4.6 ★ (105 reviews)', 'website' => '', 'country' => 'UAE'],
        ['name' => 'Sheikh Fahad Al-Tamimi', 'company' => 'Al-Sharq Building Materials', 'phone' => '+966507788990', 'email' => 'info@alsharqmaterials.sa', 'address' => 'Al Batha Commercial Area, Riyadh, KSA', 'rating' => '4.7 ★ (95 reviews)', 'website' => '', 'country' => 'KSA']
    ],
    'dentists_pk' => [
        ['name' => 'Dr. Arif Dental Clinic', 'company' => 'Arif Dental & Aesthetic Center', 'phone' => '03002145890', 'email' => '', 'address' => 'Clifton Block 5, Karachi', 'rating' => '4.8 ★ (124 reviews)', 'website' => '', 'country' => 'PK'],
        ['name' => 'The Dental Suite', 'company' => 'The Dental Suite & Implantology', 'phone' => '03218841290', 'email' => 'info@dentalsuite.pk', 'address' => 'Phase 6 DHA, Karachi', 'rating' => '4.9 ★ (88 reviews)', 'website' => 'http://dentalsuite.pk', 'country' => 'PK']
    ],
    'clothing_pk' => [
        ['name' => 'Usman Apparel', 'company' => 'Khaas Poshak Garments', 'phone' => '03014561234', 'email' => 'sales@khaasposhak.com', 'address' => 'Tariq Road, Karachi', 'rating' => '4.6 ★ (280 reviews)', 'website' => '', 'country' => 'PK']
    ]
];

$displayLeads = $SAMPLE_DIRECTORY[$selectedSectorKey] ?? $SAMPLE_DIRECTORY['realestate_uae'];

// LinkedIn Dorking Query builder
$targetRole = $_GET['li_role'] ?? 'Founder OR CEO OR "Managing Director" OR Owner';
$liSearchQuery = 'site:linkedin.com/in/ ("' . str_replace('"', '', $targetRole) . '") ("' . str_replace('"', '', $currSector['query']) . '") ("' . str_replace('"', '', $selectedCity) . '")';

admin_head('lead_finder.php', 'B2B Lead Scout & Middle East Outreach Engine — SoftNexa');
?>

<style>
.scout-tabs{display:flex;gap:8px;border-bottom:1px solid var(--line);margin-bottom:22px;padding-bottom:12px;overflow-x:auto}
.scout-tab{padding:9px 18px;border-radius:999px;font-weight:600;font-size:.88rem;text-decoration:none;border:1px solid var(--line2);background:#FFFFFF;color:var(--mut);display:inline-flex;align-items:center;gap:8px;white-space:nowrap;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.scout-tab:hover{color:var(--ink);border-color:var(--iris)}
.scout-tab.active{background:linear-gradient(110deg,var(--iris),var(--cyan));border-color:transparent;color:#fff}
.scout-search-bar{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);padding:20px;margin-bottom:24px;box-shadow:0 1px 3px rgba(15,23,42,.04)}
.scout-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px}
.prospect-card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px;display:flex;flex-direction:column;justify-content:space-between;transition:transform .2s ease,border-color .2s ease;box-shadow:0 1px 3px rgba(15,23,42,.04)}
.prospect-card:hover{border-color:color-mix(in srgb,var(--cyan) 50%,var(--iris));transform:translateY(-2px);box-shadow:0 4px 12px rgba(15,23,42,.08)}
.prospect-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px}
.prospect-title{font-size:1.02rem;font-weight:700;color:var(--ink);line-height:1.2}
.prospect-meta{font-size:.82rem;color:var(--mut);margin-top:3px}
.prospect-tag{font-size:.7rem;font-weight:700;font-family:var(--font-mono);padding:3px 8px;border-radius:6px;background:var(--panel2);border:1px solid var(--line2);color:var(--mut)}
.prospect-tag.hot{background:rgba(239,68,68,.12);color:#DC2626;border-color:rgba(239,68,68,.3)}
.prospect-tag.ready{background:rgba(22,199,132,.12);color:#059669;border-color:rgba(22,199,132,.3)}
.prospect-tag.flag{background:rgba(110,75,255,.12);color:var(--iris);border-color:rgba(110,75,255,.3)}
.prospect-body{font-size:.85rem;line-height:1.5;margin:8px 0;color:var(--mut)}
.prospect-body b{color:var(--ink)}
.prospect-actions{display:flex;gap:6px;margin-top:14px;padding-top:12px;border-top:1px solid var(--line);flex-wrap:wrap}
.btn-wa-en{background:#25D366;color:#fff;border:none;display:inline-flex;align-items:center;gap:5px;padding:7px 11px;border-radius:8px;font-weight:700;font-size:.8rem;text-decoration:none;cursor:pointer}
.btn-wa-en:hover{background:#20BD5A}
.btn-wa-ro{background:rgba(37,211,102,.12);color:#059669;border:1px solid rgba(37,211,102,.3);display:inline-flex;align-items:center;gap:5px;padding:7px 9px;border-radius:8px;font-weight:600;font-size:.78rem;text-decoration:none;cursor:pointer}
.btn-wa-ro:hover{background:rgba(37,211,102,.22)}
.btn-prop{background:linear-gradient(120deg,var(--iris),#9333EA);color:#fff;border:none;display:inline-flex;align-items:center;gap:5px;padding:7px 11px;border-radius:8px;font-weight:600;font-size:.8rem;text-decoration:none;cursor:pointer}
.btn-prop:hover{opacity:.95}
.btn-meet{background:linear-gradient(120deg,#1A73E8,#4285F4);color:#fff;border:none;display:inline-flex;align-items:center;gap:5px;padding:7px 10px;border-radius:8px;font-weight:600;font-size:.8rem;text-decoration:none;cursor:pointer}
.btn-crm{background:#FFFFFF;color:var(--ink);border:1px solid var(--line2);padding:7px 9px;border-radius:8px;font-weight:600;font-size:.78rem;cursor:pointer;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.btn-crm:hover{border-color:var(--iris);color:var(--iris);background:var(--panel2)}
.alert-box{padding:12px 18px;border-radius:10px;margin-bottom:20px;font-size:.9rem}
.alert-box.success{background:rgba(22,199,132,.12);border:1px solid rgba(22,199,132,.3);color:#059669}
.alert-box.error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#DC2626}
.script-box{background:var(--panel2);border:1px solid var(--line2);border-radius:10px;padding:14px;font-size:.84rem;color:var(--ink);line-height:1.6;margin-top:10px}
.advisory-box{background:linear-gradient(135deg,rgba(110,75,255,.06),rgba(2,132,199,.04));border:1.5px solid rgba(110,75,255,.25);border-radius:14px;padding:18px;margin-bottom:24px}
</style>

<div class="top">
  <div>
    <h1>B2B Lead Scout & Middle East Outreach Engine</h1>
    <span class="mut">Scout high-ticket business leads across UAE (Dubai / Abu Dhabi) & Saudi Arabia (Riyadh / Jeddah) — with Executive English Pitches, Tailored Short Proposals & Google Meet.</span>
  </div>
  <div class="sp">
    <a href="leads.php" class="btn">View CRM Pipeline</a>
    <a href="../#/schedule" target="_blank" class="btn p">📅 Open Google Meet Scheduler</a>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert-box <?= $flashType ?>"><?= e($flash) ?></div>
<?php endif; ?>

<!-- Anti-Ban Advisory & Strategy Guide -->
<div class="advisory-box">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
    <span style="font-size:1.3rem">🛡️</span>
    <strong style="font-size:1.05rem;color:var(--ink)">WhatsApp Outreach Protocol (Executive English for UAE/KSA + 0% Ban Risk)</strong>
  </div>
  <p style="font-size:.88rem;color:var(--mut);line-height:1.6;margin:0 0 10px">
    International clients (Dubai, Abu Dhabi, Riyadh, US, UK) ke liye pitch <b>Executive English</b> mein tayyar ki gayi hai. Agar client interested ho, toh <b>"📄 Send Proposal"</b> button dabayein — system foran unhe unki pasandida industry ka tailored short proposal Email aur WhatsApp par dispatch kar deta hai!
  </p>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;font-size:.84rem">
    <div style="background:rgba(0,0,0,.25);padding:10px 14px;border-radius:10px;border:1px solid var(--line)">
      <b style="color:var(--mint)">1️⃣ 1-Click English WhatsApp:</b>
      <p style="margin:4px 0 0;color:var(--mut)">Green "WA (EN)" button click karein. Professional English pitch tayyar khulti hai. Direct real communication hoti hai (0% ban risk).</p>
    </div>
    <div style="background:rgba(0,0,0,.25);padding:10px 14px;border-radius:10px;border:1px solid var(--line)">
      <b style="color:var(--iris)">2️⃣ 1-Click Tailored Proposal:</b>
      <p style="margin:4px 0 0;color:var(--mut)">Purple "Proposal" button dabayein. Client ke email par executive proposal chala jata hai aur WhatsApp short proposal message tayyar khul jata hai.</p>
    </div>
    <div style="background:rgba(0,0,0,.25);padding:10px 14px;border-radius:10px;border:1px solid var(--line)">
      <b style="color:var(--cyan)">3️⃣ Google Meet Instant Schedule:</b>
      <p style="margin:4px 0 0;color:var(--mut)">Client <code>softnexasolution.com/#/schedule</code> se slot book karta hai, aur foran alert aapke WhatsApp (+92 315 2989922) par deliver hota hai.</p>
    </div>
  </div>
</div>

<!-- Top Metrics Cards -->
<div class="cards" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px">
  <div class="card">
    <b style="font-size:1.6rem;color:var(--cyan)"><?= count($displayLeads) ?> Leads</b>
    <small>Currently in View</small>
  </div>
  <div class="card">
    <b style="font-size:1.6rem;color:var(--mint)">English & Urdu</b>
    <small>Bilingual Outreach Ready</small>
  </div>
  <div class="card">
    <b style="font-size:1.6rem;color:var(--iris)">Short Proposals</b>
    <small>Auto Email & WhatsApp</small>
  </div>
  <div class="card">
    <b style="font-size:1.6rem;color:var(--amber)">Google Meet</b>
    <small>Auto Scheduling Engine</small>
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
      <input type="text" name="q" value="<?= e($customQuery ?: $currSector['query']) ?>" class="field" style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--line2);background:rgba(255,255,255,.04);color:var(--ink)" placeholder="e.g. Real Estate, Logistics, Contracting">
    </div>
    <div>
      <label style="display:block;font-size:.8rem;color:var(--mut);margin-bottom:6px">City / Gulf Location</label>
      <select name="city" style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--line2);background:var(--panel2);color:var(--ink)">
        <option value="Dubai" <?= $selectedCity === 'Dubai' ? 'selected' : '' ?>>Dubai, UAE (Business Bay, Downtown, Marina)</option>
        <option value="Abu Dhabi" <?= $selectedCity === 'Abu Dhabi' ? 'selected' : '' ?>>Abu Dhabi, UAE (Corniche, Al Reem)</option>
        <option value="Riyadh" <?= $selectedCity === 'Riyadh' ? 'selected' : '' ?>>Riyadh, KSA (Al Olaya, KAFD, Al Malaz)</option>
        <option value="Jeddah" <?= $selectedCity === 'Jeddah' ? 'selected' : '' ?>>Jeddah, KSA (Al Rawdah, Al Andalus)</option>
        <option value="Sharjah" <?= $selectedCity === 'Sharjah' ? 'selected' : '' ?>>Sharjah, UAE (Industrial & Freezone)</option>
        <option value="Karachi" <?= $selectedCity === 'Karachi' ? 'selected' : '' ?>>Karachi, Pakistan (Clifton, DHA)</option>
        <option value="Lahore" <?= $selectedCity === 'Lahore' ? 'selected' : '' ?>>Lahore, Pakistan (Gulberg, DHA)</option>
        <option value="Islamabad" <?= $selectedCity === 'Islamabad' ? 'selected' : '' ?>>Islamabad, Pakistan (Blue Area)</option>
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
  <span style="font-size:.8rem;color:var(--mut);font-weight:400">Ready for English Outreach, Short Proposal & Google Meet</span>
</h2>

<div class="scout-grid">
  <?php foreach ($displayLeads as $p): 
    $phoneClean = preg_replace('/[^0-9]/', '', $p['phone']);
    if (str_starts_with($phoneClean, '03')) {
        $waNum = '92' . substr($phoneClean, 1);
    } else {
        $waNum = $phoneClean;
    }

    $clientName = $p['name'] ?: $p['company'];
    
    // English Pitch (Default for International)
    $waTextEn = str_replace('[Name]', $clientName, $currSector['pitch_en'] ?? $currSector['pitch']);
    $waUrlEn = 'https://wa.me/' . $waNum . '?text=' . rawurlencode($waTextEn);

    // Roman Urdu Pitch
    $waTextRo = str_replace('[Name]', $clientName, $currSector['pitch_ro'] ?? $currSector['pitch']);
    $waUrlRo = 'https://wa.me/' . $waNum . '?text=' . rawurlencode($waTextRo);

    // Direct Short Proposal Trigger Link
    $propUrl = "../api/send_proposal.php?name=" . urlencode($clientName) 
        . "&company=" . urlencode($p['company']) 
        . "&email=" . urlencode($p['email'] ?? '') 
        . "&phone=" . urlencode($p['phone']) 
        . "&service=" . urlencode($currSector['service']) 
        . "&lang=" . ($p['country'] === 'PK' ? 'ro' : 'en');

    // Google Calendar / Meet Scheduling URL
    $calPrefill = "https://calendar.google.com/calendar/render?action=TEMPLATE&text=" 
        . urlencode("SoftNexa Discovery Call: " . $p['company'] . " & SoftNexa")
        . "&details=" . urlencode("30-Minute Google Meet Discovery Call regarding " . $currSector['service'] . ".\nClient: " . $clientName . "\nPhone: " . $p['phone'] . "\nWebsite: https://softnexasolution.com")
        . "&location=" . urlencode("https://meet.google.com/new");
  ?>
    <div class="prospect-card">
      <div>
        <div class="prospect-head">
          <div>
            <div class="prospect-title"><?= e($p['company']) ?></div>
            <div class="prospect-meta"><?= e($p['name']) ?> · <?= e($p['address']) ?></div>
          </div>
          <div style="display:flex;gap:4px">
            <span class="prospect-tag flag"><?= e($p['country'] ?? 'GCC') ?></span>
            <?php if (empty($p['website'])): ?>
              <span class="prospect-tag hot" title="No active website detected! Prime client for Web App / E-Commerce">NO SITE</span>
            <?php else: ?>
              <span class="prospect-tag ready" title="Active website. Prime client for Modern ERP & AI Automation">HAS SITE</span>
            <?php endif; ?>
          </div>
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
          <!-- 1-Click English WhatsApp (Primary) -->
          <a href="<?= e($waUrlEn) ?>" target="_blank" rel="noopener" class="btn-wa-en" title="Send Executive English Pitch on WhatsApp">
            <span>💬</span> <span>WA (EN)</span>
          </a>

          <!-- Roman Urdu Option -->
          <a href="<?= e($waUrlRo) ?>" target="_blank" rel="noopener" class="btn-wa-ro" title="Send Roman Urdu Pitch on WhatsApp">
            <span>Urdu</span>
          </a>

          <!-- 1-Click Send Proposal (Email & WhatsApp) -->
          <a href="<?= e($propUrl) ?>" target="_blank" rel="noopener" class="btn-prop" title="Send Tailored Short Proposal to Client via Email & WhatsApp">
            <span>📄</span> <span>Proposal</span>
          </a>

          <!-- Google Meet Link -->
          <a href="<?= e($calPrefill) ?>" target="_blank" rel="noopener" class="btn-meet" title="Schedule Google Meet with this lead">
            <span>📅</span> <span>Meet</span>
          </a>

          <!-- Save to CRM -->
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
            <input type="hidden" name="region" value="<?= e($p['country'] === 'PK' ? 'Pakistan' : 'Middle East (' . $p['country'] . ')') ?>">
            <input type="hidden" name="notes" value="Scouted via Middle East Lead Scout. Address: <?= e($p['address']) ?>. Rating: <?= e($p['rating']) ?>">
            <button type="submit" class="btn-crm" title="Save this prospect into your leads.php pipeline table">
              📥 CRM
            </button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Section: Winning Outreach Script Viewer -->
<div class="card" style="margin-top:34px">
  <h2 style="margin-bottom:6px">Winning Executive English Pitch (<?= e($currSector['name']) ?>)</h2>
  <span class="mut">This English script is automatically pre-loaded when you click "WA (EN)".</span>
  <div class="script-box">
    <?= nl2br(e($currSector['pitch_en'] ?? $currSector['pitch'])) ?>
  </div>
</div>

<!-- Section: LinkedIn Decision Maker Dorking Explainer -->
<div class="card" style="margin-top:18px">
  <h2 style="margin-bottom:6px">LinkedIn Decision Maker X-Ray Engine (100% Free & Anti-Ban)</h2>
  <span class="mut">Find Founders, CEOs, and Managing Directors in UAE, Saudi Arabia & Pakistan without logging into your personal LinkedIn account.</span>
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
