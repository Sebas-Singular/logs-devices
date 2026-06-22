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

register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if (!in_array($error['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'ok' => false,
        'app' => 'logs-devices',
        'mode' => 'public',
        'error' => 'bootstrap_fatal',
        'message' => 'Application bootstrap failed.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
});

try {
    Bootstrap::init();
} catch (Throwable $exception) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'app' => 'logs-devices',
        'mode' => 'public',
        'error' => 'bootstrap_failed',
        'message' => 'Application configuration is not available.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

$appEnv             = (string) Env::get('APP_ENV', 'unknown');
$mode               = strtolower(trim((string) ($_GET['mode'] ?? 'public')));
$fullModeRequested  = $mode === 'full';

if ($fullModeRequested) {
    enforceFullHealthAuth();
}

$storageBaseDir = rtrim(Paths::for(''), '/\\');

$databaseCheck = [
    'ok'      => false,
    'message' => 'Database check failed.',
];

try {
    $pdo           = Connection::make();
    $databaseCheck = HealthReporter::database($pdo);
} catch (Throwable $exception) {
    if ($fullModeRequested && $appEnv !== 'production') {
        $databaseCheck['error_class']   = $exception::class;
        $databaseCheck['error_message'] = $exception->getMessage();
    }
}

$storageCheck    = HealthReporter::storage($storageBaseDir);
$extensionsCheck = HealthReporter::extensions();

$criticalStorageOk = ($storageCheck['raw']['ok'] ?? false)
    && ($storageCheck['rejected']['ok'] ?? false);

$warnings    = [];
$zombieCount = (int) ($databaseCheck['parsing_zombie_count'] ?? 0);

if (($storageCheck['archive']['ok'] ?? false) === false) {
    $warnings[] = 'storage_archive_not_ready';
}

if (($storageCheck['rate_limit']['ok'] ?? false) === false) {
    $warnings[] = 'storage_rate_limit_not_ready';
}

if ($zombieCount > 0) {
    $warnings[] = 'parsing_zombies_detected';
}

if (($extensionsCheck['forward_method'] ?? 'none') === 'none') {
    $warnings[] = 'forward_unavailable';
}

$databaseOk = (bool) ($databaseCheck['ok'] ?? false);
$ok         = $databaseOk && $criticalStorageOk;

http_response_code($ok ? 200 : 503);

if ($fullModeRequested) {
    echo json_encode([
        'ok'      => $ok,
        'app'     => 'logs-devices',
        'mode'    => 'full',
        'env'     => $appEnv,
        'php'     => PHP_VERSION,

        'database'      => $databaseOk ? 'ok' : 'error',
        'database_name' => $databaseCheck['database_name'] ?? null,
        'database_time' => $databaseCheck['database_time'] ?? null,

        'parsing_zombie_count' => $zombieCount,
        'forward_method'       => $extensionsCheck['forward_method'],

        'checks' => [
            'database'   => $databaseCheck,
            'storage'    => $storageCheck,
            'extensions' => $extensionsCheck,
        ],
        'warnings' => $warnings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

echo json_encode([
    'ok'  => $ok,
    'app' => 'logs-devices',
    'mode' => 'public',
    'env'  => $appEnv,

    'database' => $databaseOk ? 'ok' : 'error',
    'storage'  => $criticalStorageOk ? 'ok' : 'error',

    'parsing_zombie_count' => $zombieCount,
    'forward_method'       => $extensionsCheck['forward_method'],

    'warnings_count' => count($warnings),
    'warnings'       => $warnings,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;

function enforceFullHealthAuth(): void
{
    $expectedToken = (string) Env::get('ADMIN_REPROCESS_TOKEN', '');
    $providedToken = readAdminToken();

    if ($expectedToken === '') {
        http_response_code(500);

        echo json_encode([
            'ok'      => false,
            'error'   => 'admin_token_not_configured',
            'message' => 'Admin token is not configured.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        exit;
    }

    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(401);

        echo json_encode([
            'ok'      => false,
            'error'   => 'invalid_admin_token',
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
