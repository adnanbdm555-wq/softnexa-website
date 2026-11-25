<?php
/* ============================================================
   SoftNexa Solutions — Daily Autonomous Reels Engine
   Publishes 1 queued vertical MP4 Reel per day to BOTH:
     1) Facebook Page Reels (Meta Video Reels API v21.0)
     2) Instagram Reels (@adnan_promotion via IG Graph API v21.0)
   With automatic Gemini AI viral caption generation & queue auto-loop.

   Hostinger Cron URL (run once daily, e.g. 11:00 AM PKT):
     https://softnexasolution.com/api/cron_auto_reels.php?key=softnexa_reels_2026
   ============================================================ */
require_once __DIR__ . '/config.php';

define('AUTO_REELS_CRON_KEY', 'softnexa_reels_2026');

function init_reels_queue_table() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS social_reels_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            video_url VARCHAR(500) NOT NULL,
            caption TEXT,
            status VARCHAR(30) DEFAULT 'queued',
            fb_video_id VARCHAR(120) DEFAULT '',
            ig_media_id VARCHAR(120) DEFAULT '',
            last_error TEXT,
            posted_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (status), INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('init_reels_queue_table error: ' . $e->getMessage());
    }
}

function generate_reel_viral_caption($title) {
    $geminiKey = trim((string)setting('gemini_api_key', getenv('GEMINI_API_KEY') ?: ''));
    $prompt = "Write a short, punchy, high-converting Facebook & Instagram Reel caption (max 4-5 lines + 6 relevant hashtags) for SoftNexa Solutions (Karachi, Pakistan — serving Pakistan, UAE, UK & USA).
Topic of this Reel: {$title}
Rules:
- Mix natural, modern English + warm Pakistani business appeal.
- Mention our 2-3 weeks fast delivery & free 48-hour scope blueprint.
- Clear Call-to-Action: WhatsApp +92 335 5556290 or explore 54 live demos at https://softnexasolution.com/#/demos
- Do NOT use markdown ** stars. Plain clean social text with 2-3 emojis only.";

    if ($geminiKey !== '') {
        $models = [
            trim((string)setting('gemini_working_model', '')),
            'v1beta/models/gemini-3.8-flash',
            'v1beta/models/gemini-3.5-flash-lite',
            'v1beta/models/gemini-3-flash-preview'
        ];
        $models = array_values(array_unique(array_filter($models)));
        foreach ($models as $ep) {
            $payload = json_encode([
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.78, 'maxOutputTokens' => 900]
            ]);
            $ch = curl_init("https://generativelanguage.googleapis.com/{$ep}:generateContent?key=" . urlencode($geminiKey));
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200 && $res) {
                $j = json_decode($res, true);
                $txt = trim((string)($j['candidates'][0]['content']['parts'][0]['text'] ?? ''));
                if ($txt !== '') {
                    return str_replace('**', '', $txt);
                }
            }
        }
    }

    return "🚀 {$title} — Built by SoftNexa Solutions!\n\nWant to automate sales, bookings & operations for your business in just 2–3 weeks? Explore our 54 live interactive demos or book a free 48-hour scope blueprint today.\n\n💬 WhatsApp: +92 335 5556290\n🌐 Demos: https://softnexasolution.com/#/demos\n\n#SoftNexa #AIAutomation #WhatsAppBot #WebDevelopment #BusinessGrowth #KarachiTech";
}

function meta_http_request($url, $method = 'GET', $postFields = null, $extraHeaders = [], $timeout = 45) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if ($postFields !== null) {
            $opts[CURLOPT_POSTFIELDS] = is_array($postFields) ? http_build_query($postFields) : $postFields;
        }
    }
    if (!empty($extraHeaders)) {
        $opts[CURLOPT_HTTPHEADER] = $extraHeaders;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode((string)$body, true), 'err' => $err];
}

function publish_next_auto_reel($forceManual = false) {
    init_reels_queue_table();

    if (!$forceManual && setting('auto_reels_enabled', '1') !== '1') {
        return ['ok' => false, 'status' => 'paused', 'message' => 'Daily Auto-Reels is currently paused in Admin Settings.'];
    }

    $fbPageToken = trim((string)setting('fb_page_token', ''));
    $fbPageIdCfg = trim((string)setting('fb_page_id', 'me'));
    if ($fbPageIdCfg === '') $fbPageIdCfg = 'me';

    if ($fbPageToken === '') {
        return ['ok' => false, 'status' => 'missing_token', 'message' => 'Facebook Page Access Token missing. Save your Never-Expiring Page Token in Key & Social Settings.'];
    }

    // 1. Pick oldest queued Reel; if empty & auto-loop is enabled, recycle oldest posted Reel
    $reel = db()->query("SELECT * FROM social_reels_queue WHERE status = 'queued' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$reel && setting('auto_reels_loop', '1') === '1') {
        $oldestPosted = db()->query("SELECT * FROM social_reels_queue WHERE status = 'posted' ORDER BY posted_at ASC, id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($oldestPosted) {
            db()->prepare("UPDATE social_reels_queue SET status = 'queued' WHERE id = ?")->execute([$oldestPosted['id']]);
            $reel = $oldestPosted;
        }
    }
    if (!$reel) {
        return ['ok' => false, 'status' => 'empty_queue', 'message' => 'Reels Queue khali hai! Pehle kam az kam 1 MP4 Reel upload karein.'];
    }

    // 2. Resolve real numeric Page ID, Page Access Token & connected Instagram Business Account ID
    // Supports BOTH direct Page Access Tokens AND Never-Expiring System User Tokens!
    $realPageId = '';
    $igBizId    = trim((string)setting('ig_business_id', ''));
    $pageToken  = $fbPageToken;

    // First try /me/accounts (works for Never-Expiring System User Tokens!)
    $accRes = meta_http_request("https://graph.facebook.com/v21.0/me/accounts?fields=id,name,access_token,instagram_business_account{id,username}&access_token=" . urlencode($fbPageToken));
    if (!empty($accRes['json']['data']) && is_array($accRes['json']['data'])) {
        $chosen = $accRes['json']['data'][0];
        foreach ($accRes['json']['data'] as $pg) {
            if ($fbPageIdCfg !== 'me' && ($pg['id'] ?? '') === $fbPageIdCfg) {
                $chosen = $pg;
                break;
            }
        }
        $realPageId = $chosen['id'] ?? '';
        if (!empty($chosen['access_token'])) $pageToken = $chosen['access_token'];
        if (!empty($chosen['instagram_business_account']['id'])) $igBizId = $chosen['instagram_business_account']['id'];
    }

    // Fallback: direct Page Token lookup
    if ($realPageId === '') {
        $meRes = meta_http_request("https://graph.facebook.com/v21.0/" . urlencode($fbPageIdCfg) . "?fields=id,name,instagram_business_account{id,username}&access_token=" . urlencode($fbPageToken));
        $realPageId = $meRes['json']['id'] ?? '';
        if (!empty($meRes['json']['instagram_business_account']['id'])) {
            $igBizId = $meRes['json']['instagram_business_account']['id'];
        }
    }

    if ($realPageId === '') {
        $errMsg = 'Token/Page Error: ' . ($accRes['json']['error']['message'] ?? $meRes['json']['error']['message'] ?? 'Cannot resolve Facebook Page');
        db()->prepare("UPDATE social_reels_queue SET status = 'failed', last_error = ? WHERE id = ?")->execute([$errMsg, $reel['id']]);
        return ['ok' => false, 'status' => 'token_error', 'message' => $errMsg];
    }
    $fbPageToken = $pageToken;

    // 3. Prepare caption (use custom caption or generate fresh Gemini AI caption)
    $caption = trim((string)($reel['caption'] ?? ''));
    if ($caption === '') {
        $caption = generate_reel_viral_caption($reel['title']);
    }
    $videoUrl = trim((string)$reel['video_url']);

    $fbVideoId = '';
    $igMediaId = '';
    $errors = [];

    // 4. PUBLISH TO FACEBOOK PAGE REELS (3-Phase Meta Video Reels API with /videos fallback)
    $startRes = meta_http_request("https://graph.facebook.com/v21.0/{$realPageId}/video_reels", 'POST', [
        'upload_phase' => 'start',
        'access_token' => $fbPageToken
    ]);
    if (!empty($startRes['json']['video_id']) && !empty($startRes['json']['upload_url'])) {
        $vidId = $startRes['json']['video_id'];
        $upUrl = $startRes['json']['upload_url'];
        $upRes = meta_http_request($upUrl, 'POST', '', [
            'Authorization: OAuth ' . $fbPageToken,
            'file_url: ' . $videoUrl
        ], 60);
        if (!empty($upRes['json']['success'])) {
            $finRes = meta_http_request("https://graph.facebook.com/v21.0/{$realPageId}/video_reels", 'POST', [
                'upload_phase' => 'finish',
                'video_id'     => $vidId,
                'video_state'  => 'PUBLISHED',
                'description'  => $caption,
                'access_token' => $fbPageToken
            ]);
            if (!empty($finRes['json']['success']) || !empty($finRes['json']['post_id'])) {
                $fbVideoId = $vidId;
            } else {
                $errors[] = 'FB Reel Finish: ' . ($finRes['json']['error']['message'] ?? 'Unknown error');
            }
        } else {
            $errors[] = 'FB Reel Upload: ' . ($upRes['json']['error']['message'] ?? 'Upload failed');
        }
    }

    // Fallback to standard Page /videos endpoint if Reels 3-phase didn't finish
    if ($fbVideoId === '') {
        $fbFallback = meta_http_request("https://graph.facebook.com/v21.0/{$realPageId}/videos", 'POST', [
            'file_url'     => $videoUrl,
            'description'  => $caption,
            'access_token' => $fbPageToken
        ], [], 60);
        if (!empty($fbFallback['json']['id'])) {
            $fbVideoId = $fbFallback['json']['id'];
            $errors = []; // Cleared because fallback succeeded
        } else {
            $errors[] = 'FB Video: ' . ($fbFallback['json']['error']['message'] ?? ($startRes['json']['error']['message'] ?? 'Failed'));
        }
    }

    // 5. PUBLISH TO INSTAGRAM REELS (if IG Business Account is connected to this Page)
    if ($igBizId !== '') {
        $igCreate = meta_http_request("https://graph.facebook.com/v21.0/{$igBizId}/media", 'POST', [
            'media_type'    => 'REELS',
            'video_url'     => $videoUrl,
            'caption'       => $caption,
            'share_to_feed' => 'true',
            'access_token'  => $fbPageToken
        ]);
        $creationId = $igCreate['json']['id'] ?? '';
        if ($creationId !== '') {
            // Wait for Instagram video processing (up to 36s)
            $igReady = false;
            for ($i = 0; $i < 12; $i++) {
                sleep(3);
                $stRes = meta_http_request("https://graph.facebook.com/v21.0/{$creationId}?fields=status_code&access_token=" . urlencode($fbPageToken));
                $stCode = strtoupper((string)($stRes['json']['status_code'] ?? ''));
                if ($stCode === 'FINISHED') { $igReady = true; break; }
                if ($stCode === 'ERROR' || $stCode === 'EXPIRED') {
                    $errors[] = 'IG Processing Status: ' . $stCode;
                    break;
                }
            }
            if ($igReady) {
                $igPub = meta_http_request("https://graph.facebook.com/v21.0/{$igBizId}/media_publish", 'POST', [
                    'creation_id'  => $creationId,
                    'access_token' => $fbPageToken
                ]);
                if (!empty($igPub['json']['id'])) {
                    $igMediaId = $igPub['json']['id'];
                } else {
                    $errors[] = 'IG Publish: ' . ($igPub['json']['error']['message'] ?? 'Failed');
                }
            } else {
                $errors[] = 'IG Container still processing (check Instagram in 1 min).';
            }
        } else {
            $errors[] = 'IG Create: ' . ($igCreate['json']['error']['message'] ?? 'Check instagram_content_publish permission');
        }
    } else {
        $errors[] = 'IG Not Linked: Connect @adnan_promotion (Professional/Business Account) to SoftNexa Facebook Page to auto-publish IG Reels too.';
    }

    // 6. Save final status in database
    $succeeded = ($fbVideoId !== '' || $igMediaId !== '');
    $errText = implode(' | ', $errors);
    db()->prepare("UPDATE social_reels_queue SET status = ?, fb_video_id = ?, ig_media_id = ?, last_error = ?, posted_at = IF(?, NOW(), posted_at) WHERE id = ?")
        ->execute([$succeeded ? 'posted' : 'failed', $fbVideoId, $igMediaId, $errText, $succeeded ? 1 : 0, $reel['id']]);

    if ($succeeded) {
        set_setting('auto_reels_last_run', date('Y-m-d H:i:s'));
    }

    return [
        'ok'          => $succeeded,
        'reel_id'     => $reel['id'],
        'title'       => $reel['title'],
        'fb_video_id' => $fbVideoId,
        'ig_media_id' => $igMediaId,
        'notes'       => $errText,
        'message'     => $succeeded
            ? ('🎉 Reel Published!' . ($fbVideoId ? " FB Reel ID: {$fbVideoId}" : '') . ($igMediaId ? " | IG Reel ID: {$igMediaId}" : '') . ($errText ? " (Note: {$errText})" : ''))
            : ('⚠️ Publish Failed: ' . $errText)
    ];
}

// Direct Cron HTTP Execution
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    header('Content-Type: application/json; charset=utf-8');
    $reqKey = trim((string)($_GET['key'] ?? ''));
    if ($reqKey !== AUTO_REELS_CRON_KEY) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid cron key']);
        exit;
    }
    $force = (($_GET['force'] ?? '') === '1');
    echo json_encode(publish_next_auto_reel($force));
    exit;
}
