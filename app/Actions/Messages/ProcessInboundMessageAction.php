<?php

namespace App\Actions\Messages;

use App\Actions\Agents\DispatchMessageToAgentAction;
use App\Actions\Conversations\RecordConversationEventAction;
use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\DTO\Messages\InboundMessageData;
use App\Enums\ConversationEventType;
use App\Enums\ConversationHandoffStatus;
use App\Enums\ConversationServiceMode;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Realtime\ConversationCreated;
use App\Events\Realtime\ConversationUpdated;
use App\Events\Realtime\MessageReceived;
use App\Jobs\DispatchAgentForMessageJob;
use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Services\Realtime\CommunicationRealtimePublisher;
use App\Support\Tenancy\CurrentTenantConnection;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessInboundMessageAction
{
    public function __construct(
        private readonly DispatchMessageToAgentAction $dispatchMessageToAgent,
        private readonly TenantResolver $tenantResolver,
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
        private readonly RecordConversationEventAction $recordConversationEvent,
        private readonly CommunicationRealtimePublisher $realtimePublisher,
    ) {}

    public function handle(InboundMessageData $messageData): array
    {
        $this->tenantResolver->enforceIfEnabled($messageData->tenantId);
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($messageData->tenantId);

        try {
            $result = $this->transaction(function () use ($messageData): array {
                $channel = $this->resolveChannel($messageData);
                $contact = $this->resolveContact($messageData);
                $conversation = $this->resolveConversation($messageData, $channel, $contact);
                $conversationCreated = $conversation->wasRecentlyCreated;

                $message = $this->findExistingMessage($messageData);
                $created = false;

                if ($message === null) {
                    $message = CommunicationMessage::create([
                        'tenant_id' => $messageData->tenantId,
                        'conversation_id' => $conversation->id,
                        'contact_id' => $contact->id,
                        'channel_id' => $channel->id,
                        'provider' => $messageData->provider->value,
                        'external_message_id' => $messageData->externalMessageId,
                        'direction' => MessageDirection::Inbound->value,
                        'message_type' => $messageData->messageType->value,
                        'text' => $messageData->text,
                        'payload' => $messageData->rawPayload,
                        'status' => MessageStatus::Received->value,
                        'occurred_at' => $messageData->occurredAt,
                    ]);
                    $created = true;
                }

                if ($conversationCreated) {
                    $this->recordConversationEvent->handle(
                        eventType: ConversationEventType::ConversationCreated,
                        tenantId: $conversation->tenant_id,
                        conversationId: (string) $conversation->id,
                        actorType: 'system',
                        description: 'Conversation created.',
                        metadata: [
                            'provider' => $messageData->provider->value,
                            'service_mode' => $conversation->service_mode,
                        ],
                        occurredAt: $conversation->created_at,
                    );
                }

                if ($created) {
                    $this->recordConversationEvent->handle(
                        eventType: ConversationEventType::MessageReceived,
                        tenantId: $message->tenant_id,
                        conversationId: (string) $conversation->id,
                        actorType: 'contact',
                        messageId: (string) $message->id,
                        actorName: $contact->name,
                        description: 'Inbound message received.',
                        metadata: [
                            'provider' => $message->provider,
                            'message_type' => $message->message_type,
                        ],
                        occurredAt: $message->occurred_at ?? $message->created_at,
                    );
                }

                $conversation->forceFill([
                    'last_message_at' => $messageData->occurredAt ?? now(),
                ])->save();

                return [
                    'channel' => $channel,
                    'contact' => $contact,
                    'conversation' => $conversation->refresh(),
                    'conversation_created' => $conversationCreated,
                    'message' => $message,
                    'message_created' => $created,
                ];
            });

            if ($result['conversation_created']) {
                $this->realtimePublisher->conversation(ConversationCreated::class, $result['conversation']);
            }

            if ($result['message_created']) {
                $this->realtimePublisher->message(MessageReceived::class, $result['message']);
                $this->realtimePublisher->conversation(ConversationUpdated::class, $result['conversation']);
            }

            if ($this->shouldDispatchToAgent($result)) {
                if ((bool) config('communication.queues.agent.enabled', false)) {
                    DispatchAgentForMessageJob::dispatch(
                        (string) $result['message']->id,
                        $result['message']->tenant_id,
                    )->onQueue((string) config('communication.queues.agent.name', 'communication-agent'));
                } else {
                    try {
                        $result['agent_run'] = $this->dispatchMessageToAgent->handle($result['message']);
                    } catch (Throwable $exception) {
                        Log::warning('Inbound agent dispatch failed without interrupting message processing.', [
                            'tenant_id' => $result['message']->tenant_id ?? null,
                            'provider' => $result['message']->provider ?? null,
                            'message_id' => $result['message']->id ?? null,
                            'conversation_id' => $result['message']->conversation_id ?? null,
                            'status' => 'agent_dispatch_failed',
                            'error' => $exception->getMessage(),
                        ]);

                        report($exception);
                    }
                }
            }

            Log::info('Inbound message processed.', [
                'tenant_id' => $result['message']->tenant_id ?? null,
                'provider' => $result['message']->provider ?? null,
                'message_id' => $result['message']->id ?? null,
                'conversation_id' => $result['conversation']->id ?? null,
                'status' => $result['message_created'] ? 'created' : 'duplicate',
            ]);

            return $result;
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

    private function shouldDispatchToAgent(array $result): bool
    {
        /** @var CommunicationMessage|null $message */
        $message = $result['message'] ?? null;

        return (bool) config('communication.agent.enabled', false)
            && (bool) ($result['message_created'] ?? false)
            && $message instanceof CommunicationMessage
            && $message->direction === MessageDirection::Inbound->value
            && $message->message_type === MessageType::Text->value;
    }

    private function resolveChannel(InboundMessageData $messageData): CommunicationChannel
    {
        if ($messageData->channelId !== null) {
            $channel = CommunicationChannel::query()->find($messageData->channelId);

            if ($channel !== null) {
                return $channel;
            }
        }

        return CommunicationChannel::query()->firstOrCreate(
            [
                'tenant_id' => $messageData->tenantId,
                'provider' => $messageData->provider->value,
                'external_id' => $messageData->channelId,
            ],
            [
                'name' => strtoupper($messageData->provider->value),
                'status' => 'active',
                'settings' => [],
            ]
        );
    }

    private function resolveContact(InboundMessageData $messageData): CommunicationContact
    {
        return CommunicationContact::query()->updateOrCreate(
            [
                'tenant_id' => $messageData->tenantId,
                'provider' => $messageData->provider->value,
                'external_id' => $messageData->externalContactId,
            ],
            [
                'name' => $messageData->contactName,
                'phone' => $messageData->contactPhone,
                'metadata' => [
                    'last_external_event_id' => $messageData->externalEventId,
                ],
            ]
        );
    }

    private function resolveConversation(
        InboundMessageData $messageData,
        CommunicationChannel $channel,
        CommunicationContact $contact
    ): CommunicationConversation {
        $conversation = CommunicationConversation::query()
            ->where('tenant_id', $messageData->tenantId)
            ->where('channel_id', $channel->id)
            ->where('contact_id', $contact->id)
            ->where('status', ConversationStatus::Open->value)
            ->latest('created_at')
            ->first();

        if ($conversation !== null) {
            return $conversation;
        }

        return CommunicationConversation::create([
            'tenant_id' => $messageData->tenantId,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::Open->value,
            'service_mode' => ConversationServiceMode::Ai->value,
            'handoff_status' => ConversationHandoffStatus::None->value,
            'last_message_at' => $messageData->occurredAt ?? now(),
            'metadata' => [],
        ]);
    }

    private function findExistingMessage(InboundMessageData $messageData): ?CommunicationMessage
    {
        if ($messageData->externalMessageId === null || $messageData->externalMessageId === '') {
            return null;
        }

        return CommunicationMessage::query()
            ->where('provider', $messageData->provider->value)
            ->where('external_message_id', $messageData->externalMessageId)
            ->first();
    }
}
