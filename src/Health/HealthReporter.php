<?php

declare(strict_types=1);

namespace App\Health;

use PDO;

final class HealthReporter
{
    /**
     * Tiempo en minutos a partir del cual un ingest en status 'parsing'
     * se considera zombie (PHP-FPM lo mató antes de terminar).
     * Debe coincidir con StoredIngestProcessor::ZOMBIE_TIMEOUT_MINUTES.
     */
    private const ZOMBIE_TIMEOUT_MINUTES = 10;

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

        $zombieStmt = $pdo->prepare(
            'SELECT COUNT(*) AS total
               FROM log_ingests
              WHERE status = :status
                AND processing_started_at < DATE_SUB(NOW(), INTERVAL :minutes MINUTE)'
        );
        $zombieStmt->bindValue('status', 'parsing');
        $zombieStmt->bindValue('minutes', self::ZOMBIE_TIMEOUT_MINUTES, PDO::PARAM_INT);
        $zombieStmt->execute();
        $zombieCount = (int) $zombieStmt->fetchColumn();

        return [
            'ok'                        => true,
            'database_name'             => (string) ($row['database_name'] ?? ''),
            'database_time'             => (string) ($row['database_time'] ?? ''),
            'ingest_status_counts'      => $statusCounts,
            'latest_ingest_received_at' => $latestIngestReceivedAt !== false ? $latestIngestReceivedAt : null,
            'parsing_zombie_count'      => $zombieCount,
        ];
    }

    public static function storage(string $baseDir): array
    {
        return [
            'base'       => self::directory($baseDir),
            'raw'        => self::directory($baseDir . '/raw'),
            'rejected'   => self::directory($baseDir . '/rejected'),
            'archive'    => self::directory($baseDir . '/archive'),
            'rate_limit' => self::directory($baseDir . '/rate-limit'),
        ];
    }

    public static function extensions(): array
    {
        $curlAvailable  = extension_loaded('curl') && function_exists('curl_init');
        $allowUrlFopen  = (bool) ini_get('allow_url_fopen');

        $forwardMethod = match (true) {
            $curlAvailable => 'curl',
            $allowUrlFopen => 'stream',
            default        => 'none',
        };

        return [
            'curl_available'   => $curlAvailable,
            'allow_url_fopen'  => $allowUrlFopen,
            'forward_method'   => $forwardMethod,
        ];
    }

    private static function directory(string $path): array
    {
        $exists   = is_dir($path);
        $readable = $exists && is_readable($path);
        $writable = $exists && is_writable($path);

        $ok = $exists && $readable && $writable;

        return [
            'ok'           => $ok,
            'exists'       => $exists,
            'readable'     => $readable,
            'writable'     => $writable,
            'write_test'   => null,
            'write_probe'  => 'disabled',
        ];
    }
}
