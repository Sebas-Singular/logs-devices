<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Http\SecurityHeaders;
use App\Support\Bootstrap;
use App\Viewer\Chart;
use App\Viewer\DashboardQueries;
use App\Viewer\MetricCatalog;
use App\Viewer\ViewerAuth;
use App\Viewer\ViewerFormatter as F;

require_once __DIR__ . '/../vendor/autoload.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

// El endpoint legacy publica sobre la raíz: un POST aquí es una ingesta.
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

$bootstrapError = false;
$bootstrapErrorMessage = 'La configuración de la aplicación no está disponible en este entorno.';

try {
    Bootstrap::init();
    SecurityHeaders::applyViewer();
    ViewerAuth::enforce();
} catch (Throwable) {
    $bootstrapError = true;
    http_response_code(500);
}

$window = DashboardQueries::normalizeWindow($_GET['window'] ?? null);
$windowLabel = DashboardQueries::WINDOWS[$window]['label'];
$windowHours = DashboardQueries::WINDOWS[$window]['hours'];
$since = DashboardQueries::windowStart($window);

$loadError = null;
$health = [];
$activity = [];
$severityCounts = [];
$categories = [];
$eventTypes = [];
$dormantTypes = [];
$liveMetrics = [];
$timeline = [];
$speedSample = null;
$speedOutsideWindow = false;
$activeDevices = [];
$silentDevices = 0;
$latestEvents = [];

if (!$bootstrapError) {
    try {
        $dashboard = new DashboardQueries(Connection::make());

        $health = $dashboard->health($since);
        $activity = $dashboard->activity($since);
        $severityCounts = $dashboard->severityCounts($since);
        $categories = $dashboard->categoryBreakdown($since);
        $eventTypes = $dashboard->eventTypeBreakdown($since, 12);
        $dormantTypes = $dashboard->dormantEventTypes($since);
        $liveMetrics = $dashboard->liveMetrics($since);
        $timeline = $dashboard->eventTimeline($window, $since);
        $activeDevices = $dashboard->activeDevices($since, 8);
        $silentDevices = $dashboard->silentDeviceCount($since);
        $latestEvents = $dashboard->latestEvents($since, 20);

        $speedSample = $dashboard->latestSpeedSample($since);

        // Si la ventana no tiene lecturas de velocidad, se busca la última
        // conocida y se marca como fuera de ventana en lugar de dejar la
        // tarjeta de rendimiento vacía.
        if ($speedSample === null && $since !== null) {
            $speedSample = $dashboard->latestSpeedSample(null);
            $speedOutsideWindow = $speedSample !== null;
        }
    } catch (Throwable $exception) {
        http_response_code(500);
        $loadError = $exception->getMessage();
    }
}

$healthStatus = (string) ($health['status'] ?? 'sin_datos');
$healthUi = F::healthPresentation($healthStatus);

$eventTotal = (int) ($activity['events'] ?? 0);
$eventsPerHour = ($windowHours !== null && $windowHours > 0)
    ? $eventTotal / $windowHours
    : null;

// Severidades: error y critical se muestran siempre (un cero es información),
// el resto solo si tienen eventos en la ventana.
$severityAlways = ['critical', 'error', 'warn'];
$severityVisible = [];

foreach (['critical', 'error', 'warn', 'info', 'unknown'] as $severity) {
    $count = (int) ($severityCounts[$severity] ?? 0);

    if ($count > 0 || in_array($severity, $severityAlways, true)) {
        $severityVisible[$severity] = $count;
    }
}

$timelineIncidents = array_map(
    static fn (array $bucket): array => [
        'label' => $bucket['label'],
        'value' => $bucket['incidents'],
        'tooltip' => $bucket['tooltip'],
    ],
    $timeline
);

$incidentTotal = array_sum(array_column($timeline, 'incidents'));

// Las métricas en vivo se presentan segmentadas por el tipo de información que
// aportan: la salud de un nodo y el tráfico que mide son dos lecturas distintas.
$metricGroups = MetricCatalog::groupBy($liveMetrics);

$categoryTotal = array_sum(array_map(static fn (array $row): int => (int) $row['total'], $categories));

function windowUrl(string $window): string
{
    return '/?window=' . urlencode($window);
}

?>
<!doctype html>
<html lang="es">

<head>
    <?php $pageTitle = 'Panel de control';
    require __DIR__ . '/_viewer_head.php'; ?>
</head>

<body class="min-h-screen bg-ink-100 text-ink-900">
    <?php $activePage = 'dashboard';
    require __DIR__ . '/_viewer_header.php'; ?>

    <main class="mx-auto max-w-7xl px-6 py-8">
        <?php if ($bootstrapError): ?>
            <section class="rounded-2xl border border-red-200 bg-red-50 p-6 text-red-800 shadow-sm">
                <h2 class="text-lg font-semibold">Error cargando el dashboard</h2>
                <p class="mt-2 text-sm"><?= F::e($bootstrapErrorMessage) ?></p>
            </section>
        <?php elseif ($loadError !== null): ?>
            <section class="rounded-2xl border border-red-200 bg-red-50 p-6 text-red-800 shadow-sm">
                <h2 class="text-lg font-semibold">Error cargando el dashboard</h2>
                <p class="mt-2 font-mono text-sm"><?= F::e($loadError) ?></p>
            </section>
        <?php else: ?>

            <!-- =============================================================
                 Selector de ventana temporal: todo el panel se recalcula
                 sobre el periodo elegido.
                 ============================================================= -->
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">Panel de control</h1>
                    <p class="mt-1 text-sm text-ink-500">
                        <?= F::e($windowLabel) ?>
                        <?php if ($since !== null): ?>
                            · desde <?= F::e($since) ?>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="flex items-center gap-1 rounded-lg border border-ink-200 bg-white p-1 shadow-sm">
                    <?php foreach (DashboardQueries::WINDOWS as $key => $definition): ?>
                        <a href="<?= F::e(windowUrl($key)) ?>"
                            class="<?= $key === $window ? 'bg-ink-900 text-white' : 'text-ink-500 hover:bg-ink-100 hover:text-ink-900' ?> rounded-md px-3 py-1.5 text-xs font-medium transition-colors">
                            <?= F::e($definition['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- =============================================================
                 KPIs: los cuatro se calculan, ninguno es texto fijo.
                 ============================================================= -->
            <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">

                <!-- Estado real del sistema -->
                <article class="relative overflow-hidden rounded-2xl border border-ink-200 bg-white p-5 pl-6 shadow-sm">
                    <div class="absolute inset-y-0 left-0 w-1 <?= F::e($healthUi['rail']) ?>"></div>

                    <div class="flex items-center justify-between">
                        <p class="text-xs font-semibold uppercase tracking-wider text-ink-500">Estado del sistema</p>
                        <span class="flex h-2 w-2 rounded-full <?= F::e($healthUi['dot']) ?>"></span>
                    </div>

                    <p class="mt-3 text-3xl font-bold tracking-tight <?= F::e($healthUi['text']) ?>">
                        <?= F::e($healthUi['label']) ?>
                    </p>

                    <p class="mt-2 text-sm text-ink-600">
                        Última ingesta hace
                        <span class="font-semibold text-ink-900"><?= F::e(DashboardQueries::humanizeMinutes($health['minutes_since_ingest'] ?? null)) ?></span>
                    </p>

                    <?php if (($health['reasons'] ?? []) !== []): ?>
                        <ul class="mt-3 space-y-1.5">
                            <?php foreach ($health['reasons'] as $reason): ?>
                                <li class="flex items-start gap-2 text-xs text-ink-600">
                                    <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full <?= F::e($healthUi['rail']) ?>"></span>
                                    <?= F::e($reason) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="mt-3 text-xs text-ink-500">
                            Sin ingestas atascadas ni errores en la ventana.
                        </p>
                    <?php endif; ?>

                    <p class="mt-4 text-xs">
                        <a class="font-medium text-brand-600 hover:text-brand-700 hover:underline" href="/api/health.php">
                            Ver diagnóstico
                        </a>
                    </p>
                </article>

                <!-- Rendimiento: última velocidad calculada -->
                <article class="rounded-2xl border border-ink-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-medium text-ink-500">Rendimiento medido</p>
                        <svg class="h-5 w-5 text-ink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>

                    <?php if ($speedSample === null): ?>
                        <p class="mt-2 text-3xl font-bold text-ink-300">—</p>
                        <p class="mt-2 text-sm text-ink-500">
                            Ninguna baliza ha calculado velocidad todavía.
                        </p>
                    <?php else: ?>
                        <p class="mt-2 text-3xl font-bold">
                            <?= F::e(MetricCatalog::formatValue($speedSample['canonical'], $speedSample['value'])) ?>
                            <span class="text-lg font-semibold text-ink-500">km/h</span>
                        </p>

                        <p class="mt-2 text-sm text-ink-600">
                            <?= F::e(MetricCatalog::label($speedSample['canonical'])) ?>
                            · <?= F::e(F::relativeTime($speedSample['event_timestamp'])) ?>
                        </p>

                        <p class="mt-1 text-xs text-ink-500">
                            <?php if ($speedSample['device_id'] !== null): ?>
                                <a class="font-medium text-brand-700 hover:underline"
                                    href="/device.php?id=<?= F::e($speedSample['device_id']) ?>">
                                    <?= F::nullable($speedSample['device_name']) ?>
                                </a>
                                ·
                            <?php endif; ?>
                            <span class="font-mono"><?= F::e($speedSample['key']) ?></span>
                            <?php if ($speedSample['legacy']): ?>
                                <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800">formato antiguo</span>
                            <?php endif; ?>
                        </p>

                        <?php if ($speedOutsideWindow): ?>
                            <p class="mt-2 text-xs font-medium text-amber-700">
                                Fuera de la ventana seleccionada.
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>

                <!-- Actividad en la ventana -->
                <article class="rounded-2xl border border-ink-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-ink-500">Actividad</p>
                    <p class="mt-2 text-3xl font-bold"><?= F::number($eventTotal) ?></p>
                    <p class="mt-2 text-sm text-ink-500">
                        eventos ·
                        <?= F::number((int) ($activity['ingests'] ?? 0)) ?> ingestas
                    </p>

                    <p class="mt-1 text-xs text-ink-500">
                        <?php if ($eventsPerHour !== null): ?>
                            <?= F::e(number_format($eventsPerHour, 1, ',', '.')) ?> eventos/hora
                        <?php else: ?>
                            Histórico completo
                        <?php endif; ?>

                        <?php if ((int) ($activity['events_parse_error'] ?? 0) > 0): ?>
                            · <a class="font-medium text-red-700 hover:underline" href="/events.php?parse_ok=0">
                                <?= F::number((int) $activity['events_parse_error']) ?> con error de parseo
                            </a>
                        <?php endif; ?>
                    </p>

                    <?php if ((int) ($activity['events_suspect'] ?? 0) > 0): ?>
                        <p class="mt-1 text-xs font-medium text-amber-700">
                            <?= F::number((int) $activity['events_suspect']) ?> con calidad no válida
                        </p>
                    <?php endif; ?>
                </article>

                <!-- Cobertura de dispositivos -->
                <article class="rounded-2xl border border-ink-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-ink-500">Dispositivos emitiendo</p>
                    <p class="mt-2 text-3xl font-bold">
                        <?= F::number((int) ($activity['devices_active'] ?? 0)) ?>
                        <span class="text-lg font-semibold text-ink-400">
                            / <?= F::number((int) ($activity['devices_total'] ?? 0)) ?>
                        </span>
                    </p>
                    <p class="mt-2 text-sm text-ink-500">
                        <?= F::number((int) ($activity['bridges_active'] ?? 0)) ?> de
                        <?= F::number((int) ($activity['bridges_total'] ?? 0)) ?> bridges activos
                    </p>

                    <?php if ($silentDevices > 0): ?>
                        <p class="mt-1 text-xs font-medium text-amber-700">
                            <?= F::number($silentDevices) ?> sin señal en la ventana
                        </p>
                    <?php else: ?>
                        <p class="mt-1 text-xs text-ink-500">
                            Todos los dispositivos registrados han emitido.
                        </p>
                    <?php endif; ?>
                </article>
            </section>

            <!-- =============================================================
                 Actividad en el tiempo. Dos series de una sola variable cada
                 una en vez de un apilado por severidad: los colores de estado
                 ámbar y rojo son indistinguibles en deuteranopia, y separadas
                 no necesitan leyenda ni compiten por el mismo eje.
                 ============================================================= -->
            <?php if ($timeline !== []): ?>
                <section class="mt-8 rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-lg font-semibold">Actividad en el tiempo</h2>
                        <p class="text-sm text-ink-500">
                            <?= F::e($windowLabel) ?> · pasa el cursor sobre una barra para ver el detalle
                        </p>
                    </div>

                    <div class="mt-6">
                        <div class="flex items-baseline justify-between">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-ink-500">Eventos recibidos</h3>
                            <p class="text-xs text-ink-500"><?= F::number($eventTotal) ?> en total</p>
                        </div>
                        <div class="mt-2">
                            <?= Chart::bars($timeline, [
                                'color' => '#E40D7E',
                                'height' => 96,
                                'aria' => 'Eventos recibidos por intervalo',
                            ]) ?>
                        </div>
                    </div>

                    <div class="mt-6 border-t border-ink-100 pt-5">
                        <div class="flex items-baseline justify-between">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-ink-500">Incidencias</h3>
                            <p class="text-xs <?= $incidentTotal > 0 ? 'font-medium text-red-700' : 'text-ink-500' ?>">
                                <?= F::number($incidentTotal) ?> con severidad error o crítica
                            </p>
                        </div>
                        <div class="mt-2">
                            <?= Chart::bars($timelineIncidents, [
                                'color' => '#D03B3B',
                                'height' => 56,
                                'aria' => 'Eventos con severidad error o crítica por intervalo',
                            ]) ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <!-- =============================================================
                 Métricas en vivo: se pinta una tarjeta por cada métrica que
                 realmente tiene lectura reciente. Las que dejan de llegar
                 desaparecen solas.
                 ============================================================= -->
            <section class="mt-8 rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg font-semibold">Métricas en vivo</h2>
                    <p class="text-sm text-ink-500">
                        Última lectura conocida de cada magnitud, resuelta por su clave vigente.
                    </p>
                </div>

                <?php if ($liveMetrics === []): ?>
                    <p class="mt-5 rounded-xl border border-dashed border-ink-300 px-4 py-8 text-center text-sm text-ink-500">
                        Ningún evento de la ventana trae métricas.
                    </p>
                <?php else: ?>
                    <?php foreach ($metricGroups as $groupKey => $groupMetrics): ?>
                        <div class="mt-6 first:mt-5">
                            <h3 class="flex items-center gap-3 text-xs font-semibold uppercase tracking-wider text-ink-500">
                                <?= F::e(MetricCatalog::groupLabel((string) $groupKey)) ?>
                                <span class="h-px flex-1 bg-ink-100"></span>
                                <span class="font-normal normal-case tracking-normal text-ink-400">
                                    <?= F::number(count($groupMetrics)) ?>
                                </span>
                            </h3>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                <?php foreach ($groupMetrics as $canonical => $sample): ?>
                                    <?php $spark = Chart::sparkline($sample['series'] ?? [], [
                                        'aria' => 'Tendencia de ' . MetricCatalog::label((string) $canonical),
                                    ]); ?>
                                    <div class="rounded-xl border border-ink-200 p-4">
                                        <div class="flex items-start justify-between gap-2">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-500">
                                                <?= F::e(MetricCatalog::label((string) $canonical)) ?>
                                            </p>
                                            <?php if ($sample['legacy']): ?>
                                                <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800"
                                                    title="Leído de una clave de formato anterior">legacy</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="mt-2 flex items-end justify-between gap-3">
                                            <p class="text-2xl font-bold leading-none">
                                                <?= F::e(MetricCatalog::formatValue((string) $canonical, $sample['value'])) ?>
                                                <?php if (MetricCatalog::unit((string) $canonical) !== null): ?>
                                                    <span class="text-sm font-semibold text-ink-500">
                                                        <?= F::e(MetricCatalog::unit((string) $canonical)) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </p>

                                            <?php if ($spark !== ''): ?>
                                                <div class="shrink-0 pb-0.5"><?= $spark ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <p class="mt-2 text-xs text-ink-500">
                                            <?= F::e(F::relativeTime($sample['event_timestamp'])) ?>
                                            <?php if ($sample['device_name'] !== null): ?>
                                                · <?= F::e($sample['device_name']) ?>
                                            <?php endif; ?>
                                        </p>

                                        <p class="mt-1 truncate font-mono text-[11px] text-ink-400"
                                            title="<?= F::e($sample['key']) ?>">
                                            <?= F::e($sample['key']) ?>
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- =============================================================
                 Severidad y categorías activas
                 ============================================================= -->
            <section class="mt-8 grid gap-6 lg:grid-cols-2">
                <article class="rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Eventos por severidad</h2>
                    <p class="mt-1 text-sm text-ink-500">
                        Solo se listan las severidades con eventos en la ventana, más error y aviso.
                    </p>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <?php foreach ($severityVisible as $severity => $count): ?>
                            <a href="/events.php?severity=<?= urlencode((string) $severity) ?>"
                                class="group flex items-center justify-between rounded-xl border border-ink-200 p-4
                                    transition-colors hover:border-brand-200 hover:bg-brand-50">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::severityBadgeClass((string) $severity) ?>">
                                    <?= F::e(F::severityLabel((string) $severity)) ?>
                                </span>
                                <span class="text-lg font-bold transition-colors group-hover:text-brand-700">
                                    <?= F::number($count) ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Categorías activas</h2>
                    <p class="mt-1 text-sm text-ink-500">
                        Familias de evento que han producido datos en la ventana.
                    </p>

                    <?php if ($categories === []): ?>
                        <p class="mt-5 rounded-xl border border-dashed border-ink-300 px-4 py-8 text-center text-sm text-ink-500">
                            Sin eventos en la ventana.
                        </p>
                    <?php else: ?>
                        <div class="mt-5 space-y-3">
                            <?php foreach ($categories as $category): ?>
                                <?php
                                $total = (int) $category['total'];
                                $share = $categoryTotal > 0 ? ($total / $categoryTotal) * 100 : 0.0;
                                $errors = (int) ($category['error_total'] ?? 0);
                                ?>
                                <a href="/events.php?event_category=<?= urlencode((string) $category['value']) ?>"
                                    class="block rounded-xl border border-ink-200 p-3 transition-colors hover:border-brand-200 hover:bg-brand-50">
                                    <div class="flex items-baseline justify-between gap-3">
                                        <span class="font-medium"><?= F::e($category['value']) ?></span>
                                        <span class="text-sm font-bold"><?= F::number($total) ?></span>
                                    </div>

                                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-ink-100">
                                        <div class="h-full rounded-full bg-brand-500"
                                            style="width: <?= F::e(number_format(max($share, 1.0), 2, '.', '')) ?>%"></div>
                                    </div>

                                    <div class="mt-1.5 flex items-center justify-between text-xs text-ink-500">
                                        <span><?= F::e(F::relativeTime($category['last_event_at'] ?? null)) ?></span>
                                        <?php if ($errors > 0): ?>
                                            <span class="font-medium text-red-700"><?= F::number($errors) ?> con error de parseo</span>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </section>

            <!-- =============================================================
                 Tipos de evento vivos + los que dejaron de reportar
                 ============================================================= -->
            <section class="mt-8 grid gap-6 lg:grid-cols-2">
                <article class="rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Tipos de evento activos</h2>
                    <p class="mt-1 text-sm text-ink-500">
                        Una fila por tipo, con sus errores de parseo dentro.
                    </p>

                    <div class="mt-5 overflow-hidden rounded-xl border border-ink-200">
                        <table class="min-w-full divide-y divide-ink-200 text-sm">
                            <thead class="bg-ink-50 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                                <tr>
                                    <th class="px-4 py-3">Tipo</th>
                                    <th class="px-4 py-3">Último</th>
                                    <th class="px-4 py-3 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-ink-200 bg-white">
                                <?php foreach ($eventTypes as $row): ?>
                                    <?php $errors = (int) ($row['error_total'] ?? 0); ?>
                                    <tr class="group cursor-pointer hover:bg-ink-50"
                                        onclick="window.location='/events.php?event_type=<?= urlencode((string) $row['value']) ?>'">
                                        <td class="px-4 py-3">
                                            <div class="font-medium transition-colors group-hover:text-brand-700">
                                                <?= F::e($row['value']) ?>
                                            </div>
                                            <?php if ($errors > 0): ?>
                                                <div class="mt-1 text-xs font-medium text-red-700">
                                                    <?= F::number($errors) ?> con error de parseo
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-xs text-ink-500">
                                            <?= F::e(F::relativeTime($row['last_event_at'] ?? null)) ?>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold"><?= F::number($row['total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if ($eventTypes === []): ?>
                                    <tr>
                                        <td colspan="3" class="px-4 py-6 text-center text-ink-500">
                                            Sin eventos en la ventana.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($dormantTypes !== []): ?>
                        <details class="mt-4 rounded-xl border border-ink-200 bg-ink-50">
                            <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-ink-600">
                                <?= F::number(count($dormantTypes)) ?> tipo(s) sin datos en la ventana
                            </summary>

                            <div class="border-t border-ink-200 px-4 py-3">
                                <p class="text-xs text-ink-500">
                                    Existen en el histórico pero han dejado de reportar. Se guardan aquí
                                    para no ensuciar los filtros vivos.
                                </p>

                                <ul class="mt-3 space-y-1.5">
                                    <?php foreach ($dormantTypes as $row): ?>
                                        <li class="flex items-baseline justify-between gap-3 text-xs">
                                            <a class="font-mono text-ink-500 hover:text-brand-700 hover:underline"
                                                href="/events.php?event_type=<?= urlencode((string) $row['value']) ?>">
                                                <?= F::e($row['value']) ?>
                                            </a>
                                            <span class="text-ink-400">
                                                <?= F::number((int) $row['total']) ?> ·
                                                <?= F::e(F::relativeTime($row['last_event_at'] ?? null)) ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </details>
                    <?php endif; ?>
                </article>

                <article class="rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Dispositivos más activos</h2>
                    <p class="mt-1 text-sm text-ink-500">
                        Ordenados por volumen de eventos en la ventana.
                    </p>

                    <div class="mt-5 overflow-hidden rounded-xl border border-ink-200">
                        <table class="min-w-full divide-y divide-ink-200 text-sm">
                            <thead class="bg-ink-50 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                                <tr>
                                    <th class="px-4 py-3">Dispositivo</th>
                                    <th class="px-4 py-3">Último</th>
                                    <th class="px-4 py-3 text-right">Eventos</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-ink-200 bg-white">
                                <?php foreach ($activeDevices as $row): ?>
                                    <?php $errors = (int) ($row['error_total'] ?? 0); ?>
                                    <tr class="hover:bg-ink-50">
                                        <td class="px-4 py-3">
                                            <a class="font-medium text-brand-700 hover:underline"
                                                href="/device.php?id=<?= F::e($row['id']) ?>">
                                                <?= F::nullable($row['name'] ?? null) ?>
                                            </a>
                                            <div class="mt-1 flex items-center gap-2 text-xs text-ink-500">
                                                <span class="inline-flex rounded-full px-2 py-0.5 font-semibold ring-1 <?= F::deviceKindBadgeClass((string) $row['device_kind']) ?>">
                                                    <?= F::e(F::deviceKindLabel((string) $row['device_kind'])) ?>
                                                </span>
                                                <?php if ($errors > 0): ?>
                                                    <span class="font-medium text-red-700"><?= F::number($errors) ?> error</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-xs text-ink-500">
                                            <?= F::e(F::relativeTime($row['last_event_at'] ?? null)) ?>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold">
                                            <?= F::number((int) $row['event_total']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if ($activeDevices === []): ?>
                                    <tr>
                                        <td colspan="3" class="px-4 py-6 text-center text-ink-500">
                                            Ningún dispositivo ha emitido en la ventana.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <!-- =============================================================
                 Últimos eventos
                 ============================================================= -->
            <section class="mt-8 rounded-2xl border border-ink-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-ink-200 px-6 py-5">
                    <div>
                        <h2 class="text-lg font-semibold">Últimos eventos</h2>
                        <p class="mt-1 text-sm text-ink-500">
                            Los 20 más recientes por fecha de evento.
                        </p>
                    </div>

                    <a class="text-sm font-medium text-brand-700 hover:underline" href="/events.php">
                        Ver todos los eventos →
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-ink-200 text-sm">
                        <thead class="bg-ink-50 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                            <tr>
                                <th class="px-4 py-3">Fecha evento</th>
                                <th class="px-4 py-3">Severidad</th>
                                <th class="px-4 py-3">Tipo</th>
                                <th class="px-4 py-3">Dispositivo</th>
                                <th class="px-4 py-3">Bridge</th>
                                <th class="px-4 py-3">Mensaje</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-200 bg-white">
                            <?php foreach ($latestEvents as $event): ?>
                                <?php
                                $parseOk = ((int) $event['parse_ok']) === 1;
                                $summary = F::eventSummary($event);
                                // El resumen de métricas ya lo pinta la línea de abajo:
                                // se recorta el que la ingesta dejó dentro de message_text.
                                $message = F::messageWithoutMetrics($event['message_text'] ?? '');
                                ?>
                                <tr class="align-top hover:bg-ink-50">
                                    <td class="whitespace-nowrap px-4 py-3 text-ink-600">
                                        <?= F::datetime($event['event_timestamp'] ?? null) ?>
                                        <div class="mt-1 text-xs text-ink-400">
                                            <?= F::e(F::relativeTime($event['event_timestamp'] ?? null)) ?>
                                        </div>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::severityBadgeClass((string) $event['severity']) ?>">
                                            <?= F::e(F::severityLabel((string) $event['severity'])) ?>
                                        </span>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3">
                                        <div class="font-medium"><?= F::e($event['event_type']) ?></div>
                                        <div class="mt-1 text-xs text-ink-500">
                                            <?= F::e($event['event_category'] ?? 'unknown') ?>
                                        </div>
                                        <?php if (!$parseOk): ?>
                                            <div class="mt-1 text-xs text-red-700">
                                                <?= F::nullable($event['parse_error'] ?? null) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-4 py-3">
                                        <?php if (($event['device_id'] ?? null) !== null): ?>
                                            <a class="font-medium text-brand-700 hover:underline"
                                                href="/device.php?id=<?= F::e($event['device_id']) ?>">
                                                <?= F::nullable($event['device_name'] ?? null) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-ink-400">—</span>
                                        <?php endif; ?>
                                        <div class="mt-1 font-mono text-xs text-ink-500">
                                            <?= F::nullable($event['device_mac_raw'] ?? $event['device_mac_address'] ?? null) ?>
                                        </div>
                                    </td>

                                    <td class="px-4 py-3">
                                        <?php if (($event['bridge_device_id'] ?? null) !== null): ?>
                                            <a class="font-medium text-brand-700 hover:underline"
                                                href="/device.php?id=<?= F::e($event['bridge_device_id']) ?>">
                                                <?= F::nullable($event['bridge_external_id'] ?? null) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-ink-400">—</span>
                                        <?php endif; ?>
                                        <div class="mt-1 text-xs text-ink-500">
                                            <?= F::nullable($event['bridge_name'] ?? null) ?>
                                        </div>
                                    </td>

                                    <td class="min-w-[360px] px-4 py-3 text-ink-700">
                                        <a class="text-brand-700 hover:underline" href="/event.php?id=<?= F::e($event['id']) ?>">
                                            Ver detalle
                                        </a>
                                        <?php if ($message !== ''): ?>
                                            <div class="mt-1"><?= F::shortText($message, 200) ?></div>
                                        <?php endif; ?>
                                        <?php if ($summary !== null): ?>
                                            <div class="mt-1.5 font-mono text-xs text-ink-500">
                                                <?= F::e($summary) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ($latestEvents === []): ?>
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-ink-500">
                                        No hay eventos en la ventana seleccionada.
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
