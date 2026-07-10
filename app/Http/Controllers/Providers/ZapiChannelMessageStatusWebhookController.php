<?php

namespace App\Http\Controllers\Providers;

use App\Actions\Messages\ProcessProviderMessageStatusAction;
use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Models\CommunicationChannel;
use App\Services\Providers\ZApiProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZapiChannelMessageStatusWebhookController extends Controller
{
    public function __invoke(
        string $channelId,
        Request $request,
        ZApiProviderService $zapiProvider,
        ProcessProviderMessageStatusAction $action,
    ): JsonResponse {
        CommunicationChannel::query()->where('provider', ProviderType::Zapi->value)->findOrFail($channelId);

        return response()->json($action->handle($zapiProvider->parseMessageStatus($request->all())));
    }
}
