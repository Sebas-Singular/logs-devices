<?php

declare(strict_types=1);

namespace App\Viewer;

use PDO;

final class ViewerQueries
{
    public function __construct(private readonly PDO $pdo)
    {
    }

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

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}