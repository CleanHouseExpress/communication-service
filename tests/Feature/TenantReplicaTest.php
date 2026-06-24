<?php

namespace Tests\Feature;

use App\Enums\CommunicationTenantStatus;
use App\Models\CommunicationTenant;
use App\Support\Tenancy\TenantResolutionException;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantReplicaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_sync_requires_service_token(): void
    {
        $this->postJson('/api/internal/tenants/sync', $this->tenantPayload())
            ->assertUnauthorized();
    }

    public function test_tenant_sync_creates_tenant(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/tenants/sync', $this->tenantPayload())
            ->assertOk()
            ->assertJson([
                'orchestra_tenant_id' => 'tenant-1',
                'status' => 'active',
            ]);

        $this->assertDatabaseHas('communication_tenants', [
            'orchestra_tenant_id' => 'tenant-1',
            'name' => 'Rede Exemplo',
            'slug' => 'rede-exemplo',
            'status' => 'active',
            'timezone' => 'America/Sao_Paulo',
        ]);

        $this->assertNotNull(CommunicationTenant::firstOrFail()->synced_at);
    }

    public function test_tenant_sync_updates_existing_tenant(): void
    {
        config(['communication.service_token' => 'valid-token']);

        CommunicationTenant::create([
            'orchestra_tenant_id' => 'tenant-1',
            'name' => 'Nome Antigo',
            'status' => 'pending',
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/tenants/sync', [
                ...$this->tenantPayload(),
                'name' => 'Nome Atualizado',
                'status' => 'active',
            ])
            ->assertOk();

        $this->assertSame(1, CommunicationTenant::count());
        $this->assertDatabaseHas('communication_tenants', [
            'orchestra_tenant_id' => 'tenant-1',
            'name' => 'Nome Atualizado',
            'status' => 'active',
        ]);
    }

    public function test_disabled_tenant_is_saved_correctly(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/tenants/sync', [
                ...$this->tenantPayload(),
                'status' => 'disabled',
            ])
            ->assertOk()
            ->assertJson([
                'status' => 'disabled',
            ]);

        $tenant = CommunicationTenant::firstOrFail();

        $this->assertSame(CommunicationTenantStatus::Disabled->value, $tenant->status);
        $this->assertNotNull($tenant->disabled_at);
    }

    public function test_tenant_resolver_returns_active_tenant(): void
    {
        $tenant = CommunicationTenant::create([
            'orchestra_tenant_id' => 'tenant-active',
            'status' => 'active',
        ]);

        $resolved = app(TenantResolver::class)->resolveActive('tenant-active');

        $this->assertTrue($tenant->is($resolved));
    }

    public function test_tenant_resolver_rejects_disabled_tenant(): void
    {
        CommunicationTenant::create([
            'orchestra_tenant_id' => 'tenant-disabled',
            'status' => 'disabled',
        ]);

        $this->expectException(TenantResolutionException::class);

        app(TenantResolver::class)->resolveActive('tenant-disabled');
    }

    public function test_when_tenancy_enforce_true_internal_inbound_rejects_missing_tenant(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.tenancy.enforce' => true,
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/inbound/messages', $this->inboundPayload('tenant-missing'))
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'Communication tenant is not active or was not found.',
            ]);
    }

    public function test_when_tenancy_enforce_false_internal_inbound_keeps_current_behavior(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.tenancy.enforce' => false,
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/inbound/messages', $this->inboundPayload('tenant-not-synced'))
            ->assertCreated();

        $this->assertDatabaseHas('communication_messages', [
            'tenant_id' => 'tenant-not-synced',
            'external_message_id' => 'tenant-replica-message-1',
            'direction' => 'inbound',
        ]);
    }

    private function tenantPayload(): array
    {
        return [
            'orchestra_tenant_id' => 'tenant-1',
            'name' => 'Rede Exemplo',
            'slug' => 'rede-exemplo',
            'status' => 'active',
            'timezone' => 'America/Sao_Paulo',
            'metadata' => [
                'source' => 'test',
            ],
        ];
    }

    private function inboundPayload(string $tenantId): array
    {
        return [
            'provider' => 'zapi',
            'tenant_id' => $tenantId,
            'external_event_id' => 'tenant-replica-event-1',
            'external_message_id' => 'tenant-replica-message-1',
            'external_contact_id' => '5541999999999',
            'contact_name' => 'Maria Cliente',
            'contact_phone' => '5541999999999',
            'message_type' => 'text',
            'text' => 'Mensagem com tenant',
            'occurred_at' => '2026-06-24T12:00:00-03:00',
            'raw_payload' => [
                'source' => 'test',
            ],
        ];
    }
}
