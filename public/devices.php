<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Support\Env;
use App\Viewer\ViewerAuth;
use App\Viewer\ViewerFormatter as F;
use App\Viewer\ViewerQueries;
use App\Http\SecurityHeaders;

require_once __DIR__ . '/../vendor/autoload.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed.';
    exit;
}

use App\Support\Bootstrap;

Bootstrap::init();

SecurityHeaders::applyViewer();

ViewerAuth::enforce();

$loadError = null;
$devices = [];

try {
    $pdo = Connection::make();
    $queries = new ViewerQueries($pdo);
    $devices = $queries->devicesList();
} catch (Throwable $exception) {
    http_response_code(500);
    $loadError = $exception->getMessage();
}

?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>logs-devices · Dispositivos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-slate-100 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-5">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Dispositivos</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Bridges y balizas detectadas por la plataforma.
                </p>
            </div>

            <nav class="flex items-center gap-3 text-sm">
                <a href="/" class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                    Dashboard
                </a>
                <a href="/devices.php" class="rounded-lg bg-slate-900 px-3 py-2 font-medium text-white">
                    Dispositivos
                </a>
                <a href="/events.php" class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                    Eventos
                </a>
                <a href="/ingests.php" class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                    Ingests
                </a>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-6 py-8">
        <?php if ($loadError !== null): ?>
            <section class="rounded-2xl border border-red-200 bg-red-50 p-6 text-red-800 shadow-sm">
                <h2 class="text-lg font-semibold">Error cargando dispositivos</h2>
                <p class="mt-2 font-mono text-sm"><?= F::e($loadError) ?></p>
            </section>
        <?php else: ?>
            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold">Listado de dispositivos</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Total: <?= F::number(count($devices)) ?> dispositivos.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Tipo</th>
                                <th class="px-4 py-3">Nombre</th>
                                <th class="px-4 py-3">External ID</th>
                                <th class="px-4 py-3">MAC</th>
                                <th class="px-4 py-3">Bridge padre</th>
                                <th class="px-4 py-3 text-right">Eventos</th>
                                <th class="px-4 py-3 text-right">Warn</th>
                                <th class="px-4 py-3 text-right">Error</th>
                                <th class="px-4 py-3">Último evento</th>
                                <th class="px-4 py-3">Visto por primera vez</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-200 bg-white">
                            <?php foreach ($devices as $device): ?>
                                <?php
                                $kind = (string) $device['device_kind'];
                                $eventCount = (int) ($device['event_count'] ?? 0);
                                $warnCount = (int) ($device['warn_count'] ?? 0);
                                $errorCount = (int) ($device['error_count'] ?? 0) + (int) ($device['critical_count'] ?? 0);
                                ?>

                                <tr class="align-top hover:bg-slate-50">
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::deviceKindBadgeClass($kind) ?>">
                                            <?= F::e(F::deviceKindLabel($kind)) ?>
                                        </span>
                                    </td>

                                    <td class="px-4 py-3">
                                        <div class="font-medium text-slate-900">
                                            <a class="text-sky-700 hover:underline" href="/device.php?id=<?= F::e($device['id']) ?>">
                                                <?= F::nullable($device['name'] ?? null) ?>
                                            </a>
                                        </div>
                                        <div class="mt-1 text-xs text-slate-500">
                                            ID interno: <?= F::e($device['id']) ?>
                                        </div>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">
                                        <?= F::nullable($device['external_id'] ?? null) ?>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">
                                        <?= F::nullable($device['mac_address'] ?? null) ?>
                                    </td>

                                    <td class="px-4 py-3">
                                        <?php if (($device['parent_device_id'] ?? null) !== null): ?>
                                            <div class="font-medium">
                                                <?= F::nullable($device['parent_external_id'] ?? null) ?>
                                            </div>
                                            <div class="mt-1 text-xs text-slate-500">
                                                <?= F::nullable($device['parent_name'] ?? null) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-slate-400">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-right font-semibold">
                                        <?= F::number($eventCount) ?>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <?php if ($warnCount > 0): ?>
                                            <span class="font-semibold text-amber-700"><?= F::number($warnCount) ?></span>
                                        <?php else: ?>
                                            <span class="text-slate-400">0</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <?php if ($errorCount > 0): ?>
                                            <span class="font-semibold text-red-700"><?= F::number($errorCount) ?></span>
                                        <?php else: ?>
                                            <span class="text-slate-400">0</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                        <?= F::datetime($device['last_event_at'] ?? null) ?>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                        <?= F::datetime($device['first_seen_at'] ?? null) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ($devices === []): ?>
                                <tr>
                                    <td colspan="10" class="px-4 py-8 text-center text-slate-500">
                                        No hay dispositivos todavía.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </main>
</body>

</html>