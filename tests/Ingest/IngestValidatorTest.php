<?php

declare(strict_types=1);

namespace Tests\Ingest;

use App\Ingest\IngestValidator;
use App\Support\Env;
use PHPUnit\Framework\TestCase;

final class IngestValidatorTest extends TestCase
{
    public function testValidateServerRequestAcceptsExpectedHeaders(): void
    {
        $validator = new IngestValidator();

        $error = $validator->validateServerRequest($this->validServer());

        $this->assertNull($error);
    }

    public function testValidateServerRequestRejectsInvalidSecret(): void
    {
        $validator = new IngestValidator();

        $server = $this->validServer();
        $server['HTTP_X_LOG_AUTH'] = 'wrong-secret';

        $error = $validator->validateServerRequest($server);

        $this->assertIsArray($error);
        $this->assertSame(401, $error['status']);
        $this->assertSame('invalid_secret', $error['code']);
    }

    public function testValidatePayloadAcceptsLegacyPayload(): void
    {
        $validator = new IngestValidator();

        $payload = [
            'message' => 'bridge_logs',
            'bridgeId' => 120,
            'logText' => '[2026-05-18 10:00:00] [TELEMETRY] INFO: test',
        ];

        $this->assertNull($validator->validatePayload($payload));
    }

    public function testValidatePayloadAcceptsStructuredEventsPayloadWithoutTopLevelLogText(): void
    {
        $validator = new IngestValidator();

        $payload = [
            'message' => 'bridge_logs',
            'source' => [
                'deviceId' => 120,
            ],
            'events' => [
                [
                    'category' => 'telemetry',
                    'type' => 'telemetry_snapshot',
                ],
            ],
        ];

        $this->assertNull($validator->validatePayload($payload));
    }

    public function testValidatePayloadAcceptsRawLogTextPayloadWithoutTopLevelLogText(): void
    {
        $validator = new IngestValidator();

        $payload = [
            'message' => 'bridge_logs',
            'source' => [
                'deviceId' => 120,
            ],
            'raw' => [
                'logText' => '[2026-05-18 10:00:00] [TELEMETRY] INFO: test',
            ],
        ];

        $this->assertNull($validator->validatePayload($payload));
    }

    public function testValidatePayloadRejectsMissingSourceIdentity(): void
    {
        $validator = new IngestValidator();

        $payload = [
            'message' => 'bridge_logs',
            'logText' => '[2026-05-18 10:00:00] [TELEMETRY] INFO: test',
        ];

        $error = $validator->validatePayload($payload);

        $this->assertIsArray($error);
        $this->assertSame(422, $error['status']);
        $this->assertSame('missing_source_identity', $error['code']);
    }

    public function testValidatePayloadRejectsMissingProcessableContent(): void
    {
        $validator = new IngestValidator();

        $payload = [
            'message' => 'bridge_logs',
            'bridgeId' => 120,
        ];

        $error = $validator->validatePayload($payload);

        $this->assertIsArray($error);
        $this->assertSame(422, $error['status']);
        $this->assertSame('missing_processable_content', $error['code']);
    }

    private function validServer(): array
    {
        return [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => Env::get('LOG_INGEST_USER_AGENT', 'WalkerPisa-Bridge-Logs'),
            'HTTP_X_LOG_AUTH' => Env::get('LOG_INGEST_SECRET', 'test-secret'),
        ];
    }
}
