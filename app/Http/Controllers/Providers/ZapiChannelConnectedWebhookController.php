<?php

namespace App\Http\Controllers\Providers;

use App\Actions\Webhooks\ProcessZapiConnectionEventAction;
use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Models\CommunicationChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZapiChannelConnectedWebhookController extends Controller
{
    public function __invoke(
        string $channelId,
        Request $request,
        ProcessZapiConnectionEventAction $action,
    ): JsonResponse {
        $channel = CommunicationChannel::query()->where('provider', ProviderType::Zapi->value)->findOrFail($channelId);

        return response()->json($action->handle($channel, $request->all()));
    }
}
