<?php

namespace App\Http\Controllers\Internal;

use App\Actions\Messages\ProcessInboundMessageAction;
use App\DTO\Messages\InboundMessageData;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternalInboundMessageRequest;
use Illuminate\Http\JsonResponse;

class InboundMessageController extends Controller
{
    public function __invoke(InternalInboundMessageRequest $request, ProcessInboundMessageAction $action): JsonResponse
    {
        $result = $action->handle(InboundMessageData::fromArray($request->validated()));

        return response()->json([
            'status' => $result['message_created'] ? 'created' : 'duplicate',
            'channel_id' => $result['channel']->id,
            'contact_id' => $result['contact']->id,
            'conversation_id' => $result['conversation']->id,
            'message_id' => $result['message']->id,
        ], $result['message_created'] ? 201 : 200);
    }
}
