<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Http\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logs-devices-rate-limiter-' . bin2hex(random_bytes(8));

        mkdir($this->storageDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageDir);
    }

    public function testHit_withinLimit_allowsRequestsAndTracksRemaining(): void
    {
        $first = RateLimiter::hit(
            storageDir: $this->storageDir,
            key: 'ip:127.0.0.1|ua:test|auth:test',
            maxAttempts: 2,
            windowSeconds: 60
        );

        $this->assertTrue($first['allowed']);
        $this->assertSame(2, $first['limit']);
        $this->assertSame(1, $first['remaining']);
        $this->assertSame(0, $first['retry_after_seconds']);
        $this->assertTrue($first['storage_available']);

        $second = RateLimiter::hit(
            storageDir: $this->storageDir,
            key: 'ip:127.0.0.1|ua:test|auth:test',
            maxAttempts: 2,
            windowSeconds: 60
        );

        $this->assertTrue($second['allowed']);
        $this->assertSame(0, $second['remaining']);

        $third = RateLimiter::hit(
            storageDir: $this->storageDir,
            key: 'ip:127.0.0.1|ua:test|auth:test',
            maxAttempts: 2,
            windowSeconds: 60
        );

        $this->assertFalse($third['allowed']);
        $this->assertSame(0, $third['remaining']);
        $this->assertGreaterThan(0, $third['retry_after_seconds']);
    }

    public function testHit_withDifferentKeys_usesIndependentBuckets(): void
    {
        $firstKey = RateLimiter::hit(
            storageDir: $this->storageDir,
            key: 'key-a',
            maxAttempts: 1,
            windowSeconds: 60
        );

        $secondKey = RateLimiter::hit(
            storageDir: $this->storageDir,
            key: 'key-b',
            maxAttempts: 1,
            windowSeconds: 60
        );

        $this->assertTrue($firstKey['allowed']);
        $this->assertTrue($secondKey['allowed']);

        $firstKeyAgain = RateLimiter::hit(
            storageDir: $this->storageDir,
            key: 'key-a',
            maxAttempts: 1,
            windowSeconds: 60
        );

        $this->assertFalse($firstKeyAgain['allowed']);
    }

    public function testPrune_deletesOnlyOldJsonFiles(): void
    {
        $now = time();

        $oldJson = $this->storageDir . DIRECTORY_SEPARATOR . 'old.json';
        $freshJson = $this->storageDir . DIRECTORY_SEPARATOR . 'fresh.json';
        $oldTxt = $this->storageDir . DIRECTORY_SEPARATOR . 'old.txt';

        file_put_contents($oldJson, '{"window_start":1,"count":1}');
        file_put_contents($freshJson, '{"window_start":2,"count":1}');
        file_put_contents($oldTxt, 'do-not-delete');

        touch($oldJson, $now - 7200);
        touch($freshJson, $now - 30);
        touch($oldTxt, $now - 7200);

        $deleted = RateLimiter::prune(
            storageDir: $this->storageDir,
            olderThanSeconds: 3600,
            now: $now,
            maxDeletes: 50
        );

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($oldJson);
        $this->assertFileExists($freshJson);
        $this->assertFileExists($oldTxt);
    }

    public function testPrune_respectsMaxDeletes(): void
    {
        $now = time();

        for ($i = 1; $i <= 5; $i++) {
            $path = $this->storageDir . DIRECTORY_SEPARATOR . 'old-' . $i . '.json';
            file_put_contents($path, '{"window_start":1,"count":1}');
            touch($path, $now - 7200);
        }

        $deleted = RateLimiter::prune(
            storageDir: $this->storageDir,
            olderThanSeconds: 3600,
            now: $now,
            maxDeletes: 2
        );

        $this->assertSame(2, $deleted);

        $remainingJsonFiles = glob($this->storageDir . DIRECTORY_SEPARATOR . '*.json');

        $this->assertIsArray($remainingJsonFiles);
        $this->assertCount(3, $remainingJsonFiles);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                $this->removeDirectory($fullPath);
                continue;
            }

            @unlink($fullPath);
        }

        @rmdir($path);
    }
}
