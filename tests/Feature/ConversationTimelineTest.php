<?php

namespace Tests\Feature;

use App\Actions\Conversations\RecordConversationEventAction;
use App\Enums\ConversationEventType;
use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationConversationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_conversation_and_inbound_create_timeline_events(): void
    {
        config([
            'communication.agent.enabled' => false,
            'communication.providers.zapi.fake' => true,
        ]);

        $this->postJson('/api/providers/zapi/webhook', [
            'tenant_id' => 'tenant-1',
            'messageId' => 'timeline-inbound-1',
            'phone' => '5541999999999',
            'senderName' => 'Maria Cliente',
            'text' => ['message' => 'Oi'],
            'fromMe' => false,
            'isGroup' => false,
            'timestamp' => '2026-06-25T12:00:00-03:00',
        ])->assertOk();

        $conversation = CommunicationConversation::firstOrFail();

        $this->assertDatabaseHas('communication_conversation_events', [
            'tenant_id' => 'tenant-1',
            'conversation_id' => $conversation->id,
            'event_type' => 'conversation_created',
        ]);
        $this->assertDatabaseHas('communication_conversation_events', [
            'tenant_id' => 'tenant-1',
            'conversation_id' => $conversation->id,
            'event_type' => 'message_received',
        ]);
    }

    public function test_assign_return_to_ai_and_close_create_events(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/assign", [
                'tenant_id' => 'tenant-1',
                'external_user_id' => 'user-1',
                'external_user_name' => 'Atendente',
            ])
            ->assertOk();

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/return-to-ai", [
                'tenant_id' => 'tenant-1',
                'reason' => 'Finalizado',
            ])
            ->assertOk();

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/close", [
                'tenant_id' => 'tenant-1',
                'reason' => 'Resolvido',
            ])
            ->assertOk();

        $this->assertDatabaseHas('communication_conversation_events', [
            'conversation_id' => $conversation->id,
            'event_type' => 'conversation_assigned',
        ]);
        $this->assertDatabaseHas('communication_conversation_events', [
            'conversation_id' => $conversation->id,
            'event_type' => 'conversation_returned_to_ai',
        ]);
        $this->assertDatabaseHas('communication_conversation_events', [
            'conversation_id' => $conversation->id,
            'event_type' => 'conversation_closed',
        ]);
    }

    public function test_timeline_lists_only_events_for_tenant_in_chronological_order(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1');
        $otherTenantConversation = $this->conversation('tenant-2');
        $recorder = app(RecordConversationEventAction::class);

        $recorder->handle(
            eventType: ConversationEventType::MessageReceived,
            tenantId: 'tenant-1',
            conversationId: (string) $conversation->id,
            actorType: 'contact',
            description: 'Second',
            occurredAt: now()->addMinute(),
        );
        $recorder->handle(
            eventType: ConversationEventType::ConversationCreated,
            tenantId: 'tenant-1',
            conversationId: (string) $conversation->id,
            actorType: 'system',
            description: 'First',
            occurredAt: now(),
        );
        $recorder->handle(
            eventType: ConversationEventType::ConversationClosed,
            tenantId: 'tenant-2',
            conversationId: (string) $otherTenantConversation->id,
            actorType: 'system',
            description: 'Other tenant',
            occurredAt: now(),
        );

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson("/api/internal/inbox/conversations/{$conversation->id}/timeline?tenant_id=tenant-1")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame([
            'conversation_created',
            'message_received',
        ], collect($response->json('data'))->pluck('event_type')->all());
    }

    public function test_timeline_resource_does_not_leak_sensitive_metadata(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1');

        CommunicationConversationEvent::create([
            'tenant_id' => 'tenant-1',
            'conversation_id' => $conversation->id,
            'event_type' => 'message_sent',
            'actor_type' => 'system',
            'description' => 'Sensitive metadata',
            'metadata' => [
                'token' => 'secret-token',
                'payload' => ['raw' => 'secret-raw'],
                'safe' => 'visible',
            ],
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson("/api/internal/inbox/conversations/{$conversation->id}/timeline?tenant_id=tenant-1")
            ->assertOk()
            ->assertJsonPath('data.0.metadata.safe', 'visible');

        $this->assertStringNotContainsString('secret-token', $response->getContent());
        $this->assertStringNotContainsString('secret-raw', $response->getContent());
        $this->assertStringNotContainsString('payload', $response->getContent());
    }

    private function conversation(string $tenantId): CommunicationConversation
    {
        $channel = CommunicationChannel::create([
            'tenant_id' => $tenantId,
            'provider' => 'zapi',
            'external_id' => 'channel-'.$tenantId.'-'.uniqid(),
            'name' => 'Z-API',
            'status' => 'active',
        ]);

        $contact = CommunicationContact::create([
            'tenant_id' => $tenantId,
            'provider' => 'zapi',
            'external_id' => '5541999999999'.random_int(100, 999),
            'name' => 'Maria Cliente',
            'phone' => '5541999999999',
        ]);

        return CommunicationConversation::create([
            'tenant_id' => $tenantId,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'service_mode' => 'ai',
            'handoff_status' => 'none',
            'last_message_at' => now(),
            'metadata' => [],
        ]);
    }
}
