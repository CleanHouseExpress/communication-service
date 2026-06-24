<?php

namespace Tests\Feature;

use App\Models\CommunicationAgentRun;
use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentN8nBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_when_agent_disabled_inbound_does_not_create_outbound(): void
    {
        config([
            'communication.agent.enabled' => false,
            'communication.providers.zapi.fake' => true,
        ]);

        $this->postJson('/api/providers/zapi/webhook', $this->payload())
            ->assertOk();

        $this->assertSame(1, CommunicationMessage::where('direction', 'inbound')->count());
        $this->assertSame(0, CommunicationAgentRun::count());
        $this->assertSame(0, CommunicationOutboundMessage::count());
    }

    public function test_fake_agent_creates_completed_agent_run_for_inbound_text(): void
    {
        config([
            'communication.agent.enabled' => true,
            'communication.agent.fake' => true,
            'communication.providers.zapi.fake' => true,
        ]);

        $this->postJson('/api/providers/zapi/webhook', $this->payload())
            ->assertOk();

        $this->assertDatabaseHas('communication_agent_runs', [
            'provider' => 'zapi',
            'agent' => 'n8n',
            'status' => 'completed',
            'response_text' => 'Resposta automatica do agente.',
        ]);
    }

    public function test_fake_agent_reply_creates_outbound_message(): void
    {
        config([
            'communication.agent.enabled' => true,
            'communication.agent.fake' => true,
            'communication.providers.zapi.fake' => true,
        ]);

        $this->postJson('/api/providers/zapi/webhook', $this->payload())
            ->assertOk();

        $agentRun = CommunicationAgentRun::firstOrFail();

        $this->assertDatabaseHas('communication_outbound_messages', [
            'provider' => 'zapi',
            'external_contact_id' => '5541999999999',
            'idempotency_key' => "agent-run:{$agentRun->id}",
            'message_type' => 'text',
            'text' => 'Resposta automatica do agente.',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('communication_messages', [
            'direction' => 'outbound',
            'message_type' => 'text',
            'text' => 'Resposta automatica do agente.',
            'status' => 'sent',
        ]);
    }

    public function test_fake_agent_failure_marks_run_failed_and_inbound_still_returns_200(): void
    {
        config([
            'communication.agent.enabled' => true,
            'communication.agent.fake' => true,
            'communication.agent.fake_failure' => true,
            'communication.providers.zapi.fake' => true,
        ]);

        $this->postJson('/api/providers/zapi/webhook', $this->payload())
            ->assertOk()
            ->assertJson([
                'status' => 'processed',
            ]);

        $this->assertDatabaseHas('communication_messages', [
            'direction' => 'inbound',
            'text' => 'Oi, preciso de ajuda',
            'status' => 'received',
        ]);
        $this->assertDatabaseHas('communication_agent_runs', [
            'status' => 'failed',
            'failed_reason' => 'Fake n8n agent failure enabled.',
        ]);
        $this->assertSame(0, CommunicationOutboundMessage::count());
    }

    public function test_internal_agent_run_endpoint_requires_service_token(): void
    {
        $message = $this->createInboundMessage();

        $this->postJson('/api/internal/agent/runs', [
            'message_id' => $message->id,
        ])->assertUnauthorized();
    }

    public function test_internal_agent_run_endpoint_dispatches_agent_for_valid_message_id(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.agent.enabled' => true,
            'communication.agent.fake' => true,
            'communication.providers.zapi.fake' => true,
        ]);

        $message = $this->createInboundMessage();

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/agent/runs', [
                'message_id' => $message->id,
            ])
            ->assertCreated()
            ->assertJson([
                'status' => 'completed',
                'message_id' => $message->id,
                'response_text' => 'Resposta automatica do agente.',
            ]);

        $this->assertDatabaseHas('communication_agent_runs', [
            'message_id' => $message->id,
            'status' => 'completed',
        ]);
        $this->assertSame(1, CommunicationOutboundMessage::count());
    }

    private function payload(): array
    {
        return [
            'messageId' => 'zapi-agent-message-1',
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

    private function createInboundMessage(): CommunicationMessage
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
            'external_id' => '5541999999999',
            'name' => 'Maria Cliente',
            'phone' => '5541999999999',
        ]);

        $conversation = CommunicationConversation::create([
            'tenant_id' => 'tenant-1',
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        return CommunicationMessage::create([
            'tenant_id' => 'tenant-1',
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'provider' => 'zapi',
            'external_message_id' => 'manual-agent-message-1',
            'direction' => 'inbound',
            'message_type' => 'text',
            'text' => 'Oi, preciso de ajuda',
            'payload' => [],
            'status' => 'received',
            'occurred_at' => now(),
        ]);
    }
}
