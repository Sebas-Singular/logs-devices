<?php

declare(strict_types=1);

namespace App\Http;

final class RateLimiter
{
    /**
     * @return array{
     *     allowed: bool,
     *     limit: int,
     *     remaining: int,
     *     retry_after_seconds: int,
     *     reset_at: int,
     *     storage_available: bool
     * }
     */
    public static function hit(
        string $storageDir,
        string $key,
        int $maxAttempts,
        int $windowSeconds
    ): array {
        $now = time();

        if ($maxAttempts <= 0 || $windowSeconds <= 0) {
            return [
                'allowed' => true,
                'limit' => $maxAttempts,
                'remaining' => PHP_INT_MAX,
                'retry_after_seconds' => 0,
                'reset_at' => $now,
                'storage_available' => true,
            ];
        }

        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            return self::failOpen($maxAttempts, $windowSeconds, $now);
        }

        $filePath = rtrim($storageDir, '/\\') . '/' . hash('sha256', $key) . '.json';

        $handle = @fopen($filePath, 'c+');

        if ($handle === false) {
            return self::failOpen($maxAttempts, $windowSeconds, $now);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                return self::failOpen($maxAttempts, $windowSeconds, $now);
            }

            $raw = stream_get_contents($handle);
            $state = is_string($raw) && trim($raw) !== ''
                ? json_decode($raw, true)
                : null;

            if (!is_array($state)) {
                $state = [
                    'window_start' => $now,
                    'count' => 0,
                ];
            }

            $windowStart = isset($state['window_start']) ? (int) $state['window_start'] : $now;
            $count = isset($state['count']) ? (int) $state['count'] : 0;

            if ($now >= ($windowStart + $windowSeconds)) {
                $windowStart = $now;
                $count = 0;
            }

            $resetAt = $windowStart + $windowSeconds;

            if ($count >= $maxAttempts) {
                self::writeState($handle, $windowStart, $count);

                flock($handle, LOCK_UN);
                fclose($handle);

                return [
                    'allowed' => false,
                    'limit' => $maxAttempts,
                    'remaining' => 0,
                    'retry_after_seconds' => max(1, $resetAt - $now),
                    'reset_at' => $resetAt,
                    'storage_available' => true,
                ];
            }

            $count++;
            self::writeState($handle, $windowStart, $count);

            flock($handle, LOCK_UN);
            fclose($handle);

            return [
                'allowed' => true,
                'limit' => $maxAttempts,
                'remaining' => max(0, $maxAttempts - $count),
                'retry_after_seconds' => 0,
                'reset_at' => $resetAt,
                'storage_available' => true,
            ];
        } catch (\Throwable) {
            if (is_resource($handle)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            }

            return self::failOpen($maxAttempts, $windowSeconds, $now);
        }
    }

    private static function writeState(mixed $handle, int $windowStart, int $count): void
    {
        ftruncate($handle, 0);
        rewind($handle);

        fwrite($handle, json_encode([
            'window_start' => $windowStart,
            'count' => $count,
        ], JSON_THROW_ON_ERROR));

        fflush($handle);
    }

    /**
     * Si el storage falla, dejamos pasar la petición.
     *
     * Es mejor perder rate limiting que cortar ingesta real en producción por un problema de permisos.
     *
     * @return array{
     *     allowed: bool,
     *     limit: int,
     *     remaining: int,
     *     retry_after_seconds: int,
     *     reset_at: int,
     *     storage_available: bool
     * }
     */
    private static function failOpen(int $maxAttempts, int $windowSeconds, int $now): array
    {
        return [
            'allowed' => true,
            'limit' => $maxAttempts,
            'remaining' => max(0, $maxAttempts - 1),
            'retry_after_seconds' => 0,
            'reset_at' => $now + max(1, $windowSeconds),
            'storage_available' => false,
        ];
    }
}
