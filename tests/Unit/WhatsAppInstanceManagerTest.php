<?php

namespace Tests\Unit;

use App\Services\Messaging\WhatsAppInstanceManager;
use Clin\MessagingSdk\DTO\EvolutionResponse;
use Clin\MessagingSdk\Exceptions\RequestException;
use Clin\MessagingSdk\MessagingClient;
use Clin\MessagingSdk\Providers\Evolution\Resources\InstanceResource;
use Clin\MessagingSdk\Providers\Evolution\Resources\WebhookResource;
use Tests\TestCase;
use Throwable;

class WhatsAppInstanceManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['messaging.providers.evolution.webhook_url' => null]);
    }

    public function test_fetch_success_does_not_create_and_connects(): void
    {
        $instances = $this->instances();
        $instances->fetchResult = $this->response(true, ['instanceName' => 'clin']);
        $instances->connectResult = $this->qrResponse();

        $result = $this->manager($instances)->activate('clin');

        $this->assertSame(1, $instances->fetchCalls);
        $this->assertSame(0, $instances->createCalls);
        $this->assertSame(1, $instances->connectCalls);
        $this->assertTrue($result['success']);
        $this->assertSame('qrcode_available', $result['state']);
        $this->assertSame('qr-base64', $result['qr_code']);
    }

    public function test_fetch_false_with_not_found_creates_and_connects(): void
    {
        $instances = $this->instances();
        $instances->fetchResult = $this->response(false, null, 'Instance not found');
        $instances->createResult = $this->response(true, ['instanceName' => 'clin']);
        $instances->connectResult = $this->qrResponse();

        $result = $this->manager($instances)->activate('clin');

        $this->assertSame(1, $instances->fetchCalls);
        $this->assertSame(1, $instances->createCalls);
        $this->assertSame(1, $instances->connectCalls);
        $this->assertSame('qr-base64', $result['qr_code']);
    }

    public function test_fetch_throws_404_creates_and_connects(): void
    {
        $instances = $this->instances();
        $instances->fetchException = new RequestException('not found', 404);
        $instances->createResult = $this->response(true, ['instanceName' => 'clin']);
        $instances->connectResult = $this->qrResponse();

        $result = $this->manager($instances)->activate('clin');

        $this->assertSame(1, $instances->fetchCalls);
        $this->assertSame(1, $instances->createCalls);
        $this->assertSame(1, $instances->connectCalls);
        $this->assertSame('qr-base64', $result['qr_code']);
    }

    public function test_fetch_throws_missing_message_with_non_404_creates_and_connects(): void
    {
        $instances = $this->instances();
        $instances->fetchException = new RequestException('Instance does not exist', 409);
        $instances->createResult = $this->response(true, ['instanceName' => 'clin']);
        $instances->connectResult = $this->qrResponse();

        $result = $this->manager($instances)->activate('clin');

        $this->assertSame(1, $instances->fetchCalls);
        $this->assertSame(1, $instances->createCalls);
        $this->assertSame(1, $instances->connectCalls);
        $this->assertSame('qr-base64', $result['qr_code']);
    }

    public function test_create_already_exists_response_is_idempotent_and_connects(): void
    {
        $instances = $this->instances();
        $instances->fetchResult = $this->response(false, null, 'missing');
        $instances->createResult = $this->response(false, null, 'Instance already exists');
        $instances->connectResult = $this->qrResponse();

        $result = $this->manager($instances)->activate('clin');

        $this->assertSame(1, $instances->fetchCalls);
        $this->assertSame(1, $instances->createCalls);
        $this->assertSame(1, $instances->connectCalls);
        $this->assertSame('qr-base64', $result['qr_code']);
    }

    public function test_create_already_in_use_exception_is_idempotent_and_connects(): void
    {
        $instances = $this->instances();
        $instances->fetchResult = $this->response(false, null, 'missing');
        $instances->createException = new RequestException('This name "clin" is already in use.', 400);
        $instances->connectResult = $this->qrResponse();

        $result = $this->manager($instances)->activate('clin');

        $this->assertSame(1, $instances->fetchCalls);
        $this->assertSame(1, $instances->createCalls);
        $this->assertSame(1, $instances->connectCalls);
        $this->assertSame('qr-base64', $result['qr_code']);
    }

    public function test_activate_configures_evolution_webhook_when_url_is_available(): void
    {
        config([
            'messaging.providers.evolution.webhook_url' => 'https://communication.test/api/webhooks/evolution',
            'messaging.providers.evolution.webhook_events' => ['MESSAGES_UPSERT', 'CONNECTION_UPDATE'],
        ]);
        $instances = $this->instances();
        $instances->fetchResult = $this->response(true, ['instanceName' => 'clin']);
        $instances->connectResult = $this->qrResponse();
        $webhooks = new FakeWhatsAppWebhookResource;

        $result = $this->manager($instances, $webhooks)->activate('clin');

        $this->assertSame(1, $webhooks->setCalls);
        $this->assertSame('clin', $webhooks->lastInstanceName);
        $this->assertSame([
            'url' => 'https://communication.test/api/webhooks/evolution',
        ], $webhooks->lastPayload);
        $this->assertSame('qr-base64', $result['qr_code']);
    }

    public function test_fetch_throws_unrelated_500_and_does_not_create_or_connect(): void
    {
        $instances = $this->instances();
        $instances->fetchException = new RequestException('Evolution unavailable', 500);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Evolution unavailable');

        try {
            $this->manager($instances)->activate('clin');
        } finally {
            $this->assertSame(1, $instances->fetchCalls);
            $this->assertSame(0, $instances->createCalls);
            $this->assertSame(0, $instances->connectCalls);
        }
    }

    public function test_create_throws_unrelated_error_and_does_not_connect(): void
    {
        $instances = $this->instances();
        $instances->fetchResult = $this->response(false, null, 'not found');
        $instances->createException = new RequestException('Evolution create failed', 500);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Evolution create failed');

        try {
            $this->manager($instances)->activate('clin');
        } finally {
            $this->assertSame(1, $instances->fetchCalls);
            $this->assertSame(1, $instances->createCalls);
            $this->assertSame(0, $instances->connectCalls);
        }
    }

    private function manager(FakeWhatsAppInstanceResource $instances, ?FakeWhatsAppWebhookResource $webhooks = null): WhatsAppInstanceManager
    {
        $client = new class($instances, $webhooks) extends MessagingClient
        {
            public function __construct(
                private readonly InstanceResource $instances,
                private readonly ?WebhookResource $webhooks,
            ) {}

            public function instances(): InstanceResource
            {
                return $this->instances;
            }

            public function webhooks(): WebhookResource
            {
                return $this->webhooks ?? new FakeWhatsAppWebhookResource;
            }
        };

        return new WhatsAppInstanceManager($client);
    }

    private function instances(): FakeWhatsAppInstanceResource
    {
        return new FakeWhatsAppInstanceResource;
    }

    private function response(bool $ok, mixed $data = null, ?string $message = null): EvolutionResponse
    {
        return new EvolutionResponse($ok, $data, $message, [
            'status' => $ok ? 200 : 404,
            'message' => $message,
            'response' => $data,
        ]);
    }

    private function qrResponse(): EvolutionResponse
    {
        return $this->response(true, ['qrCode' => 'qr-base64']);
    }
}

class FakeWhatsAppInstanceResource extends InstanceResource
{
    public int $fetchCalls = 0;

    public int $createCalls = 0;

    public int $connectCalls = 0;

    public ?EvolutionResponse $fetchResult = null;

    public ?EvolutionResponse $createResult = null;

    public ?EvolutionResponse $connectResult = null;

    public ?Throwable $fetchException = null;

    public ?Throwable $createException = null;

    public ?Throwable $connectException = null;

    public function __construct() {}

    public function fetch(string $instanceName): EvolutionResponse
    {
        $this->fetchCalls++;

        if ($this->fetchException !== null) {
            throw $this->fetchException;
        }

        return $this->fetchResult ?? new EvolutionResponse(true);
    }

    public function create(string $instanceName, ?array $options = null): EvolutionResponse
    {
        $this->createCalls++;

        if ($this->createException !== null) {
            throw $this->createException;
        }

        return $this->createResult ?? new EvolutionResponse(true);
    }

    public function connect(string $instanceName): EvolutionResponse
    {
        $this->connectCalls++;

        if ($this->connectException !== null) {
            throw $this->connectException;
        }

        return $this->connectResult ?? new EvolutionResponse(true);
    }
}

class FakeWhatsAppWebhookResource extends WebhookResource
{
    public int $setCalls = 0;

    public ?string $lastInstanceName = null;

    public ?array $lastPayload = null;

    public ?EvolutionResponse $setResult = null;

    public ?Throwable $setException = null;

    public function __construct() {}

    public function set(string $instanceName, array $payload): EvolutionResponse
    {
        $this->setCalls++;
        $this->lastInstanceName = $instanceName;
        $this->lastPayload = $payload;

        if ($this->setException !== null) {
            throw $this->setException;
        }

        return $this->setResult ?? new EvolutionResponse(true);
    }
}
