<?php

declare(strict_types=1);

use App\Support\Bootstrap;
use App\Support\Env;

require_once __DIR__ . '/../vendor/autoload.php';

Bootstrap::init();

$options = getopt('', [
    'file::',
    'endpoint::',
    'limit::',
]);

$filePath = $options['file'] ?? __DIR__ . '/../storage/bridge_logs_2026_W16.json';
$endpoint = $options['endpoint'] ?? 'http://localhost/api/ingest.php';
$limit = isset($options['limit']) ? max(0, (int) $options['limit']) : null;

$secret = Env::get('LOG_INGEST_SECRET');
$userAgent = Env::get('LOG_INGEST_USER_AGENT', 'WalkerPisa-Bridge-Logs');

if ($secret === null || $secret === '') {
    fwrite(STDERR, "ERROR: LOG_INGEST_SECRET is not configured.\n");
    exit(1);
}

if (!is_file($filePath)) {
    fwrite(STDERR, "ERROR: Historical file not found: {$filePath}\n");
    exit(1);
}

$rawJson = file_get_contents($filePath);

if ($rawJson === false) {
    fwrite(STDERR, "ERROR: Unable to read historical file: {$filePath}\n");
    exit(1);
}

$data = json_decode($rawJson, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "ERROR: Malformed historical JSON: " . json_last_error_msg() . "\n");
    exit(1);
}

if (!is_array($data) || !isset($data['uploads']) || !is_array($data['uploads'])) {
    fwrite(STDERR, "ERROR: Historical JSON must contain an uploads array.\n");
    exit(1);
}

$uploads = $data['uploads'];
$totalUploads = count($uploads);

$sent = 0;
$created = 0;
$duplicates = 0;
$failed = 0;
$skipped = 0;

echo "Historical import\n";
echo "File: {$filePath}\n";
echo "Endpoint: {$endpoint}\n";
echo "Uploads in file: {$totalUploads}\n";

if ($limit !== null && $limit > 0) {
    echo "Limit: {$limit}\n";
}

echo "\n";

foreach ($uploads as $index => $upload) {
    if ($limit !== null && $limit > 0 && $sent >= $limit) {
        break;
    }

    if (!is_array($upload) || !isset($upload['payload']) || !is_array($upload['payload'])) {
        $skipped++;
        echo "[SKIP] Upload #{$index}: missing payload object\n";
        continue;
    }

    $payload = $upload['payload'];

    $response = postJson($endpoint, $payload, $userAgent, $secret);

    $sent++;

    if ($response['http_status'] >= 200 && $response['http_status'] < 300) {
        $body = $response['json'];

        if (($body['duplicate'] ?? false) === true) {
            $duplicates++;
            echo "[DUPLICATE] Upload #{$index} bridge={$payload['bridgeId']} ingest_id={$body['ingest_id']}\n";
        } else {
            $created++;
            echo "[CREATED] Upload #{$index} bridge={$payload['bridgeId']} ingest_id={$body['ingest_id']} lines={$body['line_count']}\n";
        }

        continue;
    }

    $failed++;
    echo "[FAILED] Upload #{$index} HTTP {$response['http_status']}\n";
    echo $response['body'] . "\n";
}

echo "\n";
echo "Summary\n";
echo "Sent: {$sent}\n";
echo "Created: {$created}\n";
echo "Duplicates: {$duplicates}\n";
echo "Failed: {$failed}\n";
echo "Skipped: {$skipped}\n";

exit($failed > 0 ? 1 : 0);

function postJson(string $endpoint, array $payload, string $userAgent, string $secret): array
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        return [
            'http_status' => 0,
            'body' => 'Unable to encode payload as JSON.',
            'json' => null,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'ignore_errors' => true,
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'User-Agent: ' . $userAgent,
                'X-Log-Auth: ' . $secret,
            ]),
            'content' => $json,
            'timeout' => 30,
        ],
    ]);

    $responseBody = file_get_contents($endpoint, false, $context);

    $httpStatus = extractHttpStatus($http_response_header ?? []);

    if ($responseBody === false) {
        return [
            'http_status' => $httpStatus,
            'body' => 'HTTP request failed.',
            'json' => null,
        ];
    }

    $decoded = json_decode($responseBody, true);

    return [
        'http_status' => $httpStatus,
        'body' => $responseBody,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function extractHttpStatus(array $headers): int
{
    if ($headers === []) {
        return 0;
    }

    $firstHeader = $headers[0] ?? '';

    if (preg_match('/HTTP\/\S+\s+(\d{3})/', $firstHeader, $matches) !== 1) {
        return 0;
    }

    return (int) $matches[1];
}
