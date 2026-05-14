<?php

declare(strict_types=1);

use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Storage\Paths;
use App\Support\Bootstrap;
use App\Support\Env;

require_once __DIR__ . '/../../../vendor/autoload.php';

Bootstrap::init();
SecurityHeaders::applyJson();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    JsonResponse::send([
        'ok' => false,
        'error' => 'method_not_allowed',
        'message' => 'Only POST is allowed.',
    ], 405);
}

if (!envFlag('SERVICES_LOGS_IMPORT_ENABLED')) {
    JsonResponse::send([
        'ok' => false,
        'error' => 'services_logs_import_disabled',
        'message' => 'Services logs import endpoint is disabled.',
    ], 403);
}

$expectedToken = (string) Env::get('ADMIN_REPROCESS_TOKEN', '');
$providedToken = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');

if ($expectedToken === '') {
    JsonResponse::send([
        'ok' => false,
        'error' => 'admin_token_not_configured',
        'message' => 'ADMIN_REPROCESS_TOKEN is not configured.',
    ], 500);
}

if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    JsonResponse::send([
        'ok' => false,
        'error' => 'invalid_admin_token',
        'message' => 'Invalid admin token.',
    ], 401);
}

$execute = readBoolQuery('execute', false);
$limit = readIntQuery('limit', 20, 1, 50);
$fileName = readFileName();

$sourceDir = rtrim(
    (string) Env::get(
        'SERVICES_LOGS_STORAGE_PATH',
        Bootstrap::projectRoot() . '/services/logs/storage'
    ),
    '/\\'
);

if (!is_dir($sourceDir)) {
    JsonResponse::send([
        'ok' => false,
        'error' => 'source_dir_not_found',
        'message' => 'Services logs storage directory not found.',
        'source_dir' => $sourceDir,
    ], 500);
}

$statePath = Paths::for('services-import-state.json');
$state = readState($statePath);

try {
    $files = $fileName !== null
        ? [resolveRequestedFile($sourceDir, $fileName)]
        : findBridgeLogFiles($sourceDir);

    $summary = [
        'ok' => true,
        'mode' => $execute ? 'execute' : 'dry-run',
        'source_dir' => $sourceDir,
        'state_path' => $statePath,
        'limit' => $limit,
        'processed_attempts' => 0,
        'created' => 0,
        'duplicates' => 0,
        'failed' => 0,
        'skipped' => 0,
        'files' => [],
    ];

    $endpoint = rtrim((string) Env::get('APP_URL'), '/') . '/api/ingest.php';
    $secret = (string) Env::get('LOG_INGEST_SECRET', '');
    $userAgent = (string) Env::get('LOG_INGEST_USER_AGENT', 'WalkerPisa-Bridge-Logs');

    if ($execute && ($endpoint === '/api/ingest.php' || $secret === '')) {
        JsonResponse::send([
            'ok' => false,
            'error' => 'import_not_configured',
            'message' => 'APP_URL or LOG_INGEST_SECRET is not configured.',
        ], 500);
    }

    foreach ($files as $filePath) {
        if ($summary['processed_attempts'] >= $limit) {
            break;
        }

        $baseName = basename($filePath);

        if (!isFileStable($filePath)) {
            $summary['files'][] = [
                'file' => $baseName,
                'status' => 'skipped',
                'reason' => 'file_recently_modified',
            ];
            continue;
        }

        $data = readBridgeLogFile($filePath);
        $uploads = $data['uploads'];
        $totalUploads = count($uploads);

        $fileState = $state['files'][$baseName] ?? [];
        $nextIndex = max(0, (int) ($fileState['next_index'] ?? 0));

        $fileResult = [
            'file' => $baseName,
            'week' => $data['week'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
            'uploads_total' => $totalUploads,
            'next_index_before' => $nextIndex,
            'pending_before' => max(0, $totalUploads - $nextIndex),
            'processed_attempts' => 0,
            'created' => 0,
            'duplicates' => 0,
            'failed' => 0,
            'skipped' => 0,
            'next_index_after' => $nextIndex,
            'results' => [],
        ];

        if (!$execute) {
            $summary['files'][] = $fileResult;
            continue;
        }

        while ($nextIndex < $totalUploads && $summary['processed_attempts'] < $limit) {
            $upload = $uploads[$nextIndex] ?? null;

            if (!is_array($upload) || !isset($upload['payload']) || !is_array($upload['payload'])) {
                $fileResult['skipped']++;
                $summary['skipped']++;

                $fileResult['results'][] = [
                    'index' => $nextIndex,
                    'outcome' => 'skipped',
                    'reason' => 'missing_payload',
                ];

                $nextIndex++;
                continue;
            }

            $payload = $upload['payload'];
            $response = postJson($endpoint, $payload, $userAgent, $secret);

            $summary['processed_attempts']++;
            $fileResult['processed_attempts']++;

            $body = $response['json'];

            if ($response['http_status'] >= 200 && $response['http_status'] < 300 && is_array($body)) {
                if (($body['duplicate'] ?? false) === true) {
                    $summary['duplicates']++;
                    $fileResult['duplicates']++;

                    $fileResult['results'][] = [
                        'index' => $nextIndex,
                        'outcome' => 'duplicate',
                        'bridge_id' => $payload['bridgeId'] ?? null,
                        'ingest_id' => $body['ingest_id'] ?? null,
                    ];
                } else {
                    $summary['created']++;
                    $fileResult['created']++;

                    $fileResult['results'][] = [
                        'index' => $nextIndex,
                        'outcome' => 'created',
                        'bridge_id' => $payload['bridgeId'] ?? null,
                        'ingest_id' => $body['ingest_id'] ?? null,
                        'line_count' => $body['line_count'] ?? null,
                        'parsed_ok' => $body['parsed_ok'] ?? null,
                        'parsed_error' => $body['parsed_error'] ?? null,
                    ];
                }

                $nextIndex++;
                continue;
            }

            $summary['failed']++;
            $fileResult['failed']++;

            $fileResult['results'][] = [
                'index' => $nextIndex,
                'outcome' => 'failed',
                'bridge_id' => $payload['bridgeId'] ?? null,
                'http_status' => $response['http_status'],
                'body' => truncateString($response['body'], 1000),
            ];

            break;
        }

        $fileResult['next_index_after'] = $nextIndex;
        $fileResult['pending_after'] = max(0, $totalUploads - $nextIndex);

        $state['files'][$baseName] = [
            'next_index' => $nextIndex,
            'uploads_total_last_seen' => $totalUploads,
            'updated_at_last_seen' => $data['updated_at'] ?? null,
            'last_imported_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
        ];

        $summary['files'][] = $fileResult;
    }

    if ($execute) {
        writeState($statePath, $state);
    }

    $summary['ok'] = $summary['failed'] === 0;

    JsonResponse::send($summary, $summary['failed'] > 0 ? 500 : 200);
} catch (Throwable $exception) {
    JsonResponse::send([
        'ok' => false,
        'error' => 'services_logs_import_failed',
        'message' => $exception->getMessage(),
    ], 500);
}

function envFlag(string $key): bool
{
    $value = strtolower(trim((string) Env::get($key, 'false')));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function readBoolQuery(string $key, bool $default): bool
{
    $raw = trim((string) ($_GET[$key] ?? ''));

    if ($raw === '') {
        return $default;
    }

    return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
}

function readIntQuery(string $key, int $default, int $min, int $max): int
{
    $raw = trim((string) ($_GET[$key] ?? ''));

    if ($raw === '') {
        return $default;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT);

    if ($value === false) {
        JsonResponse::send([
            'ok' => false,
            'error' => 'invalid_integer',
            'message' => "{$key} must be an integer.",
        ], 422);
    }

    $value = (int) $value;

    if ($value < $min || $value > $max) {
        JsonResponse::send([
            'ok' => false,
            'error' => 'integer_out_of_range',
            'message' => "{$key} must be between {$min} and {$max}.",
        ], 422);
    }

    return $value;
}

function readFileName(): ?string
{
    $raw = trim((string) ($_GET['file'] ?? ''));

    if ($raw === '') {
        return null;
    }

    if (basename($raw) !== $raw || !preg_match('/^bridge_logs_[A-Za-z0-9_.-]+\.json$/', $raw)) {
        JsonResponse::send([
            'ok' => false,
            'error' => 'invalid_file',
            'message' => 'Invalid file name.',
        ], 422);
    }

    return $raw;
}

function resolveRequestedFile(string $sourceDir, string $fileName): string
{
    $filePath = $sourceDir . '/' . $fileName;

    if (!is_file($filePath)) {
        JsonResponse::send([
            'ok' => false,
            'error' => 'file_not_found',
            'message' => 'Requested bridge log file not found.',
            'file' => $fileName,
        ], 404);
    }

    return $filePath;
}

function findBridgeLogFiles(string $sourceDir): array
{
    $files = glob($sourceDir . '/bridge_logs_*.json');

    if ($files === false || $files === []) {
        JsonResponse::send([
            'ok' => false,
            'error' => 'no_bridge_log_files',
            'message' => 'No bridge_logs_*.json files found in services logs storage.',
            'source_dir' => $sourceDir,
        ], 404);
    }

    sort($files, SORT_NATURAL);

    return $files;
}

function isFileStable(string $filePath): bool
{
    $modifiedAt = filemtime($filePath);

    if ($modifiedAt === false) {
        return false;
    }

    return $modifiedAt <= time() - 3;
}

function readBridgeLogFile(string $filePath): array
{
    $rawJson = file_get_contents($filePath);

    if ($rawJson === false) {
        throw new RuntimeException('Unable to read bridge log file: ' . basename($filePath));
    }

    $data = json_decode($rawJson, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Malformed bridge log JSON: ' . json_last_error_msg());
    }

    if (!is_array($data) || !isset($data['uploads']) || !is_array($data['uploads'])) {
        throw new RuntimeException('Bridge log JSON must contain an uploads array.');
    }

    return $data;
}

function readState(string $statePath): array
{
    if (!is_file($statePath)) {
        return ['files' => []];
    }

    $raw = file_get_contents($statePath);

    if ($raw === false || trim($raw) === '') {
        return ['files' => []];
    }

    $state = json_decode($raw, true);

    if (!is_array($state)) {
        return ['files' => []];
    }

    if (!isset($state['files']) || !is_array($state['files'])) {
        $state['files'] = [];
    }

    return $state;
}

function writeState(string $statePath, array $state): void
{
    $dir = dirname($statePath);

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create state directory.');
    }

    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new RuntimeException('Unable to encode import state.');
    }

    $tmpPath = $statePath . '.tmp';

    if (file_put_contents($tmpPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary import state.');
    }

    if (!rename($tmpPath, $statePath)) {
        throw new RuntimeException('Unable to replace import state file.');
    }
}

function postJson(string $endpoint, array $payload, string $userAgent, string $secret): array
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

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

function truncateString(string $value, int $maxLength): string
{
    if (strlen($value) <= $maxLength) {
        return $value;
    }

    return substr($value, 0, $maxLength) . '...';
}
