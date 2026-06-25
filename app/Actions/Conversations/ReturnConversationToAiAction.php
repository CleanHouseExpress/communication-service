<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationHandoffStatus;
use App\Enums\ConversationServiceMode;
use App\Enums\ConversationStatus;
use App\Models\CommunicationConversation;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ReturnConversationToAiAction
{
    use ResolvesTenantConversation;

    public function handle(string $conversationId, ?string $tenantId, ?string $reason = null): CommunicationConversation
    {
        return $this->withTenantContext($tenantId, function () use ($conversationId, $tenantId, $reason): CommunicationConversation {
            $conversation = $this->conversation($conversationId, $tenantId);

            if ($conversation->status === ConversationStatus::Closed->value || $conversation->closed_at !== null) {
                throw new ConflictHttpException('Conversation is closed.');
            }

            $metadata = $conversation->metadata ?? [];

            if ($reason !== null && $reason !== '') {
                $metadata['return_to_ai_reason'] = $reason;
                $metadata['returned_to_ai_at'] = now()->toIso8601String();
            }

            $conversation->forceFill([
                'service_mode' => ConversationServiceMode::Ai->value,
                'handoff_status' => ConversationHandoffStatus::None->value,
                'assigned_external_user_id' => null,
                'assigned_external_user_name' => null,
                'assigned_at' => null,
                'handoff_assigned_at' => null,
                'status' => ConversationStatus::Open->value,
                'metadata' => $metadata,
            ])->save();

            return $conversation->refresh();
        });
    }
}
