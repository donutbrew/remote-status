<?php

declare(strict_types=1);

const DATA_FILE = '/var/lib/remote-status/heartbeat.log';
const MAX_AGE_SECONDS = 360;

// Host-specific authentication keys.
// Keep these secret.
$hosts = [
    'pilr' => [
        'key' => 'REPLACE_WITH_LONG_RANDOM_SECRET',
    ],
];

header('Content-Type: application/json');

function respond(int $statusCode, array $data): never
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/*
 * --------------------------------------------------------------------------
 * POST: receive heartbeat
 * --------------------------------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $host = $_POST['host'] ?? '';
    $key = $_POST['key'] ?? '';
    $timestamp = $_POST['timestamp'] ?? '';

    if ($host === '' || $key === '' || $timestamp === '') {
        respond(400, [
            'status' => 'missing_required_field',
        ]);
    }

    if (!isset($hosts[$host])) {
        respond(401, [
            'status' => 'invalid_host',
        ]);
    }

    if (!hash_equals($hosts[$host]['key'], $key)) {
        respond(401, [
            'status' => 'invalid_key',
        ]);
    }

    /*
     * Reserved protocol fields. Everything else in POST is a service.
     */
    $reserved = [
        'host',
        'key',
        'timestamp',
    ];

    $services = [];

    foreach ($_POST as $name => $value) {

        if (in_array($name, $reserved, true)) {
            continue;
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            respond(400, [
                'status' => 'invalid_service_name',
                'service' => $name,
            ]);
        }

        if ($value !== 'ok' && $value !== 'fail') {
            respond(400, [
                'status' => 'invalid_service_status',
                'service' => $name,
            ]);
        }

        $services[$name] = $value;
    }

    if ($services === []) {
        respond(400, [
            'status' => 'no_services_reported',
        ]);
    }

    $received = gmdate('c');

    /*
     * Store only information needed for monitoring.
     * The authentication key is deliberately NOT logged.
     */
    $record = [
        'received' => $received,
        'reported' => $timestamp,
        'host' => $host,
    ];

    foreach ($services as $name => $status) {
        $record[$name] = $status;
    }

    $line = json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL;

    if (file_put_contents(DATA_FILE, $line, FILE_APPEND | LOCK_EX) === false) {
        respond(500, [
            'status' => 'log_write_failed',
        ]);
    }

    respond(200, [
        'status' => 'accepted',
        'host' => $host,
    ]);
}


/*
 * --------------------------------------------------------------------------
 * GET: health check
 *
 * Examples:
 *
 *   ?host=pilr
 *       Checks every service reported by the latest heartbeat.
 *
 *   ?host=pilr&service=homebridge
 *       Checks only Homebridge.
 *
 * --------------------------------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $host = $_GET['host'] ?? '';
    $service = $_GET['service'] ?? null;

    if ($host === '') {
        respond(400, [
            'status' => 'missing_host',
        ]);
    }

    if (!isset($hosts[$host])) {
        respond(400, [
            'status' => 'invalid_host',
        ]);
    }

    if ($service !== null) {

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $service)) {
            respond(400, [
                'status' => 'invalid_service',
                'host' => $host,
                'service' => $service,
            ]);
        }
    }

    if (!is_readable(DATA_FILE)) {
        respond(503, [
            'status' => 'no_heartbeat',
            'host' => $host,
        ]);
    }

    /*
     * Read the log backwards and find the newest heartbeat for this host.
     *
     * The log is expected to be small enough for this simple implementation.
     */
    $lines = file(DATA_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        respond(503, [
            'status' => 'no_heartbeat',
            'host' => $host,
        ]);
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

    if ($latest === null) {
        respond(503, [
            'status' => 'no_heartbeat',
            'host' => $host,
        ]);
    }

    /*
     * Determine heartbeat age using server receive time rather than the
     * client's timestamp. This prevents a bad client clock from making
     * a stale heartbeat appear fresh.
     */
    $receivedTimestamp = strtotime($latest['received'] ?? '');

    if ($receivedTimestamp === false) {
        respond(503, [
            'status' => 'invalid_heartbeat',
            'host' => $host,
        ]);
    }

    $age = time() - $receivedTimestamp;

    if ($age < 0) {
        $age = 0;
    }

    if ($age > MAX_AGE_SECONDS) {
        $response = [
            'status' => 'stale',
            'host' => $host,
            'age_seconds' => $age,
            'last_received' => $latest['received'],
        ];

        if ($service !== null) {
            $response['service'] = $service;
        }

        respond(503, $response);
    }

    /*
     * If a specific service was requested, check only that service.
     */
    if ($service !== null) {

        if (!array_key_exists($service, $latest)) {
            respond(503, [
                'status' => 'service_not_reported',
                'host' => $host,
                'service' => $service,
                'age_seconds' => $age,
                'last_received' => $latest['received'],
            ]);
        }

        $serviceStatus = $latest[$service];

        if ($serviceStatus !== 'ok') {
            respond(503, [
                'status' => 'unhealthy',
                'host' => $host,
                'service' => $service,
                'service_status' => $serviceStatus,
                'age_seconds' => $age,
                'last_received' => $latest['received'],
            ]);
        }

        respond(200, [
            'status' => 'healthy',
            'host' => $host,
            'service' => $service,
            'service_status' => 'ok',
            'age_seconds' => $age,
            'last_received' => $latest['received'],
        ]);
    }

    /*
     * No specific service requested:
     * check every service reported in the heartbeat.
     */
    $services = [];

    foreach ($latest as $name => $value) {

        if (in_array($name, [
            'received',
            'reported',
            'host',
        ], true)) {
            continue;
        }

        $services[$name] = $value;

        if ($value !== 'ok') {
            respond(503, [
                'status' => 'unhealthy',
                'host' => $host,
                'service' => $name,
                'service_status' => $value,
                'age_seconds' => $age,
                'last_received' => $latest['received'],
                'services' => $services,
            ]);
        }
    }

    respond(200, [
        'status' => 'healthy',
        'host' => $host,
        'age_seconds' => $age,
        'last_received' => $latest['received'],
        'services' => $services,
    ]);
}


/*
 * --------------------------------------------------------------------------
 * Unsupported HTTP method
 * --------------------------------------------------------------------------
 */

respond(405, [
    'status' => 'method_not_allowed',
]);
