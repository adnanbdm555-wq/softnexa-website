<?php
/* ============================================================
   SOFTNEXA GOOGLE MEET & DISCOVERY CALL SCHEDULER API
   ============================================================ */
require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
if (!rate_ok('meet_booking', 6, 3600)) json_out(['ok' => false, 'error' => 'rate_limited'], 429);

$d = json_input();
if (f($d, 'website_trap')) json_out(['ok' => true]); // Honeypot

$name     = trim((string)f($d, 'name', 120));
$company  = trim((string)f($d, 'company', 160));
$email    = trim((string)f($d, 'email', 160));
$phone    = trim((string)f($d, 'phone', 60));
$date     = trim((string)f($d, 'date', 20));
$timeSlot = trim((string)f($d, 'time_slot', 30));
$timezone = trim((string)f($d, 'timezone', 60)) ?: 'Asia/Dubai';
$topic    = trim((string)f($d, 'topic', 200)) ?: 'Custom Software & AI Discovery';
$notes    = trim((string)f($d, 'notes', 1000));

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '' || $date === '' || $timeSlot === '') {
    json_out(['ok' => false, 'error' => 'Please provide your Name, Email, WhatsApp Phone, Date and Preferred Time.'], 422);
}

// Generate unique Google Meet room identifier
$meetSlug = 'sfn-' . substr(md5(uniqid($name, true)), 0, 4) . '-' . substr(md5(uniqid($phone, true)), 4, 3);
$meetLink = 'https://meet.google.com/' . $meetSlug;

// Build Google Calendar 1-Click Add Event Link
$startDateTime = date('Ymd\THis\Z', strtotime("$date $timeSlot"));
$endDateTime   = date('Ymd\THis\Z', strtotime("$date $timeSlot +30 minutes"));

$calDetails = "SoftNexa Discovery Call with Senior Engineering Architects.\n\n"
    . "Client: " . $name . " (" . ($company ?: 'Enterprise') . ")\n"
    . "Topic: " . $topic . "\n"
    . "Google Meet Link: " . $meetLink . "\n\n"
    . "Official WhatsApp Support: +92 315 2989922\n"
    . "SoftNexa Solutions — https://softnexasolution.com";

$calUrl = "https://calendar.google.com/calendar/render?action=TEMPLATE"
    . "&text=" . urlencode("SoftNexa Discovery Call: " . ($company ?: $name) . " & SoftNexa")
    . "&dates=" . $startDateTime . "/" . $endDateTime
    . "&details=" . urlencode($calDetails)
    . "&location=" . urlencode($meetLink);

// 1. Save or Update Lead in CRM
$leadId = 0;
try {
    $msgBody = "📅 [ONLINE GOOGLE MEET SCHEDULED]\n"
        . "Date: {$date}\n"
        . "Time: {$timeSlot} ({$timezone})\n"
        . "Topic: {$topic}\n"
        . "Meet Link: {$meetLink}\n"
        . "Notes: {$notes}";

    $stmt = db()->prepare("
        INSERT INTO leads (name, company, email, phone, service, problems, status, lead_score, source, message, region, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'meeting_scheduled', 95, 'google_meet_scheduler', ?, ?, NOW())
    ");
    $stmt->execute([
        $name,
        $company ?: 'Middle East / Global Lead',
        $email,
        $phone,
        'Google Meet: ' . $topic,
        'Scheduled 30-min discovery call for ' . $date . ' at ' . $timeSlot,
        $msgBody,
        $timezone
    ]);
    $leadId = db()->lastInsertId();
} catch (Exception $e) {
    error_log("Database error saving scheduled meeting: " . $e->getMessage());
}

// 2. Dispatch Instant WhatsApp Alert to Business Owner (+92 315 2989922)
$adminPhone = '923152989922';
$ownerAlert = "📅 *NEW GOOGLE MEET SCHEDULED!*\n"
    . "━━━━━━━━━━━━━━━━━━━━━\n"
    . "👤 *Client:* " . $name . "\n"
    . "🏢 *Company:* " . ($company ?: 'B2B Lead') . "\n"
    . "📱 *WhatsApp:* " . $phone . "\n"
    . "✉️ *Email:* " . $email . "\n"
    . "🗓️ *Date:* " . $date . "\n"
    . "⏰ *Time:* " . $timeSlot . " (" . $timezone . ")\n"
    . "🎯 *Topic:* " . $topic . "\n"
    . "🔗 *Google Meet:* " . $meetLink . "\n"
    . ($notes ? "📝 *Notes:* " . $notes . "\n" : "")
    . "━━━━━━━━━━━━━━━━━━━━━\n"
    . "⚡ _SoftNexa Lead Scout & Outreach Engine_";

$waAlertRes = send_meta_whatsapp_message($adminPhone, $ownerAlert);

// 3. Dispatch WhatsApp Confirmation to the Lead (if phone is clean)
$cleanClientPhone = preg_replace('/[^0-9]/', '', $phone);
$clientAlertRes = null;
if (!empty($cleanClientPhone)) {
    $clientMsg = "Hello " . $name . "! ✨\n\n"
        . "Your Google Meet Discovery Call with SoftNexa is confirmed.\n\n"
        . "🗓️ *Date:* " . $date . "\n"
        . "⏰ *Time:* " . $timeSlot . " (" . $timezone . ")\n"
        . "🔗 *Google Meet Link:* " . $meetLink . "\n"
        . "📅 *Add to Google Calendar:* " . $calUrl . "\n\n"
        . "Our senior solution architect looks forward to discussing your project scope. Feel free to message us here if you need to reschedule!";
    
    $clientAlertRes = send_meta_whatsapp_message($cleanClientPhone, $clientMsg);
}

// 4. Send Executive HTML Email Confirmation
$adminEmail = setting('notify_email', NOTIFY_EMAIL);
$emailSubj = "📅 New Google Meet Scheduled: {$name} (" . ($company ?: 'B2B') . ") on {$date} at {$timeSlot}";
$emailHtml = "<h2>New Online Meeting Booked on Google Meet</h2>"
    . "<p><b>Client:</b> " . htmlspecialchars($name) . "</p>"
    . "<p><b>Company:</b> " . htmlspecialchars($company) . "</p>"
    . "<p><b>Email:</b> " . htmlspecialchars($email) . "</p>"
    . "<p><b>WhatsApp:</b> <a href='https://wa.me/" . htmlspecialchars($cleanClientPhone) . "'>" . htmlspecialchars($phone) . "</a></p>"
    . "<p><b>Date & Time:</b> " . htmlspecialchars($date . ' at ' . $timeSlot . ' (' . $timezone . ')') . "</p>"
    . "<p><b>Topic:</b> " . htmlspecialchars($topic) . "</p>"
    . "<p><b>Google Meet Link:</b> <a href='" . htmlspecialchars($meetLink) . "' style='font-size:1.1rem;font-weight:700;color:#6E4BFF'>" . htmlspecialchars($meetLink) . "</a></p>"
    . "<p><b>Add to Calendar:</b> <a href='" . htmlspecialchars($calUrl) . "'>Click here to add to Google Calendar</a></p>"
    . "<hr><p>View lead in CRM: <a href='https://softnexasolution.com/admin/leads.php?id=" . $leadId . "'>Lead #" . $leadId . "</a></p>";

send_html_email($adminEmail, $emailSubj, $emailHtml, $email);

// Return full response with Meet details
json_out([
    'ok'          => true,
    'lead_id'     => $leadId,
    'meet_link'   => $meetLink,
    'calendar_url'=> $calUrl,
    'date'        => $date,
    'time_slot'   => $timeSlot,
    'timezone'    => $timezone,
    'wa_sent'     => ($waAlertRes['ok'] ?? false),
    'message'     => 'Your Google Meet discovery call has been confirmed!'
]);
