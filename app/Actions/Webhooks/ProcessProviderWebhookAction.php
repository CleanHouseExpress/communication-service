<?php

namespace App\Actions\Webhooks;

use App\Actions\Messages\ProcessInboundMessageAction;
use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Enums\ProviderType;
use App\Models\CommunicationChannel;
use App\Models\CommunicationRawEvent;
use App\Services\Providers\ZApiProviderService;
use App\Support\Normalization\ZapiWebhookNormalizer;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProcessProviderWebhookAction
{
    public function __construct(
        private readonly ProcessInboundMessageAction $processInboundMessage,
        private readonly ZapiWebhookNormalizer $zapiWebhookNormalizer,
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
        private readonly ZApiProviderService $zapiProvider,
    ) {}

    public function handle(ProviderType $provider, array $payload, ?string $channelId = null): array
    {
        if ($provider !== ProviderType::Zapi) {
            throw new InvalidArgumentException("Provider [{$provider->value}] is not supported for inbound webhooks yet.");
        }

        $tenantId = $this->tenantIdFromPayload($payload);
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($tenantId);

        try {
            return $this->transaction(function () use ($provider, $payload, $tenantId, $channelId): array {
                $channel = $this->resolveChannel($provider, $channelId, $tenantId);
                $externalEventId = $this->zapiWebhookNormalizer->extractExternalEventId($payload);
                $externalMessageId = $this->zapiWebhookNormalizer->extractExternalMessageId($payload);
                $rawEvent = $this->resolveRawEvent(
                    $provider,
                    $this->zapiProvider->sanitizedPayload($payload),
                    $tenantId ?? $channel?->tenant_id,
                    $channel?->id,
                    $externalEventId,
                    $externalMessageId,
                );

                if ($rawEvent->processed_at !== null) {
                    Log::info('Provider webhook skipped as duplicate.', [
                        'provider' => $provider->value,
                        'message_id' => $rawEvent->external_message_id,
                        'status' => 'duplicate',
                    ]);

                    return [
                        'raw_event' => $rawEvent,
                        'duplicate' => true,
                        'message_created' => false,
                    ];
                }

                $normalized = $channel !== null
                    ? $this->zapiProvider->parseIncomingMessage($payload, $channel)
                    : $this->zapiWebhookNormalizer->normalize($payload, tenantId: $tenantId);
                $result = $this->processInboundMessage->handle($normalized);

                $rawEvent->forceFill([
                    'channel_id' => $result['channel']->id,
                    'normalized_payload' => $normalized->toArray(),
                    'processed_at' => now(),
                ])->save();

                Log::info('Provider webhook processed.', [
                    'tenant_id' => $result['message']->tenant_id ?? null,
                    'provider' => $provider->value,
                    'message_id' => $result['message']->id ?? null,
                    'conversation_id' => $result['conversation']->id ?? null,
                    'status' => 'processed',
                ]);

                return [
                    'raw_event' => $rawEvent->refresh(),
                    'duplicate' => ! $result['message_created'],
                    ...$result,
                ];
            });
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }

    private function resolveRawEvent(
        ProviderType $provider,
        array $payload,
        ?string $tenantId,
        ?string $channelId,
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
            'tenant_id' => $tenantId,
            'channel_id' => $channelId,
            'payload' => $payload,
            'normalized_payload' => null,
            'processed_at' => null,
        ]);
    }

    private function resolveChannel(ProviderType $provider, ?string $channelId, ?string $tenantId): ?CommunicationChannel
    {
        if ($channelId === null || $channelId === '') {
            return null;
        }

        $channelQuery = CommunicationChannel::query()
            ->where('provider', $provider->value);

        if ($tenantId !== null) {
            $channelQuery->where('tenant_id', $tenantId);
        }

        $channel = Str::isUuid($channelId)
            ? $channelQuery->find($channelId)
            : $channelQuery->where('external_id', $channelId)->first();

        if ($channel === null) {
            throw new InvalidArgumentException('Webhook channel was not found.');
        }

        if ($tenantId !== null && $channel->tenant_id !== null && $tenantId !== $channel->tenant_id) {
            throw new InvalidArgumentException('Webhook tenant does not match channel tenant.');
        }

        return $channel;
    }

    private function tenantIdFromPayload(array $payload): ?string
    {
        foreach (['tenant_id', 'tenant.id', 'orchestra_tenant_id'] as $key) {
            $value = data_get($payload, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function transaction(callable $callback): mixed
    {
        $connectionName = $this->currentTenantConnection->connectionName();

        return $connectionName !== null
            ? DB::connection($connectionName)->transaction($callback)
            : DB::transaction($callback);
    }
}
