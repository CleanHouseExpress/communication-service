<?php

namespace Tests\Feature;

use App\Enums\CommunicationTenantConnectionStatus;
use App\Models\CommunicationTenant;
use App\Models\CommunicationTenantConnection;
use App\Services\Tenancy\TenantConnectionConfigurator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantRuntimeValidationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnose_rejects_missing_tenant(): void
    {
        $exitCode = Artisan::call('communication:tenant:diagnose', [
            'orchestra_tenant_id' => 'missing',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Communication tenant was not found.', Artisan::output());
    }

    public function test_diagnose_does_not_show_password(): void
    {
        $tenant = $this->tenant('tenant_diag');
        $this->connection($tenant, [
            'database_password_encrypted' => Crypt::encryptString('secret-password'),
        ]);

        $exitCode = Artisan::call('communication:tenant:diagnose', [
            'orchestra_tenant_id' => $tenant->orchestra_tenant_id,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('secret-password', Artisan::output());
        $this->assertStringNotContainsString('database_password', Artisan::output());
    }

    public function test_diagnose_shows_pending_when_not_migrated(): void
    {
        $tenant = $this->tenant('tenant_pending');
        $this->connection($tenant, [
            'status' => CommunicationTenantConnectionStatus::Pending->value,
            'migrated_at' => null,
        ]);

        $exitCode = Artisan::call('communication:tenant:diagnose', [
            'orchestra_tenant_id' => $tenant->orchestra_tenant_id,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Connection status: pending', $output);
        $this->assertStringContainsString('Migrated: no', $output);
    }

    public function test_smoke_test_rejects_tenant_without_migration(): void
    {
        $tenant = $this->tenant('tenant_not_migrated');
        $this->connection($tenant, [
            'status' => CommunicationTenantConnectionStatus::Active->value,
            'migrated_at' => null,
        ]);

        $exitCode = Artisan::call('communication:tenant:smoke-test', [
            'orchestra_tenant_id' => $tenant->orchestra_tenant_id,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('has not been migrated', Artisan::output());
    }

    public function test_smoke_test_send_inbound_writes_to_tenant_database(): void
    {
        config(['communication.tenancy.runtime.connection_name' => 'communication_tenant']);

        $tenant = $this->tenant('tenant_smoke');
        $connection = $this->connection($tenant, [
            'database_name' => $this->tenantDatabasePath('tenant_smoke.sqlite'),
            'status' => CommunicationTenantConnectionStatus::Active->value,
            'migrated_at' => now(),
        ]);

        $this->migrateTenantDatabase($connection);

        $exitCode = Artisan::call('communication:tenant:smoke-test', [
            'orchestra_tenant_id' => $tenant->orchestra_tenant_id,
            '--send-inbound' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Smoke inbound message stored in tenant database.', Artisan::output());
        $this->assertSame(0, \DB::table('communication_messages')->count());
        $this->assertSame(1, \DB::connection('communication_tenant')->table('communication_messages')->count());
    }

    private function tenant(string $orchestraTenantId): CommunicationTenant
    {
        return CommunicationTenant::create([
            'orchestra_tenant_id' => $orchestraTenantId,
            'name' => $orchestraTenantId,
            'slug' => $orchestraTenantId,
            'status' => 'active',
        ]);
    }

    private function connection(CommunicationTenant $tenant, array $overrides = []): CommunicationTenantConnection
    {
        return CommunicationTenantConnection::create([
            ...[
                'communication_tenant_id' => $tenant->id,
                'connection_name' => 'tenant',
                'database_driver' => 'sqlite',
            'database_name' => $this->tenantDatabasePath('tenant_validation_'.$tenant->orchestra_tenant_id.'.sqlite'),
                'status' => CommunicationTenantConnectionStatus::Active->value,
                'migrated_at' => now(),
                'metadata' => [],
            ],
            ...$overrides,
        ]);
    }

    private function migrateTenantDatabase(CommunicationTenantConnection $connection): void
    {
        app(TenantConnectionConfigurator::class)->configure($connection);

        Artisan::call('migrate', [
            '--database' => 'communication_tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }

    private function tenantDatabasePath(string $filename): string
    {
        DB::purge('communication_tenant');

        $directory = storage_path('framework/testing');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        if (file_exists($path)) {
            @unlink($path);
        }

        touch($path);

        return $path;
    }
}
