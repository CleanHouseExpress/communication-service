<?php

namespace App\Services\Tenancy;

use App\Enums\CommunicationTenantConnectionStatus;
use App\Models\CommunicationTenant;
use App\Models\CommunicationTenantConnection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TenantDatabaseProvisioner
{
    public function __construct(
        private readonly TenantDatabaseNameGenerator $nameGenerator,
    ) {}

    public function provision(CommunicationTenant $tenant): CommunicationTenantConnection
    {
        $databaseName = $this->nameGenerator->generate($tenant);
        $config = config('communication.tenancy.database_provisioning');
        $enabled = (bool) ($config['enabled'] ?? false);

        if (! $enabled) {
            $connection = $this->upsertConnection($tenant, $databaseName, CommunicationTenantConnectionStatus::Skipped);

            Log::info('Tenant database provisioning skipped.', [
                'tenant_id' => $tenant->orchestra_tenant_id,
                'connection_id' => $connection->id,
                'database_name' => $databaseName,
                'status' => CommunicationTenantConnectionStatus::Skipped->value,
            ]);

            return $connection;
        }

        try {
            $this->createDatabase($databaseName);
            $connection = $this->upsertConnection($tenant, $databaseName, CommunicationTenantConnectionStatus::Active);

            Log::info('Tenant database provisioned.', [
                'tenant_id' => $tenant->orchestra_tenant_id,
                'connection_id' => $connection->id,
                'database_name' => $databaseName,
                'status' => CommunicationTenantConnectionStatus::Active->value,
            ]);

            return $connection;
        } catch (Throwable $exception) {
            $connection = $this->upsertConnection($tenant, $databaseName, CommunicationTenantConnectionStatus::Failed, $exception->getMessage());

            Log::warning('Tenant database provisioning failed.', [
                'tenant_id' => $tenant->orchestra_tenant_id,
                'connection_id' => $connection->id,
                'database_name' => $databaseName,
                'status' => CommunicationTenantConnectionStatus::Failed->value,
                'error' => $connection->metadata['failed_reason'] ?? 'Provisioning failed.',
            ]);

            return $connection;
        }
    }

    private function upsertConnection(
        CommunicationTenant $tenant,
        string $databaseName,
        CommunicationTenantConnectionStatus $status,
        ?string $failedReason = null,
    ): CommunicationTenantConnection {
        $config = config('communication.tenancy.database_provisioning');
        $password = $config['password'] ?? null;
        $metadata = [
            'provisioning_enabled' => (bool) ($config['enabled'] ?? false),
        ];

        if ($failedReason !== null) {
            $metadata['failed_reason'] = $this->safeError($failedReason);
        }

        return CommunicationTenantConnection::query()->updateOrCreate(
            [
                'communication_tenant_id' => $tenant->id,
                'connection_name' => 'tenant',
            ],
            [
                'database_host' => $config['host'] ?? null,
                'database_port' => isset($config['port']) ? (int) $config['port'] : null,
                'database_name' => $databaseName,
                'database_username' => $config['username'] ?? null,
                'database_password_encrypted' => is_string($password) && $password !== '' ? Crypt::encryptString($password) : null,
                'database_driver' => $config['driver'] ?? 'mysql',
                'status' => $status->value,
                'migrated_at' => null,
                'metadata' => $metadata,
            ],
        );
    }

    private function createDatabase(string $databaseName): void
    {
        DB::statement('CREATE DATABASE IF NOT EXISTS '.$this->quoteIdentifier($databaseName));
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z0-9_]{1,64}$/', $identifier)) {
            throw new \InvalidArgumentException('Unsafe tenant database name.');
        }

        return '`'.$identifier.'`';
    }

    private function safeError(string $error): string
    {
        return substr(preg_replace('/(password|token|secret)=?[^\\s&]*/i', '$1=[redacted]', $error) ?? $error, 0, 300);
    }
}
