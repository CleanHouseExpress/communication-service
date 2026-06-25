<?php

namespace App\Actions\Conversations;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationHandoffStatus;
use App\Enums\ConversationStatus;
use App\Events\Realtime\ConversationHandoffRequested;
use App\Events\Realtime\ConversationUpdated;
use App\Models\CommunicationConversation;
use App\Services\Realtime\CommunicationRealtimePublisher;
use App\Support\Tenancy\CurrentTenantConnection;

class RequestConversationHandoffAction
{
    use ResolvesTenantConversation;

    public function __construct(
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
        private readonly RecordConversationEventAction $recordConversationEvent,
        private readonly CommunicationRealtimePublisher $realtimePublisher,
    ) {}

    public function handle(
        string $conversationId,
        ?string $tenantId,
        ?string $reason = null,
        string $requestedBy = 'internal',
    ): CommunicationConversation {
        return $this->withTenantContext($tenantId, function () use ($conversationId, $tenantId, $reason, $requestedBy): CommunicationConversation {
            $conversation = $this->conversation($conversationId, $tenantId);

            $conversation->forceFill([
                'handoff_requested_at' => now(),
                'handoff_reason' => $reason,
                'handoff_status' => ConversationHandoffStatus::Requested->value,
                'handoff_requested_by' => $requestedBy,
                'handoff_requested_reason' => $reason,
                'status' => ConversationStatus::Pending->value,
            ])->save();

            $this->recordConversationEvent->handle(
                eventType: ConversationEventType::HandoffRequested,
                tenantId: $conversation->tenant_id,
                conversationId: (string) $conversation->id,
                actorType: $requestedBy,
                description: $reason ?: 'Handoff requested.',
                metadata: [
                    'requested_by' => $requestedBy,
                ],
                occurredAt: $conversation->handoff_requested_at,
            );

            $conversation = $conversation->refresh();
            $this->realtimePublisher->conversation(ConversationHandoffRequested::class, $conversation);
            $this->realtimePublisher->conversation(ConversationUpdated::class, $conversation);

            return $conversation;
        });
    }
}
