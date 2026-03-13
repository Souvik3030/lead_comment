


<?php

// ============================================================
// CONFIG — update these values
// ============================================================
define('BITRIX_WEBHOOK_URL', 'https://13.234.18.177.sslip.io/rest/1/eizf5lz3qpm7dfs1/');
define('LOG_FILE', __DIR__ . '/comments_to_timeline.log');

// ============================================================
// HELPERS
// ============================================================

function logEvent(string $step, string $message, $context = null): void
{
    $line  = PHP_EOL;
    $line .= '=======================================' . PHP_EOL;
    $line .= '[' . date('d.m.Y H:i:s') . '] STEP: ' . $step . PHP_EOL;
    $line .= 'MESSAGE : ' . $message . PHP_EOL;

    if ($context !== null) {
        $line .= 'DATA    : ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    }

    $line .= '=======================================' . PHP_EOL;

    file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

function respond(string $status, string $message): void
{
    logEvent('RESPONSE', $status . ' — ' . $message);
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
}

function callBitrix(string $method, array $params = []): array
{
    $url  = BITRIX_WEBHOOK_URL . $method . '.json';
    $body = http_build_query($params);

    logEvent('BITRIX API CALL', 'Calling: ' . $method, [
        'url'    => $url,
        'params' => $params,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close($ch);

    if ($curlError) {
        logEvent('BITRIX API ERROR', 'cURL error on ' . $method, [
            'curl_error' => $curlError,
            'http_code'  => $httpCode,
        ]);
        return [];
    }

    $decoded = json_decode($response, true) ?? [];

    logEvent('BITRIX API RESPONSE', 'Response from: ' . $method, [
        'http_code' => $httpCode,
        'response'  => $decoded,
    ]);

    return $decoded;
}

// ============================================================
// ENTRY POINT
// ============================================================

header('Content-Type: application/json');

logEvent('BOOT', 'Script started', [
    'method'     => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'request_uri'=> $_SERVER['REQUEST_URI'] ?? 'unknown',
    'remote_addr'=> $_SERVER['REMOTE_ADDR'] ?? 'unknown',
]);

// ============================================================
// CHECK REQUEST METHOD
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    logEvent('METHOD CHECK', 'Failed — not a POST request', ['method' => $_SERVER['REQUEST_METHOD']]);
    respond('error', 'Only POST requests are allowed');
}

logEvent('METHOD CHECK', 'Passed — POST request received');

// ============================================================
// READ RAW POST DATA
// ============================================================
$data = $_POST;

// Also try raw input in case Bitrix sends JSON body
if (empty($data)) {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? [];
    logEvent('POST DATA', '$_POST was empty, tried raw input', ['raw' => $raw, 'decoded' => $data]);
} else {
    logEvent('POST DATA', 'Received $_POST data', $data);
}

if (empty($data)) {
    logEvent('POST DATA', 'FAILED — no data received at all');
    respond('error', 'No data received');
}

// ============================================================
// CHECK EVENT TYPE
// ============================================================
$event         = $data['event'] ?? null;
$allowedEvents = ['ONCRMLEADADD', 'ONCRMLEADUPDATE'];

logEvent('EVENT CHECK', 'Checking event type', [
    'received_event' => $event,
    'allowed_events' => $allowedEvents,
]);

if (!$event || !in_array($event, $allowedEvents)) {
    logEvent('EVENT CHECK', 'IGNORED — event not in allowed list', ['event' => $event]);
    respond('ignored', 'Unrecognized event: ' . ($event ?? 'none'));
}

logEvent('EVENT CHECK', 'Passed — event is valid', ['event' => $event]);

// ============================================================
// STEP 1: Get Lead ID
// ============================================================
$leadId = $data['data']['FIELDS']['ID'] ?? null;

logEvent('STEP 1 — LEAD ID', 'Extracting Lead ID from payload', [
    'data[data]'   => $data['data'] ?? null,
    'extracted_id' => $leadId,
]);

if (!$leadId) {
    logEvent('STEP 1 — LEAD ID', 'FAILED — Lead ID missing');
    respond('error', 'Lead ID missing');
}

logEvent('STEP 1 — LEAD ID', 'Passed — Lead ID found', ['lead_id' => $leadId]);

// ============================================================
// STEP 2: Fetch Lead data
// ============================================================
logEvent('STEP 2 — FETCH LEAD', 'Fetching lead from Bitrix24', ['lead_id' => $leadId]);

$leadResult = callBitrix('crm.lead.get', ['id' => $leadId]);

if (empty($leadResult['result'])) {
    logEvent('STEP 2 — FETCH LEAD', 'FAILED — Lead not found or API error', $leadResult);
    respond('error', 'Lead not found');
}

logEvent('STEP 2 — FETCH LEAD', 'Passed — Lead fetched successfully', $leadResult['result']);

$newComment = trim($leadResult['result']['COMMENTS'] ?? '');

logEvent('STEP 2 — COMMENTS FIELD', 'Extracted COMMENTS value', [
    'raw_value'     => $leadResult['result']['COMMENTS'] ?? null,
    'trimmed_value' => $newComment,
    'is_empty'      => $newComment === '',
]);

// ============================================================
// STEP 3: Skip if COMMENTS is empty
// ============================================================
if (!$newComment) {
    logEvent('STEP 3 — EMPTY CHECK', 'IGNORED — COMMENTS field is empty, nothing to sync');
    respond('ignored', 'No comment to sync');
}

logEvent('STEP 3 — EMPTY CHECK', 'Passed — COMMENTS field has content', ['comment' => $newComment]);

// ============================================================
// STEP 4: Fetch latest timeline comment to prevent duplicates
// ============================================================
logEvent('STEP 4 — TIMELINE FETCH', 'Fetching latest timeline comment', ['lead_id' => $leadId]);

$timelineResult = callBitrix('crm.timeline.comment.list', [
    'filter[ENTITY_TYPE]' => 'lead',
    'filter[ENTITY_ID]'   => $leadId,
    'order[ID]'           => 'DESC',
    'limit'               => 1,
]);

$lastTimelineComment = trim($timelineResult['result'][0]['COMMENT'] ?? '');

logEvent('STEP 4 — TIMELINE FETCH', 'Latest timeline comment retrieved', [
    'last_timeline_comment' => $lastTimelineComment,
    'new_comment'           => $newComment,
    'are_same'              => $lastTimelineComment === $newComment,
]);

// ============================================================
// STEP 5: Skip if comment hasn't changed
// ============================================================
if ($lastTimelineComment === $newComment) {
    logEvent('STEP 5 — DUPLICATE CHECK', 'IGNORED — Comment unchanged, already exists in timeline');
    respond('ignored', 'Comment unchanged, skipping timeline post');
}

logEvent('STEP 5 — DUPLICATE CHECK', 'Passed — comment is new, proceeding to post');

// ============================================================
// STEP 6: Post new comment to timeline
// ============================================================
logEvent('STEP 6 — POST TIMELINE', 'Posting comment to timeline', [
    'lead_id' => $leadId,
    'comment' => $newComment,
]);

$addResult = callBitrix('crm.timeline.comment.add', [
    'fields[ENTITY_TYPE]' => 'lead',
    'fields[ENTITY_ID]'   => $leadId,
    'fields[COMMENT]'     => $newComment,
]);

logEvent('STEP 6 — POST TIMELINE', 'API call result', $addResult);

if (!empty($addResult['result'])) {
    logEvent('STEP 6 — POST TIMELINE', 'SUCCESS — Timeline comment created', [
        'lead_id'    => $leadId,
        'comment'    => $newComment,
        'new_entry_id' => $addResult['result'],
    ]);
    respond('success', 'Timeline comment created successfully');
} else {
    logEvent('STEP 6 — POST TIMELINE', 'FAILED — Could not create timeline comment', $addResult);
    respond('error', 'Failed to create timeline comment');
}