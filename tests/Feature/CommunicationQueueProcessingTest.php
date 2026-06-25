<?php

namespace Tests\Feature;

use App\DTO\Agents\AgentResponseData;
use App\Jobs\DispatchAgentForMessageJob;
use App\Jobs\SendOutboundMessageJob;
use App\Models\CommunicationAgentRun;
use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboundMessage;
use App\Services\Agents\N8nAgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CommunicationQueueProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_queue_disabled_keeps_synchronous_behavior(): void
    {
        config([
            'communication.agent.enabled' => true,
            'communication.queues.agent.enabled' => false,
        ]);

        Bus::fake();

        $this->mock(N8nAgentClient::class, function ($mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andReturn(new AgentResponseData(true, null, false, false, ['ok' => true]));
        });

        $this->postJson('/api/providers/zapi/webhook', $this->payload('sync-agent-1'))
            ->assertOk();

        Bus::assertNotDispatched(DispatchAgentForMessageJob::class);
        $this->assertDatabaseHas('communication_agent_runs', ['status' => 'completed']);
    }

    public function test_agent_queue_enabled_dispatches_job_without_calling_n8n_synchronously(): void
    {
        config([
            'communication.agent.enabled' => true,
            'communication.queues.agent.enabled' => true,
            'communication.queues.agent.name' => 'communication-agent',
        ]);

        Bus::fake();

        $this->mock(N8nAgentClient::class, function ($mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $this->postJson('/api/providers/zapi/webhook', $this->payload('async-agent-1'))
            ->assertOk();

        Bus::assertDispatched(DispatchAgentForMessageJob::class);
        $this->assertSame(0, CommunicationAgentRun::query()->count());
    }

    public function test_agent_job_processes_message_and_records_timeline(): void
    {
        config([
            'communication.agent.enabled' => true,
            'communication.providers.zapi.fake' => true,
        ]);

        $fixtures = $this->fixtures();
        $message = $this->message($fixtures);

        $this->mock(N8nAgentClient::class, function ($mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andReturn(new AgentResponseData(true, null, false, false, ['ok' => true]));
        });

        app(DispatchAgentForMessageJob::class, [
            'messageId' => (string) $message->id,
            'tenantId' => 'tenant-1',
        ])->handle(
            app(\App\Actions\Agents\DispatchMessageToAgentAction::class),
            app(\App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction::class),
            app(\App\Support\Tenancy\CurrentTenantConnection::class),
        );

        $this->assertDatabaseHas('communication_agent_runs', ['status' => 'completed']);
        $this->assertDatabaseHas('communication_conversation_events', ['event_type' => 'agent_started']);
        $this->assertDatabaseHas('communication_conversation_events', ['event_type' => 'agent_finished']);
    }

    public function test_outbound_queue_disabled_sends_synchronously(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.providers.zapi.fake' => true,
            'communication.queues.outbound.enabled' => false,
        ]);

        Bus::fake();
        $fixtures = $this->fixtures();

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/outbound/messages', $this->outboundPayload($fixtures))
            ->assertCreated()
            ->assertJsonPath('status', 'sent');

        Bus::assertNotDispatched(SendOutboundMessageJob::class);
        $this->assertDatabaseHas('communication_conversation_events', ['event_type' => 'message_sent']);
    }

    public function test_outbound_queue_enabled_creates_pending_and_dispatches_job(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.providers.zapi.fake' => true,
            'communication.queues.outbound.enabled' => true,
            'communication.queues.outbound.name' => 'communication-outbound',
        ]);

        Bus::fake();
        $fixtures = $this->fixtures();

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/outbound/messages', $this->outboundPayload($fixtures))
            ->assertCreated()
            ->assertJsonPath('status', 'pending');

        Bus::assertDispatched(SendOutboundMessageJob::class);
        $this->assertDatabaseHas('communication_outbound_messages', [
            'idempotency_key' => 'queue-outbound-key-1',
            'status' => 'pending',
        ]);
    }

    public function test_outbound_job_sends_pending_and_marks_sent(): void
    {
        config([
            'communication.providers.zapi.fake' => true,
            'communication.queues.outbound.enabled' => true,
        ]);

        $fixtures = $this->fixtures();
        $this->postOutboundPending($fixtures);
        $outbound = CommunicationOutboundMessage::firstOrFail();

        app(SendOutboundMessageJob::class, [
            'outboundMessageId' => (string) $outbound->id,
            'tenantId' => 'tenant-1',
        ])->handle(app(\App\Actions\Messages\SendPendingOutboundMessageAction::class));

        $this->assertSame('sent', $outbound->refresh()->status);
        $this->assertSame('sent', $outbound->communicationMessage->refresh()->status);
        $this->assertDatabaseHas('communication_conversation_events', ['event_type' => 'message_sent']);
    }

    public function test_jobs_do_not_log_or_return_secrets(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.providers.zapi.fake' => true,
            'communication.queues.outbound.enabled' => true,
        ]);

        Bus::fake();
        $fixtures = $this->fixtures();

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/outbound/messages', [
                ...$this->outboundPayload($fixtures),
                'payload' => [
                    'token' => 'secret-token',
                ],
            ])
            ->assertCreated();

        $this->assertStringNotContainsString('secret-token', $response->getContent());
    }

    private function fixtures(): array
    {
        $channel = CommunicationChannel::create([
            'tenant_id' => 'tenant-1',
            'provider' => 'zapi',
            'external_id' => 'queue-channel-'.uniqid(),
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

        return compact('channel', 'contact', 'conversation');
    }

    private function message(array $fixtures): CommunicationMessage
    {
        return CommunicationMessage::create([
            'tenant_id' => 'tenant-1',
            'conversation_id' => $fixtures['conversation']->id,
            'contact_id' => $fixtures['contact']->id,
            'channel_id' => $fixtures['channel']->id,
            'provider' => 'zapi',
            'external_message_id' => 'queue-message-'.uniqid(),
            'direction' => 'inbound',
            'message_type' => 'text',
            'text' => 'Oi',
            'payload' => [],
            'status' => 'received',
            'occurred_at' => now(),
        ]);
    }

    private function outboundPayload(array $fixtures): array
    {
        return [
            'tenant_id' => 'tenant-1',
            'channel_id' => $fixtures['channel']->id,
            'conversation_id' => $fixtures['conversation']->id,
            'contact_id' => $fixtures['contact']->id,
            'external_contact_id' => '5541999999999',
            'message_type' => 'text',
            'text' => 'Mensagem outbound',
            'idempotency_key' => 'queue-outbound-key-1',
        ];
    }

    private function postOutboundPending(array $fixtures): void
    {
        app(\App\Actions\Messages\ProcessOutboundMessageAction::class)
            ->handle(\App\DTO\Messages\OutboundMessageData::fromArray($this->outboundPayload($fixtures)));
    }

    private function payload(string $messageId): array
    {
        return [
            'tenant_id' => 'tenant-1',
            'messageId' => $messageId,
            'phone' => '5541999999999',
            'senderName' => 'Maria Cliente',
            'text' => ['message' => 'Oi'],
            'fromMe' => false,
            'isGroup' => false,
            'timestamp' => '2026-06-25T12:00:00-03:00',
        ];
    }
}
