<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Device\DeviceResolver;
use App\Http\JsonResponse;
use App\Http\RateLimiter;
use App\Http\SecurityHeaders;
use App\Ingest\IngestValidator;
use App\Ingest\LogEventWriter;
use App\Ingest\LogParser;
use App\Ingest\PayloadNormalizer;
use App\Ingest\StructuredEventNormalizer;
use App\Ingest\VehicleEventNormalizer;
use App\Parsers\SeverityDeriver;
use App\Storage\NdjsonWriter;
use App\Storage\Paths;
use App\Support\Bootstrap;
use App\Support\Env;

require_once __DIR__ . '/../../vendor/autoload.php';

Bootstrap::init();

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

if ($rawBody === false) {
    $rawBody = '';
}

// Capa 1: sanear UTF-8 inválido del cuerpo ANTES de parsear. Un byte corrupto
// en logText invalida el JSON y puede reventar el INSERT en base de datos. Si
// devolvemos 4xx/5xx por ello, el bridge reintenta el mismo bloque para siempre
// (solo avanza su offset ante un 2xx) y se atasca toda la subida.
$rawBody = sanitizeUtf8($rawBody);

if (trim($rawBody) === '') {
    // Lote sin contenido: lo reconocemos (200) para que el bridge avance.
    ackDiscarded($writer, $receivedAt, 'empty_body', 'Request body is empty.', null);
}

$contentHash = hash('sha256', $rawBody);

// Capa 2: decodificar de forma tolerante. JSON_INVALID_UTF8_SUBSTITUTE sustituye
// las secuencias UTF-8 inválidas por U+FFFD en lugar de hacer fallar el decode.
$payload = json_decode($rawBody, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
    // Contenido no decodificable: ACK 200 igual y archivamos el cuerpo en
    // rejected/ para depurar sin atascar al dispositivo.
    ackDiscarded(
        $writer,
        $receivedAt,
        'malformed_json',
        'Malformed JSON: ' . json_last_error_msg(),
        $rawBody
    );
}

$payloadError = $validator->validatePayload($payload);

if ($payloadError !== null) {
    // El payload llegó pero su contenido no es procesable. Sigue siendo un
    // problema de CONTENIDO, así que ACK 200 (no atascar el bridge).
    ackDiscarded(
        $writer,
        $receivedAt,
        $payloadError['code'],
        $payloadError['message'],
        $rawBody
    );
}

$payloadNormalizer = new PayloadNormalizer();
$normalizedPayload = $payloadNormalizer->normalize($payload);

$bridgeId = $normalizedPayload['bridge_id_reported'] !== ''
    ? (string) $normalizedPayload['bridge_id_reported']
    : 'unknown';

// bridge_id_reported es VARCHAR(50) y también se usa como external_id del
// bridge en devices: truncar evita un "Data too long" (22001) → 500.
$bridgeId = truncateForColumn($bridgeId, 50);

$bridgeName = (string) $normalizedPayload['bridge_name'];
$logText = (string) $normalizedPayload['raw_log_text'];
$lineCount = countNormalizedPayloadItems($normalizedPayload, $logText);

$rawRelativePath = buildRawRelativePath($receivedAt, $bridgeId);
$rawAbsolutePath = Paths::for($rawRelativePath);

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

    $deviceResolver = new DeviceResolver($pdo);
    $bridgeDeviceId = $deviceResolver->resolveOrCreateBridge(
        bridgeIdReported: $bridgeId,
        bridgeName: $bridgeName,
        seenAt: $receivedAt,
        mac: $normalizedPayload['source_mac'] !== '' ? $normalizedPayload['source_mac'] : null,
        firmwareVersion: $normalizedPayload['firmware_version'] !== '' ? $normalizedPayload['firmware_version'] : null,
        serialNumber: $normalizedPayload['serial_number'] !== '' ? $normalizedPayload['serial_number'] : null,
    );
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
        'received_at' => $receivedAtSql,
        'remote_addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'source_type' => truncateForColumn((string) $normalizedPayload['source_type'], 30),
        'source_device_id' => $bridgeDeviceId,
        'bridge_id_reported' => $bridgeId,
        'content_hash' => $contentHash,
        'raw_path' => $rawRelativePath,
        'payload_summary' => jsonForDb($normalizedPayload['payload_summary']),
        'status' => 'received',
        'line_count' => $lineCount,
    ]);

    $ingestId = (int) $pdo->lastInsertId();

    $processingStartedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $pdo->prepare(
        'UPDATE log_ingests
SET status = :status,
processing_started_at = :started_at
WHERE id = :id'
    )->execute([
        'status' => 'parsing',
        'started_at' => $processingStartedAt,
        'id' => $ingestId,
    ]);

    $parseResult = processNormalizedPayload(
        normalizedPayload: $normalizedPayload,
        logText: $logText,
        ingestId: $ingestId,
        bridgeDeviceId: $bridgeDeviceId,
        receivedAt: $receivedAt,
        deviceResolver: $deviceResolver,
    );

    $pdo->beginTransaction();

    try {
        $eventWriter = new LogEventWriter($pdo);
        $insertedEvents = $eventWriter->insertEvents($parseResult['events']);

        $processingFinishedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $pdo->prepare(
            'UPDATE log_ingests
SET status = :status,
parsed_ok_count = :ok_count,
parsed_error_count = :err_count,
processing_finished_at = :finished_at
WHERE id = :id'
        )->execute([
            'status' => 'processed',
            'ok_count' => $parseResult['parsed_ok'],
            'err_count' => $parseResult['parsed_error'],
            'finished_at' => $processingFinishedAt,
            'id' => $ingestId,
        ]);

        $pdo->commit();
    } catch (Throwable $eventInsertError) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $pdo->prepare(
            'UPDATE log_ingests
SET status = :status,
processing_finished_at = :finished_at
WHERE id = :id'
        )->execute([
            'status' => 'error',
            'finished_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'id' => $ingestId,
        ]);

        throw $eventInsertError;
    }

    JsonResponse::send([
        'ok' => true,
        'duplicate' => false,
        'message' => 'Payload received and processed.',
        'ingest_id' => $ingestId,
        'bridge_id' => $bridgeId,
        'bridge_device_id' => $bridgeDeviceId,
        'processing_mode' => $normalizedPayload['processing_mode'],
        'line_count' => $lineCount,
        'parsed_ok' => $parseResult['parsed_ok'],
        'parsed_error' => $parseResult['parsed_error'],
        'events_attempted' => count($parseResult['events']),
        'events_inserted' => $insertedEvents,
        'raw_path' => $rawRelativePath,
        'content_hash' => $contentHash,
    ]);
} catch (Throwable $exception) {
    // Capa 3: NUNCA devolver 5xx por el CONTENIDO de los logs. Si el procesado
    // falla (DB, parseo, etc.) registramos el error y archivamos el cuerpo, pero
    // respondemos 200 para que el bridge avance su offset y no se atasque.
    ackDiscarded(
        $writer,
        $receivedAt,
        'ingest_failed',
        $exception->getMessage(),
        $rawBody
    );
}

function processNormalizedPayload(
    array $normalizedPayload,
    string $logText,
    int $ingestId,
    int $bridgeDeviceId,
    DateTimeImmutable $receivedAt,
    DeviceResolver $deviceResolver
): array {
    if ($normalizedPayload['processing_mode'] === PayloadNormalizer::MODE_STRUCTURED_EVENTS) {
        $normalizer = new StructuredEventNormalizer();

        return $normalizer->normalizeEvents(
            events: $normalizedPayload['events'],
            ingestId: $ingestId,
            bridgeDeviceId: $bridgeDeviceId,
            receivedAt: $receivedAt,
            payloadContext: [
                'source' => $normalizedPayload['source'],
                'upload' => $normalizedPayload['upload'],
            ],
            resolveDeviceId: buildBeaconResolver($deviceResolver, $bridgeDeviceId),
        );
    }

    if ($normalizedPayload['processing_mode'] === PayloadNormalizer::MODE_VEHICLE_EVENTS) {
        $normalizer = new VehicleEventNormalizer();

        return $normalizer->normalizeEvents(
            vehicleEvents: $normalizedPayload['vehicle_events'],
            ingestId: $ingestId,
            bridgeDeviceId: $bridgeDeviceId,
            receivedAt: $receivedAt,
            payloadContext: [
                'source' => $normalizedPayload['source'],
                'upload' => $normalizedPayload['upload'],
            ],
            resolveDeviceId: buildBeaconResolver($deviceResolver, $bridgeDeviceId),
        );
    }

    $logParser = new LogParser($deviceResolver, new SeverityDeriver());

    return $logParser->processIngest(
        logText: $logText,
        ingestId: $ingestId,
        bridgeDeviceId: $bridgeDeviceId,
        receivedAt: $receivedAt,
    );
}

function buildBeaconResolver(DeviceResolver $deviceResolver, int $bridgeDeviceId): callable
{
    $beaconCache = [];

    return static function (
        string $mac,
        ?string $externalId,
        ?string $name,
        DateTimeImmutable $seenAt
    ) use ($deviceResolver, $bridgeDeviceId, &$beaconCache): int {
        $cacheKey = strtolower($mac);

        if (isset($beaconCache[$cacheKey])) {
            return $beaconCache[$cacheKey];
        }

        $deviceId = $deviceResolver->resolveOrCreateBeacon(
            mac: strtolower($mac),
            externalId: $externalId,
            name: $name,
            bridgeDeviceId: $bridgeDeviceId,
            seenAt: $seenAt,
        );

        $beaconCache[$cacheKey] = $deviceId;

        return $deviceId;
    };
}

function countNormalizedPayloadItems(array $normalizedPayload, string $logText): int
{
    return match ($normalizedPayload['processing_mode']) {
        PayloadNormalizer::MODE_STRUCTURED_EVENTS => count($normalizedPayload['events']),
        PayloadNormalizer::MODE_VEHICLE_EVENTS => count($normalizedPayload['vehicle_events']),
        PayloadNormalizer::MODE_LEGACY_LOG_TEXT => countLogLines($logText),
        default => 0,
    };
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

function writeRejectedRequest(
    NdjsonWriter $writer,
    DateTimeImmutable $receivedAt,
    array $error,
    ?string $rawBody
): void {
    $relativePath = buildRejectedRelativePath($receivedAt);
    $absolutePath = Paths::for($relativePath);

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

function jsonForDb(mixed $value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return $encoded !== false ? $encoded : 'null';
}

function truncateForColumn(string $value, int $maxLength): string
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8') <= $maxLength
            ? $value
            : (string) mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    // Fallback por bytes: puede cortar un carácter multibyte, pero nunca excede
    // la longitud de columna.
    return strlen($value) <= $maxLength ? $value : substr($value, 0, $maxLength);
}

// -----------------------------------------------------------------------------
// sanitizeUtf8
// -----------------------------------------------------------------------------
// Elimina/sustituye secuencias UTF-8 inválidas del cuerpo recibido. Los bridges
// escriben logText con bytes potencialmente corruptos; un solo byte inválido
// invalida el JSON y rompe el INSERT en base de datos. Lo limpiamos antes de
// cualquier otro procesado.
// -----------------------------------------------------------------------------
function sanitizeUtf8(string $raw): string
{
    if ($raw === '') {
        return '';
    }

    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8');

        if (is_string($converted)) {
            return $converted;
        }
    }

    if (function_exists('iconv')) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $raw);

        if (is_string($clean)) {
            return $clean;
        }
    }

    return $raw;
}

// -----------------------------------------------------------------------------
// ackDiscarded
// -----------------------------------------------------------------------------
// Reconoce un lote como recibido (HTTP 200) aunque su contenido se descarte.
//
// Principio rector: el bridge solo avanza su uploaded_offset ante una respuesta
// 2xx. Cualquier 4xx/5xx por CONTENIDO lo deja reintentando el mismo bloque
// indefinidamente y atasca toda la subida. Por eso, ante contenido inválido,
// respondemos 200, registramos el motivo en el log del servidor y archivamos el
// cuerpo en rejected/ para poder depurarlo después.
// -----------------------------------------------------------------------------
function ackDiscarded(
    NdjsonWriter $writer,
    DateTimeImmutable $receivedAt,
    string $reason,
    string $message,
    ?string $rawBody
): never {
    writeRejectedRequest(
        $writer,
        $receivedAt,
        [
            'status' => 200,
            'code' => $reason,
            'message' => $message,
        ],
        $rawBody
    );

    error_log('[log-ingest] discarded ' . $reason . ': ' . $message);

    JsonResponse::send([
        'ok' => true,
        'discarded' => true,
        'reason' => $reason,
        'message' => $message,
    ], 200);
}

function applyIngestRateLimit(): void
{
    if (!ingestRateLimitEnabled()) {
        return;
    }

    $result = RateLimiter::hit(
        storageDir: Paths::for('rate-limit'),
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
    $raw = Env::get('INGEST_RATE_LIMIT_ENABLED');

    if ($raw === null || trim((string) $raw) === '') {
        return true;
    }

    return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
}

function ingestRateLimitEnvInt(string $key, int $default): int
{
    $raw = Env::get($key);

    if ($raw === null || trim((string) $raw) === '') {
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
