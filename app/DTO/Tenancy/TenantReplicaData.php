<?php

namespace App\DTO\Tenancy;

use App\Enums\CommunicationTenantStatus;

class TenantReplicaData
{
    public function __construct(
        public readonly string $orchestraTenantId,
        public readonly ?string $name,
        public readonly ?string $slug,
        public readonly CommunicationTenantStatus $status,
        public readonly ?string $timezone,
        public readonly array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            orchestraTenantId: $data['orchestra_tenant_id'],
            name: $data['name'] ?? null,
            slug: $data['slug'] ?? null,
            status: CommunicationTenantStatus::from($data['status']),
            timezone: $data['timezone'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }
}
