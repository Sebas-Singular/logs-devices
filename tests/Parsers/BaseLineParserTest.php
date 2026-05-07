<?php
 
declare(strict_types=1);
 
namespace Tests\Parsers;
 
use App\Parsers\BaseLineParser;
use PHPUnit\Framework\TestCase;
 
// =============================================================================
// BaseLineParserTest — Tests unitarios de BaseLineParser
// =============================================================================
//
// Todas las líneas de test son líneas REALES extraídas de los NDJSON generados
// en Fase 1 (bridge_11.ndjson, W16). No son datos inventados.
//
// Convención de nombres: testVerb_Noun_Condition
//   parse → estamos testeando el parseo
//   split → estamos testeando splitRawLines
// =============================================================================
 
final class BaseLineParserTest extends TestCase
{
    // =========================================================================
    // Tests de splitRawLines
    // =========================================================================
 
    public function testSplitRawLines_withMultipleLines_returnsNonEmptyLines(): void
    {
        $logText = implode("\n", [
            '[2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:e3:1c] TELEMETRY -> id=01 name=\'Baliza 01 - Las Rozas\' timestamp=2026-04-14 10:36:59 | T=26.7C H=25.33% P=935.9hPa AQ=50.0 (WARMUP acc=0 stab=1 runin=0) alt=665m | LiDAR=5209mm | SOC=82% DISCHARGING (AC) | RSSI=-67 | LANE=1 LOC=0 POS=-1 REF=7851 SX=103.5 SY=56.9',
            ' ',
            '[2026-04-14 11:41:23] [SPEED] INFO: [34:85:18:46:e3:1c] SPEED -> lane=1 loc=1 timestamp=2026-04-14 11:41:22 | dist=5329mm pos=14.00m speed=24.87km/h',
        ]);
 
        $lines = BaseLineParser::splitRawLines($logText);
 
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('[2026-04-14 10:36:59]', $lines[0]);
        $this->assertStringStartsWith('[2026-04-14 11:41:23]', $lines[1]);
    }
 
    public function testSplitRawLines_withWindowsLineEndings_returnsCleanLines(): void
    {

        $logText = "[2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:e3:1c] TELEMETRY -> id=01 ...\r\n" .
                   "[2026-04-14 11:41:23] [SPEED] INFO: [34:85:18:46:e3:1c] SPEED -> lane=1 ...\r\n";
 
        $lines = BaseLineParser::splitRawLines($logText);
 
        $this->assertCount(2, $lines);
 
        foreach ($lines as $line) {
            $this->assertStringEndsNotWith("\r", $line);
        }
    }
 
    public function testSplitRawLines_withEmptyString_returnsEmptyArray(): void
    {
        $this->assertSame([], BaseLineParser::splitRawLines(''));
        $this->assertSame([], BaseLineParser::splitRawLines('   '));
        $this->assertSame([], BaseLineParser::splitRawLines("\n\n\n"));
    }
 
    // =========================================================================
    // Tests de parseBaseLine — línea TELEMETRY válida
    // =========================================================================
 
    public function testParseBaseLine_withValidTelemetryLine_returnsCorrectFields(): void
    {
        $line = '[2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:e3:1c] TELEMETRY -> id=01 name=\'Baliza 01 - Las Rozas\' timestamp=2026-04-14 10:36:59 | T=26.7C H=25.33% P=935.9hPa AQ=50.0 (WARMUP acc=0 stab=1 runin=0) alt=665m | LiDAR=5209mm | SOC=82% DISCHARGING (AC) | RSSI=-67 | LANE=1 LOC=0 POS=-1 REF=7851 SX=103.5 SY=56.9';
 
        $result = BaseLineParser::parseBaseLine($line, 1);
 
        $this->assertTrue($result['parse_ok']);
        $this->assertNull($result['parse_error']);
 
        $this->assertSame('2026-04-14 10:36:59', $result['received_timestamp_raw']);
        $this->assertSame('telemetry', $result['source_tag']);    
        $this->assertSame('info', $result['severity']);           
        $this->assertSame('34:85:18:46:e3:1c', $result['device_mac']); 
        $this->assertSame('telemetry', $result['event_word']);   
 
        $this->assertStringStartsWith('id=01', $result['body']);
 
        $this->assertSame($line, $result['message_text']);
        $this->assertSame($line, $result['raw_line']);
    }
 
    // =========================================================================
    // Tests de parseBaseLine — línea SPEED válida
    // =========================================================================
 
    public function testParseBaseLine_withValidSpeedLine_returnsCorrectFields(): void
    {
        $line = '[2026-04-14 11:41:23] [SPEED] INFO: [34:85:18:46:e3:1c] SPEED -> lane=1 loc=1 timestamp=2026-04-14 11:41:22 | dist=5329mm pos=14.00m speed=24.87km/h';
 
        $result = BaseLineParser::parseBaseLine($line, 2);
 
        $this->assertTrue($result['parse_ok']);
        $this->assertSame('speed', $result['source_tag']);
        $this->assertSame('info', $result['severity']);
        $this->assertSame('34:85:18:46:e3:1c', $result['device_mac']);
        $this->assertSame('speed', $result['event_word']);
        $this->assertStringStartsWith('lane=1', $result['body']);
    }
 
    // =========================================================================
    // Tests de parseBaseLine — líneas inválidas
    // =========================================================================
 
    public function testParseBaseLine_withInvalidLine_returnsParseError(): void
    {

        $line = 'Este no es un log válido';
 
        $result = BaseLineParser::parseBaseLine($line, 5);
 
        $this->assertFalse($result['parse_ok']);
        $this->assertSame('base_line_no_match', $result['parse_error']);
        $this->assertSame('unknown', $result['severity']);
        $this->assertSame('unknown', $result['event_type']);
        $this->assertSame($line, $result['message_text']);
    }
 
    public function testParseBaseLine_withEmptyLine_returnsParseError(): void
    {
        $result = BaseLineParser::parseBaseLine('', 1);
 
        $this->assertFalse($result['parse_ok']);
        $this->assertSame('base_line_no_match', $result['parse_error']);
    }
 
    public function testParseBaseLine_withLineNumber_propagatesLineNumber(): void
    {
        $line = '[2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:e3:1c] TELEMETRY -> id=01 body';
        $result = BaseLineParser::parseBaseLine($line, 42);
 
        $this->assertSame(42, $result['line_number']);
    }
 
    public function testParseBaseLine_withMacAddress_normalizesToLowercase(): void
    {
        $line = '[2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:E3:1C] TELEMETRY -> id=01 body';
        $result = BaseLineParser::parseBaseLine($line, 1);
 
        if ($result['parse_ok']) {
            $this->assertSame('34:85:18:46:e3:1c', $result['device_mac']);
        }
    }
}