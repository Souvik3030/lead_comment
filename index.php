<?php

// ============================================================
// CONFIG — update this to your Bitrix24 webhook URL
// ============================================================
define('BITRIX_WEBHOOK_URL', 'https://13.234.18.177.sslip.io/rest/1/c549rd6ic2gw5e3s/');
define('LOG_FILE',  __DIR__ . '/comments_sync.log');
define('LOCK_DIR',  __DIR__ . '/locks/');
define('LOCK_TTL',  5); // seconds before lock expires

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

function isLocked(string $leadId, string $direction): bool
{
    if (!is_dir(LOCK_DIR)) {
        mkdir(LOCK_DIR, 0755, true);
    }
    $lockFile = LOCK_DIR . 'lead_' . $leadId . '_' . $direction . '.lock';
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < LOCK_TTL) {
        logEvent('LOCK CHECK', 'LOCKED — skipping to prevent infinite loop', [
            'lead_id'   => $leadId,
            'direction' => $direction,
            'lock_file' => $lockFile,
            'age_secs'  => time() - filemtime($lockFile),
        ]);
        return true;
    }
    return false;
}

function setLock(string $leadId, string $direction): void
{
    if (!is_dir(LOCK_DIR)) {
        mkdir(LOCK_DIR, 0755, true);
    }
    $lockFile = LOCK_DIR . 'lead_' . $leadId . '_' . $direction . '.lock';
    file_put_contents($lockFile, time());
    logEvent('LOCK SET', 'Lock created — will expire in ' . LOCK_TTL . ' seconds', [
        'lead_id'   => $leadId,
        'direction' => $direction,
        'lock_file' => $lockFile,
    ]);
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
// STEP 1: Validate POST
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
// STEP 3: Validate event
// ============================================================
$event         = $data['event'] ?? null;
$allowedEvents = ['ONCRMLEADADD', 'ONCRMLEADUPDATE', 'ONCRMTIMELINECOMMENTADD'];

logEvent('STEP 3 — EVENT CHECK', 'Checking event type', [
    'received_event' => $event,
    'allowed_events' => $allowedEvents,
]);

if (!$event || !in_array($event, $allowedEvents)) {
    logEvent('STEP 3 — EVENT CHECK', 'IGNORED — event not in allowed list', ['event' => $event]);
    respond('ignored', 'Unrecognized event: ' . ($event ?? 'none'));
}

logEvent('STEP 3 — EVENT CHECK', 'Passed — valid event', ['event' => $event]);

// ============================================================
// DIRECTION A: Lead COMMENTS field → Timeline comment
// Events: ONCRMLEADADD, ONCRMLEADUPDATE
// ============================================================
if (in_array($event, ['ONCRMLEADADD', 'ONCRMLEADUPDATE'])) {

    logEvent('DIRECTION A', 'Lead COMMENTS → Timeline triggered', ['event' => $event]);

    // A1: Get Lead ID
    $leadId = $data['data']['FIELDS']['ID'] ?? null;
    logEvent('DIRECTION A — STEP 1', 'Extracting Lead ID', [
        'data[data]'   => $data['data'] ?? null,
        'extracted_id' => $leadId,
    ]);

    if (!$leadId) {
        respond('error', 'Lead ID missing');
    }

    // A2: Loop guard — skip if triggered by our own timeline→comment sync
    if (isLocked($leadId, 'timeline_to_comment')) {
        respond('ignored', 'Loop guard — skipping, triggered by our own timeline sync');
    }

    // A3: Fetch Lead
    logEvent('DIRECTION A — STEP 2', 'Fetching lead', ['lead_id' => $leadId]);
    $leadResult = callBitrix('crm.lead.get', ['id' => $leadId]);

    if (empty($leadResult['result'])) {
        logEvent('DIRECTION A — STEP 2', 'FAILED — Lead not found', $leadResult);
        respond('error', 'Lead not found');
    }

    $newComment = trim($leadResult['result']['COMMENTS'] ?? '');
    logEvent('DIRECTION A — STEP 3', 'COMMENTS field value', [
        'value'    => $newComment,
        'is_empty' => $newComment === '',
    ]);

    // A4: Skip if empty
    if (!$newComment) {
        respond('ignored', 'COMMENTS field is empty — nothing to sync');
    }

    // A5: Fetch latest timeline comment to check for duplicate
    $timelineResult      = callBitrix('crm.timeline.comment.list', [
        'filter[ENTITY_TYPE]' => 'lead',
        'filter[ENTITY_ID]'   => $leadId,
        'order[ID]'           => 'DESC',
        'limit'               => 1,
    ]);
    $lastTimelineComment = trim($timelineResult['result'][0]['COMMENT'] ?? '');

    logEvent('DIRECTION A — STEP 4', 'Duplicate check', [
        'last_timeline_comment' => $lastTimelineComment,
        'new_comment'           => $newComment,
        'is_duplicate'          => $lastTimelineComment === $newComment,
    ]);

    if ($lastTimelineComment === $newComment) {
        respond('ignored', 'Timeline already has this comment — skipping');
    }

    // A6: Set lock before posting to timeline (prevents Direction B from looping back)
    setLock($leadId, 'comment_to_timeline');

    // A7: Post to timeline
    logEvent('DIRECTION A — STEP 5', 'Posting to timeline', [
        'lead_id' => $leadId,
        'comment' => $newComment,
    ]);

    $addResult = callBitrix('crm.timeline.comment.add', [
        'fields[ENTITY_TYPE]' => 'lead',
        'fields[ENTITY_ID]'   => $leadId,
        'fields[COMMENT]'     => $newComment,
    ]);

    if (!empty($addResult['result'])) {
        logEvent('DIRECTION A — STEP 5', 'SUCCESS — Timeline comment created', [
            'lead_id'      => $leadId,
            'comment'      => $newComment,
            'new_entry_id' => $addResult['result'],
        ]);
        respond('success', 'Timeline comment created successfully');
    } else {
        logEvent('DIRECTION A — STEP 5', 'FAILED — Could not create timeline comment', $addResult);
        respond('error', 'Failed to create timeline comment');
    }
}

// ============================================================
// DIRECTION B: Timeline comment → Lead COMMENTS field
// Event: ONCRMTIMELINECOMMENTADD
// ============================================================
if ($event === 'ONCRMTIMELINECOMMENTADD') {

    logEvent('DIRECTION B', 'Timeline → Lead COMMENTS triggered', ['event' => $event]);

    // B1: Get Comment ID
    $commentId = $data['data']['FIELDS']['ID'] ?? null;
    logEvent('DIRECTION B — STEP 1', 'Extracting Comment ID', [
        'data[data]'   => $data['data'] ?? null,
        'extracted_id' => $commentId,
    ]);

    if (!$commentId) {
        respond('error', 'Comment ID missing');
    }

    // B2: Fetch comment
    logEvent('DIRECTION B — STEP 2', 'Fetching comment from Bitrix24', ['comment_id' => $commentId]);
    $commentResult = callBitrix('crm.timeline.comment.get', ['id' => $commentId]);

    if (empty($commentResult['result'])) {
        logEvent('DIRECTION B — STEP 2', 'FAILED — Comment not found', $commentResult);
        respond('error', 'Comment not found');
    }

    $commentData = $commentResult['result'];
    $commentText = trim($commentData['COMMENT'] ?? '');
    $entityType  = strtolower($commentData['ENTITY_TYPE'] ?? '');
    $entityId    = (int)($commentData['ENTITY_ID'] ?? 0);

    logEvent('DIRECTION B — STEP 3', 'Comment data extracted', [
        'comment_text' => $commentText,
        'entity_type'  => $entityType,
        'entity_id'    => $entityId,
    ]);

    // B3: Validate
    if ($entityType !== 'lead') {
        respond('ignored', 'Entity is not a lead — skipping');
    }
    if (!$entityId) {
        respond('error', 'Entity ID missing');
    }
    if (!$commentText) {
        respond('ignored', 'Comment text is empty');
    }

    logEvent('DIRECTION B — STEP 3', 'Validation passed — valid lead comment');

    // B4: Loop guard — skip if triggered by our own comment→timeline sync
    if (isLocked($entityId, 'comment_to_timeline')) {
        respond('ignored', 'Loop guard — skipping, triggered by our own COMMENTS sync');
    }

    // B5: Fetch current lead COMMENTS
    logEvent('DIRECTION B — STEP 4', 'Fetching lead to read COMMENTS field', ['lead_id' => $entityId]);
    $leadResult = callBitrix('crm.lead.get', ['id' => $entityId]);

    if (empty($leadResult['result'])) {
        logEvent('DIRECTION B — STEP 4', 'FAILED — Lead not found', $leadResult);
        respond('error', 'Lead not found');
    }

    $existingComment = trim($leadResult['result']['COMMENTS'] ?? '');
    logEvent('DIRECTION B — STEP 5', 'Existing COMMENTS field', [
        'existing_comment' => $existingComment,
        'new_comment'      => $commentText,
        'is_same'          => $existingComment === $commentText,
    ]);

    // B6: Skip if already same
    if ($existingComment === $commentText) {
        respond('ignored', 'COMMENTS already matches timeline comment — skipping');
    }

    // B7: Set lock before updating COMMENTS (prevents Direction A from looping back)
    setLock($entityId, 'timeline_to_comment');

    // B8: Update COMMENTS field
    logEvent('DIRECTION B — STEP 6', 'Updating lead COMMENTS field', [
        'lead_id' => $entityId,
        'comment' => $commentText,
    ]);

    $updateResult = callBitrix('crm.lead.update', [
        'id'               => $entityId,
        'fields[COMMENTS]' => $commentText,
    ]);

    if (!isset($updateResult['error'])) {
        logEvent('DIRECTION B — STEP 6', 'SUCCESS — Lead COMMENTS field updated', [
            'lead_id' => $entityId,
            'comment' => $commentText,
        ]);
        respond('success', 'Lead COMMENTS field updated successfully');
    } else {
        logEvent('DIRECTION B — STEP 6', 'FAILED — Could not update COMMENTS field', $updateResult);
        respond('error', 'Failed to update lead COMMENTS field');
    }
}

respond('ignored', 'No matching handler found');