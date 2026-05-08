<?php
 
declare(strict_types=1);
 
namespace App\Parsers;
 
use DateTimeImmutable;
 
// =============================================================================
// Common.php — Utilidades compartidas por todos los parsers
// =============================================================================
//
// Port de: st-platform/api/services/parsers/common.py
//
// Esta clase contiene funciones puras (sin estado, sin BD) que todos los
// parsers usan para:
//   - Convertir strings capturados por regex a tipos PHP (float, int, DateTime)
//   - Detectar nombres placeholder (balizas no configuradas)
//   - Construir el array base para eventos que no se han podido parsear
//
// Por qué `final`: no queremos herencia. Son utilidades estáticas puras.
// =============================================================================
 
final class Common
{

    private const PLACEHOLDER_NAMES = ['UNKNOWN', 'PENDING', ''];
 
    // -------------------------------------------------------------------------
    // parseFloatOrNull
    // -------------------------------------------------------------------------
    // Convierte un valor capturado por regex a float, o devuelve null si no
    // es convertible.
    //
    // Por qué no usar (float) directamente: PHP convierte silenciosamente
    // strings vacíos y 'UNSYNCED' a 0.0, lo que generaría falsos valores en
    // measurements. Este método es explícito.
    //
    // Ejemplos:
    //   '26.7'   → 26.7
    //   '-1.4'   → -1.4
    //   ''       → null
    //   'WARMUP' → null
    //   null     → null
    // -------------------------------------------------------------------------
    public static function parseFloatOrNull(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
 
        $str = trim((string) $value);
 
        if ($str === '') {
            return null;
        }

        if (!is_numeric($str)) {
            return null;
        }
 
        return (float) $str;
    }
 
    // -------------------------------------------------------------------------
    // parseIntOrNull
    // -------------------------------------------------------------------------
    // Convierte un valor capturado por regex a int, o devuelve null.
    //
    // Ejemplos:
    //   '1'    → 1
    //   '-90'  → -90
    //   '1.5'  → null (no es un entero limpio)
    //   ''     → null
    // -------------------------------------------------------------------------
    public static function parseIntOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
 
        $str = trim((string) $value);
 
        if ($str === '') {
            return null;
        }
 
        if (!ctype_digit(ltrim($str, '-'))) {
            return null;
        }
 
        return (int) $str;
    }
 
    // -------------------------------------------------------------------------
    // parseDatetimeOrNull
    // -------------------------------------------------------------------------
    // Convierte un timestamp raw del firmware a DateTimeImmutable, o null.
    //
    // Casos especiales:
    //   - 'UNSYNCED': el firmware no tiene el reloj sincronizado → null
    //   - Formato esperado: 'YYYY-MM-DD HH:MM:SS'
    //
    // El caller (TelemetryParser, SpeedParser) decide qué hacer con null:
    // generalmente usa el received_timestamp_raw del bridge como fallback
    // y añade 'unsynced_timestamp' a anomaly_flags.
    // -------------------------------------------------------------------------
    public static function parseDatetimeOrNull(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
 
        $str = trim((string) $value);
 
        if ($str === '' || strtoupper($str) === 'UNSYNCED') {
            return null;
        }
 
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $str);
 
        return $dt !== false ? $dt : null;
    }
 
    // -------------------------------------------------------------------------
    // isPlaceholderName
    // -------------------------------------------------------------------------
    // Devuelve true si el nombre de la baliza es un placeholder (no configurada).
    //
    // El firmware emite name='UNKNOWN' o name='' cuando la baliza aún no tiene
    // un nombre asignado en el sistema. Esto se marca como anomaly_flag
    // 'placeholder_name' para que el operador sepa que hay una baliza sin
    // identificar.
    // -------------------------------------------------------------------------
    public static function isPlaceholderName(?string $name): bool
    {
        if ($name === null) {
            return true;
        }
 
        return in_array(strtoupper(trim($name)), self::PLACEHOLDER_NAMES, true);
    }
 
    // -------------------------------------------------------------------------
    // buildBaseInvalidEvent
    // -------------------------------------------------------------------------
    // Construye el array de retorno cuando una línea no se puede parsear.
    //
    // Todos los parsers devuelven arrays con la misma estructura, tanto si
    // el parseo fue bien (parse_ok=true) como si falló (parse_ok=false).
    // Esto permite que el caller los trate uniformemente sin comprobar si
    // existe cada clave.
    //
    // Los arrays devueltos por los parsers se insertan directamente en
    // log_events después de pasar por SeverityDeriver y DeviceResolver.
    // -------------------------------------------------------------------------
    public static function buildBaseInvalidEvent(
        string $rawLine,
        int $lineNumber,
        string $parseError
    ): array {
        return [
            'line_number'            => $lineNumber,
            'parse_ok'               => false,
            'parse_error'            => $parseError,
            'severity'               => 'unknown',
            'event_type'             => 'unknown',
            'event_category'         => 'unknown',
            'source_tag'             => null,
            'device_mac'             => null,
            'device_external_id'     => null,
            'device_name_reported'   => null,
            'event_timestamp'        => null,       
            'firmware_timestamp_raw' => null,
            'received_timestamp_raw' => null,
            'measurements'           => [],
            'context'                => [],
            'quality_status'         => 'invalid',
            'anomaly_flags'          => [],
            'message_text'           => $rawLine,
            'raw_line'               => $rawLine,
        ];
    }
}