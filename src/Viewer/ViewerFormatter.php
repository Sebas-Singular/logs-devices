<?php

declare(strict_types=1);

namespace App\Viewer;

final class ViewerFormatter
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function nullable(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? '—' : self::e($text);
    }

    public static function datetime(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? '—' : self::e($text);
    }

    public static function number(mixed $value): string
    {
        return number_format((int) $value, 0, ',', '.');
    }

    public static function shortText(mixed $value, int $maxLength = 160): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return '—';
        }

        $length = function_exists('mb_strlen')
            ? mb_strlen($text, 'UTF-8')
            : strlen($text);

        if ($length <= $maxLength) {
            return self::e($text);
        }

        $short = function_exists('mb_substr')
            ? mb_substr($text, 0, $maxLength, 'UTF-8')
            : substr($text, 0, $maxLength);

        return self::e($short . '…');
    }

    public static function severityLabel(string $severity): string
    {
        return match ($severity) {
            'critical' => 'Crítico',
            'error' => 'Error',
            'warn' => 'Aviso',
            'info' => 'Info',
            'unknown' => 'Sin definir',
            default => ucfirst($severity),
        };
    }

    /**
     * Origen de la severidad: la reportó el firmware o la dedujo el pipeline.
     */
    public static function severityOriginLabel(mixed $origin): string
    {
        return match (trim((string) ($origin ?? ''))) {
            'reported' => 'reportada',
            'derived' => 'deducida',
            '' => '—',
            default => (string) $origin,
        };
    }

    /**
     * Estado de procesamiento de una ingesta.
     *
     * Los valores de la columna son los de la BD ('received', 'parsing'...);
     * aquí solo se traduce lo que ve el usuario.
     */
    public static function ingestStatusLabel(string $status): string
    {
        return match ($status) {
            'received' => 'Recibida',
            'parsing' => 'Procesando',
            'processed' => 'Procesada',
            'error' => 'Error',
            default => ucfirst($status),
        };
    }

    public static function ingestStatusBadgeClass(string $status): string
    {
        return match ($status) {
            'processed' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            'parsing' => 'bg-amber-50 text-amber-800 ring-amber-200',
            'received' => 'bg-ink-100 text-ink-700 ring-ink-200',
            'error' => 'bg-red-50 text-red-800 ring-red-200',
            default => 'bg-ink-100 text-ink-700 ring-ink-200',
        };
    }

    public static function severityBadgeClass(string $severity): string
    {
        return match ($severity) {
            'critical' => 'bg-red-900 text-white ring-red-900',
            'error' => 'bg-red-50 text-red-800 ring-red-200',
            'warn' => 'bg-amber-50 text-amber-800 ring-amber-200',
            'info' => 'bg-ink-50 text-ink-600 ring-ink-200',
            default => 'bg-ink-50 text-ink-500 ring-ink-200',
        };
    }

    public static function parseBadgeClass(bool $parseOk): string
    {
        return $parseOk
            ? 'bg-emerald-50 text-emerald-800 ring-emerald-200'
            : 'bg-red-50 text-red-800 ring-red-200';
    }

    /**
     * Calidad del dato calculada en la ingesta.
     *
     * El pipeline marca cada evento como valid / suspect / invalid / duplicate
     * y acumula banderas de anomalía, pero hasta ahora el visor no mostraba
     * ninguna de las dos cosas.
     */
    public static function qualityLabel(string $status): string
    {
        return match ($status) {
            'valid' => 'Válido',
            'suspect' => 'Sospechoso',
            'invalid' => 'Inválido',
            'duplicate' => 'Duplicado',
            default => ucfirst($status),
        };
    }

    public static function qualityBadgeClass(string $status): string
    {
        return match ($status) {
            'valid' => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
            'suspect' => 'bg-amber-100 text-amber-800 ring-amber-200',
            'invalid' => 'bg-red-100 text-red-800 ring-red-200',
            'duplicate' => 'bg-ink-100 text-ink-700 ring-ink-200',
            default => 'bg-ink-100 text-ink-700 ring-ink-200',
        };
    }

    /**
     * Traducción de las banderas de anomalía que emiten parsers y normalizers.
     */
    public static function anomalyLabel(string $flag): string
    {
        return match ($flag) {
            'time_fallback' => 'Timestamp deducido (el evento no traía hora fiable)',
            'unsynced_timestamp' => 'Reloj del firmware sin sincronizar',
            'gps_no_signal' => 'GPS sin señal',
            'gps_signal_lost' => 'GPS perdió la señal',
            'improbable_speed' => 'Velocidad físicamente improbable',
            'low_soc' => 'Batería baja',
            'weak_rssi' => 'Enlace débil con el bridge',
            'out_of_range_pressure' => 'Presión fuera de rango',
            'out_of_range_altitude' => 'Altitud fuera de rango',
            'zero_sensor_payload' => 'Todos los sensores a cero',
            'placeholder_name' => 'Nombre de dispositivo sin configurar',
            'invalid_structured_event' => 'Evento estructurado mal formado',
            'invalid_vehicle_event' => 'Evento de vehículo mal formado',
            default => str_replace('_', ' ', $flag),
        };
    }

    /**
     * Decodifica la columna anomaly_flags a una lista de banderas.
     *
     * @return list<string>
     */
    public static function anomalyFlags(mixed $value): array
    {
        $decoded = self::decodeJsonMap($value);

        $flags = [];

        foreach ($decoded as $flag) {
            if (is_scalar($flag) && trim((string) $flag) !== '') {
                $flags[] = trim((string) $flag);
            }
        }

        return $flags;
    }

    public static function deviceKindLabel(string $kind): string
    {
        return match ($kind) {
            'bridge' => 'Bridge',
            'baliza' => 'Baliza',
            default => ucfirst($kind),
        };
    }

    public static function deviceKindBadgeClass(string $kind): string
    {
        return match ($kind) {
            'bridge' => 'bg-brand-50 text-brand-700 ring-brand-200',
            'baliza' => 'bg-ink-100 text-ink-700 ring-ink-200',
            default => 'bg-ink-50 text-ink-500 ring-ink-200',
        };
    }

    /**
     * Resumen de métricas de un evento, legible en cualquier generación de datos.
     *
     * Antes esta función solo entendía las claves camelCase del formato
     * estructurado, así que todo evento parseado por los parsers legacy
     * (speed_kmh, temperature_c, soc_pct...) se mostraba sin resumen.
     * Ahora resuelve contra MetricCatalog, que prueba los alias en orden de
     * vigencia y devuelve el valor de la clave más actual disponible.
     */
    public static function eventSummary(array $event): ?string
    {
        $measurements = self::decodeJsonMap($event['measurements'] ?? null);
        $context = self::decodeJsonMap($event['context'] ?? null);
        $tags = is_array($context['tags'] ?? null) ? $context['tags'] : [];
        $category = (string) ($event['event_category'] ?? 'unknown');

        $parts = [];

        // Métricas propias de la categoría, en el orden del catálogo.
        foreach (MetricCatalog::forCategory($category) as $canonical) {
            $sample = MetricCatalog::resolve($canonical, $measurements);

            if ($sample === null) {
                continue;
            }

            $parts[] = self::metricPart($canonical, $sample['value']);
        }

        // Categoría desconocida o sin métricas declaradas: mostrar lo que haya
        // en el evento en lugar de dejar la fila muda.
        if ($parts === []) {
            foreach (MetricCatalog::resolveAll($measurements) as $canonical => $sample) {
                $parts[] = self::metricPart($canonical, $sample['value']);

                if (count($parts) >= 4) {
                    break;
                }
            }
        }

        // Claves que el firmware ya envía pero el catálogo no conoce todavía.
        foreach (MetricCatalog::unknownScalars($measurements) as $key => $value) {
            if (count($parts) >= 6) {
                break;
            }

            $parts[] = $key . '=' . trim((string) $value);
        }

        // Estados textuales relevantes (carga, IAQ, estado dinámico...).
        foreach (MetricCatalog::tagLabels() as $tagKey => $tagLabel) {
            if (count($parts) >= 8) {
                break;
            }

            $value = $tags[$tagKey] ?? null;

            if (!is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $parts[] = $tagLabel . '=' . trim((string) $value);
        }

        // Estado del GPS, que vive anidado dentro de measurements.
        $gps = $measurements['gps'] ?? null;

        if (is_array($gps) && count($parts) < 8) {
            if (array_key_exists('error', $gps) && (int) $gps['error'] !== 0) {
                $parts[] = 'GPS=error ' . (string) $gps['error'];
            } elseif (isset($gps['latitude'], $gps['longitude'])) {
                $parts[] = 'GPS fix';
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode(' · ', array_slice($parts, 0, 8));
    }

    /**
     * Parte humana de message_text, sin el resumen de métricas que el
     * normalizador le añade al final.
     *
     * La ingesta compone message_text como "mensaje · T=21.4C · H=45.8%", y el
     * visor ya calcula ese mismo resumen desde `measurements` -mejor, porque
     * entiende las dos generaciones de claves y traduce las unidades-. Mostrar
     * ambos duplicaba los datos en cada fila.
     *
     * Se recortan por la cola los segmentos con forma de par métrica=valor. Los
     * mensajes legacy son la línea de log cruda, sin separadores ' · ', y salen
     * intactos. Si el mensaje era SOLO métricas, devuelve cadena vacía: la vista
     * enseña el resumen y nada más.
     */
    public static function messageWithoutMetrics(mixed $message): string
    {
        $text = trim((string) ($message ?? ''));

        if ($text === '' || !str_contains($text, ' · ')) {
            return $text;
        }

        $segments = explode(' · ', $text);

        while ($segments !== [] && self::looksLikeMetricPart(end($segments))) {
            array_pop($segments);
        }

        return trim(implode(' · ', $segments));
    }

    /**
     * ¿Este segmento es un par "Etiqueta=valor" de los que genera el resumen?
     */
    private static function looksLikeMetricPart(string $segment): bool
    {
        $segment = trim($segment);

        if ($segment === '' || $segment === 'GPS fix') {
            return true;
        }

        // Etiqueta corta (puede llevar un espacio, como "Vel final"), '=' sin
        // espacios alrededor y un valor pegado. Un texto humano con '=' rara vez
        // encaja: exige que la etiqueta no pase de 14 caracteres.
        return preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._-]{0,13}=\S+$/u', $segment) === 1;
    }

    private static function metricPart(string $canonical, float $value): string
    {
        $unit = MetricCatalog::unit($canonical);

        return MetricCatalog::shortLabel($canonical)
            . '='
            . MetricCatalog::formatValue($canonical, $value)
            . ($unit ?? '');
    }

    /**
     * Antigüedad legible de una fecha de la base de datos.
     */
    public static function relativeTime(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return '—';
        }

        $timestamp = strtotime($text);

        if ($timestamp === false) {
            return '—';
        }

        $minutes = (int) floor((time() - $timestamp) / 60);

        if ($minutes < 0) {
            return 'ahora mismo';
        }

        return 'hace ' . DashboardQueries::humanizeMinutes($minutes);
    }

    /**
     * Presentación del estado de salud calculado por DashboardQueries::health().
     *
     * @return array{label: string, text: string, dot: string, card: string}
     */
    /**
     * Presentación del estado de salud calculado por DashboardQueries::health().
     *
     * Devuelve la señal cromática como un rail lateral en vez de teñir la
     * tarjeta entera: el color informa sin gritar, y la tarjeta mantiene el
     * mismo fondo blanco que el resto del panel.
     *
     * @return array{label: string, text: string, dot: string, rail: string}
     */
    public static function healthPresentation(string $status): array
    {
        return match ($status) {
            'operativo' => [
                'label' => 'Operativo',
                'text' => 'text-emerald-700',
                'dot' => 'bg-emerald-500',
                'rail' => 'bg-emerald-500',
            ],
            'degradado' => [
                'label' => 'Degradado',
                'text' => 'text-amber-700',
                'dot' => 'bg-amber-500',
                'rail' => 'bg-amber-500',
            ],
            'caido' => [
                'label' => 'Sin señal',
                'text' => 'text-red-700',
                'dot' => 'bg-red-500',
                'rail' => 'bg-red-600',
            ],
            default => [
                'label' => 'Sin datos',
                'text' => 'text-ink-500',
                'dot' => 'bg-ink-400',
                'rail' => 'bg-ink-300',
            ],
        };
    }

    private static function decodeJsonMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
