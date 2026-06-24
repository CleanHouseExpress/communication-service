<?php

namespace App\Http\Controllers\Internal;

use App\Actions\Tenancy\ProcessOrchestraTenantEventAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternalOrchestraTenantEventRequest;
use Illuminate\Http\JsonResponse;

class OrchestraTenantEventController extends Controller
{
    public function __invoke(InternalOrchestraTenantEventRequest $request, ProcessOrchestraTenantEventAction $action): JsonResponse
    {
        $result = $action->handle($request->validated());
        $event = $result['event'];
        $tenant = $result['tenant'];

        return response()->json([
            'integration_event_id' => $event->id,
            'event_id' => $event->event_id,
            'status' => $event->status,
            'tenant_id' => $tenant?->id,
            'idempotent' => $result['idempotent'],
        ], $result['idempotent'] ? 200 : 201);
    }
}
