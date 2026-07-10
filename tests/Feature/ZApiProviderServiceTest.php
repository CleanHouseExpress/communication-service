<?php

namespace Tests\Feature;

use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboundMessage;
use App\Models\CommunicationRawEvent;
use App\Services\Providers\ZApiProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZApiProviderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_qr_code_endpoint_updates_channel_to_qr_pending(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.providers.zapi.fake' => true,
        ]);

        $channel = $this->channel();

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/channels/z-api/{$channel->id}/qr-code")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'qr_pending');

        $this->assertDatabaseHas('communication_channels', [
            'id' => $channel->id,
            'status' => 'qr_pending',
        ]);
    }

    public function test_connected_webhook_updates_channel_status(): void
    {
        $channel = $this->channel(['status' => 'qr_pending']);

        $this->postJson("/api/webhooks/z-api/{$channel->id}/connected", [
            'tenant_id' => 'tenant-1',
            'timestamp' => '2026-06-29T10:00:00-03:00',
            'phone' => '5541999999999',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'connected');

        $channel->refresh();

        $this->assertSame('connected', $channel->status);
        $this->assertNotNull($channel->last_connected_at);
    }

    public function test_disconnect_endpoint_updates_channel_status(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.providers.zapi.fake' => true,
        ]);

        $channel = $this->channel(['status' => 'connected']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/channels/z-api/{$channel->id}/disconnect")
            ->assertOk()
            ->assertJsonPath('status', 'disconnected');

        $channel->refresh();

        $this->assertSame('disconnected', $channel->status);
        $this->assertNotNull($channel->last_disconnected_at);
    }

    public function test_channel_message_webhook_normalizes_message_and_sanitizes_raw_payload(): void
    {
        $channel = $this->channel();

        $this->postJson("/api/webhooks/z-api/{$channel->id}/messages", [
            'messageId' => 'zapi-message-1',
            'phone' => '5541999999999',
            'senderName' => 'Maria Cliente',
            'text' => ['message' => 'Oi, preciso de ajuda'],
            'token' => 'secret-token',
            'timestamp' => '2026-06-24T12:00:00-03:00',
        ])
            ->assertOk()
            ->assertJsonPath('duplicate', false);

        $this->assertDatabaseHas('communication_messages', [
            'tenant_id' => 'tenant-1',
            'channel_id' => $channel->id,
            'external_message_id' => 'zapi-message-1',
            'direction' => 'inbound',
            'status' => 'received',
        ]);

        $rawEvent = CommunicationRawEvent::query()->firstOrFail();

        $this->assertSame('[redacted]', $rawEvent->payload['token']);
    }

    public function test_channel_message_status_webhook_updates_existing_outbound_message(): void
    {
        $fixtures = $this->conversationFixtures();

        $message = CommunicationMessage::create([
            'tenant_id' => 'tenant-1',
            'conversation_id' => $fixtures['conversation']->id,
            'contact_id' => $fixtures['contact']->id,
            'channel_id' => $fixtures['channel']->id,
            'provider' => 'zapi',
            'external_message_id' => 'provider-message-1',
            'provider_message_id' => 'provider-message-1',
            'direction' => 'outbound',
            'message_type' => 'text',
            'text' => 'Ola',
            'status' => 'sent',
            'occurred_at' => now(),
        ]);

        CommunicationOutboundMessage::create([
            'tenant_id' => 'tenant-1',
            'channel_id' => $fixtures['channel']->id,
            'conversation_id' => $fixtures['conversation']->id,
            'contact_id' => $fixtures['contact']->id,
            'communication_message_id' => $message->id,
            'provider' => 'zapi',
            'external_contact_id' => '5541999999999',
            'idempotency_key' => 'status-key-1',
            'message_type' => 'text',
            'text' => 'Ola',
            'status' => 'sent',
            'provider_message_id' => 'provider-message-1',
        ]);

        $this->postJson("/api/webhooks/z-api/{$fixtures['channel']->id}/message-status", [
            'messageId' => 'provider-message-1',
            'status' => 'read',
            'timestamp' => '2026-06-29T10:10:00-03:00',
        ])
            ->assertOk()
            ->assertJsonPath('processed', true)
            ->assertJsonPath('status', 'read');

        $this->assertDatabaseHas('communication_messages', [
            'id' => $message->id,
            'status' => 'read',
        ]);
    }

    public function test_service_uses_encrypted_channel_tokens_for_real_http_requests(): void
    {
        config([
            'communication.providers.zapi.fake' => false,
            'communication.providers.zapi.base_url' => null,
        ]);

        Http::fake([
            'https://api.z-api.io/*' => Http::response(['messageId' => 'provider-message-1'], 200),
        ]);

        $channel = $this->channel([
            'settings' => [
                'instanceId' => 'instance-1',
                'instanceToken' => Crypt::encryptString('instance-token-1'),
                'clientToken' => Crypt::encryptString('client-token-1'),
            ],
        ]);

        $result = app(ZApiProviderService::class)->sendMessage($channel, [
            'phone' => '5541999999999',
            'message' => 'Ola',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('provider-message-1', $result->providerMessageId);

        Http::assertSent(function ($request): bool {
            return str_contains((string) $request->url(), '/instances/instance-1/token/instance-token-1/send-text')
                && $request->hasHeader('Client-Token', 'client-token-1');
        });
    }

    private function conversationFixtures(): array
    {
        $channel = $this->channel();

        $contact = CommunicationContact::create([
            'tenant_id' => 'tenant-1',
            'provider' => 'zapi',
            'external_id' => '5541999999999',
            'name' => 'Cliente',
            'phone' => '5541999999999',
        ]);

        $conversation = CommunicationConversation::create([
            'tenant_id' => 'tenant-1',
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        return compact('channel', 'contact', 'conversation');
    }

    private function channel(array $overrides = []): CommunicationChannel
    {
        return CommunicationChannel::create([
            'tenant_id' => 'tenant-1',
            'provider' => 'zapi',
            'external_id' => 'zapi-channel-1',
            'name' => 'Z-API',
            'status' => 'active',
            ...$overrides,
        ]);
    }
}

