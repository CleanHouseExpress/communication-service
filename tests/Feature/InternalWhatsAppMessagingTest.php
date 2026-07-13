<?php

namespace Tests\Feature;

use App\Contracts\Messaging\ChannelStatusCheckerInterface;
use App\Contracts\Messaging\MessageSenderInterface;
use App\DTO\Messaging\ChannelStatusResult;
use App\DTO\Messaging\MessagePayload;
use App\DTO\Messaging\MessageResult;
use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InternalWhatsAppMessagingTest extends TestCase
{
    use RefreshDatabase;

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
        $this->app->instance(MessageSenderInterface::class, new class implements MessageSenderInterface
        {
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
        $this->app->instance(ChannelStatusCheckerInterface::class, new class implements ChannelStatusCheckerInterface
        {
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
            'https://evolution.test/webhook/set/clin' => Http::response(['status' => 200, 'response' => ['enabled' => true]], 200),
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

        Http::assertSentCount(7);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://evolution.test/instance/create'
            && $request->hasHeader('apikey', 'provider-secret'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://evolution.test/webhook/set/clin');
    }

    public function test_internal_whatsapp_refresh_qrcode_uses_same_instance(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'messaging.providers.evolution.base_url' => 'https://evolution.test',
            'messaging.providers.evolution.webhook_url' => null,
        ]);
        Http::fake([
            'https://evolution.test/instance/fetch/orchestra-acme-whatsapp' => Http::response(['status' => 200, 'response' => ['instanceName' => 'orchestra-acme-whatsapp']], 200),
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

    public function test_evolution_webhook_processes_inbound_message_for_orchestra_instance(): void
    {
        config([
            'communication.tenancy.enforce' => false,
            'communication.tenancy.runtime.enabled' => false,
            'communication.agent.enabled' => false,
        ]);

        $this->postJson('/api/webhooks/evolution', [
            'event' => 'messages.upsert',
            'instance' => 'orchestra-clin-4-whatsapp',
            'data' => [
                'key' => [
                    'id' => 'provider-message-1',
                    'remoteJid' => '5511999999999@s.whatsapp.net',
                    'fromMe' => false,
                ],
                'pushName' => 'Cliente Clin',
                'message' => [
                    'conversation' => 'Oi, preciso de atendimento',
                ],
                'messageTimestamp' => 1_783_966_800,
            ],
        ])
            ->assertOk()
            ->assertJson([
                'accepted' => true,
                'provider' => 'evolution',
                'event' => 'messages.upsert',
                'processed' => true,
                'message_created' => true,
            ]);

        $this->assertDatabaseHas('communication_contacts', [
            'tenant_id' => '4',
            'provider' => 'whatsapp',
            'external_id' => '5511999999999',
            'name' => 'Cliente Clin',
            'phone' => '5511999999999',
        ]);
        $this->assertDatabaseHas('communication_messages', [
            'tenant_id' => '4',
            'provider' => 'whatsapp',
            'external_message_id' => 'provider-message-1',
            'direction' => 'inbound',
            'message_type' => 'text',
            'text' => 'Oi, preciso de atendimento',
            'status' => 'received',
        ]);
        $this->assertDatabaseHas('communication_conversations', [
            'tenant_id' => '4',
            'status' => 'open',
        ]);
    }

    public function test_evolution_webhook_resolves_orchestra_instance_as_channel_external_id(): void
    {
        config([
            'communication.tenancy.enforce' => false,
            'communication.tenancy.runtime.enabled' => false,
            'communication.agent.enabled' => false,
        ]);

        $channel = CommunicationChannel::create([
            'tenant_id' => '4',
            'provider' => 'whatsapp',
            'external_id' => 'orchestra-clin-4-whatsapp',
            'name' => 'WhatsApp Clin',
            'status' => 'active',
        ]);

        $this->postJson('/api/webhooks/evolution', [
            'event' => 'messages.update',
            'instance' => 'orchestra-clin-4-whatsapp',
            'data' => [
                'key' => [
                    'id' => 'provider-message-update-1',
                    'remoteJid' => '5541999999999@s.whatsapp.net',
                    'fromMe' => false,
                ],
                'pushName' => 'Cliente Clin',
                'message' => [
                    'conversation' => 'Mensagem recebida apos reconnect',
                ],
                'messageTimestamp' => 1_783_966_900,
            ],
        ])
            ->assertOk()
            ->assertJson([
                'accepted' => true,
                'provider' => 'evolution',
                'event' => 'messages.update',
                'processed' => true,
                'message_created' => true,
            ]);

        $this->assertDatabaseCount('communication_channels', 1);
        $this->assertDatabaseHas('communication_messages', [
            'tenant_id' => '4',
            'provider' => 'whatsapp',
            'channel_id' => $channel->id,
            'external_message_id' => 'provider-message-update-1',
            'text' => 'Mensagem recebida apos reconnect',
            'status' => 'received',
        ]);
    }

    public function test_evolution_webhook_ignores_unknown_payload_without_message_content(): void
    {
        config([
            'communication.tenancy.enforce' => false,
            'communication.tenancy.runtime.enabled' => false,
            'communication.agent.enabled' => false,
        ]);

        $this->postJson('/api/webhooks/evolution', [
            'event' => 'messages.update',
            'instance' => 'orchestra-clin-4-whatsapp',
            'data' => [
                'key' => [
                    'id' => 'provider-message-empty-1',
                    'remoteJid' => '5541999999999@s.whatsapp.net',
                    'fromMe' => false,
                ],
                'messageTimestamp' => 1_783_967_000,
            ],
        ])
            ->assertOk()
            ->assertJson([
                'accepted' => true,
                'provider' => 'evolution',
                'event' => 'messages.update',
                'processed' => false,
            ]);

        $this->assertDatabaseCount('communication_conversations', 0);
        $this->assertDatabaseCount('communication_messages', 0);
    }

    public function test_evolution_webhook_ignores_group_messages(): void
    {
        config([
            'communication.tenancy.enforce' => false,
            'communication.tenancy.runtime.enabled' => false,
            'communication.agent.enabled' => false,
        ]);

        $this->postJson('/api/webhooks/evolution', [
            'event' => 'messages.upsert',
            'instance' => 'orchestra-clin-4-whatsapp',
            'data' => [
                'key' => [
                    'id' => 'provider-group-message-1',
                    'remoteJid' => '120363123456789@g.us',
                    'participant' => '5541999999999@s.whatsapp.net',
                    'fromMe' => false,
                ],
                'pushName' => 'Participante Grupo',
                'message' => [
                    'conversation' => 'Mensagem enviada dentro do grupo',
                ],
                'messageTimestamp' => 1_783_967_050,
            ],
        ])
            ->assertOk()
            ->assertJson([
                'accepted' => true,
                'provider' => 'evolution',
                'event' => 'messages.upsert',
                'processed' => false,
            ]);

        $this->assertDatabaseCount('communication_contacts', 0);
        $this->assertDatabaseCount('communication_conversations', 0);
        $this->assertDatabaseCount('communication_messages', 0);
    }

    public function test_evolution_webhook_reuses_open_conversation_for_same_contact_when_channel_changes(): void
    {
        config([
            'communication.tenancy.enforce' => false,
            'communication.tenancy.runtime.enabled' => false,
            'communication.agent.enabled' => false,
        ]);

        $oldChannel = CommunicationChannel::create([
            'tenant_id' => '4',
            'provider' => 'whatsapp',
            'external_id' => 'legacy-whatsapp-channel',
            'name' => 'WhatsApp antigo',
            'status' => 'active',
        ]);
        $newChannel = CommunicationChannel::create([
            'tenant_id' => '4',
            'provider' => 'whatsapp',
            'external_id' => 'orchestra-clin-4-whatsapp',
            'name' => 'WhatsApp Clin',
            'status' => 'active',
        ]);
        $contact = CommunicationContact::create([
            'tenant_id' => '4',
            'provider' => 'whatsapp',
            'external_id' => '554141414444',
            'name' => 'clin',
            'phone' => '554141414444',
        ]);
        $conversation = CommunicationConversation::create([
            'tenant_id' => '4',
            'channel_id' => $oldChannel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'service_mode' => 'human',
            'handoff_status' => 'assigned',
            'assignment_status' => 'assigned',
            'assigned_external_user_id' => '1',
            'assigned_external_user_name' => 'Admin Clin',
            'last_message_at' => now()->subMinute(),
            'metadata' => [],
        ]);
        $duplicateConversation = CommunicationConversation::create([
            'tenant_id' => '4',
            'channel_id' => $newChannel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'service_mode' => 'ai',
            'handoff_status' => 'none',
            'last_message_at' => now(),
            'metadata' => [],
        ]);

        $this->postJson('/api/webhooks/evolution', [
            'event' => 'messages.upsert',
            'instance' => 'orchestra-clin-4-whatsapp',
            'data' => [
                'key' => [
                    'id' => 'provider-message-reuse-1',
                    'remoteJid' => '554141414444@s.whatsapp.net',
                    'fromMe' => false,
                ],
                'pushName' => 'clin',
                'message' => [
                    'conversation' => 'Resposta chegando no canal novo',
                ],
                'messageTimestamp' => 1_783_967_100,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('conversation_id', (string) $conversation->id)
            ->assertJsonPath('message_created', true);

        $this->assertDatabaseCount('communication_conversations', 2);
        $this->assertDatabaseHas('communication_conversations', [
            'id' => $conversation->id,
            'channel_id' => $newChannel->id,
            'service_mode' => 'human',
            'handoff_status' => 'assigned',
            'assigned_external_user_name' => 'Admin Clin',
        ]);
        $this->assertDatabaseHas('communication_messages', [
            'conversation_id' => $conversation->id,
            'channel_id' => $newChannel->id,
            'external_message_id' => 'provider-message-reuse-1',
            'text' => 'Resposta chegando no canal novo',
        ]);
        $this->assertDatabaseMissing('communication_messages', [
            'conversation_id' => $duplicateConversation->id,
            'external_message_id' => 'provider-message-reuse-1',
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
