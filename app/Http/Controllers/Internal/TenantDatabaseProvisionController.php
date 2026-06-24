<?php

namespace App\Http\Controllers\Internal;

use App\Actions\Tenancy\ProvisionTenantDatabaseAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\InternalTenantDatabaseProvisionRequest;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;

class TenantDatabaseProvisionController extends Controller
{
    public function __invoke(
        InternalTenantDatabaseProvisionRequest $request,
        TenantResolver $tenantResolver,
        ProvisionTenantDatabaseAction $action,
    ): JsonResponse {
        $orchestraTenantId = (string) $request->route('orchestra_tenant_id');
        $tenant = $tenantResolver->resolveActive($orchestraTenantId);
        $connection = $action->handle($tenant);

        return response()->json([
            'tenant_id' => $tenant->id,
            'orchestra_tenant_id' => $tenant->orchestra_tenant_id,
            'connection_id' => $connection->id,
            'database_name' => $connection->database_name,
            'database_host' => $connection->database_host,
            'database_port' => $connection->database_port,
            'database_driver' => $connection->database_driver,
            'status' => $connection->status,
            'migrated_at' => $connection->migrated_at?->toIso8601String(),
        ]);
    }
}
