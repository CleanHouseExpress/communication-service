<?php

namespace App\Actions\Conversations;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Events\Realtime\ConversationReopened;
use App\Events\Realtime\ConversationUpdated;
use App\Models\CommunicationConversation;
use App\Services\Realtime\CommunicationRealtimePublisher;
use App\Support\Tenancy\CurrentTenantConnection;

class ReopenConversationAction
{
    use ResolvesTenantConversation;

    public function __construct(
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
        private readonly RecordConversationEventAction $recordConversationEvent,
        private readonly CommunicationRealtimePublisher $realtimePublisher,
    ) {}

    public function handle(string $conversationId, ?string $tenantId): CommunicationConversation
    {
        return $this->withTenantContext($tenantId, function () use ($conversationId, $tenantId): CommunicationConversation {
            $conversation = $this->conversation($conversationId, $tenantId);

            $conversation->forceFill([
                'status' => ConversationStatus::Open->value,
                'closed_at' => null,
            ])->save();

            $this->recordConversationEvent->handle(
                eventType: ConversationEventType::ConversationReopened,
                tenantId: $conversation->tenant_id,
                conversationId: (string) $conversation->id,
                actorType: 'internal',
                description: 'Conversation reopened.',
                metadata: [],
                occurredAt: now(),
            );

            $conversation = $conversation->refresh();
            $this->realtimePublisher->conversation(ConversationReopened::class, $conversation);
            $this->realtimePublisher->conversation(ConversationUpdated::class, $conversation);

            return $conversation;
        });
    }
}
