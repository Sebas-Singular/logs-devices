<?php
 
declare(strict_types=1);
 
namespace Tests\Parsers;
 
use App\Parsers\TelemetryParser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
 
// =============================================================================
// TelemetryParserTest — Tests unitarios de TelemetryParser
// =============================================================================
//
// Todas las líneas base son reales (extraídas de bridge_11.ndjson, W16).
// Las líneas de test de low_soc y weak_rssi son variaciones mínimas de
// líneas reales, con un solo campo cambiado para activar el flag específico.
// =============================================================================
 
final class TelemetryParserTest extends TestCase
{
    // =========================================================================
    // Línea normal — todos los campos presentes y en rango
    // =========================================================================
 
    public function testParseTelemetryEvent_withNormalLine_returnsAllMeasurements(): void
    {
        $line = '[2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:e3:1c] TELEMETRY -> id=01 name=\'Baliza 01 - Las Rozas\' timestamp=2026-04-14 10:36:59 | T=26.7C H=25.33% P=935.9hPa AQ=50.0 (WARMUP acc=0 stab=1 runin=0) alt=665m | LiDAR=5209mm | SOC=82% DISCHARGING (AC) | RSSI=-67 | LANE=1 LOC=0 POS=-1 REF=7851 SX=103.5 SY=56.9';
 
        $result = TelemetryParser::parseTelemetryEvent($line, 1);
 
        $this->assertTrue($result['parse_ok']);
        $this->assertNull($result['parse_error']);
        $this->assertSame('telemetry', $result['event_type']);
        $this->assertSame('telemetry', $result['event_category']);
        $this->assertSame('info', $result['severity']);
 
        $this->assertSame('34:85:18:46:e3:1c', $result['device_mac']);
        $this->assertSame('01', $result['device_external_id']);
        $this->assertSame('Baliza 01 - Las Rozas', $result['device_name_reported']);
 
        $this->assertEqualsWithDelta(26.7, $result['measurements']['temperature_c'], 0.001);
        $this->assertEqualsWithDelta(25.33, $result['measurements']['humidity_pct'], 0.001);
        $this->assertEqualsWithDelta(935.9, $result['measurements']['pressure_hpa'], 0.001);
        $this->assertEqualsWithDelta(50.0, $result['measurements']['aq_index'], 0.001);
        $this->assertEqualsWithDelta(665.0, $result['measurements']['altitude_m'], 0.001);
        $this->assertEqualsWithDelta(5209.0, $result['measurements']['lidar_mm'], 0.001);
        $this->assertEqualsWithDelta(82.0, $result['measurements']['soc_pct'], 0.001);
        $this->assertEqualsWithDelta(-67.0, $result['measurements']['rssi_dbm'], 0.001);
 
        $this->assertSame(1, $result['context']['lane']);
        $this->assertSame(0, $result['context']['loc']);
        $this->assertSame(-1, $result['context']['pos']);
        $this->assertSame(7851, $result['context']['ref']);
        $this->assertEqualsWithDelta(103.5, $result['context']['sx'], 0.001);
        $this->assertEqualsWithDelta(56.9, $result['context']['sy'], 0.001);
 
        $this->assertSame('WARMUP', $result['context']['state']);
        $this->assertSame(0, $result['context']['acc']);
        $this->assertSame(1, $result['context']['stab']);
        $this->assertSame(0, $result['context']['runin']);
 
        $this->assertSame('DISCHARGING', $result['context']['charging_state']);
        $this->assertSame('AC', $result['context']['power_source']);
 
        $this->assertInstanceOf(DateTimeImmutable::class, $result['event_timestamp']);
        $this->assertSame('2026-04-14 10:36:59', $result['event_timestamp']->format('Y-m-d H:i:s'));
 
        $this->assertSame([], $result['anomaly_flags']);
        $this->assertSame('valid', $result['quality_status']);
    }
 
    // =========================================================================
    // Timestamp UNSYNCED
    // =========================================================================
 
    public function testParseTelemetryEvent_withUnsyncedTimestamp_setsFlag(): void
    {

        $line = '[2026-04-14 11:30:21] [TELEMETRY] INFO: [34:85:18:46:e3:4c] TELEMETRY -> id=04 name=\'Baliza 04 - Las Rozas\' timestamp=UNSYNCED | T=-1.4C H=17.26% P=420.3hPa AQ=50.0 (WARMUP acc=0 stab=1 runin=0) alt=6834m | LiDAR=0mm | SOC=0% CHARGING (AC) | RSSI=-60 | LANE=1 LOC=0 POS=-1 REF=5667 SX=90.0 SY=75.8';
 
        $result = TelemetryParser::parseTelemetryEvent($line, 1);
 
        $this->assertTrue($result['parse_ok']);
 
        $this->assertContains('unsynced_timestamp', $result['anomaly_flags']);
 
        $this->assertInstanceOf(DateTimeImmutable::class, $result['event_timestamp']);
        $this->assertSame('2026-04-14 11:30:21', $result['event_timestamp']->format('Y-m-d H:i:s'));
 
        $this->assertSame('UNSYNCED', $result['firmware_timestamp_raw']);
    }
 
    // =========================================================================
    // Zero sensor payload
    // =========================================================================
 
    public function testParseTelemetryEvent_withZeroSensors_setsFlag(): void
    {

        $line = '[2026-04-14 11:33:11] [TELEMETRY] INFO: [34:85:18:46:e3:4c] TELEMETRY -> id=04 name=\'Baliza 04 - Las Rozas\' timestamp=UNSYNCED | T=0.0C H=0.00% P=0.0hPa AQ=0.0 (WARMUP acc=0 stab=0 runin=0) alt=0m | LiDAR=0mm | SOC=0% CHARGING (AC) | RSSI=-54 | LANE=1 LOC=0 POS=-1 REF=5667 SX=90.0 SY=75.8';
 
        $result = TelemetryParser::parseTelemetryEvent($line, 1);
 
        $this->assertTrue($result['parse_ok']);
        $this->assertContains('zero_sensor_payload', $result['anomaly_flags']);
        $this->assertSame('suspect', $result['quality_status']);
 
        $this->assertContains('unsynced_timestamp', $result['anomaly_flags']);
        $this->assertContains('low_soc', $result['anomaly_flags']);
    }
 
    // =========================================================================
    // Presión fuera de rango (detectada en datos reales W16: alt=6834m, P=420hPa)
    // =========================================================================
 
    public function testParseTelemetryEvent_withOutOfRangePressure_setsFlag(): void
    {
        $line = '[2026-04-14 11:30:21] [TELEMETRY] INFO: [34:85:18:46:e3:4c] TELEMETRY -> id=04 name=\'Baliza 04 - Las Rozas\' timestamp=UNSYNCED | T=-1.4C H=17.26% P=420.3hPa AQ=50.0 (WARMUP acc=0 stab=1 runin=0) alt=6834m | LiDAR=0mm | SOC=0% CHARGING (AC) | RSSI=-60 | LANE=1 LOC=0 POS=-1 REF=5667 SX=90.0 SY=75.8';
 
        $result = TelemetryParser::parseTelemetryEvent($line, 1);
 
        $this->assertTrue($result['parse_ok']);
        $this->assertContains('out_of_range_pressure', $result['anomaly_flags']);
        $this->assertContains('out_of_range_altitude', $result['anomaly_flags']); 
    }
 
    // =========================================================================
    // [PHP+] SOC bajo (< 20%)
    // =========================================================================
 
    public function testParseTelemetryEvent_withLowSoc_setsFlag(): void
    {

        $line = '[2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:e3:1c] TELEMETRY -> id=01 name=\'Baliza 01 - Las Rozas\' timestamp=2026-04-14 10:36:59 | T=26.7C H=25.33% P=935.9hPa AQ=50.0 (WARMUP acc=1 stab=1 runin=1) alt=665m | LiDAR=5209mm | SOC=15% DISCHARGING (BAT) | RSSI=-67 | LANE=1 LOC=1 POS=14000 REF=5781 SX=90.0 SY=97.1';
 
        $result = TelemetryParser::parseTelemetryEvent($line, 1);
 
        $this->assertTrue($result['parse_ok']);
        $this->assertContains('low_soc', $result['anomaly_flags']);
        $this->assertEqualsWithDelta(15.0, $result['measurements']['soc_pct'], 0.001);
        $this->assertSame('suspect', $result['quality_status']);
 
        $lineSoc19 = str_replace('SOC=15%', 'SOC=19%', $line);
        $result19 = TelemetryParser::parseTelemetryEvent($lineSoc19, 2);
        $this->assertContains('low_soc', $result19['anomaly_flags']);
 
        $lineSoc20 = str_replace('SOC=15%', 'SOC=20%', $line);
        $result20 = TelemetryParser::parseTelemetryEvent($lineSoc20, 3);
        $this->assertNotContains('low_soc', $result20['anomaly_flags']);
    }
 
    // =========================================================================
    // [PHP+] RSSI débil (< -90 dBm)
    // =========================================================================
 
    public function testParseTelemetryEvent_withWeakRssi_setsFlag(): void
    {
        $line = '[2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:e3:04] TELEMETRY -> id=02 name=\'Baliza 02 - Las Rozas\' timestamp=2026-04-14 10:36:59 | T=20.0C H=50.00% P=940.0hPa AQ=100.0 (WARMUP acc=1 stab=1 runin=1) alt=700m | LiDAR=5000mm | SOC=80% CHARGING (AC) | RSSI=-95 | LANE=1 LOC=2 POS=44000 REF=5937 SX=90.0 SY=90.0';
 
        $result = TelemetryParser::parseTelemetryEvent($line, 1);
 
        $this->assertTrue($result['parse_ok']);
        $this->assertContains('weak_rssi', $result['anomaly_flags']);
        $this->assertEqualsWithDelta(-95.0, $result['measurements']['rssi_dbm'], 0.001);
 
        $lineRssi90 = str_replace('RSSI=-95', 'RSSI=-90', $line);
        $resultRssi90 = TelemetryParser::parseTelemetryEvent($lineRssi90, 2);
        $this->assertNotContains('weak_rssi', $resultRssi90['anomaly_flags']);
 
        $lineRssi91 = str_replace('RSSI=-95', 'RSSI=-91', $line);
        $resultRssi91 = TelemetryParser::parseTelemetryEvent($lineRssi91, 3);
        $this->assertContains('weak_rssi', $resultRssi91['anomaly_flags']);
    }
 
    // =========================================================================
    // Línea malformada
    // =========================================================================
 
    public function testParseTelemetryEvent_withInvalidLine_returnsParseError(): void
    {
        $result = TelemetryParser::parseTelemetryEvent('not a valid log line', 1);
 
        $this->assertFalse($result['parse_ok']);
        $this->assertSame('base_line_no_match', $result['parse_error']);
        $this->assertSame('unknown', $result['severity']);
        $this->assertSame('invalid', $result['quality_status']);
    }
 
    // =========================================================================
    // Línea SPEED pasada por error al TelemetryParser
    // =========================================================================
 
    public function testParseTelemetryEvent_withSpeedLine_returnsHeaderError(): void
    {

        $line = '[2026-04-14 11:41:23] [SPEED] INFO: [34:85:18:46:e3:1c] SPEED -> lane=1 loc=1 timestamp=2026-04-14 11:41:22 | dist=5329mm pos=14.00m speed=24.87km/h';
 
        $result = TelemetryParser::parseTelemetryEvent($line, 1);
 
        $this->assertFalse($result['parse_ok']);
        $this->assertSame('telemetry_header_no_match', $result['parse_error']);
    }
 
    // =========================================================================
    // Temperatura negativa (válida físicamente, detectada en W16)
    // =========================================================================
 
    public function testParseTelemetryEvent_withNegativeTemperature_parsesCorrectly(): void
    {
        $line = '[2026-04-14 11:30:45] [TELEMETRY] INFO: [34:85:18:46:e3:4c] TELEMETRY -> id=04 name=\'Baliza 04 - Las Rozas\' timestamp=2026-04-14 11:30:45 | T=-1.4C H=15.96% P=417.9hPa AQ=50.0 (WARMUP acc=0 stab=1 runin=0) alt=6876m | LiDAR=0mm | SOC=0% CHARGING (AC) | RSSI=-53 | LANE=1 LOC=0 POS=-1 REF=5667 SX=90.0 SY=75.8';
 
        $result = TelemetryParser::parseTelemetryEvent($line, 1);
 
        $this->assertTrue($result['parse_ok']);
 
        $this->assertEqualsWithDelta(-1.4, $result['measurements']['temperature_c'], 0.001);
 
        $this->assertNotContains('out_of_range_temperature', $result['anomaly_flags']);
    }
 
    // =========================================================================
    // [PHP+] Estado de carga BAT
    // =========================================================================
 
    public function testParseTelemetryEvent_withBatPowerSource_parsesCorrectly(): void
    {
        $line = '[2026-04-14 10:59:11] [TELEMETRY] INFO: [34:85:18:46:e3:1c] TELEMETRY -> id=01 name=\'Baliza 01 - Las Rozas\' timestamp=2026-04-14 10:59:11 | T=24.1C H=24.22% P=936.2hPa AQ=54.7 (WARMUP acc=1 stab=1 runin=1) alt=662m | LiDAR=7400mm | SOC=80% DISCHARGING (BAT) | RSSI=-68 | LANE=1 LOC=0 POS=-1 REF=6806 SX=103.5 SY=56.9';
 
        $result = TelemetryParser::parseTelemetryEvent($line, 1);
 
        $this->assertTrue($result['parse_ok']);
        $this->assertSame('DISCHARGING', $result['context']['charging_state']);
        $this->assertSame('BAT', $result['context']['power_source']);
    }
}