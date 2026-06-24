<?php

namespace Tests\Feature;

use App\Enums\CommunicationTenantConnectionStatus;
use App\Models\CommunicationMessage;
use App\Models\CommunicationTenant;
use App\Models\CommunicationTenantConnection;
use App\Services\Tenancy\TenantConnectionConfigurator;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_disabled_keeps_writes_on_default_database(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.tenancy.runtime.enabled' => false,
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/inbound/messages', $this->inboundPayload('tenant_not_synced'))
            ->assertCreated();

        $this->assertDatabaseHas('communication_messages', [
            'tenant_id' => 'tenant_not_synced',
            'external_message_id' => 'tenant-runtime-message-1',
        ]);
    }

    public function test_runtime_enabled_requires_active_tenant(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.tenancy.runtime.enabled' => true,
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/inbound/messages', $this->inboundPayload('missing_tenant'))
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'Communication tenant is not active or was not found.',
            ]);
    }

    public function test_runtime_enabled_rejects_tenant_without_active_connection(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.tenancy.runtime.enabled' => true,
        ]);

        $tenant = $this->tenant('tenant_without_connection');
        $this->connection($tenant, [
            'status' => CommunicationTenantConnectionStatus::Skipped->value,
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/inbound/messages', $this->inboundPayload($tenant->orchestra_tenant_id))
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'Communication tenant is not active or was not found.',
            ]);
    }

    public function test_runtime_enabled_writes_operational_data_to_tenant_database_and_clears_context(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.tenancy.runtime.enabled' => true,
            'communication.tenancy.runtime.connection_name' => 'communication_tenant',
        ]);

        $tenant = $this->tenant('tenant_runtime');
        $connection = $this->connection($tenant, [
            'database_name' => $this->tenantDatabasePath('tenant_runtime.sqlite'),
            'status' => CommunicationTenantConnectionStatus::Active->value,
        ]);
        $this->migrateTenantDatabase($connection);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/inbound/messages', $this->inboundPayload($tenant->orchestra_tenant_id))
            ->assertCreated();

        $this->assertSame(0, DB::table('communication_messages')->count());
        $this->assertSame(1, DB::connection('communication_tenant')->table('communication_messages')->count());
        $this->assertNull(app(CurrentTenantConnection::class)->tenant());
        $this->assertNull(app(CurrentTenantConnection::class)->connectionName());
    }

    public function test_trait_does_not_affect_landlord_models(): void
    {
        config(['communication.tenancy.runtime.enabled' => true]);

        $tenant = $this->tenant('tenant_trait');
        app(CurrentTenantConnection::class)->set($tenant, 'communication_tenant');

        $this->assertNull((new CommunicationTenant())->getConnectionName());
        $this->assertSame('communication_tenant', (new CommunicationMessage())->getConnectionName());

        app(CurrentTenantConnection::class)->clear();
    }

    public function test_old_flows_continue_with_runtime_disabled(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.tenancy.runtime.enabled' => false,
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/inbound/messages', $this->inboundPayload('legacy_tenant'))
            ->assertCreated();

        $this->assertDatabaseHas('communication_messages', [
            'tenant_id' => 'legacy_tenant',
            'status' => 'received',
        ]);
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
                'database_name' => $this->tenantDatabasePath('tenant.sqlite'),
                'status' => CommunicationTenantConnectionStatus::Active->value,
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
        $directory = storage_path('framework/testing');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        if (! file_exists($path)) {
            touch($path);
        }

        return $path;
    }

    private function inboundPayload(string $tenantId): array
    {
        return [
            'provider' => 'zapi',
            'tenant_id' => $tenantId,
            'external_event_id' => 'tenant-runtime-event-1',
            'external_message_id' => 'tenant-runtime-message-1',
            'external_contact_id' => '5541999999999',
            'contact_name' => 'Maria Cliente',
            'contact_phone' => '5541999999999',
            'message_type' => 'text',
            'text' => 'Mensagem runtime tenant',
            'occurred_at' => '2026-06-24T12:00:00-03:00',
            'raw_payload' => [
                'source' => 'test',
            ],
        ];
    }
}
