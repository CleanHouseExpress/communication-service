<?php

namespace Tests\Feature;

use App\Contracts\Messaging\ChannelStatusCheckerInterface;
use App\Contracts\Messaging\MessageSenderInterface;
use App\DTO\Messaging\ChannelStatusResult;
use App\DTO\Messaging\MessagePayload;
use App\DTO\Messaging\MessageResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InternalWhatsAppMessagingTest extends TestCase
{
    public function test_internal_whatsapp_message_endpoint_requires_service_token(): void
    {
        $this->postJson('/api/internal/communication/messages/whatsapp/text', [
            'instance_name' => 'clin',
            'number' => '5511999999999',
            'message' => 'Teste via Communication Service',
        ])->assertUnauthorized();
    }

    public function test_internal_whatsapp_activate_endpoint_requires_service_token(): void
    {
        $this->postJson('/api/internal/communication/channels/whatsapp/activate', [
            'instance_name' => 'clin',
        ])->assertUnauthorized();
    }

    public function test_internal_whatsapp_text_endpoint_succeeds_with_service_token(): void
    {
        config(['communication.service_token' => 'valid-token']);
        $this->app->instance(MessageSenderInterface::class, new class implements MessageSenderInterface {
            public function sendText(MessagePayload $payload): MessageResult
            {
                return new MessageResult(true, 'evolution', 'text', $payload->instanceName, 'msg-1', 'sent');
            }

            public function sendImage(MessagePayload $payload): MessageResult
            {
                throw new \LogicException('Unexpected image send.');
            }

            public function sendDocument(MessagePayload $payload): MessageResult
            {
                throw new \LogicException('Unexpected document send.');
            }

            public function sendAudio(MessagePayload $payload): MessageResult
            {
                throw new \LogicException('Unexpected audio send.');
            }
        });

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/communication/messages/whatsapp/text', [
                'instance_name' => 'clin',
                'number' => '5511999999999',
                'message' => 'Teste via Communication Service',
            ])
            ->assertAccepted()
            ->assertJson([
                'success' => true,
                'provider' => 'evolution',
                'type' => 'text',
                'instance_name' => 'clin',
                'provider_message_id' => 'msg-1',
                'status' => 'sent',
            ]);
    }

    public function test_internal_whatsapp_status_endpoint_succeeds_with_service_token(): void
    {
        config(['communication.service_token' => 'valid-token']);
        $this->app->instance(ChannelStatusCheckerInterface::class, new class implements ChannelStatusCheckerInterface {
            public function check(string $instanceName): ChannelStatusResult
            {
                return new ChannelStatusResult(true, 'evolution', $instanceName, 'open');
            }
        });

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/communication/channels/whatsapp/status?instance_name=clin')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'provider' => 'evolution',
                'instance_name' => 'clin',
                'status' => 'open',
            ]);
    }

    public function test_internal_whatsapp_activate_creates_instance_once_and_returns_qrcode(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'messaging.providers.evolution.base_url' => 'https://evolution.test',
            'messaging.providers.evolution.api_key' => 'provider-secret',
        ]);
        Http::fake([
            'https://evolution.test/instance/fetch/clin' => Http::sequence()
                ->push(['message' => 'not found'], 404)
                ->push(['status' => 200, 'response' => ['instanceName' => 'clin']], 200),
            'https://evolution.test/instance/create' => Http::response(['status' => 200, 'response' => ['instanceName' => 'clin']], 200),
            'https://evolution.test/instance/connect/clin' => Http::response(['status' => 200, 'response' => ['qrCode' => 'qr-base64']], 200),
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/communication/channels/whatsapp/activate', [
                'tenant_id' => 'tenant-1',
                'instance_name' => 'clin',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'provider' => 'evolution',
                'instance_name' => 'clin',
                'state' => 'qrcode_available',
                'qr_code' => 'qr-base64',
            ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/communication/channels/whatsapp/activate', [
                'tenant_id' => 'tenant-1',
                'instance_name' => 'clin',
            ])
            ->assertOk()
            ->assertJsonPath('instance_name', 'clin');

        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://evolution.test/instance/create'
            && $request->hasHeader('apikey', 'provider-secret'));
    }

    public function test_internal_whatsapp_refresh_qrcode_uses_same_instance(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'messaging.providers.evolution.base_url' => 'https://evolution.test',
        ]);
        Http::fake([
            'https://evolution.test/instance/connect/orchestra-acme-whatsapp' => Http::response([
                'status' => 200,
                'response' => ['qrCode' => 'new-qr'],
            ], 200),
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/communication/channels/whatsapp/qrcode/refresh', [
                'instance_name' => 'orchestra-acme-whatsapp',
            ])
            ->assertOk()
            ->assertJsonPath('instance_name', 'orchestra-acme-whatsapp')
            ->assertJsonPath('qr_code', 'new-qr');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://evolution.test/instance/connect/orchestra-acme-whatsapp');
    }

    public function test_evolution_webhook_accepts_valid_payload(): void
    {
        $this->postJson('/api/webhooks/evolution', [
            'event' => 'messages.upsert',
            'instance' => 'clin',
            'data' => ['key' => ['id' => 'provider-message-1']],
        ])
            ->assertOk()
            ->assertJson([
                'accepted' => true,
                'provider' => 'evolution',
                'event' => 'messages.upsert',
            ]);
    }

    public function test_application_does_not_call_evolution_directly_outside_messaging_sdk(): void
    {
        $root = dirname(__DIR__, 2);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app'));
        $violations = [];

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $contents = file_get_contents($path) ?: '';

            foreach (['EVOLUTION_BASE_URL', 'EVOLUTION_API_KEY', '/message/send', '/instance/connectionState'] as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $violations[] = str_replace($root.'/', '', $path).': '.$pattern;
                }
            }
        }

        $this->assertSame([], $violations);
    }
}
