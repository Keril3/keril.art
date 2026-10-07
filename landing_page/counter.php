<?php
declare(strict_types=1);

const VISITOR_COOKIE = 'keril_visitor';
const VISITOR_COOKIE_TTL = 31536000;

function respond(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('Vary: Cookie');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    respond(405, ['error' => 'method_not_allowed']);
}

$secretPath = getenv('KERIL_COUNTER_SECRET_FILE') ?: '/etc/keril.art/counter-secret';
$secret = @file_get_contents($secretPath);
if ($secret === false || strlen(trim($secret)) < 64) {
    respond(503, ['error' => 'counter_not_configured']);
}
$secret = trim($secret);

$dataDirectory = getenv('KERIL_COUNTER_DATA_DIR') ?: '/var/lib/keril.art/counter';
if (!is_dir($dataDirectory) && !@mkdir($dataDirectory, 0750, true) && !is_dir($dataDirectory)) {
    respond(503, ['error' => 'counter_storage_unavailable']);
}

$visitorId = $_COOKIE[VISITOR_COOKIE] ?? '';
if (!is_string($visitorId) || !preg_match('/\A[a-f0-9]{64}\z/D', $visitorId)) {
    try {
        $visitorId = bin2hex(random_bytes(32));
    } catch (Throwable $error) {
        respond(503, ['error' => 'visitor_id_unavailable']);
    }

    setcookie(VISITOR_COOKIE, $visitorId, [
        'expires' => time() + VISITOR_COOKIE_TTL,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

$today = gmdate('Y-m-d');
$dailyVisitorHash = hash_hmac('sha256', $today . ':' . $visitorId, $secret);
$logPath = $dataDirectory . '/visitors-' . $today . '.jsonl';
$log = @fopen($logPath, 'c+');
if ($log === false) {
    respond(503, ['error' => 'counter_log_unavailable']);
}
if (!flock($log, LOCK_EX)) {
    fclose($log);
    respond(503, ['error' => 'counter_lock_unavailable']);
}

$uniqueVisitors = [];
$pageViews = 0;
rewind($log);
while (($line = fgets($log)) !== false) {
    $entry = json_decode($line, true);
    if (!is_array($entry) || !isset($entry['visitor'])) {
        continue;
    }
    if (is_string($entry['visitor']) && preg_match('/\A[a-f0-9]{64}\z/D', $entry['visitor'])) {
        $uniqueVisitors[$entry['visitor']] = true;
        $pageViews++;
    }
}

$logEntry = json_encode([
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'visitor' => $dailyVisitorHash,
    'event' => 'pageview',
], JSON_UNESCAPED_SLASHES);
$appendOffset = ftell($log);
$lineToWrite = $logEntry . "\n";
if ($logEntry === false || $appendOffset === false || fseek($log, 0, SEEK_END) !== 0
    || fwrite($log, $lineToWrite) !== strlen($lineToWrite) || !fflush($log)) {
    if ($appendOffset !== false) {
        ftruncate($log, $appendOffset);
    }
    flock($log, LOCK_UN);
    fclose($log);
    respond(503, ['error' => 'counter_log_write_failed']);
}

$uniqueVisitors[$dailyVisitorHash] = true;
$pageViews++;
flock($log, LOCK_UN);
fclose($log);

// All-time uniques use a separate keyed hash that never rotates; each browser is stored once.
$allTimeVisitorHash = hash_hmac('sha256', 'all-time:' . $visitorId, $secret);
$allTimeLog = @fopen($dataDirectory . '/all-visitors.jsonl', 'c+');
if ($allTimeLog === false) {
    respond(503, ['error' => 'counter_log_unavailable']);
}
if (!flock($allTimeLog, LOCK_EX)) {
    fclose($allTimeLog);
    respond(503, ['error' => 'counter_lock_unavailable']);
}

$allTimeVisitors = 0;
$isKnownVisitor = false;
rewind($allTimeLog);
while (($line = fgets($allTimeLog)) !== false) {
    $entry = json_decode($line, true);
    if (is_array($entry) && isset($entry['visitor']) && is_string($entry['visitor'])
        && preg_match('/\A[a-f0-9]{64}\z/D', $entry['visitor'])) {
        $allTimeVisitors++;
        if (hash_equals($entry['visitor'], $allTimeVisitorHash)) {
            $isKnownVisitor = true;
        }
    }
}

if (!$isKnownVisitor) {
    $allTimeEntry = json_encode([
        'firstSeen' => $today,
        'visitor' => $allTimeVisitorHash,
    ], JSON_UNESCAPED_SLASHES);
    $allTimeOffset = ftell($allTimeLog);
    $allTimeLine = $allTimeEntry . "\n";
    if ($allTimeEntry === false || $allTimeOffset === false || fseek($allTimeLog, 0, SEEK_END) !== 0
        || fwrite($allTimeLog, $allTimeLine) !== strlen($allTimeLine) || !fflush($allTimeLog)) {
        if ($allTimeOffset !== false) {
            ftruncate($allTimeLog, $allTimeOffset);
        }
        flock($allTimeLog, LOCK_UN);
        fclose($allTimeLog);
        respond(503, ['error' => 'counter_log_write_failed']);
    }
    $allTimeVisitors++;
}
flock($allTimeLog, LOCK_UN);
fclose($allTimeLog);

$retentionDate = gmdate('Y-m-d', time() - 90 * 86400);
foreach (glob($dataDirectory . '/visitors-*.jsonl') ?: [] as $oldLogPath) {
    if (preg_match('/visitors-(\d{4}-\d{2}-\d{2})\.jsonl\z/D', basename($oldLogPath), $matches)
        && $matches[1] < $retentionDate) {
        @unlink($oldLogPath);
    }
}

respond(200, [
    'date' => $today,
    'uniqueVisitorsToday' => count($uniqueVisitors),
    'pageViewsToday' => $pageViews,
    'uniqueVisitorsAllTime' => $allTimeVisitors,
]);