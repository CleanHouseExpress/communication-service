<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationStatus;
use App\Enums\ConversationHandoffStatus;
use App\Models\CommunicationConversation;

class RequestConversationHandoffAction
{
    use ResolvesTenantConversation;

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

            return $conversation->refresh();
        });
    }
}
