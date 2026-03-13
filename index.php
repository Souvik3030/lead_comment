<?php

// $outbound = "https://myemirateshome.com/jenkins-automation/lead_comment-preview-20260313-060114-48m37siig5/";

// ============================================================
// CONFIG — update these values
// ============================================================
define('BITRIX_WEBHOOK_URL', 'https://13.234.18.177.sslip.io/rest/1/c549rd6ic2gw5e3s/');
define('LOG_FILE', __DIR__ . '/comments_to_timeline.log');

// ============================================================
// HELPERS
// ============================================================

function logEvent(string $message, array $context = []): void
{
    $line = '[' . date('d.m.Y H:i:s') . '] ' . $message;
    if (!empty($context)) {
        $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    file_put_contents(LOG_FILE, $line . PHP_EOL, FILE_APPEND);
}

function respond(string $status, string $message): void
{
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
}

function callBitrix(string $method, array $params = []): array
{
    $url  = BITRIX_WEBHOOK_URL . $method . '.json';
    $body = http_build_query($params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response = curl_exec($ch);
    // curl_close($ch);

    return json_decode($response, true) ?? [];
}

// ============================================================
// ENTRY POINT
// ============================================================

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    respond('error', 'Only POST requests are allowed');
}

$data = $_POST;

logEvent('Received payload', $data);

// Only handle lead add/update events
$allowedEvents = ['ONCRMLEADADD', 'ONCRMLEADUPDATE'];
if (!isset($data['event']) || !in_array($data['event'], $allowedEvents)) {
    respond('ignored', 'Unrecognized event');
}

// ============================================================
// STEP 1: Get Lead ID
// ============================================================
$leadId = $data['data']['FIELDS']['ID'] ?? null;
if (!$leadId) {
    respond('error', 'Lead ID missing');
}

// ============================================================
// STEP 2: Fetch Lead data
// ============================================================
$leadResult = callBitrix('crm.lead.get', ['id' => $leadId]);
if (empty($leadResult['result'])) {
    logEvent('Lead fetch failed', $leadResult);
    respond('error', 'Lead not found');
}

$newComment = trim($leadResult['result']['COMMENTS'] ?? '');

// ============================================================
// STEP 3: Skip if COMMENTS field is empty
// ============================================================
if (!$newComment) {
    respond('ignored', 'No comment to sync');
}

// ============================================================
// STEP 4: Fetch latest timeline comment to prevent duplicates
// ============================================================
$timelineResult = callBitrix('crm.timeline.comment.list', [
    'filter[ENTITY_TYPE]' => 'lead',
    'filter[ENTITY_ID]'   => $leadId,
    'order[ID]'           => 'DESC',
    'limit'               => 1,
]);

$lastTimelineComment = trim($timelineResult['result'][0]['COMMENT'] ?? '');

// ============================================================
// STEP 5: Skip if comment hasn't changed
// ============================================================
if ($lastTimelineComment === $newComment) {
    respond('ignored', 'Comment unchanged, skipping timeline post');
}

// ============================================================
// STEP 6: Post to timeline
// ============================================================
$addResult = callBitrix('crm.timeline.comment.add', [
    'fields[ENTITY_TYPE]' => 'lead',
    'fields[ENTITY_ID]'   => $leadId,
    'fields[COMMENT]'     => $newComment,
]);

if (!empty($addResult['result'])) {
    logEvent('Timeline comment created', ['lead_id' => $leadId, 'comment' => $newComment]);
    respond('success', 'Timeline comment created successfully');
} else {
    logEvent('Failed to create timeline comment', $addResult);
    respond('error', 'Failed to create timeline comment');
}
