<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Punto único de arranque de la aplicación.
 *
 * Cualquier entrypoint (cualquier .php que reciba una request HTTP o se ejecute
 * por CLI) debe llamar a Bootstrap::init() ANTES de hacer cualquier otra cosa.
 *
 * Centraliza:
 *   - localización de la raíz del proyecto
 *   - carga de Composer autoload
 *   - carga de configuración desde src/Config/runtime.php
 *   - defaults sanos para STORAGE_BASE_PATH
 *   - validación de claves mínimas obligatorias
 */
final class Bootstrap
{
    private static ?string $projectRoot = null;

    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$projectRoot = dirname(__DIR__, 2);

        $autoload = self::$projectRoot . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException(
                'vendor/autoload.php no encontrado. Ejecuta `composer install`. '
                    . 'Path esperado: ' . $autoload
            );
        }
        require_once $autoload;

        $runtimePath = self::$projectRoot . '/src/Config/runtime.php';
        if (!is_file($runtimePath)) {
            throw new RuntimeException(
                'src/Config/runtime.php no encontrado. '
                    . 'Copia src/Config/runtime.php.dist a src/Config/runtime.php '
                    . 'y rellena los valores reales para este entorno.'
            );
        }
        Env::loadPhpConfig($runtimePath);

        if (Env::get('STORAGE_BASE_PATH') === null) {
            self::setRuntimeDefault('STORAGE_BASE_PATH', self::$projectRoot . '/storage');
        }

        self::assertRequired([
            'APP_ENV',
            'LOG_INGEST_SECRET',
            'LOG_INGEST_USER_AGENT',
            'DB_HOST',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
        ]);

        self::$initialized = true;
    }

    public static function projectRoot(): string
    {
        if (self::$projectRoot === null) {
            throw new RuntimeException('Bootstrap::init() no se ha ejecutado todavía.');
        }
        return self::$projectRoot;
    }

    private static function setRuntimeDefault(string $key, string $value): void
    {

        Env::setDefault($key, $value);
    }
    private static function assertRequired(array $keys): void
    {
        $missing = [];
        foreach ($keys as $key) {
            $value = Env::get($key);
            if ($value === null || $value === '') {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException(
                'Faltan claves de configuración obligatorias en src/Config/runtime.php: '
                    . implode(', ', $missing)
            );
        }
    }
}
