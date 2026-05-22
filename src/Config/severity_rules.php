<?php

declare(strict_types=1);

// =============================================================================
// severity_rules.php — Configuración de reglas de derivación de severidad
// =============================================================================
//
// Este fichero es el único lugar donde se definen qué anomaly_flags producen
// qué severidad. Para cambiar un umbral de negocio, se edita solo aquí.
// Los parsers detectan los flags, este fichero decide su peso.
//
// Formato: 'nombre_del_flag' => 'severidad_resultante'
// Severidades válidas: 'warn', 'error', 'critical'
// (Los flags no listados aquí no modifican la severidad base del firmware)
//
// Regla de prioridad: si un evento tiene varios flags, gana el más grave.
// Orden: critical > error > warn > info
// =============================================================================

return [
    'unsynced_timestamp'   => 'warn',
    'zero_sensor_payload'  => 'warn',
    'out_of_range_pressure'=> 'warn',
    'out_of_range_altitude'=> 'warn',
    'low_soc'              => 'warn',
    'weak_rssi'            => 'warn',
    'improbable_speed'     => 'warn',
    'placeholder_name'     => 'info',   
];