<?php

declare(strict_types=1);

namespace App\Viewer;

use App\Support\Env;

final class ViewerAuth
{
    public static function enforce(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $expectedUsername = (string) Env::get('VIEWER_AUTH_USERNAME', '');
        $expectedPassword = (string) Env::get('VIEWER_AUTH_PASSWORD', '');

        if ($expectedUsername === '' || $expectedPassword === '') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Viewer authentication is enabled but credentials are not configured.';
            exit;
        }

        $providedUsername = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
        $providedPassword = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

        if (
            hash_equals($expectedUsername, $providedUsername)
            && hash_equals($expectedPassword, $providedPassword)
        ) {
            return;
        }

        header('WWW-Authenticate: Basic realm="logs-devices viewer"');
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Authentication required.';
        exit;
    }

    private static function isEnabled(): bool
    {
        $value = strtolower(trim((string) Env::get('VIEWER_AUTH_ENABLED', 'true')));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}