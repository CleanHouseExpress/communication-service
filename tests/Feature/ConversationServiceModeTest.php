<?php

namespace Tests\Feature;

use App\DTO\Agents\AgentResponseData;
use App\Models\CommunicationAgentRun;
use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Services\Agents\N8nAgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationServiceModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_conversation_starts_in_ai_mode_without_handoff(): void
    {
        config([
            'communication.agent.enabled' => false,
            'communication.providers.zapi.fake' => true,
        ]);

        $this->postJson('/api/providers/zapi/webhook', $this->payload('service-mode-new-1'))
            ->assertOk();

        $conversation = CommunicationConversation::firstOrFail();

        $this->assertSame('open', $conversation->status);
        $this->assertSame('ai', $conversation->service_mode);
        $this->assertSame('none', $conversation->handoff_status);
    }

    public function test_assign_moves_conversation_to_human_mode_and_assigned_handoff(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1', [
            'handoff_status' => 'requested',
            'handoff_requested_at' => now(),
            'handoff_requested_by' => 'agent',
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/assign", [
                'tenant_id' => 'tenant-1',
                'external_user_id' => 'user-123',
                'external_user_name' => 'Atendente',
            ])
            ->assertOk()
            ->assertJsonPath('data.service_mode', 'human')
            ->assertJsonPath('data.handoff_status', 'assigned');

        $conversation->refresh();

        $this->assertSame('human', $conversation->service_mode);
        $this->assertSame('assigned', $conversation->handoff_status);
        $this->assertNotNull($conversation->handoff_assigned_at);
    }

    public function test_human_observing_conversation_does_not_change_service_mode(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson("/api/internal/inbox/conversations/{$conversation->id}?tenant_id=tenant-1")
            ->assertOk()
            ->assertJsonPath('data.service_mode', 'ai')
            ->assertJsonPath('data.handoff_status', 'none');

        $conversation->refresh();

        $this->assertSame('ai', $conversation->service_mode);
        $this->assertSame('none', $conversation->handoff_status);
        $this->assertNull($conversation->assigned_external_user_id);
    }

    public function test_inbound_in_human_mode_does_not_call_agent(): void
    {
        config([
            'communication.agent.enabled' => true,
            'communication.providers.zapi.fake' => true,
        ]);

        $conversation = $this->conversation(null, [
            'service_mode' => 'human',
            'handoff_status' => 'assigned',
            'status' => 'open',
            'contact_external_id' => '5541999999999',
        ]);

        $this->mock(N8nAgentClient::class, function ($mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $this->postJson('/api/providers/zapi/webhook', $this->payload('human-mode-inbound-1'))
            ->assertOk();

        $this->assertSame(1, CommunicationAgentRun::query()->count());
        $this->assertSame('skipped', CommunicationAgentRun::firstOrFail()->status);
        $this->assertSame('human', $conversation->refresh()->service_mode);
    }

    public function test_agent_handoff_marks_requested_without_assigning_or_switching_to_human(): void
    {
        config([
            'communication.agent.enabled' => true,
            'communication.providers.zapi.fake' => true,
        ]);

        $this->mock(N8nAgentClient::class, function ($mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andReturn(new AgentResponseData(
                    success: true,
                    responseText: null,
                    shouldReply: false,
                    shouldHandoff: true,
                    rawResponse: ['should_handoff' => true],
                ));
        });

        $this->postJson('/api/providers/zapi/webhook', $this->payload('agent-handoff-mode-1'))
            ->assertOk();

        $conversation = CommunicationConversation::firstOrFail();

        $this->assertSame('ai', $conversation->service_mode);
        $this->assertSame('requested', $conversation->handoff_status);
        $this->assertSame('pending', $conversation->status);
        $this->assertSame('agent', $conversation->handoff_requested_by);
        $this->assertSame('Agent requested human handoff.', $conversation->handoff_requested_reason);
        $this->assertNull($conversation->assigned_external_user_id);
        $this->assertNull($conversation->handoff_assigned_at);
    }

    public function test_can_filter_by_service_mode_and_handoff_status(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $human = $this->conversation('tenant-1', [
            'service_mode' => 'human',
            'handoff_status' => 'assigned',
            'assigned_external_user_id' => 'user-1',
        ]);
        $requested = $this->conversation('tenant-1', [
            'service_mode' => 'ai',
            'handoff_status' => 'requested',
            'handoff_requested_at' => now(),
        ]);
        $this->conversation('tenant-1', [
            'service_mode' => 'ai',
            'handoff_status' => 'none',
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&service_mode=human')
            ->assertOk()
            ->assertJsonPath('data.0.id', $human->id)
            ->assertJsonCount(1, 'data');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&handoff_status=requested')
            ->assertOk()
            ->assertJsonPath('data.0.id', $requested->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_close_and_reopen_keep_service_mode(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1', [
            'service_mode' => 'human',
            'handoff_status' => 'assigned',
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/close", [
                'tenant_id' => 'tenant-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.service_mode', 'human');

        $this->assertSame('human', $conversation->refresh()->service_mode);
        $this->assertSame('closed', $conversation->status);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/reopen", [
                'tenant_id' => 'tenant-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.service_mode', 'human');

        $conversation->refresh();

        $this->assertSame('human', $conversation->service_mode);
        $this->assertSame('open', $conversation->status);
        $this->assertNull($conversation->closed_at);
    }

    private function payload(string $messageId): array
    {
        return [
            'messageId' => $messageId,
            'phone' => '5541999999999',
            'senderName' => 'Maria Cliente',
            'text' => [
                'message' => 'Preciso de ajuda',
            ],
            'fromMe' => false,
            'isGroup' => false,
            'timestamp' => '2026-06-25T12:00:00-03:00',
        ];
    }

    private function conversation(?string $tenantId, array $overrides = []): CommunicationConversation
    {
        $channel = CommunicationChannel::create([
            'tenant_id' => $tenantId,
            'provider' => 'zapi',
            'external_id' => $overrides['channel_external_id'] ?? null,
            'name' => 'Z-API',
            'status' => 'active',
        ]);

        $contact = CommunicationContact::create([
            'tenant_id' => $tenantId,
            'provider' => 'zapi',
            'external_id' => $overrides['contact_external_id'] ?? '5541999999999'.random_int(100, 999),
            'name' => 'Maria Cliente',
            'phone' => '5541999999999',
        ]);

        return CommunicationConversation::create([
            ...[
                'tenant_id' => $tenantId,
                'channel_id' => $channel->id,
                'contact_id' => $contact->id,
                'status' => 'open',
                'service_mode' => 'ai',
                'handoff_status' => 'none',
                'last_message_at' => now(),
                'metadata' => [],
            ],
            ...$overrides,
        ]);
    }
}
