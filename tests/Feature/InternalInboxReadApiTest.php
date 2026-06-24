<?php

namespace Tests\Feature;

use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalInboxReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoints_require_service_token(): void
    {
        $conversation = $this->conversation('tenant-1');

        $this->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1')
            ->assertUnauthorized();

        $this->getJson("/api/internal/inbox/conversations/{$conversation->id}?tenant_id=tenant-1")
            ->assertUnauthorized();

        $this->getJson("/api/internal/inbox/conversations/{$conversation->id}/messages?tenant_id=tenant-1")
            ->assertUnauthorized();
    }

    public function test_index_lists_only_conversations_for_tenant_id(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $first = $this->conversation('tenant-1');
        $this->conversation('tenant-2');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_status(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $open = $this->conversation('tenant-1', ['status' => 'open']);
        $this->conversation('tenant-1', ['status' => 'closed']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&status=open')
            ->assertOk()
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_assignment_status(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $assigned = $this->conversation('tenant-1', [
            'assigned_external_user_id' => 'agent-1',
            'assigned_external_user_name' => 'Atendente Um',
            'assigned_at' => now(),
        ]);
        $unassigned = $this->conversation('tenant-1');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&assignment_status=assigned')
            ->assertOk()
            ->assertJsonPath('data.0.id', $assigned->id)
            ->assertJsonPath('data.0.assignment_status', 'assigned')
            ->assertJsonCount(1, 'data');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&assignment_status=unassigned')
            ->assertOk()
            ->assertJsonPath('data.0.id', $unassigned->id)
            ->assertJsonPath('data.0.assignment_status', 'unassigned')
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_assigned_external_user_id(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $match = $this->conversation('tenant-1', [
            'assigned_external_user_id' => 'user-123',
            'assigned_at' => now(),
        ]);
        $this->conversation('tenant-1', [
            'assigned_external_user_id' => 'user-456',
            'assigned_at' => now(),
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&assigned_external_user_id=user-123')
            ->assertOk()
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_handoff_requested(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $handoff = $this->conversation('tenant-1', [
            'handoff_requested_at' => now(),
            'handoff_reason' => 'Precisa de humano',
        ]);
        $this->conversation('tenant-1');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&handoff=requested')
            ->assertOk()
            ->assertJsonPath('data.0.id', $handoff->id)
            ->assertJsonPath('data.0.has_handoff_requested', true)
            ->assertJsonCount(1, 'data');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&has_handoff_requested=true')
            ->assertOk()
            ->assertJsonPath('data.0.id', $handoff->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_closed_flag(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $closed = $this->conversation('tenant-1', [
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        $open = $this->conversation('tenant-1');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&closed=true')
            ->assertOk()
            ->assertJsonPath('data.0.id', $closed->id)
            ->assertJsonCount(1, 'data');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&closed=false')
            ->assertOk()
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_last_message_from(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $inboundLatest = $this->conversation('tenant-1', ['with_message' => false]);
        $this->message($inboundLatest, [
            'direction' => 'outbound',
            'occurred_at' => now()->subMinutes(5),
        ]);
        $this->message($inboundLatest, [
            'direction' => 'inbound',
            'occurred_at' => now(),
        ]);

        $outboundLatest = $this->conversation('tenant-1', ['with_message' => false]);
        $this->message($outboundLatest, [
            'direction' => 'inbound',
            'occurred_at' => now()->subMinutes(5),
        ]);
        $this->message($outboundLatest, [
            'direction' => 'outbound',
            'occurred_at' => now(),
        ]);

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&last_message_from=outbound')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($outboundLatest->id, $response->json('data.0.id'));
    }

    public function test_index_allows_safe_sort_and_direction(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $older = $this->conversation('tenant-1', [
            'last_message_at' => now()->subDay(),
        ]);
        $newer = $this->conversation('tenant-1', [
            'last_message_at' => now(),
        ]);

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&sort=last_message_at&direction=asc')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame([$older->id, $newer->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_index_rejects_invalid_sort_and_direction(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&sort=tenant_id')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&direction=sideways')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('direction');
    }

    public function test_index_searches_by_contact_and_message_text(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $matchByContact = $this->conversation('tenant-1', contact: ['name' => 'Maria Cliente']);
        $matchByMessage = $this->conversation('tenant-1');
        $this->message($matchByMessage, ['text' => 'Preciso remarcar consulta']);
        $this->conversation('tenant-1', contact: ['name' => 'Outro Contato']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&search=Maria')
            ->assertOk()
            ->assertJsonPath('data.0.id', $matchByContact->id)
            ->assertJsonCount(1, 'data');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1&search=remarcar')
            ->assertOk()
            ->assertJsonPath('data.0.id', $matchByMessage->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_show_returns_404_for_conversation_from_other_tenant(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-2');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson("/api/internal/inbox/conversations/{$conversation->id}?tenant_id=tenant-1")
            ->assertNotFound();
    }

    public function test_messages_list_only_messages_for_conversation_and_tenant(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1');
        $message = $this->message($conversation, ['text' => 'Mensagem correta']);
        $otherConversation = $this->conversation('tenant-1');
        $this->message($otherConversation, ['text' => 'Mensagem de outra conversa']);

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson("/api/internal/inbox/conversations/{$conversation->id}/messages?tenant_id=tenant-1")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertContains($message->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_messages_filter_by_direction(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1');
        $inbound = $this->message($conversation, ['direction' => 'inbound']);
        $this->message($conversation, ['direction' => 'outbound']);

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson("/api/internal/inbox/conversations/{$conversation->id}/messages?tenant_id=tenant-1&direction=inbound")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertContains($inbound->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_responses_do_not_include_raw_payload_or_provider_secrets(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $conversation = $this->conversation('tenant-1');
        $this->message($conversation, [
            'payload' => [
                'raw_payload' => 'secret-raw',
                'Authorization' => 'Bearer secret-token',
            ],
        ]);

        $conversationResponse = $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1')
            ->assertOk();

        $messageResponse = $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson("/api/internal/inbox/conversations/{$conversation->id}/messages?tenant_id=tenant-1")
            ->assertOk();

        $this->assertStringNotContainsString('secret-raw', $conversationResponse->getContent());
        $this->assertStringNotContainsString('secret-token', $conversationResponse->getContent());
        $this->assertStringNotContainsString('payload', $messageResponse->getContent());
        $this->assertStringNotContainsString('secret-token', $messageResponse->getContent());
    }

    public function test_runtime_disabled_uses_default_database(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.tenancy.runtime.enabled' => false,
        ]);

        $conversation = $this->conversation('tenant-1');

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/conversations?tenant_id=tenant-1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id);
    }

    private function conversation(string $tenantId, array $overrides = [], array $contact = []): CommunicationConversation
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
            'external_id' => $contact['external_id'] ?? '5500000000000'.random_int(100, 999),
            'name' => $contact['name'] ?? 'Cliente Teste',
            'phone' => $contact['phone'] ?? '5500000000000',
        ]);

        $conversation = CommunicationConversation::create([
            'tenant_id' => $tenantId,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => $overrides['status'] ?? 'open',
            'last_message_at' => $overrides['last_message_at'] ?? now(),
            'handoff_requested_at' => $overrides['handoff_requested_at'] ?? null,
            'handoff_reason' => $overrides['handoff_reason'] ?? null,
            'assigned_external_user_id' => $overrides['assigned_external_user_id'] ?? null,
            'assigned_external_user_name' => $overrides['assigned_external_user_name'] ?? null,
            'assigned_at' => $overrides['assigned_at'] ?? null,
            'closed_at' => $overrides['closed_at'] ?? null,
        ]);

        if (($overrides['with_message'] ?? true) !== false) {
            $this->message($conversation, [
                'text' => $overrides['message_text'] ?? 'Mensagem inicial',
            ]);
        }

        return $conversation;
    }

    private function message(CommunicationConversation $conversation, array $overrides = []): CommunicationMessage
    {
        return CommunicationMessage::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'channel_id' => $conversation->channel_id,
            'provider' => 'zapi',
            'external_message_id' => $overrides['external_message_id'] ?? 'message-'.uniqid(),
            'direction' => $overrides['direction'] ?? 'inbound',
            'message_type' => $overrides['message_type'] ?? 'text',
            'text' => $overrides['text'] ?? 'Mensagem inicial',
            'payload' => $overrides['payload'] ?? [],
            'status' => $overrides['status'] ?? 'received',
            'occurred_at' => $overrides['occurred_at'] ?? now(),
        ]);
    }
}
