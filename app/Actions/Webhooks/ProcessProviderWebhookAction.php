<?php

namespace App\Actions\Webhooks;

use App\Actions\Messages\ProcessInboundMessageAction;
use App\Enums\ProviderType;
use App\Models\CommunicationRawEvent;
use App\Support\Normalization\ZapiWebhookNormalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProcessProviderWebhookAction
{
    public function __construct(
        private readonly ProcessInboundMessageAction $processInboundMessage,
        private readonly ZapiWebhookNormalizer $zapiWebhookNormalizer,
    ) {}

    public function handle(ProviderType $provider, array $payload): array
    {
        if ($provider !== ProviderType::Zapi) {
            throw new InvalidArgumentException("Provider [{$provider->value}] is not supported for inbound webhooks yet.");
        }

        return DB::transaction(function () use ($provider, $payload): array {
            $externalEventId = $this->zapiWebhookNormalizer->extractExternalEventId($payload);
            $externalMessageId = $this->zapiWebhookNormalizer->extractExternalMessageId($payload);
            $rawEvent = $this->resolveRawEvent($provider, $payload, $externalEventId, $externalMessageId);

            if ($rawEvent->processed_at !== null) {
                return [
                    'raw_event' => $rawEvent,
                    'duplicate' => true,
                    'message_created' => false,
                ];
            }

            $normalized = $this->zapiWebhookNormalizer->normalize($payload);
            $result = $this->processInboundMessage->handle($normalized);

            $rawEvent->forceFill([
                'channel_id' => $result['channel']->id,
                'normalized_payload' => $normalized->toArray(),
                'processed_at' => now(),
            ])->save();

            return [
                'raw_event' => $rawEvent->refresh(),
                'duplicate' => ! $result['message_created'],
                ...$result,
            ];
        });
    }

    private function resolveRawEvent(
        ProviderType $provider,
        array $payload,
        ?string $externalEventId,
        ?string $externalMessageId
    ): CommunicationRawEvent {
        $query = CommunicationRawEvent::query()->where('provider', $provider->value);

        if ($externalEventId !== null && $externalEventId !== '') {
            $existing = (clone $query)->where('external_event_id', $externalEventId)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return CommunicationRawEvent::create([
            'provider' => $provider->value,
            'external_event_id' => $externalEventId,
            'external_message_id' => $externalMessageId,
            'tenant_id' => null,
            'channel_id' => null,
            'payload' => $payload,
            'normalized_payload' => null,
            'processed_at' => null,
        ]);
    }
}
