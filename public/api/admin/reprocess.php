<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Http\JsonResponse;
use App\Ingest\StoredIngestProcessor;
use App\Support\Env;
use App\Http\SecurityHeaders;

require_once __DIR__ . '/../../../vendor/autoload.php';
SecurityHeaders::applyJson();

use App\Support\Bootstrap;

Bootstrap::init();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    JsonResponse::send([
        'ok' => false,
        'error' => 'method_not_allowed',
        'message' => 'Only POST is allowed.',
    ], 405);
}

if (!envFlag('REPROCESS_ENABLED')) {
    JsonResponse::send([
        'ok' => false,
        'error' => 'reprocess_disabled',
        'message' => 'Reprocess endpoint is disabled.',
    ], 403);
}

$expectedToken = (string) Env::get('ADMIN_REPROCESS_TOKEN', '');
$providedToken = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');

if ($expectedToken === '') {
    JsonResponse::send([
        'ok' => false,
        'error' => 'admin_token_not_configured',
        'message' => 'Admin reprocess token is not configured.',
    ], 500);
}

if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    JsonResponse::send([
        'ok' => false,
        'error' => 'invalid_admin_token',
        'message' => 'Invalid admin token.',
    ], 401);
}

$id = readOptionalPositiveInt('id');
$limit = readLimit();
$retryErrors = readBoolParam('retry_errors') || readBoolParam('retryErrors');
$force = readBoolParam('force');

if ($force && $id === null) {
    JsonResponse::send([
        'ok' => false,
        'error' => 'force_requires_id',
        'message' => 'force is only allowed when processing a single ingest by id.',
    ], 422);
}

try {
    $pdo = Connection::make();
    $processor = new StoredIngestProcessor($pdo);

    if ($id !== null) {
        $result = $processor->processOne(
            ingestId: $id,
            force: $force,
            retryErrors: $retryErrors,
        );

        JsonResponse::send([
            'ok' => $result['outcome'] !== 'error',
            'mode' => 'single',
            'id' => $id,
            'force' => $force,
            'retry_errors' => $retryErrors,
            'result' => $result,
            'remaining' => $processor->countCandidates($retryErrors),
        ], $result['outcome'] === 'error' ? 500 : 200);
    }

    $summary = $processor->processBatch(
        limit: $limit,
        retryErrors: $retryErrors,
    );

    JsonResponse::send([
        'ok' => $summary['failed'] === 0,
        'mode' => 'batch',
        'limit' => $limit,
        'retry_errors' => $retryErrors,
        'processed' => $summary['processed'],
        'failed' => $summary['failed'],
        'skipped' => $summary['skipped'],
        'total_candidates' => $summary['total_candidates'],
        'remaining' => $processor->countCandidates($retryErrors),
        'results' => $summary['results'],
    ]);
} catch (Throwable $exception) {
    JsonResponse::send([
        'ok' => false,
        'error' => 'reprocess_failed',
        'message' => $exception->getMessage(),
    ], 500);
}

function envFlag(string $key): bool
{
    $value = strtolower(trim((string) Env::get($key, 'false')));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function readOptionalPositiveInt(string $key): ?int
{
    if (!isset($_GET[$key]) || trim((string) $_GET[$key]) === '') {
        return null;
    }

    $value = filter_var($_GET[$key], FILTER_VALIDATE_INT, [
        'options' => [
            'min_range' => 1,
        ],
    ]);

    if ($value === false) {
        JsonResponse::send([
            'ok' => false,
            'error' => 'invalid_' . $key,
            'message' => "{$key} must be a positive integer.",
        ], 422);
    }

    return (int) $value;
}

function readLimit(): int
{
    if (!isset($_GET['limit']) || trim((string) $_GET['limit']) === '') {
        return 10;
    }

    $value = filter_var($_GET['limit'], FILTER_VALIDATE_INT, [
        'options' => [
            'min_range' => 1,
            'max_range' => 20,
        ],
    ]);

    if ($value === false) {
        JsonResponse::send([
            'ok' => false,
            'error' => 'invalid_limit',
            'message' => 'limit must be an integer between 1 and 20.',
        ], 422);
    }

    return (int) $value;
}

function readBoolParam(string $key): bool
{
    if (!isset($_GET[$key])) {
        return false;
    }

    $value = strtolower(trim((string) $_GET[$key]));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}
