<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\Storage\Paths;
use App\Storage\RawArchiveManager;
use App\Support\Bootstrap;

require_once __DIR__ . '/../../../vendor/autoload.php';

SecurityHeaders::applyJson();

Bootstrap::init();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST');

    echo json_encode([
        'ok' => false,
        'error' => 'method_not_allowed',
        'message' => 'Only POST is allowed.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

if (!archiveEndpointEnabled()) {
    http_response_code(403);

    echo json_encode([
        'ok' => false,
        'error' => 'archive_disabled',
        'message' => 'Archive endpoint is disabled.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

$expectedToken = (string) (getenv('ADMIN_TOKEN') ?: '');
$providedToken = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');

if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);

    echo json_encode([
        'ok' => false,
        'error' => 'invalid_admin_token',
        'message' => 'Invalid admin token.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

$execute = readBoolQuery('execute', false);
$deleteSource = readBoolQuery('delete_source', false);
$minAgeDays = readIntQuery('min_age_days', 7, 1, 3650);
$limit = readIntQuery('limit', 100, 1, 500);

if (!$execute && $deleteSource) {
    http_response_code(422);

    echo json_encode([
        'ok' => false,
        'error' => 'delete_source_requires_execute',
        'message' => 'delete_source can only be used with execute=1.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

$storageBaseDir = rtrim(Paths::for(''), '/\\');

try {
    $manager = new RawArchiveManager();

    $result = $manager->run(
        storageBaseDir: $storageBaseDir,
        execute: $execute,
        deleteSource: $deleteSource,
        minAgeDays: $minAgeDays,
        limit: $limit,
    );

    http_response_code($result['ok'] ? 200 : 500);

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => 'archive_failed',
        'message' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function archiveEndpointEnabled(): bool
{
    $raw = getenv('ARCHIVE_RAW_ENDPOINT_ENABLED');

    if ($raw === false || trim((string) $raw) === '') {
        return false;
    }

    return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
}

function readBoolQuery(string $key, bool $default): bool
{
    $raw = trim((string) ($_GET[$key] ?? ''));

    if ($raw === '') {
        return $default;
    }

    if (in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    if (in_array(strtolower($raw), ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    http_response_code(422);

    echo json_encode([
        'ok' => false,
        'error' => 'invalid_boolean',
        'message' => "{$key} must be boolean.",
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

function readIntQuery(string $key, int $default, int $min, int $max): int
{
    $raw = trim((string) ($_GET[$key] ?? ''));

    if ($raw === '') {
        return $default;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT);

    if ($value === false) {
        http_response_code(422);

        echo json_encode([
            'ok' => false,
            'error' => 'invalid_integer',
            'message' => "{$key} must be an integer.",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        exit;
    }

    $value = (int) $value;

    if ($value < $min || $value > $max) {
        http_response_code(422);

        echo json_encode([
            'ok' => false,
            'error' => 'integer_out_of_range',
            'message' => "{$key} must be between {$min} and {$max}.",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        exit;
    }

    return $value;
}
