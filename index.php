<?php

// ============================================================
// CONFIG — update these values (Change only webhook url and custom field ID 'UF_CRM_...')
// ============================================================
define('LOG_FILE',  __DIR__ . '/comments_sync.log');
define('HASH_DIR',  __DIR__ . '/hashes/');
define('LOCK_DIR',  __DIR__ . '/locks/');
define('LOCK_TTL',  10);

// ONLY CONFIGURE THIS
define('BITRIX_WEBHOOK_URL', 'https://test.vortexwebre.com/rest/1/s2avv4lnmgmi8xor/'); // <- Inbound webhook
define('CUSTOM_FIELD', 'UF_CRM_1773728226354');

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

// Convert array of text values → single timeline string
// ["val1", "val2", "val3"] → "val1\nval2\nval3"
function arrayToTimeline(array $values): string
{
    return implode("\n", array_filter(array_map('trim', $values)));
}

function saveHash(string $leadId, string $direction, string $value): void
{
    if (!is_dir(HASH_DIR)) mkdir(HASH_DIR, 0755, true);
    $file = HASH_DIR . 'lead_' . $leadId . '_' . $direction . '.hash';
    file_put_contents($file, md5($value));
    logEvent('HASH SAVED', 'Saved hash', [
        'lead_id'   => $leadId,
        'direction' => $direction,
        'hash'      => md5($value),
        'value'     => $value,
    ]);
}

function alreadySynced(string $leadId, string $direction, string $value): bool
{
    if (!is_dir(HASH_DIR)) mkdir(HASH_DIR, 0755, true);
    $file = HASH_DIR . 'lead_' . $leadId . '_' . $direction . '.hash';
    if (!file_exists($file)) {
        logEvent('HASH CHECK', 'No hash file — not synced yet', [
            'lead_id'   => $leadId,
            'direction' => $direction,
        ]);
        return false;
    }
    $saved   = trim(file_get_contents($file));
    $current = md5($value);
    $isSame  = $saved === $current;
    logEvent('HASH CHECK', 'Checking if already synced', [
        'lead_id'        => $leadId,
        'direction'      => $direction,
        'saved_hash'     => $saved,
        'current_hash'   => $current,
        'already_synced' => $isSame,
    ]);
    return $isSame;
}

function getLockAge(string $leadId, string $direction): ?int
{
    if (!is_dir(LOCK_DIR)) mkdir(LOCK_DIR, 0755, true);
    $lockFile = LOCK_DIR . 'lead_' . $leadId . '_' . $direction . '.lock';
    if (!file_exists($lockFile)) return null;
    return time() - filemtime($lockFile);
}

function isLocked(string $leadId, string $direction): bool
{
    $age    = getLockAge($leadId, $direction);
    $locked = $age !== null && $age < LOCK_TTL;
    logEvent('LOCK CHECK', $locked ? 'LOCKED — preventing loop' : 'Not locked — proceeding', [
        'lead_id'   => $leadId,
        'direction' => $direction,
        'lock_age'  => $age !== null ? $age . 's' : 'no lock file',
        'ttl'       => LOCK_TTL . 's',
        'is_locked' => $locked,
    ]);
    return $locked;
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

function clearLock(string $leadId, string $direction): void
{
    $lockFile = LOCK_DIR . 'lead_' . $leadId . '_' . $direction . '.lock';
    if (file_exists($lockFile)) {
        unlink($lockFile);
        logEvent('LOCK CLEARED', 'Lock file deleted', [
            'lead_id'   => $leadId,
            'direction' => $direction,
        ]);
    }
}

// ============================================================
// ENTRY POINT
// ============================================================

header('Content-Type: application/json');

logEvent('BOOT', 'Script started', [
    'method'      => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'timestamp'   => date('d.m.Y H:i:s'),
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
// DIRECTION A: UF_CRM multiple text field → Timeline
// Events: ONCRMLEADADD, ONCRMLEADUPDATE
// ============================================================
if (in_array($event, ['ONCRMLEADADD', 'ONCRMLEADUPDATE'])) {

    logEvent('DIRECTION A — ENTRY', '★ UF_CRM field → Timeline triggered', [
        'event'    => $event,
        'raw_data' => $data,
    ]);

    // A1: Get Lead ID
    $leadId = $data['data']['FIELDS']['ID'] ?? null;
    logEvent('DIRECTION A — STEP 1', 'Extracting Lead ID', [
        'data_fields' => $data['data']['FIELDS'] ?? null,
        'lead_id'     => $leadId,
    ]);

    if (!$leadId) {
        respond('error', 'Lead ID missing');
    }

    // A2: Loop guard check
    logEvent('DIRECTION A — STEP 2', 'Checking loop guard', [
        'lead_id'  => $leadId,
        'lock_age' => getLockAge($leadId, 'b_updated_lead') !== null
            ? getLockAge($leadId, 'b_updated_lead') . 's'
            : 'no lock file',
    ]);

    if (isLocked($leadId, 'b_updated_lead')) {
        clearLock($leadId, 'b_updated_lead');
        respond('ignored', 'Loop guard — update was triggered by Direction B, skipping');
    }

    // A3: Fetch Lead
    logEvent('DIRECTION A — STEP 3', 'Fetching lead', ['lead_id' => $leadId]);
    $leadResult = callBitrix('crm.lead.get', ['id' => $leadId]);

    if (empty($leadResult['result'])) {
        logEvent('DIRECTION A — STEP 3', 'FAILED — Lead not found', $leadResult);
        respond('error', 'Lead not found');
    }

    // A4: Read UF_CRM multiple value field
    $rawValue = $leadResult['result'][CUSTOM_FIELD] ?? [];
    if (!is_array($rawValue)) {
        $rawValue = $rawValue !== '' ? [$rawValue] : [];
    }
    $rawValue = array_values(array_filter(array_map('trim', $rawValue)));

    logEvent('DIRECTION A — STEP 4', 'UF_CRM field values', [
        'field'     => CUSTOM_FIELD,
        'raw_value' => $rawValue,
        'is_empty'  => empty($rawValue),
    ]);

    if (empty($rawValue)) {
        respond('ignored', CUSTOM_FIELD . ' is empty — nothing to sync');
    }

    // A5: Convert array → single timeline string (newline separated)
    $timelineText = arrayToTimeline($rawValue);

    logEvent('DIRECTION A — STEP 5', 'Converted to timeline string', [
        'array_values'  => $rawValue,
        'timeline_text' => $timelineText,
    ]);

    // A6: Hash check
    if (alreadySynced($leadId, 'a_field_to_timeline', $timelineText)) {
        respond('ignored', 'Already synced these values to timeline — skipping duplicate');
    }

    // A7: Check latest timeline comment
    $timelineResult      = callBitrix('crm.timeline.comment.list', [
        'filter[ENTITY_TYPE]' => 'lead',
        'filter[ENTITY_ID]'   => $leadId,
        'order[ID]'           => 'DESC',
        'limit'               => 1,
    ]);
    $lastTimelineComment = trim($timelineResult['result'][0]['COMMENT'] ?? '');

    logEvent('DIRECTION A — STEP 6', 'Timeline duplicate check', [
        'last_timeline' => $lastTimelineComment,
        'new_value'     => $timelineText,
        'is_duplicate'  => $lastTimelineComment === $timelineText,
    ]);

    if ($lastTimelineComment === $timelineText) {
        respond('ignored', 'Timeline already has this value — skipping');
    }

    // A8: Set lock to block Direction B echo-back
    setLock($leadId, 'a_posted_timeline');

    // A9: Post to timeline
    logEvent('DIRECTION A — STEP 7', 'Posting to timeline', [
        'lead_id'       => $leadId,
        'timeline_text' => $timelineText,
    ]);

    $addResult = callBitrix('crm.timeline.comment.add', [
        'fields[ENTITY_TYPE]' => 'lead',
        'fields[ENTITY_ID]'   => $leadId,
        'fields[COMMENT]'     => $timelineText,
    ]);

    if (!empty($addResult['result'])) {
        saveHash($leadId, 'a_field_to_timeline', $timelineText);
        logEvent('DIRECTION A — STEP 7', '★ SUCCESS — Timeline comment created', [
            'lead_id'       => $leadId,
            'timeline_text' => $timelineText,
            'new_entry_id'  => $addResult['result'],
        ]);
        respond('success', 'Timeline comment created successfully');
    } else {
        logEvent('DIRECTION A — STEP 7', 'FAILED — Could not create timeline comment', $addResult);
        respond('error', 'Failed to create timeline comment');
    }
}

// ============================================================
// DIRECTION B: Timeline comment → APPEND to UF_CRM multiple field
// Event: ONCRMTIMELINECOMMENTADD
// NOTE: This APPENDS the new comment as a new value — does NOT replace existing values
// ============================================================
if ($event === 'ONCRMTIMELINECOMMENTADD') {

    logEvent('DIRECTION B — ENTRY', '★ Timeline → UF_CRM field (APPEND) triggered', [
        'event'    => $event,
        'raw_data' => $data,
    ]);

    // B1: Get Comment ID
    $commentId = $data['data']['FIELDS']['ID'] ?? null;
    logEvent('DIRECTION B — STEP 1', 'Extracting Comment ID', [
        'data_fields' => $data['data']['FIELDS'] ?? null,
        'comment_id'  => $commentId,
    ]);

    if (!$commentId) {
        respond('error', 'Comment ID missing');
    }

    // B2: Fetch comment
    logEvent('DIRECTION B — STEP 2', 'Fetching timeline comment', ['comment_id' => $commentId]);
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

    if ($entityType !== 'lead') respond('ignored', 'Not a lead entity — skipping');
    if (!$entityId)             respond('error',   'Entity ID missing');
    if (!$commentText)          respond('ignored', 'Comment text is empty');

    logEvent('DIRECTION B — STEP 3', 'Validation passed');

    // B3: Loop guard check — was this posted by Direction A?
    logEvent('DIRECTION B — STEP 4', 'Checking loop guard', [
        'lead_id'  => $entityId,
        'lock_age' => getLockAge($entityId, 'a_posted_timeline') !== null
            ? getLockAge($entityId, 'a_posted_timeline') . 's'
            : 'no lock file',
    ]);

    if (isLocked($entityId, 'a_posted_timeline')) {
        clearLock($entityId, 'a_posted_timeline');
        respond('ignored', 'Loop guard — comment was posted by Direction A, skipping');
    }

    // B4: Hash check — was this exact comment already appended?
    if (alreadySynced($entityId, 'b_timeline_to_field', $commentText)) {
        respond('ignored', 'Already appended this comment to UF_CRM field — skipping');
    }

    // B5: Fetch current UF_CRM field values
    logEvent('DIRECTION B — STEP 5', 'Fetching current lead UF_CRM values', ['lead_id' => $entityId]);
    $leadResult = callBitrix('crm.lead.get', ['id' => $entityId]);

    if (empty($leadResult['result'])) {
        logEvent('DIRECTION B — STEP 5', 'FAILED — Lead not found', $leadResult);
        respond('error', 'Lead not found');
    }

    $existingValues = $leadResult['result'][CUSTOM_FIELD] ?? [];
    if (!is_array($existingValues)) {
        $existingValues = $existingValues !== '' ? [$existingValues] : [];
    }
    $existingValues = array_values(array_filter(array_map('trim', $existingValues)));

    logEvent('DIRECTION B — STEP 6', 'Current UF_CRM field values', [
        'existing_values' => $existingValues,
        'new_comment'     => $commentText,
    ]);

    // B6: Check if this comment already exists in the field values — skip if duplicate
    if (in_array($commentText, $existingValues)) {
        logEvent('DIRECTION B — STEP 6', 'IGNORED — comment already exists in UF_CRM field', [
            'comment'         => $commentText,
            'existing_values' => $existingValues,
        ]);
        respond('ignored', 'Comment already exists in UF_CRM field — skipping');
    }

    // B7: APPEND new comment to existing values
    $updatedValues = array_merge($existingValues, [$commentText]);

    logEvent('DIRECTION B — STEP 7', 'Appending new comment to field', [
        'existing_values' => $existingValues,
        'appended_value'  => $commentText,
        'updated_values'  => $updatedValues,
    ]);

    // B8: Set lock so Direction A does not echo back
    setLock($entityId, 'b_updated_lead');

    // B9: Update UF_CRM multiple field with all values (existing + new)
    $params = ['id' => $entityId];
    foreach ($updatedValues as $index => $value) {
        $params['fields[' . CUSTOM_FIELD . '][' . $index . ']'] = $value;
    }

    logEvent('DIRECTION B — STEP 8', 'Updating UF_CRM field with appended values', [
        'lead_id'        => $entityId,
        'updated_values' => $updatedValues,
        'params'         => $params,
    ]);

    $updateResult = callBitrix('crm.lead.update', $params);

    if (!isset($updateResult['error'])) {
        saveHash($entityId, 'b_timeline_to_field', $commentText);
        logEvent('DIRECTION B — STEP 8', '★ SUCCESS — Comment appended to UF_CRM field', [
            'lead_id'        => $entityId,
            'appended_value' => $commentText,
            'updated_values' => $updatedValues,
        ]);
        respond('success', 'Comment appended to UF_CRM field successfully');
    } else {
        logEvent('DIRECTION B — STEP 8', 'FAILED — Could not update UF_CRM field', $updateResult);
        respond('error', 'Failed to update UF_CRM field');
    }
}

respond('ignored', 'No matching handler');