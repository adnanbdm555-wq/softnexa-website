<?php
/* ============================================================
   Musa Pick & Drop Services — 24/7 Autonomous AI WhatsApp Agent
   Exclusively for Corporate Female Staff (Karachi)
   Powered by Google Gemini 1.5 Flash + Contextual Rules Engine
   Supports: Meta Cloud API Webhook + AutoResponder Gateway (100% Free)
   ============================================================ */

require_once __DIR__ . '/config.php';

$verifyToken = 'musa_pickndrop_2026';

/* ------------------------------------------------------------
   1. META WEBHOOK VERIFICATION CHALLENGE (GET)
   ------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['hub_mode'])) {
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
   2. INCOMING MESSAGE EXTRACTION (Meta Cloud API or Gateway/App)
   ------------------------------------------------------------ */
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$senderPhone = '';
$senderName  = 'Sister / Client';
$msgText     = '';
$isGateway   = false;

if (isset($_REQUEST['query']) || isset($_REQUEST['text']) || isset($_GET['message'])) {
    // Gateway / AutoResponder direct HTTP
    $msgText     = trim((string)($_REQUEST['query'] ?? $_REQUEST['text'] ?? $_GET['message'] ?? ''));
    $senderPhone = preg_replace('/[^0-9]/', '', (string)($_REQUEST['sender'] ?? $_REQUEST['phone'] ?? ''));
    $senderName  = trim((string)($_REQUEST['name'] ?? 'Sister'));
    $isGateway   = true;
} elseif ($data && is_array($data)) {
    if (isset($data['query']) || isset($data['text'])) {
        $msgText     = trim((string)($data['query'] ?? $data['text'] ?? ''));
        $senderPhone = preg_replace('/[^0-9]/', '', (string)($data['sender'] ?? $data['phone'] ?? ''));
        $senderName  = trim((string)($data['name'] ?? 'Sister'));
        $isGateway   = true;
    } else {
        // Native Meta Cloud API structure
        $entry   = $data['entry'][0] ?? [];
        $changes = $entry['changes'][0]['value'] ?? [];
        $message = $changes['messages'][0] ?? null;
        $contact = $changes['contacts'][0] ?? null;

        if ($message) {
            $senderPhone = preg_replace('/[^0-9]/', '', (string)($message['from'] ?? ''));
            $senderName  = (string)($contact['profile']['name'] ?? 'Sister');
            $msgType     = (string)($message['type'] ?? 'text');

            if ($msgType === 'text') {
                $msgText = trim((string)($message['text']['body'] ?? ''));
            } elseif ($msgType === 'button') {
                $msgText = trim((string)($message['button']['text'] ?? ''));
            } elseif ($msgType === 'interactive') {
                $msgText = trim((string)($message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? ''));
            }
        }
    }
}

if ($msgText === '') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'empty_query', 'reply' => 'Assalam-o-Alaikum! Musa Pick & Drop Services mein khushamdeed.']);
    exit;
}

$msgLower = mb_strtolower($msgText);

/* ------------------------------------------------------------
   3. AI SYSTEM PROMPT — SARAH (MUSA PICK & DROP)
   ------------------------------------------------------------ */
$systemPrompt = "You are 'Sarah', the polite, reassuring, and highly professional female customer coordinator for 'Musa Pick & Drop Services' in Karachi.
You communicate warmly, respectfully, and conversationally in friendly Pakistani Roman Urdu.

CRITICAL SERVICE SPECIFICATIONS:
- Brand: Musa Pick & Drop Services (Karachi)
- Target Audience: EXCLUSIVELY for Female Corporate Staff / Working Ladies. Strictly NO male passengers.
- Vehicle Experience: Fully Air-Conditioned (AC Alto Car) — clean, private, comfortable, maximum 3-4 passengers, door-to-door. (Emphasize that this is NOT a crowded 15-seater commercial van!).
- Dedicated Direct Route Corridor:
  * Pickups (Home): North Karachi, FB Area (including Ayesha Manzil & nearby blocks), and Nazimabad.
  * Transit & Drops (Office): Via Numaish, directly to Clifton and Defence (DHA).
  * STRICT EXCLUSION: Shahrah-e-Faisal is strictly NOT in our route (we avoid Shahrah-e-Faisal traffic to guarantee punctual arrival).
- Available Office Shift Timings:
  1) Morning Shift: 08:00 AM to 05:00 PM
  2) Second Shift: 09:00 AM to 06:30 PM
- Monthly Package: Rs. 22,000 to Rs. 25,000 per seat (Fixed monthly rate for premium door-to-door AC Alto car pool).
- Safety & Drivers: 100% verified, courteous, family-trusted drivers.

BEHAVIOR & CONVERSATION RULES:
1. Always maintain a warm, respectful, elder/younger sister tone ('G Baji', 'Assalam-o-Alaikum', 'JazakAllah').
2. When the ad prefill message arrives ('OFFICE PICK & DROP SERVICE — KARACHI! ...'):
   Welcome them warmly, confirm that this is an exclusive AC Alto service for female staff, and ask for their 4 booking details:
   - 1. Pick-up Area & Block (Ghar)
   - 2. Drop-off Location (Office in Clifton ya DHA)
   - 3. Shift Timing (8 to 5 ya 9 to 6:30)
   - 4. Joining Date (Kab se start karna hai)
3. If they ask about Shahrah-e-Faisal:
   Politely explain: 'Baji hamara route North Karachi aur FB Area se via Numaish seedha Clifton aur DHA jata hai. Shahrah-e-Faisal par heavy traffic aur delays ki wajah se yeh gaari wahan nahi jati taake office timely pohncha jaye.'
4. If they ask why charges are 22k-25k:
   Explain with dignity: 'Baji yeh aam 15-seater van nahi hai jisme khuwari aur garmi ho. Yeh door-to-door AC Alto car hai specifically working ladies ke safe aur comfortable safar ke liye, isliye yeh fixed monthly car-pool package hai.'
5. When they provide their details:
   Thank them warmly and confirm: 'JazakAllah Baji! Aapki details note karli hain. Hamara manager aglay 15 se 20 minutes mein aapko call karke exact pick-up timing aur seat reserve kar lega.'
6. Format responses cleanly with line breaks and friendly emojis (🚗, 🌸, ✨, 📍). Keep messages concise (2 to 4 short paragraphs).";

/* ------------------------------------------------------------
   4. GENERATE AI RESPONSE (GEMINI 1.5 FLASH)
   ------------------------------------------------------------ */
$geminiKey = setting('gemini_api_key', getenv('GEMINI_API_KEY') ?: '');
$aiReply = '';
$llmSucceeded = false;

if ($geminiKey) {
    try {
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($geminiKey));
        $payload = json_encode([
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $systemPrompt]]],
                ['role' => 'model', 'parts' => [['text' => "Understood. I am Sarah, the respectful customer coordinator for Musa Pick & Drop. I will assist corporate ladies warmly in Roman Urdu."]]],
                ['role' => 'user', 'parts' => [['text' => "Client says: " . $msgText]]]
            ],
            'generationConfig' => [
                'temperature'     => 0.65,
                'maxOutputTokens' => 300
            ]
        ]);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 7,
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
   5. SMART FALLBACK ENGINE (IF AI DELAYED OR KEY NOT SET)
   ------------------------------------------------------------ */
if (!$llmSucceeded || empty($aiReply)) {
    $isAdGreeting    = (bool)preg_match('/\b(pick & drop|musa|commute|shift|timing|office pick|daily office|journey)\b/i', $msgLower);
    $isPrice         = (bool)preg_match('/\b(charges|rate|fees|kitna|price|cost|kharcha|pese|paise|monthly)\b/i', $msgLower);
    $isShahrahFaisal = (bool)preg_match('/\b(shahrah|faisal|shara|sharha|pechs|baloch)\b/i', $msgLower);
    $isTiming        = (bool)preg_match('/\b(time|timing|shift|subah|morning|evening|sham)\b/i', $msgLower);
    $isRoute         = (bool)preg_match('/\b(route|area|kahan|clifton|defence|dha|numaish|north|nazimabad|ayesha)\b/i', $msgLower);
    $isMale          = (bool)preg_match('/\b(gents|male|larka|man|boys)\b/i', $msgLower);

    if ($isMale) {
        $aiReply = "Assalam-o-Alaikum! Maazrat chahte hain, Musa Pick & Drop Services 100% EXCLUSIVELY sirf Female Corporate Staff aur Working Ladies ke liye hai. Hamari service mein gents ya male passengers allow nahi hain. Shukriya! 🌸";
    } elseif ($isShahrahFaisal) {
        $aiReply = "Assalam-o-Alaikum Baji! 🌸\n\nHamara direct route North Karachi, FB Area (Ayesha Manzil) se via Numaish seedha Clifton aur Defence DHA jata hai.\n\nShahrah-e-Faisal par heavy traffic jams aur delays ki wajah se yeh gaari Shahrah-e-Faisal nahi jati taake office timely pohncha ja sake. 🚗✨";
    } elseif ($isPrice) {
        $aiReply = "Assalam-o-Alaikum Baji! 🌸\n\nMusa Pick & Drop koi 15-seater crowded van nahi hai, yeh door-to-door AC Alto car pool hai specifically working ladies ke safe aur comfortable safar ke liye.\n\n💰 Monthly Charges: Rs. 22,000 se Rs. 25,000 per seat hain (Door-to-Door AC Car).\n\nApna Pick-up area aur office location batayein taake seat check karein?";
    } elseif ($isTiming) {
        $aiReply = "Assalam-o-Alaikum Baji! 🌸\n\nHamare paas yeh do fixed office shifts available hain:\n1️⃣ Morning Shift: 08:00 AM to 05:00 PM\n2️⃣ Second Shift: 09:00 AM to 06:30 PM\n\nAapko inme se konsi shift suit karti hai?";
    } else {
        $aiReply = "Assalam-o-Alaikum! 🌸\nMusa Pick & Drop Services mein khushamdeed.\n\nHum working ladies ke liye 100% Safe, Door-to-Door AC Alto Car Service provide karte hain (strictly female staff only). 🚗✨\n\n📍 Route: North Karachi, FB Area (Ayesha Manzil), Nazimabad ➡️ Numaish ➡️ Clifton & Defence (DHA)\n⏰ Shifts: 08:00 AM – 05:00 PM ya 09:00 AM – 06:30 PM\n💰 Package: Rs. 22,000 – 25,000 / seat\n\nSeat confirm karne ke liye apni details share karein:\n📍 1. Pick-up Area & Block (Ghar):\n🏢 2. Drop-off Location (Clifton / DHA Office):\n⏰ 3. Shift Timing:\n📅 4. Joining Date:\n\nHum foran aapko seat finalize kar denge! 🌸";
    }
}

/* ------------------------------------------------------------
   6. RECORD LOG & ALERT OWNER
   ------------------------------------------------------------ */
try {
    // If phone number is available, capture into leads
    if (!empty($senderPhone)) {
        $check = db()->prepare("SELECT id FROM leads WHERE phone = ? LIMIT 1");
        $check->execute([$senderPhone]);
        $row = $check->fetch();

        if ($row) {
            $upd = db()->prepare("UPDATE leads SET message = CONCAT(COALESCE(message,''), '\n[Musa Bot] ', ?) WHERE id = ?");
            $upd->execute([$msgText, $row['id']]);
        } else {
            $ins = db()->prepare("INSERT INTO leads (name, phone, service, message, source, status, lead_score) VALUES (?, ?, 'Musa Pick & Drop (Female)', ?, 'musa_whatsapp_bot', 'new', 75)");
            $ins->execute([$senderName, $senderPhone, $msgText]);

            // Notify owner via email
            $leadAlert = "<h2>New Musa Pick & Drop Lead! 🚗🌸</h2>"
                . "<p><b>Passenger:</b> " . htmlspecialchars($senderName) . "</p>"
                . "<p><b>WhatsApp:</b> <a href='https://wa.me/" . htmlspecialchars($senderPhone) . "'>+" . htmlspecialchars($senderPhone) . "</a></p>"
                . "<p><b>Inquiry:</b> " . htmlspecialchars($msgText) . "</p>"
                . "<p><b>AI Response:</b> " . htmlspecialchars($aiReply) . "</p>";
            send_html_email(NOTIFY_EMAIL, "New Musa Pick & Drop Lead: {$senderName}", $leadAlert);
        }
    }
} catch (Exception $e) {}

/* ------------------------------------------------------------
   7. OUTPUT RESPONSE
   ------------------------------------------------------------ */
if ($isGateway) {
    // Return format expected by AutoResponder / Webhook apps: {"replies": [{"message": "..."}]}
    header('Content-Type: application/json');
    echo json_encode([
        'replies' => [
            ['message' => $aiReply]
        ],
        'reply' => $aiReply
    ]);
    exit;
} else {
    // Meta Cloud API Direct Outbound Dispatch if token is present
    if (!empty($senderPhone)) {
        send_meta_whatsapp_message($senderPhone, $aiReply);
    }
    header('Content-Type: application/json');
    echo json_encode([
        'ok'     => true,
        'sender' => $senderPhone,
        'reply'  => $aiReply
    ]);
    exit;
}
