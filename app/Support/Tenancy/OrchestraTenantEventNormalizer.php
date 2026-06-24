<?php

namespace App\Support\Tenancy;

use App\DTO\Tenancy\OrchestraTenantEventData;
use App\Enums\CommunicationTenantStatus;
use Carbon\CarbonImmutable;

class OrchestraTenantEventNormalizer
{
    private const KNOWN_EVENTS = [
        'TenantCreated',
        'TenantUpdated',
        'TenantDisabled',
        'TenantEnabled',
    ];

    public function normalize(array $payload): OrchestraTenantEventData
    {
        $eventType = (string) $payload['event_type'];
        $tenant = is_array($payload['tenant'] ?? null) ? $payload['tenant'] : [];
        $recognized = in_array($eventType, self::KNOWN_EVENTS, true);

        return new OrchestraTenantEventData(
            eventId: (string) $payload['event_id'],
            eventType: $eventType,
            occurredAt: isset($payload['occurred_at']) ? CarbonImmutable::parse($payload['occurred_at']) : null,
            orchestraTenantId: isset($tenant['id']) ? (string) $tenant['id'] : null,
            name: isset($tenant['name']) && is_scalar($tenant['name']) ? (string) $tenant['name'] : null,
            slug: isset($tenant['slug']) && is_scalar($tenant['slug']) ? (string) $tenant['slug'] : null,
            status: $recognized ? $this->statusFor($eventType, $tenant) : null,
            timezone: isset($tenant['timezone']) && is_scalar($tenant['timezone']) ? (string) $tenant['timezone'] : null,
            metadata: is_array($tenant['metadata'] ?? null) ? $tenant['metadata'] : [],
            rawPayload: $payload,
            recognized: $recognized,
        );
    }

    private function statusFor(string $eventType, array $tenant): CommunicationTenantStatus
    {
        if ($eventType === 'TenantDisabled') {
            return CommunicationTenantStatus::Disabled;
        }

        if ($eventType === 'TenantEnabled') {
            return CommunicationTenantStatus::Active;
        }

        $status = $tenant['status'] ?? null;

        if (is_string($status) && in_array($status, array_column(CommunicationTenantStatus::cases(), 'value'), true)) {
            return CommunicationTenantStatus::from($status);
        }

        return CommunicationTenantStatus::Active;
    }
}
