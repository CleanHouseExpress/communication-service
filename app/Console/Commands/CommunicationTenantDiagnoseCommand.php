<?php

namespace App\Console\Commands;

use App\Models\CommunicationTenant;
use App\Models\CommunicationTenantConnection;
use App\Services\Tenancy\TenantConnectionConfigurator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CommunicationTenantDiagnoseCommand extends Command
{
    protected $signature = 'communication:tenant:diagnose {orchestra_tenant_id : Orchestra tenant id}';

    protected $description = 'Diagnose communication tenant replica and database connection.';

    private const EXPECTED_TABLES = [
        'communication_channels',
        'communication_contacts',
        'communication_conversations',
        'communication_messages',
        'communication_raw_events',
        'communication_outbound_messages',
        'communication_agent_runs',
    ];

    public function handle(TenantConnectionConfigurator $configurator): int
    {
        $tenant = CommunicationTenant::query()
            ->where('orchestra_tenant_id', (string) $this->argument('orchestra_tenant_id'))
            ->first();

        if ($tenant === null) {
            $this->error('Communication tenant was not found.');

            return self::FAILURE;
        }

        $connection = $this->connectionFor($tenant);

        $this->line("Tenant: {$tenant->orchestra_tenant_id}");
        $this->line("Tenant status: {$tenant->status}");
        $this->line('Connection status: '.($connection?->status ?? 'missing'));
        $this->line('Database: '.($connection?->database_name ?? 'missing'));
        $this->line('Migrated: '.($connection?->migrated_at !== null ? 'yes' : 'no'));

        if ($connection === null || $connection->database_name === null || $connection->database_name === '') {
            $this->warn('Tenant connection is not ready.');

            return self::SUCCESS;
        }

        try {
            $connectionName = $configurator->configure($connection);
            DB::connection($connectionName)->select('select 1');
            $this->info('Connection test: ok');

            $existingTables = collect(DB::connection($connectionName)->select('select name from sqlite_master where type = ?', ['table']))
                ->pluck('name')
                ->all();

            if ($connection->database_driver !== 'sqlite') {
                $existingTables = collect(DB::connection($connectionName)->select('show tables'))
                    ->map(fn (object $row): string => (string) array_values((array) $row)[0])
                    ->all();
            }

            $count = count(array_intersect(self::EXPECTED_TABLES, $existingTables));
            $this->line('Expected tenant tables found: '.$count.'/'.count(self::EXPECTED_TABLES));
        } catch (Throwable $exception) {
            $this->warn('Connection test: failed');
            $this->warn('Error: '.$this->safeError($exception->getMessage()));
        }

        return self::SUCCESS;
    }

    private function connectionFor(CommunicationTenant $tenant): ?CommunicationTenantConnection
    {
        return $tenant->connections()
            ->latest('updated_at')
            ->first();
    }

    private function safeError(string $error): string
    {
        return substr(preg_replace('/(password|token|secret)=?[^\\s&]*/i', '$1=[redacted]', $error) ?? $error, 0, 300);
    }
}
