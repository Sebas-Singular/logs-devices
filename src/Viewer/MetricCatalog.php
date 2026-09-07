<?php

declare(strict_types=1);

namespace App\Viewer;

/**
 * Catálogo único de métricas del sistema.
 *
 * El histórico contiene dos generaciones de claves para las mismas magnitudes:
 *
 *   - Formato estructurado actual (events[] del bridge):  speedKmh, temperatureC, socPct...
 *   - Parsers legacy sobre logText (regex):               speed_kmh, temperature_c, soc_pct...
 *
 * Cada métrica declara sus alias en orden de vigencia: el primero es la clave
 * vigente, los siguientes son formatos anteriores. resolve() devuelve siempre
 * el valor del alias más actual presente en el evento, de modo que un evento
 * de 2025 y uno de hoy se muestran con la misma etiqueta y la misma unidad.
 *
 * Añadir una métrica nueva = añadir una entrada aquí. El dashboard la muestra
 * sola en cuanto llega el primer evento que la contenga.
 */
final class MetricCatalog
{
    /**
     * @var array<string, array{
     *     label: string,
     *     short: string,
     *     unit: ?string,
     *     decimals: ?int,
     *     group: string,
     *     aliases: list<string>,
     *     categories: list<string>,
     *     featured: bool
     * }>
     */
    private const METRICS = [
        // --- Velocidad y dinámica de vehículo -------------------------------
        'speed' => [
            'label' => 'Velocidad',
            'short' => 'Vel',
            'unit' => 'km/h',
            'decimals' => 1,
            'group' => 'trafico',
            'aliases' => ['speedKmh', 'speed_kmh'],
            'categories' => ['speed'],
            'featured' => true,
        ],
        'final_speed' => [
            'label' => 'Velocidad final',
            'short' => 'Vel final',
            'unit' => 'km/h',
            'decimals' => 1,
            'group' => 'trafico',
            'aliases' => ['finalSpeedKmh'],
            'categories' => ['vehicle'],
            'featured' => true,
        ],
        'previous_speed' => [
            'label' => 'Velocidad previa',
            'short' => 'Vel prev',
            'unit' => 'km/h',
            'decimals' => 1,
            'group' => 'trafico',
            'aliases' => ['previousSpeedKmh'],
            'categories' => ['vehicle'],
            'featured' => false,
        ],
        'acceleration' => [
            'label' => 'Aceleración',
            'short' => 'Accel',
            'unit' => 'm/s²',
            'decimals' => 2,
            'group' => 'trafico',
            'aliases' => ['accelerationMps2'],
            'categories' => ['vehicle'],
            'featured' => false,
        ],

        // --- Ambiental ------------------------------------------------------
        'temperature' => [
            'label' => 'Temperatura',
            'short' => 'T',
            'unit' => '°C',
            'decimals' => 1,
            'group' => 'ambiental',
            'aliases' => ['temperatureC', 'temperature_c'],
            'categories' => ['telemetry'],
            'featured' => true,
        ],
        'humidity' => [
            'label' => 'Humedad',
            'short' => 'H',
            'unit' => '%',
            'decimals' => 1,
            'group' => 'ambiental',
            'aliases' => ['humidityPct', 'humidity_pct'],
            'categories' => ['telemetry'],
            'featured' => true,
        ],
        'pressure' => [
            'label' => 'Presión',
            'short' => 'P',
            'unit' => 'hPa',
            'decimals' => 1,
            'group' => 'ambiental',
            'aliases' => ['pressureHpa', 'pressure_hpa'],
            'categories' => ['telemetry'],
            'featured' => false,
        ],
        'air_quality' => [
            'label' => 'Calidad del aire',
            'short' => 'AQ',
            'unit' => null,
            'decimals' => 1,
            'group' => 'ambiental',
            'aliases' => ['airQuality', 'aq_index'],
            'categories' => ['telemetry'],
            'featured' => true,
        ],
        'altitude' => [
            'label' => 'Altitud',
            'short' => 'Alt',
            'unit' => 'm',
            'decimals' => 0,
            'group' => 'ambiental',
            'aliases' => ['altitudeM', 'altitude_m'],
            'categories' => ['telemetry'],
            'featured' => false,
        ],

        // --- Energía y enlace ------------------------------------------------
        'soc' => [
            'label' => 'Batería',
            'short' => 'SOC',
            'unit' => '%',
            'decimals' => 0,
            'group' => 'energia',
            'aliases' => ['socPct', 'soc_pct'],
            'categories' => ['telemetry'],
            'featured' => true,
        ],
        'rssi' => [
            'label' => 'RSSI enlace',
            'short' => 'RSSI',
            'unit' => 'dBm',
            'decimals' => 0,
            'group' => 'energia',
            'aliases' => ['parentRssi', 'rssi_dbm'],
            'categories' => ['telemetry'],
            'featured' => true,
        ],

        // --- Sensor y posición -----------------------------------------------
        'lidar' => [
            'label' => 'LiDAR',
            'short' => 'LiDAR',
            'unit' => 'mm',
            'decimals' => 0,
            'group' => 'sensor',
            'aliases' => ['lidarDistanceMm', 'lidar_mm'],
            'categories' => ['telemetry'],
            'featured' => true,
        ],
        'distance' => [
            'label' => 'Distancia',
            'short' => 'Dist',
            'unit' => 'mm',
            'decimals' => 0,
            'group' => 'trafico',
            'aliases' => ['distanceMm', 'distance_mm'],
            'categories' => ['speed', 'vehicle'],
            'featured' => false,
        ],
        'position' => [
            'label' => 'Posición baliza',
            'short' => 'Pos',
            'unit' => 'm',
            'decimals' => 2,
            'group' => 'trafico',
            'aliases' => ['positionM', 'position_m'],
            'categories' => ['speed'],
            'featured' => false,
        ],
        'position_mm' => [
            'label' => 'Posición vía',
            'short' => 'PosMm',
            'unit' => 'mm',
            'decimals' => 0,
            'group' => 'sensor',
            'aliases' => ['positionMm', 'pos'],
            'categories' => ['telemetry'],
            'featured' => false,
        ],
        'reference' => [
            'label' => 'Referencia LiDAR',
            'short' => 'Ref',
            'unit' => 'mm',
            'decimals' => 0,
            'group' => 'sensor',
            'aliases' => ['referenceMm', 'ref'],
            'categories' => ['telemetry'],
            'featured' => false,
        ],
        'lane' => [
            'label' => 'Carril',
            'short' => 'Carril',
            'unit' => null,
            'decimals' => 0,
            'group' => 'trafico',
            'aliases' => ['lane'],
            'categories' => ['telemetry', 'speed'],
            'featured' => false,
        ],
        'location' => [
            'label' => 'Localización',
            'short' => 'Loc',
            'unit' => null,
            'decimals' => 0,
            'group' => 'trafico',
            'aliases' => ['locationId', 'loc'],
            'categories' => ['telemetry', 'speed'],
            'featured' => false,
        ],
        'servo_x' => [
            'label' => 'Servo X',
            'short' => 'SX',
            'unit' => '°',
            'decimals' => 1,
            'group' => 'sensor',
            'aliases' => ['servoXDeg', 'sx'],
            'categories' => ['telemetry'],
            'featured' => false,
        ],
        'servo_y' => [
            'label' => 'Servo Y',
            'short' => 'SY',
            'unit' => '°',
            'decimals' => 1,
            'group' => 'sensor',
            'aliases' => ['servoYDeg', 'sy'],
            'categories' => ['telemetry'],
            'featured' => false,
        ],

        // --- Estado del sensor de aire ---------------------------------------
        'iaq_accuracy' => [
            'label' => 'Precisión IAQ',
            'short' => 'IAQacc',
            'unit' => null,
            'decimals' => 0,
            'group' => 'ambiental',
            'aliases' => ['iaqAccuracy'],
            'categories' => ['telemetry'],
            'featured' => false,
        ],

        // --- Integración HTTP -------------------------------------------------
        'http_status' => [
            'label' => 'Estado HTTP',
            'short' => 'HTTP',
            'unit' => null,
            'decimals' => 0,
            'group' => 'integracion',
            'aliases' => ['status'],
            'categories' => ['https'],
            'featured' => false,
        ],
    ];

    /**
     * Grupos por tipo de información que aporta la métrica.
     *
     * El panel segmenta las lecturas en vivo con esto: mirar la salud de un
     * nodo y mirar el tráfico que mide son dos lecturas distintas, y mezclar
     * batería con velocidad en una única rejilla no permite ninguna de las dos.
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        'trafico' => 'Tráfico y dinámica',
        'ambiental' => 'Condiciones ambientales',
        'energia' => 'Energía y enlace',
        'sensor' => 'Sensor y calibración',
        'integracion' => 'Integración',
    ];

    /**
     * Estados textuales (tags) que merecen mostrarse junto a las métricas.
     *
     * @var array<string, string>
     */
    private const TAGS = [
        'chargingState' => 'Carga',
        'powerSource' => 'Alim',
        'iaqState' => 'IAQ',
        'dynamicState' => 'Estado',
        'quality' => 'Calidad',
        'mode' => 'Modo',
        'notificationType' => 'Notif',
        'protocol' => 'Proto',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::METRICS;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function definition(string $canonical): ?array
    {
        return self::METRICS[$canonical] ?? null;
    }

    /**
     * Todas las claves conocidas por el catálogo, en cualquier generación.
     *
     * @return list<string>
     */
    public static function knownKeys(): array
    {
        $keys = [];

        foreach (self::METRICS as $metric) {
            foreach ($metric['aliases'] as $alias) {
                $keys[$alias] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Resuelve una métrica canónica sobre un mapa de measurements.
     *
     * Recorre los alias en orden de vigencia y devuelve el primero presente,
     * indicando si el valor vino de una clave de formato antiguo.
     *
     * @return array{value: float, key: string, legacy: bool}|null
     */
    public static function resolve(string $canonical, array $measurements): ?array
    {
        $metric = self::METRICS[$canonical] ?? null;

        if ($metric === null) {
            return null;
        }

        foreach ($metric['aliases'] as $index => $alias) {
            if (!array_key_exists($alias, $measurements)) {
                continue;
            }

            $value = self::numericOrNull($measurements[$alias]);

            if ($value === null) {
                continue;
            }

            return [
                'value' => $value,
                'key' => $alias,
                'legacy' => $index > 0,
            ];
        }

        return null;
    }

    /**
     * Resuelve todas las métricas del catálogo presentes en un evento.
     *
     * @return array<string, array{value: float, key: string, legacy: bool}>
     */
    public static function resolveAll(array $measurements): array
    {
        $resolved = [];

        foreach (array_keys(self::METRICS) as $canonical) {
            $sample = self::resolve($canonical, $measurements);

            if ($sample !== null) {
                $resolved[$canonical] = $sample;
            }
        }

        return $resolved;
    }

    /**
     * Claves de measurements que el catálogo no conoce todavía.
     *
     * Sirve para que el visor muestre campos nuevos del firmware sin esperar
     * a que alguien los añada aquí.
     *
     * @return array<string, scalar>
     */
    public static function unknownScalars(array $measurements): array
    {
        $known = array_flip(self::knownKeys());
        $extra = [];

        foreach ($measurements as $key => $value) {
            if (isset($known[$key]) || !is_scalar($value) || $value === '') {
                continue;
            }

            $extra[(string) $key] = $value;
        }

        return $extra;
    }

    /**
     * @return array<string, string>
     */
    public static function tagLabels(): array
    {
        return self::TAGS;
    }

    public static function tagLabel(string $key): ?string
    {
        return self::TAGS[$key] ?? null;
    }

    /**
     * @return array<string, string> clave de grupo => etiqueta legible
     */
    public static function groups(): array
    {
        return self::GROUPS;
    }

    public static function groupLabel(string $group): string
    {
        return self::GROUPS[$group] ?? $group;
    }

    public static function groupOf(string $canonical): ?string
    {
        return self::METRICS[$canonical]['group'] ?? null;
    }

    /**
     * Reparte un mapa de métricas resueltas en sus grupos, en el orden de
     * GROUPS y, dentro de cada grupo, en el orden del catálogo.
     *
     * @param array<string, mixed> $resolved
     * @return array<string, array<string, mixed>>
     */
    public static function groupBy(array $resolved): array
    {
        $grouped = [];

        foreach (array_keys(self::GROUPS) as $group) {
            foreach ($resolved as $canonical => $value) {
                if (self::groupOf((string) $canonical) === $group) {
                    $grouped[$group][$canonical] = $value;
                }
            }
        }

        // Métricas sin grupo declarado: no se pierden, van al final.
        foreach ($resolved as $canonical => $value) {
            if (self::groupOf((string) $canonical) === null) {
                $grouped['otras'][$canonical] = $value;
            }
        }

        return $grouped;
    }

    public static function label(string $canonical): string
    {
        return (string) (self::METRICS[$canonical]['label'] ?? $canonical);
    }

    public static function shortLabel(string $canonical): string
    {
        return (string) (self::METRICS[$canonical]['short'] ?? $canonical);
    }

    public static function unit(string $canonical): ?string
    {
        return self::METRICS[$canonical]['unit'] ?? null;
    }

    /**
     * Valor formateado sin unidad.
     */
    public static function formatValue(string $canonical, float $value): string
    {
        $decimals = self::METRICS[$canonical]['decimals'] ?? 1;

        if ($decimals === 0) {
            return (string) (int) round($value);
        }

        // Separador decimal '.' para que coincida con el resumen que el
        // normalizador persiste en message_text y no convivan dos formatos
        // distintos en la misma fila del visor.
        $formatted = number_format($value, $decimals ?? 1, '.', '');

        return str_contains($formatted, '.')
            ? rtrim(rtrim($formatted, '0'), '.')
            : $formatted;
    }

    /**
     * Valor formateado con unidad, para lectura humana.
     */
    public static function format(string $canonical, float $value): string
    {
        $unit = self::unit($canonical);
        $text = self::formatValue($canonical, $value);

        return $unit === null ? $text : $text . ' ' . $unit;
    }

    /**
     * Métricas relevantes para una categoría de evento, en orden de catálogo.
     *
     * @return list<string>
     */
    public static function forCategory(string $category): array
    {
        $canonicals = [];

        foreach (self::METRICS as $canonical => $metric) {
            if (in_array($category, $metric['categories'], true)) {
                $canonicals[] = $canonical;
            }
        }

        return $canonicals;
    }

    private static function numericOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }
}
