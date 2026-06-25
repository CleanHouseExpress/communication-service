<?php

namespace Tests\Feature;

use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalInboxSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_service_token(): void
    {
        $this->getJson('/api/internal/inbox/summary?tenant_id=tenant-1')
            ->assertUnauthorized();
    }

    public function test_counts_open_pending_closed_assignment_handoff_and_latest_direction(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $openUnassigned = $this->conversation('tenant-1', ['status' => 'open']);
        $this->message($openUnassigned, [
            'direction' => 'inbound',
            'occurred_at' => now(),
        ]);

        $pendingAssigned = $this->conversation('tenant-1', [
            'status' => 'pending',
            'assigned_external_user_id' => 'user-123',
            'assigned_external_user_name' => 'Atendente',
            'assigned_at' => now(),
            'handoff_requested_at' => now(),
            'handoff_reason' => 'Precisa de humano',
        ]);
        $this->message($pendingAssigned, [
            'direction' => 'inbound',
            'occurred_at' => now()->subMinutes(5),
        ]);
        $this->message($pendingAssigned, [
            'direction' => 'outbound',
            'occurred_at' => now(),
        ]);

        $closedAssigned = $this->conversation('tenant-1', [
            'status' => 'closed',
            'assigned_external_user_id' => 'user-456',
            'assigned_at' => now(),
            'closed_at' => now(),
        ]);
        $this->message($closedAssigned, [
            'direction' => 'outbound',
            'occurred_at' => now(),
        ]);

        $this->conversation('tenant-2', [
            'status' => 'pending',
            'assigned_external_user_id' => 'user-123',
            'handoff_requested_at' => now(),
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/summary?tenant_id=tenant-1&assigned_external_user_id=user-123')
            ->assertOk()
            ->assertJsonPath('data.total_open', 1)
            ->assertJsonPath('data.total_pending', 1)
            ->assertJsonPath('data.total_closed', 1)
            ->assertJsonPath('data.total_unassigned', 1)
            ->assertJsonPath('data.total_assigned', 2)
            ->assertJsonPath('data.total_handoff_requested', 1)
            ->assertJsonPath('data.total_my_assigned', 1)
            ->assertJsonPath('data.total_inbound_last_message', 1)
            ->assertJsonPath('data.total_outbound_last_message', 2);
    }

    public function test_total_my_assigned_is_null_without_assigned_external_user_id(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->conversation('tenant-1', [
            'assigned_external_user_id' => 'user-123',
            'assigned_at' => now(),
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/summary?tenant_id=tenant-1')
            ->assertOk()
            ->assertJsonPath('data.total_assigned', 1)
            ->assertJsonPath('data.total_my_assigned', null);
    }

    public function test_does_not_mix_tenants(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->conversation('tenant-1', ['status' => 'open']);
        $this->conversation('tenant-2', ['status' => 'open']);
        $this->conversation('tenant-2', ['status' => 'pending']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/inbox/summary?tenant_id=tenant-1')
            ->assertOk()
            ->assertJsonPath('data.total_open', 1)
            ->assertJsonPath('data.total_pending', 0)
            ->assertJsonPath('data.total_closed', 0);
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
            'external_id' => '5500000000000'.random_int(100, 999),
            'name' => 'Cliente Teste',
            'phone' => '5500000000000',
        ]);

        return CommunicationConversation::create([
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
