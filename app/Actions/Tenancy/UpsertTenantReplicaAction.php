<?php

namespace App\Actions\Tenancy;

use App\DTO\Tenancy\TenantReplicaData;
use App\Enums\CommunicationTenantStatus;
use App\Models\CommunicationTenant;

class UpsertTenantReplicaAction
{
    public function handle(TenantReplicaData $data): CommunicationTenant
    {
        $disabledAt = $data->status === CommunicationTenantStatus::Disabled ? now() : null;

        return CommunicationTenant::query()->updateOrCreate(
            [
                'orchestra_tenant_id' => $data->orchestraTenantId,
            ],
            [
                'name' => $data->name,
                'slug' => $data->slug,
                'status' => $data->status->value,
                'timezone' => $data->timezone,
                'metadata' => $data->metadata,
                'synced_at' => now(),
                'disabled_at' => $disabledAt,
            ],
        );
    }
}
