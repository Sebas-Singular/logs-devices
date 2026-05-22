<?php

declare(strict_types=1);

namespace Tests\Ingest;

use App\Database\Connection;
use App\Ingest\LogEventWriter;
use PDO;
use PHPUnit\Framework\TestCase;

final class LogEventWriterTest extends TestCase
{
    private PDO $pdo;
    private LogEventWriter $writer;
    private string $token;
    private array $ingestIds = [];

    protected function setUp(): void
    {
        $this->pdo = Connection::make();
        $this->writer = new LogEventWriter($this->pdo);
        $this->token = 'test_log_event_writer_' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach ($this->ingestIds as $ingestId) {
            $stmt = $this->pdo->prepare('DELETE FROM log_ingests WHERE id = :id');
            $stmt->execute(['id' => $ingestId]);
        }
    }

    public function testInsertEvents_insertsMultipleEventsInOneCall(): void
    {
        $ingestId = $this->createIngest();

        $inserted = $this->writer->insertEvents([
            $this->eventRow($ingestId, 1),
            $this->eventRow($ingestId, 2),
            $this->eventRow($ingestId, 3),
        ]);

        $this->assertSame(3, $inserted);
        $this->assertSame(3, $this->countEventsForIngest($ingestId));
    }

    public function testInsertEvents_ignoresDuplicateEventHash(): void
    {
        $ingestId = $this->createIngest();
        $event = $this->eventRow($ingestId, 1);

        $firstInsert = $this->writer->insertEvents([$event]);
        $secondInsert = $this->writer->insertEvents([$event]);

        $this->assertSame(1, $firstInsert);
        $this->assertSame(0, $secondInsert);
        $this->assertSame(1, $this->countEventsForIngest($ingestId));
    }

    public function testInsertEvents_chunksLargeBatches(): void
    {
        $ingestId = $this->createIngest();
        $events = [];

        for ($i = 1; $i <= 105; $i++) {
            $events[] = $this->eventRow($ingestId, $i);
        }

        $inserted = $this->writer->insertEvents($events);

        $this->assertSame(105, $inserted);
        $this->assertSame(105, $this->countEventsForIngest($ingestId));
    }

    private function createIngest(): int
    {
        $stmt = $this->pdo->prepare(
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
                parsed_error_count
            ) VALUES (
                :received_at,
                :remote_addr,
                :user_agent,
                :source_type,
                NULL,
                :bridge_id_reported,
                :content_hash,
                :raw_path,
                :payload_summary,
                :status,
                0,
                0,
                0
            )'
        );

        $stmt->execute([
            'received_at' => '2026-05-14 12:00:00',
            'remote_addr' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'source_type' => 'bridge',
            'bridge_id_reported' => 'test-bridge',
            'content_hash' => hash('sha256', $this->token . random_bytes(8)),
            'raw_path' => 'raw/test/' . $this->token . '.ndjson',
            'payload_summary' => json_encode(['test' => $this->token], JSON_THROW_ON_ERROR),
            'status' => 'processed',
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->ingestIds[] = $id;

        return $id;
    }

    private function eventRow(int $ingestId, int $n): array
    {
        return [
            'ingest_id' => $ingestId,
            'device_id' => null,
            'bridge_device_id' => null,
            'event_timestamp' => '2026-05-14 12:00:' . str_pad((string) ($n % 60), 2, '0', STR_PAD_LEFT),
            'received_at' => '2026-05-14 12:01:00',
            'severity' => 'info',
            'severity_origin' => 'derived',
            'event_type' => 'telemetry',
            'event_category' => 'sensor',
            'device_mac_raw' => 'ff:ff:ff:00:00:' . str_pad((string) ($n % 100), 2, '0', STR_PAD_LEFT),
            'message_text' => 'PHPUnit log event writer test ' . $n,
            'measurements' => json_encode(['temperature_c' => 20 + $n], JSON_THROW_ON_ERROR),
            'context' => json_encode(['test' => true, 'n' => $n], JSON_THROW_ON_ERROR),
            'parse_ok' => 1,
            'parse_error' => null,
            'quality_status' => 'valid',
            'anomaly_flags' => json_encode([], JSON_THROW_ON_ERROR),
            'event_hash' => hash('sha256', $this->token . '-event-' . $n),
            'created_at' => '2026-05-14 12:01:00',
        ];
    }

    private function countEventsForIngest(int $ingestId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM log_events WHERE ingest_id = :ingest_id');
        $stmt->execute(['ingest_id' => $ingestId]);

        return (int) $stmt->fetchColumn();
    }
}
