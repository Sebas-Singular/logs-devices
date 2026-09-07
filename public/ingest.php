<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Storage\Paths;
use App\Viewer\ViewerAuth;
use App\Viewer\ViewerFormatter as F;
use App\Viewer\ViewerQueries;
use App\Http\SecurityHeaders;
use App\Http\RateLimiter;
use App\Support\Env;
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

$ingestId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => [
        'min_range' => 1,
    ],
]);

if ($ingestId === false || $ingestId === null) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid or missing ingest id.';
    exit;
}

$loadError = null;
$ingest = null;
$events = [];

try {
    $pdo = Connection::make();
    $queries = new ViewerQueries($pdo);

    $ingest = $queries->findIngest((int) $ingestId);

    if ($ingest === null) {
        http_response_code(404);
    } else {
        $events = $queries->ingestEvents((int) $ingestId, 200);
    }
} catch (Throwable $exception) {
    http_response_code(500);
    $loadError = $exception->getMessage();
}

?>
<!doctype html>
<html lang="es">

<head>
    <?php $pageTitle = 'Detalle de ingesta';
    require __DIR__ . '/_viewer_head.php'; ?>
</head>

<body class="min-h-screen bg-ink-100 text-ink-900">
    <?php $activePage = '';
    require __DIR__ . '/_viewer_header.php'; ?>

    <main class="mx-auto max-w-7xl px-6 py-8">
        <?php if ($loadError !== null): ?>
            <section class="rounded-2xl border border-red-200 bg-red-50 p-6 text-red-800 shadow-sm">
                <h2 class="text-lg font-semibold">Error cargando la ingesta</h2>
                <p class="mt-2 font-mono text-sm"><?= F::e($loadError) ?></p>
            </section>
        <?php elseif ($ingest === null): ?>
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-amber-900 shadow-sm">
                <h2 class="text-lg font-semibold">Ingesta no encontrada</h2>
                <p class="mt-2 text-sm">No existe ninguna ingesta con ID <?= F::e($ingestId) ?>.</p>
                <p class="mt-4">
                    <a class="font-medium text-amber-900 underline" href="/ingests.php">Volver a ingestas</a>
                </p>
            </section>
        <?php else: ?>
            <section class="mb-8 rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">Navegación de investigación</h2>

                <div class="mt-5 flex flex-wrap gap-3">
                    <a class="rounded-lg border border-ink-300 px-4 py-2 text-sm font-semibold text-ink-700 hover:bg-ink-50" href="/events.php?ingest_id=<?= F::e($ingest['id']) ?>">
                        Eventos de este ingest
                    </a>

                    <?php if (($ingest['bridge_device_id'] ?? null) !== null): ?>
                        <a class="rounded-lg border border-ink-300 px-4 py-2 text-sm font-semibold text-ink-700 hover:bg-ink-50" href="/device.php?id=<?= F::e($ingest['bridge_device_id']) ?>">
                            Ver bridge
                        </a>
                    <?php endif; ?>

                    <a class="rounded-lg bg-ink-900 px-4 py-2 text-sm font-semibold text-white hover:bg-ink-700" href="/ingests.php">
                        Volver a ingests
                    </a>
                </div>
            </section>

            <section class="grid gap-6 lg:grid-cols-3">
                <article class="rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Resumen</h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-ink-500">Estado</dt>
                            <dd class="mt-1">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::ingestStatusBadgeClass((string) $ingest['status']) ?>">
                                    <?= F::e(F::ingestStatusLabel((string) $ingest['status'])) ?>
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Recibido</dt>
                            <dd class="mt-1 text-ink-900"><?= F::datetime($ingest['received_at'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Tipo de origen</dt>
                            <dd class="mt-1 font-mono text-ink-900"><?= F::nullable($ingest['source_type'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Bridge reportado</dt>
                            <dd class="mt-1">
                                <div class="font-mono text-ink-900"><?= F::nullable($ingest['bridge_id_reported'] ?? null) ?></div>
                                <?php if (($ingest['bridge_device_id'] ?? null) !== null): ?>
                                    <a class="mt-1 block text-brand-700 hover:underline" href="/device.php?id=<?= F::e($ingest['bridge_device_id']) ?>">
                                        <?= F::nullable($ingest['bridge_name'] ?? null) ?>
                                    </a>
                                <?php endif; ?>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Remote addr</dt>
                            <dd class="mt-1 font-mono text-ink-900"><?= F::nullable($ingest['remote_addr'] ?? null) ?></dd>
                        </div>
                    </dl>
                </article>

                <article class="rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Procesamiento</h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-ink-500">Líneas</dt>
                            <dd class="mt-1 text-ink-900"><?= F::number((int) ($ingest['line_count'] ?? 0)) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Líneas correctas</dt>
                            <dd class="mt-1 text-ink-900"><?= F::number((int) ($ingest['parsed_ok_count'] ?? 0)) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Líneas con error</dt>
                            <dd class="mt-1 text-ink-900"><?= F::number((int) ($ingest['parsed_error_count'] ?? 0)) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Inicio procesamiento</dt>
                            <dd class="mt-1 text-ink-900"><?= F::datetime($ingest['processing_started_at'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Fin procesamiento</dt>
                            <dd class="mt-1 text-ink-900"><?= F::datetime($ingest['processing_finished_at'] ?? null) ?></dd>
                        </div>
                    </dl>
                </article>

                <article class="rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Ficheros / hash</h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-ink-500">Ruta del fichero</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-ink-700"><?= F::nullable($ingest['raw_path'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">Hash del contenido</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-ink-700"><?= F::nullable($ingest['content_hash'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-ink-500">ID del dispositivo origen</dt>
                            <dd class="mt-1 font-mono text-ink-900"><?= F::nullable($ingest['source_device_id'] ?? null) ?></dd>
                        </div>
                    </dl>
                </article>
            </section>

            <section class="mt-8 rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">User-Agent</h2>
                <pre class="mt-4 whitespace-pre-wrap break-words rounded-xl bg-ink-950 p-4 text-sm text-ink-100"><?= F::e($ingest['user_agent'] ?? '') ?></pre>
            </section>

            <section class="mt-8 rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">Payload summary</h2>
                <pre class="mt-4 overflow-x-auto rounded-xl bg-ink-950 p-4 text-sm text-ink-100"><?= F::e(prettyJson($ingest['payload_summary'] ?? null)) ?></pre>
            </section>

            <section class="mt-8 rounded-2xl border border-ink-200 bg-white shadow-sm">
                <div class="border-b border-ink-200 px-6 py-5">
                    <h2 class="text-lg font-semibold">Eventos de la ingesta</h2>
                    <p class="mt-1 text-sm text-ink-500">
                        Mostrando hasta <?= F::number(count($events)) ?> eventos asociados.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-ink-200 text-sm">
                        <thead class="bg-ink-50 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">
                            <tr>
                                <th class="px-4 py-3">ID</th>
                                <th class="px-4 py-3">Fecha evento</th>
                                <th class="px-4 py-3">Severidad</th>
                                <th class="px-4 py-3">Tipo</th>
                                <th class="px-4 py-3">Dispositivo</th>
                                <th class="px-4 py-3">Mensaje</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-ink-200 bg-white">
                            <?php foreach ($events as $event): ?>
                                <?php $parseOk = ((int) $event['parse_ok']) === 1; ?>

                                <tr class="align-top hover:bg-ink-50">
                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">
                                        <a class="font-medium text-brand-700 hover:underline" href="/event.php?id=<?= F::e($event['id']) ?>">
                                            #<?= F::e($event['id']) ?>
                                        </a>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-ink-600">
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
                                            <a class="font-medium text-brand-700 hover:underline" href="/device.php?id=<?= F::e($event['device_id']) ?>">
                                                <?= F::nullable($event['device_name'] ?? null) ?>
                                            </a>
                                            <div class="mt-1 font-mono text-xs text-ink-500">
                                                <?= F::nullable($event['device_mac_raw'] ?? $event['device_mac_address'] ?? null) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-ink-400">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="min-w-[420px] px-4 py-3 text-ink-700">
                                        <a class="text-brand-700 hover:underline" href="/event.php?id=<?= F::e($event['id']) ?>">
                                            Ver detalle
                                        </a>
                                        <div class="mt-2">
                                            <?= F::shortText($event['message_text'] ?? '', 260) ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ($events === []): ?>
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-ink-500">
                                        Este ingest no tiene eventos asociados.
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

function applyIngestRateLimit(): void
{
    if (!ingestRateLimitEnabled()) {
        return;
    }

    $storageDir = Paths::for('rate-limit');

    $result = RateLimiter::hit(
        storageDir: $storageDir,
        key: ingestRateLimitKey(),
        maxAttempts: ingestRateLimitEnvInt('INGEST_RATE_LIMIT_MAX', 1000),
        windowSeconds: ingestRateLimitEnvInt('INGEST_RATE_LIMIT_WINDOW_SECONDS', 600),
    );

    if ($result['allowed']) {
        if (!headers_sent()) {
            header('X-RateLimit-Limit: ' . $result['limit']);
            header('X-RateLimit-Remaining: ' . $result['remaining']);
            header('X-RateLimit-Reset: ' . $result['reset_at']);
        }

        return;
    }

    if (!headers_sent()) {
        http_response_code(429);
        header('Retry-After: ' . $result['retry_after_seconds']);
        header('X-RateLimit-Limit: ' . $result['limit']);
        header('X-RateLimit-Remaining: 0');
        header('X-RateLimit-Reset: ' . $result['reset_at']);
    }

    echo json_encode([
        'ok' => false,
        'error' => 'rate_limited',
        'message' => 'Too many ingest requests. Please retry later.',
        'retry_after_seconds' => $result['retry_after_seconds'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

function ingestRateLimitEnabled(): bool
{
    $raw = Env::get('INGEST_RATE_LIMIT_ENABLED');

    if ($raw === null || trim((string) $raw) === '') {
        return true;
    }

    return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
}

function ingestRateLimitEnvInt(string $key, int $default): int
{
    $raw = Env::get($key);

    if ($raw === null || trim((string) $raw) === '') {
        return $default;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT);

    if ($value === false) {
        return $default;
    }

    return max(0, (int) $value);
}

function ingestRateLimitKey(): string
{
    $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
    $authHeader = trim((string) ($_SERVER['HTTP_X_LOG_AUTH'] ?? ''));

    $authMarker = $authHeader === ''
        ? 'no-auth-header'
        : hash('sha256', $authHeader);

    return 'ingest'
        . '|ip=' . $remoteAddr
        . '|ua=' . $userAgent
        . '|auth=' . $authMarker;
}
