<?php

namespace App\Support\Tenancy;

use App\Models\CommunicationTenant;

class CurrentTenantConnection
{
    private ?CommunicationTenant $tenant = null;

    private ?string $connectionName = null;

    public function set(CommunicationTenant $tenant, string $connectionName): void
    {
        $this->tenant = $tenant;
        $this->connectionName = $connectionName;
    }

    public function tenant(): ?CommunicationTenant
    {
        return $this->tenant;
    }

    public function connectionName(): ?string
    {
        return $this->connectionName;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->connectionName = null;
    }
}
