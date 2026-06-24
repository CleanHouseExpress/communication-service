<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationStatus;
use App\Models\CommunicationConversation;

class CloseConversationAction
{
    use ResolvesTenantConversation;

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

            return $conversation->refresh();
        });
    }
}
