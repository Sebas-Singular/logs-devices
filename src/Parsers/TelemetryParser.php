<?php
 
declare(strict_types=1);
 
namespace App\Parsers;
 
use DateTimeImmutable;
 
// =============================================================================
// TelemetryParser.php — Parser de eventos TELEMETRY
// =============================================================================
//
// Port de: st-platform/api/services/parsers/telemetry_parser.py
// Adiciones PHP (no en Python): 'low_soc', 'weak_rssi', 'charging_state',
//   'power_source' (ver secciones marcadas con [PHP+]).
//
// Formato de una línea TELEMETRY:
//
//   [2026-05-04 05:15:29] [TELEMETRY] INFO: [34:85:18:46:e3:20] TELEMETRY ->
//     id=03 name='Baliza 03 - Las Rozas' timestamp=2026-05-04 05:15:29 |
//     T=17.1C H=23.90% P=928.1hPa AQ=186.2 (WARMUP acc=1 stab=1 runin=1)
//     alt=734m | LiDAR=5405mm | SOC=100% CHARGING (AC) | RSSI=-78 |
//     LANE=1 LOC=3 POS=74000 REF=5975 SX=84.6 SY=92.6
//
// El body (todo lo que va después de ' -> ') se divide en dos partes:
//   - Header: id=NN name='...' timestamp=...
//   - Measurements y context: el resto de campos
// =============================================================================
 
final class TelemetryParser
{
    // -------------------------------------------------------------------------
    // TELEMETRY_HEADER_PATTERN
    // -------------------------------------------------------------------------
    // Extrae los tres campos del header de una línea TELEMETRY:
    //   device_id   → '03', '01', '08'  (id numérico de la baliza)
    //   name        → 'Baliza 03 - Las Rozas'
    //   firmware_ts → '2026-05-04 05:15:29'  o  'UNSYNCED'
    //
    // El separador del timestamp es '|' (el timestamp va hasta el primer '|').
    // Por eso firmware_ts usa [^|]+ (cualquier cosa excepto '|').
    // -------------------------------------------------------------------------
    private const TELEMETRY_HEADER_PATTERN = "/
        id=(?P<device_id>\d+)\s+
        name='(?P<name>[^']*)'\s+
        timestamp=(?P<firmware_ts>[^|]+)
    /x";
 
    // -------------------------------------------------------------------------
    // Patrones de extracción de medidas (measurements)
    // -------------------------------------------------------------------------
    // Cada patrón extrae un valor numérico con su unidad.
    // \b (word boundary) evita que 'AQ=186.2' capture el '86.2' de otro campo.
    //
    // T=17.1C        → temperature_c:   17.1  (puede ser negativo: T=-1.4C)
    // H=23.90%       → humidity_pct:    23.90
    // P=928.1hPa     → pressure_hpa:    928.1
    // AQ=186.2       → aq_index:        186.2
    // alt=734m       → altitude_m:      734.0
    // LiDAR=5405mm   → lidar_mm:        5405.0
    // SOC=100%       → soc_pct:         100.0
    // RSSI=-78       → rssi_dbm:        -78.0  (siempre negativo en práctica)
    // -------------------------------------------------------------------------
    private const TEMP_PATTERN   = '/\bT=(?P<value>-?\d+(?:\.\d+)?)C\b/';
    private const HUM_PATTERN    = '/\bH=(?P<value>-?\d+(?:\.\d+)?)%/';
    private const PRESS_PATTERN  = '/\bP=(?P<value>-?\d+(?:\.\d+)?)hPa\b/';
    private const AQ_PATTERN     = '/\bAQ=(?P<value>-?\d+(?:\.\d+)?)\b/';
    private const ALT_PATTERN    = '/\balt=(?P<value>-?\d+(?:\.\d+)?)m\b/';
    private const LIDAR_PATTERN  = '/\bLiDAR=(?P<value>-?\d+(?:\.\d+)?)mm\b/';
    private const SOC_PATTERN    = '/\bSOC=(?P<value>-?\d+(?:\.\d+)?)%/';
    private const RSSI_PATTERN   = '/\bRSSI=(?P<value>-?\d+(?:\.\d+)?)\b/';
 
    // -------------------------------------------------------------------------
    // Patrones de extracción de contexto posicional (context)
    // -------------------------------------------------------------------------
    // LANE=1      → lane: 1
    // LOC=3       → loc:  3  (posición en el carril)
    // POS=74000   → pos:  74000  (posición en mm desde el origen)
    // REF=5975    → ref:  5975   (referencia de calibración LiDAR)
    // SX=84.6     → sx:   84.6   (calibración X del sensor)
    // SY=92.6     → sy:   92.6
    // -------------------------------------------------------------------------
    private const LANE_PATTERN = '/\bLANE=(?P<value>-?\d+)\b/';
    private const LOC_PATTERN  = '/\bLOC=(?P<value>-?\d+)\b/';
    private const POS_PATTERN  = '/\bPOS=(?P<value>-?\d+)\b/';
    private const REF_PATTERN  = '/\bREF=(?P<value>-?\d+)\b/';
    private const SX_PATTERN   = '/\bSX=(?P<value>-?\d+(?:\.\d+)?)\b/';
    private const SY_PATTERN   = '/\bSY=(?P<value>-?\d+(?:\.\d+)?)\b/';
 
    // -------------------------------------------------------------------------
    // STATE_PATTERN — Estado del sensor AQ
    // -------------------------------------------------------------------------
    // El sensor de calidad del aire tiene dos estados:
    //   WARMUP: calentando, las lecturas AQ no son fiables todavía
    //   READY:  operativo, las lecturas son válidas
    //
    // Seguido de tres contadores:
    //   acc=1   → accuracy (precisión)
    //   stab=1  → stability (estabilidad)
    //   runin=1 → run-in (tiempo de rodaje)
    //
    // Ejemplo: '(WARMUP acc=0 stab=1 runin=0)'
    // -------------------------------------------------------------------------
    private const STATE_PATTERN = '/\b(?P<state>WARMUP|READY)\s+acc=(?P<acc>\d+)\s+stab=(?P<stab>\d+)\s+runin=(?P<runin>\d+)\b/';
 
    // -------------------------------------------------------------------------
    // [PHP+] CHARGING_PATTERN — Estado de carga de la batería
    // -------------------------------------------------------------------------
    // No existe en el Python original. Añadido en la versión PHP.
    //
    // Ejemplos:
    //   SOC=100% CHARGING (AC)       → charging_state=CHARGING, power_source=AC
    //   SOC=76%  CHARGING (BAT)      → charging_state=CHARGING, power_source=BAT
    //   SOC=82%  DISCHARGING (AC)    → charging_state=DISCHARGING, power_source=AC
    //   SOC=80%  DISCHARGING (BAT)   → charging_state=DISCHARGING, power_source=BAT
    //
    // 'AC'  = cargando vía corriente alterna (enchufado)
    // 'BAT' = gestionado por batería (puede estar cargando o descargando)
    //
    // Este patrón solapa con SOC_PATTERN, pero como los usamos por separado
    // con preg_match, no hay conflicto.
    // -------------------------------------------------------------------------
    private const CHARGING_PATTERN = '/\bSOC=-?\d+(?:\.\d+)?%\s+(?P<charging_state>CHARGING|DISCHARGING)\s+\((?P<power_source>AC|BAT)\)/';
 
    // -------------------------------------------------------------------------
    // parseTelemetryEvent
    // -------------------------------------------------------------------------
    // Parsea una línea de log completa y devuelve un array con todos los
    // campos extraídos, listos para insertar en log_events.
    //
    // Flujo:
    //   1. parseBaseLine → extrae MAC, severity, body, etc.
    //   2. TELEMETRY_HEADER_PATTERN → extrae id, name, firmware_ts del body
    //   3. Patrones individuales → extraen cada medida del body
    //   4. STATE_PATTERN → extrae estado AQ
    //   5. CHARGING_PATTERN → extrae estado de carga [PHP+]
    //   6. Detección de anomalías → rellena anomaly_flags
    //   7. Determina quality_status según las anomalías detectadas
    // -------------------------------------------------------------------------
    public static function parseTelemetryEvent(string $rawLine, int $lineNumber): array
    {
        // Paso 1: parseo base (MAC, severity, body, etc.)
        $base = BaseLineParser::parseBaseLine($rawLine, $lineNumber);
 
        // Si el parseo base falló (línea malformada), devolvemos el error tal cual.
        if (!$base['parse_ok']) {
            return $base;
        }
 
        $body = $base['body'];
 
        // Paso 2: header TELEMETRY (id, name, firmware_ts)
        $headerMatched = preg_match(self::TELEMETRY_HEADER_PATTERN, $body, $headerMatches);
 
        if ($headerMatched !== 1) {
            return Common::buildBaseInvalidEvent(
                rawLine: $rawLine,
                lineNumber: $lineNumber,
                parseError: 'telemetry_header_no_match',
            );
        }
 
        $firmwareTsRaw  = trim($headerMatches['firmware_ts']);
        $deviceName     = trim($headerMatches['name']);
        $deviceExternalId = trim($headerMatches['device_id']);
 
        // Paso 3: extraer medidas individuales
        // self::extract() aplica un patrón al body y devuelve el string del
        // grupo 'value', o null si no hay coincidencia.
        $measurements = [
            'temperature_c'  => Common::parseFloatOrNull(self::extract(self::TEMP_PATTERN, $body)),
            'humidity_pct'   => Common::parseFloatOrNull(self::extract(self::HUM_PATTERN, $body)),
            'pressure_hpa'   => Common::parseFloatOrNull(self::extract(self::PRESS_PATTERN, $body)),
            'aq_index'       => Common::parseFloatOrNull(self::extract(self::AQ_PATTERN, $body)),
            'altitude_m'     => Common::parseFloatOrNull(self::extract(self::ALT_PATTERN, $body)),
            'lidar_mm'       => Common::parseFloatOrNull(self::extract(self::LIDAR_PATTERN, $body)),
            'soc_pct'        => Common::parseFloatOrNull(self::extract(self::SOC_PATTERN, $body)),
            'rssi_dbm'       => Common::parseFloatOrNull(self::extract(self::RSSI_PATTERN, $body)),
        ];
 
        // Paso 4: estado del sensor AQ
        $stateMatched = preg_match(self::STATE_PATTERN, $body, $stateMatches);
 
        // Paso 5: [PHP+] estado de carga
        $chargingMatched = preg_match(self::CHARGING_PATTERN, $body, $chargingMatches);
 
        // Contexto posicional + estado del sensor + estado de carga
        $context = [
            'lane'           => Common::parseIntOrNull(self::extract(self::LANE_PATTERN, $body)),
            'loc'            => Common::parseIntOrNull(self::extract(self::LOC_PATTERN, $body)),
            'pos'            => Common::parseIntOrNull(self::extract(self::POS_PATTERN, $body)),
            'ref'            => Common::parseIntOrNull(self::extract(self::REF_PATTERN, $body)),
            'sx'             => Common::parseFloatOrNull(self::extract(self::SX_PATTERN, $body)),
            'sy'             => Common::parseFloatOrNull(self::extract(self::SY_PATTERN, $body)),
 
            // Estado del sensor AQ: null si el patrón no coincidió
            'state'          => $stateMatched === 1 ? $stateMatches['state'] : null,
            'acc'            => $stateMatched === 1 ? Common::parseIntOrNull($stateMatches['acc']) : null,
            'stab'           => $stateMatched === 1 ? Common::parseIntOrNull($stateMatches['stab']) : null,
            'runin'          => $stateMatched === 1 ? Common::parseIntOrNull($stateMatches['runin']) : null,
 
            // [PHP+] Estado de carga: null si el patrón no coincidió
            'charging_state' => $chargingMatched === 1 ? $chargingMatches['charging_state'] : null,
            'power_source'   => $chargingMatched === 1 ? $chargingMatches['power_source'] : null,
        ];
 
        // Paso 6: detección de anomalías
        $anomalyFlags = [];
 
        // Timestamp no sincronizado: el reloj de la baliza no está ajustado.
        // Ocurre tras un reinicio hasta que sincroniza con NTP o GPS.
        if (strtoupper($firmwareTsRaw) === 'UNSYNCED') {
            $anomalyFlags[] = 'unsynced_timestamp';
        }
 
        // Nombre placeholder: la baliza no tiene nombre configurado.
        if (Common::isPlaceholderName($deviceName)) {
            $anomalyFlags[] = 'placeholder_name';
        }
 
        // Sensor payload todo a cero: probablemente la baliza acaba de arrancar
        // y los sensores aún no han iniciado correctamente.
        if (
            $measurements['temperature_c'] === 0.0
            && $measurements['humidity_pct'] === 0.0
            && $measurements['pressure_hpa'] === 0.0
            && $measurements['aq_index'] === 0.0
        ) {
            $anomalyFlags[] = 'zero_sensor_payload';
        }
 
        // Presión fuera de rango físico (0-5000m de altitud → ~530-1090 hPa
        // aproximadamente). Un margen de 800-1200 detecta anomalías evidentes
        // sin ser demasiado estricto.
        $pressure = $measurements['pressure_hpa'];
        if ($pressure !== null && ($pressure < 800.0 || $pressure > 1200.0)) {
            $anomalyFlags[] = 'out_of_range_pressure';
        }
 
        // Altitud fuera de rango (las instalaciones conocidas están a <1000m).
        // El límite de 5000m es generoso pero detecta valores de firmware
        // buggy que emiten altitudes de 6834m (visto en datos reales de W16).
        $altitude = $measurements['altitude_m'];
        if ($altitude !== null && ($altitude < -500.0 || $altitude > 5000.0)) {
            $anomalyFlags[] = 'out_of_range_altitude';
        }
 
        // [PHP+] Batería crítica: SOC < 20%. En campo, las balizas deberían
        // estar siempre cargadas. Un SOC bajo puede indicar un problema de
        // alimentación que requiere atención.
        $soc = $measurements['soc_pct'];
        if ($soc !== null && $soc < 20.0) {
            $anomalyFlags[] = 'low_soc';
        }
 
        // [PHP+] Señal WiFi/BLE débil: RSSI < -90 dBm. Una señal muy débil
        // puede causar pérdida de paquetes y datos incompletos.
        $rssi = $measurements['rssi_dbm'];
        if ($rssi !== null && $rssi < -90.0) {
            $anomalyFlags[] = 'weak_rssi';
        }
 
        // Paso 7: quality_status
        // 'valid'  → sin anomalías
        // 'suspect' → hay anomalías pero el parseo fue correcto
        $qualityStatus = $anomalyFlags === [] ? 'valid' : 'suspect';
 
        // event_timestamp: usamos el timestamp del firmware si está disponible.
        // Si es UNSYNCED, usamos el timestamp del bridge (received_timestamp_raw)
        // como fallback. El flag 'unsynced_timestamp' documenta esto.
        $firmwareTimestamp = Common::parseDatetimeOrNull($firmwareTsRaw);
        $receivedTimestamp = Common::parseDatetimeOrNull($base['received_timestamp_raw']);
        $eventTimestamp = $firmwareTimestamp ?? $receivedTimestamp;
 
        return [
            'line_number'            => $lineNumber,
            'parse_ok'               => true,
            'parse_error'            => null,
 
            // La severidad la devuelve el firmware como 'info' (lowercase,
            // ya normalizado por BaseLineParser). SeverityDeriver la elevará
            // a 'warn'/'error' si hay anomaly_flags que lo justifiquen.
            'severity'               => $base['severity'],
 
            'event_type'             => 'telemetry',
            'event_category'         => 'telemetry',
 
            'source_tag'             => $base['source_tag'],
            'device_mac'             => $base['device_mac'],
            'device_external_id'     => $deviceExternalId,
            'device_name_reported'   => $deviceName !== '' ? $deviceName : null,
 
            'event_timestamp'        => $eventTimestamp,    // DateTimeImmutable|null
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
    // extract — Aplica un patrón y devuelve el string del grupo 'value'
    // -------------------------------------------------------------------------
    // Método auxiliar privado usado por parseTelemetryEvent.
    // Aplica $pattern al $text y devuelve el contenido del grupo capturado
    // 'value', o null si no hay coincidencia.
    //
    // Ejemplo:
    //   extract('/\bT=(?P<value>-?\d+(?:\.\d+)?)C\b/', 'T=17.1C H=23%')
    //   → '17.1'
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