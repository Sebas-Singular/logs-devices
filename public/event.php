<?php

declare(strict_types=1);

use App\Database\Connection;
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

$eventId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => [
        'min_range' => 1,
    ],
]);

if ($eventId === false || $eventId === null) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid or missing event id.';
    exit;
}

$loadError = null;
$event = null;

try {
    $pdo = Connection::make();
    $queries = new ViewerQueries($pdo);

    $event = $queries->findEvent((int) $eventId);

    if ($event === null) {
        http_response_code(404);
    }
} catch (Throwable $exception) {
    http_response_code(500);
    $loadError = $exception->getMessage();
}

?>
<!doctype html>
<html lang="es">

<head>
    <?php $pageTitle = 'Detalle de evento';
    require __DIR__ . '/_viewer_head.php'; ?>
</head>

<body class="min-h-screen bg-ink-100 text-ink-900">
    <?php $activePage = 'events';
    require __DIR__ . '/_viewer_header.php'; ?>

    <main class="mx-auto max-w-7xl px-6 py-8">
        <?php if ($loadError !== null): ?>
            <section class="rounded-2xl border border-red-200 bg-red-50 p-6 text-red-800 shadow-sm">
                <h2 class="text-lg font-semibold">Error cargando evento</h2>
                <p class="mt-2 font-mono text-sm"><?= F::e($loadError) ?></p>
            </section>
        <?php elseif ($event === null): ?>
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-amber-900 shadow-sm">
                <h2 class="text-lg font-semibold">Evento no encontrado</h2>
                <p class="mt-2 text-sm">No existe ningún evento con ID <?= F::e($eventId) ?>.</p>
                <p class="mt-4">
                    <a class="font-medium text-amber-900 underline" href="/events.php">Volver a eventos</a>
                </p>
            </section>
        <?php else: ?>
            <?php $parseOk = ((int) $event['parse_ok']) === 1; ?>

            <section class="mb-8 rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">Navegación de investigación</h2>
                <p class="mt-1 text-sm text-ink-500">
                    Accesos rápidos para revisar eventos relacionados.
                </p>

                <div class="mt-5 flex flex-wrap gap-3">
                    <a
                        class="rounded-lg border border-ink-300 px-4 py-2 text-sm font-semibold text-ink-700 hover:bg-ink-50"
                        href="/events.php?ingest_id=<?= F::e($event['ingest_id']) ?>">
                        Eventos de la misma ingesta
                    </a>

                    <?php if (($event['device_id'] ?? null) !== null): ?>
                        <a
                            class="rounded-lg border border-ink-300 px-4 py-2 text-sm font-semibold text-ink-700 hover:bg-ink-50"
                            href="/events.php?device_id=<?= F::e($event['device_id']) ?>">
                            Eventos del mismo dispositivo
                        </a>
                    <?php endif; ?>

                    <?php if (($event['bridge_device_id'] ?? null) !== null): ?>
                        <a
                            class="rounded-lg border border-ink-300 px-4 py-2 text-sm font-semibold text-ink-700 hover:bg-ink-50"
                            href="/events.php?bridge_id=<?= F::e($event['bridge_device_id']) ?>">
                            Eventos del mismo bridge
                        </a>
                    <?php endif; ?>

                    <?php if (($event['parse_ok'] ?? null) !== null): ?>
                        <a
                            class="rounded-lg border border-ink-300 px-4 py-2 text-sm font-semibold text-ink-700 hover:bg-ink-50"
                            href="/events.php?parse_ok=<?= ((int) $event['parse_ok']) === 1 ? '1' : '0' ?>">
                            Eventos con mismo estado de parse
                        </a>
                    <?php endif; ?>

                    <a
                        class="rounded-lg bg-ink-900 px-4 py-2 text-sm font-semibold text-white hover:bg-ink-700"
                        href="/events.php">
                        Volver a eventos
                    </a>
                </div>
            </section>

            <section class="grid gap-6 lg:grid-cols-3">
                <article class="rounded-2xl border border-ink-200 bg-white p-6 shadow-sm lg:col-span-1">
                    <h2 class="text-lg font-semibold">Resumen</h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-ink-500">ID evento</dt>
                            <dd class="mt-1 font-mono text-ink-900"><?= F::e($event['id']) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Severidad</dt>
                            <dd class="mt-1">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::severityBadgeClass((string) $event['severity']) ?>">
                                    <?= F::e(F::severityLabel((string) $event['severity'])) ?>
                                </span>
                                <span class="ml-2 text-xs text-ink-500">
                                    <?= F::e(F::severityOriginLabel($event['severity_origin'] ?? null)) ?>
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Parseo</dt>
                            <dd class="mt-1">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::parseBadgeClass($parseOk) ?>">
                                    <?= $parseOk ? 'OK' : 'Error' ?>
                                </span>

                                <?php if (!$parseOk): ?>
                                    <div class="mt-2 text-xs font-medium text-red-700">
                                        <?= F::nullable($event['parse_error'] ?? null) ?>
                                    </div>
                                <?php endif; ?>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Calidad del dato</dt>
                            <dd class="mt-1">
                                <?php $quality = (string) ($event['quality_status'] ?? 'valid'); ?>
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::qualityBadgeClass($quality) ?>">
                                    <?= F::e(F::qualityLabel($quality)) ?>
                                </span>

                                <?php $anomalies = F::anomalyFlags($event['anomaly_flags'] ?? null); ?>
                                <?php if ($anomalies !== []): ?>
                                    <ul class="mt-2 space-y-1">
                                        <?php foreach ($anomalies as $flag): ?>
                                            <li class="text-xs text-amber-800">
                                                · <?= F::e(F::anomalyLabel($flag)) ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Tipo</dt>
                            <dd class="mt-1 text-ink-900">
                                <?= F::e($event['event_type']) ?>
                                <span class="text-ink-400">/</span>
                                <?= F::e($event['event_category']) ?>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Fecha evento</dt>
                            <dd class="mt-1 text-ink-900"><?= F::datetime($event['event_timestamp'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Recibido</dt>
                            <dd class="mt-1 text-ink-900"><?= F::datetime($event['received_at'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Hash del evento</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-ink-700"><?= F::nullable($event['event_hash'] ?? null) ?></dd>
                        </div>
                    </dl>
                </article>

                <article class="rounded-2xl border border-ink-200 bg-white p-6 shadow-sm lg:col-span-2">
                    <h2 class="text-lg font-semibold">Mensaje completo</h2>

                    <pre class="mt-4 whitespace-pre-wrap break-words rounded-xl bg-ink-950 p-4 text-sm text-ink-100"><?= F::e($event['message_text'] ?? '') ?></pre>
                </article>
            </section>

            <section class="mt-8 grid gap-6 lg:grid-cols-2">
                <article class="rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Dispositivo origen</h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-ink-500">Dispositivo</dt>
                            <dd class="mt-1">
                                <?php if (($event['device_id'] ?? null) !== null): ?>
                                    <a class="font-medium text-brand-700 hover:underline" href="/device.php?id=<?= F::e($event['device_id']) ?>">
                                        <?= F::nullable($event['device_name'] ?? null) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-ink-400">—</span>
                                <?php endif; ?>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">ID externo</dt>
                            <dd class="mt-1 font-mono text-ink-900"><?= F::nullable($event['device_external_id'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">MAC normalizada</dt>
                            <dd class="mt-1 font-mono text-ink-900"><?= F::nullable($event['device_mac_address'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">MAC en línea</dt>
                            <dd class="mt-1 font-mono text-ink-900"><?= F::nullable($event['device_mac_raw'] ?? null) ?></dd>
                        </div>
                    </dl>
                </article>

                <article class="rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Bridge / ingesta</h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-ink-500">Bridge</dt>
                            <dd class="mt-1">
                                <?php if (($event['bridge_device_id'] ?? null) !== null): ?>
                                    <a class="font-medium text-brand-700 hover:underline" href="/device.php?id=<?= F::e($event['bridge_device_id']) ?>">
                                        <?= F::nullable($event['bridge_external_id'] ?? null) ?>
                                    </a>
                                    <div class="mt-1 text-xs text-ink-500">
                                        <?= F::nullable($event['bridge_name'] ?? null) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-ink-400">—</span>
                                <?php endif; ?>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Ingesta</dt>
                            <dd class="mt-1">
                                <a class="font-mono font-medium text-brand-700 hover:underline" href="/ingest.php?id=<?= F::e($event['ingest_id']) ?>">
                                    #<?= F::e($event['ingest_id']) ?>
                                </a>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Estado de la ingesta</dt>
                            <dd class="mt-1 text-ink-900"><?= F::nullable($event['ingest_status'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Bridge reportado</dt>
                            <dd class="mt-1 text-ink-900">
                                <?= F::nullable($event['bridge_id_reported'] ?? null) ?>
                                <span class="text-ink-400">·</span>
                                <?= F::nullable($event['bridge_name_reported'] ?? null) ?>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Ruta del fichero</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-ink-700"><?= F::nullable($event['raw_path'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Hash del contenido</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-ink-700"><?= F::nullable($event['content_hash'] ?? null) ?></dd>
                        </div>
                    </dl>
                </article>
            </section>

            <section class="mt-8 grid gap-6 lg:grid-cols-2">
                <article class="rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Medidas</h2>
                    <pre class="mt-4 overflow-x-auto rounded-xl bg-ink-950 p-4 text-sm text-ink-100"><?= F::e(prettyJson($event['measurements'] ?? null)) ?></pre>
                </article>

                <article class="rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Contexto</h2>
                    <pre class="mt-4 overflow-x-auto rounded-xl bg-ink-950 p-4 text-sm text-ink-100"><?= F::e(prettyJson($event['context'] ?? null)) ?></pre>
                </article>
            </section>
        <?php endif; ?>
    </main>
</body>

</html>

<?php

function prettyJson(mixed $value): string
{
    if ($value === null || $value === '') {
        return '{}';
    }

    if (is_array($value)) {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    $decoded = json_decode((string) $value, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return (string) $value;
    }

    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}
