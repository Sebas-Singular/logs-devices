<?php

declare(strict_types=1);

namespace App\Ingest;

use DateTimeImmutable;
use Throwable;

final class StructuredEventNormalizer
{
    public function normalizeEvents(
        array $events,
        int $ingestId,
        ?int $bridgeDeviceId,
        DateTimeImmutable $receivedAt,
        array $payloadContext = [],
        ?callable $resolveDeviceId = null
    ): array {
        $rows = [];
        $parsedOk = 0;
        $parsedError = 0;

        foreach ($events as $index => $event) {
            $lineNumber = $index + 1;

            if (!is_array($event)) {
                $rows[] = $this->invalidRow(
                    $ingestId,
                    $bridgeDeviceId,
                    $receivedAt,
                    $lineNumber,
                    'structured_event_not_object',
                    $event
                );

                $parsedError++;
                continue;
            }

            $row = $this->normalizeOne(
                $event,
                $ingestId,
                $bridgeDeviceId,
                $receivedAt,
                $lineNumber,
                $payloadContext,
                $resolveDeviceId
            );

            $rows[] = $row;

            if ((int) $row['parse_ok'] === 1) {
                $parsedOk++;
            } else {
                $parsedError++;
            }
        }

        return [
            'events' => $rows,
            'parsed_ok' => $parsedOk,
            'parsed_error' => $parsedError,
        ];
    }

    private function normalizeOne(
        array $event,
        int $ingestId,
        ?int $bridgeDeviceId,
        DateTimeImmutable $receivedAt,
        int $lineNumber,
        array $payloadContext,
        ?callable $resolveDeviceId
    ): array {
        $category = $this->safeIdentifier($event['category'] ?? null, 'unknown');
        $type = $this->safeIdentifier($event['type'] ?? null, 'unknown');
        $severity = $this->safeSeverity($event['level'] ?? null);
        $deviceMac = $this->normalizeMac($event['deviceMac'] ?? null);

        $timestampInfo = $this->resolveEventTimestamp(
            $event['eventTs'] ?? null,
            $event['ts'] ?? null,
            $receivedAt
        );

        $measurements = $this->arrayOrEmpty($event['metrics'] ?? null);

        if (isset($event['gps']) && is_array($event['gps'])) {
            $measurements['gps'] = $event['gps'];
        }

        if (isset($event['imu']) && is_array($event['imu'])) {
            $measurements['imu'] = $event['imu'];
        }

        $context = [
            'source' => $this->arrayOrEmpty($payloadContext['source'] ?? null),
            'upload' => $this->arrayOrEmpty($payloadContext['upload'] ?? null),
            'tags' => $this->arrayOrEmpty($event['tags'] ?? null),
            'config' => $this->arrayOrEmpty($event['config'] ?? null),
            'code' => $this->stringOrNull($event['code'] ?? null),
            'eventTsRaw' => $this->stringOrNull($event['eventTs'] ?? null),
            'tsRaw' => $this->stringOrNull($event['ts'] ?? null),
            'timestampSource' => $timestampInfo['source'],
            'timeFallback' => $timestampInfo['fallback'],
            'nodeId' => $event['nodeId'] ?? null,
            'nodeName' => $this->stringOrNull($event['nodeName'] ?? null),
            'nodeSerialNumber' => $this->stringOrNull($event['nodeSerialNumber'] ?? null),
            'label' => $this->stringOrNull($event['label'] ?? null),
            'extra' => $this->extraFields($event, [
                'ts',
                'level',
                'code',
                'category',
                'type',
                'message',
                'raw',
                'eventTs',
                'deviceMac',
                'nodeSerialNumber',
                'nodeId',
                'nodeName',
                'metrics',
                'tags',
                'gps',
                'imu',
                'config',
                'label',
            ]),
        ];

        $anomalyFlags = [];
        $qualityStatus = 'valid';

        if ($timestampInfo['fallback']) {
            $qualityStatus = 'suspect';
            $anomalyFlags[] = 'time_fallback';
        }

        $gps = $measurements['gps'] ?? null;

        if (is_array($gps)) {
            $gpsError = $gps['error'] ?? null;

            if ($gpsError === 1 || $gpsError === '1') {
                $anomalyFlags[] = 'gps_no_signal';
            } elseif ($gpsError === 2 || $gpsError === '2') {
                $anomalyFlags[] = 'gps_signal_lost';
            }
        }

        $deviceId = null;

        if ($deviceMac !== null && $resolveDeviceId !== null) {
            $resolved = $resolveDeviceId(
                $deviceMac,
                $this->stringOrNull($event['nodeId'] ?? null),
                $this->stringOrNull($event['nodeName'] ?? null),
                $timestampInfo['timestamp']
            );

            $deviceId = is_int($resolved) ? $resolved : null;
        }

        $messageText = $this->composeMessageText(
            event: $event,
            category: $category,
            type: $type,
            measurements: $measurements,
            context: $context
        );

        return [
            'ingest_id' => $ingestId,
            'device_id' => $deviceId,
            'bridge_device_id' => $bridgeDeviceId,
            'event_timestamp' => $timestampInfo['timestamp']->format('Y-m-d H:i:s'),
            'received_at' => $receivedAt->format('Y-m-d H:i:s'),
            'severity' => $severity,
            'severity_origin' => 'reported',
            'event_type' => $type,
            'event_category' => $category,
            'device_mac_raw' => $deviceMac,
            'message_text' => $messageText,
            'measurements' => $this->jsonEncode($measurements),
            'context' => $this->jsonEncode($context),
            'parse_ok' => 1,
            'parse_error' => null,
            'quality_status' => $qualityStatus,
            'anomaly_flags' => $this->jsonEncode(array_values(array_unique($anomalyFlags))),
            'event_hash' => $this->computeEventHash($event, $ingestId, $lineNumber),
            'created_at' => $receivedAt->format('Y-m-d H:i:s'),
        ];
    }

    private function invalidRow(
        int $ingestId,
        ?int $bridgeDeviceId,
        DateTimeImmutable $receivedAt,
        int $lineNumber,
        string $parseError,
        mixed $rawEvent
    ): array {
        return [
            'ingest_id' => $ingestId,
            'device_id' => null,
            'bridge_device_id' => $bridgeDeviceId,
            'event_timestamp' => $receivedAt->format('Y-m-d H:i:s'),
            'received_at' => $receivedAt->format('Y-m-d H:i:s'),
            'severity' => 'error',
            'severity_origin' => 'derived',
            'event_type' => 'unknown',
            'event_category' => 'unknown',
            'device_mac_raw' => null,
            'message_text' => 'Invalid structured event at index ' . ($lineNumber - 1),
            'measurements' => $this->jsonEncode([]),
            'context' => $this->jsonEncode([
                'rawEvent' => $rawEvent,
                'lineNumber' => $lineNumber,
            ]),
            'parse_ok' => 0,
            'parse_error' => $parseError,
            'quality_status' => 'invalid',
            'anomaly_flags' => $this->jsonEncode(['invalid_structured_event']),
            'event_hash' => null,
            'created_at' => $receivedAt->format('Y-m-d H:i:s'),
        ];
    }

    private function composeMessageText(
        array $event,
        string $category,
        string $type,
        array $measurements,
        array $context
    ): string {
        $reportedMessage = $this->firstNonEmptyString($event['message'] ?? null);
        $rawMessage = $this->firstNonEmptyString($event['raw'] ?? null);

        $summary = $this->buildStructuredSummary($category, $type, $measurements, $context);

        if ($reportedMessage !== '' && !$this->isGenericStructuredMessage($reportedMessage, $type)) {
            return $summary !== null
                ? $reportedMessage . ' · ' . $summary
                : $reportedMessage;
        }

        if ($summary !== null) {
            return $reportedMessage !== ''
                ? $reportedMessage . ' · ' . $summary
                : $summary;
        }

        return $this->firstNonEmptyString(
            $reportedMessage,
            $rawMessage,
            $type
        );
    }

    private function isGenericStructuredMessage(string $message, string $type): bool
    {
        $normalizedMessage = strtolower(trim($message));
        $normalizedType = strtolower(trim($type));
        $normalizedTypeLabel = str_replace('_', ' ', $normalizedType);

        if ($normalizedMessage === '') {
            return true;
        }

        if ($normalizedMessage === $normalizedType || $normalizedMessage === $normalizedTypeLabel) {
            return true;
        }

        return str_ends_with($normalizedType, '_snapshot')
            ? $normalizedMessage === str_replace('_snapshot', ' snapshot', $normalizedType)
            : false;
    }

    private function buildStructuredSummary(
        string $category,
        string $type,
        array $measurements,
        array $context
    ): ?string {
        $tags = is_array($context['tags'] ?? null) ? $context['tags'] : [];

        $parts = match ($category) {
            'telemetry' => $this->telemetrySummaryParts($measurements, $tags),
            'speed' => $this->speedSummaryParts($measurements),
            'vehicle' => $this->vehicleSummaryParts($type, $measurements, $tags),
            'https' => $this->httpSummaryParts($measurements, $tags),
            default => $this->genericSummaryParts($measurements, $tags),
        };

        if ($parts === []) {
            return null;
        }

        return implode(' · ', array_slice($parts, 0, 6));
    }

    private function telemetrySummaryParts(array $measurements, array $tags): array
    {
        $parts = [];

        $this->appendNumericPart($parts, 'T', $measurements['temperatureC'] ?? null, 'C');
        $this->appendNumericPart($parts, 'H', $measurements['humidityPct'] ?? null, '%');
        $this->appendNumericPart($parts, 'P', $measurements['pressureHpa'] ?? null, 'hPa');
        $this->appendNumericPart($parts, 'AQ', $measurements['airQuality'] ?? null);
        $this->appendNumericPart($parts, 'SOC', $measurements['socPct'] ?? null, '%');
        $this->appendNumericPart($parts, 'RSSI', $measurements['parentRssi'] ?? null, 'dBm');
        $this->appendNumericPart($parts, 'LiDAR', $measurements['lidarDistanceMm'] ?? null, 'mm', 0);

        if (($tags['chargingState'] ?? null) !== null) {
            $parts[] = 'Charge=' . $this->stringOrNull($tags['chargingState']);
        }

        if (($tags['iaqState'] ?? null) !== null) {
            $parts[] = 'IAQ=' . $this->stringOrNull($tags['iaqState']);
        }

        $gps = $measurements['gps'] ?? null;

        if (is_array($gps)) {
            if (array_key_exists('error', $gps)) {
                $parts[] = 'GPS=' . (string) $gps['error'];
            } elseif (isset($gps['latitude'], $gps['longitude'])) {
                $parts[] = 'GPS fix';
            }
        }

        return array_values(array_filter($parts, static fn ($part) => $part !== null && $part !== ''));
    }

    private function speedSummaryParts(array $measurements): array
    {
        $parts = [];

        $this->appendNumericPart($parts, 'Vel', $measurements['speedKmh'] ?? null, 'km/h');
        $this->appendNumericPart($parts, 'Carril', $measurements['lane'] ?? null, null, 0);
        $this->appendNumericPart($parts, 'Pos', $measurements['positionM'] ?? null, 'm');
        $this->appendNumericPart($parts, 'Dist', $measurements['distanceMm'] ?? null, 'mm', 0);

        return $parts;
    }

    private function vehicleSummaryParts(string $type, array $measurements, array $tags): array
    {
        $parts = [];

        $parts[] = 'Tipo=' . $type;
        $this->appendNumericPart($parts, 'Vel final', $measurements['finalSpeedKmh'] ?? null, 'km/h');
        $this->appendNumericPart($parts, 'Vel prev', $measurements['previousSpeedKmh'] ?? null, 'km/h');
        $this->appendNumericPart($parts, 'Accel', $measurements['accelerationMps2'] ?? null, 'm/s2');
        $this->appendNumericPart($parts, 'Dist', $measurements['distanceMm'] ?? null, 'mm', 0);

        if (($tags['dynamicState'] ?? null) !== null) {
            $parts[] = 'Estado=' . $this->stringOrNull($tags['dynamicState']);
        }

        if (($tags['quality'] ?? null) !== null) {
            $parts[] = 'Calidad=' . $this->stringOrNull($tags['quality']);
        }

        return $parts;
    }

    private function httpSummaryParts(array $measurements, array $tags): array
    {
        $parts = [];

        $this->appendNumericPart($parts, 'HTTP', $measurements['status'] ?? null, null, 0);
        $this->appendNumericPart($parts, 'OK', $measurements['ok'] ?? null, null, 0);

        if (($tags['notificationType'] ?? null) !== null) {
            $parts[] = 'Notif=' . $this->stringOrNull($tags['notificationType']);
        }

        if (($tags['protocol'] ?? null) !== null) {
            $parts[] = 'Proto=' . $this->stringOrNull($tags['protocol']);
        }

        return $parts;
    }

    private function genericSummaryParts(array $measurements, array $tags): array
    {
        $parts = [];

        foreach ($measurements as $key => $value) {
            if (!is_scalar($value) || $value === '') {
                continue;
            }

            $parts[] = $key . '=' . $this->formatScalar($value);

            if (count($parts) >= 4) {
                break;
            }
        }

        foreach ($tags as $key => $value) {
            if (!is_scalar($value) || $value === '') {
                continue;
            }

            $parts[] = $key . '=' . $this->formatScalar($value);

            if (count($parts) >= 6) {
                break;
            }
        }

        return $parts;
    }

    private function appendNumericPart(
        array &$parts,
        string $label,
        mixed $value,
        ?string $unit = null,
        ?int $decimals = 1
    ): void {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return;
        }

        $parts[] = $label . '=' . $this->formatNumeric((float) $value, $decimals) . ($unit !== null ? $unit : '');
    }

    private function formatNumeric(float $value, ?int $decimals): string
    {
        if ($decimals === 0) {
            return (string) (int) round($value);
        }

        $formatted = number_format($value, $decimals ?? 1, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function formatScalar(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return $this->formatNumeric((float) $value, is_int($value) ? 0 : 1);
        }

        return trim((string) $value);
    }

    private function resolveEventTimestamp(
        mixed $eventTs,
        mixed $ts,
        DateTimeImmutable $receivedAt
    ): array {
        $eventTsParsed = $this->parseDateTime($eventTs);

        if ($eventTsParsed !== null) {
            return [
                'timestamp' => $eventTsParsed,
                'source' => 'eventTs',
                'fallback' => false,
            ];
        }

        $tsParsed = $this->parseDateTime($ts);

        if ($tsParsed !== null) {
            return [
                'timestamp' => $tsParsed,
                'source' => 'ts',
                'fallback' => true,
            ];
        }

        return [
            'timestamp' => $receivedAt,
            'source' => 'received_at',
            'fallback' => true,
        ];
    }

    private function parseDateTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '' || strtoupper($text) === 'UNSYNCED') {
            return null;
        }

        if (ctype_digit($text) && strlen($text) >= 9 && strlen($text) <= 11) {
            try {
                return new DateTimeImmutable('@' . $text);
            } catch (Throwable) {
                return null;
            }
        }

        foreach (['Y-m-d H:i:s.u', 'Y-m-d H:i:s.v', 'Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $text);

            if ($date instanceof DateTimeImmutable) {
                return $date;
            }
        }

        try {
            return new DateTimeImmutable($text);
        } catch (Throwable) {
            return null;
        }
    }
    private function safeIdentifier(mixed $value, string $default): string
    {
        if (!is_scalar($value)) {
            return $default;
        }

        $text = strtolower(trim((string) $value));
        $text = preg_replace('/[^a-z0-9_\-]/', '_', $text) ?? '';
        $text = trim($text, '_-');

        return $text !== '' ? substr($text, 0, 50) : $default;
    }

    private function safeSeverity(mixed $value): string
    {
        $severity = strtolower(trim(is_scalar($value) ? (string) $value : ''));

        return in_array($severity, ['info', 'warn', 'error', 'critical', 'unknown'], true)
            ? $severity
            : 'unknown';
    }

    private function normalizeMac(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = strtolower(trim((string) $value));

        return preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $text) === 1
            ? $text
            : null;
    }

    private function computeEventHash(array $event, int $ingestId, int $lineNumber): string
    {
        $payload = [
            'ingest_id' => $ingestId,
            'line_number' => $lineNumber,
            'code' => $event['code'] ?? null,
            'category' => $event['category'] ?? null,
            'type' => $event['type'] ?? null,
            'device_mac' => $event['deviceMac'] ?? null,
            'event_ts' => $event['eventTs'] ?? null,
            'ts' => $event['ts'] ?? null,
            'message' => $event['message'] ?? null,
            'raw' => $event['raw'] ?? null,
        ];

        ksort($payload);

        return hash(
            'sha256',
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function extraFields(array $event, array $knownKeys): array
    {
        return array_diff_key($event, array_flip($knownKeys));
    }

    private function firstNonEmptyString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value)) {
                $text = trim((string) $value);

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return 'Structured event';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function jsonEncode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
    }
}
