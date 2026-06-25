<?php

namespace Tests\Feature;

use App\Actions\Agents\DispatchMessageToAgentAction;
use App\Actions\Messages\SendPendingOutboundMessageAction;
use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Jobs\DispatchAgentForMessageJob;
use App\Jobs\SendOutboundMessageJob;
use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationFailedJob;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboundMessage;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CommunicationQueueRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_jobs_use_configured_tries_and_backoff(): void
    {
        config([
            'communication.queues.max_tries' => 7,
            'communication.queues.backoff' => '5,15,45',
        ]);

        $agentJob = new DispatchAgentForMessageJob('message-id', 'tenant-1');
        $outboundJob = new SendOutboundMessageJob('outbound-id', 'tenant-1');

        $this->assertSame(7, $agentJob->tries());
        $this->assertSame([5, 15, 45], $agentJob->backoff());
        $this->assertSame(7, $outboundJob->tries());
        $this->assertSame([5, 15, 45], $outboundJob->backoff());
    }

    public function test_outbound_final_failure_creates_timeline_and_failed_job_without_stack_trace(): void
    {
        config([
            'communication.providers.zapi.fake' => true,
            'communication.providers.zapi.fake_failure' => true,
            'communication.queues.failed_event_enabled' => true,
        ]);

        $fixtures = $this->fixtures();
        $outbound = $this->outbound($fixtures);
        $job = new SendOutboundMessageJob((string) $outbound->id, 'tenant-1');
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(5);
        $job->setJob($queueJob);

        try {
            $job->handle(app(SendPendingOutboundMessageAction::class));
            $this->fail('The outbound job should throw after a provider failure.');
        } catch (RuntimeException) {
            $job->failed(new RuntimeException('Provider failed token=secret-value'));
        }

        $this->assertDatabaseHas('communication_conversation_events', [
            'conversation_id' => $fixtures['conversation']->id,
            'event_type' => 'outbound_failed',
        ]);
        $this->assertDatabaseHas('communication_conversation_events', [
            'conversation_id' => $fixtures['conversation']->id,
            'event_type' => 'job_failed',
        ]);
        $this->assertDatabaseHas('communication_failed_jobs_metadata', [
            'job_name' => 'SendOutboundMessageJob',
            'conversation_id' => $fixtures['conversation']->id,
            'message_id' => $outbound->communication_message_id,
            'attempts' => 5,
            'exception_class' => RuntimeException::class,
        ]);

        $serialized = CommunicationFailedJob::firstOrFail()->toJson();
        $this->assertStringNotContainsString('secret-value', $serialized);
        $this->assertStringNotContainsString('#0', $serialized);
        $this->assertStringNotContainsString('stack', strtolower($serialized));
    }

    public function test_agent_final_failure_creates_agent_and_job_failed_events(): void
    {
        config([
            'communication.agent.enabled' => true,
            'communication.agent.fake' => true,
            'communication.agent.fake_failure' => true,
            'communication.queues.failed_event_enabled' => true,
        ]);

        $fixtures = $this->fixtures();
        $message = $this->inboundMessage($fixtures);
        $job = new DispatchAgentForMessageJob((string) $message->id, 'tenant-1');

        try {
            $job->handle(
                app(DispatchMessageToAgentAction::class),
                app(ResolveTenantRuntimeConnectionAction::class),
                app(CurrentTenantConnection::class),
            );
            $this->fail('The agent job should throw after an agent failure.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $this->assertDatabaseHas('communication_conversation_events', [
            'conversation_id' => $fixtures['conversation']->id,
            'event_type' => 'agent_failed',
        ]);
        $this->assertDatabaseHas('communication_conversation_events', [
            'conversation_id' => $fixtures['conversation']->id,
            'event_type' => 'job_failed',
        ]);
        $this->assertDatabaseHas('communication_failed_jobs_metadata', [
            'job_name' => 'DispatchAgentForMessageJob',
            'message_id' => $message->id,
        ]);
    }

    public function test_manual_retry_marks_failed_job_resolved_after_success(): void
    {
        config([
            'communication.providers.zapi.fake' => true,
            'communication.providers.zapi.fake_failure' => false,
        ]);

        $fixtures = $this->fixtures();
        $outbound = $this->outbound($fixtures, 'failed');
        $failedJob = CommunicationFailedJob::create([
            'tenant_id' => 'tenant-1',
            'job_name' => 'SendOutboundMessageJob',
            'conversation_id' => $fixtures['conversation']->id,
            'message_id' => $outbound->communication_message_id,
            'payload' => [
                'outbound_message_id' => $outbound->id,
                'tenant_id' => 'tenant-1',
            ],
            'exception_class' => RuntimeException::class,
            'attempts' => 5,
            'failed_at' => now(),
            'metadata' => [
                'message' => 'Temporary provider failure.',
            ],
        ]);

        $this->artisan('communication:queue:retry-failed', [
            '--retry' => true,
            '--tenant' => 'tenant-1',
            '--job' => 'SendOutboundMessageJob',
        ])
            ->expectsOutputToContain('Resolved')
            ->assertSuccessful();

        $this->assertNotNull($failedJob->refresh()->resolved_at);
        $this->assertSame('sent', $outbound->refresh()->status);
        $this->assertSame('sent', $outbound->communicationMessage->refresh()->status);
        $this->assertSame(0, CommunicationFailedJob::query()->whereNull('resolved_at')->count());
    }

    private function fixtures(): array
    {
        $channel = CommunicationChannel::create([
            'tenant_id' => 'tenant-1',
            'provider' => 'zapi',
            'external_id' => 'retry-channel-'.uniqid(),
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

    private function inboundMessage(array $fixtures): CommunicationMessage
    {
        return CommunicationMessage::create([
            'tenant_id' => 'tenant-1',
            'conversation_id' => $fixtures['conversation']->id,
            'contact_id' => $fixtures['contact']->id,
            'channel_id' => $fixtures['channel']->id,
            'provider' => 'zapi',
            'external_message_id' => 'retry-inbound-'.uniqid(),
            'direction' => 'inbound',
            'message_type' => 'text',
            'text' => 'Oi',
            'payload' => [],
            'status' => 'received',
            'occurred_at' => now(),
        ]);
    }

    private function outbound(array $fixtures, string $status = 'pending'): CommunicationOutboundMessage
    {
        $message = CommunicationMessage::create([
            'tenant_id' => 'tenant-1',
            'conversation_id' => $fixtures['conversation']->id,
            'contact_id' => $fixtures['contact']->id,
            'channel_id' => $fixtures['channel']->id,
            'provider' => 'zapi',
            'direction' => 'outbound',
            'message_type' => 'text',
            'text' => 'Mensagem de teste',
            'payload' => ['source' => 'system'],
            'status' => $status,
            'occurred_at' => now(),
        ]);

        return CommunicationOutboundMessage::create([
            'tenant_id' => 'tenant-1',
            'channel_id' => $fixtures['channel']->id,
            'conversation_id' => $fixtures['conversation']->id,
            'contact_id' => $fixtures['contact']->id,
            'communication_message_id' => $message->id,
            'provider' => 'zapi',
            'external_contact_id' => '5541999999999',
            'idempotency_key' => 'retry-outbound-'.uniqid(),
            'message_type' => 'text',
            'text' => 'Mensagem de teste',
            'payload' => ['source' => 'system'],
            'status' => $status,
            'failed_reason' => $status === 'failed' ? 'Temporary provider failure.' : null,
        ]);
    }
}
