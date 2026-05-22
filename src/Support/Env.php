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

            if ($key === '') {
                continue;
            }

            $value = trim($value, "\"'");

            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        if (array_key_exists($key, $_ENV)) {
            return is_scalar($_ENV[$key]) ? (string) $_ENV[$key] : $default;
        }

        $env = getenv($key);

        return $env !== false ? (string) $env : $default;
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
            if (!is_string($key) || $key === '') {
                continue;
            }

            if ($value === null) {
                continue;
            }

            if (is_bool($value)) {
                $stringValue = $value ? 'true' : 'false';
            } elseif (is_scalar($value)) {
                $stringValue = (string) $value;
            } else {
                continue;
            }

            self::$values[$key] = $stringValue;
            $_ENV[$key] = $stringValue;
            putenv($key . '=' . $stringValue);
        }
    }

    public static function setDefault(string $key, string $value): void
    {
        if (!array_key_exists($key, self::$values)) {
            self::$values[$key] = $value;
        }
    }
}
