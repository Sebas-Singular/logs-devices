<?php
 
declare(strict_types=1);
 
namespace Tests\Parsers;
 
use App\Parsers\SpeedParser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
 
// =============================================================================
// SpeedParserTest — Tests unitarios de SpeedParser
// =============================================================================
 
final class SpeedParserTest extends TestCase
{
    // =========================================================================
    // Línea SPEED normal
    // =========================================================================
 
    public function testParseSpeedEvent_withNormalLine_returnsCorrectFields(): void
    {
        // Línea real del bridge_11.ndjson W16
        $line = '[2026-04-14 11:41:23] [SPEED] INFO: [34:85:18:46:e3:1c] SPEED -> lane=1 loc=1 timestamp=2026-04-14 11:41:22 | dist=5329mm pos=14.00m speed=24.87km/h';
 
        $result = SpeedParser::parseSpeedEvent($line, 1);
 
        $this->assertTrue($result['parse_ok']);
        $this->assertNull($result['parse_error']);
        $this->assertSame('speed', $result['event_type']);
        $this->assertSame('speed', $result['event_category']);
        $this->assertSame('info', $result['severity']);
 
        // Device: solo MAC (SPEED no tiene id= ni name= en el body)
        $this->assertSame('34:85:18:46:e3:1c', $result['device_mac']);
        $this->assertNull($result['device_external_id']);
        $this->assertNull($result['device_name_reported']);
 
        // Measurements
        $this->assertEqualsWithDelta(5329.0, $result['measurements']['distance_mm'], 0.001);
        $this->assertEqualsWithDelta(14.0, $result['measurements']['position_m'], 0.001);
        $this->assertEqualsWithDelta(24.87, $result['measurements']['speed_kmh'], 0.001);
 
        // Context
        $this->assertSame(1, $result['context']['lane']);
        $this->assertSame(1, $result['context']['loc']);
 
        // Timestamp: firmware_ts = '2026-04-14 11:41:22'
        $this->assertInstanceOf(DateTimeImmutable::class, $result['event_timestamp']);
        $this->assertSame('2026-04-14 11:41:22', $result['event_timestamp']->format('Y-m-d H:i:s'));
 
        // Sin anomalías
        $this->assertSame([], $result['anomaly_flags']);
        $this->assertSame('valid', $result['quality_status']);
    }
 
    // =========================================================================
    // Velocidad alta (real: 50 km/h — dentro del límite)
    // =========================================================================
 
    public function testParseSpeedEvent_withHighButValidSpeed_noFlag(): void
    {
        // 50 km/h: velocidad real de los datos W19, dentro del límite de 120 km/h
        $line = '[2026-05-04 02:42:15] [SPEED] INFO: [34:85:18:46:e3:04] SPEED -> lane=1 loc=2 timestamp=2026-05-04 02:42:14 | dist=4742mm pos=44.00m speed=50.47km/h';
 
        $result = SpeedParser::parseSpeedEvent($line, 1);
 
        $this->assertTrue($result['parse_ok']);
        $this->assertNotContains('improbable_speed', $result['anomaly_flags']);
        $this->assertEqualsWithDelta(50.47, $result['measurements']['speed_kmh'], 0.001);
    }
 
    // =========================================================================
    // Velocidad improbable (> 120 km/h)
    // =========================================================================
 
    public function testParseSpeedEvent_withImprobableSpeed_setsFlag(): void
    {
        // Variación de una línea real: speed=24.87 → speed=150.00
        $line = '[2026-04-14 11:41:23] [SPEED] INFO: [34:85:18:46:e3:1c] SPEED -> lane=1 loc=1 timestamp=2026-04-14 11:41:22 | dist=5329mm pos=14.00m speed=150.00km/h';
 
        $result = SpeedParser::parseSpeedEvent($line, 1);
 
        $this->assertTrue($result['parse_ok']);
        $this->assertContains('improbable_speed', $result['anomaly_flags']);
        $this->assertSame('suspect', $result['quality_status']);
 
        // Exactamente 120.0 NO debe disparar el flag (límite estricto > 120)
        $lineExact120 = str_replace('speed=150.00km/h', 'speed=120.00km/h', $line);
        $resultExact120 = SpeedParser::parseSpeedEvent($lineExact120, 2);
        $this->assertNotContains('improbable_speed', $resultExact120['anomaly_flags']);
 
        // 120.01 SÍ debe disparar
        $line12001 = str_replace('speed=150.00km/h', 'speed=120.01km/h', $line);
        $result12001 = SpeedParser::parseSpeedEvent($line12001, 3);
        $this->assertContains('improbable_speed', $result12001['anomaly_flags']);
    }
 
    // =========================================================================
    // Velocidad muy baja (válida: vehículo parándose o aparcando)
    // =========================================================================
 
    public function testParseSpeedEvent_withVeryLowSpeed_parsesCorrectly(): void
    {
        // Velocidad real de los datos W19: 2.57 km/h (vehículo casi parado)
        $line = '[2026-05-04 06:50:07] [SPEED] INFO: [34:85:18:46:e3:04] SPEED -> lane=1 loc=2 timestamp=2026-05-04 06:50:06 | dist=4923mm pos=44.00m speed=2.57km/h';
 
        $result = SpeedParser::parseSpeedEvent($line, 1);
 
        $this->assertTrue($result['parse_ok']);
        $this->assertEqualsWithDelta(2.57, $result['measurements']['speed_kmh'], 0.001);
        $this->assertSame([], $result['anomaly_flags']);
    }
 
    // =========================================================================
    // Línea malformada
    // =========================================================================
 
    public function testParseSpeedEvent_withInvalidLine_returnsParseError(): void
    {
        $result = SpeedParser::parseSpeedEvent('not a valid log line', 1);
 
        $this->assertFalse($result['parse_ok']);
        $this->assertSame('base_line_no_match', $result['parse_error']);
        $this->assertSame('invalid', $result['quality_status']);
    }
 
    // =========================================================================
    // Línea TELEMETRY pasada por error al SpeedParser
    // =========================================================================
 
    public function testParseSpeedEvent_withTelemetryLine_returnsHeaderError(): void
    {
        $line = '[2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:e3:1c] TELEMETRY -> id=01 name=\'Baliza 01 - Las Rozas\' timestamp=2026-04-14 10:36:59 | T=26.7C H=25.33% P=935.9hPa AQ=50.0 (WARMUP acc=0 stab=1 runin=0) alt=665m | LiDAR=5209mm | SOC=82% DISCHARGING (AC) | RSSI=-67 | LANE=1 LOC=0 POS=-1 REF=7851 SX=103.5 SY=56.9';
 
        $result = SpeedParser::parseSpeedEvent($line, 1);
 
        $this->assertFalse($result['parse_ok']);
        $this->assertSame('speed_header_no_match', $result['parse_error']);
    }
 
    // =========================================================================
    // Preservación de la línea original en message_text
    // =========================================================================
 
    public function testParseSpeedEvent_alwaysPreservesRawLine(): void
    {
        $line = '[2026-04-14 11:41:23] [SPEED] INFO: [34:85:18:46:e3:1c] SPEED -> lane=1 loc=1 timestamp=2026-04-14 11:41:22 | dist=5329mm pos=14.00m speed=24.87km/h';
 
        $result = SpeedParser::parseSpeedEvent($line, 1);
 
        $this->assertSame($line, $result['message_text']);
        $this->assertSame($line, $result['raw_line']);
    }
}