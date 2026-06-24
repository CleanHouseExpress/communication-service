<?php

namespace Tests\Feature;

use App\Models\CommunicationTenant;
use App\Models\CommunicationTenantConnection;
use App\Services\Tenancy\TenantDatabaseNameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantDatabaseProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_requires_service_token(): void
    {
        $tenant = $this->tenant();

        $this->postJson("/api/internal/tenants/{$tenant->orchestra_tenant_id}/provision-database")
            ->assertUnauthorized();
    }

    public function test_provisioning_disabled_does_not_create_physical_database_and_returns_skipped(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.tenancy.database_provisioning.enabled' => false,
            'communication.tenancy.database_provisioning.prefix' => 'communication_tenant_',
            'communication.tenancy.database_provisioning.host' => 'db.example.internal',
            'communication.tenancy.database_provisioning.port' => 3306,
            'communication.tenancy.database_provisioning.username' => 'tenant_user',
            'communication.tenancy.database_provisioning.password' => 'secret-db-password',
        ]);

        $tenant = $this->tenant();

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/tenants/{$tenant->orchestra_tenant_id}/provision-database")
            ->assertOk()
            ->assertJson([
                'orchestra_tenant_id' => 'tenant_123',
                'database_name' => 'communication_tenant_rede_exemplo',
                'database_host' => 'db.example.internal',
                'database_port' => 3306,
                'database_driver' => 'mysql',
                'status' => 'skipped',
                'migrated_at' => null,
            ])
            ->assertDontSee('secret-db-password');

        $connection = CommunicationTenantConnection::firstOrFail();

        $this->assertSame('skipped', $connection->status);
        $this->assertSame('communication_tenant_rede_exemplo', $connection->database_name);
        $this->assertNotSame('secret-db-password', $connection->database_password_encrypted);
        $this->assertNull($connection->migrated_at);
    }

    public function test_generates_safe_database_name_from_slug(): void
    {
        config(['communication.tenancy.database_provisioning.prefix' => 'communication_tenant_']);

        $tenant = CommunicationTenant::create([
            'orchestra_tenant_id' => 'tenant_abc',
            'name' => 'Rede Exemplo',
            'slug' => 'Rede Exemplo 123',
            'status' => 'active',
        ]);

        $this->assertSame(
            'communication_tenant_rede_exemplo_123',
            app(TenantDatabaseNameGenerator::class)->generate($tenant),
        );
    }

    public function test_rejects_missing_tenant(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/tenants/missing-tenant/provision-database')
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'Communication tenant is not active or was not found.',
            ]);
    }

    public function test_rejects_disabled_tenant(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $tenant = $this->tenant([
            'status' => 'disabled',
            'disabled_at' => now(),
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/tenants/{$tenant->orchestra_tenant_id}/provision-database")
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'Communication tenant is not active or was not found.',
            ]);
    }

    public function test_tenant_created_does_not_auto_provision_when_disabled(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.tenancy.database_provisioning.auto_provision' => false,
            'communication.tenancy.database_provisioning.enabled' => false,
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/orchestra/events/tenants', $this->tenantCreatedPayload())
            ->assertCreated()
            ->assertJsonPath('status', 'processed');

        $this->assertDatabaseHas('communication_tenants', [
            'orchestra_tenant_id' => 'tenant_123',
        ]);
        $this->assertSame(0, CommunicationTenantConnection::count());
    }

    public function test_tenant_created_auto_provisions_skipped_connection_when_physical_provisioning_disabled(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.tenancy.database_provisioning.auto_provision' => true,
            'communication.tenancy.database_provisioning.enabled' => false,
            'communication.tenancy.database_provisioning.prefix' => 'communication_tenant_',
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/orchestra/events/tenants', $this->tenantCreatedPayload())
            ->assertCreated()
            ->assertJsonPath('status', 'processed');

        $this->assertDatabaseHas('communication_tenant_connections', [
            'database_name' => 'communication_tenant_rede_exemplo',
            'status' => 'skipped',
            'migrated_at' => null,
        ]);
    }

    public function test_response_does_not_leak_database_password(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.tenancy.database_provisioning.enabled' => false,
            'communication.tenancy.database_provisioning.password' => 'super-secret-password',
        ]);

        $tenant = $this->tenant();

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson("/api/internal/tenants/{$tenant->orchestra_tenant_id}/provision-database")
            ->assertOk();

        $this->assertStringNotContainsString('super-secret-password', $response->getContent());
        $this->assertStringNotContainsString('database_password', $response->getContent());
    }

    public function test_malicious_slug_does_not_generate_unsafe_database_name(): void
    {
        config(['communication.tenancy.database_provisioning.prefix' => 'communication_tenant_']);

        $tenant = CommunicationTenant::create([
            'orchestra_tenant_id' => 'tenant_123',
            'name' => 'Rede Maliciosa',
            'slug' => 'rede`; DROP DATABASE mysql; --',
            'status' => 'active',
        ]);

        $databaseName = app(TenantDatabaseNameGenerator::class)->generate($tenant);

        $this->assertSame('communication_tenant_rede_drop_database_mysql', $databaseName);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $databaseName);
        $this->assertStringNotContainsString('DROP', $databaseName);
        $this->assertStringNotContainsString(';', $databaseName);
        $this->assertStringNotContainsString('`', $databaseName);
    }

    private function tenant(array $overrides = []): CommunicationTenant
    {
        return CommunicationTenant::create([
            ...[
                'orchestra_tenant_id' => 'tenant_123',
                'name' => 'Rede Exemplo',
                'slug' => 'rede-exemplo',
                'status' => 'active',
                'timezone' => 'America/Sao_Paulo',
            ],
            ...$overrides,
        ]);
    }

    private function tenantCreatedPayload(): array
    {
        return [
            'event_id' => 'evt_tenant_created_123',
            'event_type' => 'TenantCreated',
            'occurred_at' => '2026-06-24T15:00:00-03:00',
            'tenant' => [
                'id' => 'tenant_123',
                'name' => 'Rede Exemplo',
                'slug' => 'rede-exemplo',
                'status' => 'active',
                'timezone' => 'America/Sao_Paulo',
                'metadata' => [],
            ],
        ];
    }
}
