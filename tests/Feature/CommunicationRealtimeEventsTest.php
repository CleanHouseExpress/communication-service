<?php

namespace Tests\Feature;

use App\Actions\Conversations\AssignConversationAction;
use App\Actions\Conversations\CloseConversationAction;
use App\Actions\Conversations\ReopenConversationAction;
use App\Actions\Conversations\RequestConversationHandoffAction;
use App\Actions\Conversations\ReturnConversationToAiAction;
use App\Events\Realtime\AbstractCommunicationRealtimeEvent;
use App\Events\Realtime\ConversationAssigned;
use App\Events\Realtime\ConversationClosed;
use App\Events\Realtime\ConversationCreated;
use App\Events\Realtime\ConversationHandoffRequested;
use App\Events\Realtime\ConversationReopened;
use App\Events\Realtime\ConversationReturnedToAi;
use App\Events\Realtime\ConversationUpdated;
use App\Events\Realtime\MessageReceived;
use App\Events\Realtime\MessageSent;
use App\Events\Realtime\MessageStatusUpdated;
use App\Events\Realtime\TimelineUpdated;
use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationConversationEvent;
use App\Models\CommunicationMessage;
use App\Services\Realtime\CommunicationRealtimePublisher;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CommunicationRealtimeEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_realtime_disabled_does_not_publish_events(): void
    {
        config([
            'communication.realtime.enabled' => false,
            'communication.agent.enabled' => false,
        ]);
        Event::fake($this->eventClasses());

        $this->postJson('/api/providers/zapi/webhook', $this->inboundPayload())
            ->assertOk();

        foreach ($this->eventClasses() as $eventClass) {
            Event::assertNotDispatched($eventClass);
        }
    }

    public function test_realtime_disabled_does_not_require_reverb_credentials(): void
    {
        config([
            'communication.realtime.enabled' => false,
            'communication.agent.enabled' => false,
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => null,
            'broadcasting.connections.reverb.secret' => null,
            'broadcasting.connections.reverb.app_id' => null,
        ]);
        Event::fake($this->eventClasses());

        $this->postJson('/api/providers/zapi/webhook', [
            ...$this->inboundPayload(),
            'messageId' => 'realtime-disabled-no-reverb-config',
        ])->assertOk();

        foreach ($this->eventClasses() as $eventClass) {
            Event::assertNotDispatched($eventClass);
        }
    }

    public function test_inbound_publishes_created_received_updated_and_timeline_events(): void
    {
        config([
            'communication.realtime.enabled' => true,
            'communication.agent.enabled' => false,
        ]);
        Event::fake($this->eventClasses());

        $response = $this->postJson('/api/providers/zapi/webhook', $this->inboundPayload())
            ->assertOk();

        $message = CommunicationMessage::findOrFail($response->json('message_id'));

        Event::assertDispatched(ConversationCreated::class, fn ($event): bool => $this->matchesEventContext($event, $message));
        Event::assertDispatched(MessageReceived::class, fn ($event): bool => $this->matchesEventContext($event, $message));
        Event::assertDispatched(ConversationUpdated::class, fn ($event): bool => $this->matchesEventContext($event, $message));
        Event::assertDispatched(TimelineUpdated::class, fn ($event): bool => $this->matchesEventContext($event, $message));
    }

    public function test_events_use_expected_private_channels_queue_and_contracts(): void
    {
        config(['communication.realtime.queue' => 'custom-realtime']);

        foreach ($this->eventClasses() as $eventClass) {
            $event = new $eventClass('tenant-1', 'conversation-1', ['id' => 'resource-1'], now()->toIso8601String());
            $channels = $event->broadcastOn();

            $this->assertInstanceOf(ShouldBroadcast::class, $event);
            $this->assertInstanceOf(ShouldQueue::class, $event);
            $this->assertSame('private-tenant.tenant-1.communication', $channels[0]->name);
            $this->assertSame('private-conversation.conversation-1', $channels[1]->name);
            $this->assertSame('custom-realtime', $event->broadcastQueue());
            $this->assertSame('tenant-1', $event->broadcastWith()['tenant_id']);
            $this->assertSame('conversation-1', $event->broadcastWith()['conversation_id']);
        }
    }

    public function test_payload_is_sanitized_recursively(): void
    {
        config(['communication.realtime.enabled' => true]);
        Event::fake([TimelineUpdated::class]);
        $fixtures = $this->fixtures();

        $timeline = CommunicationConversationEvent::create([
            'tenant_id' => 'tenant-1',
            'conversation_id' => $fixtures['conversation']->id,
            'event_type' => 'message_received',
            'actor_type' => 'system',
            'description' => 'Safe description.',
            'metadata' => [
                'status' => 'received',
                'token' => 'secret-token',
                'nested' => [
                    'authorization' => 'Bearer secret',
                    'provider_response' => ['private' => true],
                    'safe' => 'visible',
                ],
            ],
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        app(CommunicationRealtimePublisher::class)->timeline(TimelineUpdated::class, $timeline);

        Event::assertDispatched(TimelineUpdated::class, function (TimelineUpdated $event): bool {
            $json = json_encode($event->broadcastWith());

            return ! str_contains($json, 'secret-token')
                && ! str_contains($json, 'Bearer secret')
                && ! str_contains($json, 'provider_response')
                && str_contains($json, 'visible');
        });
    }

    public function test_handoff_actions_publish_their_specific_events(): void
    {
        config(['communication.realtime.enabled' => true]);
        Event::fake($this->eventClasses());
        $fixtures = $this->fixtures();
        $conversationId = (string) $fixtures['conversation']->id;

        app(RequestConversationHandoffAction::class)->handle($conversationId, 'tenant-1', 'Need a human.');
        app(AssignConversationAction::class)->handle($conversationId, 'tenant-1', 'user-1', 'Agent One');
        app(ReturnConversationToAiAction::class)->handle($conversationId, 'tenant-1', 'Resolved.');
        app(CloseConversationAction::class)->handle($conversationId, 'tenant-1', 'Done.');
        app(ReopenConversationAction::class)->handle($conversationId, 'tenant-1');

        Event::assertDispatched(ConversationHandoffRequested::class);
        Event::assertDispatched(ConversationAssigned::class);
        Event::assertDispatched(ConversationReturnedToAi::class);
        Event::assertDispatched(ConversationClosed::class);
        Event::assertDispatched(ConversationReopened::class);
        Event::assertDispatched(ConversationUpdated::class, 5);
    }

    public function test_outbound_and_delivery_publish_message_events_for_correct_context(): void
    {
        config([
            'communication.realtime.enabled' => true,
            'communication.providers.zapi.fake' => true,
            'communication.service_token' => 'valid-token',
        ]);
        Event::fake($this->eventClasses());
        $fixtures = $this->fixtures();

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/outbound/messages', [
                'tenant_id' => 'tenant-1',
                'channel_id' => $fixtures['channel']->id,
                'conversation_id' => $fixtures['conversation']->id,
                'contact_id' => $fixtures['contact']->id,
                'external_contact_id' => '5541999999999',
                'message_type' => 'text',
                'text' => 'Hello',
                'idempotency_key' => 'realtime-outbound-1',
            ])
            ->assertCreated();

        $providerMessageId = $response->json('provider_message_id');

        Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($fixtures): bool {
            return $event->tenantId === 'tenant-1'
                && $event->conversationId === $fixtures['conversation']->id;
        });

        $this->postJson('/api/providers/zapi/message-status', [
            'tenant_id' => 'tenant-1',
            'provider_message_id' => $providerMessageId,
            'status' => 'delivered',
            'timestamp' => now()->toIso8601String(),
        ])->assertOk();

        Event::assertDispatched(MessageStatusUpdated::class, function (MessageStatusUpdated $event) use ($fixtures): bool {
            return $event->tenantId === 'tenant-1'
                && $event->conversationId === $fixtures['conversation']->id
                && $event->resource['status'] === 'delivered';
        });
    }

    private function matchesEventContext(AbstractCommunicationRealtimeEvent $event, CommunicationMessage $message): bool
    {
        return $event->tenantId === 'tenant-1'
            && $event->conversationId === $message->conversation_id;
    }

    private function eventClasses(): array
    {
        return [
            ConversationUpdated::class,
            ConversationCreated::class,
            ConversationAssigned::class,
            ConversationReturnedToAi::class,
            ConversationClosed::class,
            ConversationReopened::class,
            ConversationHandoffRequested::class,
            MessageReceived::class,
            MessageSent::class,
            MessageStatusUpdated::class,
            TimelineUpdated::class,
        ];
    }

    private function fixtures(): array
    {
        $channel = CommunicationChannel::create([
            'tenant_id' => 'tenant-1',
            'provider' => 'zapi',
            'external_id' => 'realtime-channel-'.uniqid(),
            'name' => 'Z-API',
            'status' => 'active',
        ]);

        $contact = CommunicationContact::create([
            'tenant_id' => 'tenant-1',
            'provider' => 'zapi',
            'external_id' => '5541999999999',
            'name' => 'Maria',
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

        return compact('channel', 'contact', 'conversation');
    }

    private function inboundPayload(): array
    {
        return [
            'tenant_id' => 'tenant-1',
            'messageId' => 'realtime-inbound-1',
            'phone' => '5541999999999',
            'senderName' => 'Maria',
            'text' => ['message' => 'Oi'],
            'fromMe' => false,
            'isGroup' => false,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
