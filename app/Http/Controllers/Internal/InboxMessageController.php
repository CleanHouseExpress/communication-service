<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\InternalConversationMessagesRequest;
use App\Http\Resources\MessageResource;
use App\Queries\Inbox\ListConversationMessagesQuery;
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
}
