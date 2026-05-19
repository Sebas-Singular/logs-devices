<?php

declare(strict_types=1);

namespace Tests\Ingest;

use App\Ingest\StructuredEventNormalizer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class StructuredEventNormalizerTest extends TestCase
{
    public function testNormalizeTelemetryEventMapsMetricsGpsTagsAndSource(): void
    {
        $normalizer = new StructuredEventNormalizer();

        $result = $normalizer->normalizeEvents(
            events: [
                [
                    'ts' => '2026-05-18 13:48:02',
                    'level' => 'info',
                    'code' => 'TELEMETRY',
                    'category' => 'telemetry',
                    'type' => 'telemetry_snapshot',
                    'message' => 'Telemetry snapshot',
                    'eventTs' => '2026-05-18 13:48:02',
                    'deviceMac' => '34:85:18:46:e4:20',
                    'nodeId' => 8,
                    'nodeName' => 'Baliza 08 - Las Rozas',
                    'metrics' => [
                        'temperatureC' => 30.1,
                        'humidityPct' => 27.46,
                        'pressureHpa' => 937,
                    ],
                    'tags' => [
                        'chargingState' => 'CHARGING',
                    ],
                    'gps' => [
                        'error' => 1,
                        'latitude' => null,
                        'longitude' => null,
                        'altitude' => null,
                    ],
                ],
            ],
            ingestId: 10,
            bridgeDeviceId: 20,
            receivedAt: new DateTimeImmutable('2026-05-18 13:49:00'),
            payloadContext: [
                'source' => [
                    'project' => 'walkerpisa',
                    'deviceId' => 120,
                ],
                'upload' => [
                    'sentAt' => '2026-05-18 13:52:48',
                ],
            ],
            resolveDeviceId: static fn() => 30,
        );

        $this->assertSame(1, $result['parsed_ok']);
        $this->assertSame(0, $result['parsed_error']);
        $this->assertCount(1, $result['events']);

        $event = $result['events'][0];

        $this->assertSame(10, $event['ingest_id']);
        $this->assertSame(30, $event['device_id']);
        $this->assertSame(20, $event['bridge_device_id']);
        $this->assertSame('2026-05-18 13:48:02', $event['event_timestamp']);
        $this->assertSame('info', $event['severity']);
        $this->assertSame('reported', $event['severity_origin']);
        $this->assertSame('telemetry', $event['event_category']);
        $this->assertSame('telemetry_snapshot', $event['event_type']);
        $this->assertSame('34:85:18:46:e4:20', $event['device_mac_raw']);
        $this->assertSame(1, $event['parse_ok']);
        $this->assertNull($event['parse_error']);
        $this->assertSame('valid', $event['quality_status']);

        $measurements = json_decode($event['measurements'], true);
        $context = json_decode($event['context'], true);
        $anomalyFlags = json_decode($event['anomaly_flags'], true);

        $this->assertSame(30.1, $measurements['temperatureC']);
        $this->assertSame(27.46, $measurements['humidityPct']);
        $this->assertSame(937, $measurements['pressureHpa']);
        $this->assertSame(1, $measurements['gps']['error']);

        $this->assertSame('CHARGING', $context['tags']['chargingState']);
        $this->assertSame('walkerpisa', $context['source']['project']);
        $this->assertSame('eventTs', $context['timestampSource']);
        $this->assertFalse($context['timeFallback']);

        $this->assertContains('gps_no_signal', $anomalyFlags);
    }

    public function testNormalizeUnsyncedEventUsesTsFallback(): void
    {
        $normalizer = new StructuredEventNormalizer();

        $result = $normalizer->normalizeEvents(
            events: [
                [
                    'ts' => '2026-05-18 10:02:37',
                    'level' => 'info',
                    'category' => 'telemetry',
                    'type' => 'telemetry_snapshot',
                    'message' => 'Telemetry snapshot',
                    'eventTs' => 'UNSYNCED',
                    'deviceMac' => '34:85:18:46:e4:20',
                    'metrics' => [
                        'temperatureC' => 27.2,
                    ],
                ],
            ],
            ingestId: 11,
            bridgeDeviceId: 21,
            receivedAt: new DateTimeImmutable('2026-05-18 10:03:00'),
        );

        $event = $result['events'][0];

        $this->assertSame('2026-05-18 10:02:37', $event['event_timestamp']);
        $this->assertSame('suspect', $event['quality_status']);

        $context = json_decode($event['context'], true);
        $anomalyFlags = json_decode($event['anomaly_flags'], true);

        $this->assertSame('UNSYNCED', $context['eventTsRaw']);
        $this->assertSame('ts', $context['timestampSource']);
        $this->assertTrue($context['timeFallback']);
        $this->assertContains('time_fallback', $anomalyFlags);
    }

    public function testNormalizeUnknownStructuredEventDoesNotFail(): void
    {
        $normalizer = new StructuredEventNormalizer();

        $result = $normalizer->normalizeEvents(
            events: [
                [
                    'ts' => '2026-05-18 10:02:37',
                    'level' => 'info',
                    'category' => 'future_sensor',
                    'type' => 'future_event_type',
                    'message' => 'Future event',
                    'eventTs' => '2026-05-18 10:02:37',
                    'deviceMac' => '34:85:18:46:e4:20',
                    'metrics' => [
                        'newMetric' => 123,
                    ],
                    'unexpectedField' => 'kept in extra',
                ],
            ],
            ingestId: 12,
            bridgeDeviceId: 22,
            receivedAt: new DateTimeImmutable('2026-05-18 10:03:00'),
        );

        $event = $result['events'][0];

        $this->assertSame(1, $result['parsed_ok']);
        $this->assertSame(0, $result['parsed_error']);
        $this->assertSame('future_sensor', $event['event_category']);
        $this->assertSame('future_event_type', $event['event_type']);
        $this->assertSame(1, $event['parse_ok']);

        $measurements = json_decode($event['measurements'], true);
        $context = json_decode($event['context'], true);

        $this->assertSame(123, $measurements['newMetric']);
        $this->assertSame('kept in extra', $context['extra']['unexpectedField']);
    }
}
