<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Support\Env;
use App\Viewer\ViewerAuth;
use App\Viewer\ViewerFormatter as F;
use App\Viewer\ViewerQueries;
use App\Http\SecurityHeaders;
use App\Support\Bootstrap;

require_once __DIR__ . '/../vendor/autoload.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed.';
    exit;
}

Bootstrap::init();
SecurityHeaders::applyViewer();
ViewerAuth::enforce();

$loadError = null;
$devices   = [];
$historicalBalizas = [];


try {
    $pdo     = Connection::make();
    $queries = new ViewerQueries($pdo);
    $devices = $queries->devicesList();
    $historicalBalizas = $queries->historicalBalizasByBridge();
} catch (Throwable $exception) {
    $historicalBalizas = [];
    http_response_code(500);
    $loadError = $exception->getMessage();
}

// --- Agrupación para la vista árbol ---
$bridges        = [];   // id => device
$balizasByBridge = []; // bridge_id => device[]
$orphans        = [];   // balizas sin bridge padre conocido

foreach ($devices as $device) {
    if ($device['device_kind'] === 'bridge') {
        $bridges[(int) $device['id']] = $device;
    } else {
        $parentId = isset($device['parent_device_id']) && $device['parent_device_id'] !== null
            ? (int) $device['parent_device_id']
            : null;

        if ($parentId !== null) {
            $balizasByBridge[$parentId][] = $device;
        } else {
            $orphans[] = $device;
        }
    }
}

?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>logs-devices · Dispositivos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>

<body class="min-h-screen bg-slate-100 text-slate-900">
    <?php $activePage = 'devices';
    require __DIR__ . '/_viewer_header.php'; ?>

    <main class="mx-auto max-w-7xl px-6 py-8">
        <?php if ($loadError !== null): ?>
            <section class="rounded-2xl border border-red-200 bg-red-50 p-6 text-red-800 shadow-sm">
                <h2 class="text-lg font-semibold">Error cargando dispositivos</h2>
                <p class="mt-2 font-mono text-sm"><?= F::e($loadError) ?></p>
            </section>
        <?php else: ?>

            <div
                x-data="{
                view: localStorage.getItem('ld_devices_view') || 'tree',
                open: {},
                setView(v) {
                    this.view = v;
                    localStorage.setItem('ld_devices_view', v);
                },
                toggleBridge(id) {
                    this.open[id] = !this.open[id];
                },
                isOpen(id) {
                    return this.open[id] !== false;
                }
            }"
                x-init="
                <?php foreach (array_keys($bridges) as $bridgeId): ?>
                    open[<?= (int) $bridgeId ?>] = (localStorage.getItem('ld_bridge_open_<?= (int) $bridgeId ?>') !== 'false');
                <?php endforeach; ?>
            ">
                <!-- Cabecera con toggle de vista -->
                <div class="mb-5 flex items-center justify-between">
                    <p class="text-sm text-slate-500">
                        Total: <span class="font-semibold text-slate-700"><?= F::number(count($devices)) ?></span> dispositivos
                        (<?= F::number(count($bridges)) ?> bridges · <?= F::number(count($devices) - count($bridges)) ?> balizas)
                    </p>

                    <div class="flex items-center gap-1 rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
                        <button
                            @click="setView('list')"
                            :class="view === 'list'
                            ? 'bg-slate-900 text-white'
                            : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900'"
                            class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-colors">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                            </svg>
                            Lista
                        </button>
                        <button
                            @click="setView('tree')"
                            :class="view === 'tree'
                            ? 'bg-slate-900 text-white'
                            : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900'"
                            class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-colors">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 7h4m0 0v10m0-10h10M7 12h6m0 0v5m0-5h4" />
                            </svg>
                            Árbol
                        </button>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- VISTA LISTA (tabla existente)                                 -->
                <!-- ============================================================ -->
                <section x-show="view === 'list'" x-cloak
                    class="rounded-2xl border border-slate-200 bg-white shadow-sm">
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
                                    $kind       = (string) $device['device_kind'];
                                    $eventCount = (int) ($device['event_count'] ?? 0);
                                    $warnCount  = (int) ($device['warn_count'] ?? 0);
                                    $errorCount = (int) ($device['error_count'] ?? 0) + (int) ($device['critical_count'] ?? 0);
                                    ?>
                                    <tr class="align-top hover:bg-slate-50">
                                        <td class="whitespace-nowrap px-4 py-3">
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::deviceKindBadgeClass($kind) ?>">
                                                <?= F::e(F::deviceKindLabel($kind)) ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="font-medium">
                                                <a class="text-sky-700 hover:underline" href="/device.php?id=<?= F::e($device['id']) ?>">
                                                    <?= F::nullable($device['name'] ?? null) ?>
                                                </a>
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
                                                <div class="font-medium"><?= F::nullable($device['parent_external_id'] ?? null) ?></div>
                                                <div class="mt-1 text-xs text-slate-500"><?= F::nullable($device['parent_name'] ?? null) ?></div>
                                            <?php else: ?>
                                                <span class="text-slate-400">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-semibold"><?= F::number($eventCount) ?></td>
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
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= F::datetime($device['last_event_at'] ?? null) ?></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= F::datetime($device['first_seen_at'] ?? null) ?></td>
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

                <!-- ============================================================ -->
                <!-- VISTA ÁRBOL                                                    -->
                <!-- ============================================================ -->
                <section x-show="view === 'tree'" x-cloak class="space-y-3">

                    <?php if (empty($bridges) && empty($orphans)): ?>
                        <div class="rounded-2xl border border-slate-200 bg-white px-6 py-8 text-center text-slate-500 shadow-sm">
                            No hay dispositivos todavía.
                        </div>
                    <?php endif; ?>

                    <?php foreach ($bridges as $bridgeId => $bridge): ?>
                        <?php
                        $bEvents  = (int) ($bridge['event_count'] ?? 0);
                        $bWarns   = (int) ($bridge['warn_count'] ?? 0);
                        $bErrors  = (int) ($bridge['error_count'] ?? 0) + (int) ($bridge['critical_count'] ?? 0);
                        $children = $balizasByBridge[$bridgeId] ?? [];
                        $childCount = count($children);
                        ?>

                        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

                            <!-- Fila del bridge -->
                            <div class="flex items-center gap-4 px-5 py-4 bg-slate-50 border-b border-slate-200">

                                <!-- Toggle expand -->
                                <button
                                    @click="
                                        toggleBridge(<?= $bridgeId ?>);
                                        localStorage.setItem('ld_bridge_open_<?= $bridgeId ?>', isOpen(<?= $bridgeId ?>));
                                    "
                                    class="flex-shrink-0 rounded-md p-1 text-slate-400 hover:bg-slate-200 hover:text-slate-700 transition-colors"
                                    :title="isOpen(<?= $bridgeId ?>) ? 'Colapsar' : 'Expandir'">
                                    <svg class="h-4 w-4 transition-transform duration-200"
                                        :class="isOpen(<?= $bridgeId ?>) ? 'rotate-90' : ''"
                                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </button>

                                <!-- Badge -->
                                <span class="inline-flex flex-shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::deviceKindBadgeClass('bridge') ?>">
                                    Bridge
                                </span>

                                <!-- Nombre · External ID -->
                                <div class="flex-1 min-w-0">
                                    <a class="font-semibold text-slate-900 hover:text-sky-700 hover:underline transition-colors"
                                        href="/device.php?id=<?= F::e($bridge['id']) ?>">
                                        <?= F::nullable($bridge['name'] ?? null) ?>
                                        <?php if (($bridge['external_id'] ?? '') !== ''): ?>
                                            <span class="ml-1.5 font-normal text-slate-500">· <?= F::e($bridge['external_id']) ?></span>
                                        <?php endif; ?>
                                    </a>
                                </div>

                                <!-- Contadores (anchos fijos para alineación con balizas) -->
                                <div class="flex items-center gap-4 flex-shrink-0">
                                    <div class="w-16 text-center">
                                        <div class="font-bold text-slate-800"><?= F::number($bEvents) ?></div>
                                        <div class="text-xs text-slate-500">eventos</div>
                                    </div>
                                    <div class="w-12 text-center">
                                        <div class="font-bold <?= $bWarns > 0 ? 'text-amber-700' : 'text-slate-400' ?>"><?= F::number($bWarns) ?></div>
                                        <div class="text-xs text-slate-500">warn</div>
                                    </div>
                                    <div class="w-12 text-center">
                                        <div class="font-bold <?= $bErrors > 0 ? 'text-red-700' : 'text-slate-400' ?>"><?= F::number($bErrors) ?></div>
                                        <div class="text-xs text-slate-500">error</div>
                                    </div>
                                    <div class="w-14 text-center">
                                        <div class="font-semibold text-slate-700"><?= F::number($childCount) ?></div>
                                        <div class="text-xs text-slate-500">balizas</div>
                                    </div>
                                    <div class="w-40 text-right text-xs text-slate-500 hidden lg:block">
                                        <div>Último evento</div>
                                        <div class="font-medium text-slate-700"><?= F::datetime($bridge['last_event_at'] ?? null) ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Balizas hijas -->
                            <div x-show="isOpen(<?= $bridgeId ?>)"
                                x-transition:enter="transition-all duration-200 ease-out"
                                x-transition:enter-start="opacity-0 -translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0">

                                <?php if (empty($children)): ?>
                                    <div class="px-5 py-3 text-sm text-slate-400 border-b border-slate-100">
                                        <span class="ml-9">Sin balizas asociadas</span>
                                    </div>
                                <?php endif; ?>

                                <?php foreach ($children as $baliza): ?>
                                    <?php
                                    $nEvents = (int) ($baliza['event_count'] ?? 0);
                                    $nWarns  = (int) ($baliza['warn_count'] ?? 0);
                                    $nErrors = (int) ($baliza['error_count'] ?? 0) + (int) ($baliza['critical_count'] ?? 0);
                                    ?>
                                    <div class="flex items-center gap-4 px-5 py-3 border-b border-slate-100 last:border-0 hover:bg-slate-50 transition-colors">

                                        <div class="flex-shrink-0 flex items-center gap-1 ml-7">
                                            <div class="w-px h-4 bg-slate-300"></div>
                                            <div class="w-3 h-px bg-slate-300"></div>
                                        </div>

                                        <span class="inline-flex flex-shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::deviceKindBadgeClass('baliza') ?>">
                                            Baliza
                                        </span>

                                        <div class="flex-1 min-w-0">
                                            <a class="font-medium text-slate-800 hover:text-sky-700 hover:underline transition-colors text-sm"
                                                href="/device.php?id=<?= F::e($baliza['id']) ?>">
                                                <?= F::nullable($baliza['name'] ?? null) ?>
                                                <?php if (($baliza['external_id'] ?? '') !== ''): ?>
                                                    <span class="ml-1.5 font-normal text-slate-500">· <?= F::e($baliza['external_id']) ?></span>
                                                <?php endif; ?>
                                            </a>
                                            <div class="mt-0.5 text-xs text-slate-500 font-mono">
                                                <?= F::nullable($baliza['mac_address'] ?? null) ?>
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-4 flex-shrink-0">
                                            <div class="w-16 text-center">
                                                <div class="font-semibold text-slate-700"><?= F::number($nEvents) ?></div>
                                                <div class="text-xs text-slate-500">eventos</div>
                                            </div>
                                            <div class="w-12 text-center">
                                                <div class="font-semibold <?= $nWarns > 0 ? 'text-amber-700' : 'text-slate-400' ?>"><?= F::number($nWarns) ?></div>
                                                <div class="text-xs text-slate-500">warn</div>
                                            </div>
                                            <div class="w-12 text-center">
                                                <div class="font-semibold <?= $nErrors > 0 ? 'text-red-700' : 'text-slate-400' ?>"><?= F::number($nErrors) ?></div>
                                                <div class="text-xs text-slate-500">error</div>
                                            </div>
                                            <div class="w-14"></div>
                                            <div class="w-40 text-right text-xs text-slate-500 hidden lg:block">
                                                <div>Último evento</div>
                                                <div class="font-medium text-slate-700"><?= F::datetime($baliza['last_event_at'] ?? null) ?></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>

                        <?php
                        $movedBalizas = $historicalBalizas[$bridgeId] ?? [];
                        ?>

                        <?php foreach ($movedBalizas as $moved): ?>
                            <div class="flex items-center gap-4 px-5 py-3 border-b border-slate-100 last:border-0
                                bg-slate-50/50 opacity-75">

                                <!-- Conector visual con estilo diferente -->
                                <div class="flex-shrink-0 flex items-center gap-1 ml-7">
                                    <div class="w-px h-4 bg-slate-200"></div>
                                    <div class="w-3 h-px bg-slate-200"></div>
                                </div>

                                <!-- Badge atenuado -->
                                <span class="inline-flex flex-shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ring-1
                                     bg-slate-100 text-slate-400 ring-slate-200">
                                    Baliza
                                </span>

                                <!-- Nombre + badge "movida" -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <a class="font-medium text-slate-500 hover:text-sky-700 hover:underline
                                          transition-colors text-sm"
                                            href="/device.php?id=<?= F::e($moved['baliza_id']) ?>">
                                            <?= F::nullable($moved['baliza_name']) ?>
                                            <?php if (($moved['baliza_external_id'] ?? '') !== ''): ?>
                                                <span class="ml-1 font-normal text-slate-400">
                                                    · <?= F::e($moved['baliza_external_id']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </a>

                                        <!-- Indicador de bridge actual -->
                                        <a href="/device.php?id=<?= F::e($moved['current_bridge_id']) ?>"
                                            class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5
                                          text-xs font-medium text-amber-700 ring-1 ring-amber-200
                                          hover:bg-amber-100 transition-colors">
                                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M17 8l4 4m0 0l-4 4m4-4H3" />
                                            </svg>
                                            <?= F::e($moved['current_bridge_name']) ?>
                                            <?php if (($moved['current_bridge_external_id'] ?? '') !== ''): ?>
                                                · <?= F::e($moved['current_bridge_external_id']) ?>
                                            <?php endif; ?>
                                        </a>
                                    </div>

                                    <div class="mt-0.5 text-xs text-slate-400 font-mono">
                                        <?= F::nullable($moved['baliza_mac']) ?>
                                    </div>
                                </div>

                                <!-- Contadores de eventos a través de ESTE bridge -->
                                <div class="flex items-center gap-4 flex-shrink-0">
                                    <div class="w-16 text-center">
                                        <div class="font-semibold text-slate-400">
                                            <?= F::number((int) $moved['event_count']) ?>
                                        </div>
                                        <div class="text-xs text-slate-400">eventos</div>
                                    </div>
                                    <div class="w-12"></div><!-- warn spacer -->
                                    <div class="w-12"></div><!-- error spacer -->
                                    <div class="w-14"></div><!-- balizas spacer -->
                                    <div class="w-40 text-right text-xs text-slate-400 hidden lg:block">
                                        <div>Último evento aquí</div>
                                        <div class="font-medium"><?= F::datetime($moved['last_event_at']) ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
            </div>
        <?php endforeach; ?>

        <!-- Balizas huérfanas (sin bridge padre conocido) -->
        <?php if (!empty($orphans)): ?>
            <div class="overflow-hidden rounded-2xl border border-amber-200 bg-white shadow-sm">
                <div class="flex items-center gap-3 px-5 py-3 bg-amber-50 border-b border-amber-200">
                    <span class="text-sm font-medium text-amber-800">Sin bridge asignado</span>
                    <span class="text-xs text-amber-600"><?= count($orphans) ?> dispositivo(s)</span>
                </div>
                <?php foreach ($orphans as $orphan): ?>
                    <?php
                    $oEvents = (int) ($orphan['event_count'] ?? 0);
                    $oWarns  = (int) ($orphan['warn_count'] ?? 0);
                    $oErrors = (int) ($orphan['error_count'] ?? 0) + (int) ($orphan['critical_count'] ?? 0);
                    ?>
                    <div class="flex items-center gap-4 px-5 py-3 border-b border-slate-100 last:border-0 hover:bg-slate-50 transition-colors">
                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::deviceKindBadgeClass((string) $orphan['device_kind']) ?>">
                            <?= F::e(F::deviceKindLabel((string) $orphan['device_kind'])) ?>
                        </span>
                        <div class="flex-1 min-w-0">
                            <a class="font-medium text-slate-800 hover:text-sky-700 hover:underline text-sm"
                                href="/device.php?id=<?= F::e($orphan['id']) ?>">
                                <?= F::nullable($orphan['name'] ?? null) ?>
                            </a>
                            <div class="mt-0.5 text-xs text-slate-500 font-mono">
                                <?= F::nullable($orphan['mac_address'] ?? null) ?>
                            </div>
                        </div>
                        <div class="flex items-center gap-6 text-sm flex-shrink-0">
                            <div class="text-center">
                                <div class="font-semibold text-slate-700"><?= F::number($oEvents) ?></div>
                                <div class="text-xs text-slate-500">eventos</div>
                            </div>
                            <div class="text-center">
                                <div class="font-semibold <?= $oWarns > 0 ? 'text-amber-700' : 'text-slate-400' ?>"><?= F::number($oWarns) ?></div>
                                <div class="text-xs text-slate-500">warn</div>
                            </div>
                            <div class="text-center">
                                <div class="font-semibold <?= $oErrors > 0 ? 'text-red-700' : 'text-slate-400' ?>"><?= F::number($oErrors) ?></div>
                                <div class="text-xs text-slate-500">error</div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        </section>

        </div>

    <?php endif; ?>
    </main>
</body>

</html>
