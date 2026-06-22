<?php

declare(strict_types=1);

namespace App\Viewer;

use App\Storage\Paths;
use App\Support\Env;

final class ViewerSession
{
    private const SESSION_NAME           = 'ld_sess';
    private const SESSION_KEY_AUTH       = 'authenticated';
    private const SESSION_KEY_LAST_ACT   = 'last_activity';
    private const SESSION_KEY_CSRF       = 'csrf_token';
    private const DEFAULT_LIFETIME       = 7200; // 2 horas de inactividad

    /**
     * Inicia la sesión con configuración segura.
     * Debe llamarse antes de cualquier output.
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime     = self::lifetime();
        $isSecure     = self::isHttps();
        $sessionPath  = Paths::for('sessions');

        if (!is_dir($sessionPath)) {
            mkdir($sessionPath, 0750, true);
        }

        session_save_path($sessionPath);

        ini_set('session.gc_maxlifetime', (string) ($lifetime + 60));
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        session_name(self::SESSION_NAME);
        session_start();
    }

    public static function isAuthenticated(): bool
    {
        if (
            !isset($_SESSION[self::SESSION_KEY_AUTH])
            || $_SESSION[self::SESSION_KEY_AUTH] !== true
        ) {
            return false;
        }

        $lastActivity = (int) ($_SESSION[self::SESSION_KEY_LAST_ACT] ?? 0);

        if ((time() - $lastActivity) > self::lifetime()) {
            self::destroy();
            return false;
        }

        $_SESSION[self::SESSION_KEY_LAST_ACT] = time();

        return true;
    }

    public static function login(): void
    {
        session_regenerate_id(true);

        $_SESSION[self::SESSION_KEY_AUTH]     = true;
        $_SESSION[self::SESSION_KEY_LAST_ACT] = time();

        unset($_SESSION[self::SESSION_KEY_CSRF]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION[self::SESSION_KEY_CSRF])) {
            $_SESSION[self::SESSION_KEY_CSRF] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::SESSION_KEY_CSRF];
    }

    public static function validateCsrf(string $token): bool
    {
        $expected = (string) ($_SESSION[self::SESSION_KEY_CSRF] ?? '');

        return $expected !== '' && hash_equals($expected, $token);
    }

    public static function redirectToLogin(?string $redirectTo = null): never
    {
        $url = '/login.php';

        if ($redirectTo !== null && $redirectTo !== '') {
            $url .= '?redirect=' . urlencode($redirectTo);
        }

        header('Location: ' . $url);
        http_response_code(302);
        exit;
    }

    public static function lifetime(): int
    {
        $value = Env::get('VIEWER_SESSION_LIFETIME');

        if ($value === null || trim((string) $value) === '') {
            return self::DEFAULT_LIFETIME;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 60],
        ]);

        return $int !== false ? (int) $int : self::DEFAULT_LIFETIME;
    }

    private static function isHttps(): bool
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        if (
            isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'
        ) {
            return true;
        }

        return false;
    }
}
