<?php

namespace App\Actions\Webhooks;

use App\Models\CommunicationChannel;
use App\Services\Providers\ZApiProviderService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ProcessZapiDisconnectionEventAction
{
    public function __construct(
        private readonly ZApiProviderService $zapiProvider,
    ) {}

    public function handle(CommunicationChannel $channel, array $payload): array
    {
        $event = $this->zapiProvider->parseDisconnectionEvent($payload);

        if ($event['tenant_id'] !== null && $channel->tenant_id !== null && $event['tenant_id'] !== $channel->tenant_id) {
            throw new InvalidArgumentException('Webhook tenant does not match channel tenant.');
        }

        $channel->forceFill([
            'status' => 'disconnected',
            'last_disconnected_at' => $event['timestamp'],
        ])->save();

        Log::info('Z-API channel disconnected.', [
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'provider' => 'zapi',
            'event' => 'disconnected',
            'status' => 'disconnected',
        ]);

        return [
            'processed' => true,
            'status' => 'disconnected',
            'channel_id' => $channel->id,
        ];
    }
}
