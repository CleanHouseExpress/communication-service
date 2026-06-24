<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationStatus;
use App\Models\CommunicationConversation;

class ReopenConversationAction
{
    use ResolvesTenantConversation;

    public function handle(string $conversationId, ?string $tenantId): CommunicationConversation
    {
        return $this->withTenantContext($tenantId, function () use ($conversationId, $tenantId): CommunicationConversation {
            $conversation = $this->conversation($conversationId, $tenantId);

            $conversation->forceFill([
                'status' => ConversationStatus::Open->value,
                'closed_at' => null,
            ])->save();

            return $conversation->refresh();
        });
    }
}
