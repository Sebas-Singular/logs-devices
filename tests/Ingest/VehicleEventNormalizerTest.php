<?php

declare(strict_types=1);

namespace Tests\Ingest;

use App\Ingest\VehicleEventNormalizer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class VehicleEventNormalizerTest extends TestCase
{
    public function testNormalizeDetectedVehicleEventExtractsSpeedMetricsFromRaw(): void
    {
        $normalizer = new VehicleEventNormalizer();

        $result = $normalizer->normalizeEvents(
            vehicleEvents: [
                [
                    'type' => 'detected',
                    'label' => 'VEHICLE_DETECTED [SPEED]',
                    'macAddress' => '34:85:18:46:e3:70',
                    'beaconId' => 6,
                    'beaconName' => 'Baliza 06 - Las Rozas',
                    'serialNumber' => '',
                    'timestamp' => '2026-05-18 13:26:16.920',
                    'distanceMm' => 541,
                    'raw' => '[2026-05-18 13:26:17] [VEHICLE] INFO: raw | prev=0.00 km/h final=22.57 km/h accel=0.00 m/s2 dynamic=STABLE quality=MEDIUM',
                ],
            ],
            ingestId: 100,
            bridgeDeviceId: 200,
            receivedAt: new DateTimeImmutable('2026-05-18 13:26:20'),
            payloadContext: [
                'source' => [
                    'project' => 'walkerpisa',
                ],
            ],
            resolveDeviceId: static fn() => 300,
        );

        $this->assertSame(1, $result['parsed_ok']);
        $this->assertSame(0, $result['parsed_error']);

        $event = $result['events'][0];

        $this->assertSame(100, $event['ingest_id']);
        $this->assertSame(300, $event['device_id']);
        $this->assertSame(200, $event['bridge_device_id']);
        $this->assertSame('vehicle', $event['event_category']);
        $this->assertSame('vehicle_detected', $event['event_type']);
        $this->assertSame('34:85:18:46:e3:70', $event['device_mac_raw']);
        $this->assertSame('valid', $event['quality_status']);
        $this->assertSame(1, $event['parse_ok']);

        $measurements = json_decode($event['measurements'], true);
        $context = json_decode($event['context'], true);

        $this->assertSame(541, $measurements['distanceMm']);
        $this->assertEquals(0.0, $measurements['previousSpeedKmh']);
        $this->assertEquals(22.57, $measurements['finalSpeedKmh']);
        $this->assertEquals(0.0, $measurements['accelerationMps2']);
        $this->assertSame('STABLE', $measurements['dynamicState']);
        $this->assertSame('MEDIUM', $measurements['quality']);

        $this->assertSame('speed', $context['mode']);
        $this->assertSame(6, $context['beaconId']);
        $this->assertSame('Baliza 06 - Las Rozas', $context['beaconName']);
    }

    public function testNormalizeExitVehicleEventMapsType(): void
    {
        $normalizer = new VehicleEventNormalizer();

        $result = $normalizer->normalizeEvents(
            vehicleEvents: [
                [
                    'type' => 'exit',
                    'label' => 'VEHICLE_EXIT',
                    'macAddress' => '34:85:18:46:e3:70',
                    'timestamp' => '2026-05-18 13:30:00',
                    'distanceMm' => 1498,
                    'raw' => '[2026-05-18 13:30:00] [VEHICLE] INFO: exit',
                ],
            ],
            ingestId: 101,
            bridgeDeviceId: 201,
            receivedAt: new DateTimeImmutable('2026-05-18 13:30:01'),
        );

        $event = $result['events'][0];

        $this->assertSame('vehicle_exit', $event['event_type']);
        $this->assertSame('vehicle', $event['event_category']);
        $this->assertSame(1, $event['parse_ok']);

        $measurements = json_decode($event['measurements'], true);

        $this->assertSame(1498, $measurements['distanceMm']);
    }

    public function testInvalidVehicleEventIsMarkedAsParseError(): void
    {
        $normalizer = new VehicleEventNormalizer();

        $result = $normalizer->normalizeEvents(
            vehicleEvents: [
                'not-an-object',
            ],
            ingestId: 102,
            bridgeDeviceId: 202,
            receivedAt: new DateTimeImmutable('2026-05-18 13:30:01'),
        );

        $this->assertSame(0, $result['parsed_ok']);
        $this->assertSame(1, $result['parsed_error']);

        $event = $result['events'][0];

        $this->assertSame(0, $event['parse_ok']);
        $this->assertSame('vehicle_event_not_object', $event['parse_error']);
        $this->assertSame('invalid', $event['quality_status']);
    }
}
