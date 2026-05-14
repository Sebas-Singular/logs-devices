<?php

declare(strict_types=1);

namespace App\Ingest;

use DateTimeImmutable;
use PDO;

final class LogEventWriter
{
    private const INSERT_CHUNK_SIZE = 100;

    public function __construct(private readonly PDO $pdo) {}

    public function insertEvents(array $events): int
    {
        if ($events === []) {
            return 0;
        }

        $inserted = 0;

        foreach (array_chunk($events, self::INSERT_CHUNK_SIZE) as $chunk) {
            $rows = array_map(fn(array $event): array => $this->normalizeEvent($event), $chunk);
            $inserted += $this->insertChunk($rows);
        }

        return $inserted;
    }

    private function insertChunk(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = [
            'ingest_id',
            'device_id',
            'bridge_device_id',
            'event_timestamp',
            'received_at',
            'severity',
            'severity_origin',
            'event_type',
            'event_category',
            'device_mac_raw',
            'message_text',
            'measurements',
            'context',
            'parse_ok',
            'parse_error',
            'quality_status',
            'anomaly_flags',
            'event_hash',
            'created_at',
        ];

        $valuesSql = [];
        $params = [];

        foreach ($rows as $rowIndex => $row) {
            $placeholders = [];

            foreach ($columns as $column) {
                $paramName = $column . '_' . $rowIndex;
                $placeholders[] = ':' . $paramName;
                $params[$paramName] = $row[$column];
            }

            $valuesSql[] = '(' . implode(', ', $placeholders) . ')';
        }

        $sql = 'INSERT INTO log_events (
                ingest_id, device_id, bridge_device_id,
                event_timestamp, received_at,
                severity, severity_origin,
                event_type, event_category,
                device_mac_raw, message_text,
                measurements, context,
                parse_ok, parse_error,
                quality_status, anomaly_flags,
                event_hash, created_at
            ) VALUES '
            . implode(', ', $valuesSql)
            . ' ON DUPLICATE KEY UPDATE id = id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
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
