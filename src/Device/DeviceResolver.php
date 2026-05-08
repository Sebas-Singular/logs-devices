<?php

declare(strict_types=1);

namespace App\Device;

use DateTimeImmutable;
use PDO;
use PDOException;

// =============================================================================
// DeviceResolver.php — Resuelve o crea dispositivos en la tabla devices
// =============================================================================
//
// Dos métodos públicos:
//   resolveOrCreateBridge() → para el bridge que envía el POST
//   resolveOrCreateBeacon() → para cada baliza mencionada en las líneas
//
// Ambos siguen el mismo patrón:
//   1. SELECT para buscar el dispositivo existente
//   2. UPDATE last_seen_at si se encontró
//   3. INSERT si no existe (con try/catch por si hay race condition)
//   4. Devolver el id del dispositivo (nuevo o existente)
//
// El método NO decide si fusionar dispositivos sospechosos de ser el mismo
// hardware reemplazado. Esa decisión es manual (fase 6).
// =============================================================================

final class DeviceResolver
{
    public function __construct(private readonly PDO $pdo)
    {
        // Inyectamos la conexión en lugar de crearla aquí porque:
        // - ingest.php ya tiene una conexión abierta: la reutilizamos
        // - los tests pueden inyectar una conexión de test
    }

    // -------------------------------------------------------------------------
    // resolveOrCreateBridge
    // -------------------------------------------------------------------------
    // Busca un bridge por external_id (= bridgeId del payload).
    // Si no existe lo crea. Siempre actualiza last_seen_at.
    //
    // Parámetros:
    //   $bridgeIdReported → el bridgeId del payload: '11', '115', '120'
    //   $bridgeName       → el bridgeName del payload (puede ser '')
    //   $seenAt           → timestamp de recepción del POST
    //
    // Retorna el id (BIGINT) del bridge en la tabla devices.
    // -------------------------------------------------------------------------
    public function resolveOrCreateBridge(
        string $bridgeIdReported,
        string $bridgeName,
        DateTimeImmutable $seenAt
    ): int {
        $seenAtSql = $seenAt->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'SELECT id
               FROM devices
              WHERE device_kind = :kind
                AND external_id = :external_id
              LIMIT 1'
        );

        $stmt->execute([
            'kind'        => 'bridge',
            'external_id' => $bridgeIdReported,
        ]);

        $existing = $stmt->fetch();

        if ($existing !== false) {
            $this->updateLastSeenAt((int) $existing['id'], $seenAtSql);
            return (int) $existing['id'];
        }

        $name = $bridgeName !== ''
            ? $bridgeName
            : 'Bridge ' . $bridgeIdReported;

        return $this->insertDevice([
            'device_kind'      => 'bridge',
            'mac_address'      => null,   
            'external_id'      => $bridgeIdReported,
            'name'             => $name,
            'name_origin'      => 'reported',
            'parent_device_id' => null,
            'first_seen_at'    => $seenAtSql,
            'last_seen_at'     => $seenAtSql,
        ]);
    }

    // -------------------------------------------------------------------------
    // resolveOrCreateBeacon
    // -------------------------------------------------------------------------
    // Busca una baliza en este orden de prioridad:
    //   1. Por mac_address (clave natural más fiable)
    //   2. Por external_id + parent_device_id (fallback si no hay MAC)
    //
    // Si no existe la crea. Siempre actualiza last_seen_at.
    // Si la baliza existía pero el nombre cambió y el origen era 'reported',
    // actualiza el nombre (los nombres de balizas pueden cambiar en el firmware).
    //
    // Parámetros:
    //   $mac            → MAC normalizada a lowercase: '34:85:18:46:e3:1c'
    //   $externalId     → id=NN del body TELEMETRY: '01', '03' (puede ser null)
    //   $name           → name='...' del body TELEMETRY (puede ser null)
    //   $bridgeDeviceId → id del bridge padre en devices (ya resuelto)
    //   $seenAt         → timestamp del evento (firmware o received como fallback)
    //
    // Retorna el id (BIGINT) de la baliza en la tabla devices.
    // -------------------------------------------------------------------------
    public function resolveOrCreateBeacon(
        string $mac,
        ?string $externalId,
        ?string $name,
        int $bridgeDeviceId,
        DateTimeImmutable $seenAt
    ): int {
        $seenAtSql = $seenAt->format('Y-m-d H:i:s');

        $existing = $this->findBeaconByMac($mac);

        if ($existing === false && $externalId !== null && $externalId !== '') {
            $existing = $this->findBeaconByExternalId($externalId, $bridgeDeviceId);
        }

        if ($existing !== false) {
            $deviceId = (int) $existing['id'];

            $this->updateLastSeenAt($deviceId, $seenAtSql);

            if (
                $name !== null
                && $name !== ''
                && $name !== $existing['name']
                && $existing['name_origin'] === 'reported'
            ) {
                $this->updateName($deviceId, $name);
            }

            return $deviceId;
        }

        $resolvedName = ($name !== null && $name !== '')
            ? $name
            : 'Baliza ' . ($externalId ?? $mac);

        return $this->insertDevice([
            'device_kind'      => 'baliza',
            'mac_address'      => $mac,
            'external_id'      => $externalId,
            'name'             => $resolvedName,
            'name_origin'      => 'reported',
            'parent_device_id' => $bridgeDeviceId,
            'first_seen_at'    => $seenAtSql,
            'last_seen_at'     => $seenAtSql,
        ]);
    }

    // =========================================================================
    // Métodos privados
    // =========================================================================

    private function findBeaconByMac(string $mac): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, name_origin
               FROM devices
              WHERE device_kind = :kind
                AND mac_address = :mac
              LIMIT 1'
        );

        $stmt->execute([
            'kind' => 'baliza',
            'mac'  => $mac,
        ]);

        return $stmt->fetch();
    }

    private function findBeaconByExternalId(string $externalId, int $parentDeviceId): array|false
    {
        $normalized = ltrim($externalId, '0') ?: '0';
        $padded     = str_pad($externalId, 2, '0', STR_PAD_LEFT);

        $stmt = $this->pdo->prepare(
            'SELECT id, name, name_origin
               FROM devices
              WHERE device_kind        = :kind
                AND parent_device_id   = :parent_id
                AND external_id       IN (:raw, :normalized, :padded)
              LIMIT 1'
        );

        $stmt->execute([
            'kind'       => 'baliza',
            'parent_id'  => $parentDeviceId,
            'raw'        => $externalId,
            'normalized' => $normalized,
            'padded'     => $padded,
        ]);

        return $stmt->fetch();
    }

    private function updateLastSeenAt(int $deviceId, string $seenAtSql): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE devices
                SET last_seen_at = :last_seen_at,
                    updated_at   = :updated_at
            WHERE id = :id'
        );

        $stmt->execute([
            'last_seen_at' => $seenAtSql,
            'updated_at'   => $seenAtSql,
            'id'           => $deviceId,
        ]);
    }

    private function updateName(int $deviceId, string $name): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE devices
                SET name       = :name,
                    updated_at = NOW()
              WHERE id = :id
                AND name_origin = :origin'
        );

        $stmt->execute([
            'name'   => $name,
            'id'     => $deviceId,
            'origin' => 'reported',
        ]);
    }

    // -------------------------------------------------------------------------
    // insertDevice
    // -------------------------------------------------------------------------
    // Inserta un nuevo dispositivo y devuelve su id.
    // Si hay una colisión en mac_address (race condition entre dos requests
    // concurrentes del mismo bridge), captura el error y hace SELECT para
    // devolver el id del registro que ganó la carrera.
    // -------------------------------------------------------------------------
    private function insertDevice(array $fields): int
    {
        $fields['created_at'] = $fields['first_seen_at'];
        $fields['updated_at'] = $fields['first_seen_at'];        
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO devices (
                    device_kind,
                    mac_address,
                    external_id,
                    name,
                    name_origin,
                    parent_device_id,
                    first_seen_at,
                    last_seen_at,
                    is_active,
                    created_at,
                    updated_at
                ) VALUES (
                    :device_kind,
                    :mac_address,
                    :external_id,
                    :name,
                    :name_origin,
                    :parent_device_id,
                    :first_seen_at,
                    :last_seen_at,
                    1,
                    :created_at,
                    :updated_at
                )'
            );

            $stmt->execute($fields);

            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if (str_starts_with($e->getCode(), '23')) {
                $row = $this->findExistingAfterRace($fields);

                if ($row !== false) {
                    $deviceId = (int) $row['id'];
                    $this->updateLastSeenAt($deviceId, (string) $fields['last_seen_at']);

                    return $deviceId;
                }
            }

            throw $e;
        }
    }

    private function findExistingAfterRace(array $fields): array|false
    {
        if (($fields['device_kind'] ?? null) === 'baliza') {
            if (($fields['mac_address'] ?? null) !== null) {
                return $this->findBeaconByMac((string) $fields['mac_address']);
            }

            if (
                ($fields['external_id'] ?? null) !== null
                && ($fields['parent_device_id'] ?? null) !== null
            ) {
                return $this->findBeaconByExternalId(
                    (string) $fields['external_id'],
                    (int) $fields['parent_device_id']
                );
            }
        }

        if (
            ($fields['device_kind'] ?? null) === 'bridge'
            && ($fields['external_id'] ?? null) !== null
        ) {
            $stmt = $this->pdo->prepare(
                'SELECT id, name, name_origin
                FROM devices
                WHERE device_kind = :kind
                AND external_id = :external_id
                LIMIT 1'
            );

            $stmt->execute([
                'kind' => 'bridge',
                'external_id' => $fields['external_id'],
            ]);

            return $stmt->fetch();
        }

        return false;
    }
}