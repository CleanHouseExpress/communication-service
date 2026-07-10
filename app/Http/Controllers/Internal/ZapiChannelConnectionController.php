<?php

namespace App\Http\Controllers\Internal;

use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Models\CommunicationChannel;
use App\Services\Providers\ZApiProviderService;
use Illuminate\Http\JsonResponse;

class ZapiChannelConnectionController extends Controller
{
    public function qrCode(string $channelId, ZApiProviderService $zapiProvider): JsonResponse
    {
        return response()->json($zapiProvider->getQrCode($this->channel($channelId)));
    }

    public function status(string $channelId, ZApiProviderService $zapiProvider): JsonResponse
    {
        return response()->json($zapiProvider->getConnectionStatus($this->channel($channelId)));
    }

    public function disconnect(string $channelId, ZApiProviderService $zapiProvider): JsonResponse
    {
        return response()->json($zapiProvider->disconnect($this->channel($channelId)));
    }

    public function configureWebhooks(string $channelId, ZApiProviderService $zapiProvider): JsonResponse
    {
        return response()->json($zapiProvider->configureWebhooks($this->channel($channelId)));
    }

    private function channel(string $channelId): CommunicationChannel
    {
        return CommunicationChannel::query()
            ->where('provider', ProviderType::Zapi->value)
            ->findOrFail($channelId);
    }
}
