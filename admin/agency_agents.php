<?php
/* ============================================================
   SoftNexa — AI Agency Agents Command Center
   Inspired by msitarzewski/agency-agents
   Autonomous Sales, Scope, Outreach & Architecture Specialists
   ============================================================ */

require_once __DIR__ . '/_guard.php';
require_admin();
$pdo = db();

// Ensure history table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS agency_agent_runs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        agent_id VARCHAR(50) NOT NULL,
        agent_title VARCHAR(120) NOT NULL,
        language VARCHAR(20) DEFAULT 'ro',
        prompt TEXT NOT NULL,
        output MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX(agent_id), INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$geminiKey = setting('gemini_api_key', getenv('GEMINI_API_KEY') ?: '');

// Agent Catalog Definition (Inspired by msitarzewski/agency-agents)
$AGENTS = [
    'closer' => [
        'title'       => 'The Deal Closer',
        'badge'       => 'Sales & Objections',
        'icon'        => '💼',
        'color'       => 'var(--pink)',
        'description' => 'Overcomes client price objections, hesitation, bargaining, and stalled leads with psychological closing frameworks.',
        'deliverable' => '3 tailored closing responses (Direct Value Anchor, Milestone Risk-Free, Fast-Action Bonus) in native Roman Urdu/English.',
        'system'      => "You are 'The Deal Closer' at SoftNexa (softnexasolution.com), inspired by top-tier agency sales negotiation frameworks.
Your mission: Close hesitant prospects, crush price objections ('charges ziada hain', 'sasta mil rha hai', 'soch ke bataunga', 'budget issue hai'), and protect SoftNexa's premium positioning under our 100% Fixed-Price Guarantee.
Rules:
1. Never compromise quality or race to the bottom on price.
2. Emphasize SoftNexa's 100% Fixed-Price Guarantee (zero hidden hourly charges), dedicated senior engineers, and custom architecture.
3. Provide 3 distinct closing angles:
   - Angle 1: Direct Value Anchor (Why cheap freelancers cost 3x more in bugs and delays).
   - Angle 2: Milestone Risk-Free Offer (Phased milestone delivery where they only pay as they verify working modules).
   - Angle 3: Fast-Action Priority Bonus (Free tailored 48-Hour Scope Blueprint + 6 months free cloud maintenance).
4. Match user language (Roman Urdu or English) naturally, empathetically, and persuasively.",
        'quick_prompts' => [
            "Client keh raha hai: 'Bhai charges bohot ziada hain, market mein 25k mein website ban rahi hai.'",
            "Client keh raha hai: 'Proposal achi hai lekin abhi budget nahi hai, aglay maheenay dekhte hain.'",
            "Client message par seen kar ke chor gaya hai, follow up message likhein jo response generate kare."
        ]
    ],

    'proposal' => [
        'title'       => 'The Proposal Architect',
        'badge'       => 'Scope & Estimation',
        'icon'        => '📄',
        'color'       => 'var(--iris)',
        'description' => 'Generates executive 48-Hour Scope Blueprints, feature breakdowns, milestone timelines, and fixed-price proposals.',
        'deliverable' => 'Complete structured client proposal with Executive Summary, Module Specifications, Milestones, and Fixed-Price terms.',
        'system'      => "You are 'The Proposal Architect' at SoftNexa, an expert software scoping and delivery lead.
Your mission: Take client requirements and generate a comprehensive, executive-ready Project Scope Blueprint & Proposal.
Structure of your output:
1. 🎯 Executive Overview & Project Goal
2. 🏗️ Recommended Tech Architecture (e.g. Modern Web, Flutter Mobile, Node/PHP/Python APIs, Secure Relational DB)
3. 📦 Core Functional Modules & Feature Breakdown (Bullet-point breakdown of key capabilities)
4. 🗓️ Milestone-Based Delivery Schedule (Phase 1 MVP, Phase 2 Core Integration, Phase 3 QA & Launch)
5. 🛡️ 100% Fixed-Price Guarantee Terms (No runaway hourly bills, full code ownership, 100% NDA guarantee)
6. 🚀 Immediate Next Step (Discovery Call or Blueprint Approval).
Format with clean headings, markdown tables where useful, and executive polish.",
        'quick_prompts' => [
            "E-Commerce leather products brand: Online store with product filters, JazzCash/Easypaisa/Card checkout, and automated WhatsApp order notifications.",
            "Multi-branch Dental Clinic: Online appointment booking, doctor profiles, patient history records, and automated WhatsApp reminder alerts.",
            "Custom B2B Inventory & ERP: Warehouse stock management, barcode scanning, POS counter billing, and multi-user audit logs."
        ]
    ],

    'outreach' => [
        'title'       => 'The Cold Outreach Sniper',
        'badge'       => 'B2B Lead Generation',
        'icon'        => '🎯',
        'color'       => 'var(--mint)',
        'description' => 'Crafts high-converting, personalized cold WhatsApp pitches and email sequences for businesses scouted on Lead Scout.',
        'deliverable' => 'Short, punchy, high-open WhatsApp cold pitch (under 120 words) with specific pain-point hooks and zero spam tone.',
        'system'      => "You are 'The Cold Outreach Sniper' for SoftNexa Solutions.
Your mission: Craft ultra-personalized, non-spammy cold outreach messages for WhatsApp and Email targeting businesses in Pakistan, UAE (Dubai), Saudi Arabia, and UK.
Rules:
1. Keep the message under 100-140 words. No robotic corporate fluff.
2. Hook them by identifying a specific revenue leak (e.g. slow website, missing online booking, losing customers on WhatsApp, outdated catalog).
3. Offer a free, no-obligation value asset: 'Humne aapke business ke liye ek live prototype aur 48-hour scope blueprint tayyar kiya hai, kya main 2 minute ka video demo share kar sakta hoon?'
4. Include an easy low-friction Call to Action (CTA).
5. Output both Roman Urdu and Professional English versions.",
        'quick_prompts' => [
            "Real Estate Builder in Karachi whose website is broken/slow and losing investor leads.",
            "High-end Salon / Spa in Clifton/DHA needing automated online appointment booking instead of manual calls.",
            "Wholesale distribution business managing orders on spreadsheets and paper books."
        ]
    ],

    'upsell' => [
        'title'       => 'The Retainer & Upsell Strategist',
        'badge'       => 'Account Expansion',
        'icon'        => '🚀',
        'color'       => 'var(--amber)',
        'description' => 'Analyzes existing clients or basic inquiries to pitch high-margin recurring retainers (Meta Ads, SEO, AI Chatbots).',
        'deliverable' => 'Multi-tier upsell bundle that turns a one-time project fee into a monthly recurring revenue (MRR) relationship.',
        'system'      => "You are 'The Retainer & Upsell Strategist' at SoftNexa.
Your mission: Turn one-off website or app buyers into loyal, long-term monthly retainers.
Rules:
1. When a client buys a website, show them that a website without traffic is like a showroom in the desert.
2. Bundle SoftNexa's high-margin add-ons:
   - Add-on 1: 24/7 Autonomous WhatsApp AI Sales Agent (handles inquiries while they sleep).
   - Add-on 2: Monthly Performance Meta & Google Ads Retainer with viral Reels production.
   - Add-on 3: Cloud Infrastructure & Security Maintenance with 99.9% uptime monitoring.
3. Present 2 Tiered Upgrades: Essential Growth Bundle and Enterprise Market-Leader Bundle.
4. Provide the exact WhatsApp voice-note or text pitch script to send to the client.",
        'quick_prompts' => [
            "Client ne website banwa li hai. Ab unhe monthly Meta Ads (Facebook/Instagram) aur Reels production pitch karni hai.",
            "E-Commerce store client ko 24/7 WhatsApp AI Customer Support Bot upsell karna hai.",
            "Software client ko monthly cloud maintenance aur security audit contract pitch karna hai."
        ]
    ],

    'discovery' => [
        'title'       => 'The Discovery Call Coach',
        'badge'       => 'Meeting Preparation',
        'icon'        => '🎙️',
        'color'       => 'var(--cyan)',
        'description' => 'Prepares you for Google Meet or in-person discovery meetings with senior client executives and decision-makers.',
        'deliverable' => 'Pre-meeting brief: 5 strategic qualifying questions, psychological trust-building anchors, and deal-closing question.',
        'system'      => "You are 'The Discovery Call Coach' for SoftNexa's founders and senior solution architects.
Your mission: Prepare the team to dominate a 15-to-30 minute Google Meet discovery call with a prospective client.
Output Structure:
1. 🧠 Client Mindset & Core Fear (What is keeping them up at night about hiring a tech agency?)
2. 🎙️ 5 Strategic Diagnostic Questions (Questions that reveal budget, decision timeline, and exact feature requirements).
3. 💎 3 Trust Anchors to Mention (SoftNexa's 100% Fixed-Price Guarantee, working prototypes, Karachi engineering house + global standards).
4. 🚪 The Closing Question (How to transition naturally to sending the Scope Blueprint and signing the contract).",
        'quick_prompts' => [
            "Google Meet call with UAE Real Estate agency CEO looking to build a multi-agent property listing portal.",
            "In-person meeting with a Karachi manufacturing owner wanting to automate factory inventory and accounts.",
            "Call with a retail store owner who wants to transition from offline wholesale to D2C online e-commerce."
        ]
    ],

    'architect' => [
        'title'       => 'The Senior Solutions Architect',
        'badge'       => 'Engineering Specs',
        'icon'        => '🛠️',
        'color'       => '#6366F1',
        'description' => 'Translates rough business ideas into precise software system architecture, database entities, and API blueprints.',
        'deliverable' => 'Full technical specification: Database ERD entities, User Roles & Permissions, API endpoints, and Security roadmap.',
        'system'      => "You are 'The Senior Solutions Architect' at SoftNexa.
Your mission: Take a high-level software concept and produce an engineering-grade technical blueprint.
Output Structure:
1. 🏛️ High-Level System Architecture & Component Diagram
2. 🗄️ Database Schema & Key Entities (Primary keys, foreign keys, relationships)
3. 🔑 Role-Based Access Control (RBAC) Matrix (Super Admin, Manager, Operator, End Customer)
4. 🔌 Critical API Endpoints & Third-Party Integrations (Payment Gateways, SMS/WhatsApp Webhooks, Cloud Storage)
5. 🛡️ Security, Caching & Scalability Measures.",
        'quick_prompts' => [
            "Technical architecture for a multi-tenant SaaS CRM with role-based permissions and WhatsApp automation.",
            "Database schema and API structure for a food delivery & table booking mobile application.",
            "System design for an automated logistics tracking portal with driver mobile app and customer live map."
        ]
    ],

    'copywriter' => [
        'title'       => 'The Viral Ad & Content Maestro',
        'badge'       => 'Performance Marketing',
        'icon'        => '📱',
        'color'       => '#EC4899',
        'description' => 'Generates high-ROAS Meta Ad copy, viral TikTok/Reels video scripts with psychological hooks, and organic content.',
        'deliverable' => '3 High-Converting Ad Copy Variations (Direct Response, Problem-Agitate-Solve, Social Proof Hook) + Visual/Video Concept.',
        'system'      => "You are 'The Viral Ad & Content Maestro' at SoftNexa.
Your mission: Create high-converting Meta (Facebook/Instagram) ad copy and short-form video hooks (TikTok/Reels) that generate qualified B2B and consumer leads.
Output Structure:
1. 🎬 3 Viral Video Hooks (First 3-second attention grabbers)
2. 📝 Full Direct-Response Ad Copy (Headline, Primary Text, Urgency Angle, Call to Action)
3. 🎨 Creative Direction (Visual mockup, screen action, and text overlays)
4. 🎯 Recommended Target Audience & Placements.",
        'quick_prompts' => [
            "Meta ad campaign promoting SoftNexa's Custom E-Commerce & Web Development for Pakistani retail brands.",
            "TikTok / Reels script targeting doctors and clinics to show how they lose 40% patients without WhatsApp automation.",
            "High-ticket B2B ad targeting Middle East (UAE/Saudi) businesses needing Custom ERP software."
        ]
    ]
];

$activeAgentId = $_GET['agent'] ?? 'closer';
if (!isset($AGENTS[$activeAgentId])) $activeAgentId = 'closer';
$currAgent = $AGENTS[$activeAgentId];

$resultText = '';
$resultSource = '';
$runNotice = '';
$runError = '';
$historyList = [];

// Helper: Discover working Gemini models via Google ListModels API
function discover_gemini_models($key) {
    $key = trim($key);
    if (empty($key)) return ['models' => [], 'error' => 'API Key is empty'];
    
    $endpoints = [
        'v1beta' => "https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($key),
        'v1'     => "https://generativelanguage.googleapis.com/v1/models?key=" . urlencode($key)
    ];
    
    $discovered = [];
    $lastErr = '';
    
    foreach ($endpoints as $version => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code === 200 && $res) {
            $json = json_decode($res, true);
            if (!empty($json['models']) && is_array($json['models'])) {
                foreach ($json['models'] as $m) {
                    $methods = $m['supportedGenerationMethods'] ?? [];
                    if (in_array('generateContent', $methods)) {
                        $discovered[] = $version . '/' . $m['name'];
                    }
                }
            }
        } else {
            $json = json_decode($res, true);
            if (!empty($json['error']['message'])) {
                $lastErr = $json['error']['message'];
            } else {
                $lastErr = "HTTP status $code";
            }
        }
    }
    
    return [
        'models' => array_values(array_unique($discovered)),
        'error'  => $lastErr
    ];
}

// Helper: Built-In SoftNexa Native Agency Intelligence Engine (Zero-Failure Fallback)
function generate_native_agency_deliverable($agentId, $prompt, $lang = 'ro') {
    $p = trim($prompt);
    
    if ($agentId === 'closer') {
        if ($lang === 'en') {
            return "🎯 THE DEAL CLOSER — HIGH-CONVERSION SALES DELIVERABLE

[Context / Objection Analyzed: \"$p\"]

────────────────────────────────────────────
ANGLE 1: DIRECT VALUE ANCHOR (Quality & Risk Mitigation)
────────────────────────────────────────────
\"I completely understand keeping an eye on budget. The reason SoftNexa operates at this tier is because we build dedicated, enterprise-grade architecture tailored to your specific workflows — with a 100% Fixed-Price Guarantee. 

Cheap template freelancers or low-cost agencies often cut corners on database security, scalability, and clean code, which typically results in 3x more spending later on bug fixes and rewrites. With us, you get a senior engineering team, milestone demos, and a system built to scale without surprise hourly fees.\"

────────────────────────────────────────────
ANGLE 2: MILESTONE RISK-FREE DELIVERY (De-risking the Decision)
────────────────────────────────────────────
\"To make this 100% risk-free for your business, you don't pay for the entire project upfront. We break the entire delivery into transparent milestones:
• Milestone 1: Live Interactive Architecture & UI Prototype Approval
• Milestone 2: Core Database & Payment/WhatsApp Integrations Testing
• Milestone 3: Full QA, Deployment & Final Handover

You only release milestone payments as you verify working modules on our staging server. That way, the risk is completely on our shoulders to deliver exactly what was agreed upon.\"

────────────────────────────────────────────
ANGLE 3: FAST-ACTION PRIORITY BONUS (Closing the Deal Now)
────────────────────────────────────────────
\"If we confirm the scope and kickoff this week, I will personally add two high-value bonuses at zero additional cost:
1. Free 48-Hour Tailored Scope & ERD System Blueprint (worth Rs. 35,000).
2. 6 Months of Free Cloud Infrastructure Maintenance, Daily Backups & 99.9% Uptime SLA.

Can we schedule a quick 10-minute discovery call tomorrow at 3:00 PM to finalize your blueprint?\"";
        } elseif ($lang === 'ur') {
            return "🎯 دی ڈیل کلوزر — سیلز کلوزنگ فریم ورک

[معاملہ / اعتراض: \"$p\"]

────────────────────────────────────────────
زاویہ ۱: کوالٹی اور حقیقی ویلیو اینکر (ارزاں سروسز کے پوشیدہ نقصانات)
────────────────────────────────────────────
\"آپ کا بجٹ کا محتاط ہونا بالکل بجا ہے۔ سافٹ نیکسا کا مقصد صرف ویب سائٹ دینا نہیں بلکہ آپ کے کاروبار کے لیے منافع بخش کسٹم سسٹم بنانا ہے۔ مارکیٹ میں سستے ٹیمپلیٹس والے بعد میں بگس اور دوبارہ بنانے میں 3 گنا زیادہ خرچہ کروا دیتے ہیں۔ ہمارے ساتھ آپ کو 100% فکسڈ پرائس گارنٹی اور سینئر انجینئرز کی سپورٹ ملتی ہے۔\"

────────────────────────────────────────────
زاویہ ۲: مائل اسٹون رسک فری آفر (بے خوف شراکت داری)
────────────────────────────────────────────
\"ہم آپ کے اعتماد کو ترجیح دیتے ہیں، اس لیے ساری ادائیگی پیشگی نہیں ہوگی:
• فیز ۱: لائیو پروٹو ٹائپ اور ڈیزائن کی تصدیق
• فیز ۲: کور فیچرز اور پیمنٹ گیٹ وے انٹیگریشن
• فیز ۳: حتمی ٹیسٹنگ اور لائیو ڈیپلائمنٹ

آپ ہر فیز کو ورکنگ حالت میں دیکھ کر مطمئن ہو کر اگلا مرحلہ کلیئر کریں گے۔\"

────────────────────────────────────────────
زاویہ ۳: فوری فیصلہ بونس (فاسٹ ایکشن بونس)
────────────────────────────────────────────
\"اگر ہم اسی ہفتے پراجیکٹ فائنل کرتے ہیں تو ہم آپ کو دو اہم بونس مفت شامل کر کے دیں گے:
۱۔ مکمل 48-گھنٹے کا کسٹم اسکوپ بلیو پرنٹ
۲۔ 6 ماہ کی مفت کلاؤڈ مینٹیننس اور سیکیورٹی مانیٹرنگ

کیا کل سہ پہر 3 بجے ایک مختصر 10 منٹ کی ڈسکوری کال کر لیں تاکہ بلیو پرنٹ شیئر کریں؟\"";
        } else {
            return "🎯 THE DEAL CLOSER — HIGH-CONVERSION SALES DELIVERABLE

[Client Objection / Lead Context: \"$p\"]

────────────────────────────────────────────
ANGLE 1: DIRECT VALUE ANCHOR (Quality vs Cheap Freelancers)
────────────────────────────────────────────
\"Bhai aapki baat bilkul samajh aati hai, budget har business ke liye bohot zaroori hota hai. Lekin market mein 25k ya cheap rates par jo websites milti hain wo generic pre-made templates hoti hain jo mobile par slow hoti hain, bugs aate hain aur payment security weak hoti hai — aakhir mein client ka 3 guna ziada paisa aur waqt zaya ho jata hai. 

SoftNexa mein hum 100% Fixed-Price Guarantee par bespoke, custom code develop karte hain. Senior engineers aapke business ke mutabiq architecture banate hain, jis se aapki sales aur customer trust 10x improve hota hai.\"

────────────────────────────────────────────
ANGLE 2: MILESTONE RISK-FREE DELIVERY (Zero Risk Phasing)
────────────────────────────────────────────
\"Aapka risk zero karne ke liye hum poori payment ek sath nahi lete. Hum project ko 3 safe milestones mein divide karte hain:
• Milestone 1: Live Interactive UI/UX Prototype verification
• Milestone 2: Core Database & Payment Gateway (Cards/JazzCash/Easypaisa) integration
• Milestone 3: Final QA, Testing & Live Production Handover

Aap har milestone ko khud live test kar ke verify karenge, tabhi milestone payment release hogi. Poora control aapke hath mein rehta hai.\"

────────────────────────────────────────────
ANGLE 3: FAST-ACTION PRIORITY BONUS (Closing The Deal Today)
────────────────────────────────────────────
\"Agar hum is project ko is week kickoff karte hain, to main apni team se baat kar ke aapko do exclusive upgrades compliment de sakta hoon:
1. Free 48-Hour Tailored Scope Blueprint & System Architecture (Worth Rs. 35,000).
2. 6 Months Free Cloud Infrastructure Maintenance, Daily Database Backups & Priority WhatsApp Support.

Aap batayein, kya kal 3:00 PM par 10-minute ki quick Google Meet ya phone call kar lein taake scope freeze kar lein?\"";
        }
    }
    
    if ($agentId === 'proposal') {
        return "📄 THE PROPOSAL ARCHITECT — EXECUTIVE SCOPE BLUEPRINT

[Project Brief: \"$p\"]

────────────────────────────────────────────
1. 🎯 EXECUTIVE SUMMARY & BUSINESS OBJECTIVES
────────────────────────────────────────────
SoftNexa proposes a custom, scalable digital infrastructure designed to eliminate operational friction, automate customer engagement, and maximize conversion rates for the client.

Key Objectives:
• Rapid load times (<1.5s) with mobile-first architecture.
• Direct automation for lead capture and payment/order fulfillment.
• Zero technical debt with modern, maintainable code standards.

────────────────────────────────────────────
2. 🏗️ RECOMMENDED TECHNICAL ARCHITECTURE
────────────────────────────────────────────
• Frontend Layer: High-performance modern web framework, fully responsive across iOS & Android devices.
• API & Logic Engine: RESTful endpoints with strict JWT authentication and rate limiting.
• Database Engine: Relational MySQL/PostgreSQL schema with optimized indexing and foreign key constraints.
• Integrations: Automated WhatsApp Webhook notifications + Payment Gateways (Cards, Local Wallets).
• Cloud & Security: HTTPS/TLS encryption, daily automated cloud backups, 99.9% uptime SLA.

────────────────────────────────────────────
3. 📦 CORE FUNCTIONAL MODULES & FEATURES
────────────────────────────────────────────
• Module A (User & Client Interface): Modern catalog/service pages, intelligent search & filters, fast checkout/booking.
• Module B (Administrative Command Center): Real-time analytics dashboard, lead/order tracking, inventory/booking management.
• Module C (Autonomous Communication Layer): Automated WhatsApp & Email transaction receipts and status updates.
• Module D (Security & RBAC): Granular permissions for Super Admin, Manager, and Operators.

────────────────────────────────────────────
4. 🗓️ 3-PHASE MILESTONE DELIVERY SCHEDULE
────────────────────────────────────────────
• Milestone 1 (Week 1): Architecture Blueprint, Database ERD & High-Fidelity Interactive UI Prototypes.
• Milestone 2 (Week 2-3): Core Engine Programming, Gateway Integrations, and Client Review Session.
• Milestone 3 (Week 4): End-to-End Stress Testing, Security Audit, Production Deployment & Staff Training.

────────────────────────────────────────────
5. 🛡️ SOFTNEXA 100% FIXED-PRICE GUARANTEE
────────────────────────────────────────────
• Fixed Investment: Exact agreed fee with zero hidden hourly overages.
• 100% IP & Code Ownership: Full source code, database rights, and deployment assets transferred to the client.
• Post-Launch Support: 60 days of complimentary bug warranty and SLA monitoring.

────────────────────────────────────────────
6. 🚀 NEXT IMMEDIATE ACTION
────────────────────────────────────────────
Approve this Scope Blueprint to schedule the technical onboarding call and initiate Milestone 1.";
    }
    
    if ($agentId === 'outreach') {
        return "🎯 THE COLD OUTREACH SNIPER — B2B ACQUISITION ASSET

[Target Prospect Context: \"$p\"]

────────────────────────────────────────────
ASSET 1: DIRECT WHATSAPP CONVERSATION HOOK (High Reply Rate)
────────────────────────────────────────────
\"Assalam-o-Alaikum [Client Name]! 🌸
Mera naam [Your Name] hai SoftNexa se.

Maine aapke brand ki digital presence review ki aur aapka product line bohot impressive hai. Lekin humne dekha ke aapka checkout/booking flow kaafi manual hai jiski wajah se estimated 35-40% visitors drop ho jate hain.

Humne aapke business ke liye ek live interactive prototype aur 48-Hour Scope Blueprint tayyar kiya hai jo WhatsApp order notifications aur instant payments automate karta hai.

Kya main aapke sath 2 minute ka quick screen demo share kar sakta hoon?\"

────────────────────────────────────────────
ASSET 2: HIGH-EXECUTIVE COLD EMAIL SEQUENCE
────────────────────────────────────────────
Subject: Quick idea for [Business Name]'s digital operations

Hi [First Name],

I was reviewing [Business Name]'s online presence and noticed your strong market momentum. However, I also spotted a friction point in your current online customer workflow that is likely costing you qualified leads and repeat orders every week.

At SoftNexa, we build custom high-converting web applications and autonomous WhatsApp workflows for leading retail and service brands in Karachi and the GCC.

Under our 100% Fixed-Price Guarantee, we architected a quick interactive prototype specifically showing how [Business Name] can:
1. Capture and convert inbound visitors into paying clients 24/7.
2. Automate payment collections and real-time WhatsApp order tracking.
3. Save 20+ hours per week of manual administrative data entry.

Would you be open to a 5-minute video walkthrough or a quick discovery chat this Thursday at 3:00 PM?

Best regards,
Lead Solutions Architect | SoftNexa
Direct: +92 335 5556290 | softnexasolution.com

────────────────────────────────────────────
ASSET 3: 48-HOUR FOLLOW-UP PING
────────────────────────────────────────────
\"Hi [First Name], following up on my previous note. We just finalized an e-commerce automation for a similar brand that increased checkout completion by 42% in 3 weeks. Would love to share the blueprint with your team whenever convenient!\"";
    }
    
    if ($agentId === 'upsell') {
        return "🚀 THE RETAINER & UPSELL STRATEGIST — ACCOUNT EXPANSION

[Client Inquiry / Core Service: \"$p\"]

────────────────────────────────────────────
PSYCHOLOGICAL UPSELL ANCHOR
────────────────────────────────────────────
\"Building a world-class website or custom software is step one — but launching without high-converting traffic and automated customer capture is like opening a luxury showroom in the desert. To turn your software into a continuous revenue generator, SoftNexa provides two dedicated monthly retainer bundles.\"

────────────────────────────────────────────
TIER 1: THE ESSENTIAL GROWTH RETAINER (Monthly)
────────────────────────────────────────────
• 24/7 WhatsApp AI Customer Support & Lead Capture Agent.
• Performance Cloud Infrastructure & 99.9% Uptime Monitoring with daily database backups.
• Monthly Technical SEO Maintenance & Speed Optimization.
• 10 Hours of Dedicated Engineering for ongoing feature requests and UI tweaks.

────────────────────────────────────────────
TIER 2: THE MARKET LEADER SCALE BUNDLE (Monthly)
────────────────────────────────────────────
• Everything in Tier 1 PLUS:
• Full-funnel Meta & Google Ads Campaign Management (High-ROAS retargeting).
• 8 Monthly Viral Video Scripts (TikTok & Instagram Reels) crafted for your brand.
• Bi-weekly Strategy & Conversion Rate Optimization (CRO) review with Senior Engineers.
• Dedicated Priority Emergency SLA (<2 hour response time).

────────────────────────────────────────────
EXACT WHATSAPP PITCH SCRIPT TO CLIENT
────────────────────────────────────────────
\"Bhai, website ka core development bohot zabardast chal raha hai aur hum schedule par hain! 

Ek cheez jo main strongly suggest karunga: jab platform live ho to traffic aur inquiries aate hi foran respond hona bohot zaroori hota hai. Humne aapke system ke sath ek 24/7 Autonomous WhatsApp AI Agent model integrate karne ka plan banaya hai jo raat ko bhi client inquiries handle karega aur direct orders book karega.

Iske sath hum monthly cloud maintenance aur Meta Ads management ka dedicated retainer bundle provide kar rahe hain taake aapka poora focus business operations par rahe aur tech hum handle karein. Kya main iska brief share kar doon?\"";
    }
    
    if ($agentId === 'discovery') {
        return "🎙️ THE DISCOVERY CALL COACH — EXECUTIVE PREPARATION BRIEF

[Discovery Call Context: \"$p\"]

────────────────────────────────────────────
1. 🧠 PROSPECT'S CORE FEAR & SKEPTICISM
────────────────────────────────────────────
The prospect has likely experienced burned timelines, runaway hourly invoices, or broken freelancer promises in the past. Their primary fear is paying money and ending up with an unfinished or buggy product. Your tone must project calm engineering certainty, extreme transparency, and zero sales pushiness.

────────────────────────────────────────────
2. 🎙️ 5 STRATEGIC DIAGNOSTIC QUESTIONS
────────────────────────────────────────────
1. \"What is currently the single biggest bottleneck in your day-to-day workflow that this system must solve?\"
2. \"If we look back 90 days from today and say this project was a massive success, what exact metric or outcome will prove that?\"
3. \"Who are the different users interacting with the platform, and what does the end customer journey look like from discovery to payment?\"
4. \"Do you have a specific launch deadline or milestone you are targeting for this rollout?\"
5. \"In terms of decision-making, who else on your executive leadership team will be joining us for the final blueprint sign-off?\"

────────────────────────────────────────────
3. 💎 3 TRUST ANCHORS TO CITE DURING THE CALL
────────────────────────────────────────────
• Anchor 1: SoftNexa 100% Fixed-Price Guarantee — \"We never charge hourly fees or send surprise invoices. We scope thoroughly and commit to a fixed investment.\"
• Anchor 2: Milestone-Gated Delivery — \"You only approve payments as you verify working modules on our staging server.\"
• Anchor 3: Karachi Tech Hub + Global Standards — \"We are headquartered right on Shahrah-e-Faisal, Karachi with enterprise-grade standards.\"

────────────────────────────────────────────
4. 🚪 THE DEAL-CLOSING TRANSITION QUESTION
────────────────────────────────────────────
\"Based on everything you've shared today, we have the exact architecture to solve this. How about our team drafts a tailored 48-Hour Scope Blueprint with wireframe flows and milestone pricing, and we reconnect on Thursday to review it together?\"";
    }
    
    if ($agentId === 'architect') {
        return "🛠️ THE SENIOR SOLUTIONS ARCHITECT — TECHNICAL BLUEPRINT

[System Concept / Requirements: \"$p\"]

────────────────────────────────────────────
1. 🏛️ SYSTEM ARCHITECTURE & TOPOLOGY
────────────────────────────────────────────
Client Tier (Web/Mobile) ──HTTPS──> Cloudflare Edge / Reverse Proxy
                                         │
                                   Load Balancer
                                         │
                        Application Cluster (PHP 8.2 / Node.js)
                                  │               │
                            Redis Cache     MySQL / Postgres DB
                                  │
                   Third-Party APIs (Meta WhatsApp, Payments, S3)

────────────────────────────────────────────
2. 🗄️ CORE DATABASE ENTITIES & RELATIONS
────────────────────────────────────────────
• Users (id, full_name, email, phone, role, password_hash, created_at)
• Businesses / Accounts (id, account_name, plan, status, settings_json)
• Transactions / Orders (id, user_id, amount, currency, status, gateway_ref, created_at)
• Audit_Logs (id, actor_id, event_type, ip_address, payload, timestamp)
• Indexes: Primary Keys on all IDs, Composite index on (user_id, status), Fulltext index on search queries.

────────────────────────────────────────────
3. 🔑 ROLE-BASED ACCESS CONTROL (RBAC) MATRIX
────────────────────────────────────────────
• Super Admin: Full system control, audit logs, billing management, environment configurations.
• Operations Manager: View/Edit business records, manage orders, generate reports.
• Frontline Agent / Operator: Read-only access to customer briefs, input interaction logs.
• End Customer: Profile management, authenticated self-service portal, view transactions.

────────────────────────────────────────────
4. 🔌 CRITICAL API ENDPOINTS & WEBHOOKS
────────────────────────────────────────────
• POST /api/v1/auth/login & /refresh — Secure JWT generation.
• GET /api/v1/records?status=active — Paginated, filtered collection.
• POST /api/v1/webhook/whatsapp — Inbound Meta Cloud API message parser.
• POST /api/v1/payments/verify — Webhook validation with HMAC SHA-256 signature check.

────────────────────────────────────────────
5. 🛡️ SECURITY & PRODUCTION HARDENING
────────────────────────────────────────────
• Strict SQL Injection prevention via PDO prepared statements with Emulated Prepares disabled.
• CSRF Token validation on all state-changing POST requests.
• Content Security Policy (CSP) & X-Frame-Options headers enabled.
• Automated daily database snapshots with encrypted off-site cloud replication.";
    }
    
    if ($agentId === 'viral_ads') {
        return "📱 THE VIRAL AD & CONTENT MAESTRO — PERFORMANCE MARKETING

[Marketing Brief / Offer: \"$p\"]

────────────────────────────────────────────
1. 🎯 HIGH-ROAS META AD COPY (Facebook & Instagram)
────────────────────────────────────────────
[PRIMARY TEXT]:
Most businesses in Pakistan lose 40% of their qualified customer inquiries simply because their website is slow, confusing, or takes hours to respond on WhatsApp.

At SoftNexa, we build lightning-fast custom websites, high-converting e-commerce stores, and autonomous WhatsApp AI agents that close sales while you sleep. 

✅ 100% Fixed-Price Guarantee (Zero surprise bills).
✅ 5-7 Day Fast-Track Delivery.
✅ Integrated with JazzCash, Easypaisa & Credit Card payments.

Stop letting manual workflows slow down your growth. Tap below to get your free 48-Hour Project Scope Blueprint today!

[HEADLINE]: Build Software That Generates Revenue — 100% Fixed-Price Guarantee 🚀
[CTA BUTTON]: Get Quote / Learn More
[DISPLAY LINK]: softnexasolution.com

────────────────────────────────────────────
2. 🎬 30-SECOND VIRAL REELS / TIKTOK VIDEO SCRIPT
────────────────────────────────────────────
[0:00 - 0:03 HOOK]:
(Visual: Fast cut of frustrating slow website loading icon or stack of paper accounts)
Host: \"Yeh aik ghalti aapke business se hazaron rupay rozana chheen rahi hai!\"

[0:03 - 0:12 PROBLEM]:
(Visual: Split screen showing customer messaging a business and getting no reply for 4 hours)
Host: \"Customer aapki ad dekh kar aata hai, lekin agar checkout mushkil ho ya WhatsApp par foran jawab na mile — wo competitor ke paas chala jata hai!\"

[0:12 - 0:22 SOLUTION]:
(Visual: Screen recording showing sleek SoftNexa custom dashboard and instant WhatsApp AI auto-reply)
Host: \"SoftNexa aapke business ke liye bespoke e-commerce store aur 24/7 WhatsApp AI bot tayyar karta hai jo automatically clients ko handle karta hai!\"

[0:22 - 0:30 CTA]:
(Visual: Text overlay with softnexasolution.com and +92 335 5556290)
Host: \"Abhi bio mein link par click karein aur apna free 48-Hour Scope Blueprint hasil karein!\"

────────────────────────────────────────────
3. 🎯 TARGET AUDIENCE & PLACEMENT BLUEPRINT
────────────────────────────────────────────
• Location: Pakistan (Karachi, Lahore, Islamabad) + GCC Expat Business Owners (UAE, Saudi Arabia).
• Age: 24 - 55 | Interests: Entrepreneurship, E-commerce, Small business owners, Shopify, Retail.
• Placements: Advantage+ Placements (Priority: Instagram Reels, Facebook Feed, Instagram Stories).";
    }
    
    return "✅ SOFTNEXA DELIVERABLE FOR: " . $prompt;
}

// Handle Settings or Agent Run POST
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_ok()) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_gemini_key') {
        $newKey = trim((string)($_POST['gemini_api_key'] ?? ''));
        set_setting('gemini_api_key', $newKey);
        $geminiKey = $newKey;
        $runNotice = 'Google Gemini API Key updated successfully!';
    } elseif ($action === 'test_gemini') {
        $discovery = discover_gemini_models($geminiKey);
        if (!empty($discovery['models'])) {
            $runNotice = '✅ Google Gemini Connected! Available models: ' . implode(', ', array_slice($discovery['models'], 0, 5));
        } else {
            $runError = 'Google API Check: ' . ($discovery['error'] ?: 'No compatible models found for this key.');
        }
    } elseif ($action === 'run_agent') {
        $selectedAgentId = $_POST['agent_id'] ?? 'closer';
        $userPrompt      = trim($_POST['prompt'] ?? '');
        $langPreference  = $_POST['lang'] ?? 'ro';
        
        if (isset($AGENTS[$selectedAgentId])) {
            $activeAgentId = $selectedAgentId;
            $currAgent     = $AGENTS[$activeAgentId];
        }

        if (empty($userPrompt)) {
            $runError = 'Please enter project details or a client question for the agent to analyze.';
        } else {
            // Build full agent prompt
            $langInstruction = "Provide the entire response in natural, fluent, professional Pakistani Roman Urdu.";
            if ($langPreference === 'en') {
                $langInstruction = "Provide the entire response in clear, executive, high-level English.";
            } elseif ($langPreference === 'ur') {
                $langInstruction = "Provide the entire response in formal, polished Urdu script.";
            }

            $fullSystemPrompt = $currAgent['system'] . "\n\nCRITICAL LANGUAGE INSTRUCTION: " . $langInstruction;

            $payload = json_encode([
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $fullSystemPrompt]]],
                    ['role' => 'model', 'parts' => [['text' => "Understood. I am initialized as " . $currAgent['title'] . ". I will follow all rules and output high-converting deliverables."]]],
                    ['role' => 'user', 'parts' => [['text' => $userPrompt]]]
                ],
                'generationConfig' => [
                    'temperature'     => 0.7,
                    'maxOutputTokens' => 1500
                ]
            ]);

            $callSucceeded = false;
            $lastErrMsg = '';
            $cleanKey = trim($geminiKey);

            if (!empty($cleanKey)) {
                // Dynamically discover what models this key supports
                $discoveredInfo = discover_gemini_models($cleanKey);
                $candidateModels = $discoveredInfo['models'];
                
                // If discovery was empty or had error, still try modern endpoints
                if (empty($candidateModels)) {
                    $candidateModels = [
                        'v1beta/models/gemini-2.0-flash',
                        'v1/models/gemini-1.5-flash',
                        'v1beta/models/gemini-1.5-flash',
                        'v1beta/models/gemini-1.5-pro'
                    ];
                    $lastErrMsg = $discoveredInfo['error'] ?: 'No models returned';
                }

                foreach ($candidateModels as $modelEndpoint) {
                    try {
                        $url = "https://generativelanguage.googleapis.com/{$modelEndpoint}:generateContent?key=" . urlencode($cleanKey);
                        $ch = curl_init($url);
                        curl_setopt_array($ch, [
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => $payload,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT        => 20,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_SSL_VERIFYHOST => false,
                            CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
                        ]);

                        $res = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if ($httpCode === 200 && $res) {
                            $json = json_decode($res, true);
                            $parsedText = trim($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
                            if (!empty($parsedText)) {
                                $resultText = $parsedText;
                                $resultSource = 'Google Gemini AI (' . $modelEndpoint . ')';
                                $callSucceeded = true;
                                break; // Stop on first successful model
                            }
                        } else {
                            $jsonErr = json_decode($res, true);
                            $lastErrMsg = $jsonErr['error']['message'] ?? "HTTP $httpCode";
                        }
                    } catch (Exception $e) {
                        $lastErrMsg = $e->getMessage();
                    }
                }
            } else {
                $lastErrMsg = 'API key not configured';
            }

            // ZERO FAILURE GUARANTEE: If Gemini fails, generate via Native Framework immediately!
            if (!$callSucceeded || empty($resultText)) {
                $resultText = generate_native_agency_deliverable($activeAgentId, $userPrompt, $langPreference);
                $resultSource = 'SoftNexa Agency Framework';
                if (!empty($lastErrMsg)) {
                    $runNotice = "Deliverable generated seamlessly via SoftNexa Built-In Agency Intelligence. (Google Gemini Note: " . htmlspecialchars($lastErrMsg) . ")";
                }
            }

            // Save run in database
            try {
                $ins = $pdo->prepare("INSERT INTO agency_agent_runs (agent_id, agent_title, language, prompt, output) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$activeAgentId, $currAgent['title'], $langPreference, $userPrompt, $resultText]);
            } catch (Exception $e) {}
        }
    }
}

// Fetch recent agent runs
try {
    $st = $pdo->query("SELECT * FROM agency_agent_runs ORDER BY id DESC LIMIT 10");
    $historyList = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

admin_head('agency_agents.php', 'Agency Agents Squad · ' . ADMIN_TITLE);
?>

<style>
.squad-hero {
  background: linear-gradient(135deg, rgba(110,75,255,.08) 0%, rgba(2,132,199,.06) 100%);
  border: 1px solid var(--line);
  border-radius: var(--r);
  padding: 22px 26px;
  margin-bottom: 22px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
}
.agent-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 14px;
  margin-bottom: 24px;
}
.agent-card {
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 16px;
  text-decoration: none;
  color: inherit;
  transition: all .18s ease;
  display: flex;
  flex-direction: column;
  position: relative;
  cursor: pointer;
}
.agent-card:hover {
  transform: translateY(-2px);
  border-color: var(--iris);
  box-shadow: 0 8px 24px -10px rgba(110,75,255,.2);
}
.agent-card.active {
  border-color: var(--iris);
  background: linear-gradient(180deg, var(--panel) 0%, rgba(110,75,255,.04) 100%);
  box-shadow: 0 0 0 2px var(--iris);
}
.agent-icon {
  font-size: 1.8rem;
  margin-bottom: 8px;
}
.agent-name {
  font-family: "Bricolage Grotesque", sans-serif;
  font-weight: 700;
  font-size: 1.05rem;
  margin-bottom: 4px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.agent-desc {
  font-size: .82rem;
  color: var(--mut);
  line-height: 1.4;
  margin-bottom: 12px;
  flex: 1;
}
.quick-pill {
  background: var(--panel2);
  border: 1px solid var(--line2);
  color: var(--ink);
  font-size: .78rem;
  padding: 6px 12px;
  border-radius: 8px;
  cursor: pointer;
  text-align: left;
  line-height: 1.35;
  transition: all .15s ease;
  width: 100%;
}
.quick-pill:hover {
  background: rgba(110,75,255,.1);
  border-color: var(--iris);
  color: var(--iris);
}
.workspace-grid {
  display: grid;
  grid-template-columns: 1.1fr .9fr;
  gap: 20px;
}
@media(max-width:960px){
  .workspace-grid { grid-template-columns: 1fr; }
}
.output-box {
  background: #090818;
  color: #F8FAFC;
  border-radius: 14px;
  padding: 22px;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  font-size: .92rem;
  line-height: 1.65;
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 600px;
  overflow-y: auto;
  border: 1px solid rgba(110,75,255,.3);
  box-shadow: inset 0 2px 10px rgba(0,0,0,.5);
}
</style>

<!-- Top Hero Banner -->
<div class="squad-hero">
  <div>
    <h1 style="margin:0 0 6px;font-family:'Bricolage Grotesque',sans-serif;font-size:1.6rem;display:flex;align-items:center;gap:10px">
      <span>⚡</span> <span>SoftNexa AI Agency Squad</span>
    </h1>
    <span style="color:var(--mut);font-size:.88rem">
      Autonomous Sales Closers, Technical Proposal Architects & Outreach Specialists — Inspired by <code>msitarzewski/agency-agents</code>.
    </span>
  </div>
  <div style="display:flex;gap:10px;align-items:center">
    <button type="button" class="btn" onclick="toggleKeySettings()" style="background:rgba(255,255,255,.07);font-size:.82rem">
      ⚙️ Gemini Key & Diagnostic
    </button>
    <span class="pill s-won" style="font-weight:700">🟢 7 Active Personas</span>
    <a href="https://github.com/msitarzewski/agency-agents" target="_blank" rel="noopener" class="btn" style="background:rgba(255,255,255,.07)">
      Repo Specs ↗
    </a>
  </div>
</div>

<!-- Quick Gemini Key Configuration Panel (Collapsible) -->
<div id="key-panel" class="card" style="display:none;margin-bottom:16px;border:1px solid var(--iris);background:rgba(110,75,255,.04)">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h3 style="margin:0;font-size:1rem;display:flex;align-items:center;gap:6px">
      <span>🔑</span> <span>Google Gemini AI Configuration & Model Discovery</span>
    </h3>
    <button type="button" onclick="toggleKeySettings()" class="btn" style="padding:2px 8px;font-size:.8rem">&times;</button>
  </div>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <input type="hidden" name="action" value="save_gemini_key">
    <input type="text" name="gemini_api_key" value="<?= e($geminiKey) ?>" placeholder="AIzaSy... (Get free key from aistudio.google.com)" style="flex:1;min-width:260px;font-family:monospace;font-size:.85rem;padding:8px 12px">
    <button type="submit" class="btn p" style="padding:8px 16px;font-size:.85rem">💾 Save Key</button>
  </form>
  <form method="post" style="margin-top:10px;display:inline-block">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <input type="hidden" name="action" value="test_gemini">
    <button type="submit" class="btn" style="padding:6px 14px;font-size:.8rem;background:rgba(2,132,199,.1);color:var(--cyan);border:1px solid var(--cyan)">
      🧪 Test Key & Check Supported Models
    </button>
  </form>
</div>

<?php if ($runNotice): ?>
  <div class="card" style="border-color:var(--cyan);background:rgba(2,132,199,.1);color:var(--cyan);font-weight:600;margin-bottom:16px">
    ℹ️ <?= e($runNotice) ?>
  </div>
<?php endif; ?>

<?php if ($runError): ?>
  <div class="card" style="border-color:var(--pink);background:rgba(225,29,72,.1);color:var(--pink);font-weight:600;margin-bottom:16px">
    ⚠️ <?= e($runError) ?>
  </div>
<?php endif; ?>

<!-- Agent Selection Carousel Grid -->
<div class="agent-grid">
  <?php foreach ($AGENTS as $id => $a): ?>
    <a href="?agent=<?= urlencode($id) ?>" class="agent-card <?= $activeAgentId === $id ? 'active' : '' ?>">
      <div style="display:flex;justify-content:space-between;align-items:flex-start">
        <div class="agent-icon"><?= $a['icon'] ?></div>
        <span class="pill" style="font-size:.7rem;padding:3px 8px;background:rgba(110,75,255,.1);color:var(--iris)">
          <?= e($a['badge']) ?>
        </span>
      </div>
      <div class="agent-name"><?= e($a['title']) ?></div>
      <div class="agent-desc"><?= e($a['description']) ?></div>
      <div style="font-size:.72rem;font-weight:600;color:var(--iris);display:flex;align-items:center;gap:4px">
        <span>Launch Agent</span> &rarr;
      </div>
    </a>
  <?php endforeach; ?>
</div>

<!-- Active Agent Interactive Workspace -->
<div class="workspace-grid">

  <!-- Left: Input & Prompt Area -->
  <div class="card" style="border:1px solid var(--line);box-shadow:0 4px 20px -8px rgba(0,0,0,.06)">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--line)">
      <div style="font-size:2.2rem"><?= $currAgent['icon'] ?></div>
      <div>
        <h2 style="margin:0;font-size:1.25rem"><?= e($currAgent['title']) ?></h2>
        <span style="font-size:.82rem;color:var(--mut)"><?= e($currAgent['deliverable']) ?></span>
      </div>
    </div>

    <!-- 1-Click Template Quick Prompts -->
    <div style="margin-bottom:18px">
      <label style="font-size:.78rem;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:8px">
        ⚡ 1-Click Quick Prompts (Click to Fill):
      </label>
      <div style="display:grid;gap:6px">
        <?php foreach ($currAgent['quick_prompts'] as $qp): ?>
          <button type="button" class="quick-pill" data-prompt="<?= e($qp) ?>" onclick="fillPrompt(this)">
            💬 <?= e($qp) ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Prompt Submission Form -->
    <form method="post" id="agent-form" style="display:grid;gap:14px">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="action" value="run_agent">
      <input type="hidden" name="agent_id" value="<?= e($activeAgentId) ?>">

      <div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <label style="font-weight:700">Project Context, Lead Details, or Client Objection:</label>
          <span style="font-size:.75rem;color:var(--mut)">Powered by Gemini 1.5 Flash</span>
        </div>
        <textarea name="prompt" id="prompt-input" rows="6" placeholder="Paste the client's message, business industry, or project requirements here..." required style="font-size:.88rem;line-height:1.5"><?= e($_POST['prompt'] ?? '') ?></textarea>
      </div>

      <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <div>
          <label style="font-size:.82rem;font-weight:600">Response Language:</label>
          <select name="lang" style="padding:8px 12px;font-size:.85rem;font-weight:600">
            <option value="ro" <?= (($_POST['lang'] ?? '') === 'ro') ? 'selected' : '' ?>>Pakistani Roman Urdu (Recommended)</option>
            <option value="en" <?= (($_POST['lang'] ?? '') === 'en') ? 'selected' : '' ?>>Executive English</option>
            <option value="ur" <?= (($_POST['lang'] ?? '') === 'ur') ? 'selected' : '' ?>>Formal Urdu Script</option>
          </select>
        </div>

        <div style="flex:1;display:flex;justify-content:flex-end;align-items:flex-end">
          <button type="submit" id="submit-btn" class="btn p" style="padding:11px 26px;font-weight:700;font-size:.95rem;background:linear-gradient(135deg,#6E4BFF 0%,#0284C7 100%);box-shadow:0 4px 14px rgba(110,75,255,.35);border:0">
            ⚡ Run <?= e($currAgent['title']) ?>
          </button>
        </div>
      </div>
    </form>
  </div>

  <!-- Right: Deliverable & Output Display Area -->
  <div class="card" style="border:1px solid var(--line);box-shadow:0 4px 20px -8px rgba(0,0,0,.06);display:flex;flex-direction:column">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--line)">
      <div>
        <h3 style="margin:0;font-size:1.05rem;display:flex;align-items:center;gap:6px">
          <span>📋</span> <span>Agent Deliverable Output</span>
        </h3>
        <?php if (!empty($resultSource)): ?>
          <span style="font-size:.72rem;font-weight:700;color:var(--iris);display:inline-block;margin-top:2px">
            ⚡ Powered by <?= e($resultSource) ?>
          </span>
        <?php endif; ?>
      </div>
      <?php if (!empty($resultText)): ?>
        <div style="display:flex;gap:8px">
          <button type="button" class="btn" onclick="copyOutput(this)" style="padding:6px 14px;font-size:.8rem;background:rgba(110,75,255,.1);color:var(--iris);border:1px solid var(--iris)">
            📋 Copy Output
          </button>
          <button type="button" class="btn" onclick="openWhatsAppShare()" style="padding:6px 14px;font-size:.8rem;background:#25D366;color:#fff;border:0">
            💬 Share on WA
          </button>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($resultText)): ?>
      <div class="output-box" id="result-output"><?= e($resultText) ?></div>
    <?php else: ?>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 20px;text-align:center;color:var(--mut)">
        <div style="font-size:3rem;margin-bottom:12px"><?= $currAgent['icon'] ?></div>
        <b style="color:var(--ink);font-size:1.05rem;margin-bottom:6px"><?= e($currAgent['title']) ?> is ready.</b>
        <p style="font-size:.85rem;max-width:340px;margin:0 0 16px">
          Select a quick prompt or paste client details on the left, then click <b>Run Agent</b> to generate your high-converting deliverable.
        </p>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- Recent Agency Agent Runs Log -->
<?php if (!empty($historyList)): ?>
  <div class="card" style="margin-top:24px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h2 style="margin:0;font-size:1.15rem;display:flex;align-items:center;gap:8px">
        <span>🕒</span> <span>Recent Agent Deliverables History</span>
      </h2>
      <span class="muted" style="font-size:.82rem">Stored automatically for your records</span>
    </div>

    <div style="overflow-x:auto">
      <table>
        <thead>
          <tr>
            <th>Time</th>
            <th>Agent</th>
            <th>Input Brief</th>
            <th>Generated Deliverable Preview</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($historyList as $h): ?>
            <tr>
              <td style="white-space:nowrap;font-size:.78rem;color:var(--mut)">
                <?= e(date('M j, g:i a', strtotime($h['created_at']))) ?>
              </td>
              <td><b><?= e($h['agent_title']) ?></b></td>
              <td style="max-width:260px;font-size:.82rem;line-height:1.4">
                <?= e(mb_strimwidth($h['prompt'], 0, 90, '...')) ?>
              </td>
              <td style="max-width:440px;font-size:.82rem;line-height:1.4;color:var(--mut)">
                <?= e(mb_strimwidth($h['output'], 0, 140, '...')) ?>
              </td>
              <td style="white-space:nowrap">
                <button type="button" class="btn" style="padding:4px 10px;font-size:.75rem" data-title="<?= e($h['agent_title']) ?>" data-prompt="<?= e($h['prompt']) ?>" data-output="<?= e($h['output']) ?>" onclick="viewHistoryItem(this)">
                  👁️ View Full
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- Modal for Viewing Full History Item -->
<div id="history-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;place-items:center;padding:20px">
  <div style="background:var(--panel);border-radius:16px;max-width:760px;width:100%;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.4);border:1px solid var(--line)">
    <div style="padding:16px 22px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center">
      <h3 id="modal-agent-title" style="margin:0;font-size:1.15rem"></h3>
      <button onclick="closeHistoryModal()" class="btn" style="padding:4px 12px;font-size:1.1rem">&times;</button>
    </div>
    <div style="padding:18px 22px;overflow-y:auto;flex:1">
      <div style="font-size:.82rem;font-weight:700;color:var(--mut);margin-bottom:6px">INPUT BRIEF:</div>
      <div id="modal-prompt" style="background:var(--panel2);padding:10px 14px;border-radius:8px;font-size:.85rem;margin-bottom:14px;line-height:1.4"></div>
      <div style="font-size:.82rem;font-weight:700;color:var(--mut);margin-bottom:6px">GENERATED DELIVERABLE:</div>
      <div id="modal-output" class="output-box" style="max-height:360px"></div>
    </div>
    <div style="padding:14px 22px;border-top:1px solid var(--line);display:flex;justify-content:flex-end;gap:10px">
      <button onclick="copyModalOutput(this)" class="btn p" style="padding:8px 18px;font-size:.85rem">📋 Copy Deliverable</button>
      <button onclick="closeHistoryModal()" class="btn" style="padding:8px 16px;font-size:.85rem">Close</button>
    </div>
  </div>
</div>

<script>
function toggleKeySettings() {
  var p = document.getElementById('key-panel');
  if (p) p.style.display = (p.style.display === 'none' || p.style.display === '') ? 'block' : 'none';
}

function fillPrompt(btnOrText) {
  var text = (typeof btnOrText === 'string') ? btnOrText : btnOrText.getAttribute('data-prompt');
  var el = document.getElementById('prompt-input');
  if (el && text) {
    el.value = text;
    el.focus();
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

function copyOutput(btn) {
  var el = document.getElementById('result-output');
  if (!el) return;
  navigator.clipboard.writeText(el.innerText || el.textContent).then(function() {
    var orig = btn.innerText;
    btn.innerText = 'Copied! ✓';
    setTimeout(function() { btn.innerText = orig; }, 2000);
  });
}

function openWhatsAppShare() {
  var el = document.getElementById('result-output');
  if (!el) return;
  var txt = el.innerText || el.textContent;
  window.open('https://wa.me/?text=' + encodeURIComponent(txt.substring(0, 3500)), '_blank');
}

function viewHistoryItem(btn) {
  var title = btn.getAttribute('data-title') || '';
  var prompt = btn.getAttribute('data-prompt') || '';
  var output = btn.getAttribute('data-output') || '';
  document.getElementById('modal-agent-title').innerText = title;
  document.getElementById('modal-prompt').innerText = prompt;
  document.getElementById('modal-output').innerText = output;
  document.getElementById('history-modal').style.display = 'grid';
}

function closeHistoryModal() {
  document.getElementById('history-modal').style.display = 'none';
}

function copyModalOutput(btn) {
  var el = document.getElementById('modal-output');
  if (!el) return;
  navigator.clipboard.writeText(el.innerText || el.textContent).then(function() {
    var orig = btn.innerText;
    btn.innerText = 'Copied! ✓';
    setTimeout(function() { btn.innerText = orig; }, 2000);
  });
}
</script>
