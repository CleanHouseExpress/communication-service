<?php

namespace Tests\Feature;

use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunicationMessageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_updates_message_to_sent(): void
    {
        $fixtures = $this->fixtures();

        $this->postJson('/api/providers/zapi/message-status', $this->statusPayload('sent'))
            ->assertOk()
            ->assertJsonPath('processed', true)
            ->assertJsonPath('status', 'sent');

        $this->assertSame('sent', $fixtures['message']->refresh()->status);
        $this->assertNotNull($fixtures['message']->sent_at);
        $this->assertSame('sent', $fixtures['outbound']->refresh()->status);
        $this->assertDatabaseHas('communication_conversation_events', [
            'message_id' => $fixtures['message']->id,
            'event_type' => 'message_sent',
        ]);
    }

    public function test_delivered_and_read_callbacks_advance_status_and_timestamps(): void
    {
        $fixtures = $this->fixtures();

        $this->postJson('/api/providers/zapi/message-status', $this->statusPayload('delivered'))
            ->assertOk()
            ->assertJsonPath('status', 'delivered');

        $message = $fixtures['message']->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertNotNull($message->delivered_at);

        $this->postJson('/api/providers/zapi/message-status', $this->statusPayload('read', '2026-06-25T19:00:00-03:00'))
            ->assertOk()
            ->assertJsonPath('status', 'read');

        $message->refresh();
        $this->assertSame('read', $message->status);
        $this->assertNotNull($message->read_at);
        $this->assertSame('read', $fixtures['outbound']->refresh()->status);
        $this->assertDatabaseHas('communication_conversation_events', [
            'message_id' => $message->id,
            'event_type' => 'message_delivered',
        ]);
        $this->assertDatabaseHas('communication_conversation_events', [
            'message_id' => $message->id,
            'event_type' => 'message_read',
        ]);
    }

    public function test_failed_callback_marks_message_failed(): void
    {
        $fixtures = $this->fixtures();

        $this->postJson('/api/providers/zapi/message-status', $this->statusPayload('failed'))
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        $this->assertSame('failed', $fixtures['message']->refresh()->status);
        $this->assertNotNull($fixtures['message']->failed_at);
        $this->assertSame('failed', $fixtures['outbound']->refresh()->status);
        $this->assertNotNull($fixtures['outbound']->failed_at);
        $this->assertDatabaseHas('communication_conversation_events', [
            'message_id' => $fixtures['message']->id,
            'event_type' => 'message_failed',
        ]);
    }

    public function test_unknown_callback_does_not_break_provider_flow(): void
    {
        $this->postJson('/api/providers/zapi/message-status', [
            ...$this->statusPayload('delivered'),
            'provider_message_id' => 'unknown-provider-message',
            'external_message_id' => null,
        ])
            ->assertOk()
            ->assertJson([
                'processed' => false,
                'message_id' => null,
            ]);
    }

    public function test_late_callback_does_not_regress_status_or_duplicate_timeline(): void
    {
        $fixtures = $this->fixtures();

        $this->postJson('/api/providers/zapi/message-status', $this->statusPayload('read'))->assertOk();
        $this->postJson('/api/providers/zapi/message-status', $this->statusPayload('delivered'))
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('status', 'read');

        $this->assertSame('read', $fixtures['message']->refresh()->status);
        $this->assertDatabaseCount('communication_conversation_events', 1);
    }

    public function test_timeline_metadata_and_status_endpoint_do_not_leak_secrets(): void
    {
        config(['communication.service_token' => 'valid-token']);
        $fixtures = $this->fixtures();

        $this->postJson('/api/providers/zapi/message-status', [
            ...$this->statusPayload('delivered'),
            'token' => 'provider-secret',
            'provider_response' => ['authorization' => 'Bearer secret'],
        ])->assertOk();

        $timeline = $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson("/api/internal/inbox/conversations/{$fixtures['conversation']->id}/timeline?tenant_id=tenant-1")
            ->assertOk();

        $this->assertStringNotContainsString('provider-secret', $timeline->getContent());
        $this->assertStringNotContainsString('Bearer secret', $timeline->getContent());

        $status = $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson("/api/internal/inbox/conversations/{$fixtures['conversation']->id}/messages/status?tenant_id=tenant-1")
            ->assertOk()
            ->assertJsonPath('data.0.message_id', $fixtures['message']->id)
            ->assertJsonPath('data.0.status', 'delivered');

        $this->assertSame([
            'message_id',
            'status',
            'sent_at',
            'delivered_at',
            'read_at',
        ], array_keys($status->json('data.0')));
    }

    private function fixtures(): array
    {
        $channel = CommunicationChannel::create([
            'tenant_id' => 'tenant-1',
            'provider' => 'zapi',
            'external_id' => 'delivery-channel-'.uniqid(),
            'name' => 'Z-API',
            'status' => 'active',
        ]);

        $contact = CommunicationContact::create([
            'tenant_id' => 'tenant-1',
            'provider' => 'zapi',
            'external_id' => '5541999999999',
            'name' => 'Maria Cliente',
            'phone' => '5541999999999',
        ]);

        $conversation = CommunicationConversation::create([
            'tenant_id' => 'tenant-1',
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'service_mode' => 'ai',
            'handoff_status' => 'none',
            'last_message_at' => now(),
        ]);

        $message = CommunicationMessage::create([
            'tenant_id' => 'tenant-1',
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'provider' => 'zapi',
            'external_message_id' => 'provider-message-123',
            'provider_message_id' => 'provider-message-123',
            'direction' => 'outbound',
            'message_type' => 'text',
            'text' => 'Mensagem',
            'payload' => ['token' => 'must-not-leak'],
            'status' => 'pending',
            'occurred_at' => now(),
        ]);

        $outbound = CommunicationOutboundMessage::create([
            'tenant_id' => 'tenant-1',
            'channel_id' => $channel->id,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'communication_message_id' => $message->id,
            'provider' => 'zapi',
            'external_contact_id' => '5541999999999',
            'idempotency_key' => 'delivery-'.uniqid(),
            'message_type' => 'text',
            'text' => 'Mensagem',
            'payload' => ['token' => 'must-not-leak'],
            'status' => 'pending',
            'provider_message_id' => 'provider-message-123',
        ]);

        return compact('channel', 'contact', 'conversation', 'message', 'outbound');
    }

    private function statusPayload(string $status, string $timestamp = '2026-06-25T18:30:00-03:00'): array
    {
        return [
            'tenant_id' => 'tenant-1',
            'provider_message_id' => 'provider-message-123',
            'external_message_id' => 'provider-message-123',
            'status' => $status,
            'timestamp' => $timestamp,
        ];
    }
}
