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
   1. META WEBHOOK VERIFICATION CHALLENGE (GET) & GATEWAYS
   ------------------------------------------------------------ */
$isGateway = false;
$msgText = '';
$senderPhone = '';
$senderName = 'WhatsApp Client';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    if (isset($_GET['hub_mode']) || isset($_GET['hub.mode'])) {
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
    } elseif (isset($_GET['query']) || isset($_GET['text']) || isset($_GET['message'])) {
        $msgText     = trim((string)($_GET['query'] ?? $_GET['text'] ?? $_GET['message'] ?? ''));
        $senderPhone = preg_replace('/[^0-9]/', '', (string)($_GET['sender'] ?? $_GET['phone'] ?? '923355556290'));
        $senderName  = trim((string)($_GET['name'] ?? 'Client'));
        $isGateway   = true;
    } else {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

/* ------------------------------------------------------------
   2. INCOMING MESSAGES (POST)
   ------------------------------------------------------------ */
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$isGateway) {
    if (isset($_POST['query']) || isset($_POST['text']) || isset($_POST['message'])) {
        $msgText     = trim((string)($_POST['query'] ?? $_POST['text'] ?? $_POST['message'] ?? ''));
        $senderPhone = preg_replace('/[^0-9]/', '', (string)($_POST['sender'] ?? $_POST['phone'] ?? '923355556290'));
        $senderName  = trim((string)($_POST['name'] ?? 'Client'));
        $isGateway   = true;
    } elseif (!$data || !is_array($data)) {
        http_response_code(200);
        echo json_encode(['status' => 'ignored_no_json']);
        exit;
    }
}

if (!$isGateway && (!$data || !is_array($data))) {
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

// Check per-chat human takeover / pause
$pausedPhones = json_decode((string)setting('wa_paused_phones', '[]'), true);
if (!is_array($pausedPhones)) $pausedPhones = [];

// Meta WhatsApp Cloud API standard structure
$entry   = $data['entry'][0] ?? [];
$changes = $entry['changes'][0]['value'] ?? [];
$message = $changes['messages'][0] ?? null;
$contact = $changes['contacts'][0] ?? null;

$metadata         = $changes['metadata'] ?? [];
$receivedOnPhone  = preg_replace('/[^0-9]/', '', (string)($metadata['display_phone_number'] ?? ''));
$recipientPhoneId = (string)($metadata['phone_number_id'] ?? '');

// Support third-party or generic gateway payloads if not Meta native
if ($isGateway) {
    // Already extracted from GET / POST params
    $msgId = 'gw_' . bin2hex(random_bytes(8));
} elseif (!$message && isset($data['message']) && (isset($data['phone']) || isset($data['sender']))) {
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

// Command to pause / unpause AI directly from WhatsApp chat
$trimMsg = trim($msgText);
if (preg_match('/^#(pause|stop|mute|human|takeover)$/i', $trimMsg)) {
    if (!in_array($senderPhone, $pausedPhones)) {
        $pausedPhones[] = $senderPhone;
        set_setting('wa_paused_phones', json_encode(array_values($pausedPhones)));
    }
    send_meta_whatsapp_message($senderPhone, "🛑 *AI Agent Paused for this chat.*\nHuman mode is now active. You can chat manually without automated AI replies.\n\nType *#resume* to reactivate AI.", $recipientPhoneId);
    http_response_code(200);
    echo json_encode(['status' => 'chat_paused_by_command']);
    exit;
}
if (preg_match('/^#(resume|start|unpause|ai)$/i', $trimMsg)) {
    $pausedPhones = array_values(array_diff($pausedPhones, [$senderPhone]));
    set_setting('wa_paused_phones', json_encode($pausedPhones));
    send_meta_whatsapp_message($senderPhone, "🟢 *AI Agent Resumed for this chat.*\nSoftNexa AI will now handle incoming inquiries automatically.", $recipientPhoneId);
    http_response_code(200);
    echo json_encode(['status' => 'chat_resumed_by_command']);
    exit;
}

// If chat is currently paused by admin or command, do not auto-reply
if (in_array($senderPhone, $pausedPhones)) {
    http_response_code(200);
    echo json_encode(['status' => 'chat_paused_human_mode']);
    exit;
}

/* ------------------------------------------------------------
   3. RETRIEVE RECENT CHAT HISTORY FOR CONTEXT
   ------------------------------------------------------------ */
$recentHistory = [];
try {
    $histStmt = db()->prepare("SELECT direction, message FROM wa_messages WHERE phone = ? ORDER BY id DESC LIMIT 10");
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
$msgLower = mb_strtolower(trim($msgText));

// Detect Roman Urdu quick check for instant polite exits
$quickIsRomanUrdu = (bool)preg_match('/\b(nahi|nhe|nhi|chahiye|shukriya|theek|thik|bhai|ji|karo|mat|band|zaroorat|ap|aap|hum|mera|meri)\b/i', $msgLower);

// 4A. REJECTION / OPT-OUT GUARD ("Not interested", "No thanks", "Nahi chahiye", "Stop")
// Reply politely ONCE like a human and AUTO-PAUSE AI for this number forever!
$isRejection = (bool)preg_match('/\b(not interested|no thanks|no thank you|nahi chahiye|nhe chahiye|nhi chahiye|no need|not needed|don\'t message|dont message|do not message|stop messaging|unsubscribe|mat bhejo|band karo|wrong number|already have|not looking|no required|not required|stop|block|spam)\b/i', $msgLower);
if ($isRejection) {
    if (!in_array($senderPhone, $pausedPhones)) {
        $pausedPhones[] = $senderPhone;
        set_setting('wa_paused_phones', json_encode(array_values($pausedPhones)));
    }
    $byeMsg = $quickIsRomanUrdu
        ? "Koi masla nahi hai, aapke waqt ka boht shukriya! Allah aapke business mein barkat de. Future mein kabhi zaroorat ho to hum hazir hain. 🙏"
        : "No problem at all, thank you for your time! Wishing you great success with your business. Feel free to reach out anytime in the future. Have a wonderful day! 🙏";
    send_meta_whatsapp_message($senderPhone, $byeMsg, $recipientPhoneId);
    http_response_code(200);
    echo json_encode(['status' => 'opt_out_paused_politely']);
    exit;
}

// 4B. OTHER BUSINESS AUTO-GREETING / MENU GUARD (Prevent Bot-to-Bot loops!)
// e.g. Salon/Restaurant auto-replies with "Thank you for contacting...", "How can we help you?", "15000/-", "Call us:"
$isOtherBusinessBot = (bool)preg_match('/(thank you for contacting|thanks for contacting|how can we help you|please let us know how we can help|welcome to .*(salon|spa|restaurant|clinic|store)|our timings|for booking call|call us:|\/-\s*\n|15000\/-|10000\/-)/i', $msgText);
if ($isOtherBusinessBot) {
    // Log silently in dashboard without triggering an embarrassing bot-to-bot reply
    http_response_code(200);
    echo json_encode(['status' => 'ignored_other_business_auto_reply']);
    exit;
}

// 4C. SHORT HUMAN ACKNOWLEDGMENT GUARD ("ok", "thanks", "shukriya", "theek hai", "👍")
$isShortAck = (bool)preg_match('/^(ok|okay|k|kk|thanks|thank you|thx|ty|shukriya|jazakallah|theek hai|thik hai|alright|noted|fine|hmm|hmmm|sure|done|👍|👌|🙏)+[\.\!\s]*$/iu', $msgLower);
if ($isShortAck) {
    // Check if we already sent a closing thank-you recently — if so, stay silent like a human!
    $lastOutMsg = '';
    foreach (array_reverse($recentHistory) as $rh) {
        if ($rh['role'] === 'model') { $lastOutMsg = mb_strtolower($rh['content']); break; }
    }
    if (strpos($lastOutMsg, 'most welcome') !== false || strpos($lastOutMsg, 'shukriya') !== false || strpos($lastOutMsg, 'wonderful day') !== false) {
        http_response_code(200);
        echo json_encode(['status' => 'ack_silent_complete']);
        exit;
    }
    $ackReply = $quickIsRomanUrdu
        ? "Aapka boht shukriya! 😊 Kabhi bhi koi sawal ho to bila jhijhak message kar dein."
        : "You're most welcome! 😊 Feel free to reach out anytime if you have any questions.";
    send_meta_whatsapp_message($senderPhone, $ackReply, $recipientPhoneId);
    http_response_code(200);
    echo json_encode(['status' => 'short_ack_replied']);
    exit;
}

// Detect if client requested human handover
$isHumanRequest = (bool)preg_match('/\b(insaan|human|agent|representative|call me|phone pe baat|kisi se baat|direct call|baat karni hai|owner se|adnan se)\b/i', $msgLower);
if ($isHumanRequest) {
    if (!in_array($senderPhone, $pausedPhones)) {
        $pausedPhones[] = $senderPhone;
        set_setting('wa_paused_phones', json_encode(array_values($pausedPhones)));
    }
    $aiReply = "Ji bilkul! Main aapki chat hamare senior software solutions architect ko hand over kar raha hoon. Wo jald aapse direct rabta karenge. 🤝\n\nAap chahein to hamare direct number +92 335 5556290 par call bhi kar sakte hain.";
    send_meta_whatsapp_message($senderPhone, $aiReply, $recipientPhoneId);
    http_response_code(200);
    echo json_encode(['status' => 'human_handover_triggered']);
    exit;
}

$isUrduScript = (bool)preg_match('/[\x{0600}-\x{06FF}]/u', $msgText);
$romanUrduClues = [
    'kya', 'hai', 'hain', 'mein', 'hum', 'mujhe', 'chahiye', 'karna', 'kese', 'kaise',
    'bataen', 'batao', 'boht', 'shukriya', 'rabta', 'kitna', 'kharcha', 'kitne', 'aap',
    'mai', 'me', 'din', 'kab', 'kitnay', 'milega', 'milegi', 'hoga', 'hogi', 'banao',
    'bana', 'banaen', 'lekin', 'acha', 'theek', 'ji', 'g', 'bhi', 'pe', 'par', 'se',
    'ko', 'yeh', 'ye', 'woh', 'wo', 'krna', 'skte', 'skta', 'ap', 'hmaray', 'hamara'
];

$isRomanUrdu = false;
foreach ($romanUrduClues as $w) {
    if (preg_match('/\b' . preg_quote($w, '/') . '\b/i', $msgLower)) {
        $isRomanUrdu = true;
        break;
    }
}

// Preserve Roman Urdu if previous history had Roman Urdu
if (!$isRomanUrdu && !$isUrduScript) {
    foreach ($recentHistory as $h) {
        if ($h['role'] === 'user') {
            $hLower = mb_strtolower((string)$h['content']);
            foreach ($romanUrduClues as $w) {
                if (preg_match('/\b' . preg_quote($w, '/') . '\b/i', $hLower)) {
                    $isRomanUrdu = true;
                    break 2;
                }
            }
        }
    }
}

$lang = 'en';
if ($isUrduScript) $lang = 'ur';
elseif ($isRomanUrdu) $lang = 'ro';
if ($isUrduScript) $lang = 'ur';
elseif ($isRomanUrdu) $lang = 'ro';

/* ------------------------------------------------------------
   5. AI RESPONSE GENERATION (GEMINI 1.5 FLASH)
   ------------------------------------------------------------ */
$geminiKey = setting('gemini_api_key', getenv('GEMINI_API_KEY') ?: '');
$aiReply = '';
$llmSucceeded = false;

$isMusaMessage = ($receivedOnPhone === '923052148006' || stripos($msgText, 'Musa Pick') !== false || stripos($msgText, 'OFFICE PICK & DROP SERVICE') !== false);

if ($isMusaMessage) {
    $systemPrompt = "You are 'Sarah', the polite, reassuring, and highly professional female customer coordinator for 'Musa Pick & Drop Services' in Karachi.
You communicate warmly, respectfully, and conversationally in friendly Pakistani Roman Urdu.

CRITICAL SERVICE SPECIFICATIONS:
- Brand: Musa Pick & Drop Services (Karachi)
- Target Audience: EXCLUSIVELY for Female Corporate Staff / Working Ladies. Strictly NO male passengers.
- Vehicle Experience: Fully Air-Conditioned (AC Alto Car) — clean, private, comfortable, maximum 3-4 passengers, door-to-door. (NOT a crowded commercial van!).
- Dedicated Direct Route Corridor:
  * Pickups (Home): North Karachi, FB Area (including Ayesha Manzil & nearby blocks), and Nazimabad.
  * Transit & Drops (Office): Via Numaish, directly to Clifton and Defence (DHA).
  * STRICT EXCLUSION: Shahrah-e-Faisal is strictly NOT in our route (avoid traffic delays).
- Available Office Shift Timings:
  1) Morning Shift: 08:00 AM to 05:00 PM
  2) Second Shift: 09:00 AM to 06:30 PM
- Monthly Package: Rs. 22,000 to Rs. 25,000 per seat (Fixed monthly rate for premium door-to-door AC Alto car pool).
- Safety & Drivers: 100% verified, courteous, family-trusted drivers.

BEHAVIOR & CONVERSATION RULES:
1. Always maintain a warm, respectful sisterly tone ('G Baji', 'Assalam-o-Alaikum', 'JazakAllah').
2. When the ad prefill message arrives, welcome them warmly, confirm AC Alto for female staff, and ask for:
   - 1. Pick-up Area & Block (Ghar)
   - 2. Drop-off Location (Clifton ya DHA Office)
   - 3. Shift Timing (8 to 5 ya 9 to 6:30)
   - 4. Joining Date (Kab se start karna hai)
3. If they ask about Shahrah-e-Faisal, explain that the route avoids it to reach on time.
4. If they ask about charges, explain the value of door-to-door AC Alto car vs public van.
5. Format cleanly with friendly emojis (🚗, 🌸, ✨, 📍).";
} else {
    $firstName = trim(explode(' ', (string)$senderName)[0] ?? '');
    if (strtolower($firstName) === 'whatsapp' || strtolower($firstName) === 'client' || $firstName === '') $firstName = '';

    $systemPrompt = "You are Adnan's team consultant at SoftNexa Solutions (softnexasolution.com), based in Karachi, Pakistan, texting directly with a client on WhatsApp.

HOW REAL HUMANS TEXT ON WHATSAPP (FOLLOW STRICTLY):
1. Write like a REAL, warm, friendly Pakistani/international human consultant — SHORT and NATURAL (2 to 4 sentences max). NEVER write long corporate essays or robotic bullet-point brochures unless the client specifically asks for a list of services!
2. NEVER use double asterisks (**text**). On WhatsApp, only use single *text* if you want bold, and use at most 1-2 emojis per message.
3. READ THE CONVERSATION HISTORY CAREFULLY: Never repeat yourself. Answer the client's exact last message directly and naturally.
4. If the client mentions their business or idea (e.g. leather jackets, beauty salon, clinic, restaurant, real estate, app, ERP), react warmly to THAT specific business first, give 1 practical helpful suggestion, and ask ONE short natural question to move forward.
5. Only when they say a simple greeting ('Hi', 'Salam') or ask 'what services do you offer?', briefly mention our 4 core services: AI WhatsApp Bots, Custom Websites & E-Commerce, Custom ERP/CRM/POS, and Mobile Apps/Marketing — then ask what they're looking to build.
6. Delivery timeline for all projects: 2 to 3 weeks with transparent milestone scope. Never quote fixed PKR/USD prices — explain we share a free 48-hour scope blueprint & quote after understanding their requirements.
7. Live demos: https://softnexasolution.com/#/demos | Call booking: https://softnexasolution.com/#/schedule | Direct: +92 335 5556290.
8. Match their exact language:
   - Roman Urdu: Warm, natural everyday Pakistani Roman Urdu ('G bilkul', 'Boht zabardast', 'Aap batayein').
   - Urdu Script: Polite, natural Urdu.
   - English: Friendly, concise, human English.";
}

$geminiKey = trim((string)$geminiKey);
if ($geminiKey !== '') {
    // Check cached working model first for instant 1-2s replies
    $cachedModel = trim((string)setting('gemini_working_model', ''));
    $candidateModels = [];
    if ($cachedModel !== '') $candidateModels[] = $cachedModel;

    // Fast modern Gemini models
    $defaults = [
        'v1beta/models/gemini-2.5-flash',
        'v1beta/models/gemini-2.0-flash',
        'v1beta/models/gemini-2.5-flash-lite',
        'v1/models/gemini-2.0-flash',
        'v1/models/gemini-1.5-flash'
    ];
    foreach ($defaults as $dm) {
        if (!in_array($dm, $candidateModels)) $candidateModels[] = $dm;
    }

    // Build clean multi-turn history (last 8 messages for genuine human memory)
    $contents = [];
    $contents[] = ['role' => 'user', 'parts' => [['text' => $systemPrompt]]];
    $contents[] = ['role' => 'model', 'parts' => [['text' => "Understood. I will text naturally, warmly, and concisely like a real human consultant on WhatsApp."]]];

    foreach (array_slice($recentHistory, -8) as $h) {
        $txt = trim((string)$h['content']);
        if ($txt !== '') {
            $contents[] = ['role' => $h['role'], 'parts' => [['text' => $txt]]];
        }
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $msgText]]];

    $payload = json_encode([
        'contents' => $contents,
        'generationConfig' => [
            'temperature'     => 0.72,
            'maxOutputTokens' => 280
        ]
    ]);

    $tryModels = function($modelsList) use ($geminiKey, $payload, &$aiReply, &$llmSucceeded, $cachedModel) {
        foreach ($modelsList as $modelEndpoint) {
            try {
                $ch = curl_init("https://generativelanguage.googleapis.com/{$modelEndpoint}:generateContent?key=" . urlencode($geminiKey));
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 6,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
                ]);
                $res = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && $res) {
                    $json = json_decode($res, true);
                    $rawText = trim((string)($json['candidates'][0]['content']['parts'][0]['text'] ?? ''));
                    if ($rawText !== '') {
                        $aiReply = $rawText;
                        $llmSucceeded = true;
                        if ($modelEndpoint !== $cachedModel) {
                            try { set_setting('gemini_working_model', $modelEndpoint); } catch (Exception $e) {}
                        }
                        return true;
                    }
                }
            } catch (Exception $e) {}
        }
        return false;
    };

    // 1. Try cached + fast modern models
    if (!$tryModels($candidateModels)) {
        // 2. Auto-discover available models for this key via Google ListModels
        $chL = curl_init("https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($geminiKey));
        curl_setopt_array($chL, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $listRes = curl_exec($chL);
        $listCode = curl_getinfo($chL, CURLINFO_HTTP_CODE);
        curl_close($chL);
        if ($listCode === 200 && $listRes) {
            $lj = json_decode($listRes, true);
            $disc = [];
            foreach (($lj['models'] ?? []) as $m) {
                if (in_array('generateContent', $m['supportedGenerationMethods'] ?? [])) {
                    $ep = 'v1beta/' . $m['name'];
                    if (!in_array($ep, $candidateModels)) $disc[] = $ep;
                }
            }
            if (!empty($disc)) {
                $tryModels(array_slice($disc, 0, 3));
            }
        }
    }
}

/* ------------------------------------------------------------
   6. SMART HUMAN CONSULTANT CONVERSATIONAL ENGINE (CATEGORY & MULTI-TURN TRAINED)
   ------------------------------------------------------------ */
if (!$llmSucceeded || empty($aiReply)) {
    // Combine recent history text to remember which category we are discussing
    $historyContext = '';
    foreach ($recentHistory as $rh) {
        $historyContext .= ' ' . mb_strtolower((string)$rh['content']);
    }

    // Core Intent & Category Flags
    $isInterested       = (bool)preg_match('/\b(interested|yes|yeah|yep|sure|tell me more|details|detail|batao|batayein|bataein|ji|haan|han|hanji|share|send|info|information|how it works|kaise kaam|kese kam)\b/i', $msgLower);
    $isCallReady        = (bool)preg_match('/\b(schedule|meeting|google meet|zoom|call me|let\'s talk|lets talk|tomorrow|today|kal|aaj|book a call|time slot|available at|pm|am)\b/i', $msgLower);
    $isBranchOrCount    = (bool)preg_match('/\b(single|one|two|three|multi|multiple|branch|branches|location|locations|products|items|skus|users|staff|doctors|vehicles|students)\b|\b\d{1,5}\b/i', $msgLower);
    $isObjection        = (bool)preg_match('/\b(mehnga|mehenga|zyada hai|ziada hai|sasta|discount|kam karo|kam kar|soch kar|soch ke|baad mein|bad mein|aglay maheenay|next month|too expensive|expensive|high price|budget nahi|budget issue|cheaper)\b/i', $msgLower);
    $isFollowUp         = (bool)preg_match('/\b(are you there|active ho|reply karo|sun rahe ho|kahan ho|sun rha|hello\?|koi hai|response do|jawab do)\b/i', $msgLower);
    $isTimeline         = (bool)preg_match('/\b(kitne din|kitnay din|din|timeline|kab tak|kitna time|duration|delivery time|urgent|kab live|kab milega|kab milegi)\b/i', $msgLower);
    $isPrice            = (bool)preg_match('/\b(price|cost|charges|rate|kharcha|kitna|pese|paise|fees|budget|quote|estimate|package|packages)\b/i', $msgLower);
    $isAiBot            = (bool)preg_match('/\b(whatsapp bot|ai bot|chatbot|chat bot|automation|auto reply|ai agent|whatsapp automation)\b/i', $msgLower);
    $isSalonGym         = (bool)preg_match('/\b(salon|saloon|parlor|parlour|spa|beauty|makeup|bridal|hair|gym|fitness|workout)\b/i', $msgLower);
    $isRestaurant       = (bool)preg_match('/\b(restaurant|cafe|coffee|bakery|pizza|burger|biryani|kitchen|catering|food delivery|menu)\b/i', $msgLower);
    $isDoctorClinic     = (bool)preg_match('/\b(clinic|doctor|dental|dentist|teeth|hospital|patient|medical|health|pharma|derma)\b/i', $msgLower);
    $isRealEstate       = (bool)preg_match('/\b(real estate|property|plots|plot|builder|housing|flats|apartments|brokerage)\b/i', $msgLower);
    $isSchool           = (bool)preg_match('/\b(school|academy|institute|college|coaching|tuition|education|students|campus)\b/i', $msgLower);
    $isLogistics        = (bool)preg_match('/\b(logistics|cargo|freight|transport|courier|shipping|fleet|delivery tracking)\b/i', $msgLower);
    $isAuto             = (bool)preg_match('/\b(car rental|rent a car|showroom|motors|garage|workshop|automotive)\b/i', $msgLower);
    $isProductBusiness  = (bool)preg_match('/\b(leather|clothing|clothes|shoes|jewelry|bags|cosmetics|watches|perfumes|fashion|garments|products|items|saman|bechna|sell|selling|brand|abaya)\b/i', $msgLower);
    $isEcommerce        = (bool)preg_match('/\b(ecommerce|e-commerce|online store|online shop|daraz|shopify|store|cart)\b/i', $msgLower);
    $isDigitalMarketing = (bool)preg_match('/\b(marketing|digital marketing|social media|facebook ads|meta ads|instagram|tiktok|seo|ad campaign|leads|run ads|promotion|smm)\b/i', $msgLower);
    $isERP              = (bool)preg_match('/\b(erp|pos|inventory|crm|warehouse|stock|accounting|billing|invoicing|distribution)\b/i', $msgLower);
    $isApp              = (bool)preg_match('/\b(app|mobile|android|ios|flutter|playstore|apk)\b/i', $msgLower);
    $isWebsite          = (bool)preg_match('/\b(website|web site|site|webpage|web page|landing page|wordpress|web portal|web dev)\b/i', $msgLower);
    $isOfficeContact    = (bool)preg_match('/\b(office|address|kahan|location|call|phone|number|milna|visit|shahrah|karachi|rabta)\b/i', $msgLower);
    $isDemo             = (bool)preg_match('/\b(demo|sample|portfolio|previous work|link|check|live)\b/i', $msgLower);
    $isGreeting         = (bool)preg_match('/\b(hi|hello|hey|assalam|salam|aoa|kese ho|kaise ho|keise|kaisa|kese hain)\b/i', $msgLower);
    $isServicesAsk      = (bool)preg_match('/\b(services|service|kya karte|kia kartay|kya kaam|what do you do|offer)\b/i', $msgLower);

    // 0. Auto-Deal Closer (Objection / Price Hesitation Handler)
    if ($isObjection) {
        if ($lang === 'ro') {
            $aiReply = "Aapki baat bilkul valid hai, har business ke liye ROI sab se important hota hai! 💼✨\n\nMarket mein saste templates ya freelancers shuru mein kam charge karte hain, lekin slow speed, bugs aur weak security ki wajah se baad mein 3 guna zyada kharcha aur waqt zaya ho jata hai.\n\nSoftNexa par hum aapka risk 0% rakhte hain:\n✅ 100% Fixed-Price Guarantee (koi hidden charges nahi)\n✅ Milestone Delivery (aap pehle live module verify karein, tab payment release karein)\n✅ Free 48-Hour Tailored Scope Blueprint\n\nKya hum kal 10-minute ki quick discovery call par aapke budget ke mutabiq phased roadmap plan kar lein?";
        } elseif ($lang === 'ur') {
            $aiReply = "آپ کی بات بالکل درست ہے، ہر کاروبار کے لیے بجٹ اور معیار دونوں اہم ہیں۔ 💼✨\n\nسافٹ نیکسا میں ہم آپ کا رسک صفر رکھتے ہیں:\n✅ 100% فکسڈ پرائس گارنٹی\n✅ مائل اسٹون ڈیلیوری (ہر فیز لائیو دیکھ کر ادائیگی کریں)\n✅ مفت 48 گھنٹے کا اسکوپ بلیو پرنٹ\n\nکیا کل 10 منٹ کی کال پر آپ کے بجٹ کے مطابق فیزڈ پلان ڈسکس کر لیں؟";
        } else {
            $aiReply = "I completely respect keeping a close eye on budget! 💼✨\n\nCheap templates often end up costing 3x more in bugs, slow checkout speeds, and rewrites. At SoftNexa, we de-risk your investment completely:\n✅ 100% Fixed-Price Guarantee (zero hidden hourly bills)\n✅ Milestone-Gated Delivery (you only pay as you verify working modules)\n✅ Complimentary 48-Hour Scope Blueprint\n\nWould you be open to a quick 10-minute call tomorrow to structure a phased plan that fits your goals?";
        }
    }
    // 1. Follow-up Check ("Are you there?")
    elseif ($isFollowUp) {
        if ($lang === 'ro') {
            $aiReply = "Ji bilkul, main yahan mojood hoon aur aapki baat sun raha hoon! 😊 Batayein aap apne business ke liye kis cheez par kaam karwana chahte hain?";
        } else {
            $aiReply = "Yes, I'm right here and ready to help! 😊 Tell me what you'd like to build or automate for your business.";
        }
    }
    // 2. Client Ready for Call / Meeting Scheduling
    elseif ($isCallReady) {
        if ($lang === 'ro') {
            $aiReply = "Zabardast! Aap apni marzi ka 10-minute Google Meet slot seedha yahan select kar sakte hain (foran confirmation mil jayegi):\n📅 https://softnexasolution.com/#/schedule\n\nYa agar aap chahein to mujhe yahin apna suitable time bata dein, main Adnan ke sath aapki call confirm kar deta hoon! 🤝";
        } else {
            $aiReply = "Wonderful! You can pick your preferred 10-minute Google Meet slot directly here for instant confirmation:\n📅 https://softnexasolution.com/#/schedule\n\nOr simply reply with your preferred time right here and I'll lock it in with our senior solutions architect! 🤝";
        }
    }
    // 3. Pricing Question (Tailored & Non-Generic)
    elseif ($isPrice) {
        if ($lang === 'ro') {
            $aiReply = "SoftNexa **100% Fixed-Price Guarantee** par kaam karta hai (zero hidden charges ya hourly billing!). 💼✨\n\nChunkay har system aapke business ke exact features aur scale ke mutabiq custom build hota hai, hum pehle aapki requirements samajh kar ek **Free 48-Hour Scope Blueprint** aur clear fixed-price milestone quote dete hain.\n\nAap bas 2 cheezein bata dein:\n1️⃣ Aapka business kis category ka hai (ya kitni branches/products hain)?\n2️⃣ Kya aapko sirf Website chahiye ya sath 24/7 WhatsApp AI Bot bhi?";
        } elseif ($lang === 'ur') {
            $aiReply = "سافٹ نیکسا 100% فکسڈ پرائس گارنٹی پر کام کرتا ہے (کوئی پوشیدہ اخراجات نہیں)۔ 💼✨\n\nہم آپ کی ضروریات کے مطابق کسٹم اسکوپ بلیو پرنٹ اور فکسڈ کوٹیشن فراہم کرتے ہیں۔ براہ کرم اپنے کاروبار کی نوعیت اور مطلوبہ فیچرز بتا دیں۔";
        } else {
            $aiReply = "SoftNexa operates under a strict **100% Fixed-Price Guarantee** with milestone delivery (zero surprise hourly fees). 💼✨\n\nBecause every platform is custom-tailored to your workflows, we prepare a complimentary **48-Hour Scope Blueprint** with an exact fixed quote.\n\nTo put that together quickly: how many branches/products do you operate, and would you also like our 24/7 WhatsApp AI Bot included?";
        }
    }
    // 4. Timeline Question
    elseif ($isTimeline) {
        if ($lang === 'ro') {
            $aiReply = "SoftNexa par har project (Website, E-Commerce Store, 24/7 AI WhatsApp Bot, Mobile App ya Custom Software) **2 se 3 weeks** mein guaranteed on-time live deliver hota hai (weekly milestone demos ke sath). ⚡✨\n\nAapko kab tak launch karna hai?";
        } else {
            $aiReply = "At SoftNexa, every project — whether a Website, E-Commerce Store, 24/7 AI WhatsApp Bot, Mobile App, or Custom ERP — is delivered in **2 to 3 weeks** with weekly staging demos and guaranteed on-time delivery. ⚡✨\n\nWhat target launch date are you aiming for?";
        }
    }
    // 5. Category-Specific Handlers (10 Industries + 4 Core Services)
    elseif ($isAiBot) {
        if ($lang === 'ro') {
            $aiReply = "Zabardast choice! 🤖✨ Hamara **24/7 AI WhatsApp Sales & Booking Agent** bilkul ek smart human representative ki tarah aapke clients ko Roman Urdu, Urdu aur English mein instant reply karta hai:\n\n✅ 2-Second Instant Lead Response (raat 2 baje bhi)\n✅ Automatic Appointment / Order Booking\n✅ Live Admin Dashboard + 1-Click Human Takeover\n\nAap kis business ke liye yeh WhatsApp AI Bot lagwana chahte hain?";
        } else {
            $aiReply = "Great choice! 🤖✨ Our **24/7 AI WhatsApp Sales & Booking Agent** handles customer inquiries in 2 seconds across English, Urdu & Arabic — qualifying leads, booking appointments, and syncing directly to your CRM with 1-click human takeover.\n\nWhat type of business would you like to deploy this bot for?";
        }
    } elseif ($isSalonGym) {
        if ($lang === 'ro') {
            $aiReply = "Boht zabardast! Salons, Spas aur Fitness studios ke liye hum stylish booking websites aur **24/7 WhatsApp Appointment Bot** banate hain: 💅✨\n\n✅ Bridal, Hair & Skincare Packages Menu\n📅 Automatic Date & Time Slot Booking\n🔔 Automated WhatsApp Reminders (no-shows 70% kam)\n\nAapki single branch hai ya multiple branches? Taake main aapke liye tailored Scope Blueprint tayyar kar sakoon!";
        } else {
            $aiReply = "We build sleek booking websites and **24/7 WhatsApp Appointment Bots** specifically for Salons, Spas & Gyms: 💅✨\n\n✅ Interactive Service & Bridal Packages Menu\n📅 Automated Slot Booking & WhatsApp Reminders (70% fewer no-shows)\n\nDo you operate a single location or multiple branches?";
        }
    } elseif ($isRestaurant) {
        if ($lang === 'ro') {
            $aiReply = "Zabardast! Restaurants aur Cafes ke liye hum **Commission-Free Direct Ordering Website + WhatsApp Menu Bot** banate hain: 🍽️✨\n\n✅ Delivery apps ka 25–30% commission bachayein\n✅ Digital QR Menu + Direct Online Ordering & Table Booking\n✅ Instant WhatsApp Order Alerts (kitchen aur customer dono ko)\n\nAapki kitni branches hain aur kya aap apni khud ki delivery handle karte hain?";
        } else {
            $aiReply = "For Restaurants & Cafes, we build **Commission-Free Direct Ordering Websites + WhatsApp Menu Bots** so you save 25–30% on delivery app commissions and receive instant WhatsApp order alerts. 🍽️✨\n\nHow many branches do you operate, and do you handle in-house delivery?";
        }
    } elseif ($isDoctorClinic) {
        if ($lang === 'ro') {
            $aiReply = "Zabardast! Clinics aur Doctors ke liye hum complete **Smart Clinic Booking Portal + WhatsApp Patient Reminder Bot** banate hain: 🩺✨\n\n✅ Online Doctor Appointment & Slot Booking\n🔔 Automated WhatsApp Patient Reminders (missed appointments 70% kam)\n📋 Digital Patient History & Billing Records\n\nAapke clinic mein kitne doctors hain aur kya multi-branch setup hai?";
        } else {
            $aiReply = "We engineer **Smart Clinic Booking Portals & WhatsApp Patient Reminder Bots** for medical and dental clinics — cutting no-shows by 70% and organizing digital patient records. 🩺✨\n\nHow many doctors or branches does your clinic operate?";
        }
    } elseif ($isRealEstate) {
        if ($lang === 'ro') {
            $aiReply = "Real Estate agencies aur builders ke liye hum **EstateFlow CRM + 24/7 WhatsApp Property Bot** banate hain: 🏢✨\n\n✅ Clients ko 30 seconds mein project PDF brochures & floor plans auto-deliver\n✅ Automatic site visit / viewing booking\n✅ Agent Lead Lock Vault CRM (koi lead leak nahi hoti)\n\nKya aapko apni brokerage agency ke liye portal chahiye ya kisi specific housing project ke liye?";
        } else {
            $aiReply = "For Real Estate brokerages & developers, we build **EstateFlow CRM & 24/7 WhatsApp Property Bots** that auto-deliver off-plan PDF brochures within 30 seconds and book viewing slots automatically. 🏢✨\n\nAre you looking for an agency-wide CRM or a dedicated project showcase portal?";
        }
    } elseif ($isSchool) {
        if ($lang === 'ro') {
            $aiReply = "Schools aur Academies ke liye hum **Complete Management Portal + Parents WhatsApp Alert System** banate hain: 🏫✨\n\n✅ Automated WhatsApp Fee Challan Reminders\n✅ Daily Student Attendance & Result Alerts\n✅ Online Admission Forms & Teacher Dashboard\n\nAapke institute mein taqreeban kitne students hain?";
        } else {
            $aiReply = "For Schools & Academies, we build modern management portals with **Automated WhatsApp Fee Reminders, Attendance Alerts & Online Admissions**. 🏫✨\n\nRoughly how many students or campuses do you manage?";
        }
    } elseif ($isLogistics) {
        if ($lang === 'ro') {
            $aiReply = "Logistics aur Cargo companies ke liye hum **FleetTrack ERP & Driver Mobile App** banate hain: 🚚✨\n\n✅ Paper challans 100% digital (Digital POD)\n✅ Live GPS Driver Tracking & Dispatch Dashboard\n✅ Customers ko automated WhatsApp shipment status alerts\n\nAapki fleet mein taqreeban kitni vehicles hain?";
        } else {
            $aiReply = "For Logistics & Freight companies, we engineer **FleetTrack ERPs & Driver Mobile Apps** with digital POD and real-time WhatsApp shipment tracking for clients. 🚚✨\n\nHow many vehicles or hubs are in your fleet?";
        }
    } elseif ($isAuto) {
        if ($lang === 'ro') {
            $aiReply = "Auto Showrooms, Workshops aur Car Rentals ke liye hum **Online Booking Portal + 24/7 WhatsApp Inquiry Bot** banate hain jo clients ko foran vehicle availability, rates aur booking slots deta hai. 🚗✨\n\nKya aapko car rental booking chahiye ya showroom/workshop management system?";
        } else {
            $aiReply = "For Automotive Showrooms, Workshops & Car Rentals, we build online booking portals and **24/7 WhatsApp AI Inquiry Bots** that auto-share availability and book slots instantly. 🚗✨\n\nIs this for car rentals, a showroom, or workshop bookings?";
        }
    } elseif ($isProductBusiness || $isEcommerce) {
        if ($lang === 'ro') {
            $aiReply = "Boht zabardast! Aapke brand ke liye hum ultra-fast, high-converting **E-Commerce Online Store** build kar sakte hain: 🛍️✨\n\n✅ Modern Mobile-First Product Catalog & Filters\n💳 1-Click Checkout (Cards, JazzCash, Easypaisa, COD / Tabby)\n📦 Automated WhatsApp Order Confirmation & Tracking Alerts\n\nAapke paas taqreeban kitne products honge aur kya aapko sath WhatsApp AI Sales Bot bhi chahiye?";
        } else {
            $aiReply = "Excellent! We engineer ultra-fast, mobile-first **E-Commerce Stores** with 1-click checkout (Cards, COD, Local Wallets/Tabby) and automated WhatsApp order confirmation & cart recovery. 🛍️✨\n\nRoughly how many products are in your catalog?";
        }
    } elseif ($isDigitalMarketing) {
        if ($lang === 'ro') {
            $aiReply = "G bilkul! Digital & Performance Marketing SoftNexa ki core growth service hai: 🚀📈\n\n🎯 Targeted Meta & Google Ads (High-ROAS sales & verified leads)\n🎬 Viral TikTok & Instagram Reels Production\n📅 Complete Monthly Social Media Management & SEO\n\nAap kis business/brand ke liye leads ya online sales barhana chahte hain?";
        } else {
            $aiReply = "Absolutely! Performance Marketing is a core growth service at SoftNexa: 🚀📈\n\n🎯 High-ROAS Meta & Google Ad Campaigns\n🎬 Viral Reels/TikTok Scripts & Creative Management\n🔍 SEO & Local Search Ranking\n\nWhat business are you scaling, and are you targeting leads or direct online sales?";
        }
    } elseif ($isERP) {
        if ($lang === 'ro') {
            $aiReply = "SoftNexa ka custom **ERP / CRM / POS** aapki multi-branch inventory, billing, accounting aur live reporting ko ek single cloud dashboard par le aata hai. 📊✨\n\nAapki total kitni branches ya active staff users honge?";
        } else {
            $aiReply = "We engineer custom **ERP, CRM & POS platforms** covering multi-branch inventory, automated billing, and live executive dashboards. 📊✨\n\nHow many branches and active users will use the system?";
        }
    } elseif ($isApp) {
        if ($lang === 'ro') {
            $aiReply = "Hum iOS aur Android ke liye high-performance **Flutter Mobile Apps** develop karte hain 📱 (offline support, push notifications aur payment gateways ke sath).\n\nKya aapka app concept/features ready hain ya scratch se prototype design karna hai?";
        } else {
            $aiReply = "We build high-performance **iOS & Android Mobile Apps (Flutter)** with push notifications, offline sync, and payment gateways. 📱\n\nDo you have a feature list ready, or should we design the prototype from scratch?";
        }
    } elseif ($isWebsite) {
        if ($lang === 'ro') {
            $aiReply = "G bilkul! Website development SoftNexa ki core specialization hai. 💻✨\n\nHum 2 se 3 weeks mein high-speed, mobile-responsive websites deliver karte hain (Corporate Portfolio, Booking Portal ya E-Commerce Store).\n\nAapka business kis category ka hai taake main uske mutabiq best solution aur live demo suggest kar sakoon?";
        } else {
            $aiReply = "Absolutely! Custom high-speed websites are our core expertise at SoftNexa (delivered in 2 to 3 weeks). 💻✨\n\nWhat industry is your business in so I can suggest the best layout and features?";
        }
    } elseif ($isOfficeContact) {
        if ($lang === 'ro') {
            $aiReply = "Aap hamari senior team se direct connect kar sakte hain:\n\n📍 Location: Karachi, Pakistan\n📞 Direct Call / WhatsApp: +92 335 5556290\n✉️ Email: info@softnexasolution.com\n🌐 Website: https://softnexasolution.com\n\nKya aap 10-minute Google Meet call schedule kar dein?";
        } else {
            $aiReply = "You can connect directly with our senior team:\n📍 Location: Karachi, Pakistan\n📞 Direct Call / WhatsApp: +92 335 5556290\n✉️ Email: info@softnexasolution.com\n\nWould you like to schedule a 10-minute Google Meet call?";
        }
    } elseif ($isDemo) {
        if ($lang === 'ro') {
            $aiReply = "Aap hamare live interactive software prototypes yahan khud test kar sakte hain:\n🌐 https://softnexasolution.com/#/demos\n\nInme se jo demo aapke business se match kare wo batayein — hum usay 100% aapke brand ke mutabiq customize kar sakte hain!";
        } else {
            $aiReply = "You can test our live interactive prototypes directly here:\n🌐 https://softnexasolution.com/#/demos\n\nLet me know which demo matches your vision and we can customize it 100% for your brand!";
        }
    }
    // 6. Client Replied "Interested" / "Yes" / "Details" (from our Lead Scout pitch!)
    elseif ($isInterested) {
        $detectedCat = 'your business';
        if (strpos($historyContext, 'salon') !== false || strpos($historyContext, 'spa') !== false) $detectedCat = 'your salon';
        elseif (strpos($historyContext, 'clinic') !== false || strpos($historyContext, 'dental') !== false) $detectedCat = 'your clinic';
        elseif (strpos($historyContext, 'restaurant') !== false || strpos($historyContext, 'cafe') !== false) $detectedCat = 'your restaurant';
        elseif (strpos($historyContext, 'real estate') !== false || strpos($historyContext, 'property') !== false) $detectedCat = 'your real estate brokerage';
        elseif (strpos($historyContext, 'store') !== false || strpos($historyContext, 'commerce') !== false) $detectedCat = 'your online store';

        if ($lang === 'ro') {
            $aiReply = "Boht shukriya interest show karne ka! 😊✨\n\nHum {$detectedCat} ke liye ek **Free 48-Hour Scope Blueprint** aur fixed-price roadmap tayyar kar sakte hain.\n\nBas 2 choti baatein bata dein:\n1️⃣ Aapki kitni branches/locations hain?\n2️⃣ Kya aap kal 10-minute ki quick Google Meet call par live demo dekhna chahenge, ya details yahin WhatsApp par share kar doon?\n\n📅 Instant Call Slot: https://softnexasolution.com/#/schedule";
        } else {
            $aiReply = "Awesome, glad you're interested! 😊✨\n\nWe'd love to put together a complimentary **48-Hour Scope Blueprint** and fixed-price plan tailored specifically for {$detectedCat}.\n\nJust 2 quick questions:\n1️⃣ Do you operate a single location or multiple branches?\n2️⃣ Would you prefer a quick 10-minute Google Meet walkthrough, or should I share the breakdown right here on WhatsApp?\n\n📅 Book 10-Min Call: https://softnexasolution.com/#/schedule";
        }
    }
    // 7. Follow-Up Answer (Client gave branch count / product count / number!)
    elseif ($isBranchOrCount) {
        if ($lang === 'ro') {
            $aiReply = "Perfect, note kar liya! ✅ Is scale ke hisaab se hum aapke liye boht clean aur fast system **100% Fixed-Price Guarantee** par build kar sakte hain.\n\nAglay step ke liye aap 10-minute ka quick Google Meet slot yahan select kar lein taake hamare senior architect aapko live demo aur exact fixed quote de sakein:\n📅 https://softnexasolution.com/#/schedule\n\nYa agar aap chahein to mujhe apna email bata dein, main short proposal bhej deta hoon!";
        } else {
            $aiReply = "Got it, noted! ✅ For that scale, we can architect a super clean, fast system under our **100% Fixed-Price Guarantee**.\n\nAs a quick next step, feel free to pick a 10-minute Google Meet slot below so our senior architect can walk you through a tailored blueprint and fixed quote:\n📅 https://softnexasolution.com/#/schedule\n\nOr reply with your email and I'll send over a short tailored proposal!";
        }
    }
    // 8. Greeting or General Services Inquiry -> SoftNexa Top 4 Core Services
    elseif ($isGreeting || $isServicesAsk) {
        if ($lang === 'ur') {
            $aiReply = "وعلیکم السلام! سافٹ نیکسا میں خوش آمدید۔ 👋\n\nہم 100% فکسڈ پرائس گارنٹی پر کاروبار کے لیے یہ 4 اہم سروسز فراہم کرتے ہیں:\n\n🤖 1. 24/7 AI واٹس ایپ سیلز اور بکنگ بوٹ\n🛍️ 2. جدید ویب سائٹس اور ای کامرس اسٹورز\n📊 3. کسٹم ERP، CRM اور POS سافٹ ویئر\n📱 4. موبائل ایپس (iOS/Android) اور ڈیجیٹل مارکیٹنگ\n\nآپ اپنے کاروبار کے لیے کس چیز پر کام کروانا چاہتے ہیں؟ تفصیل بتائیں تاکہ میں آپ کو بہترین گائیڈ کر سکوں!";
        } elseif ($lang === 'ro') {
            $aiReply = "Walaikum Assalam! SoftNexa Solutions mein khush aamdeed. 👋\n\nHum **100% Fixed-Price Guarantee** par businesses ko grow karne ke liye 4 core services dete hain:\n\n🤖 **24/7 AI WhatsApp Sales & Booking Bot** (raat ko bhi clients auto-handle)\n🛍️ **Modern Websites & E-Commerce Stores** (Cards/JazzCash/COD + WA Alerts)\n📊 **Custom ERP, CRM & POS Software** (Stock, Billing & Accounts)\n📱 **Mobile Apps (iOS/Android) & Digital Marketing** (Meta/Google Ads + SEO)\n\nAap apne business ke liye kis cheez ke baare mein discuss karna chahte hain? Thora sa idea batayein taake main aapke mutabiq best solution suggest kar sakoon!";
        } else {
            $aiReply = "Hello and welcome to SoftNexa Solutions! 👋\n\nWe help businesses scale faster under our **100% Fixed-Price Guarantee** across 4 core solutions:\n\n🤖 **24/7 AI WhatsApp Sales & Booking Bots**\n🛍️ **High-Converting Websites & E-Commerce Stores**\n📊 **Custom ERP, CRM & POS Software**\n📱 **Mobile Apps (iOS/Android) & Performance Marketing**\n\nWhat kind of business or project are you looking to build? Tell me a bit about your requirements so I can tailor the best solution for you!";
        }
    }
    // 9. Natural Human Clarification (Never robotic!)
    else {
        if ($lang === 'ro') {
            $aiReply = "Shukriya message karne ka! 😊 Taake main aapko bilkul sahi guide kar sakoon — kya aap thora sa bata sakte hain ke aap apne business ke liye Website, E-Commerce Store, WhatsApp AI Bot ya Custom Software mein se kis cheez par kaam karwana chahte hain?";
        } else {
            $aiReply = "Thanks for reaching out! 😊 So I can guide you accurately — could you share a quick line on what you're looking to build for your business (e.g., Website, E-Commerce Store, 24/7 WhatsApp AI Bot, or Custom Software)?";
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
   8. DISPATCH OUTBOUND MESSAGE VIA META CLOUD API (WITH ANTI-DUPLICATE GUARD)
   ------------------------------------------------------------ */
// Convert Markdown **bold** and ### headers into native WhatsApp *bold* formatting
$aiReply = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', (string)$aiReply);
$aiReply = preg_replace('/^#{1,4}\s*(.+)$/m', '*$1*', $aiReply);
$aiReply = trim($aiReply);

$lastOutText = '';
foreach (array_reverse($recentHistory) as $rh) {
    if ($rh['role'] === 'model') {
        $lastOutText = trim((string)$rh['content']);
        break;
    }
}
if ($lastOutText !== '' && $lastOutText === trim((string)$aiReply)) {
    // Prevent sending the exact same message twice in a row!
    http_response_code(200);
    echo json_encode(['status' => 'suppressed_duplicate_reply']);
    exit;
}

$sendRes = send_meta_whatsapp_message($senderPhone, $aiReply, $recipientPhoneId);

if ($isMusaMessage) {
    send_meta_whatsapp_message('923355556290', "🚗 *NEW MUSA PICK & DROP INQUIRY!*\n━━━━━━━━━━━━━━━━━━━━━\n📱 *Musa Line:* +92 305 2148006\n👤 *Passenger:* {$senderName} (+{$senderPhone})\n💬 *Message:* {$msgText}\n\n🤖 *Sarah AI Responded:* AC Alto | North-Numaish-Clifton-DHA | Rs. 22k-25k\n━━━━━━━━━━━━━━━━━━━━━");
}

if ($isGateway) {
    try {
        $st = db()->prepare("INSERT INTO wa_messages (wa_msg_id, phone, name, direction, message, raw_payload, status) VALUES (?, ?, ?, 'out', ?, ?, 'sent')");
        $st->execute(['gw_out_' . bin2hex(random_bytes(6)), $senderPhone, 'SoftNexa AI', $aiReply, 'gateway_reply']);
    } catch (Exception $e) {}

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'replies' => [
            ['message' => $aiReply]
        ],
        'reply'    => $aiReply,
        'llm_used' => $llmSucceeded
    ]);
    exit;
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'ok'       => true,
    'sender'   => $senderPhone,
    'reply'    => $aiReply,
    'meta_res' => $sendRes
]);
exit;
