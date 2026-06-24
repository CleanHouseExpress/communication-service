<?php

namespace App\Http\Controllers\Internal;

use App\Actions\Messages\ProcessOutboundMessageAction;
use App\DTO\Messages\OutboundMessageData;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternalOutboundMessageRequest;
use Illuminate\Http\JsonResponse;

class OutboundMessageController extends Controller
{
    public function __invoke(InternalOutboundMessageRequest $request, ProcessOutboundMessageAction $action): JsonResponse
    {
        $result = $action->handle(OutboundMessageData::fromArray($request->validated()));
        $outboundMessage = $result['outbound_message'];

        return response()->json([
            'status' => $outboundMessage->status,
            'duplicate' => $result['duplicate'],
            'outbound_message_id' => $outboundMessage->id,
            'message_id' => $result['communication_message']?->id,
            'provider_message_id' => $outboundMessage->provider_message_id,
            'failed_reason' => $outboundMessage->failed_reason,
        ], $result['duplicate'] ? 200 : 201);
    }
}
