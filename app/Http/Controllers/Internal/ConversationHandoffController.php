<?php

namespace App\Http\Controllers\Internal;

use App\Actions\Conversations\AssignConversationAction;
use App\Actions\Conversations\CloseConversationAction;
use App\Actions\Conversations\ReopenConversationAction;
use App\Actions\Conversations\RequestConversationHandoffAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternalConversationAssignRequest;
use App\Http\Requests\InternalConversationCloseRequest;
use App\Http\Requests\InternalConversationReopenRequest;
use App\Http\Requests\InternalConversationRequestHandoffRequest;
use App\Http\Resources\ConversationResource;

class ConversationHandoffController extends Controller
{
    public function requestHandoff(
        string $conversationId,
        InternalConversationRequestHandoffRequest $request,
        RequestConversationHandoffAction $action,
    ): ConversationResource {
        $data = $request->validated();

        return ConversationResource::make($action->handle($conversationId, $data['tenant_id'], $data['reason'] ?? null));
    }

    public function assign(
        string $conversationId,
        InternalConversationAssignRequest $request,
        AssignConversationAction $action,
    ): ConversationResource {
        $data = $request->validated();

        return ConversationResource::make($action->handle(
            conversationId: $conversationId,
            tenantId: $data['tenant_id'],
            externalUserId: $data['external_user_id'],
            externalUserName: $data['external_user_name'] ?? null,
        ));
    }

    public function close(
        string $conversationId,
        InternalConversationCloseRequest $request,
        CloseConversationAction $action,
    ): ConversationResource {
        $data = $request->validated();

        return ConversationResource::make($action->handle($conversationId, $data['tenant_id'], $data['reason'] ?? null));
    }

    public function reopen(
        string $conversationId,
        InternalConversationReopenRequest $request,
        ReopenConversationAction $action,
    ): ConversationResource {
        $data = $request->validated();

        return ConversationResource::make($action->handle($conversationId, $data['tenant_id']));
    }
}
