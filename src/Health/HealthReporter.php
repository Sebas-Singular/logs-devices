<?php

declare(strict_types=1);

namespace App\Health;

use PDO;
use Throwable;

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
            'base' => self::directory($baseDir, writeProbe: false),
            'raw' => self::directory($baseDir . '/raw', writeProbe: true),
            'rejected' => self::directory($baseDir . '/rejected', writeProbe: true),
            'archive' => self::directory($baseDir . '/archive', writeProbe: true),
            'rate_limit' => self::directory($baseDir . '/rate-limit', writeProbe: true),
        ];
    }

    private static function directory(string $path, bool $writeProbe): array
    {
        $exists = is_dir($path);
        $readable = $exists && is_readable($path);
        $writable = $exists && is_writable($path);
        $writeTest = false;

        if ($exists && $writable && $writeProbe) {
            $writeTest = self::writeProbe($path);
        }

        $ok = $exists
            && $readable
            && $writable
            && (!$writeProbe || $writeTest);

        return [
            'ok' => $ok,
            'exists' => $exists,
            'readable' => $readable,
            'writable' => $writable,
            'write_test' => $writeProbe ? $writeTest : null,
        ];
    }

    private static function writeProbe(string $directory): bool
    {
        $filePath = rtrim($directory, '/\\') . '/.healthcheck_' . bin2hex(random_bytes(8)) . '.tmp';

        try {
            $written = file_put_contents($filePath, 'ok ' . date('c'), LOCK_EX);

            if ($written === false) {
                return false;
            }

            return @unlink($filePath);
        } catch (Throwable) {
            if (is_file($filePath)) {
                @unlink($filePath);
            }

            return false;
        }
    }
}
