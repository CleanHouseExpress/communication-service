<?php

namespace App\DTO\Tenancy;

use App\Enums\CommunicationTenantStatus;
use Carbon\CarbonImmutable;

class OrchestraTenantEventData
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly ?CarbonImmutable $occurredAt,
        public readonly ?string $orchestraTenantId,
        public readonly ?string $name,
        public readonly ?string $slug,
        public readonly ?CommunicationTenantStatus $status,
        public readonly ?string $timezone,
        public readonly array $metadata,
        public readonly array $rawPayload,
        public readonly bool $recognized,
    ) {}

    public function toTenantReplicaData(): TenantReplicaData
    {
        return new TenantReplicaData(
            orchestraTenantId: (string) $this->orchestraTenantId,
            name: $this->name,
            slug: $this->slug,
            status: $this->status ?? CommunicationTenantStatus::Pending,
            timezone: $this->timezone,
            metadata: $this->metadata,
        );
    }
}
