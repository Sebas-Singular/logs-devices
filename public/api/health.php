<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Health\HealthReporter;
use App\Http\SecurityHeaders;
use App\Storage\Paths;
use App\Support\Bootstrap;
use App\Support\Env;

require_once __DIR__ . '/../../vendor/autoload.php';

SecurityHeaders::applyJson();

Bootstrap::init();

$appEnv = (string) Env::get('APP_ENV', 'unknown');
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'public')));
$fullModeRequested = $mode === 'full';

if ($fullModeRequested) {
    enforceFullHealthAuth();
}

$storageBaseDir = rtrim(Paths::for(''), '/\\');

$databaseCheck = [
    'ok' => false,
    'message' => 'Database check failed.',
];

try {
    $pdo = Connection::make();
    $databaseCheck = HealthReporter::database($pdo);
} catch (Throwable $exception) {
    if ($fullModeRequested && $appEnv !== 'production') {
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

$databaseOk = (bool) ($databaseCheck['ok'] ?? false);
$ok = $databaseOk && $criticalStorageOk;

http_response_code($ok ? 200 : 503);

if ($fullModeRequested) {
    echo json_encode([
        'ok' => $ok,
        'app' => 'logs-devices',
        'mode' => 'full',
        'env' => $appEnv,
        'php' => PHP_VERSION,

        'database' => $databaseOk ? 'ok' : 'error',
        'database_name' => $databaseCheck['database_name'] ?? null,
        'database_time' => $databaseCheck['database_time'] ?? null,

        'checks' => [
            'database' => $databaseCheck,
            'storage' => $storageCheck,
        ],
        'warnings' => $warnings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

echo json_encode([
    'ok' => $ok,
    'app' => 'logs-devices',
    'mode' => 'public',
    'env' => $appEnv,

    'database' => $databaseOk ? 'ok' : 'error',
    'storage' => $criticalStorageOk ? 'ok' : 'error',

    'warnings_count' => count($warnings),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;

function enforceFullHealthAuth(): void
{
    $expectedToken = (string) Env::get('ADMIN_REPROCESS_TOKEN', '');
    $providedToken = readAdminToken();

    if ($expectedToken === '') {
        http_response_code(500);

        echo json_encode([
            'ok' => false,
            'error' => 'admin_token_not_configured',
            'message' => 'Admin token is not configured.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        exit;
    }

    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(401);

        echo json_encode([
            'ok' => false,
            'error' => 'invalid_admin_token',
            'message' => 'Invalid admin token.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        exit;
    }
}

function readAdminToken(): string
{
    $headerToken = trim((string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''));

    if ($headerToken !== '') {
        return $headerToken;
    }

    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

    if ($authorization === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();

        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strtolower((string) $key) === 'authorization') {
                    $authorization = trim((string) $value);
                    break;
                }
            }
        }
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
        return trim((string) $matches[1]);
    }

    return '';
}
