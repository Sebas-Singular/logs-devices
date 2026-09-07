<?php

declare(strict_types=1);

namespace Tests\Viewer;

use App\Viewer\DashboardQueries;
use PHPUnit\Framework\TestCase;

// =============================================================================
// DashboardWindowTest — resolución de la ventana temporal del panel
// =============================================================================

final class DashboardWindowTest extends TestCase
{
    public function testAllWindowHasNoCutoff(): void
    {
        // Regresión: 'all' declara hours = null a propósito. Resolverlo con
        // `?? ` hacía que ese null cayera al valor por defecto y "todo el
        // histórico" se comportara como la ventana de 7 días.
        $this->assertNull(DashboardQueries::windowStart('all'));
    }

    public function testBoundedWindowsReturnACutoffInThePast(): void
    {
        foreach (['24h' => 24, '7d' => 168, '30d' => 720] as $window => $hours) {
            $start = DashboardQueries::windowStart($window);

            $this->assertNotNull($start, "La ventana {$window} debe tener corte.");

            $delta = time() - strtotime($start);

            $this->assertEqualsWithDelta(
                $hours * 3600,
                $delta,
                60,
                "El corte de {$window} no cae donde debería."
            );
        }
    }

    public function testWindowsAreOrderedFromNarrowestToWidest(): void
    {
        $previous = 0;

        foreach (DashboardQueries::WINDOWS as $key => $definition) {
            if ($definition['hours'] === null) {
                continue;
            }

            $this->assertGreaterThan($previous, $definition['hours'], "Ventana {$key} fuera de orden.");
            $previous = $definition['hours'];
        }
    }

    public function testNormalizeWindowRejectsUnknownValues(): void
    {
        foreach (['', 'pwned', '<script>', '999d', null, 42, []] as $value) {
            $this->assertSame(
                DashboardQueries::DEFAULT_WINDOW,
                DashboardQueries::normalizeWindow($value)
            );
        }
    }

    public function testNormalizeWindowAcceptsEveryDeclaredWindow(): void
    {
        foreach (array_keys(DashboardQueries::WINDOWS) as $window) {
            $this->assertSame($window, DashboardQueries::normalizeWindow($window));
        }
    }

    public function testDefaultWindowIsDeclared(): void
    {
        $this->assertArrayHasKey(DashboardQueries::DEFAULT_WINDOW, DashboardQueries::WINDOWS);
    }

    public function testHumanizeMinutes(): void
    {
        $this->assertSame('—', DashboardQueries::humanizeMinutes(null));
        $this->assertSame('ahora mismo', DashboardQueries::humanizeMinutes(0));
        $this->assertSame('45 min', DashboardQueries::humanizeMinutes(45));
        $this->assertSame('2 h', DashboardQueries::humanizeMinutes(120));
        $this->assertSame('3 d', DashboardQueries::humanizeMinutes(60 * 24 * 3));
    }
}
