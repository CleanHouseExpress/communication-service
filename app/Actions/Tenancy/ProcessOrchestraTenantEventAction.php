<?php

namespace App\Actions\Tenancy;

use App\Enums\IntegrationEventStatus;
use App\Models\CommunicationIntegrationEvent;
use App\Models\CommunicationTenant;
use App\Support\Tenancy\OrchestraTenantEventNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOrchestraTenantEventAction
{
    public function __construct(
        private readonly OrchestraTenantEventNormalizer $normalizer,
        private readonly UpsertTenantReplicaAction $upsertTenantReplica,
    ) {}

    public function handle(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            $existing = CommunicationIntegrationEvent::query()
                ->where('source', 'orchestra-api')
                ->where('event_id', $payload['event_id'])
                ->first();

            if ($existing !== null) {
                return [
                    'event' => $existing,
                    'tenant' => $this->tenantForEvent($existing),
                    'idempotent' => true,
                ];
            }

            $eventData = $this->normalizer->normalize($payload);
            $event = CommunicationIntegrationEvent::create([
                'source' => 'orchestra-api',
                'event_id' => $eventData->eventId,
                'event_type' => $eventData->eventType,
                'aggregate_type' => 'tenant',
                'aggregate_id' => $eventData->orchestraTenantId,
                'payload' => $eventData->rawPayload,
                'status' => IntegrationEventStatus::Received->value,
            ]);

            if (! $eventData->recognized) {
                $event->forceFill([
                    'status' => IntegrationEventStatus::Ignored->value,
                    'processed_at' => now(),
                ])->save();

                Log::info('Orchestra tenant event ignored.', [
                    'event_id' => $event->event_id,
                    'event_type' => $event->event_type,
                    'status' => IntegrationEventStatus::Ignored->value,
                ]);

                return [
                    'event' => $event->refresh(),
                    'tenant' => null,
                    'idempotent' => false,
                ];
            }

            try {
                $tenant = $this->upsertTenantReplica->handle($eventData->toTenantReplicaData());

                $event->forceFill([
                    'status' => IntegrationEventStatus::Processed->value,
                    'processed_at' => now(),
                ])->save();

                Log::info('Orchestra tenant event processed.', [
                    'event_id' => $event->event_id,
                    'event_type' => $event->event_type,
                    'tenant_id' => $tenant->orchestra_tenant_id,
                    'status' => IntegrationEventStatus::Processed->value,
                ]);

                return [
                    'event' => $event->refresh(),
                    'tenant' => $tenant->refresh(),
                    'idempotent' => false,
                ];
            } catch (Throwable $exception) {
                $event->forceFill([
                    'status' => IntegrationEventStatus::Failed->value,
                    'processed_at' => now(),
                    'failed_reason' => $this->safeError($exception->getMessage()),
                ])->save();

                Log::warning('Orchestra tenant event failed.', [
                    'event_id' => $event->event_id,
                    'event_type' => $event->event_type,
                    'status' => IntegrationEventStatus::Failed->value,
                    'error' => $event->failed_reason,
                ]);

                return [
                    'event' => $event->refresh(),
                    'tenant' => null,
                    'idempotent' => false,
                ];
            }
        });
    }

    private function tenantForEvent(CommunicationIntegrationEvent $event): ?CommunicationTenant
    {
        if ($event->aggregate_id === null || $event->aggregate_id === '') {
            return null;
        }

        return CommunicationTenant::query()
            ->where('orchestra_tenant_id', $event->aggregate_id)
            ->first();
    }

    private function safeError(string $error): string
    {
        return substr(preg_replace('/(token|authorization|secret)=?[^\\s&]*/i', '$1=[redacted]', $error) ?? $error, 0, 300);
    }
}
