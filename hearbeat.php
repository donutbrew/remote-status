<?php
declare(strict_types=1);

/*
 * remote-status heartbeat endpoint
 *
 * POST:
 *   Hosts report their status.
 *
 *   Required:
 *     host
 *     key
 *     timestamp
 *
 *   Additional POST fields are treated as service statuses:
 *     service_name=ok
 *     service_name=fail
 *
 * GET:
 *   /status/heartbeat.php?host=pilr
 *
 *   Returns 200 only when:
 *     - a heartbeat has been received recently
 *     - every reported service is "ok"
 *
 *   Returns 503 otherwise.
 */

// -----------------------------------------------------------------------------
// Configuration
// -----------------------------------------------------------------------------

const LOG_FILE = '/var/lib/remote-status/heartbeat.log';

// How old a heartbeat can be before the host is considered down.
const MAX_AGE_SECONDS = 360;

// Per-host authentication keys.
//
// Generate with:
//   openssl rand -hex 32
//
$hosts = [
    'pilr' => [
        'key' => 'REPLACE_WITH_LONG_RANDOM_SECRET',
    ],
];

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function respond(int $status, string $message, array $data = []): never
{
    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode(
        array_merge(['status' => $message], $data),
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function valid_host(string $host): bool
{
    return preg_match('/^[a-zA-Z0-9_-]+$/', $host) === 1;
}

function valid_service_name(string $name): bool
{
    return preg_match('/^[a-zA-Z0-9_-]+$/', $name) === 1;
}

function parse_timestamp(string $timestamp): ?int
{
    try {
        $dt = new DateTimeImmutable($timestamp);
        return $dt->getTimestamp();
    } catch (Exception) {
        return null;
    }
}

function append_log(array $record): void
{
    $line = json_encode(
        $record,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if ($line === false) {
        respond(500, 'log_encoding_failed');
    }

    if (
        file_put_contents(
            LOG_FILE,
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX
        ) === false
    ) {
        respond(500, 'log_write_failed');
    }
}

// -----------------------------------------------------------------------------
// POST — receive heartbeat
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $host = isset($_POST['host'])
        ? trim((string) $_POST['host'])
        : null;

    $key = isset($_POST['key'])
        ? trim((string) $_POST['key'])
        : null;

    $timestamp = isset($_POST['timestamp'])
        ? trim((string) $_POST['timestamp'])
        : null;

    if ($host === null || $key === null || $timestamp === null) {
        respond(400, 'missing_required_field');
    }

    if (!valid_host($host)) {
        respond(400, 'invalid_host');
    }

    if (!isset($hosts[$host])) {
        respond(404, 'unknown_host');
    }

    // Constant-time authentication comparison.
    if (!hash_equals($hosts[$host]['key'], $key)) {
        respond(403, 'invalid_key');
    }

    // -------------------------------------------------------------------------
    // Validate timestamp
    // -------------------------------------------------------------------------

    $reportedTimestamp = parse_timestamp($timestamp);

    if ($reportedTimestamp === null) {
        respond(400, 'invalid_timestamp');
    }

    $receivedTimestamp = time();

    // Reject timestamps more than five minutes from server time.
    if (abs($receivedTimestamp - $reportedTimestamp) > 300) {
        respond(400, 'timestamp_out_of_range');
    }

    // -------------------------------------------------------------------------
    // Build heartbeat record
    // -------------------------------------------------------------------------

    $record = [
        'received' => gmdate('c', $receivedTimestamp),
        'reported' => gmdate('c', $reportedTimestamp),
        'host' => $host,
    ];

    $serviceCount = 0;

    foreach ($_POST as $name => $value) {

        // Protocol fields are not services.
        if (in_array($name, ['host', 'key', 'timestamp'], true)) {
            continue;
        }

        if (!valid_service_name($name)) {
            respond(400, 'invalid_service_name');
        }

        $value = trim((string) $value);

        if (!in_array($value, ['ok', 'fail'], true)) {
            respond(400, "invalid_service:$name");
        }

        $record[$name] = $value;
        $serviceCount++;
    }

    if ($serviceCount === 0) {
        respond(400, 'no_services');
    }

    // Deliberately do NOT record the authentication key.
    append_log($record);

    respond(200, 'accepted', [
        'host' => $host,
        'received' => $record['received'],
    ]);
}

// -----------------------------------------------------------------------------
// GET — health check for UptimeRobot
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $host = isset($_GET['host'])
        ? trim((string) $_GET['host'])
        : '';

    if ($host === '' || !valid_host($host)) {
        respond(400, 'invalid_host');
    }

    if (!isset($hosts[$host])) {
        respond(404, 'unknown_host');
    }

    if (!is_readable(LOG_FILE)) {
        respond(503, 'no_heartbeat');
    }

    /*
     * Read the log and find the most recent record for this host.
     *
     * This is intentionally simple for now. If the log becomes very large,
     * this can be changed later to per-host state files or SQLite.
     */

    $lines = file(
        LOG_FILE,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if ($lines === false) {
        respond(503, 'log_unavailable');
    }

    $latest = null;

    for ($i = count($lines) - 1; $i >= 0; $i--) {

        $record = json_decode($lines[$i], true);

        if (!is_array($record)) {
            continue;
        }

        if (($record['host'] ?? null) === $host) {
            $latest = $record;
            break;
        }
    }

    if ($latest === null || !isset($latest['received'])) {
        respond(503, 'no_heartbeat');
    }

    // -------------------------------------------------------------------------
    // Check heartbeat age
    // -------------------------------------------------------------------------

    try {
        $received = new DateTimeImmutable($latest['received']);
        $age = time() - $received->getTimestamp();
    } catch (Exception) {
        respond(503, 'invalid_heartbeat');
    }

    $healthy = $age <= MAX_AGE_SECONDS;

    // -------------------------------------------------------------------------
    // Check every reported service
    // -------------------------------------------------------------------------

    foreach ($latest as $name => $value) {

        if (in_array($name, ['received', 'reported', 'host'], true)) {
            continue;
        }

        if ($value !== 'ok') {
            $healthy = false;
            break;
        }
    }

    // -------------------------------------------------------------------------
    // Return health status
    // -------------------------------------------------------------------------

    if (!$healthy) {
        respond(503, 'unhealthy', [
            'host' => $host,
            'age_seconds' => $age,
            'last_received' => $latest['received'],
        ]);
    }

    respond(200, 'healthy', [
        'host' => $host,
        'age_seconds' => $age,
        'last_received' => $latest['received'],
    ]);
}

// -----------------------------------------------------------------------------
// Anything else
// -----------------------------------------------------------------------------

header('Allow: GET, POST');

respond(405, 'method_not_allowed');
