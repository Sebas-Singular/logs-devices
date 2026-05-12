<?php

declare(strict_types=1);

namespace App\Ingest;

use DateTimeImmutable;
use PDO;

final class LogEventWriter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insertEvents(array $events): int
    {
        if ($events === []) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO log_events (
                ingest_id, device_id, bridge_device_id,
                event_timestamp, received_at,
                severity, severity_origin,
                event_type, event_category,
                device_mac_raw, message_text,
                measurements, context,
                parse_ok, parse_error,
                quality_status, anomaly_flags,
                event_hash, created_at
            ) VALUES (
                :ingest_id, :device_id, :bridge_device_id,
                :event_timestamp, :received_at,
                :severity, :severity_origin,
                :event_type, :event_category,
                :device_mac_raw, :message_text,
                :measurements, :context,
                :parse_ok, :parse_error,
                :quality_status, :anomaly_flags,
                :event_hash, :created_at
            )
            ON DUPLICATE KEY UPDATE id = id'
        );

        $inserted = 0;

        foreach ($events as $event) {
            $stmt->execute($this->normalizeEvent($event));

            if ($stmt->rowCount() === 1) {
                $inserted++;
            }
        }

        return $inserted;
    }

    private function normalizeEvent(array $event): array
    {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        return [
            'ingest_id'        => $event['ingest_id'],
            'device_id'        => $event['device_id'],
            'bridge_device_id' => $event['bridge_device_id'],

            'event_timestamp'  => $event['event_timestamp'],
            'received_at'      => $event['received_at'],

            'severity'         => $event['severity'],
            'severity_origin'  => $event['severity_origin'],

            'event_type'       => $event['event_type'],
            'event_category'   => $event['event_category'],

            'device_mac_raw'   => $event['device_mac_raw'],
            'message_text'     => $event['message_text'],

            'measurements'     => $event['measurements'],
            'context'          => $event['context'],

            'parse_ok'         => $event['parse_ok'],
            'parse_error'      => $event['parse_error'],

            'quality_status'   => $event['quality_status'],
            'anomaly_flags'    => $event['anomaly_flags'],

            'event_hash'       => $event['event_hash'],
            'created_at'       => $event['created_at'] ?? $event['received_at'] ?? $now,
        ];
    }
}