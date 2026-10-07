<?php
declare(strict_types=1);

// Visitor counter for keril.art.
//   GET                         read-only statistics, never sets a cookie or records anything
//   POST                        record a visit (only after the visitor accepted cookies on the page)
//   POST ?action=forget         delete this browser's record and its cookie (cookies declined later)
// A visit is recorded only when the browser sends back the cookie set by an earlier response,
// so clients that ignore cookies (most bots) are never counted.

const VISITOR_COOKIE = 'keril_visitor';
const VISITOR_COOKIE_TTL = 31536000;
const REQUEST_HEADER = 'HTTP_X_KERIL_COUNTER';
const BOT_USER_AGENT_PATTERN = '/bot|crawl|spider|slurp|archiv|scrap|fetch|headless|phantom|selenium|puppeteer|playwright'
    . '|python|curl|wget|httpclient|okhttp|java\/|go-http|libwww|monitor|uptime|preview|facebookexternalhit|embedly/i';

function respond(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('Vary: Cookie');
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function isDate(mixed $value): bool
{
    return is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) === 1;
}

/** @return array<string, array{firstSeen: string, lastSeen: string}> */
function readVisitors(string $path): array
{
    $visitors = [];
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return $visitors;
    }
    foreach (explode("\n", $contents) as $line) {
        $entry = json_decode($line, true);
        if (!is_array($entry) || !isset($entry['visitor']) || !is_string($entry['visitor'])
            || !preg_match('/\A[a-f0-9]{64}\z/D', $entry['visitor']) || !isDate($entry['firstSeen'] ?? null)) {
            continue;
        }
        $lastSeen = isDate($entry['lastSeen'] ?? null) ? $entry['lastSeen'] : $entry['firstSeen'];
        $visitors[$entry['visitor']] = ['firstSeen' => $entry['firstSeen'], 'lastSeen' => $lastSeen];
    }
    return $visitors;
}

function writeAtomically(string $path, string $contents): bool
{
    $temporaryPath = $path . '.tmp';
    if (@file_put_contents($temporaryPath, $contents) !== strlen($contents) || !@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        return false;
    }
    return true;
}

function writeVisitors(string $path, array $visitors): bool
{
    $lines = '';
    foreach ($visitors as $hash => $dates) {
        $line = json_encode([
            'firstSeen' => $dates['firstSeen'],
            'lastSeen' => $dates['lastSeen'],
            'visitor' => (string) $hash,
        ], JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return false;
        }
        $lines .= $line . "\n";
    }
    return writeAtomically($path, $lines);
}

/** Periods are rolling and include today: 30 and 365 days. A browser counts if its latest visit falls inside. */
function computeStats(array $visitors, string $today): array
{
    $monthStart = gmdate('Y-m-d', strtotime($today . ' -29 days'));
    $yearStart = gmdate('Y-m-d', strtotime($today . ' -364 days'));
    $stats = ['date' => $today, 'allTime' => count($visitors), 'month' => 0, 'year' => 0];
    foreach ($visitors as $dates) {
        if ($dates['lastSeen'] >= $monthStart) {
            $stats['month']++;
        }
        if ($dates['lastSeen'] >= $yearStart) {
            $stats['year']++;
        }
    }
    return $stats;
}

function statsPayload(array $stats, array $extra = []): array
{
    return [
        'uniqueVisitorsAllTime' => $stats['allTime'],
        'uniqueVisitorsPastMonth' => $stats['month'],
        'uniqueVisitorsPastYear' => $stats['year'],
    ] + $extra;
}

function setVisitorCookie(string $value, int $expires): void
{
    setcookie(VISITOR_COOKIE, $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    respond(405, ['error' => 'method_not_allowed']);
}

// Writes must come from this site's own script: a custom header (which cross-site pages cannot send
// without a CORS preflight that this endpoint never grants) and, when the browser reports them,
// a same-origin Origin and Sec-Fetch-Site.
if ($method === 'POST') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    if (($_SERVER[REQUEST_HEADER] ?? '') !== '1'
        || ($origin !== null && !in_array($origin, ['https://' . $host, 'http://' . $host], true))
        || ($fetchSite !== null && $fetchSite !== 'same-origin')) {
        respond(403, ['error' => 'forbidden']);
    }
}

$secret = @file_get_contents(getenv('KERIL_COUNTER_SECRET_FILE') ?: '/etc/keril.art/counter-secret');
if ($secret === false || strlen(trim($secret)) < 64) {
    respond(503, ['error' => 'counter_not_configured']);
}
$secret = trim($secret);

$dataDirectory = getenv('KERIL_COUNTER_DATA_DIR') ?: '/var/lib/keril.art/counter';
if (!is_dir($dataDirectory) && !@mkdir($dataDirectory, 0750, true) && !is_dir($dataDirectory)) {
    respond(503, ['error' => 'counter_storage_unavailable']);
}
$visitorsPath = $dataDirectory . '/all-visitors.jsonl';
$statsPath = $dataDirectory . '/stats-cache.json';
// The list stops growing at this size, so a flood of fake visitors cannot fill the disk.
$maxVisitors = max(1, (int) (getenv('KERIL_COUNTER_MAX_VISITORS') ?: 500000));
$today = gmdate('Y-m-d');

$lock = @fopen($dataDirectory . '/all-visitors.lock', 'c');
if ($lock === false) {
    respond(503, ['error' => 'counter_storage_unavailable']);
}

// Reads are served from a small cache refreshed once a day or whenever the list changes.
if ($method === 'GET') {
    $cached = json_decode((string) @file_get_contents($statsPath), true);
    if (is_array($cached) && ($cached['date'] ?? null) === $today
        && is_int($cached['allTime'] ?? null) && is_int($cached['month'] ?? null) && is_int($cached['year'] ?? null)) {
        respond(200, statsPayload($cached));
    }
    if (!flock($lock, LOCK_EX)) {
        respond(503, ['error' => 'counter_lock_unavailable']);
    }
    $stats = computeStats(readVisitors($visitorsPath), $today);
    writeAtomically($statsPath, (string) json_encode($stats));
    flock($lock, LOCK_UN);
    respond(200, statsPayload($stats));
}

$action = $_GET['action'] ?? 'visit';
if ($action !== 'visit' && $action !== 'forget') {
    respond(400, ['error' => 'unknown_action']);
}

$visitorId = $_COOKIE[VISITOR_COOKIE] ?? '';
$hasValidCookie = is_string($visitorId) && preg_match('/\A[a-f0-9]{64}\z/D', $visitorId) === 1;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isBot = !is_string($userAgent) || $userAgent === '' || preg_match(BOT_USER_AGENT_PATTERN, $userAgent) === 1;

// A browser without our cookie gets one now and is counted on its next request, once it sends the cookie back.
if ($action === 'visit' && !$hasValidCookie && !$isBot) {
    try {
        setVisitorCookie(bin2hex(random_bytes(32)), time() + VISITOR_COOKIE_TTL);
    } catch (Throwable $error) {
        respond(503, ['error' => 'visitor_id_unavailable']);
    }
}

if (!flock($lock, LOCK_EX)) {
    respond(503, ['error' => 'counter_lock_unavailable']);
}
$visitors = readVisitors($visitorsPath);
$changed = false;
$counted = false;
$visitorHash = $hasValidCookie ? hash_hmac('sha256', 'all-time:' . $visitorId, $secret) : null;

if ($action === 'forget') {
    if ($visitorHash !== null && isset($visitors[$visitorHash])) {
        unset($visitors[$visitorHash]);
        $changed = true;
    }
} elseif ($visitorHash !== null && !$isBot) {
    if (isset($visitors[$visitorHash])) {
        $counted = true;
        if ($visitors[$visitorHash]['lastSeen'] !== $today) {
            $visitors[$visitorHash]['lastSeen'] = $today;
            $changed = true;
        }
    } elseif (count($visitors) < $maxVisitors) {
        $visitors[$visitorHash] = ['firstSeen' => $today, 'lastSeen' => $today];
        $counted = true;
        $changed = true;
    }
}

// The list is rewritten only when something changed: at most once per browser per day.
if ($changed && !writeVisitors($visitorsPath, $visitors)) {
    flock($lock, LOCK_UN);
    respond(503, ['error' => 'counter_log_write_failed']);
}
$stats = computeStats($visitors, $today);
writeAtomically($statsPath, (string) json_encode($stats));
flock($lock, LOCK_UN);

if ($action === 'forget') {
    setVisitorCookie('', time() - 3600);
    respond(200, statsPayload($stats, ['forgotten' => true]));
}

// Earlier versions kept daily page-view logs; they are no longer written, and leftovers are removed after 90 days.
$retentionDate = gmdate('Y-m-d', time() - 90 * 86400);
foreach (glob($dataDirectory . '/visitors-*.jsonl') ?: [] as $oldLogPath) {
    if (preg_match('/visitors-(\d{4}-\d{2}-\d{2})\.jsonl\z/D', basename($oldLogPath), $matches)
        && $matches[1] < $retentionDate) {
        @unlink($oldLogPath);
    }
}

respond(200, statsPayload($stats, [
    'counted' => $counted,
    'needsConfirmation' => !$hasValidCookie && !$isBot,
]));
