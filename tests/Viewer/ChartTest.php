<?php

declare(strict_types=1);

namespace Tests\Viewer;

use App\Viewer\Chart;
use PHPUnit\Framework\TestCase;

// =============================================================================
// ChartTest — geometría y seguridad del renderizador SVG
// =============================================================================

final class ChartTest extends TestCase
{
    private function points(array $values): array
    {
        return array_map(
            static fn (int $i, float|int $v): array => ['label' => 'b' . $i, 'value' => $v],
            array_keys($values),
            $values
        );
    }

    public function testBarsReturnsNothingWithoutPoints(): void
    {
        $this->assertSame('', Chart::bars([]));
    }

    public function testBarsDrawsOneMarkPerPoint(): void
    {
        $svg = Chart::bars($this->points([3, 7, 1]));

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertSame(3, substr_count($svg, '<title>'));
    }

    public function testEmptyIntervalsKeepTheirTooltipWithoutDrawingAMark(): void
    {
        // Un intervalo sin eventos es información y debe poder consultarse, pero
        // pintarle una marca mínima crea una fila que se lee como rejilla
        // discontinua: se deja solo la zona transparente de hover.
        $svg = Chart::bars($this->points([5, 0, 2]));

        $this->assertSame(3, substr_count($svg, '<title>'));
        $this->assertSame(2, substr_count($svg, '<path'));
        $this->assertStringContainsString('fill="transparent"', $svg);
    }

    public function testBarsLabelsOnlyTheMaximum(): void
    {
        $svg = Chart::bars($this->points([2, 9, 4]));

        // Una etiqueta para el máximo y dos para los extremos del eje X.
        $this->assertSame(3, substr_count($svg, '<text'));
        $this->assertStringContainsString('>9<', $svg);
        $this->assertStringNotContainsString('>4<', $svg);
    }

    public function testBarsHeightIncludesTheAxisBand(): void
    {
        // Si la caja no incluye la banda de etiquetas, la tarjeta acaba con un
        // scroll interno o las etiquetas cortadas.
        $svg = Chart::bars($this->points([1, 2]), ['height' => 96]);

        // 96 de zona de trazado + 14 de la etiqueta del máximo + 16 del eje X.
        $this->assertSame(1, preg_match('/viewBox="0 0 \d+ (\d+)"/', $svg, $m));
        $this->assertSame('126', $m[1]);
    }

    public function testBarsUsesSolidBaselineNeverDashed(): void
    {
        $svg = Chart::bars($this->points([1, 2]));

        $this->assertStringContainsString('<line', $svg);
        $this->assertStringNotContainsString('stroke-dasharray', $svg);
    }

    public function testBarsEscapesLabelsAndTooltips(): void
    {
        $svg = Chart::bars([
            ['label' => '<script>', 'value' => 1, 'tooltip' => 'a "b" & <c>'],
        ]);

        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringContainsString('&lt;script&gt;', $svg);
        $this->assertStringContainsString('&amp;', $svg);
    }

    public function testBarsSurvivesAnAllZeroSeries(): void
    {
        $svg = Chart::bars($this->points([0, 0, 0]));

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertSame(3, substr_count($svg, '<title>'));
        $this->assertStringNotContainsString('<path', $svg);
    }

    public function testSparklineNeedsAtLeastThreePoints(): void
    {
        // Dos lecturas no son una tendencia.
        $this->assertSame('', Chart::sparkline([]));
        $this->assertSame('', Chart::sparkline([1.0]));
        $this->assertSame('', Chart::sparkline([1.0, 2.0]));
        $this->assertNotSame('', Chart::sparkline([1.0, 2.0, 3.0]));
    }

    public function testSparklineMarksTheCurrentValue(): void
    {
        $svg = Chart::sparkline([1, 5, 3]);

        $this->assertStringContainsString('<path', $svg);
        $this->assertStringContainsString('<circle', $svg);
        $this->assertStringContainsString('#E40D7E', $svg);
    }

    public function testSparklineHandlesAFlatSeries(): void
    {
        // Sin recorrido no se puede normalizar: la línea va centrada, no pegada
        // a un borde, y sobre todo no se divide por cero.
        $svg = Chart::sparkline([4, 4, 4, 4]);

        $this->assertStringContainsString('<path', $svg);
        $this->assertStringNotContainsString('NAN', strtoupper($svg));
        $this->assertStringNotContainsString('INF', strtoupper($svg));
    }

    public function testSparklineProducesNoInvalidCoordinates(): void
    {
        $svg = Chart::sparkline([0, -5, 12.5, 3]);

        // Solo se inspeccionan las coordenadas del path: buscar 'e-' en todo el
        // SVG daría falsos positivos con 'stroke-linecap' y compañía.
        $this->assertSame(1, preg_match('/ d="([^"]+)"/', $svg, $matches));

        $this->assertSame(
            0,
            preg_match('/nan|inf|e[+-]/i', $matches[1]),
            'Coordenadas no numéricas o en notación científica: ' . $matches[1]
        );

        foreach (preg_split('/[ ,ML]+/', trim($matches[1]), -1, PREG_SPLIT_NO_EMPTY) as $number) {
            $this->assertTrue(is_numeric($number), "Coordenada no numérica: {$number}");
        }
    }
}
