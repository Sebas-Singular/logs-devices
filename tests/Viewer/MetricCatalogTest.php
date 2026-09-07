<?php

declare(strict_types=1);

namespace Tests\Viewer;

use App\Viewer\MetricCatalog;
use PHPUnit\Framework\TestCase;

// =============================================================================
// MetricCatalogTest — resolución de métricas entre generaciones de formato
// =============================================================================
//
// El histórico tiene dos generaciones de claves para las mismas magnitudes:
// las camelCase del formato estructurado actual y las snake_case que producen
// los parsers legacy sobre logText. El catálogo debe leer ambas y preferir
// siempre la vigente.
// =============================================================================

final class MetricCatalogTest extends TestCase
{
    public function testResolvesCurrentStructuredKey(): void
    {
        $sample = MetricCatalog::resolve('speed', ['speedKmh' => 34.8]);

        $this->assertNotNull($sample);
        $this->assertSame(34.8, $sample['value']);
        $this->assertSame('speedKmh', $sample['key']);
        $this->assertFalse($sample['legacy']);
    }

    public function testResolvesLegacyKeyWhenCurrentIsAbsent(): void
    {
        $sample = MetricCatalog::resolve('speed', ['speed_kmh' => 24.87]);

        $this->assertNotNull($sample);
        $this->assertSame(24.87, $sample['value']);
        $this->assertSame('speed_kmh', $sample['key']);
        $this->assertTrue($sample['legacy']);
    }

    public function testPrefersCurrentKeyWhenBothGenerationsArePresent(): void
    {
        $sample = MetricCatalog::resolve('speed', [
            'speed_kmh' => 10.0,
            'speedKmh' => 42.0,
        ]);

        $this->assertNotNull($sample);
        $this->assertSame(42.0, $sample['value']);
        $this->assertSame('speedKmh', $sample['key']);
        $this->assertFalse($sample['legacy']);
    }

    public function testFallsBackToNextAliasWhenCurrentValueIsNotNumeric(): void
    {
        $sample = MetricCatalog::resolve('temperature', [
            'temperatureC' => null,
            'temperature_c' => 17.1,
        ]);

        $this->assertNotNull($sample);
        $this->assertSame(17.1, $sample['value']);
        $this->assertSame('temperature_c', $sample['key']);
    }

    public function testAcceptsNumericStrings(): void
    {
        $sample = MetricCatalog::resolve('soc', ['socPct' => '88']);

        $this->assertNotNull($sample);
        $this->assertSame(88.0, $sample['value']);
    }

    public function testReturnsNullForUnknownMetric(): void
    {
        $this->assertNull(MetricCatalog::resolve('no_existe', ['speedKmh' => 1.0]));
    }

    public function testReturnsNullWhenNoAliasIsPresent(): void
    {
        $this->assertNull(MetricCatalog::resolve('speed', ['humidityPct' => 40.0]));
    }

    public function testResolveAllKeepsCatalogOrder(): void
    {
        $resolved = MetricCatalog::resolveAll([
            'socPct' => 90,
            'speedKmh' => 12.0,
            'temperatureC' => 20.0,
        ]);

        $this->assertSame(
            ['speed', 'temperature', 'soc'],
            array_keys($resolved)
        );
    }

    public function testUnknownScalarsReportsKeysTheCatalogDoesNotCoverYet(): void
    {
        $extra = MetricCatalog::unknownScalars([
            'speedKmh' => 30.0,
            'batteryVoltage' => 3.7,
            'gps' => ['latitude' => 1.0],
        ]);

        $this->assertSame(['batteryVoltage' => 3.7], $extra);
    }

    public function testForCategoryReturnsMetricsOfThatFamily(): void
    {
        $speed = MetricCatalog::forCategory('speed');

        $this->assertContains('speed', $speed);
        $this->assertContains('distance', $speed);
        $this->assertNotContains('humidity', $speed);
    }

    public function testEveryMetricBelongsToADeclaredGroup(): void
    {
        $groups = MetricCatalog::groups();

        foreach (MetricCatalog::all() as $canonical => $metric) {
            $this->assertArrayHasKey(
                $metric['group'],
                $groups,
                "La métrica {$canonical} declara un grupo inexistente: {$metric['group']}."
            );
        }
    }

    public function testGroupByPartitionsWithoutLosingMetrics(): void
    {
        $resolved = MetricCatalog::resolveAll([
            'speedKmh' => 30.0,
            'temperatureC' => 20.0,
            'socPct' => 80,
            'lidarDistanceMm' => 1200,
        ]);

        $grouped = MetricCatalog::groupBy($resolved);

        $this->assertSame(['speed'], array_keys($grouped['trafico']));
        $this->assertSame(['temperature'], array_keys($grouped['ambiental']));
        $this->assertSame(['soc'], array_keys($grouped['energia']));
        $this->assertSame(['lidar'], array_keys($grouped['sensor']));

        $total = array_sum(array_map('count', $grouped));
        $this->assertCount($total, $resolved, 'groupBy perdió o duplicó métricas.');
    }

    public function testGroupByKeepsGroupOrder(): void
    {
        $grouped = MetricCatalog::groupBy(MetricCatalog::all());

        $this->assertSame(
            array_keys(MetricCatalog::groups()),
            array_keys($grouped)
        );
    }

    public function testFormatUsesCatalogDecimalsAndUnit(): void
    {
        $this->assertSame('34.8 km/h', MetricCatalog::format('speed', 34.8));
        $this->assertSame('88 %', MetricCatalog::format('soc', 88.0));
        $this->assertSame('5405 mm', MetricCatalog::format('lidar', 5405.0));
    }

    public function testFormatValueTrimsTrailingZeros(): void
    {
        $this->assertSame('30', MetricCatalog::formatValue('speed', 30.0));
        $this->assertSame('30.1', MetricCatalog::formatValue('speed', 30.1));
    }

    public function testEveryMetricDeclaresAtLeastOneAlias(): void
    {
        foreach (MetricCatalog::all() as $canonical => $metric) {
            $this->assertNotSame([], $metric['aliases'], "La métrica {$canonical} no declara alias.");
        }
    }

    public function testAliasesAreNotSharedBetweenMetrics(): void
    {
        $seen = [];

        foreach (MetricCatalog::all() as $canonical => $metric) {
            foreach ($metric['aliases'] as $alias) {
                $this->assertArrayNotHasKey(
                    $alias,
                    $seen,
                    "El alias {$alias} está en {$canonical} y en " . ($seen[$alias] ?? '?') . '.'
                );

                $seen[$alias] = $canonical;
            }
        }
    }
}
