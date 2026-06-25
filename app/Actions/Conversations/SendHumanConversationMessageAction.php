<?php

namespace App\Actions\Conversations;

use App\Actions\Messages\ProcessOutboundMessageAction;
use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\DTO\Messages\OutboundMessageData;
use App\Enums\ConversationStatus;
use App\Enums\MessageType;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class SendHumanConversationMessageAction
{
    public function __construct(
        private readonly ProcessOutboundMessageAction $processOutboundMessage,
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
    ) {}

    public function handle(string $conversationId, string $tenantId, string $text): CommunicationMessage
    {
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($tenantId);

        try {
            $conversation = CommunicationConversation::query()
                ->with(['contact', 'channel'])
                ->where('tenant_id', $tenantId)
                ->where('id', $conversationId)
                ->firstOrFail();

            if ($conversation->status === ConversationStatus::Closed->value || $conversation->closed_at !== null) {
                throw new ConflictHttpException('Conversation is closed.');
            }

            $externalContactId = $conversation->contact?->phone ?: $conversation->contact?->external_id;

            if (! is_string($externalContactId) || $externalContactId === '') {
                throw new UnprocessableEntityHttpException('Conversation contact does not have an external destination.');
            }

            $result = $this->processOutboundMessage->handle(new OutboundMessageData(
                tenantId: $tenantId,
                channelId: (string) $conversation->channel_id,
                conversationId: (string) $conversation->id,
                contactId: (string) $conversation->contact_id,
                externalContactId: $externalContactId,
                messageType: MessageType::Text,
                text: $text,
                idempotencyKey: 'human-message:'.Str::uuid()->toString(),
                payload: [
                    'source' => 'human',
                    'origin' => 'orchestra-api',
                ],
            ));

            /** @var CommunicationMessage $message */
            $message = $result['communication_message'];

            $conversation->forceFill([
                'last_message_at' => $message->occurred_at ?? now(),
            ])->save();

            return $message->refresh();
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }
}
