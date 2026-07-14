<?php

namespace App\Actions\Conversations;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationHandoffStatus;
use App\Enums\ConversationServiceMode;
use App\Enums\ConversationStatus;
use App\Events\Realtime\ConversationCreated;
use App\Events\Realtime\ConversationUpdated;
use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Services\Realtime\CommunicationRealtimePublisher;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartConversationAction
{
    public function __construct(
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
        private readonly RecordConversationEventAction $recordConversationEvent,
        private readonly CommunicationRealtimePublisher $realtimePublisher,
    ) {}

    public function handle(array $data): CommunicationConversation
    {
        $tenantId = (string) $data['tenant_id'];
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($tenantId);

        try {
            $created = false;
            $conversation = $this->transaction(function () use ($data, $tenantId, &$created): CommunicationConversation {
                $channel = $this->resolveChannel($tenantId, $data);
                $contact = $this->resolveContact($tenantId, $channel, $data);
                $conversation = $this->resolveConversation($tenantId, $channel, $contact, $data);
                $created = $conversation->wasRecentlyCreated;

                if (! $created) {
                    $conversation->forceFill([
                        'channel_id' => $channel->id,
                        'status' => ConversationStatus::Open->value,
                        'service_mode' => ConversationServiceMode::Human->value,
                        'handoff_status' => ConversationHandoffStatus::Assigned->value,
                        'assigned_external_user_id' => $data['assigned_external_user_id'] ?? $conversation->assigned_external_user_id,
                        'assigned_external_user_name' => $data['assigned_external_user_name'] ?? $conversation->assigned_external_user_name,
                        'assigned_at' => $conversation->assigned_at ?? now(),
                        'handoff_assigned_at' => $conversation->handoff_assigned_at ?? now(),
                        'closed_at' => null,
                    ])->save();
                }

                return $conversation->refresh()->load([
                    'contact',
                    'messages' => fn ($query) => $query->latest('created_at')->limit(1),
                ]);
            });

            if ($created) {
                $this->recordConversationEvent->handle(
                    eventType: ConversationEventType::ConversationCreated,
                    tenantId: $conversation->tenant_id,
                    conversationId: (string) $conversation->id,
                    actorType: 'human',
                    actorId: $data['assigned_external_user_id'] ?? null,
                    actorName: $data['assigned_external_user_name'] ?? null,
                    description: 'Conversation started by human.',
                    metadata: ['source' => 'manual'],
                    occurredAt: $conversation->created_at,
                );
                $this->realtimePublisher->conversation(ConversationCreated::class, $conversation);
            }

            $this->realtimePublisher->conversation(ConversationUpdated::class, $conversation);

            return $conversation;
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }

    private function resolveChannel(string $tenantId, array $data): CommunicationChannel
    {
        $query = CommunicationChannel::query()->where('tenant_id', $tenantId);

        if (! empty($data['channel_id'])) {
            $channel = (clone $query)->whereKey($data['channel_id'])->first();
            if ($channel !== null) {
                return $channel;
            }
        }

        $provider = $data['provider'] ?? null;
        if (is_string($provider) && trim($provider) !== '') {
            $query->where('provider', trim($provider));
        } else {
            $query->whereIn('provider', ['evolution', 'whatsapp', 'zapi']);
        }

        $channel = $query
            ->orderByRaw("case when status = 'connected' then 0 when status = 'active' then 1 else 2 end")
            ->latest('updated_at')
            ->first();

        if ($channel === null) {
            throw ValidationException::withMessages([
                'channel_id' => ['No active WhatsApp channel was found for this tenant.'],
            ]);
        }

        return $channel;
    }

    private function resolveContact(string $tenantId, CommunicationChannel $channel, array $data): CommunicationContact
    {
        if (! empty($data['contact_id'])) {
            return CommunicationContact::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($data['contact_id'])
                ->firstOrFail();
        }

        $contactData = $data['contact'] ?? [];
        $phone = $this->normalizePhone((string) ($contactData['phone'] ?? ''));
        $name = trim((string) ($contactData['name'] ?? ''));

        if ($phone === '') {
            throw ValidationException::withMessages([
                'contact.phone' => ['A valid phone number is required.'],
            ]);
        }

        return CommunicationContact::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'provider' => $channel->provider,
                'external_id' => $phone,
            ],
            [
                'name' => $name !== '' ? $name : null,
                'phone' => $phone,
                'metadata' => [
                    'source' => 'manual',
                    'create_user_requested' => (bool) ($data['create_user'] ?? false),
                ],
            ]
        );
    }

    private function resolveConversation(
        string $tenantId,
        CommunicationChannel $channel,
        CommunicationContact $contact,
        array $data,
    ): CommunicationConversation {
        $conversation = CommunicationConversation::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contact->id)
            ->where('status', ConversationStatus::Open->value)
            ->latest('created_at')
            ->first();

        if ($conversation !== null) {
            return $conversation;
        }

        return CommunicationConversation::create([
            'tenant_id' => $tenantId,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::Open->value,
            'service_mode' => ConversationServiceMode::Human->value,
            'handoff_status' => ConversationHandoffStatus::Assigned->value,
            'last_message_at' => null,
            'assigned_external_user_id' => $data['assigned_external_user_id'] ?? null,
            'assigned_external_user_name' => $data['assigned_external_user_name'] ?? null,
            'assigned_at' => now(),
            'handoff_assigned_at' => now(),
            'metadata' => [
                'source' => 'manual',
                'create_user_requested' => (bool) ($data['create_user'] ?? false),
            ],
        ]);
    }

    private function normalizePhone(string $phone): string
    {
        return Str::of($phone)->replaceMatches('/\D+/', '')->toString();
    }

    private function transaction(callable $callback): mixed
    {
        $connectionName = $this->currentTenantConnection->connectionName();

        return $connectionName !== null
            ? DB::connection($connectionName)->transaction($callback)
            : DB::transaction($callback);
    }
}
