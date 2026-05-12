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
                dirname(__DIR__, 4) . '/private/storage'
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

            $bridgeId = trim((string) ($payload['bridgeId'] ?? $ingest['bridge_id_reported'] ?? ''));
            $bridgeName = trim((string) ($payload['bridgeName'] ?? ''));
            $logText = (string) ($payload['logText'] ?? '');

            if ($bridgeId === '') {
                throw new RuntimeException('Payload does not contain bridgeId.');
            }

            if (trim($logText) === '') {
                throw new RuntimeException('Payload does not contain logText.');
            }

            $receivedAt = new DateTimeImmutable((string) $ingest['received_at']);
            $lineCount = count(BaseLineParser::splitRawLines($logText));

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

            $logParser = new LogParser(
                $deviceResolver,
                new SeverityDeriver(),
            );

            $parseResult = $logParser->processIngest(
                logText: $logText,
                ingestId: $ingestId,
                bridgeDeviceId: $bridgeDeviceId,
                receivedAt: $receivedAt,
            );

            $eventWriter = new LogEventWriter($this->pdo);
            $insertedEvents = $eventWriter->insertEvents($parseResult['events']);

            $this->markProcessed(
                ingestId: $ingestId,
                bridgeDeviceId: $bridgeDeviceId,
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
        int $lineCount,
        int $parsedOk,
        int $parsedError
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE log_ingests
                SET status                 = :status,
                    source_device_id       = :source_device_id,
                    line_count             = :line_count,
                    parsed_ok_count        = :parsed_ok_count,
                    parsed_error_count     = :parsed_error_count,
                    processing_finished_at = :finished_at
              WHERE id = :id'
        );

        $stmt->execute([
            'status'             => 'processed',
            'source_device_id'   => $bridgeDeviceId,
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
}