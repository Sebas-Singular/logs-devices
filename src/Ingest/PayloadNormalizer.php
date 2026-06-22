<?php

declare(strict_types=1);

namespace App\Ingest;

final class PayloadNormalizer
{
    public const MODE_STRUCTURED_EVENTS = 'structured_events';
    public const MODE_VEHICLE_EVENTS = 'vehicle_events';
    public const MODE_LEGACY_LOG_TEXT = 'legacy_log_text';
    public const MODE_EMPTY = 'empty';

    public function normalize(array $payload): array
    {
        $source = $this->arrayOrEmpty($payload['source'] ?? null);
        $upload = $this->arrayOrEmpty($payload['upload'] ?? null);
        $raw = $this->arrayOrEmpty($payload['raw'] ?? null);

        $events = $this->listOfArrays($payload['events'] ?? null);
        $vehicleEvents = $this->listOfArrays($payload['vehicleEvents'] ?? null);

        $rawLogText = $this->firstNonEmptyString(
            $raw['logText'] ?? null,
            $payload['logText'] ?? null
        );

        $bridgeIdReported = $this->firstNonEmptyString(
            $payload['bridgeId'] ?? null,
            $source['deviceId'] ?? null
        );

        $bridgeName = $this->firstNonEmptyString(
            $payload['bridgeName'] ?? null,
            $source['deviceName'] ?? null
        );

        $sentAt = $this->firstNonEmptyString(
            $upload['sentAt'] ?? null,
            $payload['sentAt'] ?? null
        );

        $hasStructuredEvents = $events !== [];
        $hasVehicleEvents = $vehicleEvents !== [];

        $processingMode = match (true) {
            $hasStructuredEvents => self::MODE_STRUCTURED_EVENTS,
            $hasVehicleEvents => self::MODE_VEHICLE_EVENTS,
            trim($rawLogText) !== '' => self::MODE_LEGACY_LOG_TEXT,
            default => self::MODE_EMPTY,
        };

        $sourceType = $this->sourceTypeFromSource($source);

        $payloadSummary = [
            'schemaVersion' => $this->nullableInt($payload['schemaVersion'] ?? null),
            'message' => $this->firstNonEmptyString($payload['message'] ?? null) ?: null,
            'source' => $source,
            'upload' => $upload,
            'eventCount' => $this->nullableInt($payload['eventCount'] ?? null),
            'actualEventCount' => count($events),
            'vehicleEventCount' => $this->nullableInt($payload['vehicleEventCount'] ?? null),
            'actualVehicleEventCount' => count($vehicleEvents),
            'processedMode' => $processingMode,
            'categories' => $this->countCategories($events),
            'legacy' => [
                'bytes' => $payload['bytes'] ?? null,
                'fromOffset' => $payload['fromOffset'] ?? null,
                'toOffset' => $payload['toOffset'] ?? null,
                'rotated' => $payload['rotated'] ?? null,
                'bridgeName' => $bridgeName !== '' ? $bridgeName : null,
            ],
        ];

        return [
            'message' => $this->firstNonEmptyString($payload['message'] ?? null),
            'schema_version' => $this->nullableInt($payload['schemaVersion'] ?? null),

            'source' => $source,
            'source_type' => $sourceType,
            'source_project' => $this->firstNonEmptyString($source['project'] ?? null),
            'source_product' => $this->firstNonEmptyString($source['product'] ?? null),
            'source_device_type' => $this->firstNonEmptyString($source['deviceType'] ?? null),
            'source_device_id' => $this->firstNonEmptyString($source['deviceId'] ?? null),
            'source_device_name' => $this->firstNonEmptyString($source['deviceName'] ?? null),
            'source_mac' => $this->firstNonEmptyString(
                $source['bridgeMac'] ?? null,
                $source['macAddress'] ?? null,
                $source['mac'] ?? null
            ),
            'firmware_version' => $this->firstNonEmptyString($source['firmwareVersion'] ?? null),
            'serial_number' => $this->firstNonEmptyString($source['serialNumber'] ?? null),

            'bridge_id_reported' => $bridgeIdReported,
            'bridge_name' => $bridgeName,
            'sent_at' => $sentAt,
            'upload' => $upload,

            'raw' => $raw,
            'raw_log_text' => $rawLogText,
            'events' => $events,
            'vehicle_events' => $vehicleEvents,

            'event_count_declared' => $this->nullableInt($payload['eventCount'] ?? null),
            'event_count_actual' => count($events),
            'vehicle_event_count_declared' => $this->nullableInt($payload['vehicleEventCount'] ?? null),
            'vehicle_event_count_actual' => count($vehicleEvents),

            'has_structured_events' => $hasStructuredEvents,
            'has_vehicle_events' => $hasVehicleEvents,
            'processing_mode' => $processingMode,
            'categories' => $this->countCategories($events),
            'payload_summary' => $payloadSummary,
        ];
    }

    private function sourceTypeFromSource(array $source): string
    {
        $project = strtolower($this->firstNonEmptyString($source['project'] ?? null));
        $product = strtolower($this->firstNonEmptyString($source['product'] ?? null));
        $deviceType = strtolower($this->firstNonEmptyString($source['deviceType'] ?? null));

        $parts = array_values(array_filter([$project, $product, $deviceType]));
        $parts = array_values(array_unique($parts));

        if ($parts === []) {
            return 'walkerpisa_bridge';
        }

        return implode('_', $parts);
    }

    private function countCategories(array $events): array
    {
        $categories = [];

        foreach ($events as $event) {
            $category = trim((string) ($event['category'] ?? 'unknown'));

            if ($category === '') {
                $category = 'unknown';
            }

            $categories[$category] = ($categories[$category] ?? 0) + 1;
        }

        ksort($categories);

        return $categories;
    }

    private function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function listOfArrays(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function firstNonEmptyString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            if (is_scalar($value)) {
                $text = trim((string) $value);

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return null;
    }
}
