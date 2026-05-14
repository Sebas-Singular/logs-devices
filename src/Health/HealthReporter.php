<?php

declare(strict_types=1);

namespace App\Health;

use PDO;

final class HealthReporter
{
    public static function database(PDO $pdo): array
    {
        $row = $pdo->query('SELECT DATABASE() AS database_name, NOW() AS database_time')->fetch();

        $statusRows = $pdo->query(
            'SELECT status, COUNT(*) AS total
               FROM log_ingests
              GROUP BY status
              ORDER BY status'
        )->fetchAll();

        $statusCounts = [];

        foreach ($statusRows as $statusRow) {
            $statusCounts[(string) $statusRow['status']] = (int) $statusRow['total'];
        }

        $latestIngestReceivedAt = $pdo->query(
            'SELECT MAX(received_at)
               FROM log_ingests'
        )->fetchColumn();

        return [
            'ok' => true,
            'database_name' => (string) ($row['database_name'] ?? ''),
            'database_time' => (string) ($row['database_time'] ?? ''),
            'ingest_status_counts' => $statusCounts,
            'latest_ingest_received_at' => $latestIngestReceivedAt !== false ? $latestIngestReceivedAt : null,
        ];
    }

    public static function storage(string $baseDir): array
    {
        return [
            'base' => self::directory($baseDir),
            'raw' => self::directory($baseDir . '/raw'),
            'rejected' => self::directory($baseDir . '/rejected'),
            'archive' => self::directory($baseDir . '/archive'),
            'rate_limit' => self::directory($baseDir . '/rate-limit'),
        ];
    }

    private static function directory(string $path): array
    {
        $exists = is_dir($path);
        $readable = $exists && is_readable($path);
        $writable = $exists && is_writable($path);

        $ok = $exists && $readable && $writable;

        return [
            'ok' => $ok,
            'exists' => $exists,
            'readable' => $readable,
            'writable' => $writable,
            'write_test' => null,
            'write_probe' => 'disabled',
        ];
    }
}
