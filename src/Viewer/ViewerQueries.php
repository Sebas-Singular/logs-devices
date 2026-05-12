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
             ORDER BY COALESCE(le.event_timestamp, le.received_at) DESC, le.id DESC
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
                MAX(COALESCE(le.event_timestamp, le.received_at)) AS last_event_at
            FROM devices d
            LEFT JOIN devices parent
            ON parent.id = d.parent_device_id
            LEFT JOIN log_events le
            ON (
                    (d.device_kind = "bridge" AND le.bridge_device_id = d.id)
                    OR
                    (d.device_kind <> "bridge" AND le.device_id = d.id)
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
            ORDER BY COALESCE(le.event_timestamp, le.received_at) DESC, le.id DESC
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

        if (!empty($filters['event_type'])) {
            $where[] = 'le.event_type = :event_type';
            $params['event_type'] = (string) $filters['event_type'];
        }

        if (array_key_exists('parse_ok', $filters) && $filters['parse_ok'] !== null) {
            $where[] = 'le.parse_ok = :parse_ok';
            $params['parse_ok'] = (int) $filters['parse_ok'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'COALESCE(le.event_timestamp, le.received_at) >= :from';
            $params['from'] = (string) $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'COALESCE(le.event_timestamp, le.received_at) <= :to';
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

        if (!empty($filters['q'])) {
            $where[] = 'le.message_text LIKE :q';
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
         ORDER BY COALESCE(le.event_timestamp, le.received_at) DESC, le.id DESC
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

        if (!empty($filters['event_type'])) {
            $where[] = 'le.event_type = :event_type';
            $params['event_type'] = (string) $filters['event_type'];
        }

        if (array_key_exists('parse_ok', $filters) && $filters['parse_ok'] !== null) {
            $where[] = 'le.parse_ok = :parse_ok';
            $params['parse_ok'] = (int) $filters['parse_ok'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'COALESCE(le.event_timestamp, le.received_at) >= :from';
            $params['from'] = (string) $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'COALESCE(le.event_timestamp, le.received_at) <= :to';
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

        if (!empty($filters['q'])) {
            $where[] = 'le.message_text LIKE :q';
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

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}
