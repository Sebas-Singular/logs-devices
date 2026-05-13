<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Http\JsonResponse;
use App\Ingest\IngestValidator;
use App\Storage\NdjsonWriter;
use App\Support\Env;
use App\Device\DeviceResolver;
use App\Ingest\LogParser;
use App\Parsers\SeverityDeriver;
use App\Ingest\LogEventWriter;
use App\Http\SecurityHeaders;
use App\Http\RateLimiter;
// -----------------------------------------------------------------------------
// Autoloader de Composer (PSR-4).
// Una sola línea reemplaza los 5 require_once que había antes.
// Carga automáticamente cualquier clase en src/ según su namespace.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../../vendor/autoload.php';

Env::load(__DIR__ . '/../../../../private/.env');
Env::loadPhpConfig(__DIR__ . '/../../src/Config/runtime.local.php');

SecurityHeaders::applyJson();
applyIngestRateLimit();

$receivedAt = new DateTimeImmutable('now');
$receivedAtSql = $receivedAt->format('Y-m-d H:i:s');

$validator = new IngestValidator();
$writer = new NdjsonWriter();

$serverError = $validator->validateServerRequest($_SERVER);

if ($serverError !== null) {
    writeRejectedRequest($writer, $receivedAt, $serverError, null);

    JsonResponse::send([
        'ok' => false,
        'error' => $serverError['code'],
        'message' => $serverError['message'],
    ], $serverError['status']);
}

$rawBody = file_get_contents('php://input');

if ($rawBody === false || trim($rawBody) === '') {
    $error = [
        'status' => 400,
        'code' => 'empty_body',
        'message' => 'Request body is empty.',
    ];

    writeRejectedRequest($writer, $receivedAt, $error, null);

    JsonResponse::send([
        'ok' => false,
        'error' => $error['code'],
        'message' => $error['message'],
    ], $error['status']);
}

$contentHash = hash('sha256', $rawBody);

$payload = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    $error = [
        'status' => 400,
        'code' => 'malformed_json',
        'message' => 'Malformed JSON: ' . json_last_error_msg(),
    ];

    writeRejectedRequest($writer, $receivedAt, $error, $rawBody);

    JsonResponse::send([
        'ok' => false,
        'error' => $error['code'],
        'message' => $error['message'],
    ], $error['status']);
}

$payloadError = $validator->validatePayload($payload);

if ($payloadError !== null) {
    writeRejectedRequest($writer, $receivedAt, $payloadError, $rawBody);

    JsonResponse::send([
        'ok' => false,
        'error' => $payloadError['code'],
        'message' => $payloadError['message'],
    ], $payloadError['status']);
}

$bridgeId = trim((string) $payload['bridgeId']);
$bridgeName = trim((string) ($payload['bridgeName'] ?? ''));
$logText = (string) $payload['logText'];

$rawRelativePath = buildRawRelativePath($receivedAt, $bridgeId);
$rawAbsolutePath = privateStoragePath($rawRelativePath);

try {
    $pdo = Connection::make();

    $existingStmt = $pdo->prepare(
        'SELECT id, received_at, raw_path
         FROM log_ingests
         WHERE content_hash = :content_hash
         LIMIT 1'
    );

    $existingStmt->execute([
        'content_hash' => $contentHash,
    ]);

    $existing = $existingStmt->fetch();

    if ($existing !== false) {
        JsonResponse::send([
            'ok' => true,
            'duplicate' => true,
            'message' => 'Payload already received.',
            'ingest_id' => (int) $existing['id'],
            'first_received_at' => $existing['received_at'],
            'raw_path' => $existing['raw_path'],
            'content_hash' => $contentHash,
        ]);
    }

    $rawRecord = [
        'received_at' => $receivedAtSql,
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'content_hash' => $contentHash,
        'payload' => $payload,
    ];

    $writer->append($rawAbsolutePath, $rawRecord);

    $lineCount = countLogLines($logText);

    $payloadSummary = [
        'bytes' => $payload['bytes'] ?? null,
        'fromOffset' => $payload['fromOffset'] ?? null,
        'toOffset' => $payload['toOffset'] ?? null,
        'sentAt' => $payload['sentAt'] ?? null,
        'rotated' => $payload['rotated'] ?? null,
        'bridgeName' => $bridgeName !== '' ? $bridgeName : null,
    ];

    // -------------------------------------------------------------------------
    // Resolver el bridge antes del INSERT del ingest
    // -------------------------------------------------------------------------
    // Esto permite guardar source_device_id en log_ingests desde el principio,
    // evitando un UPDATE posterior. Si la creación del bridge falla por algún
    // motivo, no hay un log_ingests huérfano sin device.
    $deviceResolver = new DeviceResolver($pdo);
    $bridgeDeviceId = $deviceResolver->resolveOrCreateBridge(
        bridgeIdReported: $bridgeId,
        bridgeName: $bridgeName,
        seenAt: $receivedAt,
    );

    // -------------------------------------------------------------------------
    // INSERT log_ingests con status='received'
    // -------------------------------------------------------------------------
    $insertStmt = $pdo->prepare(
        'INSERT INTO log_ingests (
            received_at,
            remote_addr,
            user_agent,
            source_type,
            source_device_id,
            bridge_id_reported,
            content_hash,
            raw_path,
            payload_summary,
            status,
            line_count,
            parsed_ok_count,
            parsed_error_count,
            processing_started_at,
            processing_finished_at
        ) VALUES (
            :received_at,
            :remote_addr,
            :user_agent,
            :source_type,
            :source_device_id,
            :bridge_id_reported,
            :content_hash,
            :raw_path,
            :payload_summary,
            :status,
            :line_count,
            0,
            0,
            NULL,
            NULL
        )'
    );

    $insertStmt->execute([
        'received_at'        => $receivedAtSql,
        'remote_addr'        => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent'         => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'source_type'        => 'walkerpisa_bridge',
        'source_device_id'   => $bridgeDeviceId,
        'bridge_id_reported' => $bridgeId,
        'content_hash'       => $contentHash,
        'raw_path'           => $rawRelativePath,
        'payload_summary'    => json_encode($payloadSummary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'status'             => 'received',
        'line_count'         => $lineCount,
    ]);

    $ingestId = (int) $pdo->lastInsertId();

    // -------------------------------------------------------------------------
    // Procesamiento inline: parsear líneas e insertar en log_events
    // -------------------------------------------------------------------------
    // Marcar el ingest como 'parsing' antes de empezar. Si algo falla a mitad,
    // el catch lo dejará como 'error' con processing_finished_at registrado.
    $processingStartedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $pdo->prepare(
        'UPDATE log_ingests
            SET status                = :status,
                processing_started_at = :started_at
          WHERE id = :id'
    )->execute([
        'status'     => 'parsing',
        'started_at' => $processingStartedAt,
        'id'         => $ingestId,
    ]);

    // El orquestador parsea, resuelve balizas y construye los eventos.
    // No inserta nada en BD por sí mismo.
    $logParser = new LogParser($deviceResolver, new SeverityDeriver());

    $parseResult = $logParser->processIngest(
        logText: $logText,
        ingestId: $ingestId,
        bridgeDeviceId: $bridgeDeviceId,
        receivedAt: $receivedAt,
    );

    $pdo->beginTransaction();

    try {
        $eventWriter = new LogEventWriter($pdo);
        $eventWriter->insertEvents($parseResult['events']);

        $processingFinishedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $pdo->prepare(
            'UPDATE log_ingests
                SET status                 = :status,
                    parsed_ok_count        = :ok_count,
                    parsed_error_count     = :err_count,
                    processing_finished_at = :finished_at
              WHERE id = :id'
        )->execute([
            'status'      => 'processed',
            'ok_count'    => $parseResult['parsed_ok'],
            'err_count'   => $parseResult['parsed_error'],
            'finished_at' => $processingFinishedAt,
            'id'          => $ingestId,
        ]);

        $pdo->commit();
    } catch (Throwable $eventInsertError) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $pdo->prepare(
            'UPDATE log_ingests
                SET status                 = :status,
                    processing_finished_at = :finished_at
              WHERE id = :id'
        )->execute([
            'status'      => 'error',
            'finished_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'id'          => $ingestId,
        ]);
        throw $eventInsertError;
    }

    JsonResponse::send([
        'ok'             => true,
        'duplicate'      => false,
        'message'        => 'Payload received and processed.',
        'ingest_id'      => $ingestId,
        'bridge_id'      => $bridgeId,
        'bridge_device_id' => $bridgeDeviceId,
        'line_count'     => $lineCount,
        'parsed_ok'      => $parseResult['parsed_ok'],
        'parsed_error'   => $parseResult['parsed_error'],
        'raw_path'       => $rawRelativePath,
        'content_hash'   => $contentHash,
    ]);
} catch (Throwable $exception) {
    $error = [
        'status' => 500,
        'code' => 'ingest_failed',
        'message' => $exception->getMessage(),
    ];

    writeRejectedRequest($writer, $receivedAt, $error, $rawBody);

    JsonResponse::send([
        'ok' => false,
        'error' => 'ingest_failed',
        'message' => $exception->getMessage(),
    ], 500);
}

function countLogLines(string $logText): int
{
    $lines = preg_split('/\R/', trim($logText));

    if ($lines === false) {
        return 0;
    }

    $nonEmptyLines = array_filter(
        $lines,
        static fn(string $line): bool => trim($line) !== ''
    );

    return count($nonEmptyLines);
}

function buildRawRelativePath(DateTimeImmutable $date, string $bridgeId): string
{
    $safeBridgeId = preg_replace('/[^A-Za-z0-9_-]/', '_', $bridgeId);

    if ($safeBridgeId === null || $safeBridgeId === '') {
        $safeBridgeId = 'unknown';
    }

    return sprintf(
        'raw/%s/%s/%s/bridge_%s.ndjson',
        $date->format('Y'),
        $date->format('m'),
        $date->format('d'),
        $safeBridgeId
    );
}

function buildRejectedRelativePath(DateTimeImmutable $date): string
{
    return sprintf(
        'rejected/%s/%s/%s/rejected_%s.ndjson',
        $date->format('Y'),
        $date->format('m'),
        $date->format('d'),
        $date->format('Y-m-d')
    );
}

function privateStoragePath(string $relativePath): string
{
    $basePath = Env::get(
        'STORAGE_BASE_PATH',
        __DIR__ . '/../../../../private/storage'
    );

    return rtrim((string) $basePath, '/\\') . '/' . ltrim($relativePath, '/\\');
}

function writeRejectedRequest(
    NdjsonWriter $writer,
    DateTimeImmutable $receivedAt,
    array $error,
    ?string $rawBody
): void {
    $relativePath = buildRejectedRelativePath($receivedAt);
    $absolutePath = privateStoragePath($relativePath);

    $record = [
        'received_at' => $receivedAt->format('Y-m-d H:i:s'),
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
        'error' => $error,
        'raw_body' => $rawBody,
    ];

    try {
        $writer->append($absolutePath, $record);
    } catch (Throwable) {
    }
}

function applyIngestRateLimit(): void
{
    if (!ingestRateLimitEnabled()) {
        return;
    }

    $result = RateLimiter::hit(
        storageDir: privateStoragePath('rate-limit'),
        key: ingestRateLimitKey(),
        maxAttempts: ingestRateLimitEnvInt('INGEST_RATE_LIMIT_MAX', 1000),
        windowSeconds: ingestRateLimitEnvInt('INGEST_RATE_LIMIT_WINDOW_SECONDS', 600),
    );

    if ($result['allowed']) {
        if (!headers_sent()) {
            header('X-RateLimit-Limit: ' . $result['limit']);
            header('X-RateLimit-Remaining: ' . $result['remaining']);
            header('X-RateLimit-Reset: ' . $result['reset_at']);
        }

        return;
    }

    if (!headers_sent()) {
        header('Retry-After: ' . $result['retry_after_seconds']);
        header('X-RateLimit-Limit: ' . $result['limit']);
        header('X-RateLimit-Remaining: 0');
        header('X-RateLimit-Reset: ' . $result['reset_at']);
    }

    JsonResponse::send([
        'ok' => false,
        'error' => 'rate_limited',
        'message' => 'Too many ingest requests. Please retry later.',
        'retry_after_seconds' => $result['retry_after_seconds'],
    ], 429);
}

function ingestRateLimitEnabled(): bool
{
    $raw = getenv('INGEST_RATE_LIMIT_ENABLED');

    if ($raw === false || trim((string) $raw) === '') {
        return true;
    }

    return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
}

function ingestRateLimitEnvInt(string $key, int $default): int
{
    $raw = getenv($key);

    if ($raw === false || trim((string) $raw) === '') {
        return $default;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT);

    if ($value === false) {
        return $default;
    }

    return max(0, (int) $value);
}

function ingestRateLimitKey(): string
{
    $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
    $authHeader = trim((string) ($_SERVER['HTTP_X_LOG_AUTH'] ?? ''));

    $authMarker = $authHeader === ''
        ? 'no-auth-header'
        : hash('sha256', $authHeader);

    return 'ingest'
        . '|ip=' . $remoteAddr
        . '|ua=' . $userAgent
        . '|auth=' . $authMarker;
}
