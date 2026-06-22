<?php

declare(strict_types=1);

namespace App\Viewer;

use PDO;

final class ViewerQueries
{
    public function __construct(private readonly PDO $pdo) {}

    public function dashboardStats(): array
    {
        return [
            'devices_total' => $this->count('SELECT COUNT(*) FROM devices'),
            'bridges_total' => $this->count("SELECT COUNT(*) FROM devices WHERE device_kind = 'bridge'"),
            'beacons_total' => $this->count("SELECT COUNT(*) FROM devices WHERE device_kind = 'baliza'"),

            'ingests_total' => $this->count('SELECT COUNT(*) FROM log_ingests'),
            'ingests_processed' => $this->count("SELECT COUNT(*) FROM log_ingests WHERE status = 'processed'"),
            'ingests_error' => $this->count("SELECT COUNT(*) FROM log_ingests WHERE status = 'error'"),
            'ingests_received' => $this->count("SELECT COUNT(*) FROM log_ingests WHERE status = 'received'"),

            'events_total' => $this->count('SELECT COUNT(*) FROM log_events'),
            'events_parse_ok' => $this->count('SELECT COUNT(*) FROM log_events WHERE parse_ok = 1'),
            'events_parse_error' => $this->count('SELECT COUNT(*) FROM log_events WHERE parse_ok = 0'),
        ];
    }

    public function severityCounts(): array
    {
        $counts = [
            'critical' => 0,
            'error' => 0,
            'warn' => 0,
            'info' => 0,
            'unknown' => 0,
        ];

        $stmt = $this->pdo->query(
            'SELECT severity, COUNT(*) AS total
               FROM log_events
              GROUP BY severity'
        );

        foreach ($stmt->fetchAll() as $row) {
            $severity = (string) $row['severity'];
            $counts[$severity] = (int) $row['total'];
        }

        return $counts;
    }

    public function eventTypeCounts(): array
    {
        $stmt = $this->pdo->query(
            'SELECT event_type, parse_ok, COUNT(*) AS total
               FROM log_events
              GROUP BY event_type, parse_ok
              ORDER BY total DESC'
        );

        return $stmt->fetchAll();
    }

    public function latestEvents(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));

        $stmt = $this->pdo->prepare(
            'SELECT
                le.id,
                le.ingest_id,
                le.device_id,
                le.bridge_device_id,
                le.event_timestamp,
                le.received_at,
                le.severity,
                le.severity_origin,
                le.event_type,
                le.event_category,
                le.device_mac_raw,
                le.parse_ok,
                le.parse_error,
                le.message_text,

                d.device_kind AS device_kind,
                d.name AS device_name,
                d.external_id AS device_external_id,
                d.mac_address AS device_mac_address,

                bridge.name AS bridge_name,
                bridge.external_id AS bridge_external_id
             FROM log_events le
             LEFT JOIN devices d
               ON d.id = le.device_id
             LEFT JOIN devices bridge
               ON bridge.id = le.bridge_device_id
             ORDER BY le.event_timestamp DESC, le.id DESC
             LIMIT :limit'
        );

        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function devicesList(): array
    {
        $stmt = $this->pdo->query(
            'SELECT
                d.id,
                d.device_kind,
                d.external_id,
                d.mac_address,
                d.name,
                d.parent_device_id,
                d.first_seen_at,
                d.last_seen_at,

                parent.external_id AS parent_external_id,
                parent.name AS parent_name,

                COUNT(le.id) AS event_count,
                SUM(CASE WHEN le.severity = "critical" THEN 1 ELSE 0 END) AS critical_count,
                SUM(CASE WHEN le.severity = "error" THEN 1 ELSE 0 END) AS error_count,
                SUM(CASE WHEN le.severity = "warn" THEN 1 ELSE 0 END) AS warn_count,
                MAX(le.event_timestamp) AS last_event_at
            FROM devices d
            LEFT JOIN devices parent
            ON parent.id = d.parent_device_id
            LEFT JOIN log_events le
            ON (
                    (d.device_kind = "bridge" AND le.bridge_device_id = d.id)
                    OR
                    (
                        d.device_kind <> "bridge"
                        AND le.device_id = d.id
                        AND (
                            d.parent_device_id IS NULL
                            OR le.bridge_device_id = d.parent_device_id
                        )
                    )
                )
            GROUP BY
                d.id,
                d.device_kind,
                d.external_id,
                d.mac_address,
                d.name,
                d.parent_device_id,
                d.first_seen_at,
                d.last_seen_at,
                parent.external_id,
                parent.name
            ORDER BY
                CASE d.device_kind
                    WHEN "bridge" THEN 0
                    WHEN "baliza" THEN 1
                    ELSE 2
                END,
                d.external_id ASC,
                d.name ASC'
        );

        return $stmt->fetchAll();
    }

    public function historicalBalizasByBridge(): array
    {
        $stmt = $this->pdo->query(
            'SELECT
            le.bridge_device_id               AS historical_bridge_id,
            d.id                              AS baliza_id,
            d.name                            AS baliza_name,
            d.mac_address                     AS baliza_mac,
            d.external_id                     AS baliza_external_id,
            d.parent_device_id                AS current_bridge_id,
            cb.name                           AS current_bridge_name,
            cb.external_id                    AS current_bridge_external_id,
            COUNT(le.id)                      AS event_count,
            MAX(le.event_timestamp)           AS last_event_at
         FROM log_events le
         JOIN devices d
           ON d.id = le.device_id
          AND d.device_kind = \'baliza\'
         JOIN devices cb
           ON cb.id = d.parent_device_id
        WHERE le.bridge_device_id IS NOT NULL
          AND le.bridge_device_id != d.parent_device_id
        GROUP BY
            le.bridge_device_id,
            d.id,
            d.name,
            d.mac_address,
            d.external_id,
            d.parent_device_id,
            cb.name,
            cb.external_id'
        );

        $result = [];

        foreach ($stmt->fetchAll() as $row) {
            $bridgeId = (int) $row['historical_bridge_id'];
            $result[$bridgeId][] = $row;
        }

        return $result;
    }

    public function eventCategoryOptions(): array
    {
        $stmt = $this->pdo->query(
            "SELECT
            COALESCE(NULLIF(event_category, ''), 'unknown') AS value,
            COUNT(*) AS total
         FROM log_events
         GROUP BY COALESCE(NULLIF(event_category, ''), 'unknown')
         ORDER BY value ASC"
        );

        return $stmt->fetchAll();
    }

    public function eventTypeOptions(): array
    {
        $stmt = $this->pdo->query(
            "SELECT
            COALESCE(NULLIF(event_type, ''), 'unknown') AS value,
            COUNT(*) AS total
         FROM log_events
         GROUP BY COALESCE(NULLIF(event_type, ''), 'unknown')
         ORDER BY value ASC"
        );

        return $stmt->fetchAll();
    }

    public function findDevice(int $deviceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                d.id,
                d.device_kind,
                d.external_id,
                d.mac_address,
                d.name,
                d.parent_device_id,
                d.first_seen_at,
                d.last_seen_at,

                parent.external_id AS parent_external_id,
                parent.name AS parent_name,
                parent.id AS parent_id
            FROM devices d
            LEFT JOIN devices parent
            ON parent.id = d.parent_device_id
            WHERE d.id = :id
            LIMIT 1'
        );

        $stmt->execute([
            'id' => $deviceId,
        ]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function childDevicesForBridge(int $bridgeDeviceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                id,
                device_kind,
                external_id,
                mac_address,
                name,
                first_seen_at,
                last_seen_at
            FROM devices
            WHERE parent_device_id = :bridge_id
            ORDER BY external_id ASC, name ASC'
        );

        $stmt->execute([
            'bridge_id' => $bridgeDeviceId,
        ]);

        return $stmt->fetchAll();
    }

    public function deviceSeverityCounts(int $deviceId, string $deviceKind): array
    {
        $counts = [
            'critical' => 0,
            'error' => 0,
            'warn' => 0,
            'info' => 0,
            'unknown' => 0,
        ];

        $condition = $deviceKind === 'bridge'
            ? 'bridge_device_id = :device_id'
            : 'device_id = :device_id';

        $stmt = $this->pdo->prepare(
            "SELECT severity, COUNT(*) AS total
            FROM log_events
            WHERE {$condition}
            GROUP BY severity"
        );

        $stmt->execute([
            'device_id' => $deviceId,
        ]);

        foreach ($stmt->fetchAll() as $row) {
            $severity = (string) $row['severity'];
            $counts[$severity] = (int) $row['total'];
        }

        return $counts;
    }

    public function deviceEventTypeCounts(int $deviceId, string $deviceKind): array
    {
        $condition = $deviceKind === 'bridge'
            ? 'bridge_device_id = :device_id'
            : 'device_id = :device_id';

        $stmt = $this->pdo->prepare(
            "SELECT event_type, parse_ok, COUNT(*) AS total
            FROM log_events
            WHERE {$condition}
            GROUP BY event_type, parse_ok
            ORDER BY total DESC"
        );

        $stmt->execute([
            'device_id' => $deviceId,
        ]);

        return $stmt->fetchAll();
    }

    public function latestDeviceEvents(int $deviceId, string $deviceKind, int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));

        $condition = $deviceKind === 'bridge'
            ? 'le.bridge_device_id = :device_id'
            : 'le.device_id = :device_id';

        $stmt = $this->pdo->prepare(
            "SELECT
                le.id,
                le.ingest_id,
                le.device_id,
                le.bridge_device_id,
                le.event_timestamp,
                le.received_at,
                le.severity,
                le.severity_origin,
                le.event_type,
                le.event_category,
                le.device_mac_raw,
                le.parse_ok,
                le.parse_error,
                le.message_text,
                le.measurements,
                le.context,

                d.device_kind AS device_kind,
                d.name AS device_name,
                d.external_id AS device_external_id,
                d.mac_address AS device_mac_address,

                bridge.name AS bridge_name,
                bridge.external_id AS bridge_external_id
            FROM log_events le
            LEFT JOIN devices d
            ON d.id = le.device_id
            LEFT JOIN devices bridge
            ON bridge.id = le.bridge_device_id
            WHERE {$condition}
            ORDER BY le.event_timestamp DESC, le.id DESC
            LIMIT :limit"
        );

        $stmt->bindValue('device_id', $deviceId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function devicesForFilter(): array
    {
        $stmt = $this->pdo->query(
            'SELECT
            id,
            device_kind,
            external_id,
            mac_address,
            name
         FROM devices
         ORDER BY
            CASE device_kind
                WHEN "bridge" THEN 0
                WHEN "baliza" THEN 1
                ELSE 2
            END,
            external_id ASC,
            name ASC'
        );

        return $stmt->fetchAll();
    }

    public function eventsSearch(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['severity'])) {
            $where[] = 'le.severity = :severity';
            $params['severity'] = (string) $filters['severity'];
        }

        if (!empty($filters['event_category'])) {
            $where[] = "COALESCE(NULLIF(le.event_category, ''), 'unknown') = :event_category";
            $params['event_category'] = (string) $filters['event_category'];
        }

        if (!empty($filters['event_type'])) {
            $where[] = "COALESCE(NULLIF(le.event_type, ''), 'unknown') = :event_type";
            $params['event_type'] = (string) $filters['event_type'];
        }

        if (array_key_exists('parse_ok', $filters) && $filters['parse_ok'] !== null) {
            $where[] = 'le.parse_ok = :parse_ok';
            $params['parse_ok'] = (int) $filters['parse_ok'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'le.event_timestamp >= :from';
            $params['from'] = (string) $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'le.event_timestamp <= :to';
            $params['to'] = (string) $filters['to'];
        }

        if (!empty($filters['device_id'])) {
            $where[] = 'le.device_id = :device_id';
            $params['device_id'] = (int) $filters['device_id'];
        }

        if (!empty($filters['bridge_id'])) {
            $where[] = 'le.bridge_device_id = :bridge_id';
            $params['bridge_id'] = (int) $filters['bridge_id'];
        }

        if (!empty($filters['ingest_id'])) {
            $where[] = 'le.ingest_id = :ingest_id';
            $params['ingest_id'] = (int) $filters['ingest_id'];
        }

        if (!empty($filters['q'])) {
            $where[] = '(
                le.message_text LIKE :q
                OR le.device_mac_raw LIKE :q
                OR le.event_category LIKE :q
                OR le.event_type LIKE :q
                OR le.measurements LIKE :q
                OR le.context LIKE :q
            )';
            $params['q'] = '%' . (string) $filters['q'] . '%';
        }

        $limit = isset($filters['limit'])
            ? max(1, min((int) $filters['limit'], 200))
            : 50;

        $offset = isset($filters['offset'])
            ? max(0, (int) $filters['offset'])
            : 0;

        $whereSql = $where === []
            ? ''
            : ' WHERE ' . implode(' AND ', $where);

        $stmt = $this->pdo->prepare(
            "SELECT
            le.id,
            le.ingest_id,
            le.device_id,
            le.bridge_device_id,
            le.event_timestamp,
            le.received_at,
            le.severity,
            le.severity_origin,
            le.event_type,
            le.event_category,
            le.device_mac_raw,
            le.parse_ok,
            le.parse_error,
            le.message_text,
            le.measurements,
            le.context,

            d.device_kind AS device_kind,
            d.name AS device_name,
            d.external_id AS device_external_id,
            d.mac_address AS device_mac_address,

            bridge.name AS bridge_name,
            bridge.external_id AS bridge_external_id
         FROM log_events le
         LEFT JOIN devices d
           ON d.id = le.device_id
         LEFT JOIN devices bridge
           ON bridge.id = le.bridge_device_id
         {$whereSql}
         ORDER BY le.event_timestamp DESC, le.id DESC
         LIMIT :limit OFFSET :offset"
        );

        foreach ($params as $name => $value) {
            $stmt->bindValue(
                ':' . $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function eventsSearchCount(array $filters): int
    {
        $where = [];
        $params = [];

        if (!empty($filters['severity'])) {
            $where[] = 'le.severity = :severity';
            $params['severity'] = (string) $filters['severity'];
        }

        if (!empty($filters['event_category'])) {
            $where[] = "COALESCE(NULLIF(le.event_category, ''), 'unknown') = :event_category";
            $params['event_category'] = (string) $filters['event_category'];
        }

        if (!empty($filters['event_type'])) {
            $where[] = "COALESCE(NULLIF(le.event_type, ''), 'unknown') = :event_type";
            $params['event_type'] = (string) $filters['event_type'];
        }

        if (array_key_exists('parse_ok', $filters) && $filters['parse_ok'] !== null) {
            $where[] = 'le.parse_ok = :parse_ok';
            $params['parse_ok'] = (int) $filters['parse_ok'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'le.event_timestamp >= :from';
            $params['from'] = (string) $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'le.event_timestamp <= :to';
            $params['to'] = (string) $filters['to'];
        }

        if (!empty($filters['device_id'])) {
            $where[] = 'le.device_id = :device_id';
            $params['device_id'] = (int) $filters['device_id'];
        }

        if (!empty($filters['bridge_id'])) {
            $where[] = 'le.bridge_device_id = :bridge_id';
            $params['bridge_id'] = (int) $filters['bridge_id'];
        }

        if (!empty($filters['ingest_id'])) {
            $where[] = 'le.ingest_id = :ingest_id';
            $params['ingest_id'] = (int) $filters['ingest_id'];
        }

        if (!empty($filters['q'])) {
            $where[] = '(
                le.message_text LIKE :q
                OR le.device_mac_raw LIKE :q
                OR le.event_category LIKE :q
                OR le.event_type LIKE :q
                OR le.measurements LIKE :q
                OR le.context LIKE :q
            )';
            $params['q'] = '%' . (string) $filters['q'] . '%';
        }

        $whereSql = $where === []
            ? ''
            : ' WHERE ' . implode(' AND ', $where);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
           FROM log_events le
           {$whereSql}"
        );

        foreach ($params as $name => $value) {
            $stmt->bindValue(
                ':' . $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function findEvent(int $eventId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
            le.id,
            le.ingest_id,
            le.device_id,
            le.bridge_device_id,
            NULL AS line_number,
            le.event_hash,
            le.event_timestamp,
            le.received_at,
            le.severity,
            le.severity_origin,
            le.event_type,
            le.event_category,
            le.device_mac_raw,
            le.parse_ok,
            le.parse_error,
            le.quality_status,
            le.anomaly_flags,
            le.message_text,
            le.measurements,
            le.context,
            le.created_at,

            d.device_kind AS device_kind,
            d.name AS device_name,
            d.external_id AS device_external_id,
            d.mac_address AS device_mac_address,

            bridge.name AS bridge_name,
            bridge.external_id AS bridge_external_id,

            li.bridge_id_reported,
            bridge.name AS bridge_name_reported,
            li.received_at AS ingest_received_at,
            li.remote_addr,
            li.user_agent,
            li.source_type,
            li.source_device_id,
            li.raw_path,
            li.content_hash,
            li.payload_summary,
            li.status AS ingest_status,
            li.line_count,
            li.parsed_ok_count,
            li.parsed_error_count,
            li.processing_started_at,
            li.processing_finished_at
         FROM log_events le
         LEFT JOIN devices d
           ON d.id = le.device_id
         LEFT JOIN devices bridge
           ON bridge.id = le.bridge_device_id
         LEFT JOIN log_ingests li
           ON li.id = le.ingest_id
         WHERE le.id = :id
         LIMIT 1'
        );

        $stmt->execute([
            'id' => $eventId,
        ]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function ingestBridgeOptions(): array
    {
        $stmt = $this->pdo->query(
            "SELECT DISTINCT
            li.bridge_id_reported,
            bridge.id AS bridge_device_id,
            bridge.name AS bridge_name
         FROM log_ingests li
         LEFT JOIN devices bridge
           ON bridge.device_kind = 'bridge'
          AND bridge.external_id = li.bridge_id_reported
         WHERE li.bridge_id_reported IS NOT NULL
           AND li.bridge_id_reported <> ''
         ORDER BY li.bridge_id_reported ASC"
        );

        return $stmt->fetchAll();
    }

    public function ingestSourceTypeOptions(): array
    {
        $stmt = $this->pdo->query(
            "SELECT DISTINCT source_type
           FROM log_ingests
          WHERE source_type IS NOT NULL
            AND source_type <> ''
          ORDER BY source_type ASC"
        );

        return $stmt->fetchAll();
    }

    public function ingestsSearch(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'li.status = :status';
            $params['status'] = (string) $filters['status'];
        }

        if (!empty($filters['bridge_id_reported'])) {
            $where[] = 'li.bridge_id_reported = :bridge_id_reported';
            $params['bridge_id_reported'] = (string) $filters['bridge_id_reported'];
        }

        if (!empty($filters['source_type'])) {
            $where[] = 'li.source_type = :source_type';
            $params['source_type'] = (string) $filters['source_type'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'li.received_at >= :from';
            $params['from'] = (string) $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'li.received_at <= :to';
            $params['to'] = (string) $filters['to'];
        }

        if (!empty($filters['q'])) {
            $where[] = '(li.raw_path LIKE :q OR li.content_hash LIKE :q OR li.payload_summary LIKE :q OR li.remote_addr LIKE :q)';
            $params['q'] = '%' . (string) $filters['q'] . '%';
        }

        $limit = isset($filters['limit'])
            ? max(1, min((int) $filters['limit'], 200))
            : 50;

        $offset = isset($filters['offset'])
            ? max(0, (int) $filters['offset'])
            : 0;

        $whereSql = $where === []
            ? ''
            : ' WHERE ' . implode(' AND ', $where);

        $stmt = $this->pdo->prepare(
            "SELECT
            li.id,
            li.received_at,
            li.remote_addr,
            li.user_agent,
            li.source_type,
            li.source_device_id,
            li.bridge_id_reported,
            li.content_hash,
            li.raw_path,
            li.payload_summary,
            li.status,
            li.line_count,
            li.parsed_ok_count,
            li.parsed_error_count,
            li.processing_started_at,
            li.processing_finished_at,

            bridge.id AS bridge_device_id,
            bridge.name AS bridge_name
         FROM log_ingests li
         LEFT JOIN devices bridge
           ON bridge.device_kind = 'bridge'
          AND bridge.external_id = li.bridge_id_reported
         {$whereSql}
         ORDER BY li.received_at DESC, li.id DESC
         LIMIT :limit OFFSET :offset"
        );

        foreach ($params as $name => $value) {
            $stmt->bindValue(
                ':' . $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function ingestsSearchCount(array $filters): int
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'li.status = :status';
            $params['status'] = (string) $filters['status'];
        }

        if (!empty($filters['bridge_id_reported'])) {
            $where[] = 'li.bridge_id_reported = :bridge_id_reported';
            $params['bridge_id_reported'] = (string) $filters['bridge_id_reported'];
        }

        if (!empty($filters['source_type'])) {
            $where[] = 'li.source_type = :source_type';
            $params['source_type'] = (string) $filters['source_type'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'li.received_at >= :from';
            $params['from'] = (string) $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'li.received_at <= :to';
            $params['to'] = (string) $filters['to'];
        }

        if (!empty($filters['q'])) {
            $where[] = '(li.raw_path LIKE :q OR li.content_hash LIKE :q OR li.payload_summary LIKE :q OR li.remote_addr LIKE :q)';
            $params['q'] = '%' . (string) $filters['q'] . '%';
        }

        $whereSql = $where === []
            ? ''
            : ' WHERE ' . implode(' AND ', $where);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
           FROM log_ingests li
           {$whereSql}"
        );

        foreach ($params as $name => $value) {
            $stmt->bindValue(
                ':' . $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function findIngest(int $ingestId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
            li.id,
            li.received_at,
            li.remote_addr,
            li.user_agent,
            li.source_type,
            li.source_device_id,
            li.bridge_id_reported,
            li.content_hash,
            li.raw_path,
            li.payload_summary,
            li.status,
            li.line_count,
            li.parsed_ok_count,
            li.parsed_error_count,
            li.processing_started_at,
            li.processing_finished_at,

            bridge.id AS bridge_device_id,
            bridge.name AS bridge_name
         FROM log_ingests li
         LEFT JOIN devices bridge
           ON bridge.device_kind = 'bridge'
          AND bridge.external_id = li.bridge_id_reported
         WHERE li.id = :id
         LIMIT 1"
        );

        $stmt->execute([
            'id' => $ingestId,
        ]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function ingestEvents(int $ingestId, int $limit = 100): array
    {
        $limit = max(1, min($limit, 200));

        $stmt = $this->pdo->prepare(
            'SELECT
            le.id,
            le.ingest_id,
            le.device_id,
            le.bridge_device_id,
            le.event_timestamp,
            le.received_at,
            le.severity,
            le.severity_origin,
            le.event_type,
            le.event_category,
            le.device_mac_raw,
            le.parse_ok,
            le.parse_error,
            le.message_text,

            d.name AS device_name,
            d.external_id AS device_external_id,
            d.mac_address AS device_mac_address
         FROM log_events le
         LEFT JOIN devices d
           ON d.id = le.device_id
         WHERE le.ingest_id = :ingest_id
         ORDER BY le.id ASC
         LIMIT :limit'
        );

        $stmt->bindValue(':ingest_id', $ingestId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}
