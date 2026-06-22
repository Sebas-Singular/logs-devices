<?php

declare(strict_types=1);

/**
 * Bootstrap de PHPUnit.
 *
 * PHPUnit invoca este fichero una vez antes de ejecutar la suite
 * (configurado en phpunit.xml.dist via bootstrap="tests/bootstrap.php").
 *
 * Su única responsabilidad es preparar el entorno de la aplicación
 * exactamente igual que un entrypoint HTTP normal:
 *   1) Cargar Composer autoload.
 *   2) Inicializar la app (Bootstrap::init).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

\App\Support\Bootstrap::init();
