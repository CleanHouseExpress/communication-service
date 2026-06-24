<?php

namespace Tests\Feature;

use App\DTO\Agents\AgentResponseData;
use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Services\Agents\N8nAgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalConversationHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoints_require_service_token(): void
    {
        $conversation = $this->conversation('tenant-1');

        foreach (['request-handoff', 'assign', 'close', 'reopen'] as $action) {
            $this->postJson("/api/internal/inbox/conversations/{$conversation->id}/{$action}", [
                'tenant_id' => 'tenant-1',
            ])->assertUnauthorized();
        }
    }

    public function test_request_handoff_marks_conversation_as_pending(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/request-handoff", [
                'tenant_id' => 'tenant-1',
                'reason' => 'Cliente pediu humano',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.handoff_reason', 'Cliente pediu humano');

        $conversation->refresh();

        $this->assertNotNull($conversation->handoff_requested_at);
        $this->assertSame('pending', $conversation->status);
    }

    public function test_assign_marks_conversation_assigned_and_open(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1', ['status' => 'pending']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/assign", [
                'tenant_id' => 'tenant-1',
                'external_user_id' => 'user-123',
                'external_user_name' => 'Atendente Externo',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.assigned_external_user_id', 'user-123')
            ->assertJsonPath('data.assigned_external_user_name', 'Atendente Externo');

        $conversation->refresh();

        $this->assertNotNull($conversation->assigned_at);
    }

    public function test_close_marks_conversation_closed(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/close", [
                'tenant_id' => 'tenant-1',
                'reason' => 'Resolvido',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $conversation->refresh();

        $this->assertNotNull($conversation->closed_at);
        $this->assertSame('Resolvido', $conversation->metadata['close_reason']);
    }

    public function test_reopen_sets_open_and_clears_closed_at(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1', [
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/reopen", [
                'tenant_id' => 'tenant-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.closed_at', null);

        $this->assertNull($conversation->refresh()->closed_at);
    }

    public function test_does_not_allow_changing_conversation_from_other_tenant(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-2');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/request-handoff", [
                'tenant_id' => 'tenant-1',
            ])
            ->assertNotFound();
    }

    public function test_agent_should_handoff_marks_conversation(): void
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
                    rawResponse: [
                        'should_handoff' => true,
                    ],
                ));
        });

        $payload = [
            'messageId' => 'handoff-agent-message-1',
            'phone' => '5541999999999',
            'senderName' => 'Maria Cliente',
            'text' => [
                'message' => 'Quero falar com humano',
            ],
            'fromMe' => false,
            'isGroup' => false,
            'timestamp' => '2026-06-24T12:00:00-03:00',
        ];

        $this->postJson('/api/providers/zapi/webhook', $payload)
            ->assertOk();

        $conversation = CommunicationConversation::firstOrFail();

        $this->assertSame('pending', $conversation->status);
        $this->assertNotNull($conversation->handoff_requested_at);
        $this->assertSame('Agent requested human handoff.', $conversation->handoff_reason);
    }

    public function test_responses_do_not_include_sensitive_payloads(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1', [
            'metadata' => [
                'secret' => 'very-secret',
            ],
        ]);

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/inbox/conversations/{$conversation->id}/request-handoff", [
                'tenant_id' => 'tenant-1',
                'reason' => 'sem segredo',
            ])
            ->assertOk();

        $this->assertStringNotContainsString('very-secret', $response->getContent());
        $this->assertStringNotContainsString('metadata', $response->getContent());
    }

    private function conversation(string $tenantId, array $overrides = []): CommunicationConversation
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
            'external_id' => '5541999999999',
            'name' => 'Maria Cliente',
            'phone' => '5541999999999',
        ]);

        return CommunicationConversation::create([
            ...[
                'tenant_id' => $tenantId,
                'channel_id' => $channel->id,
                'contact_id' => $contact->id,
                'status' => 'open',
                'last_message_at' => now(),
                'metadata' => [],
            ],
            ...$overrides,
        ]);
    }
}
