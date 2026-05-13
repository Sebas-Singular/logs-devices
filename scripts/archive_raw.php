<?php

declare(strict_types=1);

use App\Storage\RawArchiveManager;
use App\Support\Env;

require_once __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../../../private/.env');
Env::loadPhpConfig(__DIR__ . '/../src/Config/runtime.local.php');

$options = getopt('', [
    'execute',
    'delete-source',
    'min-age-days::',
    'limit::',
]);

$execute = array_key_exists('execute', $options);
$deleteSource = array_key_exists('delete-source', $options);
$minAgeDays = readPositiveIntOption($options, 'min-age-days', 7, 1, 3650);
$limit = readPositiveIntOption($options, 'limit', 100, 1, 500);

$storageBaseDir = __DIR__ . '/../../../private/storage';

echo "logs-devices raw archive\n";
echo 'Mode: ' . ($execute ? 'execute' : 'dry-run') . PHP_EOL;
echo 'Delete source: ' . ($deleteSource ? 'yes' : 'no') . PHP_EOL;
echo 'Min age days: ' . $minAgeDays . PHP_EOL;
echo 'Limit: ' . $limit . PHP_EOL;
echo PHP_EOL;

$manager = new RawArchiveManager();

try {
    $result = $manager->run(
        storageBaseDir: $storageBaseDir,
        execute: $execute,
        deleteSource: $deleteSource,
        minAgeDays: $minAgeDays,
        limit: $limit,
    );
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($result['results'] as $row) {
    $outcome = strtoupper((string) $row['outcome']);
    $source = (string) ($row['source_path'] ?? '');
    $archive = (string) ($row['archive_path'] ?? '');
    $bytes = (int) ($row['source_bytes'] ?? 0);

    echo "[{$outcome}] {$source} -> {$archive} ({$bytes} bytes)";

    if (isset($row['reason'])) {
        echo ' reason=' . $row['reason'];
    }

    if (isset($row['error'])) {
        echo ' error=' . $row['error'];
    }

    echo PHP_EOL;
}

echo PHP_EOL;
echo "Summary\n";
echo 'Scanned files:          ' . $result['scanned_files'] . PHP_EOL;
echo 'Candidate files:        ' . $result['candidate_files'] . PHP_EOL;
echo 'Archived files:         ' . $result['archived_files'] . PHP_EOL;
echo 'Skipped files:          ' . $result['skipped_files'] . PHP_EOL;
echo 'Failed files:           ' . $result['failed_files'] . PHP_EOL;
echo 'Total candidate bytes:  ' . $result['total_candidate_bytes'] . PHP_EOL;
echo 'Total archived bytes:   ' . $result['total_archived_bytes'] . PHP_EOL;

exit($result['ok'] ? 0 : 1);

function readPositiveIntOption(array $options, string $key, int $default, int $min, int $max): int
{
    if (!array_key_exists($key, $options)) {
        return $default;
    }

    $raw = $options[$key];

    if ($raw === false || $raw === '') {
        return $default;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT);

    if ($value === false) {
        fwrite(STDERR, "Invalid --{$key}. Must be an integer.\n");
        exit(1);
    }

    $value = (int) $value;

    if ($value < $min || $value > $max) {
        fwrite(STDERR, "Invalid --{$key}. Must be between {$min} and {$max}.\n");
        exit(1);
    }

    return $value;
}
