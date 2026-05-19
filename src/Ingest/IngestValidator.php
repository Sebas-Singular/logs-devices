<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Support\Env;

final class IngestValidator
{
    public function validateServerRequest(array $server): ?array
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? ''));

        if ($method !== 'POST') {
            return [
                'status' => 405,
                'code' => 'method_not_allowed',
                'message' => 'Only POST requests are allowed.',
            ];
        }

        $contentType = strtolower((string) ($server['CONTENT_TYPE'] ?? ''));

        if ($contentType !== '' && !str_starts_with($contentType, 'application/json')) {
            return [
                'status' => 415,
                'code' => 'unsupported_media_type',
                'message' => 'Content-Type must be application/json.',
            ];
        }

        $expectedUserAgent = Env::get('LOG_INGEST_USER_AGENT', 'WalkerPisa-Bridge-Logs');
        $actualUserAgent = (string) ($server['HTTP_USER_AGENT'] ?? '');

        if ($actualUserAgent !== $expectedUserAgent) {
            return [
                'status' => 401,
                'code' => 'invalid_user_agent',
                'message' => 'Invalid User-Agent.',
            ];
        }

        $expectedSecret = Env::get('LOG_INGEST_SECRET');

        if ($expectedSecret === null || $expectedSecret === '') {
            return [
                'status' => 500,
                'code' => 'missing_server_secret',
                'message' => 'Ingest secret is not configured.',
            ];
        }

        $actualSecret = (string) ($server['HTTP_X_LOG_AUTH'] ?? '');

        if ($actualSecret === '' || !hash_equals($expectedSecret, $actualSecret)) {
            return [
                'status' => 401,
                'code' => 'invalid_secret',
                'message' => 'Invalid ingest secret.',
            ];
        }

        return null;
    }

    public function validatePayload(mixed $payload): ?array
    {
        if (!is_array($payload)) {
            return [
                'status' => 400,
                'code' => 'invalid_payload',
                'message' => 'JSON body must be an object.',
            ];
        }

        $message = trim((string) ($payload['message'] ?? ''));

        if ($message !== 'bridge_logs') {
            return [
                'status' => 422,
                'code' => 'invalid_message',
                'message' => 'message must be bridge_logs.',
            ];
        }

        if (!$this->hasValidSourceIdentity($payload)) {
            return [
                'status' => 422,
                'code' => 'missing_source_identity',
                'message' => 'Payload must include bridgeId or source.deviceId.',
            ];
        }

        if (!$this->hasAnyProcessableContent($payload)) {
            return [
                'status' => 422,
                'code' => 'missing_processable_content',
                'message' => 'Payload must include logText, raw.logText, events[], or vehicleEvents[].',
            ];
        }

        return null;
    }

    private function hasValidSourceIdentity(array $payload): bool
    {
        $bridgeId = trim((string) ($payload['bridgeId'] ?? ''));

        if ($bridgeId !== '') {
            return true;
        }

        $source = $payload['source'] ?? null;

        if (!is_array($source)) {
            return false;
        }

        $sourceDeviceId = trim((string) ($source['deviceId'] ?? ''));

        return $sourceDeviceId !== '';
    }

    private function hasAnyProcessableContent(array $payload): bool
    {
        $logText = trim((string) ($payload['logText'] ?? ''));

        if ($logText !== '') {
            return true;
        }

        $raw = $payload['raw'] ?? null;

        if (is_array($raw)) {
            $rawLogText = trim((string) ($raw['logText'] ?? ''));

            if ($rawLogText !== '') {
                return true;
            }
        }

        if ($this->hasNonEmptyList($payload['events'] ?? null)) {
            return true;
        }

        return $this->hasNonEmptyList($payload['vehicleEvents'] ?? null);
    }

    private function hasNonEmptyList(mixed $value): bool
    {
        if (!is_array($value) || $value === []) {
            return false;
        }

        foreach ($value as $item) {
            if (is_array($item)) {
                return true;
            }
        }

        return false;
    }
}
