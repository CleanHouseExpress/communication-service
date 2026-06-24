<?php

namespace App\Console\Commands;

use App\Enums\CommunicationTenantConnectionStatus;
use App\Enums\CommunicationTenantStatus;
use App\Models\CommunicationTenant;
use App\Models\CommunicationTenantConnection;
use App\Services\Tenancy\TenantConnectionConfigurator;
use Illuminate\Console\Command;
use Throwable;

class CommunicationTenantMigrateCommand extends Command
{
    protected $signature = 'communication:tenant:migrate
        {orchestra_tenant_id : Orchestra tenant id}
        {--pretend : Validate and display the target without running migrations}
        {--force : Force execution in production}';

    protected $description = 'Run communication tenant migrations for one provisioned tenant database.';

    public function handle(TenantConnectionConfigurator $configurator): int
    {
        $tenant = CommunicationTenant::query()
            ->where('orchestra_tenant_id', (string) $this->argument('orchestra_tenant_id'))
            ->first();

        if ($tenant === null) {
            $this->error('Communication tenant was not found.');

            return self::FAILURE;
        }

        if ($tenant->status !== CommunicationTenantStatus::Active->value) {
            $this->error('Communication tenant is not active.');

            return self::FAILURE;
        }

        $connection = $this->connectionFor($tenant);

        if ($connection === null) {
            $this->error('Communication tenant connection with database_name was not found.');

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Use --force to run tenant migrations in production.');

            return self::FAILURE;
        }

        try {
            $migrationPath = 'database/migrations/tenant';
            $connectionName = $configurator->configure($connection);

            if ($this->option('pretend')) {
                $this->info('Tenant migration pretend mode.');
                $this->line("Tenant: {$tenant->orchestra_tenant_id}");
                $this->line("Connection: {$connectionName}");
                $this->line("Database: {$connection->database_name}");
                $this->line("Path: {$migrationPath}");

                return self::SUCCESS;
            }

            $exitCode = $this->call('migrate', [
                '--database' => $connectionName,
                '--path' => $migrationPath,
                '--force' => (bool) $this->option('force'),
            ]);

            if ($exitCode !== self::SUCCESS) {
                throw new \RuntimeException('Tenant migrations failed.');
            }

            $connection->forceFill([
                'status' => CommunicationTenantConnectionStatus::Active->value,
                'migrated_at' => now(),
                'metadata' => [
                    ...($connection->metadata ?? []),
                    'last_migration_path' => $migrationPath,
                ],
            ])->save();

            $this->info('Tenant migrations completed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $connection->forceFill([
                'status' => CommunicationTenantConnectionStatus::Failed->value,
                'metadata' => [
                    ...($connection->metadata ?? []),
                    'migration_failed_reason' => $this->safeError($exception->getMessage()),
                ],
            ])->save();

            $this->error('Tenant migrations failed.');

            return self::FAILURE;
        }
    }

    private function connectionFor(CommunicationTenant $tenant): ?CommunicationTenantConnection
    {
        return $tenant->connections()
            ->whereIn('status', [
                CommunicationTenantConnectionStatus::Active->value,
                CommunicationTenantConnectionStatus::Pending->value,
                CommunicationTenantConnectionStatus::Skipped->value,
            ])
            ->whereNotNull('database_name')
            ->latest('updated_at')
            ->first();
    }

    private function safeError(string $error): string
    {
        return substr(preg_replace('/(password|token|secret)=?[^\\s&]*/i', '$1=[redacted]', $error) ?? $error, 0, 300);
    }
}
