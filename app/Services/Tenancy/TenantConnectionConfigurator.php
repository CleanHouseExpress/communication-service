<?php

namespace App\Services\Tenancy;

use App\Models\CommunicationTenantConnection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TenantConnectionConfigurator
{
    public function configure(CommunicationTenantConnection $connection): string
    {
        $connectionName = $this->connectionName($connection);
        $driver = $connection->database_driver ?: 'mysql';
        $config = $this->baseConfig($driver, $connection);

        Config::set("database.connections.{$connectionName}", $config);
        DB::purge($connectionName);
        DB::reconnect($connectionName);

        return $connectionName;
    }

    private function connectionName(CommunicationTenantConnection $connection): string
    {
        return 'tenant_'.$connection->id;
    }

    private function baseConfig(string $driver, CommunicationTenantConnection $connection): array
    {
        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => $connection->database_name,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            'driver' => $driver,
            'host' => $connection->database_host,
            'port' => $connection->database_port,
            'database' => $connection->database_name,
            'username' => $connection->database_username,
            'password' => $this->password($connection),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ];
    }

    private function password(CommunicationTenantConnection $connection): ?string
    {
        if ($connection->database_password_encrypted === null || $connection->database_password_encrypted === '') {
            return null;
        }

        return Crypt::decryptString($connection->database_password_encrypted);
    }
}
