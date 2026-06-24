<?php

namespace Tests\Feature;

use App\Enums\CommunicationTenantConnectionStatus;
use App\Models\CommunicationTenant;
use App\Models\CommunicationTenantConnection;
use App\Services\Tenancy\TenantConnectionConfigurator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Tests\TestCase;

class TenantMigrationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_rejects_missing_tenant(): void
    {
        $exitCode = Artisan::call('communication:tenant:migrate', [
            'orchestra_tenant_id' => 'missing-tenant',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Communication tenant was not found.', Artisan::output());
    }

    public function test_command_rejects_disabled_tenant(): void
    {
        $tenant = $this->tenant([
            'status' => 'disabled',
            'disabled_at' => now(),
        ]);

        $exitCode = Artisan::call('communication:tenant:migrate', [
            'orchestra_tenant_id' => $tenant->orchestra_tenant_id,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Communication tenant is not active.', Artisan::output());
    }

    public function test_command_rejects_tenant_without_connection(): void
    {
        $tenant = $this->tenant();

        $exitCode = Artisan::call('communication:tenant:migrate', [
            'orchestra_tenant_id' => $tenant->orchestra_tenant_id,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Communication tenant connection with database_name was not found.', Artisan::output());
    }

    public function test_command_rejects_connection_without_database_name(): void
    {
        $tenant = $this->tenant();
        $this->connection($tenant, [
            'database_name' => null,
        ]);

        $exitCode = Artisan::call('communication:tenant:migrate', [
            'orchestra_tenant_id' => $tenant->orchestra_tenant_id,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Communication tenant connection with database_name was not found.', Artisan::output());
    }

    public function test_pretend_mode_validates_connection_and_prints_target(): void
    {
        $tenant = $this->tenant();
        $this->connection($tenant, [
            'database_driver' => 'sqlite',
            'database_name' => ':memory:',
            'database_password_encrypted' => Crypt::encryptString('secret-password'),
        ]);

        $exitCode = Artisan::call('communication:tenant:migrate', [
            'orchestra_tenant_id' => $tenant->orchestra_tenant_id,
            '--pretend' => true,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Tenant migration pretend mode.', $output);
        $this->assertStringContainsString('database/migrations/tenant', $output);
        $this->assertStringNotContainsString('secret-password', $output);
    }

    public function test_success_updates_migrated_at_and_marks_connection_active(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connection($tenant, [
            'database_driver' => 'sqlite',
            'database_name' => ':memory:',
            'status' => CommunicationTenantConnectionStatus::Skipped->value,
        ]);

        $exitCode = Artisan::call('communication:tenant:migrate', [
            'orchestra_tenant_id' => $tenant->orchestra_tenant_id,
        ]);

        $this->assertSame(0, $exitCode);

        $connection->refresh();

        $this->assertSame(CommunicationTenantConnectionStatus::Active->value, $connection->status);
        $this->assertNotNull($connection->migrated_at);
        $this->assertSame('database/migrations/tenant', $connection->metadata['last_migration_path']);
    }

    public function test_failure_marks_connection_failed(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connection($tenant);

        $this->mock(TenantConnectionConfigurator::class, function ($mock): void {
            $mock->shouldReceive('configure')
                ->once()
                ->andThrow(new RuntimeException('password=super-secret could not connect'));
        });

        $exitCode = Artisan::call('communication:tenant:migrate', [
            'orchestra_tenant_id' => $tenant->orchestra_tenant_id,
        ]);

        $connection->refresh();

        $this->assertSame(1, $exitCode);
        $this->assertSame(CommunicationTenantConnectionStatus::Failed->value, $connection->status);
        $this->assertStringContainsString('password=[redacted]', $connection->metadata['migration_failed_reason']);
        $this->assertStringNotContainsString('super-secret', $connection->metadata['migration_failed_reason']);
        $this->assertStringNotContainsString('super-secret', Artisan::output());
    }

    private function tenant(array $overrides = []): CommunicationTenant
    {
        return CommunicationTenant::create([
            ...[
                'orchestra_tenant_id' => 'tenant_123',
                'name' => 'Rede Exemplo',
                'slug' => 'rede-exemplo',
                'status' => 'active',
            ],
            ...$overrides,
        ]);
    }

    private function connection(CommunicationTenant $tenant, array $overrides = []): CommunicationTenantConnection
    {
        return CommunicationTenantConnection::create([
            ...[
                'communication_tenant_id' => $tenant->id,
                'connection_name' => 'tenant',
                'database_driver' => 'sqlite',
                'database_name' => ':memory:',
                'status' => CommunicationTenantConnectionStatus::Skipped->value,
                'metadata' => [],
            ],
            ...$overrides,
        ]);
    }
}
