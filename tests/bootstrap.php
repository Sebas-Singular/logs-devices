<?php
 
declare(strict_types=1);
 
// -----------------------------------------------------------------------------
// tests/bootstrap.php
// -----------------------------------------------------------------------------
// PHPUnit ejecuta este fichero antes de correr cualquier test.
//
// Hace dos cosas:
//   1. Carga el autoloader de Composer → todas las clases de src/ y tests/
//      quedan disponibles sin require_once.
//   2. Carga el .env local → si algún test necesita credenciales (ej: tests de
//      integración con BD), las encuentra disponibles vía Env::get().
//
// Los tests de parsers (Fase 2) son puramente unitarios y no necesitan BD ni
// .env, pero cargarlo aquí no hace daño y permite que futuros tests de
// integración funcionen sin cambios en este bootstrap.
// -----------------------------------------------------------------------------
 
require_once dirname(__DIR__) . '/vendor/autoload.php';
 
\App\Support\Env::load(dirname(__DIR__) . '/private/.env');