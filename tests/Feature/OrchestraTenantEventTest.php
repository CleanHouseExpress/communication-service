<?php

namespace Tests\Feature;

use App\Models\CommunicationIntegrationEvent;
use App\Models\CommunicationTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrchestraTenantEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_requires_service_token(): void
    {
        $this->postJson('/api/internal/orchestra/events/tenants', $this->payload())
            ->assertUnauthorized();
    }

    public function test_tenant_created_creates_communication_tenant(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/orchestra/events/tenants', $this->payload())
            ->assertCreated()
            ->assertJson([
                'event_id' => 'evt_123',
                'status' => 'processed',
                'idempotent' => false,
            ]);

        $this->assertDatabaseHas('communication_tenants', [
            'orchestra_tenant_id' => 'tenant_123',
            'name' => 'Rede Exemplo',
            'slug' => 'rede-exemplo',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('communication_integration_events', [
            'source' => 'orchestra-api',
            'event_id' => 'evt_123',
            'event_type' => 'TenantCreated',
            'aggregate_type' => 'tenant',
            'aggregate_id' => 'tenant_123',
            'status' => 'processed',
        ]);
    }

    public function test_tenant_updated_updates_communication_tenant(): void
    {
        config(['communication.service_token' => 'valid-token']);

        CommunicationTenant::create([
            'orchestra_tenant_id' => 'tenant_123',
            'name' => 'Nome Antigo',
            'status' => 'active',
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/orchestra/events/tenants', $this->payload([
                'event_id' => 'evt_124',
                'event_type' => 'TenantUpdated',
                'tenant' => [
                    'id' => 'tenant_123',
                    'name' => 'Nome Novo',
                    'slug' => 'rede-nova',
                    'timezone' => 'America/Sao_Paulo',
                    'metadata' => [],
                ],
            ]))
            ->assertCreated()
            ->assertJson([
                'status' => 'processed',
            ]);

        $this->assertSame(1, CommunicationTenant::count());
        $this->assertDatabaseHas('communication_tenants', [
            'orchestra_tenant_id' => 'tenant_123',
            'name' => 'Nome Novo',
            'slug' => 'rede-nova',
            'status' => 'active',
        ]);
    }

    public function test_tenant_disabled_disables_communication_tenant(): void
    {
        config(['communication.service_token' => 'valid-token']);

        CommunicationTenant::create([
            'orchestra_tenant_id' => 'tenant_123',
            'name' => 'Rede Exemplo',
            'status' => 'active',
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/orchestra/events/tenants', $this->payload([
                'event_id' => 'evt_125',
                'event_type' => 'TenantDisabled',
            ]))
            ->assertCreated();

        $tenant = CommunicationTenant::firstOrFail();

        $this->assertSame('disabled', $tenant->status);
        $this->assertNotNull($tenant->disabled_at);
    }

    public function test_tenant_enabled_reactivates_communication_tenant(): void
    {
        config(['communication.service_token' => 'valid-token']);

        CommunicationTenant::create([
            'orchestra_tenant_id' => 'tenant_123',
            'name' => 'Rede Exemplo',
            'status' => 'disabled',
            'disabled_at' => now(),
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/orchestra/events/tenants', $this->payload([
                'event_id' => 'evt_126',
                'event_type' => 'TenantEnabled',
            ]))
            ->assertCreated();

        $tenant = CommunicationTenant::firstOrFail();

        $this->assertSame('active', $tenant->status);
        $this->assertNull($tenant->disabled_at);
    }

    public function test_duplicate_event_id_is_idempotent(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/orchestra/events/tenants', $this->payload())
            ->assertCreated()
            ->assertJsonPath('idempotent', false);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/orchestra/events/tenants', $this->payload([
                'tenant' => [
                    'id' => 'tenant_123',
                    'name' => 'Nome Que Nao Deve Aplicar',
                    'slug' => 'nao-aplicar',
                    'status' => 'active',
                    'timezone' => 'America/Sao_Paulo',
                    'metadata' => [],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('idempotent', true);

        $this->assertSame(1, CommunicationIntegrationEvent::count());
        $this->assertSame(1, CommunicationTenant::count());
        $this->assertDatabaseHas('communication_tenants', [
            'orchestra_tenant_id' => 'tenant_123',
            'name' => 'Rede Exemplo',
        ]);
    }

    public function test_unknown_event_is_ignored(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/orchestra/events/tenants', [
                'event_id' => 'evt_unknown',
                'event_type' => 'TenantArchived',
                'occurred_at' => '2026-06-24T15:00:00-03:00',
                'tenant' => [
                    'id' => 'tenant_123',
                ],
            ])
            ->assertCreated()
            ->assertJson([
                'event_id' => 'evt_unknown',
                'status' => 'ignored',
                'tenant_id' => null,
            ]);

        $this->assertSame(0, CommunicationTenant::count());
        $this->assertDatabaseHas('communication_integration_events', [
            'event_id' => 'evt_unknown',
            'event_type' => 'TenantArchived',
            'status' => 'ignored',
        ]);
    }

    public function test_invalid_payload_returns_validation_error(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->postJson('/api/internal/orchestra/events/tenants', [
                'event_id' => 'evt_invalid',
                'event_type' => 'TenantCreated',
                'tenant' => [
                    'status' => 'invalid-status',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant.id', 'tenant.status']);
    }

    public function test_error_response_does_not_leak_service_token(): void
    {
        config(['communication.service_token' => 'secret-service-token']);

        $response = $this->withHeader('X-Service-Token', 'secret-service-token')
            ->postJson('/api/internal/orchestra/events/tenants', [
                'event_id' => 'evt_invalid',
                'event_type' => 'TenantCreated',
                'tenant' => [
                    'status' => 'invalid-status',
                ],
            ])
            ->assertUnprocessable();

        $this->assertStringNotContainsString('secret-service-token', $response->getContent());
    }

    private function payload(array $overrides = []): array
    {
        return [
            ...[
                'event_id' => 'evt_123',
                'event_type' => 'TenantCreated',
                'occurred_at' => '2026-06-24T15:00:00-03:00',
                'tenant' => [
                    'id' => 'tenant_123',
                    'name' => 'Rede Exemplo',
                    'slug' => 'rede-exemplo',
                    'status' => 'active',
                    'timezone' => 'America/Sao_Paulo',
                    'metadata' => [
                        'source' => 'orchestra',
                    ],
                ],
            ],
            ...$overrides,
        ];
    }
}
