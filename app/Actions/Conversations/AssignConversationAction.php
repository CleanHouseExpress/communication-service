<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationStatus;
use App\Enums\ConversationHandoffStatus;
use App\Enums\ConversationServiceMode;
use App\Models\CommunicationConversation;

class AssignConversationAction
{
    use ResolvesTenantConversation;

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

            return $conversation->refresh();
        });
    }
}
