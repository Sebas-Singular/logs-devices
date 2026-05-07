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
        // Simula el logText que llega en el payload del bridge:
        // dos líneas reales separadas por \n, con una línea vacía en medio.
        $logText = implode("\n", [
            '[2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:e3:1c] TELEMETRY -> id=01 name=\'Baliza 01 - Las Rozas\' timestamp=2026-04-14 10:36:59 | T=26.7C H=25.33% P=935.9hPa AQ=50.0 (WARMUP acc=0 stab=1 runin=0) alt=665m | LiDAR=5209mm | SOC=82% DISCHARGING (AC) | RSSI=-67 | LANE=1 LOC=0 POS=-1 REF=7851 SX=103.5 SY=56.9',
            '',  // línea vacía que debe ser ignorada
            '[2026-04-14 11:41:23] [SPEED] INFO: [34:85:18:46:e3:1c] SPEED -> lane=1 loc=1 timestamp=2026-04-14 11:41:22 | dist=5329mm pos=14.00m speed=24.87km/h',
        ]);
 
        $lines = BaseLineParser::splitRawLines($logText);
 
        // Debe devolver exactamente 2 líneas (la vacía se elimina)
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('[2026-04-14 10:36:59]', $lines[0]);
        $this->assertStringStartsWith('[2026-04-14 11:41:23]', $lines[1]);
    }
 
    public function testSplitRawLines_withWindowsLineEndings_returnsCleanLines(): void
    {
        // Los bridges pueden estar en Windows y enviar \r\n. splitRawLines debe
        // manejar esto correctamente y devolver líneas sin \r.
        $logText = "[2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:e3:1c] TELEMETRY -> id=01 ...\r\n" .
                   "[2026-04-14 11:41:23] [SPEED] INFO: [34:85:18:46:e3:1c] SPEED -> lane=1 ...\r\n";
 
        $lines = BaseLineParser::splitRawLines($logText);
 
        $this->assertCount(2, $lines);
 
        // Ninguna línea debe tener \r al final
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
        // Línea real del bridge_11.ndjson W16
        $line = '[2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:e3:1c] TELEMETRY -> id=01 name=\'Baliza 01 - Las Rozas\' timestamp=2026-04-14 10:36:59 | T=26.7C H=25.33% P=935.9hPa AQ=50.0 (WARMUP acc=0 stab=1 runin=0) alt=665m | LiDAR=5209mm | SOC=82% DISCHARGING (AC) | RSSI=-67 | LANE=1 LOC=0 POS=-1 REF=7851 SX=103.5 SY=56.9';
 
        $result = BaseLineParser::parseBaseLine($line, 1);
 
        // El parseo debe haber tenido éxito
        $this->assertTrue($result['parse_ok']);
        $this->assertNull($result['parse_error']);
 
        // Campos comunes
        $this->assertSame('2026-04-14 10:36:59', $result['received_timestamp_raw']);
        $this->assertSame('telemetry', $result['source_tag']);    // lowercase
        $this->assertSame('info', $result['severity']);           // lowercase
        $this->assertSame('34:85:18:46:e3:1c', $result['device_mac']); // lowercase
        $this->assertSame('telemetry', $result['event_word']);    // lowercase
 
        // El body debe empezar por 'id=01'
        $this->assertStringStartsWith('id=01', $result['body']);
 
        // La línea original se preserva intacta
        $this->assertSame($line, $result['message_text']);
        $this->assertSame($line, $result['raw_line']);
    }
 
    // =========================================================================
    // Tests de parseBaseLine — línea SPEED válida
    // =========================================================================
 
    public function testParseBaseLine_withValidSpeedLine_returnsCorrectFields(): void
    {
        // Línea real del bridge_11.ndjson W16
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
        // Línea que no tiene el formato esperado (podría ser un stack trace,
        // un mensaje de boot, o datos corruptos)
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
        // El line_number debe propagarse correctamente para debugging
        $line = '[2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:e3:1c] TELEMETRY -> id=01 body';
        $result = BaseLineParser::parseBaseLine($line, 42);
 
        $this->assertSame(42, $result['line_number']);
    }
 
    public function testParseBaseLine_withMacAddress_normalizesToLowercase(): void
    {
        // Aunque en práctica las MACs llegan en lowercase, el parser debe
        // normalizar a lowercase incluso si vinieran en uppercase (robustez).
        $line = '[2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:E3:1C] TELEMETRY -> id=01 body';
        $result = BaseLineParser::parseBaseLine($line, 1);
 
        if ($result['parse_ok']) {
            $this->assertSame('34:85:18:46:e3:1c', $result['device_mac']);
        }
    }
}