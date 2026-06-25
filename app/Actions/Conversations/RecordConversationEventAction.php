<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Events\Realtime\TimelineUpdated;
use App\Models\CommunicationConversationEvent;
use App\Services\Realtime\CommunicationRealtimePublisher;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordConversationEventAction
{
    public function __construct(
        private readonly CommunicationRealtimePublisher $realtimePublisher,
    ) {}

    public function handle(
        ConversationEventType $eventType,
        ?string $tenantId,
        string $conversationId,
        string $actorType,
        ?string $messageId = null,
        ?string $agentRunId = null,
        ?string $actorId = null,
        ?string $actorName = null,
        ?string $description = null,
        array $metadata = [],
        mixed $occurredAt = null,
    ): ?CommunicationConversationEvent {
        try {
            $conversationEvent = CommunicationConversationEvent::create([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'agent_run_id' => $agentRunId,
                'event_type' => $eventType->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_name' => $actorName,
                'description' => $description,
                'metadata' => $metadata,
                'occurred_at' => $occurredAt ?? now(),
                'created_at' => now(),
            ]);

            $this->realtimePublisher->timeline(TimelineUpdated::class, $conversationEvent);

            return $conversationEvent;
        } catch (Throwable $exception) {
            Log::warning('Conversation event recording failed.', [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'event_type' => $eventType->value,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
