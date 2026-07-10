<?php

namespace App\Actions\Webhooks;

use App\Models\CommunicationChannel;
use App\Services\Providers\ZApiProviderService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ProcessZapiConnectionEventAction
{
    public function __construct(
        private readonly ZApiProviderService $zapiProvider,
    ) {}

    public function handle(CommunicationChannel $channel, array $payload): array
    {
        $event = $this->zapiProvider->parseConnectionEvent($payload);

        if ($event['tenant_id'] !== null && $channel->tenant_id !== null && $event['tenant_id'] !== $channel->tenant_id) {
            throw new InvalidArgumentException('Webhook tenant does not match channel tenant.');
        }

        $channel->forceFill([
            'status' => 'connected',
            'connected_phone_number' => $event['phone'] ?? $channel->connected_phone_number,
            'last_connected_at' => $event['timestamp'],
            'last_status_check_at' => now(),
        ])->save();

        Log::info('Z-API channel connected.', [
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'provider' => 'zapi',
            'event' => 'connected',
            'status' => 'connected',
        ]);

        return [
            'processed' => true,
            'status' => 'connected',
            'channel_id' => $channel->id,
        ];
    }
}
