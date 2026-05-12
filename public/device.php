<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Support\Env;
use App\Viewer\ViewerAuth;
use App\Viewer\ViewerFormatter as F;
use App\Viewer\ViewerQueries;

require_once __DIR__ . '/../vendor/autoload.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed.';
    exit;
}

Env::load(__DIR__ . '/../../../private/.env');
Env::loadPhpConfig(__DIR__ . '/../src/Config/runtime.local.php');

ViewerAuth::enforce();

header('Cache-Control: no-store');

$deviceId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => [
        'min_range' => 1,
    ],
]);

if ($deviceId === false || $deviceId === null) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid or missing device id.';
    exit;
}

$loadError = null;
$device = null;
$children = [];
$severityCounts = [];
$eventTypeCounts = [];
$latestEvents = [];

try {
    $pdo = Connection::make();
    $queries = new ViewerQueries($pdo);

    $device = $queries->findDevice((int) $deviceId);

    if ($device === null) {
        http_response_code(404);
    } else {
        $deviceKind = (string) $device['device_kind'];

        $children = $deviceKind === 'bridge'
            ? $queries->childDevicesForBridge((int) $device['id'])
            : [];

        $severityCounts = $queries->deviceSeverityCounts((int) $device['id'], $deviceKind);
        $eventTypeCounts = $queries->deviceEventTypeCounts((int) $device['id'], $deviceKind);
        $latestEvents = $queries->latestDeviceEvents((int) $device['id'], $deviceKind, 50);
    }
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
    <title>logs-devices · Detalle dispositivo</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-slate-100 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-5">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">
                    <?= $device ? F::nullable($device['name'] ?? null) : 'Dispositivo' ?>
                </h1>
                <p class="mt-1 text-sm text-slate-500">
                    Detalle técnico del dispositivo y sus últimos eventos.
                </p>
            </div>

            <nav class="flex items-center gap-3 text-sm">
                <a href="/" class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                    Dashboard
                </a>
                <a href="/devices.php" class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                    Dispositivos
                </a>
                <a href="/events.php" class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                    Eventos
                </a>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-6 py-8">
        <?php if ($loadError !== null): ?>
            <section class="rounded-2xl border border-red-200 bg-red-50 p-6 text-red-800 shadow-sm">
                <h2 class="text-lg font-semibold">Error cargando dispositivo</h2>
                <p class="mt-2 font-mono text-sm"><?= F::e($loadError) ?></p>
            </section>
        <?php elseif ($device === null): ?>
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-amber-900 shadow-sm">
                <h2 class="text-lg font-semibold">Dispositivo no encontrado</h2>
                <p class="mt-2 text-sm">No existe ningún dispositivo con ID <?= F::e($deviceId) ?>.</p>
                <p class="mt-4">
                    <a class="font-medium text-amber-900 underline" href="/devices.php">Volver al listado</a>
                </p>
            </section>
        <?php else: ?>
            <?php
            $kind = (string) $device['device_kind'];
            $totalEvents = array_sum(array_map('intval', $severityCounts));
            ?>

            <section class="grid gap-6 lg:grid-cols-3">
                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-1">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-slate-500">Tipo</p>
                            <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::deviceKindBadgeClass($kind) ?>">
                                <?= F::e(F::deviceKindLabel($kind)) ?>
                            </span>
                        </div>

                        <div class="text-right text-xs text-slate-500">
                            ID interno<br>
                            <span class="font-mono text-slate-700"><?= F::e($device['id']) ?></span>
                        </div>
                    </div>

                    <dl class="mt-6 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-slate-500">Nombre</dt>
                            <dd class="mt-1 text-slate-900"><?= F::nullable($device['name'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">External ID</dt>
                            <dd class="mt-1 font-mono text-slate-900"><?= F::nullable($device['external_id'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">MAC</dt>
                            <dd class="mt-1 font-mono text-slate-900"><?= F::nullable($device['mac_address'] ?? null) ?></dd>
                        </div>

                        <?php if (($device['parent_device_id'] ?? null) !== null): ?>
                            <div>
                                <dt class="font-medium text-slate-500">Bridge padre</dt>
                                <dd class="mt-1">
                                    <a class="font-medium text-sky-700 hover:underline" href="/device.php?id=<?= F::e($device['parent_id']) ?>">
                                        <?= F::nullable($device['parent_external_id'] ?? null) ?>
                                    </a>
                                    <div class="mt-1 text-xs text-slate-500">
                                        <?= F::nullable($device['parent_name'] ?? null) ?>
                                    </div>
                                </dd>
                            </div>
                        <?php endif; ?>

                        <div>
                            <dt class="font-medium text-slate-500">Primer visto</dt>
                            <dd class="mt-1 text-slate-900"><?= F::datetime($device['first_seen_at'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Último visto</dt>
                            <dd class="mt-1 text-slate-900"><?= F::datetime($device['last_seen_at'] ?? null) ?></dd>
                        </div>
                    </dl>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                    <h2 class="text-lg font-semibold">Resumen de eventos</h2>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <div class="rounded-xl border border-slate-200 p-4">
                            <p class="text-sm font-medium text-slate-500">Eventos asociados</p>
                            <p class="mt-2 text-3xl font-bold"><?= F::number($totalEvents) ?></p>
                        </div>

                        <?php foreach ($severityOrder as $severity): ?>
                            <div class="rounded-xl border border-slate-200 p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::severityBadgeClass($severity) ?>">
                                        <?= F::e(F::severityLabel($severity)) ?>
                                    </span>
                                    <span class="text-xl font-bold"><?= F::number($severityCounts[$severity] ?? 0) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <h3 class="mt-8 text-sm font-semibold uppercase tracking-wide text-slate-500">Por tipo</h3>

                    <div class="mt-3 overflow-hidden rounded-xl border border-slate-200">
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
                                            No hay eventos asociados.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <?php if ($kind === 'bridge'): ?>
                <section class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-6 py-5">
                        <h2 class="text-lg font-semibold">Balizas asociadas</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Total: <?= F::number(count($children)) ?> balizas hijas.
                        </p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Nombre</th>
                                    <th class="px-4 py-3">External ID</th>
                                    <th class="px-4 py-3">MAC</th>
                                    <th class="px-4 py-3">First seen</th>
                                    <th class="px-4 py-3">Last seen</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                <?php foreach ($children as $child): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-3 font-medium">
                                            <a class="text-sky-700 hover:underline" href="/device.php?id=<?= F::e($child['id']) ?>">
                                                <?= F::nullable($child['name'] ?? null) ?>
                                            </a>
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs"><?= F::nullable($child['external_id'] ?? null) ?></td>
                                        <td class="px-4 py-3 font-mono text-xs"><?= F::nullable($child['mac_address'] ?? null) ?></td>
                                        <td class="px-4 py-3 text-slate-600"><?= F::datetime($child['first_seen_at'] ?? null) ?></td>
                                        <td class="px-4 py-3 text-slate-600"><?= F::datetime($child['last_seen_at'] ?? null) ?></td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if ($children === []): ?>
                                    <tr>
                                        <td colspan="5" class="px-4 py-6 text-center text-slate-500">
                                            Este bridge no tiene balizas asociadas.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <section class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold">Últimos eventos</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Últimos 50 eventos asociados a este dispositivo.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Fecha evento</th>
                                <th class="px-4 py-3">Severidad</th>
                                <th class="px-4 py-3">Tipo</th>
                                <th class="px-4 py-3">Dispositivo origen</th>
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
                                        <?php if (($event['device_id'] ?? null) !== null): ?>
                                            <a class="font-medium text-sky-700 hover:underline" href="/device.php?id=<?= F::e($event['device_id']) ?>">
                                                <?= F::nullable($event['device_name'] ?? null) ?>
                                            </a>
                                            <div class="mt-1 text-xs text-slate-500">
                                                <?= F::nullable($event['device_mac_raw'] ?? $event['device_mac_address'] ?? null) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-slate-400">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="min-w-[420px] px-4 py-3 text-slate-700">
                                        <?= F::shortText($event['message_text'] ?? '', 260) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ($latestEvents === []): ?>
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-slate-500">
                                        No hay eventos asociados.
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