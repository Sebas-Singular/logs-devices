<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Connection;
use App\Device\DeviceResolver;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

// =============================================================================
// DeviceResolverTest — Tests de integración (necesitan MariaDB)
// =============================================================================
//
// Estos tests hablan con la BD real del Docker. Cada test limpia los
// dispositivos de test que crea, usando un prefijo de external_id reconocible:
// 'test_bridge_*' y MACs del rango 'ff:ff:ff:*' (no aparecen en datos reales).
// =============================================================================

final class DeviceResolverTest extends TestCase
{
    private PDO $pdo;
    private DeviceResolver $resolver;

    private const TEST_MAC_1 = 'ff:ff:ff:00:00:01';
    private const TEST_MAC_2 = 'ff:ff:ff:00:00:02';
    private const TEST_BRIDGE_ID = 'test_bridge_999';

    protected function setUp(): void
    {
        $this->pdo      = Connection::make();
        $this->resolver = new DeviceResolver($this->pdo);

        $this->cleanupTestDevices();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestDevices();
    }

    // =========================================================================
    // Tests de bridges
    // =========================================================================

    public function testResolveOrCreateBridge_newBridge_createsAndReturnsId(): void
    {
        $seenAt = new DateTimeImmutable('2026-05-01 10:00:00');

        $id = $this->resolver->resolveOrCreateBridge(
            bridgeIdReported: self::TEST_BRIDGE_ID,
            bridgeName: 'Test Bridge',
            seenAt: $seenAt
        );

        $this->assertGreaterThan(0, $id);

        $row = $this->fetchDevice($id);
        $this->assertNotNull($row);
        $this->assertSame('bridge', $row['device_kind']);
        $this->assertSame(self::TEST_BRIDGE_ID, $row['external_id']);
        $this->assertSame('Test Bridge', $row['name']);
        $this->assertNull($row['mac_address']);
        $this->assertNull($row['parent_device_id']);
    }

    public function testResolveOrCreateBridge_existingBridge_returnsSameId(): void
    {
        $seenAt1 = new DateTimeImmutable('2026-05-01 10:00:00');
        $seenAt2 = new DateTimeImmutable('2026-05-01 11:00:00');

        $id1 = $this->resolver->resolveOrCreateBridge(self::TEST_BRIDGE_ID, 'Test Bridge', $seenAt1);
        $id2 = $this->resolver->resolveOrCreateBridge(self::TEST_BRIDGE_ID, 'Test Bridge', $seenAt2);

        $this->assertSame($id1, $id2);

        $count = $this->countDevices('bridge', self::TEST_BRIDGE_ID);
        $this->assertSame(1, $count);
    }

    public function testResolveOrCreateBridge_updatesLastSeenAt(): void
    {
        $seenAt1 = new DateTimeImmutable('2026-05-01 10:00:00');
        $seenAt2 = new DateTimeImmutable('2026-05-01 11:00:00');

        $id = $this->resolver->resolveOrCreateBridge(self::TEST_BRIDGE_ID, 'Test Bridge', $seenAt1);
        $this->resolver->resolveOrCreateBridge(self::TEST_BRIDGE_ID, 'Test Bridge', $seenAt2);

        $row = $this->fetchDevice($id);
        $this->assertSame('2026-05-01 11:00:00', $row['last_seen_at']);
        $this->assertSame('2026-05-01 10:00:00', $row['first_seen_at']);
    }

    // =========================================================================
    // Tests de balizas
    // =========================================================================

    public function testResolveOrCreateBeacon_newBeacon_createsAndReturnsId(): void
    {
        $seenAt   = new DateTimeImmutable('2026-05-01 10:00:00');
        $bridgeId = $this->resolver->resolveOrCreateBridge(self::TEST_BRIDGE_ID, 'Test Bridge', $seenAt);

        $beaconId = $this->resolver->resolveOrCreateBeacon(
            mac: self::TEST_MAC_1,
            externalId: '01',
            name: 'Baliza 01 - Test',
            bridgeDeviceId: $bridgeId,
            seenAt: $seenAt
        );

        $this->assertGreaterThan(0, $beaconId);

        $row = $this->fetchDevice($beaconId);
        $this->assertSame('baliza', $row['device_kind']);
        $this->assertSame(self::TEST_MAC_1, $row['mac_address']);
        $this->assertSame('01', $row['external_id']);
        $this->assertSame('Baliza 01 - Test', $row['name']);
        $this->assertSame($bridgeId, (int) $row['parent_device_id']);
    }

    public function testResolveOrCreateBeacon_existingByMac_returnsSameId(): void
    {
        $seenAt   = new DateTimeImmutable('2026-05-01 10:00:00');
        $bridgeId = $this->resolver->resolveOrCreateBridge(self::TEST_BRIDGE_ID, 'Test Bridge', $seenAt);

        $id1 = $this->resolver->resolveOrCreateBeacon(self::TEST_MAC_1, '01', 'Baliza Test', $bridgeId, $seenAt);
        $id2 = $this->resolver->resolveOrCreateBeacon(self::TEST_MAC_1, '01', 'Baliza Test', $bridgeId, $seenAt);

        $this->assertSame($id1, $id2);
    }

    public function testResolveOrCreateBeacon_updatesNameIfReportedAndChanged(): void
    {
        $seenAt   = new DateTimeImmutable('2026-05-01 10:00:00');
        $bridgeId = $this->resolver->resolveOrCreateBridge(self::TEST_BRIDGE_ID, 'Test Bridge', $seenAt);

        $id = $this->resolver->resolveOrCreateBeacon(self::TEST_MAC_1, '01', 'Nombre Antiguo', $bridgeId, $seenAt);
        $this->resolver->resolveOrCreateBeacon(self::TEST_MAC_1, '01', 'Nombre Nuevo', $bridgeId, $seenAt);

        $row = $this->fetchDevice($id);
        $this->assertSame('Nombre Nuevo', $row['name']);
    }

    public function testResolveOrCreateBeacon_doesNotUpdateNameIfManual(): void
    {
        $seenAt   = new DateTimeImmutable('2026-05-01 10:00:00');
        $bridgeId = $this->resolver->resolveOrCreateBridge(self::TEST_BRIDGE_ID, 'Test Bridge', $seenAt);

        $id = $this->resolver->resolveOrCreateBeacon(self::TEST_MAC_1, '01', 'Nombre Original', $bridgeId, $seenAt);

        $this->pdo->exec("UPDATE devices SET name = 'Nombre Manual', name_origin = 'manual' WHERE id = {$id}");

        $this->resolver->resolveOrCreateBeacon(self::TEST_MAC_1, '01', 'Nombre del Firmware', $bridgeId, $seenAt);

        $row = $this->fetchDevice($id);
        $this->assertSame('Nombre Manual', $row['name']);
    }

    public function testResolveOrCreateBeacon_findsExistingByExternalIdIfNoMac(): void
    {
        $seenAt   = new DateTimeImmutable('2026-05-01 10:00:00');
        $bridgeId = $this->resolver->resolveOrCreateBridge(self::TEST_BRIDGE_ID, 'Test Bridge', $seenAt);

        $id1 = $this->resolver->resolveOrCreateBeacon(self::TEST_MAC_2, '07', 'Baliza 07', $bridgeId, $seenAt);

        $id2 = $this->resolver->resolveOrCreateBeacon(self::TEST_MAC_2, '7', 'Baliza 07', $bridgeId, $seenAt);

        $this->assertSame($id1, $id2);
    }

    // =========================================================================
    // Helpers privados
    // =========================================================================

    private function fetchDevice(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, device_kind, mac_address, external_id, name,
                name_origin, parent_device_id, first_seen_at, last_seen_at,
                metadata
           FROM devices
          WHERE id = :id
          LIMIT 1'
        );

        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    private function countDevices(string $kind, string $externalId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM devices
              WHERE device_kind = :kind AND external_id = :external_id'
        );
        $stmt->execute(['kind' => $kind, 'external_id' => $externalId]);
        return (int) $stmt->fetchColumn();
    }

    private function cleanupTestDevices(): void
    {
        $this->pdo->exec(
            "DELETE FROM devices
              WHERE mac_address IN ('" . self::TEST_MAC_1 . "', '" . self::TEST_MAC_2 . "')
                 OR external_id = '" . self::TEST_BRIDGE_ID . "'"
        );
    }

    public function testResolveOrCreateBridge_existingBridgeWithoutMac_updatesMacWhenProvided(): void
    {
        $seenAt = new DateTimeImmutable('2026-05-01 10:00:00');

        // Crear bridge sin MAC
        $id = $this->resolver->resolveOrCreateBridge(
            bridgeIdReported: self::TEST_BRIDGE_ID,
            bridgeName: 'Test Bridge',
            seenAt: $seenAt,
        );

        $row = $this->fetchDevice($id);
        $this->assertNull($row['mac_address']);

        // Segunda llamada con MAC
        $this->resolver->resolveOrCreateBridge(
            bridgeIdReported: self::TEST_BRIDGE_ID,
            bridgeName: 'Test Bridge',
            seenAt: new DateTimeImmutable('2026-05-01 11:00:00'),
            mac: self::TEST_MAC_1,
        );

        $row = $this->fetchDevice($id);
        $this->assertSame(self::TEST_MAC_1, $row['mac_address']);
    }

    public function testResolveOrCreateBridge_existingBridgeWithMac_doesNotOverrideMac(): void
    {
        $seenAt = new DateTimeImmutable('2026-05-01 10:00:00');

        // Crear bridge con MAC
        $id = $this->resolver->resolveOrCreateBridge(
            bridgeIdReported: self::TEST_BRIDGE_ID,
            bridgeName: 'Test Bridge',
            seenAt: $seenAt,
            mac: self::TEST_MAC_1,
        );

        // Segunda llamada con otra MAC — no debe sobrescribir
        $this->resolver->resolveOrCreateBridge(
            bridgeIdReported: self::TEST_BRIDGE_ID,
            bridgeName: 'Test Bridge',
            seenAt: new DateTimeImmutable('2026-05-01 11:00:00'),
            mac: self::TEST_MAC_2,
        );

        $row = $this->fetchDevice($id);
        $this->assertSame(self::TEST_MAC_1, $row['mac_address']);
    }

    public function testResolveOrCreateBridge_updatesFirmwareInMetadata(): void
    {
        $seenAt = new DateTimeImmutable('2026-05-01 10:00:00');

        $id = $this->resolver->resolveOrCreateBridge(
            bridgeIdReported: self::TEST_BRIDGE_ID,
            bridgeName: 'Test Bridge',
            seenAt: $seenAt,
        );

        $this->resolver->resolveOrCreateBridge(
            bridgeIdReported: self::TEST_BRIDGE_ID,
            bridgeName: 'Test Bridge',
            seenAt: new DateTimeImmutable('2026-05-01 11:00:00'),
            firmwareVersion: '0.11.1',
        );

        $row  = $this->fetchDevice($id);
        $meta = json_decode((string) $row['metadata'], true);

        $this->assertSame('0.11.1', $meta['firmware_version'] ?? null);
    }

    public function testResolveOrCreateBeacon_movesToDifferentBridge_updatesParentDeviceId(): void
    {
        $seenAt = new DateTimeImmutable('2026-05-01 10:00:00');

        // Crear bridge A y bridge B
        $bridgeA = $this->resolver->resolveOrCreateBridge('test_bridge_999', 'Bridge A', $seenAt);
        $bridgeB = $this->resolver->resolveOrCreateBridge('test_bridge_998', 'Bridge B', $seenAt);

        // Primera vez: baliza llega por bridge A
        $balizaId = $this->resolver->resolveOrCreateBeacon(
            mac: self::TEST_MAC_1,
            externalId: '01',
            name: 'Test Baliza',
            bridgeDeviceId: $bridgeA,
            seenAt: $seenAt,
        );

        $row = $this->fetchDevice($balizaId);
        $this->assertSame($bridgeA, (int) $row['parent_device_id']);

        // Segunda vez: misma baliza llega por bridge B
        $this->resolver->resolveOrCreateBeacon(
            mac: self::TEST_MAC_1,
            externalId: '01',
            name: 'Test Baliza',
            bridgeDeviceId: $bridgeB,
            seenAt: new DateTimeImmutable('2026-05-02 10:00:00'),
        );

        $row = $this->fetchDevice($balizaId);
        $this->assertSame(
            $bridgeB,
            (int) $row['parent_device_id'],
            'parent_device_id debe actualizarse al nuevo bridge'
        );
    }
}
