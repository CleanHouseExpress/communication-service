<?php

namespace App\Actions\Tenancy;

use App\Models\CommunicationTenant;
use App\Models\CommunicationTenantConnection;
use App\Services\Tenancy\TenantDatabaseProvisioner;

class ProvisionTenantDatabaseAction
{
    public function __construct(
        private readonly TenantDatabaseProvisioner $provisioner,
    ) {}

    public function handle(CommunicationTenant $tenant): CommunicationTenantConnection
    {
        return $this->provisioner->provision($tenant);
    }
}
