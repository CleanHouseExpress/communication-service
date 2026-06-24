<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalInboundMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_inbound_endpoint_requires_service_token(): void
    {
        $this->postJson('/api/internal/inbound/messages', $this->payload())
            ->assertUnauthorized();
    }

    public function test_internal_inbound_endpoint_creates_inbound_message(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/inbound/messages', $this->payload())
            ->assertCreated()
            ->assertJson([
                'status' => 'created',
            ]);

        $this->assertDatabaseHas('communication_messages', [
            'provider' => 'zapi',
            'external_message_id' => 'internal-message-1',
            'direction' => 'inbound',
            'message_type' => 'text',
            'text' => 'Mensagem normalizada',
            'status' => 'received',
        ]);

        $this->assertSame(1, CommunicationMessage::count());
    }

    private function payload(): array
    {
        return [
            'provider' => 'zapi',
            'external_event_id' => 'internal-event-1',
            'external_message_id' => 'internal-message-1',
            'external_contact_id' => '5541888888888',
            'contact_name' => 'Contato Interno',
            'contact_phone' => '5541888888888',
            'message_type' => 'text',
            'text' => 'Mensagem normalizada',
            'occurred_at' => '2026-06-24T12:00:00-03:00',
            'raw_payload' => [
                'source' => 'test',
            ],
        ];
    }
}
