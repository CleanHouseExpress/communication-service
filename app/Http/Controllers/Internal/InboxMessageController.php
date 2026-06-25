<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\InternalConversationMessagesRequest;
use App\Http\Requests\InternalConversationMessageStatusRequest;
use App\Http\Resources\MessageResource;
use App\Http\Resources\MessageStatusResource;
use App\Queries\Inbox\ListConversationMessagesQuery;
use App\Queries\Inbox\ListConversationMessageStatusesQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InboxMessageController extends Controller
{
    public function index(
        string $conversationId,
        InternalConversationMessagesRequest $request,
        ListConversationMessagesQuery $query,
    ): AnonymousResourceCollection {
        return MessageResource::collection($query->handle($conversationId, $request->validated()));
    }

    public function status(
        string $conversationId,
        InternalConversationMessageStatusRequest $request,
        ListConversationMessageStatusesQuery $query,
    ): AnonymousResourceCollection {
        return MessageStatusResource::collection(
            $query->handle($conversationId, $request->validated('tenant_id')),
        );
    }
}
