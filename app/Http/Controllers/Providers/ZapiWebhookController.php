<?php

namespace App\Http\Controllers\Providers;

use App\Actions\Webhooks\ProcessProviderWebhookAction;
use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ZapiWebhookRequest;
use Illuminate\Http\JsonResponse;

class ZapiWebhookController extends Controller
{
    public function __invoke(ZapiWebhookRequest $request, ProcessProviderWebhookAction $action): JsonResponse
    {
        $result = $action->handle(ProviderType::Zapi, $request->all());

        return response()->json([
            'status' => 'processed',
            'duplicate' => $result['duplicate'],
            'raw_event_id' => $result['raw_event']->id,
            'message_id' => $result['message']->id ?? null,
        ]);
    }
}
