<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Device\DeviceResolver;
use App\Parsers\BaseLineParser;
use App\Parsers\SeverityDeriver;
use App\Support\Env;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class StoredIngestProcessor
{
    private readonly string $storageBasePath;

    public function __construct(
        private readonly PDO $pdo,
        ?string $storageBasePath = null
    ) {
        $this->storageBasePath = rtrim(
            $storageBasePath ?? (string) Env::get(
                'STORAGE_BASE_PATH',
                dirname(__DIR__, 2) . '/storage'
            ),
            '/\\'
        );
    }

    public function listCandidates(int $limit = 50, bool $retryErrors = false): array
    {
        $limit = max(1, min($limit, 500));

        $where = $retryErrors
            ? "status IN ('received', 'error')"
            : "status = 'received'";

        $stmt = $this->pdo->prepare(
            "SELECT id, received_at, bridge_id_reported, content_hash, raw_path, status, line_count
               FROM log_ingests
              WHERE {$where}
              ORDER BY id ASC
              LIMIT :limit"
        );

        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countCandidates(bool $retryErrors = false): int
    {
        $where = $retryErrors
            ? "status IN ('received', 'error')"
            : "status = 'received'";

        $stmt = $this->pdo->query(
            "SELECT COUNT(*) AS total
            FROM log_ingests
            WHERE {$where}"
        );

        return (int) $stmt->fetchColumn();
    }

    public function getIngest(int $ingestId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, received_at, source_device_id, bridge_id_reported,
                    content_hash, raw_path, status, line_count
               FROM log_ingests
              WHERE id = :id
              LIMIT 1'
        );

        $stmt->execute([
            'id' => $ingestId,
        ]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function processBatch(int $limit = 50, bool $retryErrors = false): array
    {
        $candidates = $this->listCandidates($limit, $retryErrors);

        $summary = [
            'total_candidates' => count($candidates),
            'processed'        => 0,
            'failed'           => 0,
            'skipped'          => 0,
            'results'          => [],
        ];

        foreach ($candidates as $candidate) {
            $result = $this->processOne(
                ingestId: (int) $candidate['id'],
                force: false,
                retryErrors: $retryErrors,
            );

            $summary['results'][] = $result;

            match ($result['outcome']) {
                'processed' => $summary['processed']++,
                'error'     => $summary['failed']++,
                'skipped'   => $summary['skipped']++,
                default     => null,
            };
        }

        return $summary;
    }

    public function processOne(
        int $ingestId,
        bool $force = false,
        bool $retryErrors = false
    ): array {
        $ingest = null;

        try {
            $ingest = $this->getIngest($ingestId);

            if ($ingest === null) {
                return [
                    'id'      => $ingestId,
                    'outcome' => 'error',
                    'error'   => 'Ingest not found.',
                ];
            }

            $status = (string) $ingest['status'];

            if (!$this->canProcessStatus($status, $force, $retryErrors)) {
                return [
                    'id'      => $ingestId,
                    'outcome' => 'skipped',
                    'status'  => $status,
                    'reason'  => 'Status is not eligible for reprocess.',
                ];
            }

            $payload = $this->findPayloadForIngest($ingest);

            $payloadNormalizer = new PayloadNormalizer();
            $normalizedPayload = $payloadNormalizer->normalize($payload);

            $bridgeId = (string) ($normalizedPayload['bridge_id_reported'] ?? '');

            if ($bridgeId === '') {
                $bridgeId = trim((string) ($ingest['bridge_id_reported'] ?? ''));
            }

            $bridgeName = (string) ($normalizedPayload['bridge_name'] ?? '');
            $logText = (string) ($normalizedPayload['raw_log_text'] ?? '');
            $processingMode = (string) ($normalizedPayload['processing_mode'] ?? PayloadNormalizer::MODE_EMPTY);

            if ($bridgeId === '') {
                throw new RuntimeException('Payload does not contain bridgeId or source.deviceId.');
            }

            if ($processingMode === PayloadNormalizer::MODE_EMPTY) {
                throw new RuntimeException('Payload does not contain logText, raw.logText, events[], or vehicleEvents[].');
            }

            $receivedAt = new DateTimeImmutable((string) $ingest['received_at']);
            $lineCount = $this->countNormalizedPayloadItems($normalizedPayload, $logText);

            $this->pdo->beginTransaction();

            $this->markParsing($ingestId);

            if ($force) {
                $this->deleteExistingEvents($ingestId);
            }

            $deviceResolver = new DeviceResolver($this->pdo);

            $bridgeDeviceId = $deviceResolver->resolveOrCreateBridge(
                bridgeIdReported: $bridgeId,
                bridgeName: $bridgeName,
                seenAt: $receivedAt,
            );

            $parseResult = $this->processNormalizedPayload(
                normalizedPayload: $normalizedPayload,
                logText: $logText,
                ingestId: $ingestId,
                bridgeDeviceId: $bridgeDeviceId,
                receivedAt: $receivedAt,
                deviceResolver: $deviceResolver,
            );

            $eventWriter = new LogEventWriter($this->pdo);
            $insertedEvents = $eventWriter->insertEvents($parseResult['events']);

            $this->markProcessed(
                ingestId: $ingestId,
                bridgeDeviceId: $bridgeDeviceId,
                bridgeId: $bridgeId,
                sourceType: $this->truncateForColumn((string) ($normalizedPayload['source_type'] ?? 'walkerpisa_bridge'), 30),
                payloadSummary: $normalizedPayload['payload_summary'] ?? [],
                lineCount: $lineCount,
                parsedOk: (int) $parseResult['parsed_ok'],
                parsedError: (int) $parseResult['parsed_error'],
            );

            $this->pdo->commit();

            return [
                'id'               => $ingestId,
                'outcome'          => 'processed',
                'bridge_id'        => $bridgeId,
                'bridge_device_id' => $bridgeDeviceId,
                'processing_mode'  => $processingMode,
                'line_count'       => $lineCount,
                'parsed_ok'        => (int) $parseResult['parsed_ok'],
                'parsed_error'     => (int) $parseResult['parsed_error'],
                'events_attempted' => count($parseResult['events']),
                'events_inserted'  => $insertedEvents,
                'forced'           => $force,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if (is_array($ingest) && isset($ingest['id'])) {
                $this->markError((int) $ingest['id']);
            }

            return [
                'id'      => $ingestId,
                'outcome' => 'error',
                'error'   => $exception->getMessage(),
            ];
        }
    }

    private function canProcessStatus(string $status, bool $force, bool $retryErrors): bool
    {
        if ($force) {
            return true;
        }

        if ($status === 'received') {
            return true;
        }

        return $retryErrors && $status === 'error';
    }

    private function processNormalizedPayload(
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
                resolveDeviceId: $this->buildBeaconResolver($deviceResolver, $bridgeDeviceId),
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
                resolveDeviceId: $this->buildBeaconResolver($deviceResolver, $bridgeDeviceId),
            );
        }

        $logParser = new LogParser(
            $deviceResolver,
            new SeverityDeriver(),
        );

        return $logParser->processIngest(
            logText: $logText,
            ingestId: $ingestId,
            bridgeDeviceId: $bridgeDeviceId,
            receivedAt: $receivedAt,
        );
    }

    private function buildBeaconResolver(DeviceResolver $deviceResolver, int $bridgeDeviceId): callable
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

    private function countNormalizedPayloadItems(array $normalizedPayload, string $logText): int
    {
        return match ($normalizedPayload['processing_mode']) {
            PayloadNormalizer::MODE_STRUCTURED_EVENTS => count($normalizedPayload['events']),
            PayloadNormalizer::MODE_VEHICLE_EVENTS => count($normalizedPayload['vehicle_events']),
            PayloadNormalizer::MODE_LEGACY_LOG_TEXT => count(BaseLineParser::splitRawLines($logText)),
            default => 0,
        };
    }

    private function findPayloadForIngest(array $ingest): array
    {
        $rawPath = (string) $ingest['raw_path'];
        $contentHash = (string) $ingest['content_hash'];

        $absolutePath = $this->absoluteRawPath($rawPath);

        if (!is_file($absolutePath)) {
            throw new RuntimeException("Raw NDJSON file not found: {$rawPath}");
        }

        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open raw NDJSON file: {$rawPath}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);

                if (!is_array($record)) {
                    continue;
                }

                if (($record['content_hash'] ?? null) !== $contentHash) {
                    continue;
                }

                $payload = $record['payload'] ?? null;

                if (!is_array($payload)) {
                    throw new RuntimeException("Raw NDJSON record has no payload object: {$rawPath}");
                }

                return $payload;
            }
        } finally {
            fclose($handle);
        }

        throw new RuntimeException("Payload content_hash not found in raw NDJSON file: {$rawPath}");
    }

    private function absoluteRawPath(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        $normalized = ltrim($normalized, '/');

        if ($normalized === '' || str_contains($normalized, '..')) {
            throw new RuntimeException('Unsafe raw_path value.');
        }

        return $this->storageBasePath . '/' . $normalized;
    }

    private function markParsing(int $ingestId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE log_ingests
                SET status                 = :status,
                    processing_started_at  = :started_at,
                    processing_finished_at = NULL
              WHERE id = :id'
        );

        $stmt->execute([
            'status'     => 'parsing',
            'started_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'id'         => $ingestId,
        ]);
    }

    private function markProcessed(
        int $ingestId,
        int $bridgeDeviceId,
        string $bridgeId,
        string $sourceType,
        array $payloadSummary,
        int $lineCount,
        int $parsedOk,
        int $parsedError
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE log_ingests
                SET status                 = :status,
                    source_type            = :source_type,
                    source_device_id       = :source_device_id,
                    bridge_id_reported     = :bridge_id_reported,
                    payload_summary        = :payload_summary,
                    line_count             = :line_count,
                    parsed_ok_count        = :parsed_ok_count,
                    parsed_error_count     = :parsed_error_count,
                    processing_finished_at = :finished_at
              WHERE id = :id'
        );

        $stmt->execute([
            'status'             => 'processed',
            'source_type'        => $sourceType,
            'source_device_id'   => $bridgeDeviceId,
            'bridge_id_reported' => $bridgeId,
            'payload_summary'    => $this->jsonForDb($payloadSummary),
            'line_count'         => $lineCount,
            'parsed_ok_count'    => $parsedOk,
            'parsed_error_count' => $parsedError,
            'finished_at'        => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'id'                 => $ingestId,
        ]);
    }

    private function markError(int $ingestId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE log_ingests
                SET status                 = :status,
                    processing_finished_at = :finished_at
              WHERE id = :id'
        );

        $stmt->execute([
            'status'      => 'error',
            'finished_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'id'          => $ingestId,
        ]);
    }

    private function deleteExistingEvents(int $ingestId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM log_events
              WHERE ingest_id = :ingest_id'
        );

        $stmt->execute([
            'ingest_id' => $ingestId,
        ]);
    }

    private function jsonForDb(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded !== false ? $encoded : 'null';
    }

    private function truncateForColumn(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength);
    }
}
