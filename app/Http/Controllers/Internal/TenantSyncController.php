<?php

namespace App\Http\Controllers\Internal;

use App\Actions\Tenancy\UpsertTenantReplicaAction;
use App\DTO\Tenancy\TenantReplicaData;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternalTenantSyncRequest;
use Illuminate\Http\JsonResponse;

class TenantSyncController extends Controller
{
    public function __invoke(InternalTenantSyncRequest $request, UpsertTenantReplicaAction $action): JsonResponse
    {
        $tenant = $action->handle(TenantReplicaData::fromArray($request->validated()));

        return response()->json([
            'tenant_id' => $tenant->id,
            'orchestra_tenant_id' => $tenant->orchestra_tenant_id,
            'status' => $tenant->status,
            'synced_at' => $tenant->synced_at?->toIso8601String(),
        ]);
    }
}
