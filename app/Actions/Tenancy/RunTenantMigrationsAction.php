<?php

namespace App\Actions\Tenancy;

use App\DTO\Tenancy\TenantMigrationResult;
use App\Enums\CommunicationTenantConnectionStatus;
use App\Models\CommunicationTenant;
use App\Models\CommunicationTenantConnection;
use App\Services\Tenancy\TenantConnectionConfigurator;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Throwable;

class RunTenantMigrationsAction
{
    private const MIGRATION_PATH = 'database/migrations/tenant';

    public function __construct(
        private readonly TenantConnectionConfigurator $configurator,
        private readonly ConsoleKernel $console,
    ) {}

    public function handle(CommunicationTenant $tenant, bool $pretend = false, bool $force = false): TenantMigrationResult
    {
        $connection = $this->connectionFor($tenant);

        if ($connection === null) {
            return new TenantMigrationResult(
                tenant: $tenant,
                connection: null,
                success: false,
                skipped: true,
                pretend: $pretend,
                error: 'Communication tenant connection with database_name was not found.',
            );
        }

        try {
            $connectionName = $this->configurator->configure($connection);

            if ($pretend) {
                return new TenantMigrationResult(
                    tenant: $tenant,
                    connection: $connection,
                    success: true,
                    skipped: false,
                    pretend: true,
                    connectionName: $connectionName,
                    migrationPath: self::MIGRATION_PATH,
                );
            }

            $exitCode = $this->console->call('migrate', [
                '--database' => $connectionName,
                '--path' => self::MIGRATION_PATH,
                '--force' => $force,
            ]);

            if ($exitCode !== 0) {
                throw new \RuntimeException('Tenant migrations failed.');
            }

            $connection->forceFill([
                'status' => CommunicationTenantConnectionStatus::Active->value,
                'migrated_at' => now(),
                'metadata' => [
                    ...($connection->metadata ?? []),
                    'last_migration_path' => self::MIGRATION_PATH,
                ],
            ])->save();

            return new TenantMigrationResult(
                tenant: $tenant,
                connection: $connection->refresh(),
                success: true,
                skipped: false,
                pretend: false,
                connectionName: $connectionName,
                migrationPath: self::MIGRATION_PATH,
            );
        } catch (Throwable $exception) {
            $connection->forceFill([
                'status' => CommunicationTenantConnectionStatus::Failed->value,
                'metadata' => [
                    ...($connection->metadata ?? []),
                    'migration_failed_reason' => $this->safeError($exception->getMessage()),
                ],
            ])->save();

            return new TenantMigrationResult(
                tenant: $tenant,
                connection: $connection->refresh(),
                success: false,
                skipped: false,
                pretend: $pretend,
                migrationPath: self::MIGRATION_PATH,
                error: $connection->metadata['migration_failed_reason'] ?? 'Tenant migrations failed.',
            );
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
