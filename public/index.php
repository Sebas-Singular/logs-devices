<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Support\Env;
use App\Viewer\ViewerAuth;
use App\Viewer\ViewerFormatter as F;
use App\Viewer\ViewerQueries;

require_once __DIR__ . '/../vendor/autoload.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
    require __DIR__ . '/api/ingest.php';
    exit;
}

if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed.';
    exit;
}

Env::load(__DIR__ . '/../../../private/.env');
Env::loadPhpConfig(__DIR__ . '/../src/Config/runtime.local.php');

ViewerAuth::enforce();

header('Cache-Control: no-store');

$appEnv = Env::get('APP_ENV', 'unknown');
$phpVersion = PHP_VERSION;

$loadError = null;
$stats = [];
$severityCounts = [];
$eventTypeCounts = [];
$latestEvents = [];

try {
    $pdo = Connection::make();

    $queries = new ViewerQueries($pdo);

    $stats = $queries->dashboardStats();
    $severityCounts = $queries->severityCounts();
    $eventTypeCounts = $queries->eventTypeCounts();
    $latestEvents = $queries->latestEvents(20);
} catch (Throwable $exception) {
    http_response_code(500);
    $loadError = $exception->getMessage();
}

$severityOrder = ['critical', 'error', 'warn', 'info', 'unknown'];

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>logs-devices · Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-5">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">logs-devices</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Viewer básico de logs IoT / industriales
                </p>
            </div>

            <div class="text-right text-sm text-slate-500">
                <div>
                    Entorno:
                    <span class="font-semibold text-slate-700"><?= F::e($appEnv) ?></span>
                </div>
                <div>
                    PHP:
                    <span class="font-semibold text-slate-700"><?= F::e($phpVersion) ?></span>
                </div>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-6 py-8">
        <?php if ($loadError !== null): ?>
            <section class="rounded-2xl border border-red-200 bg-red-50 p-6 text-red-800 shadow-sm">
                <h2 class="text-lg font-semibold">Error cargando el dashboard</h2>
                <p class="mt-2 font-mono text-sm"><?= F::e($loadError) ?></p>
            </section>
        <?php else: ?>
            <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-slate-500">Dispositivos</p>
                    <p class="mt-2 text-3xl font-bold"><?= F::number($stats['devices_total'] ?? 0) ?></p>
                    <p class="mt-2 text-sm text-slate-500">
                        <?= F::number($stats['bridges_total'] ?? 0) ?> bridges ·
                        <?= F::number($stats['beacons_total'] ?? 0) ?> balizas
                    </p>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-slate-500">Ingests</p>
                    <p class="mt-2 text-3xl font-bold"><?= F::number($stats['ingests_total'] ?? 0) ?></p>
                    <p class="mt-2 text-sm text-slate-500">
                        <?= F::number($stats['ingests_processed'] ?? 0) ?> processed ·
                        <?= F::number($stats['ingests_received'] ?? 0) ?> received ·
                        <?= F::number($stats['ingests_error'] ?? 0) ?> error
                    </p>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-slate-500">Eventos</p>
                    <p class="mt-2 text-3xl font-bold"><?= F::number($stats['events_total'] ?? 0) ?></p>
                    <p class="mt-2 text-sm text-slate-500">
                        <?= F::number($stats['events_parse_ok'] ?? 0) ?> OK ·
                        <?= F::number($stats['events_parse_error'] ?? 0) ?> con error de parseo
                    </p>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-slate-500">Estado sistema</p>
                    <p class="mt-2 text-3xl font-bold text-emerald-700">Online</p>
                    <p class="mt-2 text-sm text-slate-500">
                        BD conectada · <a class="font-medium text-sky-700 hover:underline" href="/api/health.php">healthcheck</a>
                    </p>
                </article>
            </section>

            <section class="mt-8 grid gap-6 lg:grid-cols-2">
                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold">Eventos por severidad</h2>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <?php foreach ($severityOrder as $severity): ?>
                            <div class="flex items-center justify-between rounded-xl border border-slate-200 p-4">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::severityBadgeClass($severity) ?>">
                                    <?= F::e(F::severityLabel($severity)) ?>
                                </span>
                                <span class="text-lg font-bold">
                                    <?= F::number($severityCounts[$severity] ?? 0) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Eventos por tipo</h2>

                    <div class="mt-5 overflow-hidden rounded-xl border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Tipo</th>
                                    <th class="px-4 py-3">Parse</th>
                                    <th class="px-4 py-3 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                <?php foreach ($eventTypeCounts as $row): ?>
                                    <?php $parseOk = ((int) $row['parse_ok']) === 1; ?>
                                    <tr>
                                        <td class="px-4 py-3 font-medium"><?= F::e($row['event_type']) ?></td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::parseBadgeClass($parseOk) ?>">
                                                <?= $parseOk ? 'OK' : 'Error' ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold"><?= F::number($row['total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if ($eventTypeCounts === []): ?>
                                    <tr>
                                        <td colspan="3" class="px-4 py-6 text-center text-slate-500">
                                            No hay eventos todavía.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <section class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold">Últimos eventos</h2>
                    <p class="mt-1 text-sm text-slate-500">Los 20 eventos más recientes por timestamp de evento o recepción.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Fecha evento</th>
                                <th class="px-4 py-3">Severidad</th>
                                <th class="px-4 py-3">Tipo</th>
                                <th class="px-4 py-3">Dispositivo</th>
                                <th class="px-4 py-3">Bridge</th>
                                <th class="px-4 py-3">Mensaje</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            <?php foreach ($latestEvents as $event): ?>
                                <?php $parseOk = ((int) $event['parse_ok']) === 1; ?>
                                <tr class="align-top hover:bg-slate-50">
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                        <?= F::datetime($event['event_timestamp'] ?? null) ?>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::severityBadgeClass((string) $event['severity']) ?>">
                                            <?= F::e(F::severityLabel((string) $event['severity'])) ?>
                                        </span>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3">
                                        <div class="font-medium"><?= F::e($event['event_type']) ?></div>
                                        <?php if (!$parseOk): ?>
                                            <div class="mt-1 text-xs text-red-700">
                                                <?= F::nullable($event['parse_error'] ?? null) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-4 py-3">
                                        <div class="font-medium">
                                            <?= F::nullable($event['device_name'] ?? null) ?>
                                        </div>
                                        <div class="mt-1 text-xs text-slate-500">
                                            <?= F::nullable($event['device_mac_raw'] ?? $event['device_mac_address'] ?? null) ?>
                                        </div>
                                    </td>

                                    <td class="px-4 py-3">
                                        <div class="font-medium">
                                            <?= F::nullable($event['bridge_external_id'] ?? null) ?>
                                        </div>
                                        <div class="mt-1 text-xs text-slate-500">
                                            <?= F::nullable($event['bridge_name'] ?? null) ?>
                                        </div>
                                    </td>

                                    <td class="min-w-[360px] px-4 py-3 text-slate-700">
                                        <?= F::shortText($event['message_text'] ?? '', 220) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ($latestEvents === []): ?>
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-slate-500">
                                        No hay eventos todavía.
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