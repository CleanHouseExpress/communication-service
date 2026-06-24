<?php

namespace Tests\Feature;

use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalOutboundMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_outbound_endpoint_requires_service_token(): void
    {
        $fixtures = $this->fixtures();

        $this->postJson('/api/internal/outbound/messages', $this->payload($fixtures))
            ->assertUnauthorized();
    }

    public function test_internal_outbound_endpoint_creates_outbound_message(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.providers.zapi.fake' => true,
        ]);

        $fixtures = $this->fixtures();

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/outbound/messages', $this->payload($fixtures))
            ->assertCreated()
            ->assertJson([
                'status' => 'sent',
                'duplicate' => false,
            ]);

        $this->assertDatabaseHas('communication_outbound_messages', [
            'tenant_id' => 'tenant-1',
            'provider' => 'zapi',
            'external_contact_id' => '5511999999999',
            'idempotency_key' => 'outbound-key-1',
            'message_type' => 'text',
            'text' => 'Ola, como posso ajudar?',
            'status' => 'sent',
        ]);
    }

    public function test_internal_outbound_endpoint_creates_communication_message_with_outbound_direction(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.providers.zapi.fake' => true,
        ]);

        $fixtures = $this->fixtures();

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/outbound/messages', $this->payload($fixtures))
            ->assertCreated();

        $this->assertDatabaseHas('communication_messages', [
            'tenant_id' => 'tenant-1',
            'conversation_id' => $fixtures['conversation']->id,
            'contact_id' => $fixtures['contact']->id,
            'channel_id' => $fixtures['channel']->id,
            'provider' => 'zapi',
            'direction' => 'outbound',
            'message_type' => 'text',
            'text' => 'Ola, como posso ajudar?',
            'status' => 'sent',
        ]);
    }

    public function test_fake_zapi_marks_message_as_sent(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.providers.zapi.fake' => true,
        ]);

        $fixtures = $this->fixtures();

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/outbound/messages', $this->payload($fixtures))
            ->assertCreated()
            ->assertJsonPath('status', 'sent');

        $providerMessageId = $response->json('provider_message_id');

        $this->assertNotEmpty($providerMessageId);
        $this->assertDatabaseHas('communication_outbound_messages', [
            'provider_message_id' => $providerMessageId,
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('communication_messages', [
            'external_message_id' => $providerMessageId,
            'status' => 'sent',
        ]);
    }

    public function test_duplicate_idempotency_key_does_not_duplicate_local_messages(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.providers.zapi.fake' => true,
        ]);

        $fixtures = $this->fixtures();
        $payload = $this->payload($fixtures);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/outbound/messages', $payload)
            ->assertCreated()
            ->assertJsonPath('duplicate', false);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/outbound/messages', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, CommunicationOutboundMessage::count());
        $this->assertSame(1, CommunicationMessage::count());
    }

    public function test_fake_zapi_failure_marks_message_as_failed(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.providers.zapi.fake' => true,
            'communication.providers.zapi.fake_failure' => true,
        ]);

        $fixtures = $this->fixtures();

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/outbound/messages', $this->payload($fixtures))
            ->assertCreated()
            ->assertJson([
                'status' => 'failed',
                'failed_reason' => 'Fake Z-API failure enabled.',
            ]);

        $this->assertDatabaseHas('communication_outbound_messages', [
            'idempotency_key' => 'outbound-key-1',
            'status' => 'failed',
            'failed_reason' => 'Fake Z-API failure enabled.',
        ]);
        $this->assertDatabaseHas('communication_messages', [
            'direction' => 'outbound',
            'status' => 'failed',
        ]);
    }

    private function fixtures(): array
    {
        $channel = CommunicationChannel::create([
            'tenant_id' => 'tenant-1',
            'provider' => 'zapi',
            'external_id' => 'zapi-channel-1',
            'name' => 'Z-API',
            'status' => 'active',
        ]);

        $contact = CommunicationContact::create([
            'tenant_id' => 'tenant-1',
            'provider' => 'zapi',
            'external_id' => '5511999999999',
            'name' => 'Cliente Outbound',
            'phone' => '5511999999999',
        ]);

        $conversation = CommunicationConversation::create([
            'tenant_id' => 'tenant-1',
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        return [
            'channel' => $channel,
            'contact' => $contact,
            'conversation' => $conversation,
        ];
    }

    private function payload(array $fixtures): array
    {
        return [
            'tenant_id' => 'tenant-1',
            'channel_id' => $fixtures['channel']->id,
            'conversation_id' => $fixtures['conversation']->id,
            'contact_id' => $fixtures['contact']->id,
            'external_contact_id' => '5511999999999',
            'message_type' => 'text',
            'text' => 'Ola, como posso ajudar?',
            'idempotency_key' => 'outbound-key-1',
        ];
    }
}
