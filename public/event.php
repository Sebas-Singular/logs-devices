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

Env::load(__DIR__ . '/../../../private/.env');
Env::loadPhpConfig(__DIR__ . '/../src/Config/runtime.local.php');

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
    <meta charset="utf-8">
    <title>logs-devices · Detalle evento</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-slate-100 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-5">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">
                    Evento #<?= F::e($eventId) ?>
                </h1>
                <p class="mt-1 text-sm text-slate-500">
                    Detalle completo de un evento parseado.
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
                <a href="/ingests.php" class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                    Ingests
                </a>
            </nav>
        </div>
    </header>

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

            <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">Navegación de investigación</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Accesos rápidos para revisar eventos relacionados.
                </p>

                <div class="mt-5 flex flex-wrap gap-3">
                    <a
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                        href="/events.php?ingest_id=<?= F::e($event['ingest_id']) ?>">
                        Eventos del mismo ingest
                    </a>

                    <?php if (($event['device_id'] ?? null) !== null): ?>
                        <a
                            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            href="/events.php?device_id=<?= F::e($event['device_id']) ?>">
                            Eventos del mismo dispositivo
                        </a>
                    <?php endif; ?>

                    <?php if (($event['bridge_device_id'] ?? null) !== null): ?>
                        <a
                            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            href="/events.php?bridge_id=<?= F::e($event['bridge_device_id']) ?>">
                            Eventos del mismo bridge
                        </a>
                    <?php endif; ?>

                    <?php if (($event['parse_ok'] ?? null) !== null): ?>
                        <a
                            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            href="/events.php?parse_ok=<?= ((int) $event['parse_ok']) === 1 ? '1' : '0' ?>">
                            Eventos con mismo estado de parse
                        </a>
                    <?php endif; ?>

                    <a
                        class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700"
                        href="/events.php">
                        Volver a eventos
                    </a>
                </div>
            </section>

            <section class="grid gap-6 lg:grid-cols-3">
                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-1">
                    <h2 class="text-lg font-semibold">Resumen</h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-slate-500">ID evento</dt>
                            <dd class="mt-1 font-mono text-slate-900"><?= F::e($event['id']) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Severidad</dt>
                            <dd class="mt-1">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::severityBadgeClass((string) $event['severity']) ?>">
                                    <?= F::e(F::severityLabel((string) $event['severity'])) ?>
                                </span>
                                <span class="ml-2 text-xs text-slate-500">
                                    <?= F::nullable($event['severity_origin'] ?? null) ?>
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Parse</dt>
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
                            <dt class="font-medium text-slate-500">Tipo</dt>
                            <dd class="mt-1 text-slate-900">
                                <?= F::e($event['event_type']) ?>
                                <span class="text-slate-400">/</span>
                                <?= F::e($event['event_category']) ?>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Fecha evento</dt>
                            <dd class="mt-1 text-slate-900"><?= F::datetime($event['event_timestamp'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Recibido</dt>
                            <dd class="mt-1 text-slate-900"><?= F::datetime($event['received_at'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Línea</dt>
                            <dd class="mt-1 font-mono text-slate-900"><?= F::nullable($event['line_number'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Hash evento</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-slate-700"><?= F::nullable($event['event_hash'] ?? null) ?></dd>
                        </div>
                    </dl>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                    <h2 class="text-lg font-semibold">Mensaje completo</h2>

                    <pre class="mt-4 whitespace-pre-wrap break-words rounded-xl bg-slate-950 p-4 text-sm text-slate-100"><?= F::e($event['message_text'] ?? '') ?></pre>
                </article>
            </section>

            <section class="mt-8 grid gap-6 lg:grid-cols-2">
                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Dispositivo origen</h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-slate-500">Dispositivo</dt>
                            <dd class="mt-1">
                                <?php if (($event['device_id'] ?? null) !== null): ?>
                                    <a class="font-medium text-sky-700 hover:underline" href="/device.php?id=<?= F::e($event['device_id']) ?>">
                                        <?= F::nullable($event['device_name'] ?? null) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-slate-400">—</span>
                                <?php endif; ?>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">External ID</dt>
                            <dd class="mt-1 font-mono text-slate-900"><?= F::nullable($event['device_external_id'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">MAC normalizada</dt>
                            <dd class="mt-1 font-mono text-slate-900"><?= F::nullable($event['device_mac_address'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">MAC en línea</dt>
                            <dd class="mt-1 font-mono text-slate-900"><?= F::nullable($event['device_mac_raw'] ?? null) ?></dd>
                        </div>
                    </dl>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Bridge / ingest</h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-slate-500">Bridge</dt>
                            <dd class="mt-1">
                                <?php if (($event['bridge_device_id'] ?? null) !== null): ?>
                                    <a class="font-medium text-sky-700 hover:underline" href="/device.php?id=<?= F::e($event['bridge_device_id']) ?>">
                                        <?= F::nullable($event['bridge_external_id'] ?? null) ?>
                                    </a>
                                    <div class="mt-1 text-xs text-slate-500">
                                        <?= F::nullable($event['bridge_name'] ?? null) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-slate-400">—</span>
                                <?php endif; ?>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Ingest</dt>
                            <dd class="mt-1">
                                <a class="font-mono font-medium text-sky-700 hover:underline" href="/ingest.php?id=<?= F::e($event['ingest_id']) ?>">
                                    #<?= F::e($event['ingest_id']) ?>
                                </a>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Estado ingest</dt>
                            <dd class="mt-1 text-slate-900"><?= F::nullable($event['ingest_status'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Bridge reportado</dt>
                            <dd class="mt-1 text-slate-900">
                                <?= F::nullable($event['bridge_id_reported'] ?? null) ?>
                                <span class="text-slate-400">·</span>
                                <?= F::nullable($event['bridge_name_reported'] ?? null) ?>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Raw path</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-slate-700"><?= F::nullable($event['raw_path'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Content hash</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-slate-700"><?= F::nullable($event['content_hash'] ?? null) ?></dd>
                        </div>
                    </dl>
                </article>
            </section>

            <section class="mt-8 grid gap-6 lg:grid-cols-2">
                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Measurements</h2>
                    <pre class="mt-4 overflow-x-auto rounded-xl bg-slate-950 p-4 text-sm text-slate-100"><?= F::e(prettyJson($event['measurements'] ?? null)) ?></pre>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Context</h2>
                    <pre class="mt-4 overflow-x-auto rounded-xl bg-slate-950 p-4 text-sm text-slate-100"><?= F::e(prettyJson($event['context'] ?? null)) ?></pre>
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
