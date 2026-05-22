<?php

declare(strict_types=1);

namespace App\Ingest;

use DateTimeImmutable;
use Throwable;

final class VehicleEventNormalizer
{
    public function normalizeEvents(
        array $vehicleEvents,
        int $ingestId,
        ?int $bridgeDeviceId,
        DateTimeImmutable $receivedAt,
        array $payloadContext = [],
        ?callable $resolveDeviceId = null
    ): array {
        $rows = [];
        $parsedOk = 0;
        $parsedError = 0;

        foreach ($vehicleEvents as $index => $vehicleEvent) {
            $lineNumber = $index + 1;

            if (!is_array($vehicleEvent)) {
                $rows[] = $this->invalidRow($ingestId, $bridgeDeviceId, $receivedAt, $lineNumber);
                $parsedError++;
                continue;
            }

            $row = $this->normalizeOne(
                $vehicleEvent,
                $ingestId,
                $bridgeDeviceId,
                $receivedAt,
                $lineNumber,
                $payloadContext,
                $resolveDeviceId
            );

            $rows[] = $row;
            $parsedOk++;
        }

        return [
            'events' => $rows,
            'parsed_ok' => $parsedOk,
            'parsed_error' => $parsedError,
        ];
    }

    private function normalizeOne(
        array $vehicleEvent,
        int $ingestId,
        ?int $bridgeDeviceId,
        DateTimeImmutable $receivedAt,
        int $lineNumber,
        array $payloadContext,
        ?callable $resolveDeviceId
    ): array {
        $timestamp = $this->parseDateTime($vehicleEvent['timestamp'] ?? null) ?? $receivedAt;
        $timeFallback = !isset($vehicleEvent['timestamp'])
            || $this->parseDateTime($vehicleEvent['timestamp']) === null;

        $mac = $this->normalizeMac($vehicleEvent['macAddress'] ?? null);
        $type = $this->mapVehicleType($vehicleEvent['type'] ?? null, $vehicleEvent['label'] ?? null);

        $measurements = [];

        if (array_key_exists('distanceMm', $vehicleEvent)) {
            $measurements['distanceMm'] = $vehicleEvent['distanceMm'];
        }

        $measurements = array_merge(
            $measurements,
            $this->extractMetricsFromRaw($vehicleEvent['raw'] ?? null)
        );

        $context = [
            'source' => is_array($payloadContext['source'] ?? null) ? $payloadContext['source'] : [],
            'upload' => is_array($payloadContext['upload'] ?? null) ? $payloadContext['upload'] : [],
            'label' => $this->stringOrNull($vehicleEvent['label'] ?? null),
            'vehicleEventTypeRaw' => $this->stringOrNull($vehicleEvent['type'] ?? null),
            'beaconId' => $vehicleEvent['beaconId'] ?? null,
            'beaconName' => $this->stringOrNull($vehicleEvent['beaconName'] ?? null),
            'serialNumber' => $this->stringOrNull($vehicleEvent['serialNumber'] ?? null),
            'timestampRaw' => $this->stringOrNull($vehicleEvent['timestamp'] ?? null),
            'timestampSource' => $timeFallback ? 'received_at' : 'timestamp',
            'timeFallback' => $timeFallback,
            'mode' => $this->inferMode($vehicleEvent['label'] ?? null, $vehicleEvent['raw'] ?? null),
        ];

        $deviceId = null;

        if ($mac !== null && $resolveDeviceId !== null) {
            $resolved = $resolveDeviceId(
                $mac,
                $this->stringOrNull($vehicleEvent['beaconId'] ?? null),
                $this->stringOrNull($vehicleEvent['beaconName'] ?? null),
                $timestamp
            );

            $deviceId = is_int($resolved) ? $resolved : null;
        }

        $anomalyFlags = $timeFallback ? ['time_fallback'] : [];

        return [
            'ingest_id' => $ingestId,
            'device_id' => $deviceId,
            'bridge_device_id' => $bridgeDeviceId,
            'event_timestamp' => $timestamp->format('Y-m-d H:i:s'),
            'received_at' => $receivedAt->format('Y-m-d H:i:s'),
            'severity' => 'info',
            'severity_origin' => 'reported',
            'event_type' => $type,
            'event_category' => 'vehicle',
            'device_mac_raw' => $mac,
            'message_text' => $this->firstNonEmptyString(
                $vehicleEvent['raw'] ?? null,
                $vehicleEvent['label'] ?? null,
                $type
            ),
            'measurements' => $this->jsonEncode($measurements),
            'context' => $this->jsonEncode($context),
            'parse_ok' => 1,
            'parse_error' => null,
            'quality_status' => $timeFallback ? 'suspect' : 'valid',
            'anomaly_flags' => $this->jsonEncode($anomalyFlags),
            'event_hash' => $this->computeEventHash($vehicleEvent, $ingestId, $lineNumber),
            'created_at' => $receivedAt->format('Y-m-d H:i:s'),
        ];
    }

    private function invalidRow(
        int $ingestId,
        ?int $bridgeDeviceId,
        DateTimeImmutable $receivedAt,
        int $lineNumber
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
            'event_category' => 'vehicle',
            'device_mac_raw' => null,
            'message_text' => 'Invalid vehicle event at index ' . ($lineNumber - 1),
            'measurements' => $this->jsonEncode([]),
            'context' => $this->jsonEncode(['lineNumber' => $lineNumber]),
            'parse_ok' => 0,
            'parse_error' => 'vehicle_event_not_object',
            'quality_status' => 'invalid',
            'anomaly_flags' => $this->jsonEncode(['invalid_vehicle_event']),
            'event_hash' => null,
            'created_at' => $receivedAt->format('Y-m-d H:i:s'),
        ];
    }

    private function mapVehicleType(mixed $type, mixed $label): string
    {
        $rawType = strtolower(trim(is_scalar($type) ? (string) $type : ''));
        $rawLabel = strtoupper(trim(is_scalar($label) ? (string) $label : ''));

        if ($rawType === 'detected' || str_contains($rawLabel, 'VEHICLE_DETECTED')) {
            return 'vehicle_detected';
        }

        if ($rawType === 'exit' || str_contains($rawLabel, 'VEHICLE_EXIT')) {
            return 'vehicle_exit';
        }

        return 'vehicle_unknown';
    }

    private function inferMode(mixed $label, mixed $raw): ?string
    {
        $haystack = strtoupper(
            trim((is_scalar($label) ? (string) $label : '') . ' ' . (is_scalar($raw) ? (string) $raw : ''))
        );

        if (str_contains($haystack, '[SPEED]') || str_contains($haystack, ' KM/H')) {
            return 'speed';
        }

        return null;
    }

    private function extractMetricsFromRaw(mixed $raw): array
    {
        if (!is_scalar($raw)) {
            return [];
        }

        $text = (string) $raw;
        $metrics = [];

        $patterns = [
            'previousSpeedKmh' => '/\bprev=([-+]?\d+(?:\.\d+)?)\s*km\/h/i',
            'finalSpeedKmh' => '/\bfinal=([-+]?\d+(?:\.\d+)?)\s*km\/h/i',
            'accelerationMps2' => '/\baccel=([-+]?\d+(?:\.\d+)?)\s*m\/s2/i',
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $metrics[$key] = (float) $matches[1];
            }
        }

        if (preg_match('/\bdynamic=([A-Z_]+)/i', $text, $matches) === 1) {
            $metrics['dynamicState'] = strtoupper($matches[1]);
        }

        if (preg_match('/\bquality=([A-Z_]+)/i', $text, $matches) === 1) {
            $metrics['quality'] = strtoupper($matches[1]);
        }

        return $metrics;
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
            'type' => $event['type'] ?? null,
            'label' => $event['label'] ?? null,
            'macAddress' => $event['macAddress'] ?? null,
            'timestamp' => $event['timestamp'] ?? null,
            'distanceMm' => $event['distanceMm'] ?? null,
            'raw' => $event['raw'] ?? null,
        ];

        ksort($payload);

        return hash(
            'sha256',
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
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

        return 'Vehicle event';
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
