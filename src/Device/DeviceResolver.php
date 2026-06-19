<?php

declare(strict_types=1);

namespace App\Device;

use DateTimeImmutable;
use PDO;
use PDOException;

final class DeviceResolver
{
    public function __construct(private readonly PDO $pdo) {}

    // -------------------------------------------------------------------------
    // resolveOrCreateBridge
    // -------------------------------------------------------------------------
    // Nuevos parámetros opcionales:
    //   $mac             → MAC del bridge (source.bridgeMac del payload)
    //   $firmwareVersion → versión de firmware reportada
    //   $serialNumber    → número de serie reportado
    //
    // Si el bridge ya existe y le faltaba MAC/firmware/serial, se actualiza.
    // NUNCA sobreescribe una MAC ya existente (solo rellena si era null).
    // -------------------------------------------------------------------------
    public function resolveOrCreateBridge(
        string $bridgeIdReported,
        string $bridgeName,
        DateTimeImmutable $seenAt,
        ?string $mac = null,
        ?string $firmwareVersion = null,
        ?string $serialNumber = null,
    ): int {
        $seenAtSql = $seenAt->format('Y-m-d H:i:s');

        // Truncar a la longitud de columna antes de buscar/insertar: un valor
        // demasiado largo provoca "Data too long" (22001) → 500. Truncamos antes
        // de la búsqueda para que el lookup y el insert usen el mismo valor.
        $bridgeIdReported = $this->truncate($bridgeIdReported, 50);
        $bridgeName = $this->truncate($bridgeName, 255);

        $stmt = $this->pdo->prepare(
            'SELECT id, name, name_origin, mac_address, metadata
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
            $deviceId = (int) $existing['id'];

            $this->updateLastSeenAt($deviceId, $seenAtSql);

            // Rellenar MAC si el bridge no la tenía y ahora la tenemos
            if (
                $mac !== null
                && $mac !== ''
                && ($existing['mac_address'] === null || $existing['mac_address'] === '')
            ) {
                $this->updateBridgeMac($deviceId, $mac);
            }

            // Actualizar nombre si cambió y el origen es 'reported'
            if (
                $bridgeName !== ''
                && $bridgeName !== $existing['name']
                && $existing['name_origin'] === 'reported'
            ) {
                $this->updateName($deviceId, $bridgeName);
            }

            // Actualizar firmware/serial en metadata si vienen en el payload
            if ($firmwareVersion !== null || $serialNumber !== null) {
                $this->updateBridgeMetadata(
                    $deviceId,
                    (string) $existing['metadata'],
                    $firmwareVersion,
                    $serialNumber
                );
            }

            return $deviceId;
        }

        $name = $bridgeName !== ''
            ? $bridgeName
            : 'Bridge ' . $bridgeIdReported;

        $metadata = $this->buildInitialMetadata($firmwareVersion, $serialNumber);

        return $this->insertDevice([
            'device_kind'      => 'bridge',
            'mac_address'      => ($mac !== null && $mac !== '') ? $mac : null,
            'external_id'      => $bridgeIdReported,
            'name'             => $name,
            'name_origin'      => 'reported',
            'parent_device_id' => null,
            'first_seen_at'    => $seenAtSql,
            'last_seen_at'     => $seenAtSql,
            'metadata'         => $metadata,
        ]);
    }

    // -------------------------------------------------------------------------
    // resolveOrCreateBeacon — sin cambios de lógica
    // -------------------------------------------------------------------------
    public function resolveOrCreateBeacon(
        string $mac,
        ?string $externalId,
        ?string $name,
        int $bridgeDeviceId,
        DateTimeImmutable $seenAt
    ): int {
        $seenAtSql = $seenAt->format('Y-m-d H:i:s');

        // Truncar a la longitud de columna (external_id VARCHAR(50), name
        // VARCHAR(255)) para evitar "Data too long" (22001) → 500.
        if ($externalId !== null) {
            $externalId = $this->truncate($externalId, 50);
        }

        if ($name !== null) {
            $name = $this->truncate($name, 255);
        }

        $existing = $this->findBeaconByMac($mac);

        if ($existing === false && $externalId !== null && $externalId !== '') {
            $existing = $this->findBeaconByExternalId($externalId, $bridgeDeviceId);
        }

        if ($existing !== false) {
            $deviceId = (int) $existing['id'];

            $this->updateLastSeenAt($deviceId, $seenAtSql);

            if ((int) $existing['parent_device_id'] !== $bridgeDeviceId) {
                $this->updateBeaconBridge($deviceId, $bridgeDeviceId);
            }

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

    private function truncate(string $value, int $maxLength): string
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8') <= $maxLength
                ? $value
                : (string) mb_substr($value, 0, $maxLength, 'UTF-8');
        }

        return strlen($value) <= $maxLength ? $value : substr($value, 0, $maxLength);
    }

    private function findBeaconByMac(string $mac): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, name_origin, parent_device_id
           FROM devices
          WHERE device_kind = :kind
            AND mac_address = :mac
          LIMIT 1'
        );

        $stmt->execute(['kind' => 'baliza', 'mac' => $mac]);

        return $stmt->fetch();
    }

    private function findBeaconByExternalId(string $externalId, int $parentDeviceId): array|false
    {
        $normalized = ltrim($externalId, '0') ?: '0';
        $padded     = str_pad($externalId, 2, '0', STR_PAD_LEFT);

        $stmt = $this->pdo->prepare(
            'SELECT id, name, name_origin, parent_device_id
           FROM devices
          WHERE device_kind      = :kind
            AND parent_device_id = :parent_id
            AND external_id     IN (:raw, :normalized, :padded)
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

    /**
     * Rellena la MAC de un bridge solo si actualmente es NULL.
     * Nunca sobreescribe una MAC existente.
     */
    private function updateBridgeMac(int $deviceId, string $mac): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE devices
                SET mac_address = :mac,
                    updated_at  = NOW()
              WHERE id          = :id
                AND mac_address IS NULL'
        );

        $stmt->execute(['mac' => $mac, 'id' => $deviceId]);
    }

    private function updateBeaconBridge(int $deviceId, int $newBridgeDeviceId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE devices
            SET parent_device_id = :bridge_id,
                updated_at       = NOW()
          WHERE id = :id'
        );

        $stmt->execute([
            'bridge_id' => $newBridgeDeviceId,
            'id'        => $deviceId,
        ]);
    }

    /**
     * Actualiza firmware_version y/o serial_number en el JSON de metadata.
     * Hace merge con los valores existentes; nunca borra claves ya presentes.
     * Solo escribe si hay algún cambio real.
     */
    private function updateBridgeMetadata(
        int $deviceId,
        string $currentMetadataRaw,
        ?string $firmwareVersion,
        ?string $serialNumber
    ): void {
        $meta = [];

        if ($currentMetadataRaw !== '' && $currentMetadataRaw !== 'null') {
            $decoded = json_decode($currentMetadataRaw, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $changed = false;

        if ($firmwareVersion !== null && $firmwareVersion !== '') {
            if (($meta['firmware_version'] ?? null) !== $firmwareVersion) {
                $meta['firmware_version'] = $firmwareVersion;
                $changed = true;
            }
        }

        if ($serialNumber !== null && $serialNumber !== '') {
            if (($meta['serial_number'] ?? null) !== $serialNumber) {
                $meta['serial_number'] = $serialNumber;
                $changed = true;
            }
        }

        if (!$changed) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE devices
                SET metadata   = :metadata,
                    updated_at = NOW()
              WHERE id = :id'
        );

        $stmt->execute([
            'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id'       => $deviceId,
        ]);
    }

    private function buildInitialMetadata(
        ?string $firmwareVersion,
        ?string $serialNumber
    ): ?string {
        $meta = array_filter([
            'firmware_version' => ($firmwareVersion !== null && $firmwareVersion !== '') ? $firmwareVersion : null,
            'serial_number'    => ($serialNumber !== null && $serialNumber !== '') ? $serialNumber : null,
        ]);

        return $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    private function insertDevice(array $fields): int
    {
        $fields['created_at'] = $fields['first_seen_at'];
        $fields['updated_at'] = $fields['first_seen_at'];

        $hasMetadata = array_key_exists('metadata', $fields);

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
                    updated_at'
                    . ($hasMetadata ? ', metadata' : '') .
                    ') VALUES (
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
                    :updated_at'
                    . ($hasMetadata ? ', :metadata' : '') .
                    ')'
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
                'kind'        => 'bridge',
                'external_id' => $fields['external_id'],
            ]);

            return $stmt->fetch();
        }

        return false;
    }
}
