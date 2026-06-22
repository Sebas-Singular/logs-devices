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

        ViewerSession::start();

        if (ViewerSession::isAuthenticated()) {
            return;
        }

        $currentUrl = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        ViewerSession::redirectToLogin($currentUrl);
    }

    public static function checkCredentials(string $username, string $password): bool
    {
        $expectedUsername = (string) Env::get('VIEWER_AUTH_USERNAME', '');
        $expectedPassword = (string) Env::get('VIEWER_AUTH_PASSWORD', '');

        if ($expectedUsername === '' || $expectedPassword === '') {
            return false;
        }

        return hash_equals($expectedUsername, $username)
            && hash_equals($expectedPassword, $password);
    }

    public static function isEnabled(): bool
    {
        $value = strtolower(trim((string) Env::get('VIEWER_AUTH_ENABLED', 'true')));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
