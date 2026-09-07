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

/**
 * Días sin un solo evento a partir de los cuales una opción de filtro se
 * considera dormida: existe en el histórico pero ya no reporta.
 */
const FILTER_STALE_DAYS = 7;


$severity = readChoice('severity', ['', 'critical', 'error', 'warn', 'info', 'unknown']);
$eventCategory = readSafeFilterValue('event_category', 50);
$eventType = readSafeFilterValue('event_type', 80);
$parseOk = readParseOk();
$from = readDateTimeLocal('from', false);
$to = readDateTimeLocal('to', true);
$deviceId = readOptionalPositiveInt('device_id');
$bridgeId = readOptionalPositiveInt('bridge_id');
$ingestId = readOptionalPositiveInt('ingest_id');
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
    'severity' => $severity !== '' ? $severity : null,
    'event_category' => $eventCategory !== '' ? $eventCategory : null,
    'event_type' => $eventType !== '' ? $eventType : null,
    'parse_ok' => $parseOk,
    'from' => $from,
    'to' => $to,
    'device_id' => $deviceId,
    'bridge_id' => $bridgeId,
    'ingest_id' => $ingestId,
    'q' => $q !== '' ? $q : null,
    'limit' => $limit,
    'offset' => $offset,
];

$loadError = null;
$devices = [];
$eventCategories = [];
$eventTypes = [];
$events = [];
$totalEvents = 0;
$totalPages = 1;

try {
    $pdo = Connection::make();
    $queries = new ViewerQueries($pdo);

    $devices = $queries->devicesForFilter();
    $eventCategories = $queries->eventCategoryOptions();
    $eventTypes = $queries->eventTypeOptions();

    $totalEvents = $queries->eventsSearchCount($filters);
    $totalPages = max(1, (int) ceil($totalEvents / $limit));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
        $filters['offset'] = $offset;
    }

    $events = $queries->eventsSearch($filters);
} catch (Throwable $exception) {
    http_response_code(500);
    $loadError = $exception->getMessage();
}

?>
<!doctype html>
<html lang="es">

<head>
    <?php $pageTitle = 'Eventos';
    require __DIR__ . '/_viewer_head.php'; ?>
</head>

<body class="min-h-screen bg-ink-100 text-ink-900">
    <?php $activePage = 'events';
    require __DIR__ . '/_viewer_header.php'; ?>

    <main class="mx-auto max-w-7xl px-6 py-8">
        <?php if ($loadError !== null): ?>
            <section class="rounded-2xl border border-red-200 bg-red-50 p-6 text-red-800 shadow-sm">
                <h2 class="text-lg font-semibold">Error cargando eventos</h2>
                <p class="mt-2 font-mono text-sm"><?= F::e($loadError) ?></p>
            </section>
        <?php else: ?>
            <section class="rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                <form method="GET" action="/events.php" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <input type="hidden" name="page" value="1">
                    <div>
                        <label for="severity" class="block text-sm font-medium text-ink-700">Severidad</label>
                        <select id="severity" name="severity" class="mt-1 w-full rounded-lg border border-ink-300 bg-white px-3 py-2 text-sm">
                            <option value="" <?= selectedValue($severity, '') ?>>Todas</option>
                            <option value="critical" <?= selectedValue($severity, 'critical') ?>>Crítico</option>
                            <option value="error" <?= selectedValue($severity, 'error') ?>>Error</option>
                            <option value="warn" <?= selectedValue($severity, 'warn') ?>>Aviso</option>
                            <option value="info" <?= selectedValue($severity, 'info') ?>>Info</option>
                            <option value="unknown" <?= selectedValue($severity, 'unknown') ?>>Sin definir</option>
                        </select>
                    </div>

                    <div>
                        <label for="event_category" class="block text-sm font-medium text-ink-700">Categoría</label>
                        <select id="event_category" name="event_category" class="mt-1 w-full rounded-lg border border-ink-300 bg-white px-3 py-2 text-sm">
                            <option value="" <?= selectedValue($eventCategory, '') ?>>Todas</option>

                            <?= renderFilterOptions($eventCategories, $eventCategory) ?>

                            <?php if ($eventCategory !== '' && !optionValueExists($eventCategories, $eventCategory)): ?>
                                <option value="<?= F::e($eventCategory) ?>" selected>
                                    <?= F::e($eventCategory) ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div>
                        <label for="event_type" class="block text-sm font-medium text-ink-700">Tipo de evento</label>
                        <select id="event_type" name="event_type" class="mt-1 w-full rounded-lg border border-ink-300 bg-white px-3 py-2 text-sm">
                            <option value="" <?= selectedValue($eventType, '') ?>>Todos</option>

                            <?= renderFilterOptions($eventTypes, $eventType) ?>

                            <?php if ($eventType !== '' && !optionValueExists($eventTypes, $eventType)): ?>
                                <option value="<?= F::e($eventType) ?>" selected>
                                    <?= F::e($eventType) ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div>
                        <label for="parse_ok" class="block text-sm font-medium text-ink-700">Parseo</label>
                        <select id="parse_ok" name="parse_ok" class="mt-1 w-full rounded-lg border border-ink-300 bg-white px-3 py-2 text-sm">
                            <option value="" <?= selectedValue($parseOk === null ? '' : (string) $parseOk, '') ?>>Todos</option>
                            <option value="1" <?= selectedValue($parseOk === null ? '' : (string) $parseOk, '1') ?>>OK</option>
                            <option value="0" <?= selectedValue($parseOk === null ? '' : (string) $parseOk, '0') ?>>Error</option>
                        </select>
                    </div>

                    <div>
                        <label for="limit" class="block text-sm font-medium text-ink-700">Límite</label>
                        <select id="limit" name="limit" class="mt-1 w-full rounded-lg border border-ink-300 bg-white px-3 py-2 text-sm">
                            <option value="50" <?= selectedValue($limit, 50) ?>>50</option>
                            <option value="100" <?= selectedValue($limit, 100) ?>>100</option>
                            <option value="200" <?= selectedValue($limit, 200) ?>>200</option>
                        </select>
                    </div>

                    <div>
                        <label for="from" class="block text-sm font-medium text-ink-700">Desde</label>
                        <input
                            id="from"
                            name="from"
                            type="datetime-local"
                            value="<?= F::e(toDateTimeLocalValue($from)) ?>"
                            class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label for="to" class="block text-sm font-medium text-ink-700">Hasta</label>
                        <input
                            id="to"
                            name="to"
                            type="datetime-local"
                            value="<?= F::e(toDateTimeLocalValue($to)) ?>"
                            class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label for="bridge_id" class="block text-sm font-medium text-ink-700">Bridge</label>
                        <select id="bridge_id" name="bridge_id" class="mt-1 w-full rounded-lg border border-ink-300 bg-white px-3 py-2 text-sm">
                            <option value="" <?= selectedValue($bridgeId ?? '', '') ?>>Todos</option>
                            <?php foreach ($devices as $device): ?>
                                <?php if ((string) $device['device_kind'] !== 'bridge') {
                                    continue;
                                } ?>
                                <option value="<?= F::e($device['id']) ?>" <?= selectedValue($bridgeId ?? '', $device['id']) ?>>
                                    <?= F::e(($device['external_id'] ?? '—') . ' · ' . ($device['name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="device_id" class="block text-sm font-medium text-ink-700">Dispositivo origen</label>
                        <select id="device_id" name="device_id" class="mt-1 w-full rounded-lg border border-ink-300 bg-white px-3 py-2 text-sm">
                            <option value="" <?= selectedValue($deviceId ?? '', '') ?>>Todos</option>
                            <?php foreach ($devices as $device): ?>
                                <option value="<?= F::e($device['id']) ?>" <?= selectedValue($deviceId ?? '', $device['id']) ?>>
                                    <?= F::e(F::deviceKindLabel((string) $device['device_kind']) . ' ' . ($device['external_id'] ?? '—') . ' · ' . ($device['name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="ingest_id" class="block text-sm font-medium text-ink-700">ID de ingesta</label>
                        <input
                            id="ingest_id"
                            name="ingest_id"
                            type="number"
                            min="1"
                            value="<?= F::e($ingestId ?? '') ?>"
                            placeholder="Ej: 406"
                            class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm">
                    </div>

                    <div class="md:col-span-2">
                        <label for="q" class="block text-sm font-medium text-ink-700">Buscar texto</label>
                        <input
                            id="q"
                            name="q"
                            type="search"
                            value="<?= F::e($q) ?>"
                            placeholder="Ej: UNSYNCED, LiDAR, SOC=0, telemetry_header..."
                            class="mt-1 w-full rounded-lg border border-ink-300 px-3 py-2 text-sm">
                    </div>

                    <div class="flex items-end gap-3 md:col-span-2 xl:col-span-4">
                        <button type="submit" class="rounded-lg bg-ink-900 px-4 py-2 text-sm font-semibold text-white hover:bg-ink-700">
                            Aplicar filtros
                        </button>

                        <a href="/events.php" class="rounded-lg border border-ink-300 px-4 py-2 text-sm font-semibold text-ink-700 hover:bg-ink-50">
                            Limpiar
                        </a>
                    </div>
                </form>
            </section>

            <section class="mt-8 rounded-2xl border border-ink-200 bg-white shadow-sm">
                <div class="border-b border-ink-200 px-6 py-5">
                    <h2 class="text-lg font-semibold">Resultados</h2>
                    <p class="mt-1 text-sm text-ink-500">
                        Mostrando <?= F::number(count($events)) ?> de <?= F::number($totalEvents) ?> eventos.
                        Página <?= F::number($page) ?> de <?= F::number($totalPages) ?>.
                        Límite actual: <?= F::number($limit) ?>.
                    </p>
                    <?php if ($totalPages > 1): ?>
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <?php if ($page > 1): ?>
                                <a
                                    class="rounded-lg border border-ink-300 px-4 py-2 text-sm font-semibold text-ink-700 hover:bg-ink-50"
                                    href="<?= F::e(eventsPageUrl($page - 1)) ?>">
                                    ← Anterior
                                </a>
                            <?php else: ?>
                                <span class="rounded-lg border border-ink-200 px-4 py-2 text-sm font-semibold text-ink-300">
                                    ← Anterior
                                </span>
                            <?php endif; ?>

                            <?php if ($page < $totalPages): ?>
                                <a
                                    class="rounded-lg border border-ink-300 px-4 py-2 text-sm font-semibold text-ink-700 hover:bg-ink-50"
                                    href="<?= F::e(eventsPageUrl($page + 1)) ?>">
                                    Siguiente →
                                </a>
                            <?php else: ?>
                                <span class="rounded-lg border border-ink-200 px-4 py-2 text-sm font-semibold text-ink-300">
                                    Siguiente →
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
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
                            <?php foreach ($events as $event): ?>
                                <?php $parseOk = ((int) $event['parse_ok']) === 1; ?>

                                <tr class="align-top hover:bg-ink-50">
                                    <td class="whitespace-nowrap px-4 py-3 text-ink-600">
                                        <?= F::datetime($event['event_timestamp'] ?? null) ?>
                                        <div class="mt-1 text-xs text-ink-400">
                                            ingesta #<?= F::e($event['ingest_id']) ?>
                                        </div>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= F::severityBadgeClass((string) $event['severity']) ?>">
                                            <?= F::e(F::severityLabel((string) $event['severity'])) ?>
                                        </span>
                                        <div class="mt-1 text-xs text-ink-400">
                                            <?= F::e(F::severityOriginLabel($event['severity_origin'] ?? null)) ?>
                                        </div>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3">
                                        <div class="font-medium"><?= F::e($event['event_type']) ?></div>

                                        <div class="mt-1 text-xs text-ink-500">
                                            <?= F::e($event['event_category'] ?? 'unknown') ?>
                                        </div>

                                        <span class="mt-2 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ring-1 <?= F::parseBadgeClass($parseOk) ?>">
                                            <?= $parseOk ? 'OK' : 'Error' ?>
                                        </span>

                                        <?php $quality = (string) ($event['quality_status'] ?? 'valid'); ?>
                                        <?php if ($quality !== 'valid'): ?>
                                            <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ring-1 <?= F::qualityBadgeClass($quality) ?>"
                                                title="<?= F::e(implode(' · ', array_map([F::class, 'anomalyLabel'], F::anomalyFlags($event['anomaly_flags'] ?? null)))) ?>">
                                                <?= F::e(F::qualityLabel($quality)) ?>
                                            </span>
                                        <?php endif; ?>

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

                                    <td class="px-4 py-3">
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
                                    </td>

                                    <td class="min-w-[460px] px-4 py-3 text-ink-700">
                                        <a class="text-brand-700 hover:underline" href="/event.php?id=<?= F::e($event['id']) ?>">
                                            Ver detalle
                                        </a>
                                        <?php $message = F::messageWithoutMetrics($event['message_text'] ?? ''); ?>
                                        <?php if ($message !== ''): ?>
                                            <div class="mt-2"><?= F::shortText($message, 320) ?></div>
                                        <?php endif; ?>
                                        <?php $eventSummary = F::eventSummary($event); ?>
                                        <?php if ($eventSummary !== null): ?>
                                            <div class="mt-2 font-mono text-xs text-ink-500">
                                                <?= F::e($eventSummary) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ($events === []): ?>
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-ink-500">
                                        No hay eventos que coincidan con los filtros.
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

function readSafeFilterValue(string $key, int $maxLength): string
{
    $value = trim((string) ($_GET[$key] ?? ''));

    if ($value === '') {
        return '';
    }

    $length = function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);

    if ($length > $maxLength) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo "{$key} is too long.";
        exit;
    }

    if (preg_match('/^[A-Za-z0-9_.:-]+$/', $value) !== 1) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo "{$key} has an invalid value.";
        exit;
    }

    return $value;
}

function readParseOk(): ?int
{
    $value = trim((string) ($_GET['parse_ok'] ?? ''));

    if ($value === '') {
        return null;
    }

    if ($value === '1') {
        return 1;
    }

    if ($value === '0') {
        return 0;
    }

    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'parse_ok must be 1, 0 or empty.';
    exit;
}

function readOptionalPositiveInt(string $key): ?int
{
    $raw = trim((string) ($_GET[$key] ?? ''));

    if ($raw === '') {
        return null;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT, [
        'options' => [
            'min_range' => 1,
        ],
    ]);

    if ($value === false) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo "{$key} must be a positive integer.";
        exit;
    }

    return (int) $value;
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
        $date = $date->setTime(
            (int) $date->format('H'),
            (int) $date->format('i'),
            59
        );
    } else {
        $date = $date->setTime(
            (int) $date->format('H'),
            (int) $date->format('i'),
            0
        );
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

function eventsPageUrl(int $page): string
{
    $params = $_GET;
    $params['page'] = max(1, $page);

    return '/events.php?' . http_build_query($params);
}

function selectedValue(mixed $current, mixed $expected): string
{
    return (string) $current === (string) $expected ? ' selected' : '';
}

/**
 * Pinta las opciones de un filtro separando las que siguen reportando de las
 * que llevan FILTER_STALE_DAYS sin un evento.
 *
 * Antes todas las opciones se listaban juntas y con su total histórico, así
 * que un tipo que dejó de existir hace meses parecía tan vigente como uno que
 * llegó hace un minuto.
 */
function renderFilterOptions(array $options, string $current): string
{
    $cutoff = time() - (FILTER_STALE_DAYS * 86400);
    $live = [];
    $dormant = [];

    foreach ($options as $option) {
        $lastEventAt = strtotime((string) ($option['last_event_at'] ?? ''));

        if ($lastEventAt !== false && $lastEventAt >= $cutoff) {
            $live[] = $option;
        } else {
            $dormant[] = $option;
        }
    }

    $html = renderFilterOptionGroup('Con datos recientes', $live, $current);
    $html .= renderFilterOptionGroup(
        'Sin datos desde hace más de ' . FILTER_STALE_DAYS . ' días',
        $dormant,
        $current
    );

    return $html;
}

function renderFilterOptionGroup(string $label, array $options, string $current): string
{
    if ($options === []) {
        return '';
    }

    $html = '<optgroup label="' . F::e($label) . '">';

    foreach ($options as $option) {
        $value = (string) ($option['value'] ?? 'unknown');

        $html .= '<option value="' . F::e($value) . '"'
            . selectedValue($current, $value) . '>'
            . F::e($value)
            . ' (' . F::number((int) ($option['total'] ?? 0)) . ')'
            . '</option>';
    }

    return $html . '</optgroup>';
}

function optionValueExists(array $options, string $value): bool
{
    foreach ($options as $option) {
        if ((string) ($option['value'] ?? '') === $value) {
            return true;
        }
    }

    return false;
}
