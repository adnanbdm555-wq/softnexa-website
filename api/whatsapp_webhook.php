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

$systemPrompt = "You are the Senior Software Solutions Architect & Business Consultant at SoftNexa (softnexasolution.com), based in Karachi, Pakistan.
You are communicating directly with a business client via WhatsApp.

About SoftNexa:
- We build custom Enterprise ERPs, POS & Inventory Systems, Scalable Web Apps, Flutter iOS/Android Apps, and Autonomous AI/WhatsApp Bots.
- 100% Fixed-Price Guarantee with tangible milestone delivery (zero hourly surprises).
- Core MVPs typically start at $3,000 / PKR equivalent (delivered in 2-4 weeks). Full enterprise multi-branch systems range $6,000 - $15,000+.
- Live interactive demos available at: https://softnexasolution.com/#/demos
- Office: Shahrah-e-Faisal, Karachi, Pakistan.

WhatsApp Rules:
1. Always match the user's language EXACTLY:
   - If Roman Urdu: Reply in warm, natural, fluent Roman Urdu.
   - If Urdu script: Reply in courteous, elegant Urdu.
   - If English: Reply in clear, crisp, executive English.
2. Keep replies concise and easy to read on mobile (under 75 words).
3. Use clean spacing and friendly emojis (💼, ⚡, 📱, 🚀).
4. Emphasize our 100% Fixed-Price Guarantee & 48-Hour Scope Blueprint.
5. Provide the live demo link (https://softnexasolution.com/#/demos) and invite them to share what key features or workflow their business needs.";

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
   6. SMART CONVERSATIONAL FALLBACK (IF LLM OFFLINE OR BUSY)
   ------------------------------------------------------------ */
if (!$llmSucceeded || empty($aiReply)) {
    $isGreeting = preg_match('/\b(hi|hello|hey|assalam|salam|aoa|kese ho|kaise ho|keise|kaisa|kese hain)\b/i', $msgLower);
    $isPrice    = preg_match('/\b(price|cost|charges|rate|kharcha|kitna|pese|paise|fees|budget|quote|estimate)\b/i', $msgLower);
    $isERP      = preg_match('/\b(erp|pos|inventory|crm|warehouse|stock|accounting|billing|invoicing|distribution|shop)\b/i', $msgLower);
    $isApp      = preg_match('/\b(app|mobile|android|ios|flutter|playstore)\b/i', $msgLower);
    $isDemo     = preg_match('/\b(demo|sample|portfolio|previous work|link|check|live)\b/i', $msgLower);

    if ($isGreeting) {
        if ($lang === 'ur') {
            $aiReply = "وعلیکم السلام! سافٹ نیکسا میں خوش آمدید۔ 👋\nہم کسٹم ای آر پی، موبائل ایپس، ویب پورٹلز اور اے آئی آٹومیشن 100% فکسڈ پرائس گارنٹی پر بناتے ہیں۔ آپ کس طرح کا سوفٹ ویئر بنوانا چاہتے ہیں؟\n\nہمارے لائیو سسٹمز دیکھیں: https://softnexasolution.com/#/demos";
        } elseif ($lang === 'ro') {
            $aiReply = "Walaikum Assalam! Welcome to SoftNexa Solutions. 👋\nHum bespoke Enterprise ERPs, Mobile Apps, aur Web Portals 100% Fixed-Price Guarantee par build karte hain. Aap apne business ke liye kis tarah ka software build karna chahte hain?\n\nHumare live demos dekhein: https://softnexasolution.com/#/demos";
        } else {
            $aiReply = "Hello and welcome to SoftNexa Solutions! 👋\nWe engineer bespoke Enterprise ERPs, Mobile Apps, and AI Automations under a 100% Fixed-Price Guarantee. What type of software or digital system are you looking to build?\n\nExplore our live systems: https://softnexasolution.com/#/demos";
        }
    } elseif ($isPrice) {
        if ($lang === 'ur') {
            $aiReply = "سافٹ نیکسا 100% فکسڈ پرائس گارنٹی پر کام کرتا ہے (کوئی غیر متوقع گھنٹوں کے چارجز نہیں):\n- کور ایم وی پی / فوکسڈ سسٹم: $3,000 / PKR مساوی سے شروع ہوتا ہے (2-4 ہفتوں میں ڈیلیور)۔\n- مکمل انٹرپرائز ای آر پی: اسکوپ کے مطابق $6,000 سے $15,000+۔\nادائیگی مائل اسٹون بیسڈ ہوتی ہے۔ آپ کے پروجیکٹ میں کیا اہم فیچرز شامل ہوں گے؟";
        } elseif ($lang === 'ro') {
            $aiReply = "SoftNexa 100% Fixed-Price Guarantee par kaam karta hai (no hourly surprise bills):\n- Core MVP System: $3,000 / PKR equivalent se start hota hai (2 to 4 weeks delivery).\n- Enterprise ERP: Scope ke mutabiq $6,000 se $15,000+.\nPayment milestone-based hoti hai. Aapke project mein kin features ki zaroorat hai taake hum exact scope finalize karein?";
        } else {
            $aiReply = "SoftNexa operates under a 100% Fixed-Price Guarantee with milestone-based delivery:\n- Core MVPs start at $3,000 (delivered in 2-4 weeks).\n- Enterprise Multi-Branch Systems range from $6,000 to $15,000+.\nWhat are the key operational features or modules you need in this system?";
        }
    } elseif ($isDemo) {
        if ($lang === 'ur') {
            $aiReply = "آپ ہمارے لائیو کسٹم سسٹمز اور انڈسٹری پروٹوٹائپس یہاں ٹیسٹ کر سکتے ہیں:\n🌐 https://softnexasolution.com/#/demos\n\nاس میں فارمیسی، مینوفیکچرنگ، لاجسٹکس، اور ای کامرس کے سسٹمز شامل ہیں۔ آپ کس انڈسٹری کا پروٹوٹائپ دیکھنا چاہتے ہیں؟";
        } elseif ($lang === 'ro') {
            $aiReply = "Aap hamare live custom software prototypes yahan test kar sakte hain:\n🌐 https://softnexasolution.com/#/demos\n\nIsme Manufacturing, Retail POS, Logistics aur E-Commerce ke live interactive demos shamil hain. Aapko kis industry ka demo review karna hai?";
        } else {
            $aiReply = "You can interact with our live industry software prototypes right here:\n🌐 https://softnexasolution.com/#/demos\n\nCovers Manufacturing, Retail POS, Logistics, and E-Commerce. Which specific vertical would you like to review?";
        }
    } elseif ($isERP) {
        if ($lang === 'ur') {
            $aiReply = "ہم مکمل طور پر کسٹم ای آر پی اور پی او ایس بناتے ہیں جس میں ملٹی برانچ انوینٹری، بارکوڈ اسکیننگ، رول بیسڈ پرمیشنز اور ریئل ٹائم رپورٹس شامل ہیں۔ آپ کے پاس کتنی برانچز اور صارفین ہوں گے؟";
        } elseif ($lang === 'ro') {
            $aiReply = "SoftNexa ka custom ERP aapki multi-branch inventory, accounts, POS billing aur live reporting ko single cloud dashboard par le aata hai. Hamara system 100% bespoke hota hai. Aapki total kitni branches ya active team members hain?";
        } else {
            $aiReply = "We build custom, modular ERP & POS systems—covering inventory, multi-branch POS, automated invoicing, and role-based workflows built for your exact operations. How many branches and active operators will use this system?";
        }
    } elseif ($isApp) {
        if ($lang === 'ur') {
            $aiReply = "ہم آئی او ایس اور اینڈرائیڈ دونوں کے لیے فاسٹ اور خوبصورت موبائل ایپس (Flutter) بناتے ہیں، جن میں ریئل ٹائم ٹریکنگ اور پیمنٹ گیٹ ویز شامل ہوتے ہیں۔ آپ کی ایپ کا بنیادی مقصد کیا ہے؟";
        } elseif ($lang === 'ro') {
            $aiReply = "Hum iOS aur Android ke liye smooth single-codebase Flutter apps develop karte hain (with offline support, push notifications aur payment integrations). Kya aapke paas wireframes ready hain ya scratch se design karna hai?";
        } else {
            $aiReply = "We build high-performance mobile apps for iOS and Android using Flutter with offline support, real-time push alerts, and payment integrations. Do you have wireframes prepared, or should we design the prototype from scratch?";
        }
    } else {
        if ($lang === 'ur') {
            $aiReply = "یہ ایک بہترین پروجیکٹ لگ رہا ہے۔ سافٹ نیکسا آپ کے سسٹم کو ماڈرن کلاؤڈ آرکیٹیکچر اور 100% فکسڈ پرائس گارنٹی کے ساتھ بنا سکتا ہے۔ کیا آپ بتائیں گے کہ اس میں کون سے ضروری ماڈیولز ہونے چاہئیں؟\n\nلائیو پروٹوٹائپس: https://softnexasolution.com/#/demos";
        } elseif ($lang === 'ro') {
            $aiReply = "Yeh requirement boht impressive hai! SoftNexa isko modern cloud architecture aur 100% Fixed-Price Guarantee ke sath tailor-make kar sakta hai. Kya aap batayenge ke isme kaunse main modules shamil karne hain?\n\nHumare live demos: https://softnexasolution.com/#/demos";
        } else {
            $aiReply = "This sounds like a high-impact initiative! SoftNexa can architect this with a scalable cloud stack under our 100% Fixed-Price Guarantee. What specific workflows or modules should we include in your scope blueprint?\n\nLive demos: https://softnexasolution.com/#/demos";
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
