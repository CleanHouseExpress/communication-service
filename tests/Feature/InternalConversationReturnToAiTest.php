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

class InternalConversationReturnToAiTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_service_token(): void
    {
        $conversation = $this->conversation('tenant-1');

        $this->postJson("/api/internal/inbox/conversations/{$conversation->id}/return-to-ai", [
            'tenant_id' => 'tenant-1',
        ])->assertUnauthorized();
    }

    public function test_tenant_id_is_required(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/return-to-ai")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
    }

    public function test_conversation_not_found_returns_404(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/inbox/conversations/00000000-0000-4000-8000-000000000000/return-to-ai', [
                'tenant_id' => 'tenant-1',
            ])
            ->assertNotFound();
    }

    public function test_conversation_from_other_tenant_returns_404(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-2');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/return-to-ai", [
                'tenant_id' => 'tenant-1',
            ])
            ->assertNotFound();
    }

    public function test_closed_conversation_returns_409(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1', [
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/return-to-ai", [
                'tenant_id' => 'tenant-1',
            ])
            ->assertConflict();
    }

    public function test_human_assigned_conversation_returns_to_ai_and_clears_current_assignment(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1', [
            'service_mode' => 'human',
            'handoff_status' => 'assigned',
            'assigned_external_user_id' => 'user-123',
            'assigned_external_user_name' => 'Atendente',
            'assigned_at' => now(),
            'handoff_assigned_at' => now(),
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/return-to-ai", [
                'tenant_id' => 'tenant-1',
                'reason' => 'Atendimento humano finalizado, devolver para IA',
            ])
            ->assertOk()
            ->assertJsonPath('data.service_mode', 'ai')
            ->assertJsonPath('data.handoff_status', 'none')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.assigned_external_user_id', null)
            ->assertJsonPath('data.assigned_external_user_name', null)
            ->assertJsonPath('data.assigned_at', null)
            ->assertJsonPath('data.handoff_assigned_at', null);

        $conversation->refresh();

        $this->assertSame('ai', $conversation->service_mode);
        $this->assertSame('none', $conversation->handoff_status);
        $this->assertNull($conversation->assigned_external_user_id);
        $this->assertNull($conversation->assigned_external_user_name);
        $this->assertNull($conversation->assigned_at);
        $this->assertNull($conversation->handoff_assigned_at);
        $this->assertSame('Atendimento humano finalizado, devolver para IA', $conversation->metadata['return_to_ai_reason']);
        $this->assertArrayHasKey('returned_to_ai_at', $conversation->metadata);
    }

    public function test_inbound_after_return_to_ai_calls_agent_again(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.agent.enabled' => true,
            'communication.providers.zapi.fake' => true,
        ]);

        $conversation = $this->conversation('tenant-1', [
            'service_mode' => 'human',
            'handoff_status' => 'assigned',
            'assigned_external_user_id' => 'user-123',
            'assigned_at' => now(),
            'handoff_assigned_at' => now(),
            'contact_external_id' => '5541999999999',
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/return-to-ai", [
                'tenant_id' => 'tenant-1',
                'reason' => 'Voltar para IA',
            ])
            ->assertOk();

        $this->mock(N8nAgentClient::class, function ($mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andReturn(new AgentResponseData(
                    success: true,
                    responseText: null,
                    shouldReply: false,
                    shouldHandoff: false,
                    rawResponse: ['ok' => true],
                ));
        });

        $payload = [
            ...$this->payload('return-ai-inbound-1'),
            'tenant_id' => 'tenant-1',
        ];

        $this->postJson('/api/providers/zapi/webhook', $payload)
            ->assertOk();

        $this->assertSame(1, CommunicationAgentRun::query()->count());
        $this->assertSame('completed', CommunicationAgentRun::firstOrFail()->status);
    }

    public function test_response_does_not_leak_metadata_or_sensitive_payloads(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1', [
            'metadata' => [
                'secret' => 'very-secret',
            ],
        ]);

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/return-to-ai", [
                'tenant_id' => 'tenant-1',
                'reason' => 'sem segredo',
            ])
            ->assertOk();

        $this->assertStringNotContainsString('very-secret', $response->getContent());
        $this->assertStringNotContainsString('metadata', $response->getContent());
    }

    private function payload(string $messageId): array
    {
        return [
            'messageId' => $messageId,
            'phone' => '5541999999999',
            'senderName' => 'Maria Cliente',
            'text' => [
                'message' => 'Voltei para IA',
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
                'service_mode' => 'human',
                'handoff_status' => 'assigned',
                'last_message_at' => now(),
                'metadata' => [],
            ],
            ...$overrides,
        ]);
    }
}
