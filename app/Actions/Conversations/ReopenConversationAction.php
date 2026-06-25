<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationStatus;
use App\Enums\ConversationEventType;
use App\Models\CommunicationConversation;

class ReopenConversationAction
{
    use ResolvesTenantConversation;

    public function __construct(
        private readonly \App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly \App\Support\Tenancy\CurrentTenantConnection $currentTenantConnection,
        private readonly RecordConversationEventAction $recordConversationEvent,
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

            return $conversation->refresh();
        });
    }
}
