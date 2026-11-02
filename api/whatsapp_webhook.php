<?php
/* ============================================================
   SoftNexa — Official WhatsApp Cloud API Webhook & AI Bot
   Handles Meta Webhook Verification (GET) and Incoming Messages (POST)
   Powered by Google Gemini 1.5 Flash + Conversational Fallback Engine
   ============================================================ */

require_once __DIR__ . '/config.php';

// Ensure table exists
init_whatsapp_tables();

$verifyToken = setting('wa_verify_token', 'softnexa_wa_bot_2026');

/* ------------------------------------------------------------
   1. META WEBHOOK VERIFICATION CHALLENGE (GET)
   ------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $mode      = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';

    if ($mode === 'subscribe' && $token === $verifyToken) {
        http_response_code(200);
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    } else {
        http_response_code(403);
        echo 'Verification token mismatch';
        exit;
    }
}

/* ------------------------------------------------------------
   2. INCOMING MESSAGES (POST)
   ------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data || !is_array($data)) {
    http_response_code(200);
    echo json_encode(['status' => 'ignored_no_json']);
    exit;
}

// Check if WhatsApp bot is globally enabled
$botEnabled = (int)setting('wa_bot_enabled', 1);
if ($botEnabled !== 1) {
    http_response_code(200);
    echo json_encode(['status' => 'bot_disabled_human_mode']);
    exit;
}

// Meta WhatsApp Cloud API standard structure
$entry   = $data['entry'][0] ?? [];
$changes = $entry['changes'][0]['value'] ?? [];
$message = $changes['messages'][0] ?? null;
$contact = $changes['contacts'][0] ?? null;

// Support third-party or generic gateway payloads if not Meta native
if (!$message && isset($data['message']) && (isset($data['phone']) || isset($data['sender']))) {
    $senderPhone = preg_replace('/[^0-9]/', '', (string)($data['phone'] ?? $data['sender']));
    $senderName  = (string)($data['name'] ?? 'WhatsApp Client');
    $msgText     = trim((string)$data['message']);
    $msgId       = (string)($data['id'] ?? ('gw_' . bin2hex(random_bytes(8))));
} elseif ($message) {
    $msgId       = (string)($message['id'] ?? '');
    $senderPhone = preg_replace('/[^0-9]/', '', (string)($message['from'] ?? ''));
    $senderName  = (string)($contact['profile']['name'] ?? 'WhatsApp Client');
    $msgType     = (string)($message['type'] ?? 'text');

    // Extract message body based on type
    if ($msgType === 'text') {
        $msgText = trim((string)($message['text']['body'] ?? ''));
    } elseif ($msgType === 'button') {
        $msgText = trim((string)($message['button']['text'] ?? ''));
    } elseif ($msgType === 'interactive') {
        $msgText = trim((string)($message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? ''));
    } else {
        $msgText = '';
    }
} else {
    // Delivery receipts, read status, or other system events
    http_response_code(200);
    echo json_encode(['status' => 'status_event_ignored']);
    exit;
}

if ($senderPhone === '' || $msgText === '') {
    http_response_code(200);
    echo json_encode(['status' => 'empty_message']);
    exit;
}

// Deduplication: check if message ID was already processed
if ($msgId !== '') {
    try {
        $chk = db()->prepare("SELECT id FROM wa_messages WHERE wa_msg_id = ? LIMIT 1");
        $chk->execute([$msgId]);
        if ($chk->fetch()) {
            http_response_code(200);
            echo json_encode(['status' => 'already_processed']);
            exit;
        }
    } catch (Exception $e) {}
}

// Log incoming message to wa_messages
try {
    $st = db()->prepare("INSERT INTO wa_messages (wa_msg_id, phone, name, direction, message, raw_payload, status) VALUES (?, ?, ?, 'in', ?, ?, 'received')");
    $st->execute([$msgId, $senderPhone, $senderName, $msgText, $rawInput]);
} catch (Exception $e) {}

/* ------------------------------------------------------------
   3. RETRIEVE RECENT CHAT HISTORY FOR CONTEXT
   ------------------------------------------------------------ */
$recentHistory = [];
try {
    $histStmt = db()->prepare("SELECT direction, message FROM wa_messages WHERE phone = ? ORDER BY id DESC LIMIT 6");
    $histStmt->execute([$senderPhone]);
    $rows = array_reverse($histStmt->fetchAll(PDO::FETCH_ASSOC));
    foreach ($rows as $r) {
        $recentHistory[] = [
            'role'    => ($r['direction'] === 'in') ? 'user' : 'model',
            'content' => $r['message']
        ];
    }
} catch (Exception $e) {}

/* ------------------------------------------------------------
   4. DETECT LANGUAGE & INTENTS
   ------------------------------------------------------------ */
$msgLower = mb_strtolower($msgText);

$isUrduScript = (bool)preg_match('/[\x{0600}-\x{06FF}]/u', $msgText);
$romanUrduClues = ['kya', 'hai', 'hain', 'mein', 'hum', 'mujhe', 'chahiye', 'karna', 'kese', 'kaise', 'bataen', 'batao', 'boht', 'shukriya', 'rabta', 'kitna', 'kharcha', 'kitne', 'aap'];
$isRomanUrdu = false;
$foundWords = 0;
foreach ($romanUrduClues as $w) {
    if (preg_match('/\b' . preg_quote($w, '/') . '\b/i', $msgLower)) $foundWords++;
}
if ($foundWords >= 2) $isRomanUrdu = true;

$lang = 'en';
if ($isUrduScript) $lang = 'ur';
elseif ($isRomanUrdu) $lang = 'ro';

/* ------------------------------------------------------------
   5. AI RESPONSE GENERATION (GEMINI 1.5 FLASH)
   ------------------------------------------------------------ */
$geminiKey = setting('gemini_api_key', getenv('GEMINI_API_KEY') ?: '');
$aiReply = '';
$llmSucceeded = false;

$systemPrompt = "You are the Senior Software Solutions Architect & Lead Consultant at SoftNexa (softnexasolution.com), based on Shahrah-e-Faisal, Karachi, Pakistan.
You are communicating directly with a prospective business client on WhatsApp.

Your Persona & Tone:
- Professional, warm, courteous, and deeply helpful — like a trusted human technical advisor and partner.
- Never sound robotic, stiff, or like an automated script. Speak naturally, empathetically, and conversationally.
- Directly acknowledge and answer the client's specific questions or business ideas first.

About SoftNexa Offerings & Standards:
- Custom Websites & Web Portals: Fast, responsive, modern UI/UX (corporate sites delivered in 5-7 days; e-commerce & portals in 2-3 weeks).
- Custom Enterprise ERPs, POS & Inventory Systems: Multi-branch stock, billing, automated accounting, role-based controls.
- Mobile Applications: High-performance Flutter apps for iOS & Android with offline support, push notifications, and payment gateways.
- AI Agents & Automation: WhatsApp bots, CRM automations, and custom workflows.
- 100% Fixed-Price Guarantee with milestone delivery (zero hidden charges or hourly surprises).
- Live interactive demos: https://softnexasolution.com/#/demos
- Office: Shahrah-e-Faisal, Karachi, Pakistan (Clients are welcome to schedule in-person meetings or discovery calls).
- Direct Line: +92 315 2989922.

WhatsApp Communication Rules:
1. Match the client's exact language:
   - Roman Urdu: Warm, natural, native Pakistani Roman Urdu (e.g. 'G bilkul', 'Hum zaroor bana sakte hain', 'Aap batayein').
   - Urdu Script: Courteous, respectful Urdu.
   - English: Clear, executive, consultative English.
2. Format cleanly for mobile: 2 to 4 concise paragraphs or bullet points, friendly emojis (💻, 📱, ✨, 💼).
3. Always guide the user forward with ONE clear, smart question or next step (e.g., asking about their business type, key features, or offering a quick 10-minute discovery call).";

if ($geminiKey) {
    try {
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($geminiKey));
        $contents = [];
        $contents[] = ['role' => 'user', 'parts' => [['text' => $systemPrompt]]];
        $contents[] = ['role' => 'model', 'parts' => [['text' => "Understood. I am the SoftNexa WhatsApp Solutions Consultant. I will reply concisely in the user's exact language with high authority, transparency, and warmth."]]];

        foreach (array_slice($recentHistory, -4) as $h) {
            $contents[] = ['role' => $h['role'], 'parts' => [['text' => (string)$h['content']]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $msgText]]];

        $payload = json_encode([
            'contents' => $contents,
            'generationConfig' => [
                'temperature'     => 0.65,
                'maxOutputTokens' => 250
            ]
        ]);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $res) {
            $json = json_decode($res, true);
            $rawText = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (!empty(trim($rawText))) {
                $aiReply = trim($rawText);
                $llmSucceeded = true;
            }
        }
    } catch (Exception $e) {
        $llmSucceeded = false;
    }
}

/* ------------------------------------------------------------
   6. SMART HUMAN CONSULTANT CONVERSATIONAL ENGINE (FALLBACK / CORE)
   ------------------------------------------------------------ */
if (!$llmSucceeded || empty($aiReply)) {
    // Intent flags
    $isFollowUp      = (bool)preg_match('/\b(are you there|online|reply|sun rahe ho|kahan ho|sun rha|hello\?|koi hai|response|jawab)\b/i', $msgLower);
    $isWebsite       = (bool)preg_match('/\b(website|web site|site|webpage|web page|landing page|wordpress|web portal|web dev)\b/i', $msgLower);
    $isDoctorClinic  = (bool)preg_match('/\b(clinic|doctor|dental|dentist|teeth|hospital|patient|medical|health|pharma)\b/i', $msgLower);
    $isRealEstate    = (bool)preg_match('/\b(real estate|property|plots|plot|builder|housing|flats|apartments)\b/i', $msgLower);
    $isSalonGym      = (bool)preg_match('/\b(salon|parlor|parlour|spa|beauty|gym|fitness|workout)\b/i', $msgLower);
    $isEcommerce     = (bool)preg_match('/\b(ecommerce|e-commerce|online store|online shop|daraz|shopify|store|products sell|cart)\b/i', $msgLower);
    $isPrice         = (bool)preg_match('/\b(price|cost|charges|rate|kharcha|kitna|pese|paise|fees|budget|quote|estimate)\b/i', $msgLower);
    $isTimeline      = (bool)preg_match('/\b(kitne din|din|timeline|kab tak|time|duration|delivery time|urgent)\b/i', $msgLower);
    $isOfficeContact = (bool)preg_match('/\b(office|address|kahan|location|call|phone|number|milna|visit|shahrah|karachi|rabta)\b/i', $msgLower);
    $isERP           = (bool)preg_match('/\b(erp|pos|inventory|crm|warehouse|stock|accounting|billing|invoicing|distribution)\b/i', $msgLower);
    $isApp           = (bool)preg_match('/\b(app|mobile|android|ios|flutter|playstore|apk)\b/i', $msgLower);
    $isDemo          = (bool)preg_match('/\b(demo|sample|portfolio|previous work|link|check|live)\b/i', $msgLower);
    $isGreeting      = (bool)preg_match('/\b(hi|hello|hey|assalam|salam|aoa|kese ho|kaise ho|keise|kaisa|kese hain)\b/i', $msgLower);

    if ($isFollowUp) {
        if ($lang === 'ro') {
            $aiReply = "Ji bilkul, main yahan mojood hoon aur aapki baat sun raha hoon! 😊\n\nSoftNexa ki taraf se batayein, aap kis service ya project (Website, Mobile App, ya ERP Software) ke baare mein maloomat lena chahte hain?";
        } elseif ($lang === 'ur') {
            $aiReply = "جی بالکل، میں یہاں موجود ہوں اور آپ کی مدد کے لیے دستیاب ہوں! 😊\n\nبتائیں، آپ کس پروجیکٹ (ویب سائٹ، موبائل ایپ، یا سافٹ ویئر) کے حوالے سے رہنمائی چاہتے ہیں؟";
        } else {
            $aiReply = "Yes, I am right here and ready to help! 😊\n\nPlease let me know what software, website, or mobile application project you would like to discuss.";
        }
    } elseif ($isWebsite) {
        if ($lang === 'ro') {
            $aiReply = "G bilkul! Website development SoftNexa ki core specialization hai. 💻✨\n\nHum high-speed, mobile-responsive aur modern UI/UX wali websites banate hain. Aapko kis type ki website chahiye:\n\n1️⃣ Business / Corporate Portfolio (Services, Company Profile, Lead Forms)\n2️⃣ E-Commerce Online Store (Product Catalog, Cart, Payment/COD)\n3️⃣ Custom Web Portal (Booking System, Client Dashboard)\n\nAapka business kis type ka hai taake main relevant live demos share kar sakoon?";
        } elseif ($lang === 'ur') {
            $aiReply = "جی بالکل! ویب سائٹ ڈیولپمنٹ سافٹ نیکسا کی بنیادی مہارت ہے۔ 💻✨\n\nہم تیز رفتار، موبائل فرینڈلی اور جدید ڈیزائن والی ویب سائٹس بناتے ہیں:\n1️⃣ کاروباری پورٹ فولیو ویب سائٹ\n2️⃣ آن لائن ای کامرس اسٹور (پراڈکٹس اور آرڈرز)\n3️⃣ کسٹم ویب پورٹل یا بکنگ سسٹم\n\nآپ کا بزنس کس نوعیت کا ہے تاکہ ہم بہترین ڈیمو شیئر کر سکیں؟";
        } else {
            $aiReply = "Absolutely! Custom website development is our core expertise at SoftNexa. 💻✨\n\nWe build high-converting, mobile-responsive, and modern web platforms:\n1️⃣ Corporate Portfolio Website\n2️⃣ E-Commerce Online Store\n3️⃣ Custom Web Portal or SaaS Platform\n\nCould you share a bit about your business and key features you need?";
        }
    } elseif ($isDoctorClinic) {
        if ($lang === 'ro') {
            $aiReply = "Zabardast! Clinics aur Healthcare ke liye hum complete digital system provide karte hain:\n\n🩺 Online Patient Appointment Booking\n📋 Doctors & Treatments Profile\n🔔 Automated WhatsApp Reminders & Follow-ups\n💳 Billing & Patient History Records\n\nHumara live clinic prototype dekhna chahenge? https://softnexasolution.com/#/demos";
        } else {
            $aiReply = "We specialize in digital healthcare solutions for clinics and hospitals:\n🩺 Online Patient Appointments\n📋 Doctor & Treatment Profiles\n🔔 Automated WhatsApp Booking Reminders\n\nReview our live healthcare prototypes: https://softnexasolution.com/#/demos";
        }
    } elseif ($isRealEstate) {
        if ($lang === 'ro') {
            $aiReply = "Real Estate aur Construction ke liye hum premium lead-generation portals banate hain:\n\n🏢 Property Listings & Video Tours\n📍 Interactive Location Maps & Amenities\n📲 Direct WhatsApp Inquiries to Agents\n📊 CRM Lead Tracking Dashboard\n\nKya aapko multi-property agency portal chahiye ya kisi specific project/society ki website?";
        } else {
            $aiReply = "For Real Estate and Builders, we build high-converting property portals with interactive listings, location maps, and instant WhatsApp lead capture. Would you like to see a live prototype?";
        }
    } elseif ($isSalonGym) {
        if ($lang === 'ro') {
            $aiReply = "Salons, Spas aur Fitness Gyms ke liye hum stylish, fast websites aur booking systems banate hain:\n\n💇‍♀️ Services Menu & Pricing Packages\n📅 Date & Time Slot Booking\n📸 Instagram Gallery & Reviews Showcase\n🔔 WhatsApp Booking Confirmations\n\nAapka setup single branch hai ya multi-branch?";
        } else {
            $aiReply = "We craft beautiful, high-converting booking websites for Salons, Spas, and Gyms with slot booking and automated WhatsApp alerts. How many locations does your brand operate?";
        }
    } elseif ($isEcommerce) {
        if ($lang === 'ro') {
            $aiReply = "E-Commerce ke liye hum ultra-fast online shopping stores banate hain:\n\n🛍️ Smooth Product Catalog & Filtering\n💳 Payment Gateways (Credit Card, JazzCash, Easypaisa, COD)\n📦 Automated WhatsApp Order Confirmation & Tracking\n⚡ 100% Mobile Optimized Experience\n\nAapke store mein taqreeban kitne products honge?";
        } else {
            $aiReply = "We engineer high-performance E-Commerce stores with lightning-fast catalog search, payment gateway integrations, and automated WhatsApp order alerts. What product lines are you selling?";
        }
    } elseif ($isPrice) {
        if ($lang === 'ro') {
            $aiReply = "SoftNexa 100% Fixed-Price Guarantee par kaam karta hai (no hourly surprise bills):\n\n💼 Business / Corporate Website: PKR 35,000 - 55,000 (5 to 7 days delivery)\n🛍️ E-Commerce Store: PKR 75,000 - 150,000 (2 to 3 weeks delivery)\n🏢 Custom ERP / Multi-Branch Software: Scope ke mutabiq milestone-based\n\nHar package mein Free SSL, Mobile Responsive Design aur 1 Month Technical Support shamil hota hai. Aapki exact requirement par hum custom blueprint bana dete hain.";
        } elseif ($lang === 'ur') {
            $aiReply = "سافٹ نیکسا 100% فکسڈ پرائس گارنٹی پر کام کرتا ہے:\n- کاروباری ویب سائٹ: PKR 35,000 سے 55,000 (5 سے 7 دن)\n- ای کامرس اسٹور: PKR 75,000 سے 150,000 (2 سے 3 ہفتے)\n- کسٹم ای آر پی اور ایپس: اسکوپ کے مطابق\nہر پراجیکٹ میں مفت ایس ایس ایل، موبائل رسپانسیو ڈیزائن اور ٹیکنیکل سپورٹ شامل ہے۔";
        } else {
            $aiReply = "SoftNexa operates on a strict 100% Fixed-Price Guarantee:\n- Corporate Websites: PKR 35,000 - 55,000 (5-7 days)\n- Full E-Commerce Stores: PKR 75,000 - 150,000 (2-3 weeks)\n- Enterprise ERPs & Apps: Based on milestone scope\nIncludes free SSL, mobile optimization, and 30-day technical support.";
        }
    } elseif ($isTimeline) {
        if ($lang === 'ro') {
            $aiReply = "Delivery timeline project ke scope par depend karti hai:\n\n⚡ Business Website: 5 se 7 working days mein live ho jati hai.\n🛍️ E-Commerce Online Store: 2 se 3 weeks.\n🏢 Custom ERP / Mobile App: 3 se 6 weeks (milestone delivery ke sath).\n\nHum guaranteed on-time delivery dete hain. Aapko kab tak project live chahiye?";
        } else {
            $aiReply = "Our delivery timelines:\n⚡ Corporate Websites: 5-7 working days\n🛍️ E-Commerce Stores: 2-3 weeks\n🏢 Custom Systems/Apps: 3-6 weeks with weekly milestone demos.\nWhat is your target launch deadline?";
        }
    } elseif ($isOfficeContact) {
        if ($lang === 'ro') {
            $aiReply = "Aap hamari senior technical team se direct connect kar sakte hain:\n\n🏢 Office: Shahrah-e-Faisal, Karachi, Pakistan\n📞 Direct Call / WhatsApp: +92 315 2989922\n✉️ Email: info@softnexasolution.com\n🌐 Website: https://softnexasolution.com\n\nAap chahein to office visit kar sakte hain ya hum ek 10-minute discovery call arrange kar dein?";
        } else {
            $aiReply = "You can connect directly with our technical leadership:\n🏢 Office: Shahrah-e-Faisal, Karachi, Pakistan\n📞 Direct Phone / WhatsApp: +92 315 2989922\n✉️ Email: info@softnexasolution.com\n\nWould you prefer an in-person meeting or a 10-minute discovery call?";
        }
    } elseif ($isERP) {
        if ($lang === 'ro') {
            $aiReply = "SoftNexa ka custom ERP aapki multi-branch inventory, accounts, POS billing aur live reporting ko single cloud dashboard par le aata hai. 📊\n\nHum 100% bespoke banate hain, yani aapke business ke mutabiq customize hota hai. Aapki total kitni branches ya active users honge?";
        } else {
            $aiReply = "We engineer custom ERP and POS systems covering multi-branch inventory, automated billing, and live management dashboards. How many branches and active operators will use the platform?";
        }
    } elseif ($isApp) {
        if ($lang === 'ro') {
            $aiReply = "Hum iOS aur Android ke liye smooth Flutter mobile apps develop karte hain 📱 (with offline sync, push notifications aur online payment integrations).\n\nKya aapke paas app ka concept/wireframe ready hai ya scratch se design karna hai?";
        } else {
            $aiReply = "We build high-performance mobile apps for iOS and Android using Flutter with offline support, real-time push alerts, and payment gateways. Do you have wireframes prepared, or should we design the prototype from scratch?";
        }
    } elseif ($isDemo) {
        if ($lang === 'ro') {
            $aiReply = "Aap hamare live custom software prototypes yahan directly explore kar sakte hain:\n🌐 https://softnexasolution.com/#/demos\n\nIsme Healthcare Clinics, Retail POS, Logistics aur E-Commerce ke live interactive demos mojood hain. Aapko kis industry ka demo review karna hai?";
        } else {
            $aiReply = "You can test our live industry software prototypes here:\n🌐 https://softnexasolution.com/#/demos\n\nCovers Healthcare, Retail POS, Logistics, and E-Commerce. Which vertical would you like to review?";
        }
    } elseif ($isGreeting) {
        if ($lang === 'ur') {
            $aiReply = "وعلیکم السلام! سافٹ نیکسا میں خوش آمدید۔ 👋\nہم کسٹم ویب سائٹس، موبائل ایپس اور انٹرپرائز سسٹمز 100% فکسڈ پرائس گارنٹی پر بناتے ہیں۔ آپ اپنے کاروبار کے لیے کس طرح کا حل تلاش کر رہے ہیں؟\n\nہمارے لائیو سسٹمز: https://softnexasolution.com/#/demos";
        } elseif ($lang === 'ro') {
            $aiReply = "Walaikum Assalam! SoftNexa Solutions mein khush aamdeed. 👋\n\nMain SoftNexa ka Solutions Consultant hoon. Hum modern Websites, Mobile Apps aur Business Software 100% Fixed-Price Guarantee par build karte hain.\n\nAap apne business ke liye kis project ke baare mein discuss karna chahte hain?";
        } else {
            $aiReply = "Hello and welcome to SoftNexa Solutions! 👋\n\nI am your Solutions Consultant. We engineer bespoke Websites, Mobile Apps, and Enterprise Systems under a 100% Fixed-Price Guarantee.\n\nWhat type of digital system or website would you like to build?";
        }
    } else {
        if ($lang === 'ro') {
            $aiReply = "Yeh aik boht zaroori aur high-impact requirement lag rahi hai! ✨\n\nSoftNexa isko modern cloud architecture aur 100% Fixed-Price Guarantee ke sath tailor-make kar sakta hai. Isme aapko kaunse zaroori features ya modules chahiye?\n\nHumare live prototypes dekhein: https://softnexasolution.com/#/demos";
        } else {
            $aiReply = "This sounds like a high-impact initiative! SoftNexa can architect this under our 100% Fixed-Price Guarantee. What specific workflows or modules should we include in your scope blueprint?\n\nLive demos: https://softnexasolution.com/#/demos";
        }
    }
}

/* ------------------------------------------------------------
   7. AUTO CRM LEAD UPSERT & NOTIFICATION
   ------------------------------------------------------------ */
try {
    $leadCheck = db()->prepare("SELECT id FROM leads WHERE phone = ? LIMIT 1");
    $leadCheck->execute([$senderPhone]);
    $existing = $leadCheck->fetch();

    if ($existing) {
        $upd = db()->prepare("UPDATE leads SET message = CONCAT(COALESCE(message,''), '\n[WhatsApp] ', ?), status = CASE WHEN status = 'new' THEN 'contacted' ELSE status END WHERE id = ?");
        $upd->execute([$msgText, $existing['id']]);
    } else {
        $ins = db()->prepare("INSERT INTO leads (name, phone, service, message, source, status, lead_score) VALUES (?, ?, 'WhatsApp AI Consultation', ?, 'whatsapp_bot', 'new', 65)");
        $ins->execute([$senderName, $senderPhone, $msgText]);

        // Email admin notification for brand new WhatsApp lead
        $alertHtml = "<h2>New WhatsApp Lead Connected!</h2>"
            . "<p><b>Name:</b> " . htmlspecialchars($senderName) . "</p>"
            . "<p><b>Phone / WhatsApp:</b> <a href='https://wa.me/" . htmlspecialchars($senderPhone) . "'>" . htmlspecialchars($senderPhone) . "</a></p>"
            . "<p><b>First Inquiry:</b> " . htmlspecialchars($msgText) . "</p>"
            . "<p><b>AI Bot Reply:</b> " . htmlspecialchars($aiReply) . "</p>"
            . "<hr><p><a href='https://softnexasolution.com/admin/whatsapp_bot.php'>View WhatsApp Chat Log in Dashboard &rarr;</a></p>";
        send_html_email(NOTIFY_EMAIL, "New WhatsApp Lead: {$senderName} ({$senderPhone})", $alertHtml);
    }
} catch (Exception $e) {
    error_log("WhatsApp lead capture error: " . $e->getMessage());
}

/* ------------------------------------------------------------
   8. DISPATCH OUTBOUND MESSAGE VIA META CLOUD API
   ------------------------------------------------------------ */
$sendRes = send_meta_whatsapp_message($senderPhone, $aiReply);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'ok'       => true,
    'sender'   => $senderPhone,
    'reply'    => $aiReply,
    'meta_res' => $sendRes
]);
exit;
