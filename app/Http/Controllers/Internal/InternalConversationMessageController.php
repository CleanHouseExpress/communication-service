<?php

namespace App\Http\Controllers\Internal;

use App\Actions\Conversations\SendHumanConversationMessageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternalConversationSendMessageRequest;
use App\Http\Resources\MessageResource;
use Illuminate\Http\JsonResponse;

class InternalConversationMessageController extends Controller
{
    public function send(
        string $conversationId,
        InternalConversationSendMessageRequest $request,
        SendHumanConversationMessageAction $action,
    ): JsonResponse {
        $data = $request->validated();

        return MessageResource::make($action->handle($conversationId, $data['tenant_id'], $data['text']))
            ->response()
            ->setStatusCode(200);
    }
}
