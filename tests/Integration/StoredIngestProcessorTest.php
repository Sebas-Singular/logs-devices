<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Connection;
use App\Ingest\StoredIngestProcessor;
use PDO;
use PHPUnit\Framework\TestCase;

// =============================================================================
// StoredIngestProcessorTest — Tests de integración (necesitan MariaDB)
//
// Usa content_hash con prefijo 'test_zombie_' para identificar y limpiar
// los ingests de test sin tocar datos reales.
// =============================================================================

final class StoredIngestProcessorTest extends TestCase
{
    private PDO $pdo;
    private StoredIngestProcessor $processor;

    protected function setUp(): void
    {
        $this->pdo       = Connection::make();
        $this->processor = new StoredIngestProcessor($this->pdo);

        $this->cleanupTestIngests();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestIngests();
    }

    // =========================================================================
    // countZombies
    // =========================================================================

    public function testCountZombies_noZombies_returnsZero(): void
    {
        $count = $this->processor->countZombies();

        $this->assertSame(0, $count);
    }

    public function testCountZombies_withFreshParsingIngest_returnsZero(): void
    {
        $this->insertTestIngest('parsing', date('Y-m-d H:i:s'));

        $count = $this->processor->countZombies();

        $this->assertSame(0, $count);
    }

    public function testCountZombies_withOldParsingIngest_returnsOne(): void
    {
        $oldTime = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $this->insertTestIngest('parsing', $oldTime);

        $count = $this->processor->countZombies();

        $this->assertGreaterThanOrEqual(1, $count);
    }

    // =========================================================================
    // rescueZombies
    // =========================================================================

    public function testRescueZombies_noZombies_returnsZero(): void
    {
        $rescued = $this->processor->rescueZombies();

        $this->assertSame(0, $rescued);
    }

    public function testRescueZombies_withFreshParsingIngest_doesNotRescue(): void
    {
        $this->insertTestIngest('parsing', date('Y-m-d H:i:s'));

        $rescued = $this->processor->rescueZombies();

        $this->assertSame(0, $rescued);
    }

    public function testRescueZombies_withOldParsingIngest_rescuesIt(): void
    {
        $oldTime = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $this->insertTestIngest('parsing', $oldTime);

        $rescued = $this->processor->rescueZombies();

        $this->assertGreaterThanOrEqual(1, $rescued);
    }

    public function testRescueZombies_rescuedIngest_statusBecomesReceived(): void
    {
        $oldTime = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $hash    = $this->insertTestIngest('parsing', $oldTime);

        $this->processor->rescueZombies();

        $stmt = $this->pdo->prepare(
            'SELECT status, processing_started_at
               FROM log_ingests
              WHERE content_hash = :hash
              LIMIT 1'
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row);
        $this->assertSame('received', $row['status']);
        $this->assertNull($row['processing_started_at']);
    }

    // =========================================================================
    // processBatch
    // =========================================================================

    public function testProcessBatch_summaryContainsRescuedZombiesKey(): void
    {
        $summary = $this->processor->processBatch(limit: 1);

        $this->assertArrayHasKey('rescued_zombies', $summary);
        $this->assertIsInt($summary['rescued_zombies']);
    }

    public function testProcessBatch_rescuesZombiesBeforeProcessing(): void
    {
        $oldTime = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $this->insertTestIngest('parsing', $oldTime);

        // El batch no va a poder procesar el ingest (no tiene NDJSON real),
        // pero sí debe rescatarlo de 'parsing' a 'received' antes de intentarlo.
        $summary = $this->processor->processBatch(limit: 1);

        $this->assertGreaterThanOrEqual(1, $summary['rescued_zombies']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Inserta un ingest de test y devuelve su content_hash.
     */
    private function insertTestIngest(string $status, ?string $processingStartedAt): string
    {
        $hash = 'test_zombie_' . bin2hex(random_bytes(8));

        $stmt = $this->pdo->prepare(
            'INSERT INTO log_ingests (
                received_at, remote_addr, user_agent,
                source_type, source_device_id, bridge_id_reported,
                content_hash, raw_path, payload_summary,
                status, line_count, parsed_ok_count, parsed_error_count,
                processing_started_at, processing_finished_at
            ) VALUES (
                NOW(), :remote_addr, :user_agent,
                :source_type, NULL, :bridge_id,
                :content_hash, :raw_path, NULL,
                :status, 0, 0, 0,
                :processing_started_at, NULL
            )'
        );

        $stmt->execute([
            'remote_addr'           => '127.0.0.1',
            'user_agent'            => 'test',
            'source_type'           => 'test',
            'bridge_id'             => 'test_bridge_zombie',
            'content_hash'          => $hash,
            'raw_path'              => 'raw/test/zombie_' . $hash . '.ndjson',
            'status'                => $status,
            'processing_started_at' => $processingStartedAt,
        ]);

        return $hash;
    }

    private function cleanupTestIngests(): void
    {
        $this->pdo->exec(
            "DELETE FROM log_ingests WHERE content_hash LIKE 'test_zombie_%'"
        );
    }
}
