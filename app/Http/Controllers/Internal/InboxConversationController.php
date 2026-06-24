<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\InternalConversationIndexRequest;
use App\Http\Requests\InternalConversationShowRequest;
use App\Http\Resources\ConversationResource;
use App\Queries\Inbox\ListConversationsQuery;
use App\Queries\Inbox\ShowConversationQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InboxConversationController extends Controller
{
    public function index(InternalConversationIndexRequest $request, ListConversationsQuery $query): AnonymousResourceCollection
    {
        return ConversationResource::collection($query->handle($request->validated()));
    }

    public function show(
        string $conversationId,
        InternalConversationShowRequest $request,
        ShowConversationQuery $query,
    ): ConversationResource {
        return ConversationResource::make($query->handle($conversationId, $request->validated('tenant_id')));
    }
}
