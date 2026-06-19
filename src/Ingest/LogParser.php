<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Device\DeviceResolver;
use App\Parsers\BaseLineParser;
use App\Parsers\SeverityDeriver;
use App\Parsers\SpeedParser;
use App\Parsers\TelemetryParser;
use DateTimeImmutable;

// =============================================================================
// LogParser.php — Orquestador del parseo de un ingest completo
// =============================================================================
//
// Recibe el logText de un payload + IDs del ingest y del bridge ya resueltos,
// y devuelve un array de eventos listos para insertar en log_events.
//
// IMPORTANTE: este orquestador NO inserta en log_events. La inserción es
// responsabilidad de quien le llame (ingest.php inline o el script de
// reproceso histórico). Esto facilita los tests y permite que la misma
// lógica sirva para ambos flujos sin duplicación.
//
// Flujo por línea:
//   1. BaseLineParser detecta event_word (telemetry|speed|...)
//   2. Enruta al parser específico (TelemetryParser o SpeedParser)
//   3. Si la línea es válida y tiene MAC → DeviceResolver resuelve/crea la baliza
//   4. SeverityDeriver calcula severidad final
//   5. Se construye el array de evento para log_events
//
// Caché de balizas por payload:
//   Un POST típico contiene líneas de 3-5 balizas distintas pero con ~200
//   líneas. Sin caché habría 200 SELECTs. Con caché se resuelve cada MAC
//   una sola vez por payload. El caché es local al método processIngest()
//   y se descarta al terminar.
// =============================================================================

final class LogParser
{
    public function __construct(
        private readonly DeviceResolver $deviceResolver,
        private readonly SeverityDeriver $severityDeriver,
    ) {
    }

    // -------------------------------------------------------------------------
    // processIngest
    // -------------------------------------------------------------------------
    // Procesa el logText completo de un ingest y devuelve los eventos parseados.
    //
    // Parámetros:
    //   $logText         → campo logText del payload del bridge
    //   $ingestId        → id de la fila log_ingests asociada (ya creada)
    //   $bridgeDeviceId  → id del bridge en devices (ya resuelto)
    //   $receivedAt      → timestamp de recepción del POST (fallback de event_timestamp)
    //
    // Retorna un array con:
    //   'events'        → array de eventos listos para insert en log_events
    //   'parsed_ok'     → contador de líneas parseadas con éxito
    //   'parsed_error'  → contador de líneas con error de parseo
    // -------------------------------------------------------------------------
    public function processIngest(
        string $logText,
        int $ingestId,
        int $bridgeDeviceId,
        DateTimeImmutable $receivedAt
    ): array {
        $events       = [];
        $parsedOk     = 0;
        $parsedError  = 0;

        $beaconCache = [];

        $lines = BaseLineParser::splitRawLines($logText);

        foreach ($lines as $lineNumber => $rawLine) {
            $event = $this->parseSingleLine($rawLine, $lineNumber + 1);

            $deviceId = null;
            if ($event['parse_ok'] && $event['device_mac'] !== null) {
                $deviceId = $this->resolveBeaconCached(
                    cache: $beaconCache,
                    mac: $event['device_mac'],
                    externalId: $event['device_external_id'],
                    name: $event['device_name_reported'],
                    bridgeDeviceId: $bridgeDeviceId,
                    seenAt: $event['event_timestamp'] ?? $receivedAt,
                );
            }

            $derivedSeverity = $this->severityDeriver->derive($event);

            $events[] = $this->buildLogEventRow(
                parsedEvent: $event,
                ingestId: $ingestId,
                deviceId: $deviceId,
                bridgeDeviceId: $bridgeDeviceId,
                receivedAt: $receivedAt,
                derivedSeverity: $derivedSeverity,
            );

            if ($event['parse_ok']) {
                $parsedOk++;
            } else {
                $parsedError++;
            }
        }

        return [
            'events'       => $events,
            'parsed_ok'    => $parsedOk,
            'parsed_error' => $parsedError,
        ];
    }

    // -------------------------------------------------------------------------
    // parseSingleLine
    // -------------------------------------------------------------------------
    // Enruta una línea al parser específico según su event_word.
    //
    // Hacemos primero un parseBaseLine para identificar el tipo. Esto añade
    // una pasada extra de regex por línea respecto a llamar directamente al
    // parser específico, pero a cambio:
    //   - El enrutamiento es robusto (no asumimos tipo por el orden de las líneas).
    //   - Si una línea es desconocida, se preserva como evento 'unknown' sin
    //     perder la información (no se descarta silenciosamente).
    // -------------------------------------------------------------------------
    private function parseSingleLine(string $rawLine, int $lineNumber): array
    {
        $base = BaseLineParser::parseBaseLine($rawLine, $lineNumber);

        if (!$base['parse_ok']) {
            return $base;
        }
        return match ($base['event_word']) {
            'telemetry' => TelemetryParser::parseTelemetryEvent($rawLine, $lineNumber),
            'speed'     => SpeedParser::parseSpeedEvent($rawLine, $lineNumber),
            default     => $this->buildUnknownEventTypeResult($base),
        };
    }

    // -------------------------------------------------------------------------
    // buildUnknownEventTypeResult
    // -------------------------------------------------------------------------
    // Construye un evento cuando event_word no es reconocido. La línea está
    // bien formada (parseBaseLine fue OK) pero el tipo es desconocido para
    // nosotros. Lo guardamos como 'unknown' para no perderlo.
    //
    // Cuando aparezca un nuevo tipo de evento (ej: UNSYNCED standalone),
    // este caso permite detectarlo en producción y saber qué frecuencia tiene
    // antes de escribir su parser.
    // -------------------------------------------------------------------------
    private function buildUnknownEventTypeResult(array $base): array
    {
        return [
            'line_number'            => $base['line_number'],
            'parse_ok'               => false,
            'parse_error'            => 'unknown_event_word:' . $base['event_word'],
            'severity'               => $base['severity'],
            'event_type'             => 'unknown',
            'event_category'         => 'unknown',
            'source_tag'             => $base['source_tag'],
            'device_mac'             => $base['device_mac'],
            'device_external_id'     => null,
            'device_name_reported'   => null,
            'event_timestamp'        => null,
            'firmware_timestamp_raw' => null,
            'received_timestamp_raw' => $base['received_timestamp_raw'],
            'measurements'           => [],
            'context'                => [],
            'quality_status'         => 'invalid',
            'anomaly_flags'          => [],
            'message_text'           => $base['message_text'],
            'raw_line'               => $base['raw_line'],
        ];
    }

    // -------------------------------------------------------------------------
    // resolveBeaconCached
    // -------------------------------------------------------------------------
    // Resuelve una baliza usando un caché local al payload.
    //
    // Por qué se cachea por MAC y no por (MAC, externalId): la MAC es la clave
    // natural de la baliza. Una baliza no cambia de MAC en mitad de un payload.
    // Si la primera vez que aparece tiene externalId y la segunda no (caso de
    // líneas SPEED que no llevan id=), el caché por MAC sigue siendo correcto:
    // ya resolvimos la baliza por su MAC, no necesitamos volver a resolverla.
    // -------------------------------------------------------------------------
    private function resolveBeaconCached(
        array &$cache,
        string $mac,
        ?string $externalId,
        ?string $name,
        int $bridgeDeviceId,
        DateTimeImmutable $seenAt
    ): int {
        if (isset($cache[$mac])) {
            return $cache[$mac];
        }

        $deviceId = $this->deviceResolver->resolveOrCreateBeacon(
            mac: $mac,
            externalId: $externalId,
            name: $name,
            bridgeDeviceId: $bridgeDeviceId,
            seenAt: $seenAt,
        );

        $cache[$mac] = $deviceId;

        return $deviceId;
    }

    // -------------------------------------------------------------------------
    // buildLogEventRow
    // -------------------------------------------------------------------------
    // Construye el array con la forma exacta que necesita un INSERT en
    // log_events. Quien lo recibe (ingest.php o reproceso) lo usa directamente
    // como parámetros de un prepared statement.
    //
    // event_timestamp:
    //   Si el parser devolvió un DateTimeImmutable lo usamos.
    //   Si no (líneas malformadas, sin timestamp), usamos receivedAt como
    //   fallback. Nunca dejamos event_timestamp en NULL: la columna es NOT NULL
    //   en el schema y queremos poder consultar por fecha siempre.
    // -------------------------------------------------------------------------
    private function buildLogEventRow(
        array $parsedEvent,
        int $ingestId,
        ?int $deviceId,
        int $bridgeDeviceId,
        DateTimeImmutable $receivedAt,
        array $derivedSeverity
    ): array {
        $eventTimestamp = $parsedEvent['event_timestamp'] instanceof DateTimeImmutable
            ? $parsedEvent['event_timestamp']
            : $receivedAt;

        return [
            'ingest_id'        => $ingestId,
            'device_id'        => $deviceId,             
            'bridge_device_id' => $bridgeDeviceId,
            'event_timestamp'  => $eventTimestamp->format('Y-m-d H:i:s'),
            'received_at'      => $receivedAt->format('Y-m-d H:i:s'),
            'severity'         => $derivedSeverity['severity'],
            'severity_origin'  => $derivedSeverity['severity_origin'],
            'event_type'       => $parsedEvent['event_type'],
            'event_category'   => $parsedEvent['event_category'],
            'device_mac_raw'   => $parsedEvent['device_mac'],
            'message_text'     => $parsedEvent['message_text'],
            'measurements'     => $this->jsonForColumn($parsedEvent['measurements'] ?? []),
            'context'          => $this->jsonForColumn($parsedEvent['context'] ?? []),
            'parse_ok'         => $parsedEvent['parse_ok'] ? 1 : 0,
            'parse_error'      => $parsedEvent['parse_error'],
            'quality_status'   => $parsedEvent['quality_status'],
            'anomaly_flags'    => $this->jsonForColumn($parsedEvent['anomaly_flags'] ?? []),
            'event_hash'       => $this->computeEventHash($parsedEvent, $ingestId),
            'created_at'       => $receivedAt->format('Y-m-d H:i:s'),
        ];
    }

    // -------------------------------------------------------------------------
    // computeEventHash
    // -------------------------------------------------------------------------
    // SHA-256 que identifica un evento de forma única para deduplicación.
    //
    // El hash NO se calcula sobre la línea entera (eso fallaría si el firmware
    // emite la misma línea con espacios distintos o timestamps en diferente
    // formato). Se calcula sobre un payload normalizado de los campos que
    // identifican el evento:
    //   - event_type, source_tag, device_mac, firmware_timestamp_raw, message_text
    //
    // Para líneas que no se parsearon (parse_ok=false), devolvemos NULL.
    // La columna event_hash es NULL en log_events para eventos malformados:
    // no tiene sentido deduplicar algo cuyo contenido no entendemos.
    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // jsonForColumn
    // -------------------------------------------------------------------------
    // Codifica un valor para una columna JSON tolerando UTF-8 inválido. Sin la
    // sustitución, un byte corrupto haría que json_encode devolviese false, lo
    // que se insertaría como cadena vacía en una columna JSON (valor inválido)
    // y reventaría el INSERT. Devolvemos siempre JSON válido ('null' como
    // último recurso).
    // -------------------------------------------------------------------------
    private function jsonForColumn(mixed $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $encoded !== false ? $encoded : 'null';
    }

    private function computeEventHash(array $parsedEvent, int $ingestId): ?string
    {
        if (!$parsedEvent['parse_ok']) {
            return null;
        }

        $payload = [
            'ingest_id'       => $ingestId,
            'line_number'     => $parsedEvent['line_number'],
            'source_tag'      => $parsedEvent['source_tag'],
            'event_type'      => $parsedEvent['event_type'],
            'device_mac'      => $parsedEvent['device_mac'],
            'firmware_ts_raw' => $parsedEvent['firmware_timestamp_raw'],
            'message_text'    => $parsedEvent['message_text'],
        ];
        ksort($payload);

        $serialized = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', (string) $serialized);
    }
}