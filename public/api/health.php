<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Health\HealthReporter;
use App\Http\SecurityHeaders;
use App\Support\Env;

require_once __DIR__ . '/../../vendor/autoload.php';

SecurityHeaders::applyJson();

Env::load(__DIR__ . '/../../../../private/.env');
Env::loadPhpConfig(__DIR__ . '/../../src/Config/runtime.local.php');

$appEnv = (string) (getenv('APP_ENV') ?: 'unknown');
$storageBaseDir = __DIR__ . '/../../../../private/storage';

$databaseCheck = [
    'ok' => false,
    'message' => 'Database check failed.',
];

try {
    $pdo = Connection::make();
    $databaseCheck = HealthReporter::database($pdo);
} catch (Throwable $exception) {
    if ($appEnv !== 'production') {
        $databaseCheck['error_class'] = $exception::class;
        $databaseCheck['error_message'] = $exception->getMessage();
    }
}

$storageCheck = HealthReporter::storage($storageBaseDir);

$criticalStorageOk = ($storageCheck['raw']['ok'] ?? false)
    && ($storageCheck['rejected']['ok'] ?? false);

$warnings = [];

if (($storageCheck['archive']['ok'] ?? false) === false) {
    $warnings[] = 'storage_archive_not_ready';
}

if (($storageCheck['rate_limit']['ok'] ?? false) === false) {
    $warnings[] = 'storage_rate_limit_not_ready';
}

$ok = ((bool) ($databaseCheck['ok'] ?? false)) && $criticalStorageOk;

http_response_code($ok ? 200 : 503);

echo json_encode([
    'ok' => $ok,
    'app' => 'logs-devices',
    'env' => $appEnv,
    'php' => PHP_VERSION,

    'database' => ($databaseCheck['ok'] ?? false) ? 'ok' : 'error',
    'database_name' => $databaseCheck['database_name'] ?? null,
    'database_time' => $databaseCheck['database_time'] ?? null,

    'checks' => [
        'database' => $databaseCheck,
        'storage' => $storageCheck,
    ],
    'warnings' => $warnings,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
