<?php

namespace App\Support\Tenancy;

use App\Enums\CommunicationTenantStatus;
use App\Models\CommunicationTenant;

class TenantResolver
{
    public function resolveActive(?string $orchestraTenantId): CommunicationTenant
    {
        if ($orchestraTenantId === null || $orchestraTenantId === '') {
            throw TenantResolutionException::notFound('');
        }

        $tenant = CommunicationTenant::query()
            ->where('orchestra_tenant_id', $orchestraTenantId)
            ->first();

        if ($tenant === null) {
            throw TenantResolutionException::notFound($orchestraTenantId);
        }

        if ($tenant->status !== CommunicationTenantStatus::Active->value) {
            throw TenantResolutionException::inactive($orchestraTenantId);
        }

        return $tenant;
    }

    public function enforceIfEnabled(?string $orchestraTenantId): ?CommunicationTenant
    {
        if (! (bool) config('communication.tenancy.enforce', false)) {
            return null;
        }

        return $this->resolveActive($orchestraTenantId);
    }
}
