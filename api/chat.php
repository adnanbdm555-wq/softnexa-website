<?php
/* ============================================================
   SoftNexa — Multilingual AI Sales & Solutions Agent Backend
   Handles natural customer discussions in English, Roman Urdu,
   Urdu, Arabic, etc. Qualifies leads, provides pricing/timelines,
   and captures client contact info for instant email alerts.
   ============================================================ */

require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'method'], 405);

if (!rate_ok('chat', 60, 3600)) {
    json_out(['ok' => false, 'reply' => "You've sent quite a few messages! Please take a quick moment, or feel free to WhatsApp us directly at +92 300 0000000 / email info@softnexasolution.com."]);
}

$input = json_input();
$message = trim((string)($input['message'] ?? ''));
$history = is_array($input['history'] ?? null) ? $input['history'] : [];
$sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['session_id'] ?? ''));
$forcedLang = trim((string)($input['lang'] ?? ''));

if ($message === '') {
    json_out(['ok' => false, 'error' => 'empty_message'], 422);
}

// Extract contact details if provided anywhere in message or history
$detectedPhone = '';
$detectedEmail = '';
$detectedName = '';

if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $message, $m)) {
    $detectedEmail = $m[0];
}
if (preg_match('/(\+?[0-9]{1,4}[\s-]?)?(\(?03[0-9]{2}\)?[\s-]?[0-9]{7}|[0-9]{10,14})/', $message, $m)) {
    $cleanPhone = preg_replace('/[^\+0-9]/', '', $m[0]);
    if (strlen($cleanPhone) >= 9) $detectedPhone = $cleanPhone;
}

// Check for client name indicators
if (preg_match('/(?:my name is|mera naam|i am|this is|naam)\s+([a-zA-Z\x{0600}-\x{06FF}\s]{2,30})/iu', $message, $m)) {
    $detectedName = trim($m[1]);
}

// Language detection
function detect_language($text, $pref = '') {
    if ($pref === 'ur' || preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
        return 'ur';
    }
    if ($pref === 'ro' || preg_match('/\b(kya|hai|hain|karo|karein|mujhe|chahiye|ap|aap|ka|ki|ke|kitna|kharcha|banwana|rabta|shukriya|bhai|salaam|assalam|walaikum|keise|kaisi|ho|acha|theek|batao|bataen)\b/i', $text)) {
        return 'ro';
    }
    if ($pref === 'ar' || preg_match('/\b(مرحبا|اريد|تكلفة|نظام|تطبيق|شركة|شكرا)\b/u', $text)) {
        return 'ar';
    }
    return 'en';
}

$lang = detect_language($message, $forcedLang);

// Check if external LLM API is available (OpenAI or Gemini)
$openaiKey = setting('openai_api_key', getenv('OPENAI_API_KEY') ?: '');
$geminiKey = setting('gemini_api_key', getenv('GEMINI_API_KEY') ?: '');

$aiReply = '';
$llmSucceeded = false;

// SoftNexa System Persona
$systemPrompt = "You are Sofia, the Senior AI Solutions Architect & Lead Consultant at SoftNexa (softnexasolution.com).
SoftNexa is an engineering-first software house based in Karachi, Pakistan, serving global enterprises across USA, UK, UAE, and Pakistan.
Our core offerings:
1. Bespoke Enterprise ERP & CRM systems (Inventory, POS, Accounting, Multi-warehouse, HR).
2. Scalable Web Apps & SaaS Platforms (React, Next.js, Node.js, Python, PostgreSQL, AWS).
3. Native & Cross-Platform Mobile Apps (Flutter, iOS, Android).
4. Autonomous AI Automations & WhatsApp AI Agents (document processing, OCR, customer support bots).

Pricing & Delivery Model:
- 100% Fixed-Price Guarantee (no surprise hourly bills).
- Fast MVP launches in 2 to 4 weeks. Full enterprise systems in 4 to 8 weeks.
- MVPs typically start around $3,000 / PKR equivalent; enterprise systems range $6,000 - $15,000+ depending on scope.
- We offer a free 48-hour tailored scope blueprint and prototype demo.

Tone & Behavior:
- Talk like an empathetic, highly skilled technical consultant.
- Speak naturally in the SAME language the user addresses you in (Urdu script, Roman Urdu, English, Arabic, etc.).
- If Roman Urdu is used, reply in warm, professional Roman Urdu.
- Empathize with their problem first, explain how SoftNexa solves it, suggest the ideal architecture, and actively steer towards closing the deal by asking for their Name, WhatsApp number, or Email so the engineering team can deliver a free 48-hour blueprint.
- Keep responses concise (under 120 words), well-spaced, and easy to read.";

if ($geminiKey) {
    try {
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($geminiKey));
        $contents = [];
        $contents[] = ['role' => 'user', 'parts' => [['text' => $systemPrompt]]];
        $contents[] = ['role' => 'model', 'parts' => [['text' => "Understood. I am Sofia from SoftNexa, ready to consult clients and qualify leads in any language with technical excellence and high conversion."]]];
        foreach (array_slice($history, -6) as $h) {
            $r = ($h['role'] === 'user') ? 'user' : 'model';
            $contents[] = ['role' => $r, 'parts' => [['text' => (string)$h['content']]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];
        
        $payload = json_encode(['contents' => $contents, 'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 350]]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $res = curl_exec($ch);
        if ($res && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
            $data = json_decode($res, true);
            $aiReply = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (!empty($aiReply)) $llmSucceeded = true;
        }
        curl_close($ch);
    } catch (Exception $e) {}
}

if (!$llmSucceeded && $openaiKey) {
    try {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach (array_slice($history, -6) as $h) {
            $messages[] = ['role' => $h['role'] === 'user' ? 'user' : 'assistant', 'content' => (string)$h['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $message];
        $payload = json_encode(['model' => 'gpt-4o-mini', 'messages' => $messages, 'temperature' => 0.7, 'max_tokens' => 350]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $openaiKey]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $res = curl_exec($ch);
        if ($res && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
            $data = json_decode($res, true);
            $aiReply = $data['choices'][0]['message']['content'] ?? '';
            if (!empty($aiReply)) $llmSucceeded = true;
        }
        curl_close($ch);
    } catch (Exception $e) {}
}

// Built-in Autonomous Conversational Engine (Zero-Failure Fallback)
if (!$llmSucceeded || empty($aiReply)) {
    $msgLower = mb_strtolower($message);
    
    // Quick intent checks
    $isGreeting = preg_match('/\b(hi|hello|hey|assalam|salam|aoa|kese ho|kaise ho|keise|kaisa|good morning|good evening)\b/i', $msgLower);
    $isPrice = preg_match('/\b(price|cost|charges|rate|kharcha|kitna|pese|paise|fees|budget|quote|estimate)\b/i', $msgLower);
    $isTimeline = preg_match('/\b(time|timeline|duration|kab tak|kitne din|weeks|months|deliver|fast)\b/i', $msgLower);
    $isERP = preg_match('/\b(erp|pos|inventory|crm|warehouse|stock|accounting|billing|invoicing|hms|hospital|school)\b/i', $msgLower);
    $isApp = preg_match('/\b(app|mobile|android|ios|flutter|playstore|play store|app store)\b/i', $msgLower);
    $isWeb = preg_match('/\b(web|website|saas|portal|software|dashboard|react|nextjs)\b/i', $msgLower);
    $isAI = preg_match('/\b(ai|agent|chatbot|bot|whatsapp bot|automation|automate|llm|ocr)\b/i', $msgLower);
    $isLocation = preg_match('/\b(office|location|address|karachi|where|kahan|lahore|islamabad)\b/i', $msgLower);
    $isContactSubmission = ($detectedEmail !== '' || $detectedPhone !== '');

    if ($isContactSubmission) {
        if ($lang === 'ur') {
            $aiReply = "بہت بہت شکریہ! آپ کی تفصیلات (" . ($detectedEmail ?: $detectedPhone) . ") ہماری سینئر انجینئرنگ ٹیم کو موصول ہو چکی ہیں۔ ہماری ٹیم 2 سے 4 گھنٹوں کے اندر آپ سے واٹس ایپ یا ای میل پر رابطہ کر کے مکمل اسکوپ اور کوٹیشن شیئر کرے گی۔";
        } elseif ($lang === 'ro') {
            $aiReply = "Boht shukriya! Aap ki details (" . ($detectedEmail ?: $detectedPhone) . ") hamare senior solutions architect tak deliver ho chuki hain. Hum boht jald aap se direct WhatsApp/Email par rabta karein gay aur project ka tailored scope blueprint share karein gay! Kiya koi specific features hain jo aap pehle dekhna chahte hain?";
        } else {
            $aiReply = "Thank you so much! We have received your contact details (" . ($detectedEmail ?: $detectedPhone) . "). Our lead engineering architect will reach out to you within a few hours on WhatsApp/Email with a tailored project scope and fixed-price quote.";
        }
    } elseif ($isGreeting && count($history) <= 2) {
        if ($lang === 'ur') {
            $aiReply = "وعلیکم السلام! میں صوفیہ ہوں، سافٹ نیکسا کی سینئر سلوشنز کنسلٹنٹ۔ ہم آپ کے کاروبار کے لیے کسٹم ای آر پی، ویب پورٹلز، موبائل ایپس اور اے آئی آٹومیشن تیار کرتے ہیں۔ آپ کس طرح کا سسٹم بنوانا چاہتے ہیں؟";
        } elseif ($lang === 'ro') {
            $aiReply = "Walaikum Assalam! Main Sofia hoon, SoftNexa ki Senior AI Solutions Consultant. Hum businesses ke liye custom enterprise ERPs, scalable web apps, mobile apps aur AI automations design aur develop karte hain. Aap apne business ke liye kis tarah ka software build karna chahte hain?";
        } else {
            $aiReply = "Hello! I am Sofia, Senior AI Solutions Consultant at SoftNexa. We architect custom business software, enterprise ERPs, high-performance web/mobile apps, and AI automations. Tell me about the workflow or problem you'd like to solve!";
        }
    } elseif ($isERP) {
        if ($lang === 'ur') {
            $aiReply = "ہم مکمل طور پر کسٹم ای آر پی اور سی آر ایم سسٹمز بناتے ہیں جن میں انوینٹری، ملٹی برانچ پی او ایس، انوائسنگ، رولز پرمیشنز اور ریئل ٹائم رپورٹس شامل ہوتی ہیں۔ یہ بالکل آپ کے کام کرنے کے طریقے کے مطابق بنایا جاتا ہے۔ آپ کی ٹیم میں کتنے لوگ اس سسٹم کو استعمال کریں گے؟";
        } elseif ($lang === 'ro') {
            $aiReply = "SoftNexa ka custom ERP system aapki inventory, accounts, POS, warehouse aur multi-branch reporting ko ek single dashboard par le aata hai. Hamare sabhi systems 100% bespoke hotay hain. Aapke paas abhi kitne branches ya team members hain, aur kya koi purana data migrate karna hai?";
        } else {
            $aiReply = "We specialize in custom, modular ERP and CRM platforms—covering inventory, multi-branch POS, automated invoicing, role-based workflows, and live audit logs. Everything is built to match your exact operations. How many branches or active team members will be using this system?";
        }
    } elseif ($isApp) {
        if ($lang === 'ur') {
            $aiReply = "ہم آئی او ایس اور اینڈرائیڈ دونوں کے لیے فاسٹ اور خوبصورت موبائل ایپس (Flutter / React Native) بناتے ہیں، جن میں ریئل ٹائم ٹریکنگ، پش نوٹیفیکیشنز اور پیمنٹ گیٹ ویز شامل ہوتے ہیں۔ آپ کی ایپ کے اہم فیچرز کیا ہوں گے؟";
        } elseif ($lang === 'ro') {
            $aiReply = "Hum iOS aur Android ke liye single-codebase Flutter apps build karte hain jo App Store aur Play Store dono par super smooth perform karti hain (with offline sync & payment gateway integrations). Kya aapke paas design/wireframes ready hain ya scratch se build karna hai?";
        } else {
            $aiReply = "We build high-performance mobile apps for iOS and Android using Flutter and native modules. Features include real-time push notifications, payment gateway integrations, and offline caching. Do you already have UI/wireframes, or should we design the prototype from scratch?";
        }
    } elseif ($isAI) {
        if ($lang === 'ur') {
            $aiReply = "ہم کسٹم اے آئی ایجنٹس، واٹس ایپ کسٹمر چیٹ بوٹس اور دستاویزات پڑھنے والے او سی آر سسٹمز بناتے ہیں جو آپ کا 80 فیصد روٹین کام خودکار کر دیتے ہیں۔ آپ کس پروسیس کو اے آئی کے ذریعے آٹومیٹ کرنا چاہتے ہیں؟";
        } elseif ($lang === 'ro') {
            $aiReply = "SoftNexa ke AI agents customer dealing, WhatsApp support, invoice reading (OCR), aur automated reporting ko 24/7 autonomously handle karte hain. Kya aapko WhatsApp sales bot chahiye ya internal company data par trained AI assistant?";
        } else {
            $aiReply = "We engineer autonomous AI agents, WhatsApp business chatbots, and smart document extractors (OCR/PDF) that handle customer inquiries and data workflows 24/7. Are you looking for a customer-facing sales agent or internal document automation?";
        }
    } elseif ($isPrice) {
        if ($lang === 'ur') {
            $aiReply = "ہم فکسڈ پرائس گارنٹی پر کام کرتے ہیں، یعنی درمیان میں کوئی پوشیدہ اخراجات نہیں ہوتے۔ بنیادی ایم وی پی سسٹمز تقریباً 3,000 ڈالرز (یا مساوی پی کے آر) سے شروع ہوتے ہیں، جبکہ مکمل کسٹم انٹرپرائز ای آر پی 6,000 سے 15,000 ڈالرز کے درمیان ہوتا ہے۔ کیا میں آپ کے لیے 48 گھنٹوں کا کسٹم اسکوپ بلیو پرنٹ تیار کروں؟ اپنا واٹس ایپ نمبر یا ای میل شیئر کیجیے!";
        } elseif ($lang === 'ro') {
            $aiReply = "Hamare sabhi projects 100% Fixed-Price Guarantee ke sath aate hain (no hidden hourly charges). Fast MVPs $3,000 / PKR equivalent se start hote hain, aur comprehensive Enterprise ERPs scope ke mutabiq $6,000 se $15,000+ ke range mein hote hain. Main aapke requirements ke mutabiq 48-hour scope blueprint draft kar sakti hoon—aapka WhatsApp number ya Email kya hai?";
        } else {
            $aiReply = "All our engineering is delivered under a 100% Fixed-Price Guarantee with milestone-based delivery. Focused MVPs typically start at $3,000, while end-to-end Enterprise ERPs range from $6,000 to $15,000+ depending on features. May I have your WhatsApp number or Email so our team can send you a tailored 48-hour scope blueprint?";
        }
    } elseif ($isTimeline) {
        if ($lang === 'ur') {
            $aiReply = "ہم 2 سے 4 ہفتوں کے تیز رفتار سپرنٹس میں ڈیلیور کرتے ہیں، اور ہر مرحلے پر آپ لائیو اسٹیجنگ ڈیمو دیکھ سکتے ہیں۔ مکمل انٹرپرائز پروجیکٹ 4 سے 8 ہفتوں میں مکمل ہو جاتا ہے۔ آپ کا مطلوبہ لانچ ٹائم فریم کیا ہے؟";
        } elseif ($lang === 'ro') {
            $aiReply = "Hum rapid 2 to 4-week delivery cycles follow karte hain. First MVP working version aapko 2 se 3 hafton mein live staging server par mil jata hai. Full enterprise build 4 to 8 weeks mein ready hota hai. Aap kab tak launch karna chahte hain?";
        } else {
            $aiReply = "We operate on rapid 2-to-4 week engineering sprints. You get access to a live interactive staging demo within the first 14 days, with full production deployment in 4 to 8 weeks. What is your ideal launch timeline?";
        }
    } elseif ($isLocation) {
        if ($lang === 'ur') {
            $aiReply = "سافٹ نیکسا کا مین انجینئرنگ ہاؤس کراچی، پاکستان میں واقع ہے، اور ہم امریکہ، برطانیہ، مشرق وسطیٰ اور پاکستان بھر کے کلائنٹس کو انٹرپرائز سافٹ ویئر سلوشنز فراہم کرتے ہیں۔ آپ کراچی آفس میں میٹنگ بھی شیڈول کر سکتے ہیں!";
        } elseif ($lang === 'ro') {
            $aiReply = "SoftNexa ka main engineering office Karachi, Pakistan mein hai. Hum locally Pakistan aur globally US, UK, UAE ke enterprise clients ko serve karte hain. Aap direct call ya Karachi office mein in-person meeting bhi schedule kar sakte hain! Aap kis city/country se baat kar rahe hain?";
        } else {
            $aiReply = "SoftNexa's primary engineering office is located in Karachi, Pakistan, serving enterprise clients globally across the US, UK, Middle East, and Pakistan. We'd be delighted to schedule a virtual discovery call or an in-person meeting at our office!";
        }
    } else {
        if ($lang === 'ur') {
            $aiReply = "یہ ایک بہترین پروجیکٹ لگ رہا ہے۔ سافٹ نیکسا آپ کے اس سسٹم کو کسٹم کوڈنگ اور ماڈرن ٹیکنالوجی کے ساتھ بنا سکتا ہے۔ مکمل اسکوپ اور درست قیمت جاننے کے لیے برائے مہربانی اپنا نام اور واٹس ایپ یا ای میل فراہم کیجیے تاکہ ہمارے چیف آرکیٹیکٹ آپ سے رابطہ کر سکیں۔";
        } elseif ($lang === 'ro') {
            $aiReply = "Yeh requirement boht promising hai! SoftNexa ki team isko modern cloud architecture aur sleek UI ke sath perfect tarike se build kar sakti hai. Exact pricing aur architecture roadmap ke liye kya aap apna Name aur WhatsApp number ya Email share kar sakte hain?";
        } else {
            $aiReply = "This sounds like a high-impact initiative! SoftNexa can architect this with a modern, scalable cloud stack and tailored workflows. To give you an exact engineering roadmap and fixed quote, could you share your Name and WhatsApp number or Email?";
        }
    }
}

// Automatically save lead if contact details are detected
$leadCaptured = false;
if ($detectedEmail !== '' || $detectedPhone !== '') {
    $leadName = $detectedName !== '' ? $detectedName : 'Chat Client (' . ($detectedEmail ?: $detectedPhone) . ')';
    $fullChatLog = "AI Chat Log:\n";
    foreach ($history as $h) {
        $fullChatLog .= ($h['role'] === 'user' ? 'Client: ' : 'Sofia: ') . $h['content'] . "\n";
    }
    $fullChatLog .= "Client: " . $message . "\n";
    $fullChatLog .= "Sofia: " . $aiReply . "\n";

    $leadRow = [
        'name'       => $leadName,
        'company'    => '',
        'email'      => $detectedEmail ?: 'chat-lead@softnexasolution.com',
        'phone'      => $detectedPhone,
        'service'    => 'AI Chatbot Consultation',
        'industry'   => 'Inquired via Chat',
        'problems'   => mb_substr($fullChatLog, 0, 2000),
        'tools'      => 'Chatbot conversation',
        'team'       => 'Pending review',
        'urgency'    => 'Immediate / Hot',
        'modules'    => '',
        'estimate'   => 'Chat Inquired',
        'timeline'   => '48-hr scope requested',
        'trial_days' => 0,
        'founding'   => '',
        'region'     => country_code() === 'PK' ? 'pk' : 'intl',
        'message'    => mb_substr($fullChatLog, 0, 4000),
        'lead_score' => 85,
        'source'     => 'ai-chatbot',
        'session_id' => $sessionId,
        'referrer'   => ref_host($_SERVER['HTTP_REFERER'] ?? ''),
        'country'    => country_code(),
        'device'     => device_type($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'ip'         => client_ip(),
        'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
    ];

    try {
        $cols = implode(',', array_keys($leadRow));
        $marks= implode(',', array_fill(0, count($leadRow), '?'));
        db()->prepare("INSERT INTO leads ($cols) VALUES ($marks)")->execute(array_values($leadRow));
        $leadId = db()->lastInsertId();
        $leadCaptured = true;

        // Send instant email alerts to both adnanbdm555@gmail.com and info@softnexasolution.com
        $to = setting('notify_email', NOTIFY_EMAIL);
        $subj = '[HOT 85] New AI Chatbot Lead: ' . $leadName . ' (' . ($detectedPhone ?: $detectedEmail) . ')';
        $items = [
            'Source'         => 'Interactive AI Sales Chatbot',
            'Phone / WhatsApp' => $detectedPhone ?: 'Not provided',
            'Email'          => $detectedEmail ?: 'Not provided',
            'Language'       => strtoupper($lang),
            'Status'         => '🔥 Hot Lead (Direct Chat Engagement)',
            'Session ID'     => $sessionId,
        ];
        $host = $_SERVER['HTTP_HOST'] ?? 'softnexasolution.com';
        $emailHtml = render_lead_email([
            'title'       => $subj,
            'badge'       => '🔥 HOT CHATBOT LEAD (85)',
            'badge_color' => '#EF4444',
            'name'        => $leadName,
            'company'     => 'Direct Website Visitor',
            'email'       => $detectedEmail ?: 'chat@softnexasolution.com',
            'phone'       => $detectedPhone,
            'items'       => $items,
            'message'     => nl2br(htmlspecialchars($fullChatLog)),
            'cta_url'     => 'https://' . $host . '/admin/leads.php?id=' . $leadId,
            'cta_text'    => 'View Chat Lead #' . $leadId,
            'meta'        => ['Country' => country_code(), 'IP' => client_ip()]
        ]);
        send_html_email($to, $subj, $emailHtml, $detectedEmail ?: '');
    } catch (Exception $e) {
        // Continue even if DB write fails
    }
}

// Recommended quick replies based on context
$quickReplies = [];
if ($lang === 'ur') {
    $quickReplies = ['کسٹم ای آر پی کی معلومات', 'موبائل ایپ بنوانی ہے', 'قیمت اور کوٹیشن', 'انجینئر سے بات کریں'];
} elseif ($lang === 'ro') {
    $quickReplies = ['Custom ERP details', 'Mobile App banwani hai', 'Pricing & Quote', 'Office kahan hai?'];
} else {
    $quickReplies = ['Custom ERP & CRM', 'Mobile App Development', 'Fixed-Price Quote', 'Timeline & Process'];
}

json_out([
    'ok'            => true,
    'reply'         => $aiReply,
    'lang'          => $lang,
    'lead_captured' => $leadCaptured,
    'quick_replies' => $quickReplies
]);
