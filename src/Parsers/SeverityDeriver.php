<?php

declare(strict_types=1);

namespace App\Parsers;

// =============================================================================
// SeverityDeriver.php — Aplica las reglas de severidad a un evento parseado
// =============================================================================
//
// Recibe el array de un evento ya parseado (output de TelemetryParser o
// SpeedParser) y devuelve la severidad final y su origen.
//
// Por qué está separado de los parsers:
//   - Los parsers detectan hechos objetivos (flags de anomalía).
//   - Este servicio aplica política de negocio (qué peso tiene cada hecho).
//   - Si mañana decidimos que 'low_soc' es 'error' en lugar de 'warn',
//     solo cambiamos severity_rules.php, no tocamos los parsers ni sus tests.
// =============================================================================

final class SeverityDeriver
{
    private const SEVERITY_ORDER = [
        'info'     => 0,
        'warn'     => 1,
        'error'    => 2,
        'critical' => 3,
    ];

    private array $rules;

    public function __construct()
    {
        $this->rules = require __DIR__ . '/../Config/severity_rules.php';
    }

    // -------------------------------------------------------------------------
    // derive
    // -------------------------------------------------------------------------
    // Recibe un array de evento parseado y devuelve un array con dos claves:
    //   'severity'        → la severidad final ('info', 'warn', 'error', 'critical')
    //   'severity_origin' → 'reported' si viene del firmware, 'derived' si la
    //                       calculamos nosotros
    //
    // Casos:
    //   1. parse_ok = false → siempre 'error' + 'derived'
    //   2. anomaly_flags vacío → se respeta la severidad del firmware + 'reported'
    //   3. anomaly_flags no vacío → se calcula la severidad máxima de los flags
    //      y se compara con la del firmware; gana la más grave + 'derived'
    // -------------------------------------------------------------------------
    public function derive(array $parsedEvent): array
    {
        if (!($parsedEvent['parse_ok'] ?? false)) {
            return [
                'severity'        => 'error',
                'severity_origin' => 'derived',
            ];
        }

        $firmwareSeverity = $parsedEvent['severity'] ?? 'info';
        $anomalyFlags     = $parsedEvent['anomaly_flags'] ?? [];

        if ($anomalyFlags === []) {
            return [
                'severity'        => $firmwareSeverity,
                'severity_origin' => 'reported',
            ];
        }

        $derivedSeverity = $firmwareSeverity;

        foreach ($anomalyFlags as $flag) {
            $flagSeverity = $this->rules[$flag] ?? null;

            if ($flagSeverity === null) {
                continue;
            }

            $currentOrder = self::SEVERITY_ORDER[$derivedSeverity] ?? 0;
            $flagOrder    = self::SEVERITY_ORDER[$flagSeverity] ?? 0;

            if ($flagOrder > $currentOrder) {
                $derivedSeverity = $flagSeverity;
            }
        }

        $origin = $derivedSeverity !== $firmwareSeverity ? 'derived' : 'reported';

        return [
            'severity'        => $derivedSeverity,
            'severity_origin' => $origin,
        ];
    }
}