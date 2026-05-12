<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Ingest\StoredIngestProcessor;
use App\Support\Env;

require_once __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../../../private/.env');
Env::loadPhpConfig(__DIR__ . '/../src/Config/runtime.local.php');

$options = getopt('', [
    'limit::',
    'id::',
    'dry-run',
    'retry-errors',
    'force',
]);

$limit = isset($options['limit'])
    ? max(1, min((int) $options['limit'], 500))
    : 50;

$id = isset($options['id'])
    ? (int) $options['id']
    : null;

$dryRun = array_key_exists('dry-run', $options);
$retryErrors = array_key_exists('retry-errors', $options);
$force = array_key_exists('force', $options);

if ($force && $id === null) {
    fwrite(STDERR, "ERROR: --force requires --id. Refusing force batch reprocess.\n");
    exit(1);
}

$pdo = Connection::make();
$processor = new StoredIngestProcessor($pdo);

echo "logs-devices reprocess ingests\n";
echo "Mode: " . ($dryRun ? "dry-run" : "execute") . "\n";
echo "Retry errors: " . ($retryErrors ? "yes" : "no") . "\n";
echo "Force: " . ($force ? "yes" : "no") . "\n";

if ($id !== null) {
    echo "Target ingest id: {$id}\n\n";

    if ($dryRun) {
        $ingest = $processor->getIngest($id);

        if ($ingest === null) {
            echo "[NOT FOUND] ingest_id={$id}\n";
            exit(1);
        }

        echo "[DRY-RUN] ingest_id={$ingest['id']} status={$ingest['status']} raw_path={$ingest['raw_path']}\n";
        exit(0);
    }

    $result = $processor->processOne(
        ingestId: $id,
        force: $force,
        retryErrors: $retryErrors,
    );

    printResult($result);

    exit($result['outcome'] === 'error' ? 1 : 0);
}

echo "Limit: {$limit}\n\n";

if ($dryRun) {
    $candidates = $processor->listCandidates($limit, $retryErrors);

    if ($candidates === []) {
        echo "No candidates found.\n";
        exit(0);
    }

    foreach ($candidates as $candidate) {
        echo sprintf(
            "[DRY-RUN] ingest_id=%d status=%s bridge=%s lines=%d raw_path=%s\n",
            (int) $candidate['id'],
            (string) $candidate['status'],
            (string) ($candidate['bridge_id_reported'] ?? ''),
            (int) $candidate['line_count'],
            (string) $candidate['raw_path']
        );
    }

    echo "\nCandidates: " . count($candidates) . "\n";
    exit(0);
}

$summary = $processor->processBatch(
    limit: $limit,
    retryErrors: $retryErrors,
);

foreach ($summary['results'] as $result) {
    printResult($result);
}

echo "\nSummary\n";
echo "Candidates: {$summary['total_candidates']}\n";
echo "Processed:  {$summary['processed']}\n";
echo "Failed:     {$summary['failed']}\n";
echo "Skipped:    {$summary['skipped']}\n";

exit($summary['failed'] > 0 ? 1 : 0);

function printResult(array $result): void
{
    $id = (int) $result['id'];
    $outcome = (string) $result['outcome'];

    if ($outcome === 'processed') {
        echo sprintf(
            "[OK] ingest_id=%d bridge=%s lines=%d parsed_ok=%d parsed_error=%d events_inserted=%d\n",
            $id,
            (string) $result['bridge_id'],
            (int) $result['line_count'],
            (int) $result['parsed_ok'],
            (int) $result['parsed_error'],
            (int) $result['events_inserted']
        );

        return;
    }

    if ($outcome === 'skipped') {
        echo sprintf(
            "[SKIP] ingest_id=%d status=%s reason=%s\n",
            $id,
            (string) ($result['status'] ?? ''),
            (string) ($result['reason'] ?? '')
        );

        return;
    }

    echo sprintf(
        "[ERROR] ingest_id=%d error=%s\n",
        $id,
        (string) ($result['error'] ?? 'unknown error')
    );
}