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


$status = readChoice('status', ['', 'received', 'parsing', 'processed', 'error']);
$bridgeIdReported = readBridgeIdReported();
$sourceType = readSourceType();
$from = readDateTimeLocal('from', false);
$to = readDateTimeLocal('to', true);
$q = readSearchText();
$limit = readLimit();
$page = readPage();
$offset = ($page - 1) * $limit;

if ($from !== null && $to !== null && strtotime($from) > strtotime($to)) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'from must be earlier than or equal to to.';
    exit;
}

$filters = [
    'status' => $status !== '' ? $status : null,
    'bridge_id_reported' => $bridgeIdReported !== '' ? $bridgeIdReported : null,
    'source_type' => $sourceType !== '' ? $sourceType : null,
    'from' => $from,
    'to' => $to,
    'q' => $q !== '' ? $q : null,
    'limit' => $limit,
    'offset' => $offset,
];

$loadError = null;
$ingests = [];
$bridgeOptions = [];
$sourceTypeOptions = [];
$totalIngests = 0;
$totalPages = 1;

try {
    $pdo = Connection::make();
    $queries = new ViewerQueries($pdo);

    $bridgeOptions = $queries->ingestBridgeOptions();
    $sourceTypeOptions = $queries->ingestSourceTypeOptions();

    $totalIngests = $queries->ingestsSearchCount($filters);
    $totalPages = max(1, (int) ceil($totalIngests / $limit));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
        $filters['offset'] = $offset;
    }

    $ingests = $queries->ingestsSearch($filters);
} catch (Throwable $exception) {
    http_response_code(500);
    $loadError = $exception->getMessage();
}

?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>logs-devices · Ingests</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-slate-100 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-5">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Ingests</h1>
                <p class="mt-1 text-sm text-slate-500">
                    POSTs recibidos desde bridges y otros orígenes.
                </p>
            </div>

            <nav class="flex items-center gap-3 text-sm">
                <a href="/" class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">Dashboard</a>
                <a href="/devices.php" class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">Dispositivos</a>
                <a href="/events.php" class="rounded-lg px-3 py-2 font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">Eventos</a>
                <a href="/ingests.php" class="rounded-lg bg-slate-900 px-3 py-2 font-medium text-white">Ingests</a>
            </nav>
            <a href="/logout.php"
                class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-900"
                title="Cerrar sesión">
                Salir
            </a>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-6 py-8">
        <?php if ($loadError !== null): ?>
            <section class="rounded-2xl border border-red-200 bg-red-50 p-6 text-red-800 shadow-sm">
                <h2 class="text-lg font-semibold">Error cargando ingests</h2>
                <p class="mt-2 font-mono text-sm"><?= F::e($loadError) ?></p>
            </section>
        <?php else: ?>
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <form method="GET" action="/ingests.php" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <input type="hidden" name="page" value="1">

                    <div>
                        <label for="status" class="block text-sm font-medium text-slate-700">Status</label>
                        <select id="status" name="status" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                            <option value="" <?= selectedValue($status, '') ?>>Todos</option>
                            <option value="received" <?= selectedValue($status, 'received') ?>>received</option>
                            <option value="parsing" <?= selectedValue($status, 'parsing') ?>>parsing</option>
                            <option value="processed" <?= selectedValue($status, 'processed') ?>>processed</option>
                            <option value="error" <?= selectedValue($status, 'error') ?>>error</option>
                        </select>
                    </div>

                    <div>
                        <label for="bridge_id_reported" class="block text-sm font-medium text-slate-700">Bridge reportado</label>
                        <select id="bridge_id_reported" name="bridge_id_reported" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                            <option value="" <?= selectedValue($bridgeIdReported, '') ?>>Todos</option>
                            <?php foreach ($bridgeOptions as $bridge): ?>
                                <option value="<?= F::e($bridge['bridge_id_reported']) ?>" <?= selectedValue($bridgeIdReported, $bridge['bridge_id_reported']) ?>>
                                    <?= F::e($bridge['bridge_id_reported']) ?>
                                    <?php if (!empty($bridge['bridge_name'])): ?>
                                        · <?= F::e($bridge['bridge_name']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="source_type" class="block text-sm font-medium text-slate-700">Source type</label>
                        <select id="source_type" name="source_type" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                            <option value="" <?= selectedValue($sourceType, '') ?>>Todos</option>
                            <?php foreach ($sourceTypeOptions as $option): ?>
                                <option value="<?= F::e($option['source_type']) ?>" <?= selectedValue($sourceType, $option['source_type']) ?>>
                                    <?= F::e($option['source_type']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="limit" class="block text-sm font-medium text-slate-700">Límite</label>
                        <select id="limit" name="limit" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                            <option value="50" <?= selectedValue($limit, 50) ?>>50</option>
                            <option value="100" <?= selectedValue($limit, 100) ?>>100</option>
                            <option value="200" <?= selectedValue($limit, 200) ?>>200</option>
                        </select>
                    </div>

                    <div>
                        <label for="from" class="block text-sm font-medium text-slate-700">Desde</label>
                        <input
                            id="from"
                            name="from"
                            type="datetime-local"
                            value="<?= F::e(toDateTimeLocalValue($from)) ?>"
                            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label for="to" class="block text-sm font-medium text-slate-700">Hasta</label>
                        <input
                            id="to"
                            name="to"
                            type="datetime-local"
                            value="<?= F::e(toDateTimeLocalValue($to)) ?>"
                            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div class="md:col-span-2">
                        <label for="q" class="block text-sm font-medium text-slate-700">Buscar texto</label>
                        <input
                            id="q"
                            name="q"
                            type="search"
                            value="<?= F::e($q) ?>"
                            placeholder="raw_path, hash, remote_addr, payload_summary..."
                            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>

                    <div class="flex items-end gap-3 md:col-span-2 xl:col-span-4">
                        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                            Aplicar filtros
                        </button>

                        <a href="/ingests.php" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Limpiar
                        </a>
                    </div>
                </form>
            </section>

            <section class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold">Resultados</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Mostrando <?= F::number(count($ingests)) ?> de <?= F::number($totalIngests) ?> ingests.
                        Página <?= F::number($page) ?> de <?= F::number($totalPages) ?>.
                        Límite actual: <?= F::number($limit) ?>.
                    </p>

                    <?php if ($totalPages > 1): ?>
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <?php if ($page > 1): ?>
                                <a class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="<?= F::e(ingestsPageUrl($page - 1)) ?>">
                                    ← Anterior
                                </a>
                            <?php else: ?>
                                <span class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-300">
                                    ← Anterior
                                </span>
                            <?php endif; ?>

                            <?php if ($page < $totalPages): ?>
                                <a class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="<?= F::e(ingestsPageUrl($page + 1)) ?>">
                                    Siguiente →
                                </a>
                            <?php else: ?>
                                <span class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-300">
                                    Siguiente →
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Recibido</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Bridge</th>
                                <th class="px-4 py-3">Source</th>
                                <th class="px-4 py-3 text-right">Líneas</th>
                                <th class="px-4 py-3 text-right">OK</th>
                                <th class="px-4 py-3 text-right">Error</th>
                                <th class="px-4 py-3">Raw path</th>
                                <th class="px-4 py-3">Acciones</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-200 bg-white">
                            <?php foreach ($ingests as $ingest): ?>
                                <tr class="align-top hover:bg-slate-50">
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                        <?= F::datetime($ingest['received_at'] ?? null) ?>
                                        <div class="mt-1 font-mono text-xs text-slate-400">
                                            #<?= F::e($ingest['id']) ?>
                                        </div>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= ingestStatusBadgeClass((string) $ingest['status']) ?>">
                                            <?= F::e($ingest['status']) ?>
                                        </span>
                                    </td>

                                    <td class="px-4 py-3">
                                        <div class="font-medium"><?= F::nullable($ingest['bridge_id_reported'] ?? null) ?></div>
                                        <?php if (($ingest['bridge_device_id'] ?? null) !== null): ?>
                                            <a class="mt-1 block text-xs text-sky-700 hover:underline" href="/device.php?id=<?= F::e($ingest['bridge_device_id']) ?>">
                                                <?= F::nullable($ingest['bridge_name'] ?? null) ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-4 py-3">
                                        <div class="font-mono text-xs"><?= F::nullable($ingest['source_type'] ?? null) ?></div>
                                        <div class="mt-1 font-mono text-xs text-slate-500"><?= F::nullable($ingest['remote_addr'] ?? null) ?></div>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-right font-semibold">
                                        <?= F::number((int) ($ingest['line_count'] ?? 0)) ?>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <?= F::number((int) ($ingest['parsed_ok_count'] ?? 0)) ?>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <?php $errors = (int) ($ingest['parsed_error_count'] ?? 0); ?>
                                        <?php if ($errors > 0): ?>
                                            <span class="font-semibold text-red-700"><?= F::number($errors) ?></span>
                                        <?php else: ?>
                                            <span class="text-slate-400">0</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="min-w-[260px] px-4 py-3">
                                        <div class="break-all font-mono text-xs text-slate-700">
                                            <?= F::nullable($ingest['raw_path'] ?? null) ?>
                                        </div>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3">
                                        <a class="font-medium text-sky-700 hover:underline" href="/ingest.php?id=<?= F::e($ingest['id']) ?>">
                                            Ver ingest
                                        </a>
                                        <div class="mt-1">
                                            <a class="text-xs text-sky-700 hover:underline" href="/events.php?ingest_id=<?= F::e($ingest['id']) ?>">
                                                Eventos
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ($ingests === []): ?>
                                <tr>
                                    <td colspan="9" class="px-4 py-8 text-center text-slate-500">
                                        No hay ingests que coincidan con los filtros.
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

function readChoice(string $key, array $allowed): string
{
    $value = trim((string) ($_GET[$key] ?? ''));

    if (!in_array($value, $allowed, true)) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo "{$key} has an invalid value.";
        exit;
    }

    return $value;
}

function readBridgeIdReported(): string
{
    $value = trim((string) ($_GET['bridge_id_reported'] ?? ''));

    if ($value === '') {
        return '';
    }

    if (strlen($value) > 100 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $value)) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'bridge_id_reported has an invalid value.';
        exit;
    }

    return $value;
}

function readSourceType(): string
{
    $value = trim((string) ($_GET['source_type'] ?? ''));

    if ($value === '') {
        return '';
    }

    if (strlen($value) > 100 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $value)) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'source_type has an invalid value.';
        exit;
    }

    return $value;
}

function readSearchText(): string
{
    $value = trim((string) ($_GET['q'] ?? ''));

    $length = function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);

    if ($length > 200) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'q must be 200 characters or less.';
        exit;
    }

    return $value;
}

function readLimit(): int
{
    $raw = trim((string) ($_GET['limit'] ?? ''));

    if ($raw === '') {
        return 50;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT, [
        'options' => [
            'min_range' => 1,
            'max_range' => 200,
        ],
    ]);

    if ($value === false) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'limit must be an integer between 1 and 200.';
        exit;
    }

    return (int) $value;
}

function readPage(): int
{
    $raw = trim((string) ($_GET['page'] ?? ''));

    if ($raw === '') {
        return 1;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT, [
        'options' => [
            'min_range' => 1,
        ],
    ]);

    if ($value === false) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'page must be a positive integer.';
        exit;
    }

    return (int) $value;
}

function readDateTimeLocal(string $key, bool $endOfMinute): ?string
{
    $raw = trim((string) ($_GET[$key] ?? ''));

    if ($raw === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        $date === false
        || (
            is_array($errors)
            && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)
        )
    ) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo "{$key} must have format YYYY-MM-DDTHH:MM.";
        exit;
    }

    if ($endOfMinute) {
        $date = $date->setTime((int) $date->format('H'), (int) $date->format('i'), 59);
    } else {
        $date = $date->setTime((int) $date->format('H'), (int) $date->format('i'), 0);
    }

    return $date->format('Y-m-d H:i:s');
}

function toDateTimeLocalValue(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }

    return substr(str_replace(' ', 'T', $value), 0, 16);
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

function ingestsPageUrl(int $page): string
{
    $params = $_GET;
    $params['page'] = max(1, $page);

    return '/ingests.php?' . http_build_query($params);
}

function selectedValue(mixed $current, mixed $expected): string
{
    return (string) $current === (string) $expected ? ' selected' : '';
}
