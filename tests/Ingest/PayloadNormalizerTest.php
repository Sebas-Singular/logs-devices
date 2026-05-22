<?php

declare(strict_types=1);

namespace Tests\Ingest;

use App\Ingest\PayloadNormalizer;
use PHPUnit\Framework\TestCase;

final class PayloadNormalizerTest extends TestCase
{
    public function testNormalizeLegacyPayloadUsesLegacyMode(): void
    {
        $normalizer = new PayloadNormalizer();

        $payload = [
            'message' => 'bridge_logs',
            'bridgeId' => 120,
            'bridgeName' => 'WalkerPisa Bridge',
            'sentAt' => '2026-05-18 10:00:00',
            'logText' => "[2026-05-18 10:00:00] [TELEMETRY] INFO: test\n",
        ];

        $result = $normalizer->normalize($payload);

        $this->assertSame(PayloadNormalizer::MODE_LEGACY_LOG_TEXT, $result['processing_mode']);
        $this->assertSame('120', $result['bridge_id_reported']);
        $this->assertSame('WalkerPisa Bridge', $result['bridge_name']);
        $this->assertSame("[2026-05-18 10:00:00] [TELEMETRY] INFO: test", $result['raw_log_text']);
        $this->assertFalse($result['has_structured_events']);
        $this->assertFalse($result['has_vehicle_events']);
    }

    public function testNormalizeStructuredPayloadPrioritizesEventsOverVehicleEventsAndLogText(): void
    {
        $normalizer = new PayloadNormalizer();

        $payload = [
            'message' => 'bridge_logs',
            'schemaVersion' => 1,
            'source' => [
                'project' => 'walkerpisa',
                'product' => 'bridge',
                'deviceType' => 'bridge',
                'deviceId' => 120,
                'deviceName' => 'WalkerPisa Bridge',
                'firmwareVersion' => '0.11.1',
            ],
            'upload' => [
                'sentAt' => '2026-05-18 13:52:48',
            ],
            'raw' => [
                'format' => 'plain-text',
                'logText' => 'legacy raw fallback text',
            ],
            'events' => [
                [
                    'category' => 'telemetry',
                    'type' => 'telemetry_snapshot',
                ],
                [
                    'category' => 'vehicle',
                    'type' => 'vehicle_detected',
                ],
            ],
            'vehicleEvents' => [
                [
                    'type' => 'detected',
                ],
            ],
        ];

        $result = $normalizer->normalize($payload);

        $this->assertSame(PayloadNormalizer::MODE_STRUCTURED_EVENTS, $result['processing_mode']);
        $this->assertSame('120', $result['bridge_id_reported']);
        $this->assertSame('WalkerPisa Bridge', $result['bridge_name']);
        $this->assertSame('legacy raw fallback text', $result['raw_log_text']);
        $this->assertTrue($result['has_structured_events']);
        $this->assertTrue($result['has_vehicle_events']);
        $this->assertCount(2, $result['events']);
        $this->assertCount(1, $result['vehicle_events']);
        $this->assertSame([
            'telemetry' => 1,
            'vehicle' => 1,
        ], $result['categories']);
        $this->assertSame('structured_events', $result['payload_summary']['processedMode']);
    }

    public function testNormalizeVehicleEventsOnlyUsesVehicleFallbackMode(): void
    {
        $normalizer = new PayloadNormalizer();

        $payload = [
            'message' => 'bridge_logs',
            'source' => [
                'deviceId' => 120,
                'deviceName' => 'WalkerPisa Bridge',
            ],
            'vehicleEvents' => [
                [
                    'type' => 'detected',
                    'macAddress' => '34:85:18:46:e3:70',
                ],
            ],
        ];

        $result = $normalizer->normalize($payload);

        $this->assertSame(PayloadNormalizer::MODE_VEHICLE_EVENTS, $result['processing_mode']);
        $this->assertFalse($result['has_structured_events']);
        $this->assertTrue($result['has_vehicle_events']);
        $this->assertCount(1, $result['vehicle_events']);
    }

    public function testNormalizeEmptyPayloadUsesEmptyMode(): void
    {
        $normalizer = new PayloadNormalizer();

        $payload = [
            'message' => 'bridge_logs',
            'bridgeId' => 120,
        ];

        $result = $normalizer->normalize($payload);

        $this->assertSame(PayloadNormalizer::MODE_EMPTY, $result['processing_mode']);
    }
}
