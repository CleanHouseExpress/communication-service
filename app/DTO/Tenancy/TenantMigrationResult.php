<?php

namespace App\DTO\Tenancy;

use App\Models\CommunicationTenant;
use App\Models\CommunicationTenantConnection;

class TenantMigrationResult
{
    public function __construct(
        public readonly CommunicationTenant $tenant,
        public readonly ?CommunicationTenantConnection $connection,
        public readonly bool $success,
        public readonly bool $skipped,
        public readonly bool $pretend,
        public readonly ?string $connectionName = null,
        public readonly ?string $migrationPath = null,
        public readonly ?string $error = null,
    ) {}
}
