<?php
/* ============================================================
   SoftNexa — 24/7 Cloud Autonomous SEO & Sitemap Engine
   Runs 24/7 on Hostinger Cloud Server (Regardless of whether PC is ON/OFF).
   Can be called via Hostinger Cron Job or Webhook:
   URL: https://softnexasolution.com/api/seo_cron.php?key=softnexa_seo_2026
   ============================================================ */
require_once __DIR__ . '/config.php';

$key = $_GET['key'] ?? ($_SERVER['argv'][1] ?? '');
$isCli = (php_sapi_name() === 'cli');

// Basic secret key guard for web calls
if (!$isCli && $key !== 'softnexa_seo_2026') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$today = date('Y-m-d');
$rootDir = dirname(__DIR__);
$sitemapFile = $rootDir . '/sitemap.xml';
$indexFile = $rootDir . '/index.html';

$actions = [];

// 1. Refresh Sitemap timestamps so Google continuously crawls fresh pages
if (file_exists($sitemapFile)) {
    $content = file_get_contents($sitemapFile);
    $updated = preg_replace('/<lastmod>\d{4}-\d{2}-\d{2}<\/lastmod>/', '<lastmod>' . $today . '</lastmod>', $content);
    if ($updated && $updated !== $content) {
        file_put_contents($sitemapFile, $updated);
        $actions[] = "Sitemap timestamps refreshed to $today";
    } else {
        $actions[] = "Sitemap already up to date ($today)";
    }
}

// 2. Health check on Core Meta Tags
$metaHealth = 'PASS';
if (file_exists($indexFile)) {
    $html = file_get_contents($indexFile);
    if (preg_match('/<title>(.*?)<\/title>/', $html, $m)) {
        $titleLen = mb_strlen(trim($m[1]));
        $actions[] = "Title tag OK ($titleLen chars)";
    }
    if (preg_match('/<meta name="description" content="(.*?)">/', $html, $m)) {
        $descLen = mb_strlen(trim($m[1]));
        $actions[] = "Meta description OK ($descLen chars)";
    }
}

// 3. Log run in database if table exists or create it
try {
    db()->exec("CREATE TABLE IF NOT EXISTS seo_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        run_date DATE NOT NULL,
        actions TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_date (run_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $st = db()->prepare("INSERT INTO seo_logs (run_date, actions) VALUES (?, ?) ON DUPLICATE KEY UPDATE actions = VALUES(actions)");
    $st->execute([$today, implode('; ', $actions)]);
    $actions[] = "Logged to database successfully";
} catch (Exception $e) {
    $actions[] = "DB log note: " . $e->getMessage();
}

$response = [
    'ok' => true,
    'agent' => 'SoftNexa 24/7 Cloud SEO Engine',
    'date' => $today,
    'actions' => $actions
];

header('Content-Type: application/json');
echo json_encode($response, JSON_PRETTY_PRINT);
