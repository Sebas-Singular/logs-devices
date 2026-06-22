<?php
 
declare(strict_types=1);
 
namespace App\Parsers;
 
// =============================================================================
// BaseLineParser.php — Parser de la estructura base de una línea de log
// =============================================================================
//
// Port de: st-platform/api/services/parsers/base_line_parser.py
//
// Todas las líneas de log que emiten los bridges tienen el mismo formato base:
//
//   [YYYY-MM-DD HH:MM:SS] [TIPO] SEVERIDAD: [MAC] EVENTO -> cuerpo del evento
//
// Ejemplo real:
//   [2026-04-14 10:36:59] [TELEMETRY] INFO: [34:85:18:46:e3:1c] TELEMETRY -> id=01 ...
//   [2026-04-14 11:41:22] [SPEED] INFO: [34:85:18:46:e3:1c] SPEED -> lane=1 loc=1 ...
//
// Este parser extrae los campos comunes. Cada parser específico (Telemetry,
// Speed) recibe el resultado de este parser y extrae los campos propios del
// cuerpo (`body`).
//
// Flujo:
//   1. BaseLineParser::splitRawLines()  → divide logText en líneas individuales
//   2. BaseLineParser::parseBaseLine()  → extrae campos comunes de una línea
//   3. TelemetryParser / SpeedParser    → extraen campos específicos del body
// =============================================================================
 
final class BaseLineParser
{
    // -------------------------------------------------------------------------
    // LINE_PATTERN — Regex que define el formato base de todas las líneas
    // -------------------------------------------------------------------------
    //
    // Grupos nombrados (en PHP PCRE, la sintaxis es (?P<nombre>...)):
    //
    //   received_ts  → timestamp del servidor/bridge: '2026-04-14 10:36:59'
    //   source_tag   → tipo de evento entre corchetes: 'TELEMETRY', 'SPEED'
    //   severity     → nivel de log en mayúsculas: 'INFO', 'WARN', 'ERROR'
    //   device_mac   → dirección MAC de la baliza: '34:85:18:46:e3:1c'
    //   event_word   → palabra clave del evento: 'TELEMETRY', 'SPEED'
    //   body         → todo lo que va después de ' -> ': los datos del evento
    //
    // El modificador /x (PCRE_EXTENDED) ignora los espacios y saltos de línea
    // dentro del pattern, lo que permite formatearlo de forma legible.
    // Es equivalente a re.VERBOSE en Python.
    //
    // El modificador /D (PCRE_DOLLAR_ENDONLY) hace que $ solo coincida al
    // final del string, no antes de un \n al final. Evita problemas con
    // líneas que terminen en \r\n en Windows.
    // -------------------------------------------------------------------------
    private const LINE_PATTERN = "/
        ^\[(?P<received_ts>[^\]]+)\]\s+
        \[(?P<source_tag>[^\]]+)\]\s+
        (?P<severity>[A-Z]+):\s+
        \[(?P<device_mac>[^\]]+)\]\s+
        (?P<event_word>[A-Z_]+)\s+->\s+
        (?P<body>.*)$
    /xD";
 
    // -------------------------------------------------------------------------
    // splitRawLines
    // -------------------------------------------------------------------------
    // Divide el campo logText (string multilínea) en un array de líneas, una
    // por elemento. Elimina líneas vacías y espacios al principio/final de
    // cada línea.
    //
    // Por qué no usar explode("\n", ...):
    //   Los bridges pueden estar en Windows y enviar \r\n. El patrón /\R/
    //   de PCRE reconoce cualquier fin de línea: \n, \r\n, \r, \u{2028}, etc.
    //   explode("\n") rompería en Windows dejando \r al final de cada línea.
    //
    // Parámetro:
    //   $logText — el campo logText del payload JSON del bridge
    //
    // Retorna:
    //   array de strings, cada uno es una línea de log no vacía
    // -------------------------------------------------------------------------
    public static function splitRawLines(string $logText): array
    {
        $lines = preg_split('/\R/', $logText);
 
        if ($lines === false) {
            return [];
        }
 
        return array_values(
            array_filter(
                array_map('trim', $lines),
                static fn (string $line): bool => $line !== ''
            )
        );
    }
 
    // -------------------------------------------------------------------------
    // parseBaseLine
    // -------------------------------------------------------------------------
    // Intenta parsear una sola línea de log con el regex base.
    //
    // Parámetros:
    //   $rawLine    — la línea de log completa (sin trim previo)
    //   $lineNumber — posición de la línea en el logText (para debugging)
    //
    // Retorna:
    //   array con los campos extraídos si el parseo fue bien (parse_ok=true)
    //   array de error si no coincidió el regex (parse_ok=false)
    //
    // Los valores de retorno están SIEMPRE normalizados:
    //   - source_tag  → lowercase: 'telemetry', 'speed'
    //   - severity    → lowercase: 'info', 'warn', 'error'
    //   - device_mac  → lowercase: '34:85:18:46:e3:1c'
    //   - event_word  → lowercase: 'telemetry', 'speed'
    //
    // El campo `body` se devuelve tal cual (sin normalizar) porque los parsers
    // específicos aplicarán sus propios regex sobre él.
    // -------------------------------------------------------------------------
    public static function parseBaseLine(string $rawLine, int $lineNumber): array
    {

        $matched = preg_match(self::LINE_PATTERN, $rawLine, $matches);
 
        if ($matched !== 1) {

            return Common::buildBaseInvalidEvent(
                rawLine: $rawLine,
                lineNumber: $lineNumber,
                parseError: 'base_line_no_match',
            );
        }
 
        return [
            'line_number'            => $lineNumber,
            'parse_ok'               => true,
            'parse_error'            => null,

            'received_timestamp_raw' => trim($matches['received_ts']),
 
            'source_tag'             => strtolower(trim($matches['source_tag'])),

            'severity'               => strtolower(trim($matches['severity'])),
 
            'device_mac'             => strtolower(trim($matches['device_mac'])),
 
            'event_word'             => strtolower(trim($matches['event_word'])),
 
            'body'                   => $matches['body'] ?? '',
 
            'message_text'           => $rawLine,
            'raw_line'               => $rawLine,
        ];
    }
}