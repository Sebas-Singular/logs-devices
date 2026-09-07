<?php

declare(strict_types=1);

namespace App\Viewer;

use DateTimeImmutable;
use PDO;

/**
 * Consultas del panel de control.
 *
 * Todo lo que devuelve esta clase está acotado a una ventana temporal y
 * refleja el estado real del sistema, no totales históricos acumulados.
 * Las secciones del dashboard se construyen a partir de lo que estas
 * consultas encuentran: si una categoría, un tipo de evento o una métrica
 * no ha producido datos en la ventana, no se pinta como opción viva.
 */
final class DashboardQueries
{
    /** Debe coincidir con StoredIngestProcessor y HealthReporter. */
    private const ZOMBIE_TIMEOUT_MINUTES = 10;

    /** Ventanas ofrecidas en el selector del panel. */
    public const WINDOWS = [
        '24h' => ['label' => 'Últimas 24 h', 'hours' => 24],
        '7d' => ['label' => 'Últimos 7 días', 'hours' => 168],
        '30d' => ['label' => 'Últimos 30 días', 'hours' => 720],
        'all' => ['label' => 'Todo el histórico', 'hours' => null],
    ];

    public const DEFAULT_WINDOW = '7d';

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Traduce una clave de ventana al instante de corte, o null si es 'all'.
     */
    public static function windowStart(string $window): ?string
    {
        // Se resuelve primero la definición completa: 'all' declara hours = null
        // a propósito, y con `?? ` ese null caía al valor por defecto, así que
        // "todo el histórico" se comportaba como la ventana de 7 días.
        $definition = self::WINDOWS[$window] ?? self::WINDOWS[self::DEFAULT_WINDOW];
        $hours = $definition['hours'];

        if ($hours === null) {
            return null;
        }

        return date('Y-m-d H:i:s', time() - ($hours * 3600));
    }

    public static function normalizeWindow(mixed $value): string
    {
        $key = is_scalar($value) ? trim((string) $value) : '';

        return isset(self::WINDOWS[$key]) ? $key : self::DEFAULT_WINDOW;
    }

    // =====================================================================
    // Salud del sistema
    // =====================================================================

    /**
     * Estado operativo real, derivado de datos, no de una constante en la vista.
     *
     * @return array{
     *     status: string,
     *     last_ingest_at: ?string,
     *     minutes_since_ingest: ?int,
     *     last_event_at: ?string,
     *     minutes_since_event: ?int,
     *     zombie_count: int,
     *     error_count: int,
     *     received_count: int,
     *     reasons: list<string>
     * }
     */
    public function health(?string $since): array
    {
        // Se ordena por la marca temporal, no por id: los scripts de importación
        // histórica y de reprocesado insertan eventos antiguos con ids nuevos,
        // así que el id más alto no es necesariamente el dato más reciente.
        $ingest = $this->pdo->query(
            'SELECT received_at
               FROM log_ingests
              ORDER BY received_at DESC, id DESC
              LIMIT 1'
        )->fetch();

        $event = $this->pdo->query(
            'SELECT event_timestamp, received_at
               FROM log_events
              ORDER BY event_timestamp DESC, id DESC
              LIMIT 1'
        )->fetch();

        $zombieStmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM log_ingests
              WHERE status = :status
                AND processing_started_at < DATE_SUB(NOW(), INTERVAL :minutes MINUTE)'
        );
        $zombieStmt->bindValue('status', 'parsing');
        $zombieStmt->bindValue('minutes', self::ZOMBIE_TIMEOUT_MINUTES, PDO::PARAM_INT);
        $zombieStmt->execute();
        $zombieCount = (int) $zombieStmt->fetchColumn();

        $pending = $this->ingestStatusCounts($since);

        $minutesSinceIngest = self::minutesSince($ingest['received_at'] ?? null);
        $minutesSinceEvent = self::minutesSince($event['received_at'] ?? null);

        $errorCount = (int) ($pending['error'] ?? 0);
        $receivedCount = (int) ($pending['received'] ?? 0);
        $processedCount = (int) ($pending['processed'] ?? 0);

        $reasons = [];

        if ($ingest === false) {
            $reasons[] = 'Sin ninguna ingesta registrada';
        } elseif ($minutesSinceIngest !== null && $minutesSinceIngest > 180) {
            $reasons[] = 'Sin ingestas desde hace ' . self::humanizeMinutes($minutesSinceIngest);
        }

        if ($zombieCount > 0) {
            $reasons[] = $zombieCount . ' ' . ($zombieCount === 1 ? 'ingesta atascada' : 'ingestas atascadas') . ' procesando';
        }

        if ($errorCount > 0) {
            $reasons[] = $errorCount . ' ' . ($errorCount === 1 ? 'ingesta en error' : 'ingestas en error');
        }

        $totalWindow = $errorCount + $receivedCount + $processedCount;

        if ($totalWindow > 0 && $errorCount / $totalWindow > 0.1) {
            $reasons[] = 'Tasa de error por encima del 10%';
        }

        $status = match (true) {
            $ingest === false => 'sin_datos',
            $minutesSinceIngest !== null && $minutesSinceIngest > 720 => 'caido',
            $zombieCount > 0 || $errorCount > 0 => 'degradado',
            $minutesSinceIngest !== null && $minutesSinceIngest > 180 => 'degradado',
            default => 'operativo',
        };

        return [
            'status' => $status,
            'last_ingest_at' => $ingest === false ? null : (string) $ingest['received_at'],
            'minutes_since_ingest' => $minutesSinceIngest,
            'last_event_at' => $event === false ? null : (string) $event['event_timestamp'],
            'minutes_since_event' => $minutesSinceEvent,
            'zombie_count' => $zombieCount,
            'error_count' => $errorCount,
            'received_count' => $receivedCount,
            'reasons' => $reasons,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function ingestStatusCounts(?string $since): array
    {
        $sql = 'SELECT status, COUNT(*) AS total FROM log_ingests';
        $params = [];

        if ($since !== null) {
            $sql .= ' WHERE received_at >= :since';
            $params['since'] = $since;
        }

        $sql .= ' GROUP BY status';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $counts = [];

        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    // =====================================================================
    // Actividad
    // =====================================================================

    /**
     * Volumen de la ventana en una sola pasada por tabla.
     *
     * @return array{
     *     events: int,
     *     events_parse_error: int,
     *     events_suspect: int,
     *     ingests: int,
     *     devices_active: int,
     *     bridges_active: int,
     *     devices_total: int,
     *     bridges_total: int,
     *     beacons_total: int
     * }
     */
    public function activity(?string $since): array
    {
        $eventWhere = $since !== null ? ' WHERE event_timestamp >= :since' : '';
        $params = $since !== null ? ['since' => $since] : [];

        $stmt = $this->pdo->prepare(
            'SELECT
                COUNT(*) AS events,
                SUM(CASE WHEN parse_ok = 0 THEN 1 ELSE 0 END) AS events_parse_error,
                SUM(CASE WHEN quality_status <> \'valid\' THEN 1 ELSE 0 END) AS events_suspect,
                COUNT(DISTINCT device_id) AS devices_active,
                COUNT(DISTINCT bridge_device_id) AS bridges_active
             FROM log_events' . $eventWhere
        );
        $stmt->execute($params);
        $events = $stmt->fetch() ?: [];

        $ingestStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM log_ingests'
            . ($since !== null ? ' WHERE received_at >= :since' : '')
        );
        $ingestStmt->execute($params);
        $ingests = (int) $ingestStmt->fetchColumn();

        $devices = $this->pdo->query(
            'SELECT
                COUNT(*) AS devices_total,
                SUM(CASE WHEN device_kind = \'bridge\' THEN 1 ELSE 0 END) AS bridges_total,
                SUM(CASE WHEN device_kind = \'baliza\' THEN 1 ELSE 0 END) AS beacons_total
             FROM devices'
        )->fetch() ?: [];

        $activeDevices = (int) ($events['devices_active'] ?? 0);
        $activeBridges = (int) ($events['bridges_active'] ?? 0);

        return [
            'events' => (int) ($events['events'] ?? 0),
            'events_parse_error' => (int) ($events['events_parse_error'] ?? 0),
            'events_suspect' => (int) ($events['events_suspect'] ?? 0),
            'ingests' => $ingests,
            'devices_active' => $activeDevices,
            'bridges_active' => $activeBridges,
            'devices_total' => (int) ($devices['devices_total'] ?? 0),
            'bridges_total' => (int) ($devices['bridges_total'] ?? 0),
            'beacons_total' => (int) ($devices['beacons_total'] ?? 0),
        ];
    }

    // =====================================================================
    // Severidad, categorías y tipos — solo lo que sigue vivo
    // =====================================================================

    /**
     * @return array<string, int>
     */
    public function severityCounts(?string $since): array
    {
        $sql = 'SELECT severity, COUNT(*) AS total FROM log_events';
        $params = [];

        if ($since !== null) {
            $sql .= ' WHERE event_timestamp >= :since';
            $params['since'] = $since;
        }

        $sql .= ' GROUP BY severity';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $counts = [];

        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['severity']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Categorías con actividad en la ventana, con su último evento.
     *
     * @return list<array{value: string, total: int, last_event_at: ?string, error_total: int}>
     */
    public function categoryBreakdown(?string $since): array
    {
        return $this->breakdown('event_category', $since, 50);
    }

    /**
     * Tipos de evento con actividad en la ventana.
     *
     * A diferencia del panel anterior, un tipo produce UNA fila con su
     * recuento de errores de parseo dentro, no dos filas separadas.
     *
     * @return list<array{value: string, total: int, last_event_at: ?string, error_total: int}>
     */
    public function eventTypeBreakdown(?string $since, int $limit = 12): array
    {
        return $this->breakdown('event_type', $since, $limit);
    }

    /**
     * Tipos de evento que existen en el histórico pero llevan toda la ventana
     * sin producir un solo evento. Son las "opciones que dejaron de dar
     * información": se listan aparte y atenuadas, no como filtro vivo.
     *
     * @return list<array{value: string, total: int, last_event_at: ?string}>
     */
    public function dormantEventTypes(?string $since, int $limit = 30): array
    {
        if ($since === null) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(NULLIF(event_type, ''), 'unknown') AS value,
                COUNT(*) AS total,
                MAX(event_timestamp) AS last_event_at
             FROM log_events
             GROUP BY COALESCE(NULLIF(event_type, ''), 'unknown')
             HAVING MAX(event_timestamp) < :since
             ORDER BY last_event_at DESC
             LIMIT :limit"
        );

        $stmt->bindValue('since', $since);
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return list<array{value: string, total: int, last_event_at: ?string, error_total: int}>
     */
    private function breakdown(string $column, ?string $since, int $limit): array
    {
        if (!in_array($column, ['event_category', 'event_type'], true)) {
            return [];
        }

        $where = $since !== null ? ' WHERE event_timestamp >= :since' : '';

        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(NULLIF({$column}, ''), 'unknown') AS value,
                COUNT(*) AS total,
                SUM(CASE WHEN parse_ok = 0 THEN 1 ELSE 0 END) AS error_total,
                MAX(event_timestamp) AS last_event_at
             FROM log_events
             {$where}
             GROUP BY COALESCE(NULLIF({$column}, ''), 'unknown')
             ORDER BY total DESC
             LIMIT :limit"
        );

        if ($since !== null) {
            $stmt->bindValue('since', $since);
        }

        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // =====================================================================
    // Métricas en vivo
    // =====================================================================

    /**
     * Última lectura conocida de cada métrica del catálogo.
     *
     * Recorre hacia atrás los eventos recientes que traen measurements y se
     * queda con el primer valor resoluble de cada métrica canónica. Como
     * MetricCatalog prueba los alias en orden de vigencia, un evento que
     * traiga a la vez speedKmh y speed_kmh se lee por la clave actual, y un
     * evento antiguo que solo traiga speed_kmh sigue siendo legible.
     *
     * @return array<string, array{
     *     canonical: string,
     *     value: float,
     *     key: string,
     *     legacy: bool,
     *     event_id: int,
     *     event_timestamp: string,
     *     device_id: ?int,
     *     device_name: ?string,
     *     event_category: ?string,
     *     event_type: string,
     *     series: list<float>
     * }>
     */
    public function liveMetrics(?string $since, int $scanLimit = 400, int $seriesLength = 16): array
    {
        $where = "le.measurements IS NOT NULL
                  AND le.measurements <> ''
                  AND le.measurements <> '[]'
                  AND le.measurements <> '{}'";
        $params = [];

        if ($since !== null) {
            $where .= ' AND le.event_timestamp >= :since';
            $params['since'] = $since;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                le.id,
                le.event_timestamp,
                le.event_category,
                le.event_type,
                le.device_id,
                le.measurements,
                d.name AS device_name
             FROM log_events le
             LEFT JOIN devices d ON d.id = le.device_id
             WHERE {$where}
             ORDER BY le.event_timestamp DESC, le.id DESC
             LIMIT :scan"
        );

        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }

        $stmt->bindValue('scan', max(1, min($scanLimit, 2000)), PDO::PARAM_INT);
        $stmt->execute();

        $latest = [];
        $series = [];

        foreach ($stmt->fetchAll() as $row) {
            $measurements = self::decodeJsonMap($row['measurements'] ?? null);

            if ($measurements === []) {
                continue;
            }

            foreach (MetricCatalog::resolveAll($measurements) as $canonical => $sample) {
                // Serie corta para la línea de tendencia de la tarjeta. Sale del
                // mismo recorrido que la última lectura: cero consultas extra.
                if (count($series[$canonical] ?? []) < $seriesLength) {
                    $series[$canonical][] = $sample['value'];
                }

                if (isset($latest[$canonical])) {
                    continue;
                }

                $latest[$canonical] = [
                    'canonical' => $canonical,
                    'value' => $sample['value'],
                    'key' => $sample['key'],
                    'legacy' => $sample['legacy'],
                    'event_id' => (int) $row['id'],
                    'event_timestamp' => (string) $row['event_timestamp'],
                    'device_id' => $row['device_id'] === null ? null : (int) $row['device_id'],
                    'device_name' => $row['device_name'] === null ? null : (string) $row['device_name'],
                    'event_category' => $row['event_category'] === null ? null : (string) $row['event_category'],
                    'event_type' => (string) $row['event_type'],
                ];
            }
        }

        // Devolver en el orden del catálogo, no en el de aparición.
        $ordered = [];

        foreach (array_keys(MetricCatalog::all()) as $canonical) {
            if (!isset($latest[$canonical])) {
                continue;
            }

            // El recorrido va de más reciente a más antigua; la serie se
            // presenta en orden cronológico.
            $latest[$canonical]['series'] = array_reverse($series[$canonical] ?? []);
            $ordered[$canonical] = $latest[$canonical];
        }

        return $ordered;
    }

    /**
     * Última lectura de velocidad del sistema, sea cual sea la generación
     * del dato que la produjo.
     *
     * El histórico tiene tres campos que calculan velocidad:
     *   - speedKmh       (categoría speed, formato estructurado actual)
     *   - finalSpeedKmh  (categoría vehicle, estimador del bridge)
     *   - speed_kmh      (SpeedParser legacy sobre logText)
     *
     * Se recorre hacia atrás y se devuelve el primero que resuelva, prefiriendo
     * siempre la clave vigente cuando un mismo evento trae varias. Este es el
     * indicador de rendimiento del panel.
     *
     * @return array{
     *     canonical: string,
     *     value: float,
     *     key: string,
     *     legacy: bool,
     *     event_id: int,
     *     event_timestamp: string,
     *     device_id: ?int,
     *     device_name: ?string,
     *     event_type: string
     * }|null
     */
    public function latestSpeedSample(?string $since, int $scanLimit = 300): ?array
    {
        $where = "(le.event_category IN ('speed', 'vehicle')
                   OR le.event_type IN ('speed', 'speed_calculated', 'vehicle_detected', 'vehicle_exit'))
                  AND le.measurements IS NOT NULL
                  AND le.measurements <> ''";

        if ($since !== null) {
            $where .= ' AND le.event_timestamp >= :since';
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                le.id,
                le.event_timestamp,
                le.event_type,
                le.device_id,
                le.measurements,
                d.name AS device_name
             FROM log_events le
             LEFT JOIN devices d ON d.id = le.device_id
             WHERE {$where}
             ORDER BY le.event_timestamp DESC, le.id DESC
             LIMIT :scan"
        );

        if ($since !== null) {
            $stmt->bindValue('since', $since);
        }

        $stmt->bindValue('scan', max(1, min($scanLimit, 1000)), PDO::PARAM_INT);
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            $measurements = self::decodeJsonMap($row['measurements'] ?? null);

            foreach (['speed', 'final_speed'] as $canonical) {
                $sample = MetricCatalog::resolve($canonical, $measurements);

                if ($sample === null) {
                    continue;
                }

                return [
                    'canonical' => $canonical,
                    'value' => $sample['value'],
                    'key' => $sample['key'],
                    'legacy' => $sample['legacy'],
                    'event_id' => (int) $row['id'],
                    'event_timestamp' => (string) $row['event_timestamp'],
                    'device_id' => $row['device_id'] === null ? null : (int) $row['device_id'],
                    'device_name' => $row['device_name'] === null ? null : (string) $row['device_name'],
                    'event_type' => (string) $row['event_type'],
                ];
            }
        }

        return null;
    }

    /**
     * Serie temporal de eventos, con los intervalos vacíos incluidos.
     *
     * Rellenar los huecos importa: un intervalo sin eventos es información
     * -el sistema dejó de reportar-, y si solo se pintan los intervalos con
     * datos el gráfico los oculta y la serie miente.
     *
     * El tamaño del intervalo lo fija la ventana: horas en 24 h, días en 7 y 30,
     * meses en el histórico completo.
     *
     * @return list<array{label: string, value: int, incidents: int, tooltip: string}>
     */
    public function eventTimeline(string $window, ?string $since): array
    {
        $spec = match ($window) {
            '24h' => ['sql' => '%Y-%m-%d %H', 'php' => 'Y-m-d H', 'label' => 'j/n H\\h', 'step' => '+1 hour'],
            '7d', '30d' => ['sql' => '%Y-%m-%d', 'php' => 'Y-m-d', 'label' => 'j/n', 'step' => '+1 day'],
            default => ['sql' => '%Y-%m', 'php' => 'Y-m', 'label' => 'n/Y', 'step' => '+1 month'],
        };

        $where = $since !== null ? ' WHERE event_timestamp >= :since' : '';

        $stmt = $this->pdo->prepare(
            "SELECT
                DATE_FORMAT(event_timestamp, '{$spec['sql']}') AS bucket,
                COUNT(*) AS total,
                SUM(CASE WHEN severity IN ('error', 'critical') THEN 1 ELSE 0 END) AS incidents
             FROM log_events
             {$where}
             GROUP BY bucket
             ORDER BY bucket ASC"
        );

        if ($since !== null) {
            $stmt->bindValue('since', $since);
        }

        $stmt->execute();

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[(string) $row['bucket']] = [
                'total' => (int) $row['total'],
                'incidents' => (int) $row['incidents'],
            ];
        }

        if ($rows === []) {
            return [];
        }

        $start = $since !== null
            ? new DateTimeImmutable($since)
            : new DateTimeImmutable((string) array_key_first($rows) . self::bucketSuffix($window));

        $cursor = $start;
        $now = new DateTimeImmutable();
        $timeline = [];

        // Tope de intervalos: en el histórico completo la serie puede abarcar
        // años y un gráfico de 400 barras no se lee.
        $guard = 0;

        while ($cursor <= $now && $guard < 200) {
            $key = $cursor->format($spec['php']);
            $bucket = $rows[$key] ?? ['total' => 0, 'incidents' => 0];

            $timeline[] = [
                'label' => $cursor->format($spec['label']),
                'value' => $bucket['total'],
                'incidents' => $bucket['incidents'],
                'tooltip' => $cursor->format($spec['php']) . ' · '
                    . $bucket['total'] . ' eventos'
                    . ($bucket['incidents'] > 0 ? ' · ' . $bucket['incidents'] . ' con incidencia' : ''),
            ];

            $cursor = $cursor->modify($spec['step']);
            $guard++;
        }

        return $timeline;
    }

    /**
     * Completa una clave de intervalo para poder construir un DateTimeImmutable.
     */
    private static function bucketSuffix(string $window): string
    {
        return match ($window) {
            '24h' => ':00:00',
            '7d', '30d' => ' 00:00:00',
            default => '-01 00:00:00',
        };
    }

    // =====================================================================
    // Últimos eventos
    // =====================================================================

    /**
     * @return list<array<string, mixed>>
     */
    public function latestEvents(?string $since, int $limit = 20): array
    {
        $where = $since !== null ? ' WHERE le.event_timestamp >= :since' : '';

        $stmt = $this->pdo->prepare(
            "SELECT
                le.id,
                le.ingest_id,
                le.device_id,
                le.bridge_device_id,
                le.event_timestamp,
                le.received_at,
                le.severity,
                le.event_type,
                le.event_category,
                le.device_mac_raw,
                le.parse_ok,
                le.parse_error,
                le.quality_status,
                le.message_text,
                le.measurements,
                le.context,

                d.name AS device_name,
                d.mac_address AS device_mac_address,

                bridge.name AS bridge_name,
                bridge.external_id AS bridge_external_id
             FROM log_events le
             LEFT JOIN devices d ON d.id = le.device_id
             LEFT JOIN devices bridge ON bridge.id = le.bridge_device_id
             {$where}
             ORDER BY le.event_timestamp DESC, le.id DESC
             LIMIT :limit"
        );

        if ($since !== null) {
            $stmt->bindValue('since', $since);
        }

        $stmt->bindValue('limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Dispositivos que han emitido en la ventana, con su última señal.
     *
     * @return list<array<string, mixed>>
     */
    public function activeDevices(?string $since, int $limit = 10): array
    {
        $where = $since !== null ? ' WHERE le.event_timestamp >= :since' : '';

        $stmt = $this->pdo->prepare(
            "SELECT
                d.id,
                d.device_kind,
                d.name,
                d.external_id,
                COUNT(le.id) AS event_total,
                SUM(CASE WHEN le.severity IN ('error', 'critical') THEN 1 ELSE 0 END) AS error_total,
                MAX(le.event_timestamp) AS last_event_at
             FROM log_events le
             JOIN devices d ON d.id = le.device_id
             {$where}
             GROUP BY d.id, d.device_kind, d.name, d.external_id
             ORDER BY event_total DESC
             LIMIT :limit"
        );

        if ($since !== null) {
            $stmt->bindValue('since', $since);
        }

        $stmt->bindValue('limit', max(1, min($limit, 50)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Dispositivos registrados que llevan toda la ventana en silencio.
     */
    public function silentDeviceCount(?string $since): int
    {
        if ($since === null) {
            return 0;
        }

        // Dos NOT EXISTS separados en lugar de uno con OR: así cada subconsulta
        // puede usar su índice -(device_id, event_timestamp) y
        // (bridge_device_id, event_timestamp)- en vez de recorrer log_events.
        // Placeholders distintos porque PDO sin emulación no reutiliza nombres.
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM devices d
              WHERE NOT EXISTS (
                    SELECT 1
                      FROM log_events le
                     WHERE le.device_id = d.id
                       AND le.event_timestamp >= :since_device
              )
                AND NOT EXISTS (
                    SELECT 1
                      FROM log_events le
                     WHERE le.bridge_device_id = d.id
                       AND le.event_timestamp >= :since_bridge
              )'
        );

        $stmt->execute([
            'since_device' => $since,
            'since_bridge' => $since,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Minutos transcurridos desde un DATETIME de la base de datos.
     *
     * Se calcula en PHP -que Bootstrap fija en UTC, la misma zona en la que se
     * escriben los DATETIME- en lugar de con TIMESTAMPDIFF(..., NOW()), que
     * depende de la zona de la sesión de MariaDB.
     */
    private static function minutesSince(mixed $value): ?int
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        $timestamp = strtotime($text);

        if ($timestamp === false) {
            return null;
        }

        return max(0, (int) floor((time() - $timestamp) / 60));
    }

    public static function humanizeMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        if ($minutes < 1) {
            return 'ahora mismo';
        }

        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return $hours . ' h';
        }

        return intdiv($hours, 24) . ' d';
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
