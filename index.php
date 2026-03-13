<?php

// ============================================================
// CONFIG — update this to your Bitrix24 webhook URL
// ============================================================
define('BITRIX_WEBHOOK_URL', 'https://your-domain.bitrix24.com/rest/1/your_webhook_token/');
define('LOG_FILE',  __DIR__ . '/comments_sync.log');
define('HASH_DIR',  __DIR__ . '/hashes/'); // stores last synced comment hash per lead
define('LOCK_DIR',  __DIR__ . '/locks/');  // stores loop guard locks
define('LOCK_TTL',  10);                   // lock expiry in seconds

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

// Save last synced comment hash for a lead+direction
function saveHash(string $leadId, string $direction, string $comment): void
{
    if (!is_dir(HASH_DIR)) mkdir(HASH_DIR, 0755, true);
    $file = HASH_DIR . 'lead_' . $leadId . '_' . $direction . '.hash';
    file_put_contents($file, md5($comment));
    logEvent('HASH SAVED', 'Saved hash for lead', [
        'lead_id'   => $leadId,
        'direction' => $direction,
        'hash'      => md5($comment),
        'comment'   => $comment,
    ]);
}

// Check if this exact comment was already synced from this direction
function alreadySynced(string $leadId, string $direction, string $comment): bool
{
    if (!is_dir(HASH_DIR)) mkdir(HASH_DIR, 0755, true);
    $file = HASH_DIR . 'lead_' . $leadId . '_' . $direction . '.hash';
    if (!file_exists($file)) return false;
    $saved = trim(file_get_contents($file));
    $current = md5($comment);
    $isSame = $saved === $current;
    logEvent('HASH CHECK', 'Checking if already synced', [
        'lead_id'        => $leadId,
        'direction'      => $direction,
        'saved_hash'     => $saved,
        'current_hash'   => $current,
        'already_synced' => $isSame,
    ]);
    return $isSame;
}

// Time-based lock to block the immediate echo-back event
function isLocked(string $leadId, string $direction): bool
{
    if (!is_dir(LOCK_DIR)) mkdir(LOCK_DIR, 0755, true);
    $lockFile = LOCK_DIR . 'lead_' . $leadId . '_' . $direction . '.lock';
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < LOCK_TTL) {
        logEvent('LOCK CHECK', 'LOCKED — preventing loop', [
            'lead_id'   => $leadId,
            'direction' => $direction,
            'age_secs'  => time() - filemtime($lockFile),
            'ttl'       => LOCK_TTL,
        ]);
        return true;
    }
    return false;
}

function setLock(string $leadId, string $direction): void
{
    if (!is_dir(LOCK_DIR)) mkdir(LOCK_DIR, 0755, true);
    $lockFile = LOCK_DIR . 'lead_' . $leadId . '_' . $direction . '.lock';
    file_put_contents($lockFile, time());
    logEvent('LOCK SET', 'Lock created — expires in ' . LOCK_TTL . 's', [
        'lead_id'   => $leadId,
        'direction' => $direction,
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

logEvent('STEP 1 — METHOD CHECK', 'Passed — POST received');

// ============================================================
// STEP 2: Read POST data
// ============================================================
$data = $_POST;

if (empty($data)) {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? [];
    logEvent('STEP 2 — POST DATA', '$_POST empty, tried raw input', [
        'raw'     => $raw,
        'decoded' => $data,
    ]);
} else {
    logEvent('STEP 2 — POST DATA', 'Received $_POST data', $data);
}

if (empty($data)) {
    logEvent('STEP 2 — POST DATA', 'FAILED — no data at all');
    respond('error', 'No data received');
}

// ============================================================
// STEP 3: Validate event
// ============================================================
$event         = $data['event'] ?? null;
$allowedEvents = ['ONCRMLEADADD', 'ONCRMLEADUPDATE', 'ONCRMTIMELINECOMMENTADD'];

logEvent('STEP 3 — EVENT CHECK', 'Checking event', [
    'received' => $event,
    'allowed'  => $allowedEvents,
]);

if (!$event || !in_array($event, $allowedEvents)) {
    respond('ignored', 'Unrecognized event: ' . ($event ?? 'none'));
}

logEvent('STEP 3 — EVENT CHECK', 'Passed', ['event' => $event]);

// ============================================================
// DIRECTION A: Lead COMMENTS → Timeline
// Events: ONCRMLEADADD, ONCRMLEADUPDATE
// ============================================================
if (in_array($event, ['ONCRMLEADADD', 'ONCRMLEADUPDATE'])) {

    logEvent('DIRECTION A', 'Lead COMMENTS → Timeline triggered', ['event' => $event]);

    $leadId = $data['data']['FIELDS']['ID'] ?? null;
    logEvent('DIRECTION A — STEP 1', 'Extracting Lead ID', ['lead_id' => $leadId]);

    if (!$leadId) {
        respond('error', 'Lead ID missing');
    }

    // Check loop guard — was this ONCRMLEADUPDATE fired by our OWN crm.lead.update (Direction B)?
    if (isLocked($leadId, 'b_updated_lead')) {
        respond('ignored', 'Loop guard — this update was triggered by Direction B, skipping');
    }

    // Fetch Lead
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

    if (!$newComment) {
        respond('ignored', 'COMMENTS field is empty — nothing to sync');
    }

    // Check hash — was this exact comment already synced to timeline?
    if (alreadySynced($leadId, 'a_comment_to_timeline', $newComment)) {
        respond('ignored', 'This comment was already synced to timeline — skipping duplicate');
    }

    // Fetch latest timeline comment as extra safety check
    $timelineResult      = callBitrix('crm.timeline.comment.list', [
        'filter[ENTITY_TYPE]' => 'lead',
        'filter[ENTITY_ID]'   => $leadId,
        'order[ID]'           => 'DESC',
        'limit'               => 1,
    ]);
    $lastTimelineComment = trim($timelineResult['result'][0]['COMMENT'] ?? '');

    logEvent('DIRECTION A — STEP 4', 'Timeline latest comment check', [
        'last_timeline' => $lastTimelineComment,
        'new_comment'   => $newComment,
        'is_duplicate'  => $lastTimelineComment === $newComment,
    ]);

    if ($lastTimelineComment === $newComment) {
        respond('ignored', 'Timeline already has this comment — skipping');
    }

    // Set lock so Direction B does not echo back when ONCRMTIMELINECOMMENTADD fires
    setLock($leadId, 'a_posted_timeline');

    // Post to timeline
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
        // Save hash so we don't re-sync this same comment again
        saveHash($leadId, 'a_comment_to_timeline', $newComment);
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

    $commentId = $data['data']['FIELDS']['ID'] ?? null;
    logEvent('DIRECTION B — STEP 1', 'Extracting Comment ID', ['comment_id' => $commentId]);

    if (!$commentId) {
        respond('error', 'Comment ID missing');
    }

    // Fetch comment
    $commentResult = callBitrix('crm.timeline.comment.get', ['id' => $commentId]);

    if (empty($commentResult['result'])) {
        logEvent('DIRECTION B — STEP 2', 'FAILED — Comment not found', $commentResult);
        respond('error', 'Comment not found');
    }

    $commentData = $commentResult['result'];
    $commentText = trim($commentData['COMMENT'] ?? '');
    $entityType  = strtolower($commentData['ENTITY_TYPE'] ?? '');
    $entityId    = (int)($commentData['ENTITY_ID'] ?? 0);

    logEvent('DIRECTION B — STEP 3', 'Comment data', [
        'comment_text' => $commentText,
        'entity_type'  => $entityType,
        'entity_id'    => $entityId,
    ]);

    if ($entityType !== 'lead') respond('ignored', 'Not a lead entity');
    if (!$entityId)             respond('error',   'Entity ID missing');
    if (!$commentText)          respond('ignored', 'Comment text is empty');

    logEvent('DIRECTION B — STEP 3', 'Validation passed');

    // Check loop guard — was this timeline comment posted by our OWN Direction A?
    if (isLocked($entityId, 'a_posted_timeline')) {
        respond('ignored', 'Loop guard — this timeline comment was posted by Direction A, skipping');
    }

    // Check hash — was this exact comment already synced to COMMENTS?
    if (alreadySynced($entityId, 'b_timeline_to_comment', $commentText)) {
        respond('ignored', 'This comment was already synced to COMMENTS field — skipping');
    }

    // Fetch current lead COMMENTS
    $leadResult = callBitrix('crm.lead.get', ['id' => $entityId]);

    if (empty($leadResult['result'])) {
        logEvent('DIRECTION B — STEP 4', 'FAILED — Lead not found', $leadResult);
        respond('error', 'Lead not found');
    }

    $existingComment = trim($leadResult['result']['COMMENTS'] ?? '');

    logEvent('DIRECTION B — STEP 5', 'COMMENTS field comparison', [
        'existing' => $existingComment,
        'new'      => $commentText,
        'is_same'  => $existingComment === $commentText,
    ]);

    if ($existingComment === $commentText) {
        respond('ignored', 'COMMENTS already matches — skipping');
    }

    // Set lock so Direction A does not echo back when ONCRMLEADUPDATE fires
    setLock($entityId, 'b_updated_lead');

    // Update COMMENTS
    logEvent('DIRECTION B — STEP 6', 'Updating lead COMMENTS', [
        'lead_id' => $entityId,
        'comment' => $commentText,
    ]);

    $updateResult = callBitrix('crm.lead.update', [
        'id'               => $entityId,
        'fields[COMMENTS]' => $commentText,
    ]);

    if (!isset($updateResult['error'])) {
        // Save hash so we don't re-sync this same comment again
        saveHash($entityId, 'b_timeline_to_comment', $commentText);
        logEvent('DIRECTION B — STEP 6', 'SUCCESS — Lead COMMENTS updated', [
            'lead_id' => $entityId,
            'comment' => $commentText,
        ]);
        respond('success', 'Lead COMMENTS field updated successfully');
    } else {
        logEvent('DIRECTION B — STEP 6', 'FAILED — Could not update COMMENTS', $updateResult);
        respond('error', 'Failed to update lead COMMENTS field');
    }
}

respond('ignored', 'No matching handler');