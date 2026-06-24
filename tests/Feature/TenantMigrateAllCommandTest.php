<?php

namespace Tests\Feature;

use App\Actions\Tenancy\RunTenantMigrationsAction;
use App\DTO\Tenancy\TenantMigrationResult;
use App\Enums\CommunicationTenantConnectionStatus;
use App\Models\CommunicationTenant;
use App\Models\CommunicationTenantConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

class TenantMigrateAllCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_appears_in_artisan_list(): void
    {
        Artisan::call('list');

        $this->assertStringContainsString('communication:tenant:migrate-all', Artisan::output());
    }

    public function test_no_active_tenants_returns_empty_summary(): void
    {
        $exitCode = Artisan::call('communication:tenant:migrate-all', [
            '--pretend' => true,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('total: 0', $output);
        $this->assertStringContainsString('success: 0', $output);
        $this->assertStringContainsString('failed: 0', $output);
        $this->assertStringContainsString('skipped: 0', $output);
    }

    public function test_disabled_tenant_is_ignored_by_default(): void
    {
        $tenant = $this->tenant('tenant_disabled', 'disabled');
        $this->connection($tenant);

        $exitCode = Artisan::call('communication:tenant:migrate-all', [
            '--pretend' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('total: 0', Artisan::output());
    }

    public function test_only_option_limits_tenants(): void
    {
        $first = $this->tenant('tenant_1');
        $second = $this->tenant('tenant_2');
        $this->connection($first);
        $this->connection($second);

        $exitCode = Artisan::call('communication:tenant:migrate-all', [
            '--pretend' => true,
            '--only' => 'tenant_2',
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('Tenant: tenant_1', $output);
        $this->assertStringContainsString('Tenant: tenant_2', $output);
        $this->assertStringContainsString('total: 1', $output);
    }

    public function test_pretend_does_not_run_real_migrations(): void
    {
        $tenant = $this->tenant('tenant_1');
        $connection = $this->connection($tenant);

        $exitCode = Artisan::call('communication:tenant:migrate-all', [
            '--pretend' => true,
        ]);

        $connection->refresh();

        $this->assertSame(0, $exitCode);
        $this->assertNull($connection->migrated_at);
        $this->assertSame(CommunicationTenantConnectionStatus::Skipped->value, $connection->status);
        $this->assertStringContainsString('pretend:', Artisan::output());
    }

    public function test_failure_in_one_tenant_does_not_stop_next_tenant(): void
    {
        $first = $this->tenant('tenant_1');
        $second = $this->tenant('tenant_2');
        $firstConnection = $this->connection($first);
        $secondConnection = $this->connection($second);

        $this->mock(RunTenantMigrationsAction::class, function ($mock) use ($first, $second, $firstConnection, $secondConnection): void {
            $mock->shouldReceive('handle')
                ->once()
                ->with(Mockery::on(fn ($tenant): bool => $tenant->is($first)), false, false)
                ->andReturn(new TenantMigrationResult(
                    tenant: $first,
                    connection: $firstConnection,
                    success: false,
                    skipped: false,
                    pretend: false,
                    error: 'failed',
                ));

            $mock->shouldReceive('handle')
                ->once()
                ->with(Mockery::on(fn ($tenant): bool => $tenant->is($second)), false, false)
                ->andReturn(new TenantMigrationResult(
                    tenant: $second,
                    connection: $secondConnection,
                    success: true,
                    skipped: false,
                    pretend: false,
                    connectionName: 'tenant_connection',
                    migrationPath: 'database/migrations/tenant',
                ));
        });

        $exitCode = Artisan::call('communication:tenant:migrate-all');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Tenant: tenant_1', $output);
        $this->assertStringContainsString('Tenant: tenant_2', $output);
        $this->assertStringContainsString('success: 1', $output);
        $this->assertStringContainsString('failed: 1', $output);
    }

    public function test_output_does_not_show_password(): void
    {
        $tenant = $this->tenant('tenant_1');
        $this->connection($tenant, [
            'database_password_encrypted' => Crypt::encryptString('secret-password'),
        ]);

        $exitCode = Artisan::call('communication:tenant:migrate-all', [
            '--pretend' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('secret-password', Artisan::output());
    }

    public function test_summary_counts_success_failed_and_skipped(): void
    {
        $successTenant = $this->tenant('tenant_success');
        $failedTenant = $this->tenant('tenant_failed');
        $skippedTenant = $this->tenant('tenant_skipped');
        $successConnection = $this->connection($successTenant);
        $failedConnection = $this->connection($failedTenant);

        $this->mock(RunTenantMigrationsAction::class, function ($mock) use (
            $successTenant,
            $failedTenant,
            $skippedTenant,
            $successConnection,
            $failedConnection,
        ): void {
            $mock->shouldReceive('handle')
                ->once()
                ->with(Mockery::on(fn ($tenant): bool => $tenant->is($failedTenant)), true, false)
                ->andReturn(new TenantMigrationResult(
                    tenant: $failedTenant,
                    connection: $failedConnection,
                    success: false,
                    skipped: false,
                    pretend: true,
                    error: 'failed',
                ));

            $mock->shouldReceive('handle')
                ->once()
                ->with(Mockery::on(fn ($tenant): bool => $tenant->is($skippedTenant)), true, false)
                ->andReturn(new TenantMigrationResult(
                    tenant: $skippedTenant,
                    connection: null,
                    success: false,
                    skipped: true,
                    pretend: true,
                    error: 'missing connection',
                ));

            $mock->shouldReceive('handle')
                ->once()
                ->with(Mockery::on(fn ($tenant): bool => $tenant->is($successTenant)), true, false)
                ->andReturn(new TenantMigrationResult(
                    tenant: $successTenant,
                    connection: $successConnection,
                    success: true,
                    skipped: false,
                    pretend: true,
                    connectionName: 'tenant_connection',
                    migrationPath: 'database/migrations/tenant',
                ));
        });

        $exitCode = Artisan::call('communication:tenant:migrate-all', [
            '--pretend' => true,
        ]);

        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('total: 3', $output);
        $this->assertStringContainsString('success: 1', $output);
        $this->assertStringContainsString('failed: 1', $output);
        $this->assertStringContainsString('skipped: 1', $output);
    }

    private function tenant(string $orchestraTenantId, string $status = 'active'): CommunicationTenant
    {
        return CommunicationTenant::create([
            'orchestra_tenant_id' => $orchestraTenantId,
            'name' => $orchestraTenantId,
            'slug' => $orchestraTenantId,
            'status' => $status,
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
