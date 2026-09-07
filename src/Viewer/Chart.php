<?php

declare(strict_types=1);

namespace App\Viewer;

/**
 * Gráficos SVG generados en servidor.
 *
 * Sin librería ni CDN: el visor ya renderiza en PHP y estos gráficos son
 * suficientemente simples como para no arrastrar una dependencia de JavaScript
 * por ellos. El hover lo da <title>, que el navegador muestra de forma nativa.
 *
 * Decisiones de forma (una serie por gráfico):
 *   - Apilar severidades en una sola barra se descartó: los colores de estado
 *     ámbar y rojo quedan a ΔE 2.8 en deuteranopia, es decir, indistinguibles.
 *     Dos gráficos de una serie cada uno comparten eje y no necesitan leyenda.
 *   - Marcas finas, ejes de un tono sobre el fondo, sin rejilla discontinua.
 *   - Etiquetas selectivas: solo el máximo y los extremos del eje X.
 */
final class Chart
{
    /** Radio de los extremos redondeados de la barra. */
    private const BAR_RADIUS = 4.0;

    /** Separación entre barras contiguas, en px del viewBox. */
    private const BAR_GAP = 2.0;

    /**
     * Barras verticales de una sola serie.
     *
     * @param list<array{label: string, value: float|int, tooltip?: string}> $points
     * @param array{color?: string, height?: int, width?: int, unit?: string} $options
     */
    public static function bars(array $points, array $options = []): string
    {
        $points = array_values($points);

        if ($points === []) {
            return '';
        }

        $color = (string) ($options['color'] ?? '#E40D7E');
        // El SVG se estira al ancho del contenedor. Con un viewBox estrecho el
        // factor de escala deforma los extremos redondeados, así que se parte de
        // un ancho cercano al real y la distorsión queda despreciable.
        $width = (int) ($options['width'] ?? 1200);
        $plotHeight = (int) ($options['height'] ?? 96);
        $unit = (string) ($options['unit'] ?? '');

        // La caja incluye la banda del eje X: si no, las etiquetas quedan
        // cortadas o fuerzan un scroll interno en la tarjeta.
        $labelBand = 16;
        $topBand = 14; // hueco para la etiqueta del máximo
        $height = $topBand + $plotHeight + $labelBand;
        $baseline = $topBand + $plotHeight;

        $values = array_map(static fn (array $p): float => (float) $p['value'], $points);
        $max = max($values);
        $maxIndex = (int) array_search($max, $values, true);

        $slot = $width / count($points);
        $barWidth = max(1.0, $slot - self::BAR_GAP);

        $svg = [];

        foreach ($points as $index => $point) {
            $value = (float) $point['value'];
            $barHeight = $max > 0 ? ($value / $max) * $plotHeight : 0.0;

            $x = ($index * $slot) + (self::BAR_GAP / 2);
            $y = $baseline - $barHeight;

            $tooltip = (string) ($point['tooltip'] ?? ($point['label'] . ': ' . self::formatNumber($value) . $unit));

            if ($barHeight <= 0.0) {
                // Intervalo sin eventos: no se dibuja marca. Una fila de marcas
                // mínimas a lo largo del eje se lee como rejilla discontinua, que
                // es ruido. Se deja solo una zona transparente para que el hueco
                // siga teniendo su tooltip.
                $svg[] = sprintf(
                    '<rect x="%s" y="%s" width="%s" height="%s" fill="transparent"><title>%s</title></rect>',
                    self::n($x),
                    self::n($topBand),
                    self::n($barWidth),
                    self::n($plotHeight),
                    self::e($tooltip)
                );
                continue;
            }

            $svg[] = sprintf(
                '<path d="%s" fill="%s"><title>%s</title></path>',
                self::e(self::roundedTopBar($x, $y, $barWidth, $barHeight, $baseline)),
                self::e($color),
                self::e($tooltip)
            );
        }

        // Línea base: sólida y de un tono sobre el fondo, nunca discontinua.
        $svg[] = sprintf(
            '<line x1="0" y1="%s" x2="%s" y2="%s" stroke="#E2E2E1" stroke-width="1"/>',
            self::n($baseline + 0.5),
            self::n($width),
            self::n($baseline + 0.5)
        );

        // Etiqueta directa solo en el máximo.
        if ($max > 0) {
            $anchorX = ($maxIndex * $slot) + ($slot / 2);
            $textAnchor = 'middle';

            if ($anchorX < 20) {
                $textAnchor = 'start';
                $anchorX = 0;
            } elseif ($anchorX > $width - 20) {
                $textAnchor = 'end';
                $anchorX = $width;
            }

            $svg[] = sprintf(
                '<text x="%s" y="%s" text-anchor="%s" font-size="10" font-weight="600" fill="#585857">%s</text>',
                self::n($anchorX),
                self::n($topBand - 4),
                $textAnchor,
                self::e(self::formatNumber($max) . $unit)
            );
        }

        // Extremos del eje X. Solo dos etiquetas: más colisionan.
        $first = (string) $points[0]['label'];
        $last = (string) $points[count($points) - 1]['label'];

        $svg[] = sprintf(
            '<text x="0" y="%s" text-anchor="start" font-size="10" fill="#9A9A99">%s</text>',
            self::n($height - 3),
            self::e($first)
        );

        if (count($points) > 1) {
            $svg[] = sprintf(
                '<text x="%s" y="%s" text-anchor="end" font-size="10" fill="#9A9A99">%s</text>',
                self::n($width),
                self::n($height - 3),
                self::e($last)
            );
        }

        return sprintf(
            '<svg viewBox="0 0 %s %s" width="100%%" height="%s" preserveAspectRatio="none" role="img" aria-label="%s" style="display:block">%s</svg>',
            self::n($width),
            self::n($height),
            self::n($height),
            self::e($options['aria'] ?? 'Serie temporal'),
            implode('', $svg)
        );
    }

    /**
     * Línea de tendencia compacta para una tarjeta de métrica.
     *
     * Trazo en tono de baja emphasis y el valor actual en el color de marca,
     * que es lo que interesa señalar. Con menos de tres lecturas no se dibuja:
     * dos puntos no son una tendencia.
     *
     * @param list<float|int> $values de más antiguo a más reciente
     */
    public static function sparkline(array $values, array $options = []): string
    {
        $values = array_values(array_map('floatval', $values));

        if (count($values) < 3) {
            return '';
        }

        $width = (int) ($options['width'] ?? 120);
        $height = (int) ($options['height'] ?? 28);
        $lineColor = (string) ($options['line'] ?? '#C4C4C4');
        $accent = (string) ($options['accent'] ?? '#E40D7E');

        $min = min($values);
        $max = max($values);
        $span = $max - $min;

        $padding = 3.0;
        $usable = $height - (2 * $padding);
        $step = count($values) > 1 ? $width / (count($values) - 1) : 0.0;

        $coords = [];

        foreach ($values as $index => $value) {
            // Serie plana: se dibuja centrada en vez de pegada a un borde.
            $ratio = $span > 0 ? ($value - $min) / $span : 0.5;

            $coords[] = [
                $index * $step,
                $padding + ($usable * (1 - $ratio)),
            ];
        }

        $path = [];

        foreach ($coords as $index => [$x, $y]) {
            $path[] = ($index === 0 ? 'M' : 'L') . self::n($x) . ',' . self::n($y);
        }

        [$lastX, $lastY] = $coords[count($coords) - 1];

        return sprintf(
            '<svg viewBox="0 0 %s %s" width="%s" height="%s" role="img" aria-label="%s" style="display:block">'
                . '<path d="%s" fill="none" stroke="%s" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
                . '<circle cx="%s" cy="%s" r="2.5" fill="%s"/>'
                . '</svg>',
            self::n($width),
            self::n($height),
            self::n($width),
            self::n($height),
            self::e($options['aria'] ?? 'Tendencia de las últimas lecturas'),
            self::e(implode(' ', $path)),
            self::e($lineColor),
            self::n($lastX),
            self::n($lastY),
            self::e($accent)
        );
    }

    /**
     * Barra con los dos extremos superiores redondeados, anclada a la base.
     */
    private static function roundedTopBar(float $x, float $y, float $width, float $height, float $baseline): string
    {
        $radius = min(self::BAR_RADIUS, $width / 2, $height);

        return sprintf(
            'M %s %s V %s Q %s %s %s %s H %s Q %s %s %s %s V %s Z',
            self::n($x),
            self::n($baseline),
            self::n($y + $radius),
            self::n($x),
            self::n($y),
            self::n($x + $radius),
            self::n($y),
            self::n($x + $width - $radius),
            self::n($x + $width),
            self::n($y),
            self::n($x + $width),
            self::n($y + $radius),
            self::n($baseline)
        );
    }

    private static function formatNumber(float $value): string
    {
        return $value == (int) $value
            ? number_format($value, 0, ',', '.')
            : number_format($value, 1, ',', '.');
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
