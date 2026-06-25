<?php

namespace App\Actions\Messages;

use App\Actions\Conversations\RecordConversationEventAction;
use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Enums\ConversationEventType;
use App\Enums\MessageStatus;
use App\Events\Realtime\ConversationUpdated;
use App\Events\Realtime\MessageStatusUpdated;
use App\Models\CommunicationOutboundMessage;
use App\Services\Realtime\CommunicationRealtimePublisher;
use App\Support\Tenancy\CurrentTenantConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessProviderMessageStatusAction
{
    public function __construct(
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
        private readonly RecordConversationEventAction $recordConversationEvent,
        private readonly CommunicationRealtimePublisher $realtimePublisher,
    ) {}

    public function handle(array $data): array
    {
        $tenantId = isset($data['tenant_id']) ? (string) $data['tenant_id'] : null;
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($tenantId);

        try {
            return $this->transaction(function () use ($data, $tenantId): array {
                $outboundMessage = $this->findOutboundMessage($data, $tenantId);

                if ($outboundMessage === null) {
                    Log::info('Provider message status ignored because outbound message was not found.', [
                        'tenant_id' => $tenantId,
                        'provider' => 'zapi',
                        'provider_message_id' => $data['provider_message_id'] ?? null,
                        'status' => $data['status'],
                    ]);

                    return [
                        'processed' => false,
                        'duplicate' => false,
                        'message_id' => null,
                        'status' => $data['status'],
                    ];
                }

                $message = $outboundMessage->communicationMessage;
                $currentStatus = $message?->status ?? $outboundMessage->status;
                $nextStatus = (string) $data['status'];

                if (! $this->shouldAdvance($currentStatus, $nextStatus)) {
                    return [
                        'processed' => true,
                        'duplicate' => true,
                        'message_id' => $message?->id,
                        'status' => $currentStatus,
                    ];
                }

                $occurredAt = CarbonImmutable::parse((string) $data['timestamp']);
                $timestamps = $this->timestampsFor($nextStatus, $occurredAt, $message?->toArray() ?? []);
                $providerMessageId = $data['provider_message_id']
                    ?? $outboundMessage->provider_message_id
                    ?? $data['external_message_id']
                    ?? null;

                $outboundMessage->forceFill([
                    'status' => $nextStatus,
                    'provider_message_id' => $providerMessageId,
                    ...$timestamps,
                ])->save();

                $message?->forceFill([
                    'status' => $nextStatus,
                    'provider_message_id' => $providerMessageId,
                    ...$timestamps,
                ])->save();

                if ($message !== null) {
                    $this->recordConversationEvent->handle(
                        eventType: $this->eventType($nextStatus),
                        tenantId: $message->tenant_id,
                        conversationId: (string) $message->conversation_id,
                        actorType: 'provider',
                        messageId: (string) $message->id,
                        description: "Message status updated to {$nextStatus}.",
                        metadata: [
                            'provider' => $outboundMessage->provider,
                            'status' => $nextStatus,
                            'provider_message_id' => $providerMessageId,
                        ],
                        occurredAt: $occurredAt,
                    );

                    $this->realtimePublisher->message(MessageStatusUpdated::class, $message->refresh());

                    if ($message->conversation !== null) {
                        $this->realtimePublisher->conversation(
                            ConversationUpdated::class,
                            $message->conversation,
                        );
                    }
                }

                Log::info('Provider message status processed.', [
                    'tenant_id' => $outboundMessage->tenant_id,
                    'provider' => $outboundMessage->provider,
                    'message_id' => $message?->id,
                    'conversation_id' => $outboundMessage->conversation_id,
                    'status' => $nextStatus,
                ]);

                return [
                    'processed' => true,
                    'duplicate' => false,
                    'message_id' => $message?->id,
                    'status' => $nextStatus,
                ];
            });
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }

    private function findOutboundMessage(array $data, ?string $tenantId): ?CommunicationOutboundMessage
    {
        $query = CommunicationOutboundMessage::query()->with('communicationMessage');

        if ($tenantId !== null && $tenantId !== '') {
            $query->where('tenant_id', $tenantId);
        }

        return $query->where(function ($query) use ($data): void {
            if (! empty($data['provider_message_id'])) {
                $query->where('provider_message_id', $data['provider_message_id'])
                    ->orWhereHas('communicationMessage', function ($messageQuery) use ($data): void {
                        $messageQuery->where('provider_message_id', $data['provider_message_id'])
                            ->orWhere('external_message_id', $data['provider_message_id']);
                    });
            }

            if (! empty($data['external_message_id'])) {
                $method = ! empty($data['provider_message_id']) ? 'orWhere' : 'where';
                $query->{$method}('provider_message_id', $data['external_message_id'])
                    ->orWhereHas('communicationMessage', function ($messageQuery) use ($data): void {
                        $messageQuery->where('external_message_id', $data['external_message_id'])
                            ->orWhere('provider_message_id', $data['external_message_id']);
                    });
            }
        })->first();
    }

    private function shouldAdvance(?string $currentStatus, string $nextStatus): bool
    {
        if ($currentStatus === $nextStatus || $currentStatus === MessageStatus::Read->value) {
            return false;
        }

        if ($nextStatus === MessageStatus::Failed->value) {
            return ! in_array($currentStatus, [
                MessageStatus::Delivered->value,
                MessageStatus::Read->value,
            ], true);
        }

        if ($currentStatus === MessageStatus::Failed->value) {
            return false;
        }

        $rank = [
            MessageStatus::Pending->value => 0,
            MessageStatus::Sending->value => 1,
            MessageStatus::Sent->value => 2,
            MessageStatus::Delivered->value => 3,
            MessageStatus::Read->value => 4,
        ];

        return ($rank[$nextStatus] ?? -1) > ($rank[$currentStatus] ?? -1);
    }

    private function timestampsFor(string $status, CarbonImmutable $occurredAt, array $current): array
    {
        return match ($status) {
            MessageStatus::Sent->value => ['sent_at' => $occurredAt],
            MessageStatus::Delivered->value => [
                'sent_at' => $current['sent_at'] ?? $occurredAt,
                'delivered_at' => $occurredAt,
            ],
            MessageStatus::Read->value => [
                'sent_at' => $current['sent_at'] ?? $occurredAt,
                'delivered_at' => $current['delivered_at'] ?? $occurredAt,
                'read_at' => $occurredAt,
            ],
            MessageStatus::Failed->value => ['failed_at' => $occurredAt],
            default => [],
        };
    }

    private function eventType(string $status): ConversationEventType
    {
        return match ($status) {
            MessageStatus::Sent->value => ConversationEventType::MessageSent,
            MessageStatus::Delivered->value => ConversationEventType::MessageDelivered,
            MessageStatus::Read->value => ConversationEventType::MessageRead,
            MessageStatus::Failed->value => ConversationEventType::MessageFailed,
        };
    }

    private function transaction(callable $callback): mixed
    {
        $connectionName = $this->currentTenantConnection->connectionName();

        return $connectionName !== null
            ? DB::connection($connectionName)->transaction($callback)
            : DB::transaction($callback);
    }
}
