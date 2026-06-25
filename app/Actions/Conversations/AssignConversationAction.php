<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationStatus;
use App\Enums\ConversationEventType;
use App\Enums\ConversationHandoffStatus;
use App\Enums\ConversationServiceMode;
use App\Models\CommunicationConversation;

class AssignConversationAction
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
        string $externalUserId,
        ?string $externalUserName = null,
    ): CommunicationConversation {
        return $this->withTenantContext($tenantId, function () use ($conversationId, $tenantId, $externalUserId, $externalUserName): CommunicationConversation {
            $conversation = $this->conversation($conversationId, $tenantId);

            $conversation->forceFill([
                'service_mode' => ConversationServiceMode::Human->value,
                'handoff_status' => ConversationHandoffStatus::Assigned->value,
                'assigned_external_user_id' => $externalUserId,
                'assigned_external_user_name' => $externalUserName,
                'assigned_at' => now(),
                'handoff_assigned_at' => now(),
                'status' => ConversationStatus::Open->value,
            ])->save();

            $this->recordConversationEvent->handle(
                eventType: ConversationEventType::ConversationAssigned,
                tenantId: $conversation->tenant_id,
                conversationId: (string) $conversation->id,
                actorType: 'human',
                actorId: $externalUserId,
                actorName: $externalUserName,
                description: 'Conversation assigned to human.',
                metadata: [],
                occurredAt: $conversation->assigned_at,
            );

            return $conversation->refresh();
        });
    }
}
