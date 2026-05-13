<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Support\Env;
use App\Viewer\ViewerAuth;
use App\Viewer\ViewerFormatter as F;
use App\Viewer\ViewerQueries;
use App\Http\SecurityHeaders;
use App\Http\RateLimiter;

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
applyIngestRateLimit();

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
    <meta charset="utf-8">
    <title>logs-devices · Detalle ingest</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-slate-100 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-5">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Ingest #<?= F::e($ingestId) ?></h1>
                <p class="mt-1 text-sm text-slate-500">
                    Detalle técnico del POST recibido.
                </p>
            </div>

            <nav class="flex items-center gap-3 text-sm">
                <a href="/" class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">Dashboard</a>
                <a href="/devices.php" class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">Dispositivos</a>
                <a href="/events.php" class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">Eventos</a>
                <a href="/ingests.php" class="rounded-lg bg-slate-900 px-3 py-2 font-medium text-white">Ingests</a>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-6 py-8">
        <?php if ($loadError !== null): ?>
            <section class="rounded-2xl border border-red-200 bg-red-50 p-6 text-red-800 shadow-sm">
                <h2 class="text-lg font-semibold">Error cargando ingest</h2>
                <p class="mt-2 font-mono text-sm"><?= F::e($loadError) ?></p>
            </section>
        <?php elseif ($ingest === null): ?>
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-amber-900 shadow-sm">
                <h2 class="text-lg font-semibold">Ingest no encontrado</h2>
                <p class="mt-2 text-sm">No existe ningún ingest con ID <?= F::e($ingestId) ?>.</p>
                <p class="mt-4">
                    <a class="font-medium text-amber-900 underline" href="/ingests.php">Volver a ingests</a>
                </p>
            </section>
        <?php else: ?>
            <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">Navegación de investigación</h2>

                <div class="mt-5 flex flex-wrap gap-3">
                    <a class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="/events.php?ingest_id=<?= F::e($ingest['id']) ?>">
                        Eventos de este ingest
                    </a>

                    <?php if (($ingest['bridge_device_id'] ?? null) !== null): ?>
                        <a class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="/device.php?id=<?= F::e($ingest['bridge_device_id']) ?>">
                            Ver bridge
                        </a>
                    <?php endif; ?>

                    <a class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700" href="/ingests.php">
                        Volver a ingests
                    </a>
                </div>
            </section>

            <section class="grid gap-6 lg:grid-cols-3">
                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Resumen</h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-slate-500">Status</dt>
                            <dd class="mt-1">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= ingestStatusBadgeClass((string) $ingest['status']) ?>">
                                    <?= F::e($ingest['status']) ?>
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Recibido</dt>
                            <dd class="mt-1 text-slate-900"><?= F::datetime($ingest['received_at'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Source type</dt>
                            <dd class="mt-1 font-mono text-slate-900"><?= F::nullable($ingest['source_type'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Bridge reportado</dt>
                            <dd class="mt-1">
                                <div class="font-mono text-slate-900"><?= F::nullable($ingest['bridge_id_reported'] ?? null) ?></div>
                                <?php if (($ingest['bridge_device_id'] ?? null) !== null): ?>
                                    <a class="mt-1 block text-sky-700 hover:underline" href="/device.php?id=<?= F::e($ingest['bridge_device_id']) ?>">
                                        <?= F::nullable($ingest['bridge_name'] ?? null) ?>
                                    </a>
                                <?php endif; ?>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Remote addr</dt>
                            <dd class="mt-1 font-mono text-slate-900"><?= F::nullable($ingest['remote_addr'] ?? null) ?></dd>
                        </div>
                    </dl>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Procesamiento</h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-slate-500">Líneas</dt>
                            <dd class="mt-1 text-slate-900"><?= F::number((int) ($ingest['line_count'] ?? 0)) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Parse OK</dt>
                            <dd class="mt-1 text-slate-900"><?= F::number((int) ($ingest['parsed_ok_count'] ?? 0)) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Parse error</dt>
                            <dd class="mt-1 text-slate-900"><?= F::number((int) ($ingest['parsed_error_count'] ?? 0)) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Inicio procesamiento</dt>
                            <dd class="mt-1 text-slate-900"><?= F::datetime($ingest['processing_started_at'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Fin procesamiento</dt>
                            <dd class="mt-1 text-slate-900"><?= F::datetime($ingest['processing_finished_at'] ?? null) ?></dd>
                        </div>
                    </dl>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Ficheros / hash</h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="font-medium text-slate-500">Raw path</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-slate-700"><?= F::nullable($ingest['raw_path'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Content hash</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-slate-700"><?= F::nullable($ingest['content_hash'] ?? null) ?></dd>
                        </div>

                        <div>
                            <dt class="font-medium text-slate-500">Source device ID</dt>
                            <dd class="mt-1 font-mono text-slate-900"><?= F::nullable($ingest['source_device_id'] ?? null) ?></dd>
                        </div>
                    </dl>
                </article>
            </section>

            <section class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">User-Agent</h2>
                <pre class="mt-4 whitespace-pre-wrap break-words rounded-xl bg-slate-950 p-4 text-sm text-slate-100"><?= F::e($ingest['user_agent'] ?? '') ?></pre>
            </section>

            <section class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">Payload summary</h2>
                <pre class="mt-4 overflow-x-auto rounded-xl bg-slate-950 p-4 text-sm text-slate-100"><?= F::e(prettyJson($ingest['payload_summary'] ?? null)) ?></pre>
            </section>

            <section class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold">Eventos del ingest</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Mostrando hasta <?= F::number(count($events)) ?> eventos asociados.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">ID</th>
                                <th class="px-4 py-3">Fecha evento</th>
                                <th class="px-4 py-3">Severidad</th>
                                <th class="px-4 py-3">Tipo</th>
                                <th class="px-4 py-3">Dispositivo</th>
                                <th class="px-4 py-3">Mensaje</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-200 bg-white">
                            <?php foreach ($events as $event): ?>
                                <?php $parseOk = ((int) $event['parse_ok']) === 1; ?>

                                <tr class="align-top hover:bg-slate-50">
                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">
                                        <a class="font-medium text-sky-700 hover:underline" href="/event.php?id=<?= F::e($event['id']) ?>">
                                            #<?= F::e($event['id']) ?>
                                        </a>
                                    </td>

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
                                            <div class="mt-1 font-mono text-xs text-slate-500">
                                                <?= F::nullable($event['device_mac_raw'] ?? $event['device_mac_address'] ?? null) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-slate-400">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="min-w-[420px] px-4 py-3 text-slate-700">
                                        <a class="text-sky-700 hover:underline" href="/event.php?id=<?= F::e($event['id']) ?>">
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
                                    <td colspan="6" class="px-4 py-8 text-center text-slate-500">
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

function ingestStatusBadgeClass(string $status): string
{
    return match ($status) {
        'processed' => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
        'error' => 'bg-red-100 text-red-800 ring-red-200',
        'parsing' => 'bg-amber-100 text-amber-800 ring-amber-200',
        'received' => 'bg-sky-100 text-sky-800 ring-sky-200',
        default => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
}

function applyIngestRateLimit(): void
{
    if (!ingestRateLimitEnabled()) {
        return;
    }

    $storageDir = __DIR__ . '/../../../../private/storage/rate-limit';

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
    $raw = getenv('INGEST_RATE_LIMIT_ENABLED');

    if ($raw === false || trim((string) $raw) === '') {
        return true;
    }

    return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
}

function ingestRateLimitEnvInt(string $key, int $default): int
{
    $raw = getenv($key);

    if ($raw === false || trim((string) $raw) === '') {
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
