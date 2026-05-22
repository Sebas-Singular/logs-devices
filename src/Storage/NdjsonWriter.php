<?php

declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

final class NdjsonWriter
{
    public function append(string $filePath, array $record): void
    {
        $directory = dirname($filePath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create storage directory: ' . $directory);
        }

        $jsonLine = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($jsonLine === false) {
            throw new RuntimeException('Unable to encode NDJSON record.');
        }

        $handle = fopen($filePath, 'ab');

        if ($handle === false) {
            throw new RuntimeException('Unable to open NDJSON file: ' . $filePath);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock NDJSON file: ' . $filePath);
            }

            fwrite($handle, $jsonLine . PHP_EOL);
            fflush($handle);

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }
}