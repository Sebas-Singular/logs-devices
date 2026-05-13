<?php

declare(strict_types=1);

namespace App\Storage;

use DateTimeImmutable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

final class RawArchiveManager
{
    /**
     * @return array{
     *     ok: bool,
     *     mode: string,
     *     storage_base_dir: string,
     *     raw_dir: string,
     *     archive_dir: string,
     *     min_age_days: int,
     *     limit: int,
     *     delete_source: bool,
     *     scanned_files: int,
     *     candidate_files: int,
     *     archived_files: int,
     *     skipped_files: int,
     *     failed_files: int,
     *     total_candidate_bytes: int,
     *     total_archived_bytes: int,
     *     results: array<int, array<string, mixed>>
     * }
     */
    public function run(
        string $storageBaseDir,
        bool $execute,
        bool $deleteSource,
        int $minAgeDays = 7,
        int $limit = 100
    ): array {
        $storageBaseDir = rtrim($storageBaseDir, '/\\');
        $rawDir = $storageBaseDir . '/raw';
        $archiveDir = $storageBaseDir . '/archive/raw';

        $minAgeDays = max(1, $minAgeDays);
        $limit = max(1, min($limit, 500));

        $summary = [
            'ok' => true,
            'mode' => $execute ? 'execute' : 'dry-run',
            'storage_base_dir' => $storageBaseDir,
            'raw_dir' => $rawDir,
            'archive_dir' => $archiveDir,
            'min_age_days' => $minAgeDays,
            'limit' => $limit,
            'delete_source' => $deleteSource,
            'scanned_files' => 0,
            'candidate_files' => 0,
            'archived_files' => 0,
            'skipped_files' => 0,
            'failed_files' => 0,
            'total_candidate_bytes' => 0,
            'total_archived_bytes' => 0,
            'results' => [],
        ];

        if (!is_dir($rawDir)) {
            throw new RuntimeException('Raw storage directory does not exist: ' . $rawDir);
        }

        if ($execute && !is_dir($archiveDir) && !mkdir($archiveDir, 0775, true) && !is_dir($archiveDir)) {
            throw new RuntimeException('Cannot create archive directory: ' . $archiveDir);
        }

        foreach ($this->ndjsonFiles($rawDir) as $file) {
            $summary['scanned_files']++;

            if (count($summary['results']) >= $limit) {
                break;
            }

            $sourcePath = $file->getPathname();

            if (!$this->isEligibleByPathDate($sourcePath, $rawDir, $minAgeDays)) {
                continue;
            }

            $relativePath = $this->relativePath($sourcePath, $rawDir);
            $archivePath = $archiveDir . '/' . $relativePath . '.gz';
            $sourceBytes = $file->getSize();

            $summary['candidate_files']++;
            $summary['total_candidate_bytes'] += $sourceBytes;

            if (is_file($archivePath)) {
                $summary['skipped_files']++;
                $summary['results'][] = [
                    'outcome' => 'skipped',
                    'reason' => 'archive_already_exists',
                    'source_path' => $sourcePath,
                    'archive_path' => $archivePath,
                    'source_bytes' => $sourceBytes,
                ];
                continue;
            }

            if (!$execute) {
                $summary['results'][] = [
                    'outcome' => 'candidate',
                    'source_path' => $sourcePath,
                    'archive_path' => $archivePath,
                    'source_bytes' => $sourceBytes,
                ];
                continue;
            }

            try {
                $archiveBytes = $this->archiveFile($sourcePath, $archivePath, $deleteSource);

                $summary['archived_files']++;
                $summary['total_archived_bytes'] += $archiveBytes;
                $summary['results'][] = [
                    'outcome' => 'archived',
                    'source_path' => $sourcePath,
                    'archive_path' => $archivePath,
                    'source_bytes' => $sourceBytes,
                    'archive_bytes' => $archiveBytes,
                    'source_deleted' => $deleteSource,
                ];
            } catch (Throwable $exception) {
                $summary['ok'] = false;
                $summary['failed_files']++;
                $summary['results'][] = [
                    'outcome' => 'failed',
                    'source_path' => $sourcePath,
                    'archive_path' => $archivePath,
                    'source_bytes' => $sourceBytes,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $summary;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function ndjsonFiles(string $rawDir): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rawDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'ndjson') {
                continue;
            }

            yield $file;
        }
    }

    private function isEligibleByPathDate(string $sourcePath, string $rawDir, int $minAgeDays): bool
    {
        $relativePath = str_replace('\\', '/', $this->relativePath($sourcePath, $rawDir));

        if (!preg_match('#^(\d{4})/(\d{2})/(\d{2})/#', $relativePath, $matches)) {
            return false;
        }

        $fileDate = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            "{$matches[1]}-{$matches[2]}-{$matches[3]} 00:00:00"
        );

        if ($fileDate === false) {
            return false;
        }

        $cutoff = (new DateTimeImmutable('today'))->modify('-' . $minAgeDays . ' days');

        return $fileDate < $cutoff;
    }

    private function archiveFile(string $sourcePath, string $archivePath, bool $deleteSource): int
    {
        $archiveDirectory = dirname($archivePath);

        if (!is_dir($archiveDirectory) && !mkdir($archiveDirectory, 0775, true) && !is_dir($archiveDirectory)) {
            throw new RuntimeException('Cannot create archive subdirectory: ' . $archiveDirectory);
        }

        $raw = file_get_contents($sourcePath);

        if ($raw === false) {
            throw new RuntimeException('Cannot read source file.');
        }

        if (!function_exists('gzencode')) {
            throw new RuntimeException('PHP gzencode() is not available.');
        }

        $compressed = gzencode($raw, 6);

        if ($compressed === false) {
            throw new RuntimeException('Cannot compress source file.');
        }

        $tmpPath = $archivePath . '.tmp';

        $written = file_put_contents($tmpPath, $compressed, LOCK_EX);

        if ($written === false) {
            throw new RuntimeException('Cannot write archive file.');
        }

        if (!rename($tmpPath, $archivePath)) {
            @unlink($tmpPath);
            throw new RuntimeException('Cannot move temporary archive file into final path.');
        }

        if ($deleteSource && !unlink($sourcePath)) {
            throw new RuntimeException('Archive created, but source file could not be deleted.');
        }

        return (int) filesize($archivePath);
    }

    private function relativePath(string $path, string $baseDir): string
    {
        $path = str_replace('\\', '/', $path);
        $baseDir = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';

        if (!str_starts_with($path, $baseDir)) {
            return basename($path);
        }

        return substr($path, strlen($baseDir));
    }
}
