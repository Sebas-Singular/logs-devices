<?php

declare(strict_types=1);

namespace App\Viewer;

final class ViewerFormatter
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function nullable(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? '—' : self::e($text);
    }

    public static function datetime(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? '—' : self::e($text);
    }

    public static function number(mixed $value): string
    {
        return number_format((int) $value, 0, ',', '.');
    }

    public static function shortText(mixed $value, int $maxLength = 160): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return '—';
        }

        $length = function_exists('mb_strlen')
            ? mb_strlen($text, 'UTF-8')
            : strlen($text);

        if ($length <= $maxLength) {
            return self::e($text);
        }

        $short = function_exists('mb_substr')
            ? mb_substr($text, 0, $maxLength, 'UTF-8')
            : substr($text, 0, $maxLength);

        return self::e($short . '…');
    }

    public static function severityLabel(string $severity): string
    {
        return match ($severity) {
            'critical' => 'Critical',
            'error' => 'Error',
            'warn' => 'Warning',
            'info' => 'Info',
            'unknown' => 'Unknown',
            default => ucfirst($severity),
        };
    }

    public static function severityBadgeClass(string $severity): string
    {
        return match ($severity) {
            'critical' => 'bg-purple-100 text-purple-800 ring-purple-200',
            'error' => 'bg-red-100 text-red-800 ring-red-200',
            'warn' => 'bg-amber-100 text-amber-800 ring-amber-200',
            'info' => 'bg-sky-100 text-sky-800 ring-sky-200',
            default => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    public static function parseBadgeClass(bool $parseOk): string
    {
        return $parseOk
            ? 'bg-emerald-100 text-emerald-800 ring-emerald-200'
            : 'bg-red-100 text-red-800 ring-red-200';
    }

    public static function deviceKindLabel(string $kind): string
    {
        return match ($kind) {
            'bridge' => 'Bridge',
            'baliza' => 'Baliza',
            default => ucfirst($kind),
        };
    }

    public static function deviceKindBadgeClass(string $kind): string
    {
        return match ($kind) {
            'bridge' => 'bg-indigo-100 text-indigo-800 ring-indigo-200',
            'baliza' => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
            default => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    public static function eventSummary(array $event): ?string
    {
        $measurements = self::decodeJsonMap($event['measurements'] ?? null);
        $context = self::decodeJsonMap($event['context'] ?? null);
        $tags = is_array($context['tags'] ?? null) ? $context['tags'] : [];
        $category = (string) ($event['event_category'] ?? 'unknown');

        $parts = match ($category) {
            'telemetry' => self::telemetrySummaryParts($measurements, $tags),
            'speed' => self::speedSummaryParts($measurements),
            'vehicle' => self::vehicleSummaryParts($measurements, $tags),
            'https' => self::httpSummaryParts($measurements, $tags),
            default => [],
        };

        if ($parts === []) {
            return null;
        }

        return implode(' · ', array_slice($parts, 0, 6));
    }

    private static function telemetrySummaryParts(array $measurements, array $tags): array
    {
        $parts = [];

        self::appendMetricPart($parts, 'T', $measurements['temperatureC'] ?? null, 'C');
        self::appendMetricPart($parts, 'H', $measurements['humidityPct'] ?? null, '%');
        self::appendMetricPart($parts, 'P', $measurements['pressureHpa'] ?? null, 'hPa');
        self::appendMetricPart($parts, 'AQ', $measurements['airQuality'] ?? null);
        self::appendMetricPart($parts, 'SOC', $measurements['socPct'] ?? null, '%');
        self::appendMetricPart($parts, 'RSSI', $measurements['parentRssi'] ?? null, 'dBm');

        if (($tags['chargingState'] ?? null) !== null && trim((string) $tags['chargingState']) !== '') {
            $parts[] = 'Charge=' . trim((string) $tags['chargingState']);
        }

        if (($tags['iaqState'] ?? null) !== null && trim((string) $tags['iaqState']) !== '') {
            $parts[] = 'IAQ=' . trim((string) $tags['iaqState']);
        }

        return $parts;
    }

    private static function speedSummaryParts(array $measurements): array
    {
        $parts = [];

        self::appendMetricPart($parts, 'Vel', $measurements['speedKmh'] ?? null, 'km/h');
        self::appendMetricPart($parts, 'Carril', $measurements['lane'] ?? null, null, 0);
        self::appendMetricPart($parts, 'Pos', $measurements['positionM'] ?? null, 'm');
        self::appendMetricPart($parts, 'Dist', $measurements['distanceMm'] ?? null, 'mm', 0);

        return $parts;
    }

    private static function vehicleSummaryParts(array $measurements, array $tags): array
    {
        $parts = [];

        self::appendMetricPart($parts, 'Vel final', $measurements['finalSpeedKmh'] ?? null, 'km/h');
        self::appendMetricPart($parts, 'Vel prev', $measurements['previousSpeedKmh'] ?? null, 'km/h');
        self::appendMetricPart($parts, 'Accel', $measurements['accelerationMps2'] ?? null, 'm/s2');

        if (($tags['dynamicState'] ?? null) !== null && trim((string) $tags['dynamicState']) !== '') {
            $parts[] = 'Estado=' . trim((string) $tags['dynamicState']);
        }

        return $parts;
    }

    private static function httpSummaryParts(array $measurements, array $tags): array
    {
        $parts = [];

        self::appendMetricPart($parts, 'HTTP', $measurements['status'] ?? null, null, 0);
        self::appendMetricPart($parts, 'OK', $measurements['ok'] ?? null, null, 0);

        if (($tags['notificationType'] ?? null) !== null && trim((string) $tags['notificationType']) !== '') {
            $parts[] = 'Notif=' . trim((string) $tags['notificationType']);
        }

        return $parts;
    }

    private static function appendMetricPart(
        array &$parts,
        string $label,
        mixed $value,
        ?string $unit = null,
        ?int $decimals = 1
    ): void {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return;
        }

        $parts[] = $label . '=' . self::formatMetricNumber((float) $value, $decimals) . ($unit !== null ? $unit : '');
    }

    private static function formatMetricNumber(float $value, ?int $decimals): string
    {
        if ($decimals === 0) {
            return (string) (int) round($value);
        }

        $formatted = number_format($value, $decimals ?? 1, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private static function decodeJsonMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
