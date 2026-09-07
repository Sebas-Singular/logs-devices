<?php

declare(strict_types=1);

namespace Tests\Viewer;

use App\Viewer\ViewerFormatter;
use PHPUnit\Framework\TestCase;

// =============================================================================
// ViewerFormatterTest — resumen de métricas en el visor
// =============================================================================
//
// El resumen anterior solo entendía las claves camelCase del formato
// estructurado, así que todos los eventos parseados por los parsers legacy
// se mostraban sin métricas. Estos tests fijan que ambas generaciones se leen.
// =============================================================================

final class ViewerFormatterTest extends TestCase
{
    public function testSummarizesStructuredTelemetryEvent(): void
    {
        $summary = ViewerFormatter::eventSummary([
            'event_category' => 'telemetry',
            'measurements' => json_encode([
                'temperatureC' => 30.1,
                'humidityPct' => 27.5,
                'socPct' => 100,
                'parentRssi' => -78,
                'lidarDistanceMm' => 5405,
            ]),
            'context' => json_encode([
                'tags' => [
                    'chargingState' => 'CHARGING',
                    'iaqState' => 'READY',
                ],
            ]),
        ]);

        $this->assertNotNull($summary);
        $this->assertStringContainsString('T=30.1°C', $summary);
        $this->assertStringContainsString('H=27.5%', $summary);
        $this->assertStringContainsString('SOC=100%', $summary);
        $this->assertStringContainsString('RSSI=-78dBm', $summary);
        $this->assertStringContainsString('LiDAR=5405mm', $summary);
        $this->assertStringContainsString('Carga=CHARGING', $summary);
        $this->assertStringContainsString('IAQ=READY', $summary);
    }

    public function testSummarizesLegacySpeedEventWithSnakeCaseKeys(): void
    {
        $summary = ViewerFormatter::eventSummary([
            'event_category' => 'speed',
            'measurements' => json_encode([
                'speed_kmh' => 24.87,
                'distance_mm' => 5329.0,
                'position_m' => 14.0,
            ]),
            'context' => json_encode([
                'lane' => 1,
                'loc' => 1,
            ]),
        ]);

        $this->assertNotNull($summary);
        $this->assertStringContainsString('Vel=24.9km/h', $summary);
        $this->assertStringContainsString('Dist=5329mm', $summary);
        $this->assertStringContainsString('Pos=14m', $summary);
    }

    public function testSummarizesLegacyTelemetryEventWithSnakeCaseKeys(): void
    {
        $summary = ViewerFormatter::eventSummary([
            'event_category' => 'telemetry',
            'measurements' => json_encode([
                'temperature_c' => 17.1,
                'humidity_pct' => 23.9,
                'soc_pct' => 100.0,
                'rssi_dbm' => -78.0,
                'lidar_mm' => 5405.0,
            ]),
            'context' => json_encode([]),
        ]);

        $this->assertNotNull($summary);
        $this->assertStringContainsString('T=17.1°C', $summary);
        $this->assertStringContainsString('H=23.9%', $summary);
        $this->assertStringContainsString('SOC=100%', $summary);
    }

    public function testSummarizesVehicleEvent(): void
    {
        $summary = ViewerFormatter::eventSummary([
            'event_category' => 'vehicle',
            'measurements' => json_encode([
                'finalSpeedKmh' => 31.4,
                'previousSpeedKmh' => 28.1,
                'accelerationMps2' => -0.6,
            ]),
            'context' => json_encode([
                'tags' => ['dynamicState' => 'BRAKING'],
            ]),
        ]);

        $this->assertNotNull($summary);
        $this->assertStringContainsString('Vel final=31.4km/h', $summary);
        $this->assertStringContainsString('Vel prev=28.1km/h', $summary);
        $this->assertStringContainsString('Estado=BRAKING', $summary);
    }

    public function testUnknownCategoryStillShowsItsMetrics(): void
    {
        // Antes, una categoría fuera del match devolvía null y la fila
        // aparecía muda aunque el evento trajera datos.
        $summary = ViewerFormatter::eventSummary([
            'event_category' => 'ota',
            'measurements' => json_encode(['socPct' => 61]),
            'context' => json_encode([]),
        ]);

        $this->assertNotNull($summary);
        $this->assertStringContainsString('SOC=61%', $summary);
    }

    public function testShowsMetricsTheCatalogDoesNotKnowYet(): void
    {
        $summary = ViewerFormatter::eventSummary([
            'event_category' => 'telemetry',
            'measurements' => json_encode([
                'temperatureC' => 21.0,
                'batteryVoltage' => 3.7,
            ]),
            'context' => json_encode([]),
        ]);

        $this->assertNotNull($summary);
        $this->assertStringContainsString('T=21°C', $summary);
        $this->assertStringContainsString('batteryVoltage=3.7', $summary);
    }

    public function testReportsGpsError(): void
    {
        $summary = ViewerFormatter::eventSummary([
            'event_category' => 'telemetry',
            'measurements' => json_encode([
                'temperatureC' => 21.0,
                'gps' => ['error' => 1],
            ]),
            'context' => json_encode([]),
        ]);

        $this->assertNotNull($summary);
        $this->assertStringContainsString('GPS=error 1', $summary);
    }

    public function testReturnsNullWhenEventHasNothingToSummarize(): void
    {
        $this->assertNull(ViewerFormatter::eventSummary([
            'event_category' => 'errors',
            'measurements' => json_encode([]),
            'context' => json_encode([]),
        ]));
    }

    public function testAcceptsAlreadyDecodedArrays(): void
    {
        $summary = ViewerFormatter::eventSummary([
            'event_category' => 'speed',
            'measurements' => ['speedKmh' => 12.5],
            'context' => [],
        ]);

        $this->assertSame('Vel=12.5km/h', $summary);
    }

    public function testMessageWithoutMetricsKeepsOnlyTheHumanPart(): void
    {
        // La ingesta compone message_text como "mensaje · métrica=valor · ...".
        // El visor ya pinta ese resumen aparte, así que aquí sobra.
        $this->assertSame(
            'Telemetry snapshot',
            ViewerFormatter::messageWithoutMetrics('Telemetry snapshot · T=21.4C · H=45.8%')
        );

        $this->assertSame(
            'HTTPS POST result failed',
            ViewerFormatter::messageWithoutMetrics('HTTPS POST result failed · HTTP=500 · Notif=ping')
        );

        $this->assertSame(
            'Vehicle detected',
            ViewerFormatter::messageWithoutMetrics('Vehicle detected · Vel final=31.4km/h · Estado=BRAKING')
        );
    }

    public function testMessageWithoutMetricsLeavesLegacyRawLinesIntact(): void
    {
        // Los mensajes legacy son la línea de log cruda, sin separadores ' · '.
        $raw = "[2026-05-04 05:15:29] [TELEMETRY] INFO: T=17.1C H=23.90% P=928.1hPa";

        $this->assertSame($raw, ViewerFormatter::messageWithoutMetrics($raw));
    }

    public function testMessageWithoutMetricsLeavesPlainMessagesIntact(): void
    {
        foreach ([
            'Invalid structured event at index 3',
            'Bridge error: watchdog reset',
        ] as $message) {
            $this->assertSame($message, ViewerFormatter::messageWithoutMetrics($message));
        }
    }

    public function testMessageWithoutMetricsReturnsEmptyWhenItWasOnlyMetrics(): void
    {
        // Sin parte humana la vista no pinta la línea y se queda solo el resumen.
        $this->assertSame('', ViewerFormatter::messageWithoutMetrics('T=21.4C · H=45.8%'));
        $this->assertSame('', ViewerFormatter::messageWithoutMetrics(''));
        $this->assertSame('', ViewerFormatter::messageWithoutMetrics(null));
    }

    public function testRelativeTimeDescribesAge(): void
    {
        $this->assertSame(
            'hace 1 h',
            ViewerFormatter::relativeTime(gmdate('Y-m-d H:i:s', time() - 5400))
        );

        $this->assertSame('—', ViewerFormatter::relativeTime(null));
        $this->assertSame('—', ViewerFormatter::relativeTime(''));
    }

    public function testHealthPresentationMapsEveryStatus(): void
    {
        foreach (['operativo', 'degradado', 'caido', 'sin_datos'] as $status) {
            $ui = ViewerFormatter::healthPresentation($status);

            $this->assertArrayHasKey('label', $ui);
            $this->assertArrayHasKey('text', $ui);
            $this->assertArrayHasKey('dot', $ui);
            $this->assertArrayHasKey('rail', $ui);
            $this->assertNotSame('', $ui['label']);
        }
    }
}
