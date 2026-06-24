<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationStatus;
use App\Models\CommunicationConversation;

class RequestConversationHandoffAction
{
    use ResolvesTenantConversation;

    public function handle(string $conversationId, ?string $tenantId, ?string $reason = null): CommunicationConversation
    {
        return $this->withTenantContext($tenantId, function () use ($conversationId, $tenantId, $reason): CommunicationConversation {
            $conversation = $this->conversation($conversationId, $tenantId);

            $conversation->forceFill([
                'handoff_requested_at' => now(),
                'handoff_reason' => $reason,
                'status' => ConversationStatus::Pending->value,
            ])->save();

            return $conversation->refresh();
        });
    }
}
