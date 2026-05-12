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
}