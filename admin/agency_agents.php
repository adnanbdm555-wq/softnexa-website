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
$runError = '';
$historyList = [];

// Handle Agent Run POST
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_ok()) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'run_agent') {
        $selectedAgentId = $_POST['agent_id'] ?? 'closer';
        $userPrompt      = trim($_POST['prompt'] ?? '');
        $langPreference  = $_POST['lang'] ?? 'ro';
        
        if (isset($AGENTS[$selectedAgentId])) {
            $activeAgentId = $selectedAgentId;
            $currAgent     = $AGENTS[$activeAgentId];
        }

        if (empty($userPrompt)) {
            $runError = 'Please enter project details or a client question for the agent to analyze.';
        } elseif (empty($geminiKey)) {
            $runError = 'Google Gemini API Key is missing. Please add your free key in WhatsApp Bot settings or Settings.';
        } else {
            // Build full agent prompt
            $langInstruction = "Provide the entire response in natural, fluent, professional Pakistani Roman Urdu.";
            if ($langPreference === 'en') {
                $langInstruction = "Provide the entire response in clear, executive, high-level English.";
            } elseif ($langPreference === 'ur') {
                $langInstruction = "Provide the entire response in formal, polished Urdu script.";
            }

            $fullSystemPrompt = $currAgent['system'] . "\n\nCRITICAL LANGUAGE INSTRUCTION: " . $langInstruction;

            // Multi-model auto-fallback (tries Gemini 2.0 Flash, 1.5 Flash v1, latest, 001, and 1.5 Pro)
            $candidateModels = [
                'v1beta/models/gemini-2.0-flash',
                'v1/models/gemini-1.5-flash',
                'v1beta/models/gemini-1.5-flash-latest',
                'v1beta/models/gemini-1.5-flash',
                'v1beta/models/gemini-1.5-flash-001',
                'v1beta/models/gemini-1.5-pro',
                'v1/models/gemini-pro'
            ];

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

            foreach ($candidateModels as $modelEndpoint) {
                try {
                    $url = "https://generativelanguage.googleapis.com/{$modelEndpoint}:generateContent?key=" . urlencode($geminiKey);
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $payload,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 25,
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
                            $callSucceeded = true;
                            
                            // Save run in database
                            try {
                                $ins = $pdo->prepare("INSERT INTO agency_agent_runs (agent_id, agent_title, language, prompt, output) VALUES (?, ?, ?, ?, ?)");
                                $ins->execute([$activeAgentId, $currAgent['title'], $langPreference, $userPrompt, $resultText]);
                            } catch (Exception $e) {}
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

            if (!$callSucceeded && empty($resultText)) {
                $runError = 'Error communicating with AI Brain: ' . htmlspecialchars($lastErrMsg);
            }
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
    <span class="pill s-won" style="font-weight:700">🟢 7 Active Agency Personas</span>
    <a href="https://github.com/msitarzewski/agency-agents" target="_blank" rel="noopener" class="btn" style="background:rgba(255,255,255,.07)">
      Repo Specs ↗
    </a>
  </div>
</div>

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
      <h3 style="margin:0;font-size:1.05rem;display:flex;align-items:center;gap:6px">
        <span>📋</span> <span>Agent Deliverable Output</span>
      </h3>
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
