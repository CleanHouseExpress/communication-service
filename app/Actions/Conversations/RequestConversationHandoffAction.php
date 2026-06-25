<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationStatus;
use App\Enums\ConversationEventType;
use App\Enums\ConversationHandoffStatus;
use App\Models\CommunicationConversation;

class RequestConversationHandoffAction
{
    use ResolvesTenantConversation;

    public function __construct(
        private readonly \App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly \App\Support\Tenancy\CurrentTenantConnection $currentTenantConnection,
        private readonly RecordConversationEventAction $recordConversationEvent,
    ) {}

    public function handle(
        string $conversationId,
        ?string $tenantId,
        ?string $reason = null,
        string $requestedBy = 'internal',
    ): CommunicationConversation
    {
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

            return $conversation->refresh();
        });
    }
}
