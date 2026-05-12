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

        $bridgeId = trim((string) ($payload['bridgeId'] ?? ''));

        if ($bridgeId === '') {
            return [
                'status' => 422,
                'code' => 'missing_bridge_id',
                'message' => 'bridgeId is required.',
            ];
        }

        $logText = (string) ($payload['logText'] ?? '');

        if (trim($logText) === '') {
            return [
                'status' => 422,
                'code' => 'missing_log_text',
                'message' => 'logText is required.',
            ];
        }

        return null;
    }
}