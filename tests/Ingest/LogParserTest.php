<?php

declare(strict_types=1);

namespace Tests\Ingest;

use App\Database\Connection;
use App\Device\DeviceResolver;
use App\Ingest\LogParser;
use App\Parsers\SeverityDeriver;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

// =============================================================================
// LogParserTest — Tests de integración del orquestador
// =============================================================================
//
// Estos tests usan BD real porque LogParser depende de DeviceResolver, que
// hace INSERT/UPDATE en devices. Usamos identificadores de test que no
// aparecen en datos reales: bridges 'test_lp_*' y MACs 'fe:fe:fe:*'.
// =============================================================================

final class LogParserTest extends TestCase
{
    private PDO $pdo;
    private LogParser $parser;
    private int $bridgeDeviceId;

    private const TEST_BRIDGE_ID  = 'test_lp_999';
    private const TEST_MAC_PREFIX = 'fe:fe:fe:';

    protected function setUp(): void
    {
        $this->pdo    = Connection::make();
        $this->parser = new LogParser(
            new DeviceResolver($this->pdo),
            new SeverityDeriver(),
        );

        $this->cleanupTestData();

        // Bridge de test reutilizable por todos los tests
        $resolver = new DeviceResolver($this->pdo);
        $this->bridgeDeviceId = $resolver->resolveOrCreateBridge(
            bridgeIdReported: self::TEST_BRIDGE_ID,
            bridgeName: 'Test LP Bridge',
            seenAt: new DateTimeImmutable('2026-05-01 10:00:00'),
        );
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
    }

    // =========================================================================

    public function testProcessIngest_sameLogLineInDifferentIngests_producesDifferentEventHash(): void
    {
        // El hash incluye ingest_id + line_number para no perder líneas repetidas reales.
        $mac = self::TEST_MAC_PREFIX . '00:05';
        $line = '[2026-04-14 11:41:23] [SPEED] INFO: [' . $mac . '] SPEED -> lane=1 loc=1 timestamp=2026-04-14 11:41:22 | dist=5329mm pos=14.00m speed=24.87km/h';

        $result1 = $this->parser->processIngest($line, 1, $this->bridgeDeviceId, new DateTimeImmutable('2026-05-01 10:00:00'));
        $result2 = $this->parser->processIngest($line, 2, $this->bridgeDeviceId, new DateTimeImmutable('2026-05-01 11:00:00'));

        $this->assertNotSame(
            $result1['events'][0]['event_hash'],
            $result2['events'][0]['event_hash']
        );
    }

    public function testProcessIngest_withMultipleLines_cachesBeaconResolution(): void
    {
        // Tres líneas de la misma baliza: la resolución debe pasar por BD
        // solo la primera vez. Las otras dos vienen del caché.
        $mac = self::TEST_MAC_PREFIX . '00:02';
        $logText = implode("\n", [
            '[2026-04-14 10:36:59] [TELEMETRY] INFO: [' . $mac . '] TELEMETRY -> id=02 name=\'Baliza 02\' timestamp=2026-04-14 10:36:59 | T=20.0C H=50.00% P=940.0hPa AQ=100.0 (READY acc=1 stab=1 runin=1) alt=700m | LiDAR=5000mm | SOC=80% CHARGING (AC) | RSSI=-60 | LANE=1 LOC=0 POS=-1 REF=5000 SX=90.0 SY=90.0',
            '[2026-04-14 10:37:00] [SPEED] INFO: [' . $mac . '] SPEED -> lane=1 loc=1 timestamp=2026-04-14 10:36:59 | dist=5000mm pos=14.00m speed=20.00km/h',
            '[2026-04-14 10:37:01] [SPEED] INFO: [' . $mac . '] SPEED -> lane=1 loc=1 timestamp=2026-04-14 10:37:00 | dist=4800mm pos=14.50m speed=22.00km/h',
        ]);

        $result = $this->parser->processIngest(
            logText: $logText,
            ingestId: 999998,
            bridgeDeviceId: $this->bridgeDeviceId,
            receivedAt: new DateTimeImmutable('2026-04-14 10:38:00'),
        );

        $this->assertSame(3, $result['parsed_ok']);
        $this->assertSame(0, $result['parsed_error']);

        // Los tres eventos deben tener el mismo device_id (el de la baliza creada)
        $deviceIds = array_column($result['events'], 'device_id');
        $this->assertCount(1, array_unique($deviceIds));
        $this->assertNotNull($deviceIds[0]);

        // Y solo debe haber UNA baliza en la BD con esa MAC
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM devices WHERE mac_address = :mac');
        $stmt->execute(['mac' => $mac]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testProcessIngest_withMalformedLine_marksAsParseError(): void
    {
        $logText = "esta linea no tiene formato valido\n";

        $result = $this->parser->processIngest(
            logText: $logText,
            ingestId: 999997,
            bridgeDeviceId: $this->bridgeDeviceId,
            receivedAt: new DateTimeImmutable('2026-05-01 10:00:00'),
        );

        $this->assertSame(0, $result['parsed_ok']);
        $this->assertSame(1, $result['parsed_error']);

        $event = $result['events'][0];
        $this->assertSame(0, $event['parse_ok']);
        $this->assertSame('error', $event['severity']);
        $this->assertSame('derived', $event['severity_origin']);
        $this->assertSame('unknown', $event['event_type']);
        $this->assertSame('invalid', $event['quality_status']);
        $this->assertNull($event['device_id']);
        $this->assertNull($event['event_hash']);
    }

    public function testProcessIngest_mixedLines_countsCorrectly(): void
    {
        $mac = self::TEST_MAC_PREFIX . '00:03';
        $logText = implode("\n", [
            '[2026-04-14 10:36:59] [TELEMETRY] INFO: [' . $mac . '] TELEMETRY -> id=03 name=\'Baliza 03\' timestamp=2026-04-14 10:36:59 | T=20.0C H=50.00% P=940.0hPa AQ=100.0 (READY acc=1 stab=1 runin=1) alt=700m | LiDAR=5000mm | SOC=80% CHARGING (AC) | RSSI=-60 | LANE=1 LOC=0 POS=-1 REF=5000 SX=90.0 SY=90.0',
            'linea basura sin formato',
            '[2026-04-14 10:37:00] [SPEED] INFO: [' . $mac . '] SPEED -> lane=1 loc=1 timestamp=2026-04-14 10:36:59 | dist=5000mm pos=14.00m speed=20.00km/h',
        ]);

        $result = $this->parser->processIngest(
            logText: $logText,
            ingestId: 999996,
            bridgeDeviceId: $this->bridgeDeviceId,
            receivedAt: new DateTimeImmutable('2026-04-14 10:38:00'),
        );

        $this->assertSame(2, $result['parsed_ok']);
        $this->assertSame(1, $result['parsed_error']);
        $this->assertCount(3, $result['events']);
    }

    public function testProcessIngest_lineWithLowSoc_derivesSeverityToWarn(): void
    {
        // Variación con SOC bajo: severidad debe elevarse a 'warn' derivado
        $mac = self::TEST_MAC_PREFIX . '00:04';
        $line = '[2026-04-14 10:36:59] [TELEMETRY] INFO: [' . $mac . '] TELEMETRY -> id=04 name=\'Baliza 04\' timestamp=2026-04-14 10:36:59 | T=26.7C H=25.33% P=935.9hPa AQ=50.0 (WARMUP acc=0 stab=1 runin=0) alt=665m | LiDAR=5209mm | SOC=15% DISCHARGING (BAT) | RSSI=-67 | LANE=1 LOC=0 POS=-1 REF=7851 SX=103.5 SY=56.9';

        $result = $this->parser->processIngest(
            logText: $line,
            ingestId: 999995,
            bridgeDeviceId: $this->bridgeDeviceId,
            receivedAt: new DateTimeImmutable('2026-04-14 10:37:00'),
        );

        $event = $result['events'][0];
        $this->assertSame('warn', $event['severity']);
        $this->assertSame('derived', $event['severity_origin']);
        $this->assertSame('suspect', $event['quality_status']);

        // anomaly_flags debe contener 'low_soc' (es un JSON string)
        $this->assertStringContainsString('low_soc', $event['anomaly_flags']);
    }
    // =========================================================================

    private function cleanupTestData(): void
    {
        $this->pdo->exec(
            "DELETE FROM devices
              WHERE mac_address LIKE '" . self::TEST_MAC_PREFIX . "%'
                 OR external_id  = '" . self::TEST_BRIDGE_ID . "'"
        );
    }
}