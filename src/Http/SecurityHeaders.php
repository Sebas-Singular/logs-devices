<?php

declare(strict_types=1);

namespace App\Http;

final class SecurityHeaders
{
    public static function applyCommon(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    public static function applyNoStore(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    public static function applyViewer(): void
    {
        self::applyCommon();
        self::applyNoStore();
    }

    public static function applyJson(): void
    {
        self::applyCommon();
        self::applyNoStore();

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
    }

    public static function applyText(): void
    {
        self::applyCommon();
        self::applyNoStore();

        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
    }
}
