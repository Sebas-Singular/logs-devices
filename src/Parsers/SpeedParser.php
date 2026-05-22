<?php
 
declare(strict_types=1);
 
namespace App\Parsers;
 
// =============================================================================
// SpeedParser.php — Parser de eventos SPEED
// =============================================================================
//
// Port de: st-platform/api/services/parsers/speed_parser.py
//
// Formato de una línea SPEED:
//
//   [2026-04-14 11:41:22] [SPEED] INFO: [34:85:18:46:e3:1c] SPEED ->
//     lane=1 loc=1 timestamp=2026-04-14 11:41:22 | dist=5329mm pos=14.00m speed=24.87km/h
//
// Los eventos SPEED son generados por las balizas cuando detectan el paso
// de un vehículo. Contienen:
//   - lane/loc: en qué carril y posición está la baliza
//   - timestamp: cuándo se detectó el vehículo (firmware)
//   - dist: distancia al vehículo en mm (medida por el LiDAR)
//   - pos: posición de la baliza en la vía (en metros)
//   - speed: velocidad calculada del vehículo en km/h
//
// NOTA sobre POS: en SPEED es 'pos=14.00m' (lowercase, en metros, con decimales).
//   En TELEMETRY es 'POS=74000' (uppercase, en milímetros, entero). Son campos
//   completamente distintos con patrones distintos. No confundir.
// =============================================================================
 
final class SpeedParser
{
    // -------------------------------------------------------------------------
    // SPEED_HEADER_PATTERN
    // -------------------------------------------------------------------------
    // Extrae los campos del header de una línea SPEED:
    //   lane         → '1'
    //   loc          → '1'
    //   firmware_ts  → '2026-04-14 11:41:22'  (o 'UNSYNCED' si no sincronizado)
    //
    // La separación del timestamp es con '|', igual que en TELEMETRY.
    // -------------------------------------------------------------------------
    private const SPEED_HEADER_PATTERN = "/
        lane=(?P<lane>-?\d+)\s+
        loc=(?P<loc>-?\d+)\s+
        timestamp=(?P<firmware_ts>[^|]+)
    /x";
 
    // -------------------------------------------------------------------------
    // Patrones de medidas de velocidad
    // -------------------------------------------------------------------------
    // dist=5329mm    → distance_mm:  5329.0  (distancia al vehículo)
    // pos=14.00m     → position_m:   14.0    (posición de la baliza)
    // speed=24.87km/h → speed_kmh:   24.87   (velocidad del vehículo)
    //
    // NOTA: 'pos' en SPEED es lowercase, en metros, con decimales (\d+(?:\.\d+)?m).
    //       El pattern incluye el '\b' al final para no capturar 'pos=14.00mm'.
    //       El '/' en 'km/h' debe ir sin escapar en PCRE entre '/' delimitadores:
    //       usamos '#' como delimitador del pattern para evitar conflicto.
    // -------------------------------------------------------------------------
    private const DIST_PATTERN  = '/\bdist=(?P<value>-?\d+(?:\.\d+)?)mm\b/';
    private const POS_PATTERN   = '/\bpos=(?P<value>-?\d+(?:\.\d+)?)m\b/';
    private const SPEED_PATTERN = '#\bspeed=(?P<value>-?\d+(?:\.\d+)?)km/h\b#';
 
    // -------------------------------------------------------------------------
    // parseSpeedEvent
    // -------------------------------------------------------------------------
    // Parsea una línea SPEED completa y devuelve un array con los campos
    // extraídos, listos para insertar en log_events.
    // -------------------------------------------------------------------------
    public static function parseSpeedEvent(string $rawLine, int $lineNumber): array
    {
        // Paso 1: parseo base
        $base = BaseLineParser::parseBaseLine($rawLine, $lineNumber);
 
        if (!$base['parse_ok']) {
            return $base;
        }
 
        $body = $base['body'];
 
        // Paso 2: header SPEED (lane, loc, firmware_ts)
        $headerMatched = preg_match(self::SPEED_HEADER_PATTERN, $body, $headerMatches);
 
        if ($headerMatched !== 1) {
            return Common::buildBaseInvalidEvent(
                rawLine: $rawLine,
                lineNumber: $lineNumber,
                parseError: 'speed_header_no_match',
            );
        }
 
        $firmwareTsRaw = trim($headerMatches['firmware_ts']);
 
        // Paso 3: extraer medidas
        $measurements = [
            'distance_mm' => Common::parseFloatOrNull(self::extract(self::DIST_PATTERN, $body)),
            'position_m'  => Common::parseFloatOrNull(self::extract(self::POS_PATTERN, $body)),
            'speed_kmh'   => Common::parseFloatOrNull(self::extract(self::SPEED_PATTERN, $body)),
        ];
 
        // Contexto: solo lane y loc (la posición de la baliza que detectó)
        $context = [
            'lane' => Common::parseIntOrNull($headerMatches['lane']),
            'loc'  => Common::parseIntOrNull($headerMatches['loc']),
        ];
 
        // Paso 4: anomalías
        $anomalyFlags = [];
 
        // Timestamp no sincronizado (menos frecuente en SPEED que en TELEMETRY,
        // pero ocurre en los primeros minutos de arranque de una baliza)
        if (strtoupper($firmwareTsRaw) === 'UNSYNCED') {
            $anomalyFlags[] = 'unsynced_timestamp';
        }
 
        // Velocidad físicamente improbable: >120 km/h en una instalación peatonal
        // o de tráfico urbano indica un error del sensor o del cálculo del firmware.
        $speed = $measurements['speed_kmh'];
        if ($speed !== null && $speed > 120.0) {
            $anomalyFlags[] = 'improbable_speed';
        }
 
        $qualityStatus = $anomalyFlags === [] ? 'valid' : 'suspect';
 
        // event_timestamp: firmware si disponible, received_timestamp_raw como fallback
        $firmwareTimestamp = Common::parseDatetimeOrNull($firmwareTsRaw);
        $receivedTimestamp = Common::parseDatetimeOrNull($base['received_timestamp_raw']);
        $eventTimestamp    = $firmwareTimestamp ?? $receivedTimestamp;
 
        return [
            'line_number'            => $lineNumber,
            'parse_ok'               => true,
            'parse_error'            => null,
 
            'severity'               => $base['severity'],   // 'info' del firmware
            'event_type'             => 'speed',
            'event_category'         => 'speed',
 
            'source_tag'             => $base['source_tag'],
            'device_mac'             => $base['device_mac'],
 
            // Los eventos SPEED no tienen id= ni name= en el body.
            // La baliza emisora se identifica solo por su MAC.
            'device_external_id'     => null,
            'device_name_reported'   => null,
 
            'event_timestamp'        => $eventTimestamp,
            'firmware_timestamp_raw' => $firmwareTsRaw,
            'received_timestamp_raw' => $base['received_timestamp_raw'],
 
            'measurements'           => $measurements,
            'context'                => $context,
 
            'quality_status'         => $qualityStatus,
            'anomaly_flags'          => $anomalyFlags,
 
            'message_text'           => $rawLine,
            'raw_line'               => $rawLine,
        ];
    }
 
    // -------------------------------------------------------------------------
    // extract — igual que en TelemetryParser
    // -------------------------------------------------------------------------
    // En lugar de duplicar código, podríamos mover extract() a Common.
    // Lo mantenemos aquí por ahora para que cada parser sea autocontenido
    // (más fácil de leer y testear). Si añadimos un tercer parser, lo movemos.
    // -------------------------------------------------------------------------
    private static function extract(string $pattern, string $text): ?string
    {
        $matched = preg_match($pattern, $text, $matches);
 
        if ($matched !== 1) {
            return null;
        }
 
        return $matches['value'] ?? null;
    }
}