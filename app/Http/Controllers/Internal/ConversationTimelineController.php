<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\InternalConversationTimelineRequest;
use App\Http\Resources\ConversationTimelineResource;
use App\Queries\Inbox\ListConversationTimelineQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationTimelineController extends Controller
{
    public function __invoke(
        string $conversationId,
        InternalConversationTimelineRequest $request,
        ListConversationTimelineQuery $query,
    ): AnonymousResourceCollection {
        return ConversationTimelineResource::collection($query->handle($conversationId, $request->validated('tenant_id')));
    }
}
