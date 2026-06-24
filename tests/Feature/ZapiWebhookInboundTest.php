<?php

namespace Tests\Feature;

use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\CommunicationRawEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZapiWebhookInboundTest extends TestCase
{
    use RefreshDatabase;

    public function test_zapi_webhook_returns_200(): void
    {
        $this->postJson('/api/providers/zapi/webhook', $this->payload())
            ->assertOk()
            ->assertJson([
                'status' => 'processed',
                'duplicate' => false,
            ]);
    }

    public function test_zapi_webhook_creates_raw_event(): void
    {
        $this->postJson('/api/providers/zapi/webhook', $this->payload())->assertOk();

        $this->assertDatabaseHas('communication_raw_events', [
            'provider' => 'zapi',
            'external_event_id' => 'zapi-message-1',
            'external_message_id' => 'zapi-message-1',
        ]);

        $this->assertNotNull(CommunicationRawEvent::first()->processed_at);
    }

    public function test_zapi_webhook_creates_contact_conversation_and_inbound_message(): void
    {
        $this->postJson('/api/providers/zapi/webhook', $this->payload())->assertOk();

        $this->assertDatabaseHas('communication_contacts', [
            'provider' => 'zapi',
            'external_id' => '5541999999999',
            'name' => 'Maria Cliente',
            'phone' => '5541999999999',
        ]);

        $this->assertDatabaseHas('communication_conversations', [
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('communication_messages', [
            'provider' => 'zapi',
            'external_message_id' => 'zapi-message-1',
            'direction' => 'inbound',
            'message_type' => 'text',
            'text' => 'Oi, preciso de ajuda',
            'status' => 'received',
        ]);
    }

    public function test_duplicate_zapi_webhook_does_not_create_duplicate_message(): void
    {
        $this->postJson('/api/providers/zapi/webhook', $this->payload())->assertOk();

        $this->postJson('/api/providers/zapi/webhook', $this->payload())
            ->assertOk()
            ->assertJson([
                'status' => 'processed',
                'duplicate' => true,
            ]);

        $this->assertSame(1, CommunicationRawEvent::count());
        $this->assertSame(1, CommunicationContact::count());
        $this->assertSame(1, CommunicationConversation::count());
        $this->assertSame(1, CommunicationMessage::count());
    }

    public function test_duplicate_zapi_webhook_by_external_event_id_does_not_create_duplicate_message(): void
    {
        $payload = [
            ...$this->payload(),
            'eventId' => 'zapi-event-1',
        ];

        $this->postJson('/api/providers/zapi/webhook', $payload)->assertOk();

        $this->postJson('/api/providers/zapi/webhook', [
            ...$payload,
            'text' => [
                'message' => 'Mensagem repetida pelo mesmo evento',
            ],
        ])
            ->assertOk()
            ->assertJson([
                'duplicate' => true,
            ]);

        $this->assertSame(1, CommunicationRawEvent::count());
        $this->assertSame(1, CommunicationMessage::count());
    }

    public function test_duplicate_zapi_webhook_by_external_message_id_does_not_create_duplicate_message(): void
    {
        $this->postJson('/api/providers/zapi/webhook', [
            ...$this->payload(),
            'eventId' => 'zapi-event-1',
        ])->assertOk();

        $this->postJson('/api/providers/zapi/webhook', [
            ...$this->payload(),
            'eventId' => 'zapi-event-2',
        ])
            ->assertOk()
            ->assertJson([
                'duplicate' => true,
            ]);

        $this->assertSame(2, CommunicationRawEvent::count());
        $this->assertSame(1, CommunicationMessage::count());
    }

    private function payload(): array
    {
        return [
            'messageId' => 'zapi-message-1',
            'phone' => '5541999999999',
            'senderName' => 'Maria Cliente',
            'text' => [
                'message' => 'Oi, preciso de ajuda',
            ],
            'fromMe' => false,
            'isGroup' => false,
            'timestamp' => '2026-06-24T12:00:00-03:00',
        ];
    }
}
