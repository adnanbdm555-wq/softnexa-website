<?php
require_once __DIR__ . '/_guard.php';
require_admin();
$pdo = db();

/* ============================================================
   B2B LEAD SCOUT & OUTREACH ENGINE — MIDDLE EAST & PAKISTAN
   ============================================================ */

$flash = '';
$flashType = 'success';

// Ensure scraped_leads table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS scraped_leads (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        source_type VARCHAR(30) NOT NULL DEFAULT 'google_maps',
        sector VARCHAR(50) NOT NULL DEFAULT '',
        city VARCHAR(80) NOT NULL DEFAULT '',
        name VARCHAR(160) DEFAULT '',
        company VARCHAR(180) DEFAULT '',
        phone VARCHAR(60) DEFAULT '',
        email VARCHAR(160) DEFAULT '',
        website VARCHAR(255) DEFAULT '',
        linkedin_url VARCHAR(255) DEFAULT '',
        address VARCHAR(255) DEFAULT '',
        rating VARCHAR(80) DEFAULT '',
        country VARCHAR(20) DEFAULT 'GCC',
        status VARCHAR(30) DEFAULT 'new',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX(source_type), INDEX(sector), INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$serperKey = trim((string)setting('serper_api_key', ''));

// Helper: Extract public email from website homepage (fast 3s timeout)
function extract_email_from_url($url) {
    if (empty($url) || !preg_match('#^https?://#i', $url)) return '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 2,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SoftNexaBot/1.0)'
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) return '';
    if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}/', $html, $m)) {
        foreach ($m[0] as $em) {
            $emLower = strtolower($em);
            if (!preg_match('/(example\.com|sentry\.io|wixpress|wordpress|@2x\.|png|jpg|svg|webp|noreply|no-reply)/i', $emLower)) {
                return $emLower;
            }
        }
    }
    return '';
}

// Helper: Live Scrape via Serper.dev (Google Maps Places OR LinkedIn X-Ray)
function run_serper_scrape($apiKey, $mode, $query, $city) {
    $endpoint = ($mode === 'linkedin') ? 'https://google.serper.dev/search' : 'https://google.serper.dev/places';
    $q = ($mode === 'linkedin')
        ? 'site:linkedin.com/in/ ("CEO" OR "Founder" OR "Managing Director" OR "Owner") "' . $query . '" "' . $city . '"'
        : $query . ' in ' . $city;

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['q' => $q, 'num' => 15]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'X-API-KEY: ' . $apiKey,
            'Content-Type: application/json'
        ]
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$res) {
        $errJson = json_decode((string)$res, true);
        return ['ok' => false, 'error' => $errJson['message'] ?? "Serper API HTTP $code"];
    }
    $data = json_decode($res, true);
    $results = [];

    $countryCode = 'GCC';
    if (stripos($city, 'Dubai') !== false || stripos($city, 'Abu Dhabi') !== false || stripos($city, 'Sharjah') !== false) $countryCode = 'UAE';
    elseif (stripos($city, 'Riyadh') !== false || stripos($city, 'Jeddah') !== false) $countryCode = 'KSA';
    elseif (stripos($city, 'Karachi') !== false || stripos($city, 'Lahore') !== false || stripos($city, 'Islamabad') !== false) $countryCode = 'PK';

    if ($mode === 'linkedin') {
        foreach (($data['organic'] ?? []) as $item) {
            $title = trim((string)($item['title'] ?? ''));
            $link  = trim((string)($item['link'] ?? ''));
            $snip  = trim((string)($item['snippet'] ?? ''));
            if (empty($link)) continue;
            $parts = preg_split('/\s*[-–|]\s*/', $title);
            $personName = trim($parts[0] ?? 'Decision Maker');
            $companyOrRole = trim($parts[1] ?? $title);
            $emailFound = '';
            if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}/', $snip, $em)) {
                $emailFound = strtolower($em[0]);
            }
            $results[] = [
                'source_type'  => 'linkedin',
                'name'         => $personName,
                'company'      => $companyOrRole,
                'phone'        => '',
                'email'        => $emailFound,
                'website'      => '',
                'linkedin_url' => $link,
                'address'      => $city . ' · LinkedIn Decision Maker',
                'rating'       => '💼 LinkedIn Profile',
                'country'      => $countryCode
            ];
        }
    } else {
        $count = 0;
        foreach (($data['places'] ?? []) as $pl) {
            $title   = trim((string)($pl['title'] ?? ''));
            if (empty($title)) continue;
            $phone   = trim((string)($pl['phoneNumber'] ?? ''));
            $website = trim((string)($pl['website'] ?? ''));
            $address = trim((string)($pl['address'] ?? $city));
            $rating  = isset($pl['rating']) ? ($pl['rating'] . ' ★ (' . ($pl['ratingCount'] ?? 0) . ' reviews)') : 'Google Business';
            // Extract email for first 6 websites to keep scrape fast
            $email = ($website && $count < 6) ? extract_email_from_url($website) : '';
            $count++;
            $results[] = [
                'source_type'  => 'google_maps',
                'name'         => $title,
                'company'      => $title,
                'phone'        => $phone,
                'email'        => $email,
                'website'      => $website,
                'linkedin_url' => '',
                'address'      => $address,
                'rating'       => $rating,
                'country'      => $countryCode
            ];
        }
    }
    return ['ok' => true, 'leads' => $results];
}

// Helper: Live Scrape via SerpApi.com (Google Maps + LinkedIn X-Ray — Works Globally)
function run_serpapi_scrape($apiKey, $mode, $query, $city) {
    $countryCode = 'GCC';
    if (in_array($city, ['Dubai', 'Abu Dhabi', 'Sharjah'])) $countryCode = 'UAE';
    elseif (in_array($city, ['Riyadh', 'Jeddah'])) $countryCode = 'KSA';
    elseif (in_array($city, ['Karachi', 'Lahore', 'Islamabad'])) $countryCode = 'PK';

    if ($mode === 'linkedin') {
        $q = 'site:linkedin.com/in/ ("CEO" OR "Founder" OR "Managing Director" OR "Owner") "' . $query . '" "' . $city . '"';
        $url = 'https://serpapi.com/search.json?engine=google&q=' . urlencode($q) . '&num=15&api_key=' . urlencode($apiKey);
    } else {
        $q = $query . ' in ' . $city;
        $url = 'https://serpapi.com/search.json?engine=google_maps&q=' . urlencode($q) . '&type=search&api_key=' . urlencode($apiKey);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 18,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$res) {
        $errJson = json_decode((string)$res, true);
        return ['ok' => false, 'error' => $errJson['error'] ?? "SerpApi HTTP $code"];
    }
    $data = json_decode($res, true);
    if (!empty($data['error'])) {
        return ['ok' => false, 'error' => $data['error']];
    }

    $results = [];
    if ($mode === 'linkedin') {
        foreach (($data['organic_results'] ?? []) as $item) {
            $title = trim((string)($item['title'] ?? ''));
            $link  = trim((string)($item['link'] ?? ''));
            $snip  = trim((string)($item['snippet'] ?? ''));
            if (empty($link)) continue;
            $parts = preg_split('/\s*[-–|]\s*/', $title);
            $personName = trim($parts[0] ?? 'Decision Maker');
            $companyOrRole = trim($parts[1] ?? $title);
            $emailFound = '';
            if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}/', $snip, $em)) {
                $emailFound = strtolower($em[0]);
            }
            $results[] = [
                'source_type'  => 'linkedin',
                'name'         => $personName,
                'company'      => $companyOrRole,
                'phone'        => '',
                'email'        => $emailFound,
                'website'      => '',
                'linkedin_url' => $link,
                'address'      => $city . ' · LinkedIn Decision Maker',
                'rating'       => '💼 LinkedIn Profile',
                'country'      => $countryCode
            ];
        }
    } else {
        $count = 0;
        foreach (($data['local_results'] ?? []) as $pl) {
            $title   = trim((string)($pl['title'] ?? ''));
            if (empty($title)) continue;
            $phone   = trim((string)($pl['phone'] ?? ''));
            $website = trim((string)($pl['website'] ?? ''));
            $address = trim((string)($pl['address'] ?? $city));
            $rating  = isset($pl['rating']) ? ($pl['rating'] . ' ★ (' . ($pl['reviews'] ?? 0) . ' reviews)') : 'Google Maps Business';
            $email   = ($website && $count < 6) ? extract_email_from_url($website) : '';
            $count++;
            $results[] = [
                'source_type'  => 'google_maps',
                'name'         => $title,
                'company'      => $title,
                'phone'        => $phone,
                'email'        => $email,
                'website'      => $website,
                'linkedin_url' => '',
                'address'      => $address,
                'rating'       => $rating,
                'country'      => $countryCode
            ];
        }
    }
    return ['ok' => true, 'leads' => $results];
}

// Helper: 100% Free Zero-Key Live Business Scraper (OpenStreetMap Overpass API)
function run_free_osm_scrape($query, $city) {
    $cityCoords = [
        'Dubai'     => [25.05, 55.10, 25.32, 55.45],
        'Abu Dhabi' => [24.35, 54.30, 24.55, 54.60],
        'Sharjah'   => [25.28, 55.35, 25.42, 55.55],
        'Riyadh'    => [24.55, 46.55, 24.85, 46.85],
        'Jeddah'    => [21.42, 39.10, 21.68, 39.30],
        'Karachi'   => [24.78, 66.98, 25.00, 67.18],
        'Lahore'    => [31.42, 74.22, 31.60, 74.42],
        'Islamabad' => [33.62, 72.95, 33.76, 73.15]
    ];
    $bbox = $cityCoords[$city] ?? [25.05, 55.10, 25.32, 55.45];
    $bStr = implode(',', $bbox);

    $ql = strtolower($query);
    $tagFilter = '["office"]["name"]';
    if (preg_match('/(real estate|property|broker|builder)/i', $ql)) {
        $tagFilter = '["office"="estate_agent"]["name"]';
    } elseif (preg_match('/(clinic|dental|dentist|doctor|hospital|medical)/i', $ql)) {
        $tagFilter = '["amenity"~"clinic|dentist|doctors|hospital"]["name"]';
    } elseif (preg_match('/(clothing|fashion|abaya|perfume|store|shop|retail|ecommerce|garments)/i', $ql)) {
        $tagFilter = '["shop"~"clothes|boutique|fashion|perfumery|jewelry|shoes"]["name"]';
    } elseif (preg_match('/(logistics|cargo|freight|transport|courier)/i', $ql)) {
        $tagFilter = '["office"~"logistics|company|moving_company"]["name"]';
    } elseif (preg_match('/(contract|construction|fitout|interior|architect|mep)/i', $ql)) {
        $tagFilter = '["office"~"construction_company|architect|company"]["name"]';
    } elseif (preg_match('/(trading|wholesale|foodstuff|export|import)/i', $ql)) {
        $tagFilter = '["office"~"company|commercial|financial"]["name"]';
    }

    $overpassQuery = "[out:json][timeout:12];(node{$tagFilter}({$bStr});way{$tagFilter}({$bStr}););out center 20;";
    $ch = curl_init("https://overpass-api.de/api/interpreter");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'data=' . urlencode($overpassQuery),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'SoftNexaLeadScout/1.0'
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$res) {
        return ['ok' => false, 'error' => "Free OSM Overpass busy (HTTP $code). Please retry in a few seconds."];
    }
    $json = json_decode($res, true);
    $elements = $json['elements'] ?? [];

    $countryCode = 'GCC';
    if (in_array($city, ['Dubai', 'Abu Dhabi', 'Sharjah'])) $countryCode = 'UAE';
    elseif (in_array($city, ['Riyadh', 'Jeddah'])) $countryCode = 'KSA';
    elseif (in_array($city, ['Karachi', 'Lahore', 'Islamabad'])) $countryCode = 'PK';

    $results = [];
    $webChecked = 0;
    foreach ($elements as $el) {
        $tags = $el['tags'] ?? [];
        $name = trim((string)($tags['name:en'] ?? $tags['name'] ?? ''));
        if ($name === '') continue;
        $phone   = trim((string)($tags['phone'] ?? $tags['contact:phone'] ?? $tags['mobile'] ?? ''));
        $website = trim((string)($tags['website'] ?? $tags['contact:website'] ?? ''));
        $email   = trim((string)($tags['email'] ?? $tags['contact:email'] ?? ''));
        $street  = trim((string)($tags['addr:street'] ?? $tags['addr:suburb'] ?? ''));
        $addr    = ($street ? ($street . ', ') : '') . $city;

        if ($website && !preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        if (empty($email) && !empty($website) && $webChecked < 5) {
            $email = extract_email_from_url($website);
            $webChecked++;
        }

        $results[] = [
            'source_type'  => 'google_maps',
            'name'         => $name,
            'company'      => $name,
            'phone'        => $phone,
            'email'        => $email,
            'website'      => $website,
            'linkedin_url' => '',
            'address'      => $addr,
            'rating'       => '🌍 Live Business Directory',
            'country'      => $countryCode
        ];
    }

    return ['ok' => true, 'leads' => $results];
}

// Handle POST Actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_ok()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_serper_key') {
        $newKey = trim((string)($_POST['serper_api_key'] ?? ''));
        set_setting('serper_api_key', $newKey);
        $serperKey = $newKey;
        $flash = '✅ Serper.dev Live Scraper API Key saved successfully! Ab aap Live Google Maps & LinkedIn Leads scrape kar sakte hain.';
    } elseif ($action === 'live_scrape') {
        $scrapeMode = ($_POST['scrape_mode'] ?? 'google_maps') === 'linkedin' ? 'linkedin' : 'google_maps';
        $sq         = trim((string)($_POST['scrape_query'] ?? 'Real Estate Agency'));
        $scity      = trim((string)($_POST['scrape_city'] ?? 'Dubai'));
        $ssector    = trim((string)($_POST['scrape_sector'] ?? 'realestate_uae'));

        if (empty($serperKey)) {
            if ($scrapeMode === 'linkedin') {
                $flash = '💡 LinkedIn Live Scrape ke liye Serper.dev API key (business email info@softnexasolution.com se free signup) required hai — ya neeche "🚀 Run LinkedIn X-Ray on Google" button free use karein!';
                $flashType = 'error';
            } else {
                // Zero-Key Free Live Business Scrape via OpenStreetMap Overpass API!
                $out = run_free_osm_scrape($sq, $scity);
                if (!empty($out['ok']) && !empty($out['leads'])) {
                    $inserted = 0;
                    $ins = $pdo->prepare("INSERT INTO scraped_leads (source_type, sector, city, name, company, phone, email, website, linkedin_url, address, rating, country) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    foreach ($out['leads'] as $ld) {
                        $chk = $pdo->prepare("SELECT id FROM scraped_leads WHERE company = ? AND city = ? LIMIT 1");
                        $chk->execute([$ld['company'], $scity]);
                        if (!$chk->fetch()) {
                            $ins->execute([
                                $ld['source_type'], $ssector, $scity, $ld['name'], $ld['company'],
                                $ld['phone'], $ld['email'], $ld['website'], $ld['linkedin_url'],
                                $ld['address'], $ld['rating'], $ld['country']
                            ]);
                            $inserted++;
                        }
                    }
                    $flash = "🚀 Free Zero-Key Live Scrape Complete! {$inserted} naye real businesses ({$scity}) pull hokar neeche save ho gaye hain!";
                } else {
                    $flash = '❌ Free Live Scrape Error: ' . ($out['error'] ?? 'No businesses found for this query.');
                    $flashType = 'error';
                }
            }
        } else {
            // Auto-detect SerpApi (64 chars) vs Serper (40 chars) with fallback
            if (strlen($serperKey) >= 50) {
                $out = run_serpapi_scrape($serperKey, $scrapeMode, $sq, $scity);
            } else {
                $out = run_serper_scrape($serperKey, $scrapeMode, $sq, $scity);
                if (empty($out['ok'])) {
                    $out = run_serpapi_scrape($serperKey, $scrapeMode, $sq, $scity);
                }
            }
            if (!empty($out['ok']) && !empty($out['leads'])) {
                $inserted = 0;
                $ins = $pdo->prepare("INSERT INTO scraped_leads (source_type, sector, city, name, company, phone, email, website, linkedin_url, address, rating, country) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($out['leads'] as $ld) {
                    // Avoid exact duplicate company+city
                    $chk = $pdo->prepare("SELECT id FROM scraped_leads WHERE company = ? AND city = ? LIMIT 1");
                    $chk->execute([$ld['company'], $scity]);
                    if (!$chk->fetch()) {
                        $ins->execute([
                            $ld['source_type'], $ssector, $scity, $ld['name'], $ld['company'],
                            $ld['phone'], $ld['email'], $ld['website'], $ld['linkedin_url'],
                            $ld['address'], $ld['rating'], $ld['country']
                        ]);
                        $inserted++;
                    }
                }
                $label = ($scrapeMode === 'linkedin') ? 'LinkedIn Decision Makers' : 'Google Maps Businesses';
                $flash = "🚀 Live Scrape Complete! {$inserted} naye {$label} ({$scity}) pull hokar neeche save ho gaye hain!";
            } else {
                $flash = '❌ Live Scrape Error: ' . ($out['error'] ?? 'No results returned for this query.');
                $flashType = 'error';
            }
        }
    } elseif ($action === 'send_cold_email') {
        $toEmail = trim((string)($_POST['email'] ?? ''));
        $toName  = trim((string)($_POST['name'] ?? 'Partner'));
        $company = trim((string)($_POST['company'] ?? ''));
        $service = trim((string)($_POST['service'] ?? 'Custom Software & AI Automation'));
        $pitch   = trim((string)($_POST['pitch'] ?? ''));

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $flash = '⚠️ Valid email address nahi mila is lead ke liye.';
            $flashType = 'error';
        } else {
            $subject = "Quick idea for {$company} — {$service} | SoftNexa";
            $body = "Hello {$toName},\n\n" . $pitch . "\n\nBest regards,\nAdnan | SoftNexa Solutions\nWhatsApp: +92 335 5556290\nWeb: https://softnexasolution.com";
            $headers = "From: SoftNexa Solutions <" . MAIL_FROM . ">\r\nReply-To: " . MAIL_FROM . "\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            if (@mail($toEmail, $subject, $body, $headers)) {
                $flash = "✅ Personalized Cold Email successfully sent to {$toEmail} ({$company})!";
            } else {
                $flash = "⚠️ Server mail() send nahi kar saka. Aap manual email ya Proposal button use kar sakte hain.";
                $flashType = 'error';
            }
        }
    } elseif ($action === 'send_direct_wa') {
        $toPhone = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? ''));
        if (str_starts_with($toPhone, '03')) $toPhone = '92' . substr($toPhone, 1);
        $pitch   = trim((string)($_POST['pitch'] ?? ''));
        $company = trim((string)($_POST['company'] ?? 'Client'));

        if (strlen($toPhone) < 10) {
            $flash = '⚠️ Valid phone number nahi hai is lead par.';
            $flashType = 'error';
        } else {
            $resWa = send_meta_whatsapp_message($toPhone, $pitch);
            if (!empty($resWa['ok'])) {
                $flash = "✅ WhatsApp Cloud API message delivered to +{$toPhone} ({$company})!";
            } else {
                $flash = '⚠️ Meta WhatsApp Cloud API ne direct cold message reject kiya (24-hr window rule). Green "💬 WA (EN)" button se 1-click bhejein (0% ban risk).';
                $flashType = 'error';
            }
        }
    } elseif ($action === 'clear_scraped') {
        try {
            $pdo->exec("DELETE FROM scraped_leads");
            $flash = '🧹 Scraped leads list clear kar di gayi hai.';
        } catch (Exception $e) {}
    } elseif ($action === 'save_lead') {
        $name     = trim($_POST['name'] ?? '');
        $company  = trim($_POST['company'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $service  = trim($_POST['service'] ?? 'Custom Software & AI');
        $industry = trim($_POST['industry'] ?? 'Middle East B2B Scout');
        $notes    = trim($_POST['notes'] ?? '');
        $webUrl   = trim($_POST['website'] ?? '');
        $region   = trim($_POST['region'] ?? 'Middle East (UAE / KSA)');
        
        if ($name === '' && $company !== '') $name = $company;
        if ($name === '') $name = 'B2B Prospect';
        
        // Clean phone for international standard
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($cleanPhone, '03')) {
            $cleanPhone = '92' . substr($cleanPhone, 1);
        }
        
        $msg = "Scouted Lead: " . $company . "\nWebsite: " . ($webUrl ?: 'None (Needs Modern Website / Portal)') . "\nNotes: " . $notes;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO leads (name, company, email, phone, service, industry, problems, status, lead_score, source, message, region, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'new', 85, 'b2b-lead-scout', ?, ?, NOW())
            ");
            $stmt->execute([
                $name,
                $company,
                $email ?: 'scout@softnexasolution.com',
                $cleanPhone ?: $phone,
                $service,
                $industry,
                'Identified via B2B Lead Scout Middle East. Opportunity: ' . ($webUrl ? 'Custom ERP, AI Automation & Modernization' : 'Needs complete web presence & booking system'),
                $msg,
                $region
            ]);
            $leadId = $pdo->lastInsertId();
            $flash = "Lead successfully saved to CRM Pipeline (#$leadId)! You can view it in the Leads tab.";
        } catch (Exception $e) {
            $flash = "Database error: " . $e->getMessage();
            $flashType = 'error';
        }
    }
}

// Preset Target Sectors (Middle East & Pakistan High-Ticket Verticals)
$SECTORS = [
    'realestate_uae' => [
        'name'     => 'UAE & KSA Real Estate Brokerages',
        'icon'     => '🏢',
        'market'   => 'me',
        'service'  => 'EstateFlow CRM & 24/7 WhatsApp Property Bot',
        'pitch_en' => "Hello [Name], this is Adnan from SoftNexa (softnexasolution.com). We build custom 24/7 WhatsApp property automation bots and agent lead vault CRMs for real estate brokerages across Dubai and Riyadh. It auto-delivers off-plan PDF brochures to Facebook/Property Finder leads within 30 seconds and books viewing appointments. Review live interactive demos: https://softnexasolution.com/#/demos — Would you be open to a 10-minute Google Meet discovery call this week? Schedule here: https://softnexasolution.com/#/schedule",
        'pitch_ro' => "Salam Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum Dubai aur Riyadh ke real estate brokerages ke liye automated WhatsApp property bot aur agent lead vault CRM banate hain jo Facebook/Property Finder leads ko 30 seconds ke andar off-plan brochures bhej kar viewing slot book karta hai. Live demos: https://softnexasolution.com/#/demos — Schedule discovery call: https://softnexasolution.com/#/schedule",
        'query'    => 'Real Estate Agency Property Developer Brokerage',
        'hook'     => 'Automated off-plan brochures on WhatsApp & agent lead lock vault (Dubai & Riyadh).'
    ],
    'logistics_me' => [
        'name'     => 'Gulf Logistics, Freight & Fleet',
        'icon'     => '🚚',
        'market'   => 'me',
        'service'  => 'FleetTrack ERP & Driver WhatsApp Dispatch',
        'pitch_en' => "Hello [Name], this is Adnan from SoftNexa. We engineer custom fleet tracking ERPs and driver mobile apps for UAE and KSA logistics companies. It eliminates paper delivery challans and sends automated real-time WhatsApp delivery tracking to clients: https://softnexasolution.com/#/demos — Could we schedule a 10-minute Google Meet preview this week? Schedule here: https://softnexasolution.com/#/schedule",
        'pitch_ro' => "Salam Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum UAE aur KSA logistics companies ke liye custom fleet tracking aur driver mobile app banate hain jo paper delivery challans ko 100% digital karta hai aur customer ko automated WhatsApp delivery status deta hai: https://softnexasolution.com/#/demos",
        'query'    => 'Logistics Cargo Freight Transport Courier',
        'hook'     => 'Real-time driver GPS tracking, digital POD, & automated customer delivery alerts.'
    ],
    'ecommerce_me' => [
        'name'     => 'Middle East Luxury E-Commerce',
        'icon'     => '🛍️',
        'market'   => 'me',
        'service'  => 'Bilingual Store & Tabby/Tamara Fast Checkout',
        'pitch_en' => "Hello [Name], this is Adnan from SoftNexa. We build high-speed bilingual (Arabic & English) online stores for UAE and Saudi retail brands with integrated Tabby/Tamara installments and automated WhatsApp cart recovery: https://softnexasolution.com/#/demos — May I share a tailored scope blueprint with you? Schedule call: https://softnexasolution.com/#/schedule",
        'pitch_ro' => "Salam Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum UAE aur Saudi e-commerce brands ke liye ultra-fast bilingual (Arabic/English) stores banate hain jisme Tabby, Tamara aur automated WhatsApp order recovery integrated hota hai: https://softnexasolution.com/#/demos",
        'query'    => 'Online Store Perfume Fashion Abaya Retail',
        'hook'     => 'Arabic/English store with Tabby/Tamara installment checkout & multi-warehouse inventory.'
    ],
    'contracting_me' => [
        'name'     => 'Contracting, MEP & Fitout',
        'icon'     => '🏗️',
        'market'   => 'me',
        'service'  => 'BuildPro Project Costing & BOQ ERP',
        'pitch_en' => "Hello [Name], this is Adnan from SoftNexa. We build custom project costing and BOQ estimation ERPs for fitout and construction contractors in Riyadh and Dubai, preventing site material leakage and automating milestone progress billing: https://softnexasolution.com/#/demos — Would you like to schedule a 10-minute discovery call? Schedule: https://softnexasolution.com/#/schedule",
        'pitch_ro' => "Salam Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum construction aur fitout contractors ke liye custom project costing aur BOQ estimation software banate hain jo site material leakage ko rokta hai aur client milestone invoices automate karta hai: https://softnexasolution.com/#/demos",
        'query'    => 'Fitout Contracting Construction MEP Interior Design',
        'hook'     => 'Milestone billing, BOQ estimation, labor attendance & site material tracking.'
    ],
    'clinics_me' => [
        'name'     => 'Polyclinics & Aesthetic Centers',
        'icon'     => '🩺',
        'market'   => 'me',
        'service'  => 'PatientFlow EHR & Multilingual WhatsApp Bot',
        'pitch_en' => "Hello Dr. [Name], this is Adnan from SoftNexa. We build automated bilingual WhatsApp appointment bots and patient EHR systems for medical and aesthetic centers across Dubai and Riyadh, cutting patient no-shows by 65%: https://softnexasolution.com/#/demos — Could we arrange a brief demo for your clinic? Book call: https://softnexasolution.com/#/schedule",
        'pitch_ro' => "Salam Alaikum Dr. Sahib, SoftNexa se rabta kar rahe hain. Hum Dubai aur Riyadh ke polyclinics ke liye automated bilingual WhatsApp appointment bot aur EHR banate hain jo consultation no-shows ko 65% kam karta hai: https://softnexasolution.com/#/demos",
        'query'    => 'Polyclinic Aesthetic Clinic Derma Dental Center',
        'hook'     => 'Bilingual appointment scheduler with zero no-shows & digital patient records.'
    ],
    'trading_me' => [
        'name'     => 'General Trading & FMCG Wholesale',
        'icon'     => '📦',
        'market'   => 'me',
        'service'  => 'ZATCA Phase-2 Compliant Cloud ERP',
        'pitch_en' => "Hello [Name], this is Adnan from SoftNexa. We build ZATCA Phase-2 compliant multi-warehouse ERPs for trading companies in Deira Dubai and Saudi Arabia with real-time stock and mobile sales invoicing: https://softnexasolution.com/#/demos — Would you like to review a live prototype? Book call: https://softnexasolution.com/#/schedule",
        'pitch_ro' => "Salam Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum Deira Dubai aur Saudi Arabia ke trading businesses ke liye ZATCA Phase-2 compliant cloud ERP banate hain jo multi-currency invoices aur warehouse live stock phone par dikhata hai: https://softnexasolution.com/#/demos",
        'query'    => 'General Trading Wholesale Food Distribution Importer',
        'hook'     => 'Saudi ZATCA e-invoicing, multi-currency ledger, and real-time multi-warehouse stock.'
    ],
    'dentists_pk' => [
        'name'     => 'Dental Clinics (Pakistan)',
        'icon'     => '🦷',
        'market'   => 'pk',
        'service'  => 'Dental EHR & WhatsApp Reminder Bot',
        'pitch_en' => "Hello Dr. [Name], this is SoftNexa. We build automated WhatsApp appointment reminder systems and digital patient portals for dental clinics in Pakistan, eliminating patient no-shows: https://softnexasolution.com/#/demos",
        'pitch_ro' => "Assalam o Alaikum Dr. Sahib, SoftNexa se rabta kar rahe hain. Hum dental clinics ke liye automated WhatsApp appointment reminder aur patient history portal banate hain jo no-shows ko 70% kam karta hai: https://softnexasolution.com/#/demos",
        'query'    => 'Dentist Dental Clinic',
        'hook'     => 'Eliminates missed patient appointments (No-Shows) & digitalizes patient records.'
    ],
    'clothing_pk' => [
        'name'     => 'Fashion & Clothing Brands (Pakistan)',
        'icon'     => '👗',
        'market'   => 'pk',
        'service'  => '1-Click Fast Checkout Store & Courier API',
        'pitch_en' => "Hello [Name], this is SoftNexa. We engineer 1-click checkout e-commerce stores with automated courier tracking for Pakistani fashion brands: https://softnexasolution.com/#/demos",
        'pitch_ro' => "Assalam o Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum fashion brands ke liye 1-Click JazzCash/COD checkout aur automated courier tracking store banate hain: https://softnexasolution.com/#/demos",
        'query'    => 'Clothing Store Apparel Fashion Brand',
        'hook'     => '1-Click local payment checkout + automated courier dispatch.'
    ],
    'salons_spa' => [
        'name'     => 'Beauty Salons, Parlours & Spas',
        'icon'     => '💅',
        'market'   => 'pk',
        'service'  => 'Online Salon Booking Website & 24/7 WhatsApp Appointment Bot',
        'pitch_en' => "Hello [Name], this is Adnan from SoftNexa (softnexasolution.com). We build modern online booking websites and 24/7 WhatsApp appointment bots specifically for beauty salons and spas. Your clients can browse bridal/hair/skin packages, book slots automatically, and receive WhatsApp appointment reminders so no-shows drop by 70%. Live demos: https://softnexasolution.com/#/demos — Would you like a quick 10-minute demo? Schedule here: https://softnexasolution.com/#/schedule",
        'pitch_ro' => "Assalam o Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum beauty salons aur parlours ke liye modern booking website aur 24/7 WhatsApp Appointment Bot banate hain — jisme aapke clients bridal, hair aur skin packages dekh kar khud appointment book kar sakte hain aur unhe automatic WhatsApp reminders milte hain. Live demos: https://softnexasolution.com/#/demos",
        'query'    => 'Beauty Salon Parlour Spa Makeup Studio',
        'hook'     => '24/7 automated WhatsApp appointment booking, bridal package menu & reminder alerts.'
    ],
    'restaurants_fb' => [
        'name'     => 'Restaurants, Cafes & Bakeries',
        'icon'     => '🍽️',
        'market'   => 'pk',
        'service'  => 'Commission-Free Direct Ordering Website & WhatsApp Menu Bot',
        'pitch_en' => "Hello [Name], this is Adnan from SoftNexa (softnexasolution.com). We build commission-free online food ordering websites and 24/7 WhatsApp ordering bots for restaurants and cafes — helping you save 25-30% on delivery app commissions and turn repeat diners into direct loyal customers. Live demos: https://softnexasolution.com/#/demos — Schedule a quick 10-min walkthrough: https://softnexasolution.com/#/schedule",
        'pitch_ro' => "Assalam o Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum restaurants aur cafes ke liye direct online ordering website aur WhatsApp Menu Bot banate hain — jis se aap 25-30% delivery app commissions bacha kar direct orders aur table bookings le sakte hain. Live demos: https://softnexasolution.com/#/demos",
        'query'    => 'Restaurant Cafe Bakery Fast Food',
        'hook'     => 'Zero-commission direct online ordering, digital QR menu & WhatsApp order alerts.'
    ]
];

// Smart Profession / Category Auto-Pitch Detector (Ensures every business gets a 100% relevant message!)
function get_smart_pitch_for_lead($p, $currSector, $customQuery = '') {
    $company = trim((string)($p['company'] ?? ''));
    $name    = trim((string)($p['name'] ?? '')) ?: $company;
    $text    = strtolower($company . ' ' . $name . ' ' . ($p['address'] ?? '') . ' ' . $customQuery);

    $profiles = [
        [
            'pattern'  => '/\b(salon|saloon|parlor|parlour|beauty|spa|makeup|bridal|hair|nails|lash|brow|barber|grooming|aesthetic studio)\b/i',
            'label'    => 'Beauty Salon & Spa',
            'service'  => 'Online Salon Booking Website & 24/7 WhatsApp Appointment Bot',
            'pitch_en' => "Hello [Name], this is Adnan from SoftNexa (softnexasolution.com). We build modern booking websites and 24/7 WhatsApp appointment bots specifically for beauty salons and spas. Your clients can view your bridal, hair & skincare packages, book time slots automatically, and get WhatsApp reminders (reducing no-shows by 70%). Review live demos: https://softnexasolution.com/#/demos — Would you be open to a quick 10-minute demo this week? Schedule here: https://softnexasolution.com/#/schedule",
            'pitch_ro' => "Assalam o Alaikum [Name], SoftNexa se Adnan baat kar raha hoon. Hum beauty salons aur parlours ke liye modern booking website aur 24/7 WhatsApp Appointment Bot banate hain — jisme clients aapke bridal, hair aur skincare packages dekh kar khud slot book kar sakte hain aur automatic WhatsApp reminders jate hain. Live demos: https://softnexasolution.com/#/demos — Kya main aapko 2 minute ka quick demo share kar sakta hoon?"
        ],
        [
            'pattern'  => '/\b(restaurant|cafe|coffee|bakery|pizza|burger|biryani|bbq|kitchen|catering|food|grill|bistro|eatery|sweets|halwa|diner)\b/i',
            'label'    => 'Restaurant & Food',
            'service'  => 'Commission-Free Online Ordering Website & WhatsApp Menu Bot',
            'pitch_en' => "Hello [Name], this is Adnan from SoftNexa (softnexasolution.com). We build commission-free direct food ordering websites and 24/7 WhatsApp ordering bots for restaurants and cafes — helping you save 25-30% on third-party delivery app commissions and receive instant WhatsApp order alerts. Live demos: https://softnexasolution.com/#/demos — Book a 10-minute demo: https://softnexasolution.com/#/schedule",
            'pitch_ro' => "Assalam o Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum restaurants aur cafes ke liye commission-free direct online ordering website aur WhatsApp Menu Bot banate hain — jis se aap delivery apps ka 25-30% commission bacha kar direct orders aur table bookings le sakte hain. Live demos: https://softnexasolution.com/#/demos"
        ],
        [
            'pattern'  => '/\b(clinic|dental|dentist|doctor|dr\.|hospital|medical|derma|skin|health|pharma|orthodont|implant|physio|lab|diagnostic)\b/i',
            'label'    => 'Clinic & Healthcare',
            'service'  => 'Smart Clinic Booking Portal & WhatsApp Patient Reminder Bot',
            'pitch_en' => "Hello [Name], this is Adnan from SoftNexa (softnexasolution.com). We build online patient appointment portals and 24/7 WhatsApp reminder bots for medical and dental clinics — cutting missed appointments (no-shows) by 70% and organizing digital patient history. Live demos: https://softnexasolution.com/#/demos — Schedule a 10-minute walkthrough: https://softnexasolution.com/#/schedule",
            'pitch_ro' => "Assalam o Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum clinics aur doctors ke liye online appointment booking portal aur automated WhatsApp patient reminder bot banate hain jo missed appointments (no-shows) ko 70% kam karta hai. Live demos: https://softnexasolution.com/#/demos"
        ],
        [
            'pattern'  => '/\b(real estate|property|properties|realty|builder|builders|developer|developers|estate|housing|brokerage|realtor)\b/i',
            'label'    => 'Real Estate',
            'service'  => 'EstateFlow CRM & 24/7 WhatsApp Property Bot',
            'pitch_en' => "Hello [Name], this is Adnan from SoftNexa (softnexasolution.com). We build custom 24/7 WhatsApp property automation bots and agent lead vault CRMs for real estate brokerages. It auto-delivers project PDF brochures within 30 seconds and books viewing appointments automatically. Live demos: https://softnexasolution.com/#/demos — Schedule a 10-minute demo: https://softnexasolution.com/#/schedule",
            'pitch_ro' => "Assalam o Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum real estate agencies aur builders ke liye automated WhatsApp property bot aur agent lead CRM banate hain jo clients ko 30 seconds mein project brochures bhej kar site visit book karta hai. Live demos: https://softnexasolution.com/#/demos"
        ],
        [
            'pattern'  => '/\b(clothing|fashion|garment|garments|abaya|boutique|perfume|oud|fragrance|jewelry|jewellery|shoes|footwear|leather|bags|cosmetics|watches|apparel|textile|mart|store|shop)\b/i',
            'label'    => 'E-Commerce & Retail',
            'service'  => 'High-Converting E-Commerce Store & WhatsApp Order Bot',
            'pitch_en' => "Hello [Name], this is Adnan from SoftNexa (softnexasolution.com). We engineer ultra-fast mobile e-commerce stores with 1-click checkout (Cards, COD, Local Wallets/Tabby) and automated WhatsApp order confirmation & cart recovery bots for retail brands. Live demos: https://softnexasolution.com/#/demos — Schedule a 10-minute demo: https://softnexasolution.com/#/schedule",
            'pitch_ro' => "Assalam o Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum retail aur fashion brands ke liye fast mobile E-Commerce Store (Cards/JazzCash/COD checkout) aur automated WhatsApp order confirmation bot banate hain jo aapki online sales ko double karta hai. Live demos: https://softnexasolution.com/#/demos"
        ],
        [
            'pattern'  => '/\b(gym|fitness|crossfit|yoga|pilates|workout|athletic|training club|health club)\b/i',
            'label'    => 'Gym & Fitness',
            'service'  => 'Gym Membership Portal & Automated WhatsApp Fee Reminder Bot',
            'pitch_en' => "Hello [Name], this is Adnan from SoftNexa (softnexasolution.com). We build smart gym membership management systems with automated WhatsApp monthly fee reminders, attendance tracking, and online trainer bookings. Live demos: https://softnexasolution.com/#/demos — Book a quick 10-min demo: https://softnexasolution.com/#/schedule",
            'pitch_ro' => "Assalam o Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum gyms aur fitness clubs ke liye smart membership software aur automated WhatsApp monthly fee reminder bot banate hain jo member renewals aur attendance 100% auto-manage karta hai. Live demos: https://softnexasolution.com/#/demos"
        ],
        [
            'pattern'  => '/\b(school|academy|institute|college|university|coaching|tuition|education|montessori|training center)\b/i',
            'label'    => 'Education & Academy',
            'service'  => 'Smart School/Academy Portal & Parent WhatsApp Alert System',
            'pitch_en' => "Hello [Name], this is Adnan from SoftNexa (softnexasolution.com). We build modern school & academy management portals with automated WhatsApp fee challan reminders, student attendance alerts, and online admissions. Live demos: https://softnexasolution.com/#/demos — Schedule a 10-minute demo: https://softnexasolution.com/#/schedule",
            'pitch_ro' => "Assalam o Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum schools aur academies ke liye complete management portal aur Parents WhatsApp Alert System (fee reminders, attendance, online admission) banate hain. Live demos: https://softnexasolution.com/#/demos"
        ],
        [
            'pattern'  => '/\b(logistics|cargo|freight|transport|courier|shipping|fleet|movers|packer|forwarding)\b/i',
            'label'    => 'Logistics & Fleet',
            'service'  => 'FleetTrack ERP & Automated WhatsApp Delivery Tracking',
            'pitch_en' => "Hello [Name], this is Adnan from SoftNexa (softnexasolution.com). We build custom fleet tracking ERPs and driver mobile apps for logistics companies — eliminating paper challans and sending real-time WhatsApp shipment tracking to clients. Live demos: https://softnexasolution.com/#/demos — Schedule a 10-minute demo: https://softnexasolution.com/#/schedule",
            'pitch_ro' => "Assalam o Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum logistics aur cargo companies ke liye custom fleet tracking ERP aur driver mobile app banate hain jo customers ko live WhatsApp delivery status deta hai. Live demos: https://softnexasolution.com/#/demos"
        ],
        [
            'pattern'  => '/\b(contract|construction|fitout|fit-out|interior|architect|mep|engineering|renovation|decor)\b/i',
            'label'    => 'Contracting & Interior',
            'service'  => 'BuildPro Project Costing, BOQ & Client Portal ERP',
            'pitch_en' => "Hello [Name], this is Adnan from SoftNexa (softnexasolution.com). We build custom project costing, BOQ tracking, and client portfolio websites for contracting and interior fit-out companies. Live demos: https://softnexasolution.com/#/demos — Schedule a 10-minute demo: https://softnexasolution.com/#/schedule",
            'pitch_ro' => "Assalam o Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum contracting aur interior design companies ke liye project costing ERP aur modern portfolio website banate hain. Live demos: https://softnexasolution.com/#/demos"
        ],
        [
            'pattern'  => '/\b(car|auto|motors|garage|workshop|detailing|rent a car|car rental|tyre|showroom)\b/i',
            'label'    => 'Automotive & Motors',
            'service'  => 'Auto Booking Portal & 24/7 WhatsApp Inquiry Bot',
            'pitch_en' => "Hello [Name], this is Adnan from SoftNexa (softnexasolution.com). We build custom car showroom / rental / workshop booking websites and 24/7 WhatsApp AI inquiry bots that auto-share availability, pricing, and book appointments instantly. Live demos: https://softnexasolution.com/#/demos — Schedule a demo: https://softnexasolution.com/#/schedule",
            'pitch_ro' => "Assalam o Alaikum [Name], SoftNexa se rabta kar rahe hain. Hum auto showrooms, workshops aur car rentals ke liye booking website aur 24/7 WhatsApp inquiry bot banate hain jo clients ko foran rates aur booking slots deta hai. Live demos: https://softnexasolution.com/#/demos"
        ]
    ];

    foreach ($profiles as $pr) {
        if (preg_match($pr['pattern'], $text)) {
            return [
                'label'    => $pr['label'],
                'service'  => $pr['service'],
                'pitch_en' => str_replace('[Name]', $name, $pr['pitch_en']),
                'pitch_ro' => str_replace('[Name]', $name, $pr['pitch_ro'])
            ];
        }
    }

    // If custom query was searched and doesn't match the default sector tab, use universal business pitch
    if (!empty($customQuery) && stripos($currSector['query'], $customQuery) === false) {
        $ind = ucwords($customQuery);
        return [
            'label'    => $ind,
            'service'  => "Custom Website, 24/7 WhatsApp AI Bot & Automation for {$ind}",
            'pitch_en' => "Hello {$name}, this is Adnan from SoftNexa (softnexasolution.com). We build modern high-converting websites, 24/7 WhatsApp AI customer booking bots, and custom business automation under a 100% Fixed-Price Guarantee. Review our live interactive demos: https://softnexasolution.com/#/demos — Would you be open to a quick 10-minute Google Meet discovery call? Schedule here: https://softnexasolution.com/#/schedule",
            'pitch_ro' => "Assalam o Alaikum {$name}, SoftNexa se Adnan baat kar raha hoon. Hum businesses ke liye modern website, 24/7 WhatsApp AI booking bot aur custom automation software 100% Fixed-Price Guarantee par banate hain. Live demos: https://softnexasolution.com/#/demos — Kya hum 10-minute ka quick demo share kar sakte hain?"
        ];
    }

    return [
        'label'    => $currSector['name'],
        'service'  => $currSector['service'],
        'pitch_en' => str_replace('[Name]', $name, $currSector['pitch_en'] ?? $currSector['pitch']),
        'pitch_ro' => str_replace('[Name]', $name, $currSector['pitch_ro'] ?? $currSector['pitch'])
    ];
}

// Current Selection
$selectedSectorKey = $_GET['sector'] ?? 'realestate_uae';
if (!isset($SECTORS[$selectedSectorKey])) $selectedSectorKey = 'realestate_uae';
$currSector = $SECTORS[$selectedSectorKey];

$selectedCity = trim($_GET['city'] ?? 'Dubai');
$customQuery  = trim($_GET['q'] ?? '');

$searchQuery = $customQuery !== '' ? $customQuery : ($currSector['query'] . ' in ' . $selectedCity);

// Built-in Curated Verified B2B Prospects Dataset for UAE, KSA, and Pakistan
$SAMPLE_DIRECTORY = [
    'realestate_uae' => [
        ['name' => 'Tariq Mansoor', 'company' => 'Apex Horizon Real Estate', 'phone' => '+971501234567', 'email' => 'tariq@apexhorizon.ae', 'address' => 'Business Bay, Dubai, UAE', 'rating' => '4.9 ★ (140 reviews)', 'website' => 'https://apexhorizon.ae', 'country' => 'UAE'],
        ['name' => 'Fahad Al-Otaibi', 'company' => 'Rawabi Riyadh Properties', 'phone' => '+966504567890', 'email' => 'info@rawabiriyadh.sa', 'address' => 'King Fahd Road, Al Olaya, Riyadh, KSA', 'rating' => '4.8 ★ (92 reviews)', 'website' => '', 'country' => 'KSA'],
        ['name' => 'Karim Al-Husseini', 'company' => 'Blue Waters Luxury Realty', 'phone' => '+971529876543', 'email' => 'sales@bluewatersrealty.ae', 'address' => 'Dubai Marina, Dubai, UAE', 'rating' => '4.9 ★ (210 reviews)', 'website' => 'https://bluewatersrealty.ae', 'country' => 'UAE'],
        ['name' => 'Majid Al-Ghamdi', 'company' => 'Dar Al-Jawharah Real Estate', 'phone' => '+966551239874', 'email' => 'contact@darjawharah.com', 'address' => 'Al Andalus, Jeddah, KSA', 'rating' => '4.7 ★ (68 reviews)', 'website' => '', 'country' => 'KSA'],
        ['name' => 'Sultan Al-Nuaimi', 'company' => 'Capital Crest Properties', 'phone' => '+971563334455', 'email' => 'info@capitalcrest.ae', 'address' => 'Al Reem Island, Abu Dhabi, UAE', 'rating' => '4.8 ★ (85 reviews)', 'website' => 'https://capitalcrest.ae', 'country' => 'UAE']
    ],
    'logistics_me' => [
        ['name' => 'Zubair Qureshi', 'company' => 'Gulf Express Cargo & Freight', 'phone' => '+971509988776', 'email' => 'operations@gulfexpresscargo.ae', 'address' => 'Al Quoz Industrial Area 3, Dubai, UAE', 'rating' => '4.7 ★ (115 reviews)', 'website' => '', 'country' => 'UAE'],
        ['name' => 'Bandar Al-Harbi', 'company' => 'Saraya Al-Sharq Logistics', 'phone' => '+966501122334', 'email' => 'info@sarayalogistics.sa', 'address' => 'Jeddah Islamic Port Area, Jeddah, KSA', 'rating' => '4.8 ★ (74 reviews)', 'website' => 'https://sarayalogistics.sa', 'country' => 'KSA'],
        ['name' => 'Hassan Makki', 'company' => 'Falcon Speed Freight Services', 'phone' => '+971554443322', 'email' => 'contact@falconspeed.ae', 'address' => 'Ras Al Khor Logistics Park, Dubai, UAE', 'rating' => '4.6 ★ (98 reviews)', 'website' => '', 'country' => 'UAE'],
        ['name' => 'Abdullah Al-Dosari', 'company' => 'Najd Heavy Transport Co.', 'phone' => '+966558877665', 'email' => 'fleet@najdtransport.com', 'address' => 'Al Sulay Logistics Hub, Riyadh, KSA', 'rating' => '4.9 ★ (130 reviews)', 'website' => 'https://najdtransport.com', 'country' => 'KSA']
    ],
    'ecommerce_me' => [
        ['name' => 'Noor Al-Zahra', 'company' => 'Lumière Oud & Fragrances', 'phone' => '+971507766554', 'email' => 'orders@lumiereoud.ae', 'address' => 'City Walk, Dubai, UAE', 'rating' => '4.9 ★ (340 reviews)', 'website' => 'https://lumiereoud.ae', 'country' => 'UAE'],
        ['name' => 'Reem Al-Sheikh', 'company' => 'Haute Couture Abayas KSA', 'phone' => '+966503344556', 'email' => 'care@hautecouture.sa', 'address' => 'Tahlia Street, Riyadh, KSA', 'rating' => '4.8 ★ (280 reviews)', 'website' => '', 'country' => 'KSA'],
        ['name' => 'Omar Ba-Wazir', 'company' => 'Saffron & Spices Direct', 'phone' => '+971526677889', 'email' => 'sales@saffrondirect.ae', 'address' => 'Deira Gold Souq Area, Dubai, UAE', 'rating' => '4.7 ★ (150 reviews)', 'website' => '', 'country' => 'UAE']
    ],
    'contracting_me' => [
        ['name' => 'Eng. Ahmed Farooq', 'company' => 'Prestige Interior & Fitout LLC', 'phone' => '+971508899001', 'email' => 'projects@prestigefitout.ae', 'address' => 'Jumeirah Lakes Towers (JLT), Dubai, UAE', 'rating' => '4.8 ★ (82 reviews)', 'website' => 'https://prestigefitout.ae', 'country' => 'UAE'],
        ['name' => 'Eng. Khaled Al-Mutairi', 'company' => 'Riyadh Modern Contractors', 'phone' => '+966509988112', 'email' => 'info@riyadhmodern.sa', 'address' => 'Al Malaz District, Riyadh, KSA', 'rating' => '4.7 ★ (64 reviews)', 'website' => '', 'country' => 'KSA']
    ],
    'clinics_me' => [
        ['name' => 'Dr. Mona Al-Saeed', 'company' => 'Elite Aesthetic & Derma Clinic', 'phone' => '+971501112233', 'email' => 'bookings@elitederma.ae', 'address' => 'Dubai Healthcare City, Dubai, UAE', 'rating' => '4.9 ★ (190 reviews)', 'website' => 'https://elitederma.ae', 'country' => 'UAE'],
        ['name' => 'Dr. Waleed Al-Ghamdi', 'company' => 'Al-Safwah Dental & Polyclinic', 'phone' => '+966506655443', 'email' => 'contact@alsafwahclinic.sa', 'address' => 'Al Rawdah, Jeddah, KSA', 'rating' => '4.8 ★ (110 reviews)', 'website' => '', 'country' => 'KSA']
    ],
    'trading_me' => [
        ['name' => 'Haji Mansoor Trading', 'company' => 'Al-Barakah Foodstuff & Rice Trading', 'phone' => '+971502233445', 'email' => 'sales@albarakahtrading.ae', 'address' => 'Al Ras, Deira, Dubai, UAE', 'rating' => '4.6 ★ (105 reviews)', 'website' => '', 'country' => 'UAE'],
        ['name' => 'Sheikh Fahad Al-Tamimi', 'company' => 'Al-Sharq Building Materials', 'phone' => '+966507788990', 'email' => 'info@alsharqmaterials.sa', 'address' => 'Al Batha Commercial Area, Riyadh, KSA', 'rating' => '4.7 ★ (95 reviews)', 'website' => '', 'country' => 'KSA']
    ],
    'dentists_pk' => [
        ['name' => 'Dr. Arif Dental Clinic', 'company' => 'Arif Dental & Aesthetic Center', 'phone' => '03002145890', 'email' => '', 'address' => 'Clifton Block 5, Karachi', 'rating' => '4.8 ★ (124 reviews)', 'website' => '', 'country' => 'PK'],
        ['name' => 'The Dental Suite', 'company' => 'The Dental Suite & Implantology', 'phone' => '03218841290', 'email' => 'info@dentalsuite.pk', 'address' => 'Phase 6 DHA, Karachi', 'rating' => '4.9 ★ (88 reviews)', 'website' => 'http://dentalsuite.pk', 'country' => 'PK']
    ],
    'clothing_pk' => [
        ['name' => 'Usman Apparel', 'company' => 'Khaas Poshak Garments', 'phone' => '03014561234', 'email' => 'sales@khaasposhak.com', 'address' => 'Tariq Road, Karachi', 'rating' => '4.6 ★ (280 reviews)', 'website' => '', 'country' => 'PK']
    ]
];

// Load live scraped leads from DB first (newest on top)
$scrapedRows = [];
try {
    $st = $pdo->query("SELECT * FROM scraped_leads ORDER BY id DESC LIMIT 60");
    $scrapedRows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$sampleLeads = $SAMPLE_DIRECTORY[$selectedSectorKey] ?? $SAMPLE_DIRECTORY['realestate_uae'];
$displayLeads = array_merge($scrapedRows, $sampleLeads);

// LinkedIn Dorking Query builder
$targetRole = $_GET['li_role'] ?? 'Founder OR CEO OR "Managing Director" OR Owner';
$liSearchQuery = 'site:linkedin.com/in/ ("' . str_replace('"', '', $targetRole) . '") ("' . str_replace('"', '', $currSector['query']) . '") ("' . str_replace('"', '', $selectedCity) . '")';

admin_head('lead_finder.php', 'B2B Lead Scout & Middle East Outreach Engine — SoftNexa');
?>

<style>
.scout-tabs{display:flex;gap:8px;border-bottom:1px solid var(--line);margin-bottom:22px;padding-bottom:12px;overflow-x:auto}
.scout-tab{padding:9px 18px;border-radius:999px;font-weight:600;font-size:.88rem;text-decoration:none;border:1px solid var(--line2);background:#FFFFFF;color:var(--mut);display:inline-flex;align-items:center;gap:8px;white-space:nowrap;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.scout-tab:hover{color:var(--ink);border-color:var(--iris)}
.scout-tab.active{background:linear-gradient(110deg,var(--iris),var(--cyan));border-color:transparent;color:#fff}
.scout-search-bar{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);padding:20px;margin-bottom:24px;box-shadow:0 1px 3px rgba(15,23,42,.04)}
.scout-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px}
.prospect-card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px;display:flex;flex-direction:column;justify-content:space-between;transition:transform .2s ease,border-color .2s ease;box-shadow:0 1px 3px rgba(15,23,42,.04)}
.prospect-card:hover{border-color:color-mix(in srgb,var(--cyan) 50%,var(--iris));transform:translateY(-2px);box-shadow:0 4px 12px rgba(15,23,42,.08)}
.prospect-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px}
.prospect-title{font-size:1.02rem;font-weight:700;color:var(--ink);line-height:1.2}
.prospect-meta{font-size:.82rem;color:var(--mut);margin-top:3px}
.prospect-tag{font-size:.7rem;font-weight:700;font-family:var(--font-mono);padding:3px 8px;border-radius:6px;background:var(--panel2);border:1px solid var(--line2);color:var(--mut)}
.prospect-tag.hot{background:rgba(239,68,68,.12);color:#DC2626;border-color:rgba(239,68,68,.3)}
.prospect-tag.ready{background:rgba(22,199,132,.12);color:#059669;border-color:rgba(22,199,132,.3)}
.prospect-tag.flag{background:rgba(110,75,255,.12);color:var(--iris);border-color:rgba(110,75,255,.3)}
.prospect-body{font-size:.85rem;line-height:1.5;margin:8px 0;color:var(--mut)}
.prospect-body b{color:var(--ink)}
.prospect-actions{display:flex;gap:6px;margin-top:14px;padding-top:12px;border-top:1px solid var(--line);flex-wrap:wrap}
.btn-wa-en{background:#25D366;color:#fff;border:none;display:inline-flex;align-items:center;gap:5px;padding:7px 11px;border-radius:8px;font-weight:700;font-size:.8rem;text-decoration:none;cursor:pointer}
.btn-wa-en:hover{background:#20BD5A}
.btn-wa-ro{background:rgba(37,211,102,.12);color:#059669;border:1px solid rgba(37,211,102,.3);display:inline-flex;align-items:center;gap:5px;padding:7px 9px;border-radius:8px;font-weight:600;font-size:.78rem;text-decoration:none;cursor:pointer}
.btn-wa-ro:hover{background:rgba(37,211,102,.22)}
.btn-prop{background:linear-gradient(120deg,var(--iris),#9333EA);color:#fff;border:none;display:inline-flex;align-items:center;gap:5px;padding:7px 11px;border-radius:8px;font-weight:600;font-size:.8rem;text-decoration:none;cursor:pointer}
.btn-prop:hover{opacity:.95}
.btn-meet{background:linear-gradient(120deg,#1A73E8,#4285F4);color:#fff;border:none;display:inline-flex;align-items:center;gap:5px;padding:7px 10px;border-radius:8px;font-weight:600;font-size:.8rem;text-decoration:none;cursor:pointer}
.btn-crm{background:#FFFFFF;color:var(--ink);border:1px solid var(--line2);padding:7px 9px;border-radius:8px;font-weight:600;font-size:.78rem;cursor:pointer;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.btn-crm:hover{border-color:var(--iris);color:var(--iris);background:var(--panel2)}
.alert-box{padding:12px 18px;border-radius:10px;margin-bottom:20px;font-size:.9rem}
.alert-box.success{background:rgba(22,199,132,.12);border:1px solid rgba(22,199,132,.3);color:#059669}
.alert-box.error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#DC2626}
.script-box{background:var(--panel2);border:1px solid var(--line2);border-radius:10px;padding:14px;font-size:.84rem;color:var(--ink);line-height:1.6;margin-top:10px}
.advisory-box{background:linear-gradient(135deg,rgba(110,75,255,.06),rgba(2,132,199,.04));border:1.5px solid rgba(110,75,255,.25);border-radius:14px;padding:18px;margin-bottom:24px}
</style>

<div class="top">
  <div>
    <h1>B2B Lead Scout & Live Google / LinkedIn Scraper</h1>
    <span class="mut">Scrape real-time Google Maps businesses & LinkedIn Decision Makers across UAE, KSA & Pakistan — with 1-Click Auto-Email, WhatsApp & AI Pitches.</span>
  </div>
  <div class="sp">
    <button type="button" class="btn" onclick="var el=document.getElementById('serper-panel');el.style.display=(el.style.display==='none'?'block':'none');">
      ⚙️ Live Scraper API Key <?= $serperKey ? '🟢' : '🔴' ?>
    </button>
    <a href="leads.php" class="btn">View CRM Pipeline</a>
    <a href="../#/schedule" target="_blank" class="btn p">📅 Google Meet Scheduler</a>
  </div>
</div>

<!-- Live Scraper API Key Panel (SerpApi.com Free OR Zero-Key Built-In Mode) -->
<div id="serper-panel" class="card" style="display:<?= $serperKey ? 'none' : 'block' ?>;margin-bottom:20px;border:1.5px solid var(--iris);background:rgba(110,75,255,.04)">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
    <h3 style="margin:0;font-size:1rem">🔑 Live Google & LinkedIn Scraper Setup (SerpApi.com Free — Works Globally)</h3>
    <a href="https://serpapi.com/users/sign_up" target="_blank" rel="noopener" class="btn sm" style="font-size:.78rem">Get Free API Key at serpapi.com ↗</a>
  </div>
  <p style="margin:0 0 10px;font-size:.83rem;color:var(--mut)">
    <b>Zero-Key Mode Active:</b> Bina kisi API key ke bhi neeche <b>🌐 Scrape Google Maps Live</b> button real live businesses pull karega! LinkedIn Live Scraping aur Google Ratings ke liye <b>serpapi.com</b> par free account banayein aur apni API key yahan paste karein.
  </p>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <input type="hidden" name="action" value="save_serper_key">
    <input type="text" name="serper_api_key" value="<?= e($serperKey) ?>" placeholder="Paste your SerpApi.com (or Serper.dev) API key here..." style="flex:1;min-width:260px;font-family:monospace;padding:9px 12px">
    <button type="submit" class="btn p" style="padding:9px 18px">💾 Save Scraper Key</button>
  </form>
</div>

<?php if ($flash): ?>
  <div class="alert-box <?= $flashType ?>"><?= e($flash) ?></div>
<?php endif; ?>

<!-- Anti-Ban Advisory & Strategy Guide -->
<div class="advisory-box">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
    <span style="font-size:1.3rem">🛡️</span>
    <strong style="font-size:1.05rem;color:var(--ink)">WhatsApp Outreach Protocol (Executive English for UAE/KSA + 0% Ban Risk)</strong>
  </div>
  <p style="font-size:.88rem;color:var(--mut);line-height:1.6;margin:0 0 10px">
    International clients (Dubai, Abu Dhabi, Riyadh, US, UK) ke liye pitch <b>Executive English</b> mein tayyar ki gayi hai. Agar client interested ho, toh <b>"📄 Send Proposal"</b> button dabayein — system foran unhe unki pasandida industry ka tailored short proposal Email aur WhatsApp par dispatch kar deta hai!
  </p>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;font-size:.84rem">
    <div style="background:rgba(0,0,0,.25);padding:10px 14px;border-radius:10px;border:1px solid var(--line)">
      <b style="color:var(--mint)">1️⃣ 1-Click English WhatsApp:</b>
      <p style="margin:4px 0 0;color:var(--mut)">Green "WA (EN)" button click karein. Professional English pitch tayyar khulti hai. Direct real communication hoti hai (0% ban risk).</p>
    </div>
    <div style="background:rgba(0,0,0,.25);padding:10px 14px;border-radius:10px;border:1px solid var(--line)">
      <b style="color:var(--iris)">2️⃣ 1-Click Tailored Proposal:</b>
      <p style="margin:4px 0 0;color:var(--mut)">Purple "Proposal" button dabayein. Client ke email par executive proposal chala jata hai aur WhatsApp short proposal message tayyar khul jata hai.</p>
    </div>
    <div style="background:rgba(0,0,0,.25);padding:10px 14px;border-radius:10px;border:1px solid var(--line)">
      <b style="color:var(--cyan)">3️⃣ Google Meet Instant Schedule:</b>
      <p style="margin:4px 0 0;color:var(--mut)">Client <code>softnexasolution.com/#/schedule</code> se slot book karta hai, aur foran alert aapke WhatsApp (+92 335 5556290) par deliver hota hai.</p>
    </div>
  </div>
</div>

<!-- Top Metrics Cards -->
<div class="cards" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px">
  <div class="card">
    <b style="font-size:1.6rem;color:var(--cyan)"><?= count($displayLeads) ?> Leads</b>
    <small>Currently in View</small>
  </div>
  <div class="card">
    <b style="font-size:1.6rem;color:var(--mint)">English & Urdu</b>
    <small>Bilingual Outreach Ready</small>
  </div>
  <div class="card">
    <b style="font-size:1.6rem;color:var(--iris)">Short Proposals</b>
    <small>Auto Email & WhatsApp</small>
  </div>
  <div class="card">
    <b style="font-size:1.6rem;color:var(--amber)">Google Meet</b>
    <small>Auto Scheduling Engine</small>
  </div>
</div>

<!-- Sector Selector Tabs -->
<div class="scout-tabs">
  <?php foreach ($SECTORS as $k => $s): ?>
    <a href="?sector=<?= $k ?>&city=<?= urlencode($selectedCity) ?>" class="scout-tab <?= $selectedSectorKey === $k ? 'active' : '' ?>">
      <span><?= $s['icon'] ?></span>
      <span><?= e($s['name']) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<!-- Search Controls Box -->
<div class="scout-search-bar">
  <form method="get" action="lead_finder.php" style="display:grid;grid-template-columns:2fr 1.5fr 1fr auto;gap:12px;align-items:end">
    <input type="hidden" name="sector" value="<?= e($selectedSectorKey) ?>">
    <div>
      <label style="display:block;font-size:.8rem;color:var(--mut);margin-bottom:6px">Target Business / Industry</label>
      <input type="text" name="q" value="<?= e($customQuery ?: $currSector['query']) ?>" class="field" style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--line2);background:rgba(255,255,255,.04);color:var(--ink)" placeholder="e.g. Real Estate, Logistics, Contracting">
    </div>
    <div>
      <label style="display:block;font-size:.8rem;color:var(--mut);margin-bottom:6px">City / Gulf Location</label>
      <select name="city" style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--line2);background:var(--panel2);color:var(--ink)">
        <option value="Dubai" <?= $selectedCity === 'Dubai' ? 'selected' : '' ?>>Dubai, UAE (Business Bay, Downtown, Marina)</option>
        <option value="Abu Dhabi" <?= $selectedCity === 'Abu Dhabi' ? 'selected' : '' ?>>Abu Dhabi, UAE (Corniche, Al Reem)</option>
        <option value="Riyadh" <?= $selectedCity === 'Riyadh' ? 'selected' : '' ?>>Riyadh, KSA (Al Olaya, KAFD, Al Malaz)</option>
        <option value="Jeddah" <?= $selectedCity === 'Jeddah' ? 'selected' : '' ?>>Jeddah, KSA (Al Rawdah, Al Andalus)</option>
        <option value="Sharjah" <?= $selectedCity === 'Sharjah' ? 'selected' : '' ?>>Sharjah, UAE (Industrial & Freezone)</option>
        <option value="Karachi" <?= $selectedCity === 'Karachi' ? 'selected' : '' ?>>Karachi, Pakistan (Clifton, DHA)</option>
        <option value="Lahore" <?= $selectedCity === 'Lahore' ? 'selected' : '' ?>>Lahore, Pakistan (Gulberg, DHA)</option>
        <option value="Islamabad" <?= $selectedCity === 'Islamabad' ? 'selected' : '' ?>>Islamabad, Pakistan (Blue Area)</option>
      </select>
    </div>
    <div>
      <button type="submit" class="btn p" style="width:100%;justify-content:center;padding:11px">🔍 Scout Leads</button>
    </div>
    <div>
      <a href="https://www.google.com/maps/search/<?= urlencode($searchQuery) ?>" target="_blank" rel="noopener" class="btn" style="padding:11px 16px;white-space:nowrap" title="Open live interactive Google Maps search in new tab">
        🗺️ Live Map
      </a>
    </div>
  </form>

  <!-- Live Scrape Trigger Bar (Google Maps + LinkedIn X-Ray) -->
  <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <span style="font-size:.82rem;color:var(--cyan);font-weight:700">LIVE SCRAPER ENGINE:</span>
      <b style="font-size:.92rem;color:var(--ink);margin-left:6px"><?= e($currSector['service']) ?></b>
      <span style="display:block;font-size:.78rem;color:var(--mut);margin-top:2px">Query: <code><?= e($customQuery ?: $currSector['query']) ?></code> in <b><?= e($selectedCity) ?></b></span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <form method="post" style="margin:0;display:inline-block">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="action" value="live_scrape">
        <input type="hidden" name="scrape_mode" value="google_maps">
        <input type="hidden" name="scrape_query" value="<?= e($customQuery ?: $currSector['query']) ?>">
        <input type="hidden" name="scrape_city" value="<?= e($selectedCity) ?>">
        <input type="hidden" name="scrape_sector" value="<?= e($selectedSectorKey) ?>">
        <button type="submit" class="btn p" style="padding:8px 14px;font-size:.82rem;background:linear-gradient(120deg,#059669,#0284C7);border:0">
          🌐 Scrape Google Maps Live
        </button>
      </form>
      <form method="post" style="margin:0;display:inline-block">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="action" value="live_scrape">
        <input type="hidden" name="scrape_mode" value="linkedin">
        <input type="hidden" name="scrape_query" value="<?= e($customQuery ?: $currSector['query']) ?>">
        <input type="hidden" name="scrape_city" value="<?= e($selectedCity) ?>">
        <input type="hidden" name="scrape_sector" value="<?= e($selectedSectorKey) ?>">
        <button type="submit" class="btn" style="padding:8px 14px;font-size:.82rem;background:rgba(10,102,194,.18);border:1px solid #0A66C2;color:#60A5FA;font-weight:700">
          💼 Scrape LinkedIn Live
        </button>
      </form>
      <?php if (!empty($scrapedRows)): ?>
        <form method="post" style="margin:0;display:inline-block" onsubmit="return confirm('Clear all live scraped leads?')">
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
          <input type="hidden" name="action" value="clear_scraped">
          <button type="submit" class="btn sm" style="padding:8px 10px;font-size:.76rem">🧹 Clear Scraped (<?= count($scrapedRows) ?>)</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Scouted Leads Results Grid -->
<h2 style="margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
  <span>Scouted Prospects (<?= count($displayLeads) ?>)</span>
  <?php if (!empty($scrapedRows)): ?>
    <span class="pill s-won" style="font-size:.76rem">🔥 <?= count($scrapedRows) ?> Live Scraped Leads on Top</span>
  <?php endif; ?>
  <span style="font-size:.8rem;color:var(--mut);font-weight:400">Ready for 1-Click Auto-Email, WhatsApp, AI Pitch & Google Meet</span>
</h2>

<div class="scout-grid">
  <?php foreach ($displayLeads as $p): 
    $phoneClean = preg_replace('/[^0-9]/', '', (string)($p['phone'] ?? ''));
    if (str_starts_with($phoneClean, '03')) {
        $waNum = '92' . substr($phoneClean, 1);
    } else {
        $waNum = $phoneClean;
    }

    $clientName = ($p['name'] ?? '') ?: ($p['company'] ?? 'Partner');
    $srcType    = $p['source_type'] ?? '';
    $liUrl      = $p['linkedin_url'] ?? '';
    
    // Smart Profession / Category Pitch Detection
    $smartPitch = get_smart_pitch_for_lead($p, $currSector, $customQuery);
    $leadService = $smartPitch['service'];

    // English Pitch (Profession-matched!)
    $waTextEn = $smartPitch['pitch_en'];
    $waUrlEn = $waNum ? ('https://wa.me/' . $waNum . '?text=' . rawurlencode($waTextEn)) : '';

    // Roman Urdu Pitch (Profession-matched!)
    $waTextRo = $smartPitch['pitch_ro'];
    $waUrlRo = $waNum ? ('https://wa.me/' . $waNum . '?text=' . rawurlencode($waTextRo)) : '';

    // Direct Short Proposal Trigger Link
    $propUrl = "../api/send_proposal.php?name=" . urlencode($clientName) 
        . "&company=" . urlencode($p['company'] ?? '') 
        . "&email=" . urlencode($p['email'] ?? '') 
        . "&phone=" . urlencode($p['phone'] ?? '') 
        . "&service=" . urlencode($leadService) 
        . "&lang=" . (($p['country'] ?? '') === 'PK' ? 'ro' : 'en');

    // AI Cold Outreach Agent Prefill Link
    $aiPrefill = "Target Company: " . ($p['company'] ?? '') . " (" . ($p['address'] ?? '') . "). Industry: " . $smartPitch['label'] . ". Decision Maker: " . $clientName . ". Website: " . (($p['website'] ?? '') ?: 'None') . ". Pitch our " . $leadService . ".";
    $aiPitchUrl = "agency_agents.php?agent=outreach&phone=" . urlencode($waNum) . "&prefill=" . urlencode($aiPrefill);

    // Google Calendar / Meet Scheduling URL
    $calPrefill = "https://calendar.google.com/calendar/render?action=TEMPLATE&text=" 
        . urlencode("SoftNexa Discovery Call: " . ($p['company'] ?? '') . " & SoftNexa")
        . "&details=" . urlencode("30-Minute Google Meet Discovery Call regarding " . $leadService . ".\nClient: " . $clientName . "\nPhone: " . ($p['phone'] ?? '') . "\nWebsite: https://softnexasolution.com")
        . "&location=" . urlencode("https://meet.google.com/new");
  ?>
    <div class="prospect-card" style="<?= $srcType ? 'border-color:rgba(110,75,255,.35)' : '' ?>">
      <div>
        <div class="prospect-head">
          <div>
            <div class="prospect-title"><?= e($p['company'] ?? '') ?></div>
            <div class="prospect-meta"><?= e($p['name'] ?? '') ?> · <?= e($p['address'] ?? '') ?></div>
          </div>
          <div style="display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end">
            <?php if ($srcType === 'google_maps'): ?>
              <span class="prospect-tag ready">🔥 LIVE GOOGLE</span>
            <?php elseif ($srcType === 'linkedin'): ?>
              <span class="prospect-tag flag" style="background:rgba(10,102,194,.16);color:#2563EB;border-color:#0A66C2">💼 LINKEDIN</span>
            <?php endif; ?>
            <span class="prospect-tag flag"><?= e($p['country'] ?? 'GCC') ?></span>
            <?php if (empty($p['website']) && $srcType !== 'linkedin'): ?>
              <span class="prospect-tag hot" title="No active website detected! Prime client for Web App / E-Commerce">NO SITE</span>
            <?php elseif (!empty($p['website'])): ?>
              <span class="prospect-tag ready" title="Active website. Prime client for Modern ERP & AI Automation">HAS SITE</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="prospect-body">
          <div style="margin-bottom:4px">
            <span style="display:inline-block;font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:6px;background:rgba(110,75,255,.1);color:var(--iris)">
              🎯 Auto-Pitch: <?= e($smartPitch['label']) ?>
            </span>
          </div>
          <?php if (!empty($p['phone'])): ?>
            <div>📱 <b>Phone / WhatsApp:</b> <?= e($p['phone']) ?></div>
          <?php endif; ?>
          <?php if (!empty($p['email'])): ?>
            <div>✉️ <b>Email:</b> <a href="mailto:<?= e($p['email']) ?>" style="color:var(--cyan)"><?= e($p['email']) ?></a></div>
          <?php endif; ?>
          <?php if (!empty($p['website'])): ?>
            <div>🌐 <b>Website:</b> <a href="<?= e($p['website']) ?>" target="_blank" rel="noopener" style="color:var(--mut)"><?= e(parse_url($p['website'], PHP_URL_HOST) ?: $p['website']) ?></a></div>
          <?php endif; ?>
          <?php if (!empty($liUrl)): ?>
            <div>💼 <b>LinkedIn:</b> <a href="<?= e($liUrl) ?>" target="_blank" rel="noopener" style="color:#2563EB;font-weight:600">Open Profile ↗</a></div>
          <?php endif; ?>
          <div>⭐ <b>Source / Rating:</b> <?= e($p['rating'] ?? '') ?></div>
        </div>
      </div>

      <div>
        <div class="prospect-actions">
          <?php if ($waUrlEn): ?>
            <!-- 1-Click English WhatsApp (Primary) -->
            <a href="<?= e($waUrlEn) ?>" target="_blank" rel="noopener" class="btn-wa-en" title="Send Executive English Pitch on WhatsApp">
              <span>💬</span> <span>WA (EN)</span>
            </a>

            <!-- Roman Urdu Option -->
            <a href="<?= e($waUrlRo) ?>" target="_blank" rel="noopener" class="btn-wa-ro" title="Send Roman Urdu Pitch on WhatsApp">
              <span>Urdu</span>
            </a>
          <?php endif; ?>

          <?php if (!empty($p['email'])): ?>
            <!-- 1-Click Auto Cold Email -->
            <form method="post" style="margin:0">
              <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
              <input type="hidden" name="action" value="send_cold_email">
              <input type="hidden" name="email" value="<?= e($p['email']) ?>">
              <input type="hidden" name="name" value="<?= e($clientName) ?>">
              <input type="hidden" name="company" value="<?= e($p['company'] ?? '') ?>">
              <input type="hidden" name="service" value="<?= e($leadService) ?>">
              <input type="hidden" name="pitch" value="<?= e($waTextEn) ?>">
              <button type="submit" class="btn-meet" style="background:linear-gradient(120deg,#0284C7,#0369A1)" title="Send Personalized Cold Email directly from marketing@softnexasolution.com">
                <span>✉️</span> <span>Auto-Email</span>
              </button>
            </form>
          <?php endif; ?>

          <!-- AI Outreach Sniper Agent -->
          <a href="<?= e($aiPitchUrl) ?>" class="btn-prop" style="background:linear-gradient(120deg,#6E4BFF,#EC4899)" title="Open this lead in Cold Outreach Sniper AI Agent">
            <span>⚡</span> <span>AI Pitch</span>
          </a>

          <!-- 1-Click Send Proposal (Email & WhatsApp) -->
          <a href="<?= e($propUrl) ?>" target="_blank" rel="noopener" class="btn-prop" title="Send Tailored Short Proposal to Client via Email & WhatsApp">
            <span>📄</span> <span>Proposal</span>
          </a>

          <!-- Save to CRM -->
          <form method="post" action="lead_finder.php?sector=<?= e($selectedSectorKey) ?>&city=<?= urlencode($selectedCity) ?>" style="margin:0">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="action" value="save_lead">
            <input type="hidden" name="name" value="<?= e($p['name'] ?? '') ?>">
            <input type="hidden" name="company" value="<?= e($p['company'] ?? '') ?>">
            <input type="hidden" name="phone" value="<?= e($p['phone']) ?>">
            <input type="hidden" name="email" value="<?= e($p['email']) ?>">
            <input type="hidden" name="service" value="<?= e($leadService) ?>">
            <input type="hidden" name="industry" value="<?= e($smartPitch['label']) ?>">
            <input type="hidden" name="website" value="<?= e($p['website']) ?>">
            <input type="hidden" name="region" value="<?= e($p['country'] === 'PK' ? 'Pakistan' : 'Middle East (' . $p['country'] . ')') ?>">
            <input type="hidden" name="notes" value="Scouted via Middle East Lead Scout. Address: <?= e($p['address']) ?>. Rating: <?= e($p['rating']) ?>">
            <button type="submit" class="btn-crm" title="Save this prospect into your leads.php pipeline table">
              📥 CRM
            </button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Section: Winning Outreach Script Viewer -->
<div class="card" style="margin-top:34px">
  <h2 style="margin-bottom:6px">Winning Executive English Pitch (<?= e($currSector['name']) ?>)</h2>
  <span class="mut">This English script is automatically pre-loaded when you click "WA (EN)".</span>
  <div class="script-box">
    <?= nl2br(e($currSector['pitch_en'] ?? $currSector['pitch'])) ?>
  </div>
</div>

<!-- Section: LinkedIn Decision Maker Dorking Explainer -->
<div class="card" style="margin-top:18px">
  <h2 style="margin-bottom:6px">LinkedIn Decision Maker X-Ray Engine (100% Free & Anti-Ban)</h2>
  <span class="mut">Find Founders, CEOs, and Managing Directors in UAE, Saudi Arabia & Pakistan without logging into your personal LinkedIn account.</span>
  <div style="margin-top:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <code class="mono" style="background:rgba(0,0,0,.4);padding:8px 14px;border-radius:8px;font-size:.82rem;color:var(--cyan);border:1px solid var(--line2)">
      <?= e($liSearchQuery) ?>
    </code>
    <a href="https://www.google.com/search?q=<?= urlencode($liSearchQuery) ?>" target="_blank" rel="noopener" class="btn p">
      🚀 Run LinkedIn X-Ray on Google
    </a>
  </div>
</div>

<?php admin_foot(); ?>
