<?php
/* ============================================================
   SoftNexa — Multilingual AI Sales & Solutions Consultant Backend
   Handles natural, human-like customer discussions in English,
   Roman Urdu, Urdu, Arabic, etc. Qualifies leads, provides
   transparent pricing/timelines, and captures client contact info.
   ============================================================ */

require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'method'], 405);

if (!rate_ok('chat', 60, 3600)) {
    json_out(['ok' => false, 'reply' => "You've sent quite a few messages! Please take a quick moment, or feel free to WhatsApp us directly at +92 315 2989922 (https://wa.me/923152989922) or email info@softnexasolution.com."]);
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
    if ($pref === 'ro' || preg_match('/\b(kya|hai|hain|karo|karein|mujhe|chahiye|ap|aap|ka|ki|ke|kitna|kharcha|banwana|rabta|shukriya|bhai|salaam|assalam|walaikum|keise|kaisi|kese|kaise|ho|acha|theek|batao|bataen|krte|karna|bhi|nahi|nhe)\b/i', $text)) {
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
$systemPrompt = "You are SoftNexa Assistant, the Senior Solutions Consultant at SoftNexa (softnexasolution.com).
IMPORTANT: Do NOT refer to yourself as Sofia or any personal human female/male name. You are simply 'SoftNexa Assistant' or 'Solutions Consultant'.
SoftNexa is an engineering-first software house based in Karachi, Pakistan, serving global enterprises across USA, UK, UAE, and Pakistan.

Our core offerings:
1. Bespoke Enterprise ERP & CRM systems (Inventory, POS, Accounting, Multi-warehouse, HR, Manufacturing, Healthcare).
2. Scalable Web Apps & SaaS Platforms (React, Next.js, Node.js, Python, PostgreSQL, AWS).
3. Native & Cross-Platform Mobile Apps (Flutter, iOS, Android).
4. Autonomous AI Automations & WhatsApp AI Agents (document processing, OCR, customer support bots).

Pricing & Delivery Model:
- 100% Fixed-Price Guarantee (no surprise hourly bills).
- Fast MVP launches in 2 to 4 weeks. Full enterprise systems in 4 to 8 weeks.
- MVPs typically start around $3,000 / PKR equivalent; enterprise systems range $6,000 - $15,000+ depending on scope.
- We offer a free 48-hour tailored scope blueprint and prototype demo.

Official Contact & WhatsApp:
- Official WhatsApp / Direct Call: +92 315 2989922 (https://wa.me/923152989922)
- Official Email: info@softnexasolution.com
- Office: Shahrah-e-Faisal, Karachi, Pakistan
- When the user asks for contact info, phone, or how to WhatsApp us, provide +92 315 2989922 and invite them to connect.

Tone & Behavior:
- Talk like an empathetic, highly skilled technical consultant dealing directly with a customer.
- Always match the EXACT language and dialect the customer speaks in:
  - If Roman Urdu: Reply in warm, natural, fluent Roman Urdu.
  - If Urdu script: Reply in courteous, formal Urdu.
  - If English: Reply in clear, professional, engineering-led English.
- Always address the client's questions or technical requirements thoroughly first.
- Lead Qualification & Contact Intake (Polite, Helpful & Strictly Optional):
  - Politely and casually offer to draft a free 48-Hour Scope Blueprint or detailed architecture proposal.
  - Gently ask: 'If you would like our engineering leads to prepare a free 48-Hour Scope Blueprint & fixed estimate, feel free to share your Name & WhatsApp number (optional). Or we can continue discussing your project right here!'
  - CRITICAL RULE: Contact details must NEVER be compulsory or blocking. Never refuse to answer or force the visitor to give contact info. If they ask further technical, price, or architecture questions without sharing contact info, enthusiastically answer their questions without repeating the request.
- Keep responses concise (under 120 words), well-spaced, and easy to read.";

if ($geminiKey) {
    try {
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($geminiKey));
        $contents = [];
        $contents[] = ['role' => 'user', 'parts' => [['text' => $systemPrompt]]];
        $contents[] = ['role' => 'model', 'parts' => [['text' => "Understood. I am the SoftNexa Assistant, ready to consult clients and qualify leads in any language with technical excellence and high conversion."]]];
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
    
    // Detailed intent flags
    $isContactSubmission = ($detectedEmail !== '' || $detectedPhone !== '');
    $isGreeting = preg_match('/\b(hi|hello|hey|assalam|salam|aoa|kese ho|kaise ho|keise|kaisa|kese hain|kaise hain|hal hai|haal hai|good morning|good evening)\b/i', $msgLower);
    $isPrice = preg_match('/\b(price|cost|charges|rate|kharcha|kitna|pese|paise|fees|budget|quote|estimate|payment terms)\b/i', $msgLower);
    $isTimeline = preg_match('/\b(time|timeline|duration|kab tak|kitne din|weeks|months|deliver|fast|kitna time|launch)\b/i', $msgLower);
    $isHealthcare = preg_match('/\b(pharmacy|clinic|hospital|doctor|medicine|dawa|medical|patient|prescription)\b/i', $msgLower);
    $isEcommerce = preg_match('/\b(ecommerce|e-commerce|store|shop|dukan|clothing|brand|orders|cart)\b/i', $msgLower);
    $isLogistics = preg_match('/\b(logistics|fleet|tracking|driver|delivery|dispatch|courier|cargo|transport)\b/i', $msgLower);
    $isERP = preg_match('/\b(erp|pos|inventory|crm|warehouse|stock|accounting|billing|invoicing|hms|school|distribution|supermarket)\b/i', $msgLower);
    $isApp = preg_match('/\b(app|mobile|android|ios|flutter|playstore|play store|app store|react native)\b/i', $msgLower);
    $isWeb = preg_match('/\b(web|website|saas|portal|software|dashboard|react|nextjs)\b/i', $msgLower);
    $isAI = preg_match('/\b(ai|agent|chatbot|bot|whatsapp bot|automation|automate|llm|ocr|rag)\b/i', $msgLower);
    $isContact = preg_match('/\b(contact|phone|number|whatsapp|call|rabta|email|mobile|cell|num)\b/i', $msgLower);
    $isLocation = preg_match('/\b(office|location|address|karachi|where|kahan|lahore|islamabad|meeting)\b/i', $msgLower);

    // 1. Lead Contact Submission
    if ($isContactSubmission) {
        if ($lang === 'ur') {
            $aiReply = "بہت بہت شکریہ! آپ کی رابطہ تفصیلات (" . ($detectedEmail ?: $detectedPhone) . ") ہماری سینئر انجینئرنگ ٹیم کو موصول ہو چکی ہیں۔ ہماری ٹیم 2 سے 4 گھنٹوں کے اندر آپ سے واٹس ایپ یا ای میل پر رابطہ کر کے مکمل اسکوپ بلیو پرنٹ اور فکسڈ کوٹیشن شیئر کرے گی۔";
        } elseif ($lang === 'ro') {
            $aiReply = "Boht shukriya! Aap ki contact details (" . ($detectedEmail ?: $detectedPhone) . ") hamare senior software architect ko handover ho chuki hain. Hum boht jald aap se direct WhatsApp/Email par rabta karein gay aur project ka tailored scope blueprint share karein gay! Kya koi specific feature ya timeline hai jo aap discuss karna chahein?";
        } else {
            $aiReply = "Thank you so much! We have received your contact details (" . ($detectedEmail ?: $detectedPhone) . "). Our lead engineering architect will reach out to you within a few hours on WhatsApp/Email with a tailored project scope blueprint and fixed-price quote.";
        }
    }
    // 2. Pricing and Budget Inquiry
    elseif ($isPrice) {
        if ($lang === 'ur') {
            $aiReply = "ہم 100% فکسڈ پرائس گارنٹی پر کام کرتے ہیں، یعنی درمیان میں کوئی پوشیدہ اخراجات نہیں ہوتے۔\n- بنیادی ایم وی پی (MVP): تقریباً $3,000 سے شروع ہوتا ہے۔\n- مکمل انٹرپرائز ای آر پی / پورٹل: ماڈیولز کے مطابق $6,000 سے $15,000+ تک ہوتا ہے۔\nادائیگی مائل اسٹون کی بنیاد پر ہوتی ہے۔ اگر آپ چاہیں تو 48 گھنٹوں کے مفت اسکوپ کے لیے اپنا نام اور واٹس ایپ نمبر شیئر کر سکتے ہیں (اختیاری)، یا آپ مجھ سے بلا جھجھک مزید سوالات پوچھ سکتے ہیں!";
        } elseif ($lang === 'ro') {
            $aiReply = "SoftNexa 100% Fixed-Price Guarantee par kaam karta hai (no hidden hourly charges):\n- Core MVP / Focused System: $3,000 / PKR equivalent se start hota hai.\n- Full Enterprise ERP / Multi-Branch System: Scope ke mutabiq $6,000 se $15,000+ tak hota hai.\nPayment milestone-based hoti hai (25% advance, 25% prototype, 25% testing, 25% live launch). Aapke project ka tailored 48-Hour Scope Blueprint tayyar karne ke liye agar aap chahein toh apna Name aur WhatsApp number ya Email share kar dein (optional), ya aap mujhse mazeed technical sawalat bhi yahan pooch sakte hain!";
        } else {
            $aiReply = "All SoftNexa engineering is delivered under a 100% Fixed-Price Guarantee with milestone-based delivery:\n- Focused MVPs start at $3,000.\n- Full Enterprise ERPs & Multi-Branch Systems range from $6,000 to $15,000+ depending on modules.\nPayments are tied to tangible sprint milestones. If you'd like, feel free to share your Name and WhatsApp number or Email (optional) so we can draft your free 48-hour scope blueprint, or let me know what questions you have!";
        }
    }
    // 3. Timeline & Delivery Duration
    elseif ($isTimeline) {
        if ($lang === 'ur') {
            $aiReply = "ہم 2 سے 4 ہفتوں کے تیز رفتار سپرنٹس میں کام کرتے ہیں۔ پہلے 2 ہفتوں میں ہی انٹرایکٹو ورکنگ پروٹوٹائپ لائیو اسٹیجنگ پر فراہم کیا جاتا ہے، اور مکمل پروجیکٹ 4 سے 8 ہفتوں میں لائیو لانچ ہو جاتا ہے۔ آپ کا متوقع لانچ ٹائم فریم کیا ہے؟ (اختیاری: آپ چاہیں تو نام/واٹس ایپ بھی شیئر کر سکتے ہیں تاکہ اسپرنٹ روڈ میپ بھیجا جا سکے)۔";
        } elseif ($lang === 'ro') {
            $aiReply = "Hum rapid 2 to 4-week delivery sprints follow karte hain. First functional prototype aapko pehle 2 se 3 hafton mein live staging server par mil jata hai taake aap test kar sakein, aur full enterprise build 4 to 8 weeks mein live ho jata hai. Aap kab tak launch karna chahte hain? (Chahein toh apna Name ya WhatsApp bhi share kar sakte hain taake hum roadmap bhej sakein).";
        } else {
            $aiReply = "We operate on rapid 2-to-4 week engineering sprints. You receive a live interactive staging prototype within the first 14 days, followed by full production deployment in 4 to 8 weeks. What is your ideal launch timeline? (Feel free to also share your Name/WhatsApp if you'd like us to map out a sprint plan).";
        }
    }
    // 4. Pharmacy & Healthcare Specific
    elseif ($isHealthcare) {
        if ($lang === 'ur') {
            $aiReply = "فارمیسی اور ہیلتھ کیئر کے لیے ہم اسپیشلائزڈ ای آر پی بناتے ہیں جس میں بیچ اور ایکسپائری الرٹس، ملٹی کاؤنٹر پی او ایس اور سینٹرل انوینٹری سنک شامل ہے۔ آپ کی کتنی برانچز ہیں اور روزانہ کتنے بل بنتے ہیں؟";
        } elseif ($lang === 'ro') {
            $aiReply = "Pharmacy aur Healthcare businesses ke liye hum specialized ERP architect karte hain jisme automated Batch & Expiry alerts, barcode scanning, fast POS billing aur multi-branch central stock sync shamil hota hai. Aapki total kitni branches hain aur kitne staff members use karenge?";
        } else {
            $aiReply = "For healthcare and pharmacy networks, we build specialized ERPs featuring automated batch/expiry tracking, multi-counter POS, barcode validation, and multi-branch inventory sync. How many branches and active operators do you plan to support?";
        }
    }
    // 5. Logistics & Fleet Specific
    elseif ($isLogistics) {
        if ($lang === 'ur') {
            $aiReply = "ٹرانسپورٹ اور لاجسٹکس کے لیے ہم لائیو جی پی ایس ٹریکنگ، ڈرائیور موبائل ایپس اور ڈسپیچ مینجمنٹ سسٹمز بناتے ہیں۔ آپ کے پاس کل کتنی گاڑیاں یا فلیٹ یونٹس ہیں؟";
        } elseif ($lang === 'ro') {
            $aiReply = "Logistics aur transport operations ke liye hum real-time dispatch dashboards aur driver mobile apps build karte hain (live GPS tracking, automated route assignment aur digital proof-of-delivery). Aapke paas kitni vehicles ya fleet units hain?";
        } else {
            $aiReply = "For logistics and transport providers, we build central dispatch management portals paired with iOS & Android driver apps—complete with real-time GPS tracking, automated routing, and digital proof-of-delivery. How large is your fleet?";
        }
    }
    // 6. E-Commerce & Retail
    elseif ($isEcommerce) {
        if ($lang === 'ur') {
            $aiReply = "ہم تیز رفتار ای کامرس ویب سائٹس اور شاپنگ ایپس بناتے ہیں جن میں کورئیر انٹیگریشن (TCS، Trax، Leopards) اور آن لائن پیمنٹ گیٹ ویز شامل ہوتے ہیں۔ آپ کے پاس ماہانہ کتنے آرڈرز متوقع ہیں؟";
        } elseif ($lang === 'ro') {
            $aiReply = "E-commerce brands ke liye hum custom high-conversion web stores aur Flutter shopping apps build karte hain jin me automated courier integration (Trax, TCS, Leopards), inventory sync aur online payments shamil hotay hain. Aapka monthly order volume kitna hai?";
        } else {
            $aiReply = "We build high-converting, scalable e-commerce platforms and companion mobile apps with automated courier dispatch (Trax, TCS, Leopards, DHL), live inventory sync, and multi-currency payments. What is your expected monthly order volume?";
        }
    }
    // 7. General ERP & CRM
    elseif ($isERP) {
        if ($lang === 'ur') {
            $aiReply = "ہم مکمل طور پر کسٹم ای آر پی اور سی آر ایم سسٹمز بناتے ہیں جن میں انوینٹری، ملٹی برانچ پی او ایس، انوائسنگ، رولز پرمیشنز اور ریئل ٹائم رپورٹس شامل ہوتی ہیں۔ یہ بالکل آپ کے کام کرنے کے طریقے کے مطابق بنایا جاتا ہے۔ آپ کی ٹیم میں کتنے لوگ اس سسٹم کو استعمال کریں گے؟";
        } elseif ($lang === 'ro') {
            $aiReply = "SoftNexa ka custom ERP system aapki inventory, accounts, POS, warehouse aur multi-branch reporting ko ek single cloud dashboard par le aata hai. Hamare sabhi systems 100% bespoke hotay hain. Aapke paas abhi kitne branches ya team members hain, aur kya koi purana Excel/legacy data migrate karna hai?";
        } else {
            $aiReply = "We specialize in custom, modular ERP and CRM platforms—covering inventory, multi-branch POS, automated invoicing, role-based workflows, and live audit logs. Everything is built to match your exact operations. How many branches or active team members will be using this system?";
        }
    }
    // 8. Mobile Apps
    elseif ($isApp) {
        if ($lang === 'ur') {
            $aiReply = "ہم آئی او ایس اور اینڈرائیڈ دونوں کے لیے فاسٹ اور خوبصورت موبائل ایپس (Flutter / React Native) بناتے ہیں، جن میں ریئل ٹائم ٹریکنگ، پش نوٹیفیکیشنز اور پیمنٹ گیٹ ویز شامل ہوتے ہیں۔ آپ کی ایپ کے اہم فیچرز کیا ہوں گے؟";
        } elseif ($lang === 'ro') {
            $aiReply = "Hum iOS aur Android ke liye single-codebase Flutter apps build karte hain jo App Store aur Play Store dono par super smooth perform karti hain (with offline sync & payment gateway integrations). Kya aapke paas design/wireframes ready hain ya scratch se build karna hai?";
        } else {
            $aiReply = "We build high-performance mobile apps for iOS and Android using Flutter and native modules. Features include real-time push notifications, payment gateway integrations, and offline caching. Do you already have UI/wireframes, or should we design the prototype from scratch?";
        }
    }
    // 9. AI Agents & WhatsApp Bots
    elseif ($isAI) {
        if ($lang === 'ur') {
            $aiReply = "ہم کسٹم اے آئی ایجنٹس، واٹس ایپ کسٹمر چیٹ بوٹس اور دستاویزات پڑھنے والے او سی آر سسٹمز بناتے ہیں جو آپ کا 80 فیصد روٹین کام خودکار کر دیتے ہیں۔ آپ کس پروسیس کو اے آئی کے ذریعے آٹومیٹ کرنا چاہتے ہیں؟";
        } elseif ($lang === 'ro') {
            $aiReply = "SoftNexa ke AI agents customer dealing, WhatsApp support, invoice reading (OCR), aur automated reporting ko 24/7 autonomously handle karte hain. Kya aapko WhatsApp sales bot chahiye ya internal company data par trained AI assistant?";
        } else {
            $aiReply = "We engineer autonomous AI agents, WhatsApp business chatbots, and smart document extractors (OCR/PDF) that handle customer inquiries and data workflows 24/7. Are you looking for a customer-facing sales agent or internal document automation?";
        }
    }
    // 10. Direct Contact & WhatsApp
    elseif ($isContact) {
        if ($lang === 'ur') {
            $aiReply = "آپ ہم سے براہ راست آفیشل واٹس ایپ / کال پر رابطہ کر سکتے ہیں: +92 315 2989922 (ڈائریکٹ لنک: https://wa.me/923152989922) یا info@softnexasolution.com پر ای میل بھیج سکتے ہیں۔ ہماری ٹیم فوری رہنمائی کے لیے دستیاب ہے!";
        } elseif ($lang === 'ro') {
            $aiReply = "Aap hum se direct official WhatsApp / Call par rabta kar sakte hain: +92 315 2989922 (Direct chat: https://wa.me/923152989922) ya info@softnexasolution.com par email bhej sakte hain. Hamari senior engineering team aapko foran response de gi!";
        } else {
            $aiReply = "You can reach our engineering leads directly on WhatsApp or phone at +92 315 2989922 (or click: https://wa.me/923152989922), or email us at info@softnexasolution.com. We're ready to discuss your project anytime!";
        }
    }
    // 11. Office Location & Meetings
    elseif ($isLocation) {
        if ($lang === 'ur') {
            $aiReply = "سافٹ نیکسا کا مین انجینئرنگ ہاؤس شاہراہِ فیصل، کراچی میں واقع ہے، اور ہم امریکہ، برطانیہ، مشرق وسطیٰ اور پاکستان بھر کے کلائنٹس کو انٹرپرائز سافٹ ویئر سلوشنز فراہم کرتے ہیں۔ آپ کراچی آفس میں میٹنگ بھی شیڈول کر سکتے ہیں!";
        } elseif ($lang === 'ro') {
            $aiReply = "SoftNexa ka main engineering office Shahrah-e-Faisal, Karachi mein hai. Hum locally Pakistan aur globally US, UK, UAE ke enterprise clients ko serve karte hain. Aap direct call ya Karachi office mein in-person meeting bhi schedule kar sakte hain! Aap kis city/country se baat kar rahe hain?";
        } else {
            $aiReply = "SoftNexa's primary engineering office is located on Shahrah-e-Faisal, Karachi, Pakistan, serving enterprise clients globally across the US, UK, Middle East, and Pakistan. We'd be delighted to schedule a virtual discovery call or an in-person meeting at our office!";
        }
    }
    // 11. Polite Greetings
    elseif ($isGreeting) {
        if ($lang === 'ur') {
            $aiReply = "وعلیکم السلام! میں بالکل ٹھیک ہوں، شکریہ۔ میں سافٹ نیکسا کا اے آئی سلوشنز کنسلٹنٹ ہوں۔ ہم آپ کے کاروبار کے لیے کسٹم ای آر پی، موبائل ایپس، ویب پورٹلز اور اے آئی آٹومیشن تیار کرتے ہیں۔ آپ کس طرح کا سسٹم بنوانا چاہتے ہیں؟";
        } elseif ($lang === 'ro') {
            $aiReply = "Walaikum Assalam! Main theek hoon, shukriya. Main SoftNexa ka Solutions Consultant hoon. Hum businesses ke liye custom enterprise ERPs, scalable web apps, mobile apps aur AI automations design aur develop karte hain. Aap apne business ke liye kis tarah ka software build karna chahte hain?";
        } else {
            $aiReply = "Hello and welcome to SoftNexa! I am your AI Solutions Consultant. We architect custom business software, enterprise ERPs, high-performance web/mobile apps, and AI automations. Tell me about the workflow or problem you'd like to solve!";
        }
    }
    // 12. General Fallback
    else {
        if ($lang === 'ur') {
            $aiReply = "یہ ایک بہترین پروجیکٹ لگ رہا ہے۔ سافٹ نیکسا آپ کے اس سسٹم کو کسٹم کوڈنگ اور ماڈرن ٹیکنالوجی کے ساتھ بنا سکتا ہے۔ اگر آپ چاہیں تو مفت اسکوپ کے لیے اپنا نام اور واٹس ایپ نمبر شیئر کر سکتے ہیں (اختیاری)، یا آپ مجھ سے مزید تفصیلات پوچھ سکتے ہیں۔";
        } elseif ($lang === 'ro') {
            $aiReply = "Yeh requirement boht promising hai! SoftNexa ki team isko modern cloud architecture aur sleek UI ke sath perfect tarike se build kar sakti hai. Agar aap chahein toh apna Name aur WhatsApp number ya Email share kar dein (optional) taake hum free 48-hour scope blueprint share karein, ya aap mujhse mazeed sawalat pooch sakte hain!";
        } else {
            $aiReply = "This sounds like a high-impact initiative! SoftNexa can architect this with a modern, scalable cloud stack. If you would like an exact architectural roadmap, feel free to share your Name and WhatsApp or Email (totally optional), or feel free to ask me anything right here!";
        }
    }
}

// Format full chat conversation log
$fullChatLog = "=== AI Chatbot Conversation ===\n";
foreach ($history as $h) {
    $r = ($h['role'] === 'user' ? 'Client' : 'Assistant');
    $fullChatLog .= "[$r]: " . $h['content'] . "\n\n";
}
$fullChatLog .= "[Client]: " . $message . "\n\n";
$fullChatLog .= "[Assistant]: " . $aiReply . "\n";

// Automatically save / update every chat session into the database (leads table)
$leadCaptured = false;
$hasContact = ($detectedEmail !== '' || $detectedPhone !== '');

try {
    $db = db();
    $existingLead = null;

    if ($sessionId !== '') {
        $st = $db->prepare("SELECT id, name, email, phone, lead_score FROM leads WHERE session_id = ? AND source = 'ai-chatbot' ORDER BY id DESC LIMIT 1");
        $st->execute([$sessionId]);
        $existingLead = $st->fetch();
    }

    if ($existingLead) {
        $leadId = (int)$existingLead['id'];
        $updFields = [
            'problems' => mb_substr($fullChatLog, 0, 3500),
            'message'  => mb_substr($fullChatLog, 0, 4000)
        ];

        $shouldNotify = false;
        if ($detectedPhone !== '' && empty($existingLead['phone'])) {
            $updFields['phone'] = $detectedPhone;
            $shouldNotify = true;
        }
        if ($detectedEmail !== '' && (empty($existingLead['email']) || $existingLead['email'] === 'chat-visitor@softnexasolution.com')) {
            $updFields['email'] = $detectedEmail;
            $shouldNotify = true;
        }
        if ($detectedName !== '' && (strpos($existingLead['name'], 'Chat Visitor') !== false || empty($existingLead['name']))) {
            $updFields['name'] = $detectedName;
        }
        if ($hasContact) {
            $updFields['lead_score'] = max((int)$existingLead['lead_score'], 85);
            $updFields['urgency'] = 'Immediate / Hot';
            $leadCaptured = true;
        }

        $sets = [];
        $vals = [];
        foreach ($updFields as $col => $val) {
            $sets[] = "$col = ?";
            $vals[] = $val;
        }
        $vals[] = $leadId;
        $db->prepare("UPDATE leads SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);

        if ($shouldNotify) {
            $notifyName = $updFields['name'] ?? $existingLead['name'];
            $to = setting('notify_email', NOTIFY_EMAIL);
            $subj = '[HOT 85] New AI Chatbot Lead: ' . $notifyName . ' (' . ($detectedPhone ?: $detectedEmail) . ')';
            $items = [
                'Source'           => 'Interactive AI Sales Chatbot',
                'Phone / WhatsApp' => ($detectedPhone ?: $existingLead['phone'] ?: 'Not provided'),
                'Email'            => ($detectedEmail ?: $existingLead['email'] ?: 'Not provided'),
                'Language'         => strtoupper($lang),
                'Status'           => '🔥 Hot Lead (Direct Chat Engagement)',
                'Session ID'       => $sessionId,
            ];
            $host = $_SERVER['HTTP_HOST'] ?? 'softnexasolution.com';
            $emailHtml = render_lead_email([
                'title'       => $subj,
                'badge'       => '🔥 HOT CHATBOT LEAD (85)',
                'badge_color' => '#EF4444',
                'name'        => $notifyName,
                'company'     => 'Direct Website Visitor',
                'email'       => $detectedEmail ?: $existingLead['email'] ?: 'chat@softnexasolution.com',
                'phone'       => $detectedPhone ?: $existingLead['phone'] ?: '',
                'items'       => $items,
                'message'     => nl2br(htmlspecialchars($fullChatLog)),
                'cta_url'     => 'https://' . $host . '/admin/leads.php?id=' . $leadId,
                'cta_text'    => 'View Chat Lead #' . $leadId,
                'meta'        => ['Country' => country_code(), 'IP' => client_ip()]
            ]);
            send_html_email($to, $subj, $emailHtml, $detectedEmail ?: '');

            // Send customer receipt confirmation email if client provided a valid email
            if ($detectedEmail && filter_var($detectedEmail, FILTER_VALIDATE_EMAIL)) {
                try {
                    $custSubj = 'We Received Your Project Inquiry - SoftNexa Solutions';
                    $custHtml = render_customer_confirmation_email([
                        'name'     => $leadName,
                        'service'  => 'AI Solutions & Custom Software Architecture',
                        'timeline' => '24-48 Hours Scope Review',
                        'estimate' => '100% Fixed-Price Scope Blueprint',
                        'message'  => "Thank you for connecting with our AI Assistant! Our engineering architecture team has logged your inquiry and is reviewing your project requirements.",
                        'phone'    => $detectedPhone ?: '',
                        'source'   => 'AI Assistant Chat Consultation'
                    ]);
                    send_html_email($detectedEmail, $custSubj, $custHtml, 'info@softnexasolution.com');
                } catch (Exception $e) {
                    // Ignore customer email dispatch error to avoid breaking chat flow
                }
            }
        }
    } else {
        $leadName = $detectedName !== '' ? $detectedName : ('Chat Visitor ' . ($sessionId ? '#' . substr($sessionId, 0, 6) : ''));
        $leadRow = [
            'name'       => $leadName,
            'company'    => '',
            'email'      => $detectedEmail ?: 'chat-visitor@softnexasolution.com',
            'phone'      => $detectedPhone ?: '',
            'service'    => 'AI Chatbot Consultation',
            'industry'   => 'Inquired via Chat',
            'problems'   => mb_substr($fullChatLog, 0, 3500),
            'tools'      => 'Chatbot conversation',
            'team'       => 'Pending review',
            'urgency'    => $hasContact ? 'Immediate / Hot' : 'In Discussion',
            'modules'    => '',
            'estimate'   => 'Chat Consultation',
            'timeline'   => 'In progress',
            'trial_days' => 0,
            'founding'   => '',
            'region'     => country_code() === 'PK' ? 'pk' : 'intl',
            'message'    => mb_substr($fullChatLog, 0, 4000),
            'lead_score' => $hasContact ? 85 : 45,
            'source'     => 'ai-chatbot',
            'session_id' => $sessionId,
            'referrer'   => ref_host($_SERVER['HTTP_REFERER'] ?? ''),
            'country'    => country_code(),
            'device'     => device_type($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'ip'         => client_ip(),
            'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ];

        $cols = implode(',', array_keys($leadRow));
        $marks= implode(',', array_fill(0, count($leadRow), '?'));
        $db->prepare("INSERT INTO leads ($cols) VALUES ($marks)")->execute(array_values($leadRow));
        $leadId = $db->lastInsertId();

        if ($hasContact) {
            $leadCaptured = true;
            $to = setting('notify_email', NOTIFY_EMAIL);
            $subj = '[HOT 85] New AI Chatbot Lead: ' . $leadName . ' (' . ($detectedPhone ?: $detectedEmail) . ')';
            $items = [
                'Source'           => 'Interactive AI Sales Chatbot',
                'Phone / WhatsApp' => $detectedPhone ?: 'Not provided',
                'Email'            => $detectedEmail ?: 'Not provided',
                'Language'         => strtoupper($lang),
                'Status'           => '🔥 Hot Lead (Direct Chat Engagement)',
                'Session ID'       => $sessionId,
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

            // Send customer receipt confirmation email if client provided a valid email
            if ($detectedEmail && filter_var($detectedEmail, FILTER_VALIDATE_EMAIL)) {
                try {
                    $custSubj = 'We Received Your Project Inquiry - SoftNexa Solutions';
                    $custHtml = render_customer_confirmation_email([
                        'name'     => $leadName,
                        'service'  => 'AI Solutions & Custom Software Architecture',
                        'timeline' => '24-48 Hours Scope Review',
                        'estimate' => '100% Fixed-Price Scope Blueprint',
                        'message'  => "Thank you for connecting with our AI Assistant! Our engineering architecture team has logged your inquiry and is reviewing your project requirements.",
                        'phone'    => $detectedPhone ?: '',
                        'source'   => 'AI Assistant Chat Consultation'
                    ]);
                    send_html_email($detectedEmail, $custSubj, $custHtml, 'info@softnexasolution.com');
                } catch (Exception $e) {
                    // Ignore customer email dispatch error to avoid breaking chat flow
                }
            }
        }
    }
} catch (Exception $e) {
    // Continue even if DB write fails
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
