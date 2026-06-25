<?php

namespace App\Actions\Conversations;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Events\Realtime\ConversationClosed;
use App\Events\Realtime\ConversationUpdated;
use App\Models\CommunicationConversation;
use App\Services\Realtime\CommunicationRealtimePublisher;
use App\Support\Tenancy\CurrentTenantConnection;

class CloseConversationAction
{
    use ResolvesTenantConversation;

    public function __construct(
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
        private readonly RecordConversationEventAction $recordConversationEvent,
        private readonly CommunicationRealtimePublisher $realtimePublisher,
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

            $conversation = $conversation->refresh();
            $this->realtimePublisher->conversation(ConversationClosed::class, $conversation);
            $this->realtimePublisher->conversation(ConversationUpdated::class, $conversation);

            return $conversation;
        });
    }
}
