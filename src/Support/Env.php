<?php

declare(strict_types=1);

namespace App\Support;

final class Env
{
    private static array $values = [];

    public static function load(string $path): void
    {
        self::$values = [];

        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            $value = trim($value, "\"'");

            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$values[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }

    public static function loadPhpConfig(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $values = require $path;

        if (!is_array($values)) {
            return;
        }

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            if (is_bool($value)) {
                self::$values[$key] = $value ? 'true' : 'false';
                continue;
            }

            if (is_scalar($value)) {
                self::$values[$key] = (string) $value;
            }
        }
    }

    public static function setDefault(string $key, string $value): void
    {
        if (!array_key_exists($key, self::$values)) {
            self::$values[$key] = $value;
        }
    }
}
