<?php

namespace App\Http\Controllers\Internal;

use App\Actions\Agents\DispatchMessageToAgentAction;
use App\Enums\MessageDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternalAgentRunRequest;
use App\Models\CommunicationMessage;
use Illuminate\Http\JsonResponse;

class AgentRunController extends Controller
{
    public function __invoke(InternalAgentRunRequest $request, DispatchMessageToAgentAction $action): JsonResponse
    {
        $message = CommunicationMessage::query()
            ->where('id', $request->validated('message_id'))
            ->where('direction', MessageDirection::Inbound->value)
            ->firstOrFail();

        $agentRun = $action->handle($message);

        return response()->json([
            'status' => $agentRun->status,
            'agent_run_id' => $agentRun->id,
            'message_id' => $agentRun->message_id,
            'response_text' => $agentRun->response_text,
            'failed_reason' => $agentRun->failed_reason,
        ], 201);
    }
}
