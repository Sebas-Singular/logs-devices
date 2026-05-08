<?php

declare(strict_types=1);

namespace Tests\Parsers;

use App\Parsers\SeverityDeriver;
use PHPUnit\Framework\TestCase;

final class SeverityDeriverTest extends TestCase
{
    private SeverityDeriver $deriver;

    protected function setUp(): void
    {
        // setUp() se ejecuta antes de cada test. Aquí instanciamos el deriver
        // una vez por test para asegurar estado limpio.
        $this->deriver = new SeverityDeriver();
    }

    // Evento válido sin anomalías: se respeta la severidad del firmware
    public function testDerive_withNoFlags_returnsReportedSeverity(): void
    {
        $event = [
            'parse_ok'      => true,
            'severity'      => 'info',
            'anomaly_flags' => [],
        ];

        $result = $this->deriver->derive($event);

        $this->assertSame('info', $result['severity']);
        $this->assertSame('reported', $result['severity_origin']);
    }

    // Parseo fallido: siempre error derivado
    public function testDerive_withParseFailure_returnsError(): void
    {
        $event = [
            'parse_ok'      => false,
            'severity'      => 'unknown',
            'anomaly_flags' => [],
        ];

        $result = $this->deriver->derive($event);

        $this->assertSame('error', $result['severity']);
        $this->assertSame('derived', $result['severity_origin']);
    }

    // Un flag warn eleva info → warn
    public function testDerive_withWarnFlag_elevatesInfoToWarn(): void
    {
        $event = [
            'parse_ok'      => true,
            'severity'      => 'info',
            'anomaly_flags' => ['low_soc'],
        ];

        $result = $this->deriver->derive($event);

        $this->assertSame('warn', $result['severity']);
        $this->assertSame('derived', $result['severity_origin']);
    }

    // Varios flags warn: resultado sigue siendo warn (no se acumulan)
    public function testDerive_withMultipleWarnFlags_returnsWarn(): void
    {
        $event = [
            'parse_ok'      => true,
            'severity'      => 'info',
            'anomaly_flags' => ['low_soc', 'weak_rssi', 'unsynced_timestamp'],
        ];

        $result = $this->deriver->derive($event);

        $this->assertSame('warn', $result['severity']);
        $this->assertSame('derived', $result['severity_origin']);
    }

    // placeholder_name es 'info' en las reglas: no eleva la severidad
    public function testDerive_withPlaceholderNameOnly_keepsSeverityReported(): void
    {
        $event = [
            'parse_ok'      => true,
            'severity'      => 'info',
            'anomaly_flags' => ['placeholder_name'],
        ];

        $result = $this->deriver->derive($event);

        // La severidad sigue siendo 'info' y el origen 'reported' porque
        // placeholder_name no la elevó
        $this->assertSame('info', $result['severity']);
        $this->assertSame('reported', $result['severity_origin']);
    }

    // Flag desconocido (no está en las reglas): se ignora sin romper
    public function testDerive_withUnknownFlag_ignoresItGracefully(): void
    {
        $event = [
            'parse_ok'      => true,
            'severity'      => 'info',
            'anomaly_flags' => ['future_unknown_flag'],
        ];

        $result = $this->deriver->derive($event);

        $this->assertSame('info', $result['severity']);
    }
}