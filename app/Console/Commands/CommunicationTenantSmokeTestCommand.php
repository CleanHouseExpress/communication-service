<?php

namespace App\Console\Commands;

use App\Actions\Messages\ProcessInboundMessageAction;
use App\DTO\Messages\InboundMessageData;
use App\Enums\CommunicationTenantConnectionStatus;
use App\Enums\MessageType;
use App\Enums\ProviderType;
use App\Models\CommunicationTenant;
use App\Models\CommunicationTenantConnection;
use App\Support\Tenancy\CurrentTenantConnection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

class CommunicationTenantSmokeTestCommand extends Command
{
    protected $signature = 'communication:tenant:smoke-test
        {orchestra_tenant_id : Orchestra tenant id}
        {--send-inbound : Process a fake inbound message through the internal flow}';

    protected $description = 'Run a local smoke test for tenant runtime.';

    public function handle(ProcessInboundMessageAction $processInboundMessage, CurrentTenantConnection $currentTenantConnection): int
    {
        $tenant = CommunicationTenant::query()
            ->where('orchestra_tenant_id', (string) $this->argument('orchestra_tenant_id'))
            ->first();

        if ($tenant === null || $tenant->status !== 'active') {
            $this->error('Communication tenant is not active or was not found.');

            return self::FAILURE;
        }

        $connection = $this->connectionFor($tenant);

        if ($connection === null) {
            $this->error('Communication tenant connection is not active.');

            return self::FAILURE;
        }

        if ($connection->migrated_at === null) {
            $this->error('Communication tenant connection has not been migrated.');

            return self::FAILURE;
        }

        $this->info('Tenant runtime smoke prerequisites: ok');

        if (! $this->option('send-inbound')) {
            return self::SUCCESS;
        }

        $previousRuntime = config('communication.tenancy.runtime.enabled');
        $previousAgentEnabled = config('communication.agent.enabled');
        $previousZapiFake = config('communication.providers.zapi.fake');

        Config::set('communication.tenancy.runtime.enabled', true);
        Config::set('communication.agent.enabled', false);
        Config::set('communication.providers.zapi.fake', true);

        try {
            $externalMessageId = 'smoke-'.now()->format('YmdHis');

            $processInboundMessage->handle(new InboundMessageData(
                provider: ProviderType::Zapi,
                tenantId: $tenant->orchestra_tenant_id,
                channelId: null,
                externalEventId: $externalMessageId,
                externalMessageId: $externalMessageId,
                externalContactId: '5500000000000',
                contactName: 'Smoke Test',
                contactPhone: '5500000000000',
                messageType: MessageType::Text,
                text: 'Smoke test inbound',
                occurredAt: CarbonImmutable::now(),
                rawPayload: [
                    'source' => 'tenant-smoke-test',
                ],
            ));

            $connectionName = config('communication.tenancy.runtime.connection_name', 'communication_tenant');
            $exists = DB::connection($connectionName)
                ->table('communication_messages')
                ->where('external_message_id', $externalMessageId)
                ->exists();

            if (! $exists) {
                $this->error('Smoke inbound message was not found in tenant database.');

                return self::FAILURE;
            }

            $this->info('Smoke inbound message stored in tenant database.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Smoke test failed: '.$this->safeError($exception->getMessage()));

            return self::FAILURE;
        } finally {
            Config::set('communication.tenancy.runtime.enabled', $previousRuntime);
            Config::set('communication.agent.enabled', $previousAgentEnabled);
            Config::set('communication.providers.zapi.fake', $previousZapiFake);
            $currentTenantConnection->clear();
        }
    }

    private function connectionFor(CommunicationTenant $tenant): ?CommunicationTenantConnection
    {
        return $tenant->connections()
            ->where('status', CommunicationTenantConnectionStatus::Active->value)
            ->whereNotNull('database_name')
            ->latest('updated_at')
            ->first();
    }

    private function safeError(string $error): string
    {
        return substr(preg_replace('/(password|token|secret)=?[^\\s&]*/i', '$1=[redacted]', $error) ?? $error, 0, 300);
    }
}
