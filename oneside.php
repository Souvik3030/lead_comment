<?php

// ============================================================
// CONFIG — update this to your Bitrix24 webhook URL
// ============================================================
define('BITRIX_WEBHOOK_URL', 'https://13.234.18.177.sslip.io/rest/1/c549rd6ic2gw5e3s/');
define('LOG_FILE', __DIR__ . '/timeline_to_comment.log');

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
    'method'      => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
]);

// ============================================================
// STEP 1: Validate POST request
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    logEvent('STEP 1 — METHOD CHECK', 'FAILED — not a POST request', [
        'method' => $_SERVER['REQUEST_METHOD'],
    ]);
    respond('error', 'Only POST requests are allowed');
}

logEvent('STEP 1 — METHOD CHECK', 'Passed — POST request received');

// ============================================================
// STEP 2: Read POST data
// ============================================================
$data = $_POST;

if (empty($data)) {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? [];
    logEvent('STEP 2 — POST DATA', '$_POST was empty, tried raw input', [
        'raw'     => $raw,
        'decoded' => $data,
    ]);
} else {
    logEvent('STEP 2 — POST DATA', 'Received $_POST data', $data);
}

if (empty($data)) {
    logEvent('STEP 2 — POST DATA', 'FAILED — no data received at all');
    respond('error', 'No data received');
}

// ============================================================
// STEP 3: Validate event type
// ============================================================
$event = $data['event'] ?? null;

logEvent('STEP 3 — EVENT CHECK', 'Checking event type', [
    'received_event'  => $event,
    'expected_event'  => 'ONCRMTIMELINECOMMENTADD',
]);

if ($event !== 'ONCRMTIMELINECOMMENTADD') {
    logEvent('STEP 3 — EVENT CHECK', 'IGNORED — not a timeline comment event', [
        'event' => $event,
    ]);
    respond('ignored', 'Unrecognized event: ' . ($event ?? 'none'));
}

logEvent('STEP 3 — EVENT CHECK', 'Passed — ONCRMTIMELINECOMMENTADD received');

// ============================================================
// STEP 4: Extract Comment ID
// ============================================================
$commentId = $data['data']['FIELDS']['ID'] ?? null;

logEvent('STEP 4 — COMMENT ID', 'Extracting Comment ID from payload', [
    'data[data]'   => $data['data'] ?? null,
    'extracted_id' => $commentId,
]);

if (!$commentId) {
    logEvent('STEP 4 — COMMENT ID', 'FAILED — Comment ID missing');
    respond('error', 'Comment ID missing');
}

logEvent('STEP 4 — COMMENT ID', 'Passed — Comment ID found', ['comment_id' => $commentId]);

// ============================================================
// STEP 5: Fetch comment data from Bitrix24
// ============================================================
logEvent('STEP 5 — FETCH COMMENT', 'Fetching comment from Bitrix24', ['comment_id' => $commentId]);

$commentResult = callBitrix('crm.timeline.comment.get', ['id' => $commentId]);

if (empty($commentResult['result'])) {
    logEvent('STEP 5 — FETCH COMMENT', 'FAILED — Comment not found or API error', $commentResult);
    respond('error', 'Comment not found');
}

$commentData = $commentResult['result'];
$commentText = trim($commentData['COMMENT'] ?? '');
$entityType  = strtolower($commentData['ENTITY_TYPE'] ?? '');
$entityId    = (int)($commentData['ENTITY_ID'] ?? 0);

logEvent('STEP 5 — FETCH COMMENT', 'Comment data extracted', [
    'comment_text' => $commentText,
    'entity_type'  => $entityType,
    'entity_id'    => $entityId,
]);

// ============================================================
// STEP 6: Validate — must be a lead comment with text
// ============================================================
logEvent('STEP 6 — VALIDATION', 'Validating entity and comment text', [
    'entity_type'       => $entityType,
    'entity_id'         => $entityId,
    'comment_not_empty' => !empty($commentText),
]);

if ($entityType !== 'lead') {
    logEvent('STEP 6 — VALIDATION', 'IGNORED — entity is not a lead', ['entity_type' => $entityType]);
    respond('ignored', 'Entity is not a lead');
}

if (!$entityId) {
    logEvent('STEP 6 — VALIDATION', 'FAILED — Entity ID is missing or zero');
    respond('error', 'Entity ID missing');
}

if (!$commentText) {
    logEvent('STEP 6 — VALIDATION', 'IGNORED — Comment text is empty');
    respond('ignored', 'Comment text is empty');
}

logEvent('STEP 6 — VALIDATION', 'Passed — valid lead comment');

// ============================================================
// STEP 7: Fetch current Lead to read existing COMMENTS field
// ============================================================
logEvent('STEP 7 — FETCH LEAD', 'Fetching lead data', ['lead_id' => $entityId]);

$leadResult = callBitrix('crm.lead.get', ['id' => $entityId]);

if (empty($leadResult['result'])) {
    logEvent('STEP 7 — FETCH LEAD', 'FAILED — Lead not found', $leadResult);
    respond('error', 'Lead not found');
}

$existingComment = trim($leadResult['result']['COMMENTS'] ?? '');

logEvent('STEP 7 — FETCH LEAD', 'Current COMMENTS field value', [
    'existing_comment' => $existingComment,
    'is_empty'         => $existingComment === '',
]);

// ============================================================
// STEP 8: Skip if COMMENTS already matches the timeline comment
// ============================================================
if ($existingComment === $commentText) {
    logEvent('STEP 8 — DUPLICATE CHECK', 'IGNORED — COMMENTS already matches timeline comment');
    respond('ignored', 'COMMENTS already up to date');
}

logEvent('STEP 8 — DUPLICATE CHECK', 'Passed — comment is new, proceeding to update');

// ============================================================
// STEP 9: Update Lead COMMENTS field
// ============================================================
logEvent('STEP 9 — UPDATE LEAD', 'Updating lead COMMENTS field', [
    'lead_id'     => $entityId,
    'new_comment' => $commentText,
]);

$updateResult = callBitrix('crm.lead.update', [
    'id'               => $entityId,
    'fields[COMMENTS]' => $commentText,
]);

logEvent('STEP 9 — UPDATE LEAD', 'Update API result', $updateResult);

if (!isset($updateResult['error'])) {
    logEvent('STEP 9 — UPDATE LEAD', 'SUCCESS — Lead COMMENTS field updated', [
        'lead_id' => $entityId,
        'comment' => $commentText,
    ]);
    respond('success', 'Lead COMMENTS field updated successfully');
} else {
    logEvent('STEP 9 — UPDATE LEAD', 'FAILED — Could not update COMMENTS field', $updateResult);
    respond('error', 'Failed to update lead COMMENTS field');
}