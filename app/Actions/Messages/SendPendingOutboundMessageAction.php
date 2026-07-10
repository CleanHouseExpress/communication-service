<?php

namespace App\Actions\Messages;

use App\Actions\Conversations\RecordConversationEventAction;
use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Enums\ConversationEventType;
use App\Enums\MessageStatus;
use App\Events\Realtime\ConversationUpdated;
use App\Events\Realtime\MessageSent;
use App\Models\CommunicationOutboundMessage;
use App\Services\Providers\ZApiProviderService;
use App\Services\Realtime\CommunicationRealtimePublisher;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SendPendingOutboundMessageAction
{
    public function __construct(
        private readonly ZApiProviderService $zapiProvider,
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
        private readonly RecordConversationEventAction $recordConversationEvent,
        private readonly CommunicationRealtimePublisher $realtimePublisher,
    ) {}

    public function handle(string $outboundMessageId, ?string $tenantId): ?CommunicationOutboundMessage
    {
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($tenantId);

        try {
            return $this->transaction(function () use ($outboundMessageId, $tenantId): ?CommunicationOutboundMessage {
                $query = CommunicationOutboundMessage::query()->with('communicationMessage');

                if ($tenantId !== null && $tenantId !== '') {
                    $query->where('tenant_id', $tenantId);
                } else {
                    $query->where(function ($query): void {
                        $query->whereNull('tenant_id')
                            ->orWhere('tenant_id', '');
                    });
                }

                $outboundMessage = $query->where('id', $outboundMessageId)->first();

                if ($outboundMessage === null) {
                    Log::warning('Pending outbound message not found for send.', [
                        'tenant_id' => $tenantId,
                        'outbound_message_id' => $outboundMessageId,
                        'status' => 'not_found',
                    ]);

                    return null;
                }

                $communicationMessage = $outboundMessage->communicationMessage;

                if ($outboundMessage->status === MessageStatus::Sent->value) {
                    return $outboundMessage->refresh();
                }

                $outboundMessage->forceFill(['status' => MessageStatus::Sending->value])->save();
                $communicationMessage?->forceFill(['status' => MessageStatus::Sending->value])->save();

                $channel = $outboundMessage->channel;

                if ($channel === null) {
                    throw new InvalidArgumentException('Outbound message channel was not found.');
                }

                $result = match ($outboundMessage->message_type) {
                    'text' => $this->zapiProvider->sendMessage($channel, [
                        'phone' => $outboundMessage->external_contact_id,
                        'message' => (string) $outboundMessage->text,
                    ]),
                    default => throw new InvalidArgumentException('Only text outbound messages are supported in this phase.'),
                };

                if ($result->success) {
                    $outboundMessage->forceFill([
                        'status' => MessageStatus::Sent->value,
                        'provider_message_id' => $result->providerMessageId,
                        'provider_response' => $result->response,
                        'failed_reason' => null,
                        'sent_at' => now(),
                    ])->save();

                    $communicationMessage?->forceFill([
                        'external_message_id' => $result->providerMessageId,
                        'provider_message_id' => $result->providerMessageId,
                        'status' => MessageStatus::Sent->value,
                        'sent_at' => $outboundMessage->sent_at,
                    ])->save();

                    Log::info('Outbound message sent.', [
                        'tenant_id' => $outboundMessage->tenant_id,
                        'provider' => $outboundMessage->provider,
                        'message_id' => $communicationMessage?->id,
                        'conversation_id' => $outboundMessage->conversation_id,
                        'status' => MessageStatus::Sent->value,
                    ]);

                    if ($communicationMessage !== null) {
                        $this->recordConversationEvent->handle(
                            eventType: ConversationEventType::MessageSent,
                            tenantId: $communicationMessage->tenant_id,
                            conversationId: (string) $communicationMessage->conversation_id,
                            actorType: (string) ($outboundMessage->payload['source'] ?? 'system'),
                            messageId: (string) $communicationMessage->id,
                            description: 'Outbound message sent.',
                            metadata: [
                                'provider' => $outboundMessage->provider,
                                'message_type' => $outboundMessage->message_type,
                                'source' => $outboundMessage->payload['source'] ?? null,
                            ],
                            occurredAt: $outboundMessage->sent_at,
                        );

                        $this->realtimePublisher->message(MessageSent::class, $communicationMessage->refresh());

                        if ($communicationMessage->conversation !== null) {
                            $this->realtimePublisher->conversation(
                                ConversationUpdated::class,
                                $communicationMessage->conversation,
                            );
                        }
                    }
                } else {
                    $outboundMessage->forceFill([
                        'status' => MessageStatus::Failed->value,
                        'provider_response' => $result->response,
                        'failed_reason' => $result->error,
                    ])->save();

                    $communicationMessage?->forceFill([
                        'status' => MessageStatus::Failed->value,
                        'failed_at' => now(),
                    ])->save();

                    $outboundMessage->forceFill([
                        'failed_at' => $communicationMessage?->failed_at ?? now(),
                    ])->save();

                    Log::warning('Outbound message failed.', [
                        'tenant_id' => $outboundMessage->tenant_id,
                        'provider' => $outboundMessage->provider,
                        'message_id' => $communicationMessage?->id,
                        'conversation_id' => $outboundMessage->conversation_id,
                        'status' => MessageStatus::Failed->value,
                        'error' => $result->error,
                    ]);

                    if ($communicationMessage !== null) {
                        $this->recordConversationEvent->handle(
                            eventType: ConversationEventType::OutboundFailed,
                            tenantId: $communicationMessage->tenant_id,
                            conversationId: (string) $communicationMessage->conversation_id,
                            actorType: (string) ($outboundMessage->payload['source'] ?? 'system'),
                            messageId: (string) $communicationMessage->id,
                            description: 'Outbound message send failed.',
                            metadata: [
                                'provider' => $outboundMessage->provider,
                                'message_type' => $outboundMessage->message_type,
                                'error' => $result->error !== null
                                    ? mb_substr($result->error, 0, 300)
                                    : null,
                            ],
                        );
                    }
                }

                return $outboundMessage->refresh();
            });
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }

    private function transaction(callable $callback): mixed
    {
        $connectionName = $this->currentTenantConnection->connectionName();

        return $connectionName !== null
            ? DB::connection($connectionName)->transaction($callback)
            : DB::transaction($callback);
    }
}
