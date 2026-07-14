<?php

namespace App\Http\Controllers\Internal;

use App\Actions\Conversations\StartConversationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternalContactIndexRequest;
use App\Http\Requests\InternalConversationIndexRequest;
use App\Http\Requests\InternalConversationShowRequest;
use App\Http\Requests\InternalConversationStartRequest;
use App\Http\Resources\ContactResource;
use App\Http\Resources\ConversationResource;
use App\Queries\Inbox\ListContactsQuery;
use App\Queries\Inbox\ListConversationsQuery;
use App\Queries\Inbox\ShowConversationQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InboxConversationController extends Controller
{
    public function contacts(InternalContactIndexRequest $request, ListContactsQuery $query): AnonymousResourceCollection
    {
        return ContactResource::collection($query->handle($request->validated()));
    }

    public function index(InternalConversationIndexRequest $request, ListConversationsQuery $query): AnonymousResourceCollection
    {
        return ConversationResource::collection($query->handle($request->validated()));
    }

    public function store(InternalConversationStartRequest $request, StartConversationAction $action): JsonResponse
    {
        return ConversationResource::make($action->handle($request->validated()))
            ->response()
            ->setStatusCode(200);
    }

    public function show(
        string $conversationId,
        InternalConversationShowRequest $request,
        ShowConversationQuery $query,
    ): ConversationResource {
        return ConversationResource::make($query->handle($conversationId, $request->validated('tenant_id')));
    }
}