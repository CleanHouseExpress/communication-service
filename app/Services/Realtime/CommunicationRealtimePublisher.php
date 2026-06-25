<?php

namespace App\Services\Realtime;

use App\Events\Realtime\AbstractCommunicationRealtimeEvent;
use App\Models\CommunicationConversation;
use App\Models\CommunicationConversationEvent;
use App\Models\CommunicationMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

class CommunicationRealtimePublisher
{
    public function conversation(
        string $eventClass,
        CommunicationConversation $conversation,
    ): void {
        $this->publish(
            eventClass: $eventClass,
            tenantId: $conversation->tenant_id,
            conversationId: (string) $conversation->id,
            resource: [
                'id' => $conversation->id,
                'status' => $conversation->status,
                'service_mode' => $conversation->service_mode,
                'handoff_status' => $conversation->handoff_status,
                'channel_id' => $conversation->channel_id,
                'contact_id' => $conversation->contact_id,
                'assigned_external_user_id' => $conversation->assigned_external_user_id,
                'assigned_external_user_name' => $conversation->assigned_external_user_name,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'updated_at' => $conversation->updated_at?->toIso8601String(),
            ],
        );
    }

    public function message(
        string $eventClass,
        CommunicationMessage $message,
    ): void {
        $this->publish(
            eventClass: $eventClass,
            tenantId: $message->tenant_id,
            conversationId: (string) $message->conversation_id,
            resource: [
                'id' => $message->id,
                'direction' => $message->direction,
                'message_type' => $message->message_type,
                'text' => $message->text,
                'status' => $message->status,
                'provider' => $message->provider,
                'occurred_at' => $message->occurred_at?->toIso8601String(),
                'sent_at' => $message->sent_at?->toIso8601String(),
                'delivered_at' => $message->delivered_at?->toIso8601String(),
                'read_at' => $message->read_at?->toIso8601String(),
                'failed_at' => $message->failed_at?->toIso8601String(),
            ],
        );
    }

    public function timeline(
        string $eventClass,
        CommunicationConversationEvent $timelineEvent,
    ): void {
        $this->publish(
            eventClass: $eventClass,
            tenantId: $timelineEvent->tenant_id,
            conversationId: (string) $timelineEvent->conversation_id,
            resource: [
                'id' => $timelineEvent->id,
                'event_type' => $timelineEvent->event_type,
                'actor_type' => $timelineEvent->actor_type,
                'actor_name' => $timelineEvent->actor_name,
                'description' => $timelineEvent->description,
                'metadata' => $timelineEvent->metadata ?? [],
                'occurred_at' => $timelineEvent->occurred_at?->toIso8601String(),
            ],
        );
    }

    private function publish(
        string $eventClass,
        ?string $tenantId,
        string $conversationId,
        array $resource,
    ): void {
        if (! (bool) config('communication.realtime.enabled', false)) {
            return;
        }

        if (! is_a($eventClass, AbstractCommunicationRealtimeEvent::class, true)) {
            throw new \InvalidArgumentException('Unsupported communication realtime event.');
        }

        try {
            event(new $eventClass(
                tenantId: (string) $tenantId,
                conversationId: $conversationId,
                resource: $this->sanitize($resource),
                occurredAt: now()->toIso8601String(),
            ));
        } catch (Throwable $exception) {
            Log::warning('Communication realtime event publication failed.', [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'event' => class_basename($eventClass),
                'error' => mb_substr($exception->getMessage(), 0, 300),
            ]);
        }
    }

    private function sanitize(array $resource): array
    {
        $unsafeKeys = [
            'payload',
            'raw_payload',
            'headers',
            'token',
            'secret',
            'authorization',
            'password',
            'provider_response',
            'prompt',
            'prompt_ai',
            'request_payload',
            'response_payload',
        ];

        return collect($resource)
            ->reject(fn (mixed $value, string|int $key): bool => in_array(strtolower((string) $key), $unsafeKeys, true))
            ->map(fn (mixed $value): mixed => is_array($value) ? $this->sanitize($value) : $value)
            ->all();
    }
}
