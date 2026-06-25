<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationStatus;
use App\Enums\ConversationEventType;
use App\Models\CommunicationConversation;

class CloseConversationAction
{
    use ResolvesTenantConversation;

    public function __construct(
        private readonly \App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly \App\Support\Tenancy\CurrentTenantConnection $currentTenantConnection,
        private readonly RecordConversationEventAction $recordConversationEvent,
    ) {}

    public function handle(string $conversationId, ?string $tenantId, ?string $reason = null): CommunicationConversation
    {
        return $this->withTenantContext($tenantId, function () use ($conversationId, $tenantId, $reason): CommunicationConversation {
            $conversation = $this->conversation($conversationId, $tenantId);
            $metadata = $conversation->metadata ?? [];

            if ($reason !== null && $reason !== '') {
                $metadata['close_reason'] = $reason;
            }

            $conversation->forceFill([
                'status' => ConversationStatus::Closed->value,
                'closed_at' => now(),
                'metadata' => $metadata,
            ])->save();

            $this->recordConversationEvent->handle(
                eventType: ConversationEventType::ConversationClosed,
                tenantId: $conversation->tenant_id,
                conversationId: (string) $conversation->id,
                actorType: 'internal',
                description: $reason ?: 'Conversation closed.',
                metadata: $reason ? ['reason' => $reason] : [],
                occurredAt: $conversation->closed_at,
            );

            return $conversation->refresh();
        });
    }
}
