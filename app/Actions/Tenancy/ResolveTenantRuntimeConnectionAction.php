<?php

namespace App\Actions\Tenancy;

use App\Enums\CommunicationTenantConnectionStatus;
use App\Services\Tenancy\TenantConnectionConfigurator;
use App\Support\Tenancy\CurrentTenantConnection;
use App\Support\Tenancy\TenantResolutionException;
use App\Support\Tenancy\TenantResolver;

class ResolveTenantRuntimeConnectionAction
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly TenantConnectionConfigurator $tenantConnectionConfigurator,
        private readonly CurrentTenantConnection $currentTenantConnection,
    ) {}

    public function handle(?string $tenantId): ?string
    {
        if (! (bool) config('communication.tenancy.runtime.enabled', false)) {
            return null;
        }

        $tenant = $this->tenantResolver->resolveActive($tenantId);
        $connection = $tenant->connections()
            ->where('status', CommunicationTenantConnectionStatus::Active->value)
            ->whereNotNull('database_name')
            ->latest('updated_at')
            ->first();

        if ($connection === null) {
            throw TenantResolutionException::inactive((string) $tenantId);
        }

        $connectionName = $this->tenantConnectionConfigurator->configure($connection);
        $this->currentTenantConnection->set($tenant, $connectionName);

        return $connectionName;
    }
}
