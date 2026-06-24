<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_health_requires_service_token(): void
    {
        $this->getJson('/api/internal/health')
            ->assertUnauthorized();
    }

    public function test_internal_health_returns_database_ok(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database.ok', true);
    }

    public function test_internal_health_does_not_return_secrets(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.providers.zapi.token' => 'secret-zapi-token',
            'communication.providers.zapi.client_token' => 'secret-client-token',
            'communication.agent.n8n_token' => 'secret-n8n-token',
        ]);

        $response = $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/health')
            ->assertOk();

        $body = $response->getContent();

        $this->assertStringNotContainsString('secret-zapi-token', $body);
        $this->assertStringNotContainsString('secret-client-token', $body);
        $this->assertStringNotContainsString('secret-n8n-token', $body);
        $this->assertStringNotContainsString('valid-token', $body);
    }

    public function test_internal_health_returns_fake_and_agent_flags_without_tokens(): void
    {
        config([
            'communication.service_token' => 'valid-token',
            'communication.agent.enabled' => true,
            'communication.agent.fake' => true,
            'communication.providers.zapi.enabled' => true,
            'communication.providers.zapi.fake' => true,
        ]);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/health')
            ->assertOk()
            ->assertJsonPath('agent.enabled', true)
            ->assertJsonPath('agent.fake', true)
            ->assertJsonPath('zapi.enabled', true)
            ->assertJsonPath('zapi.fake', true)
            ->assertJsonMissingPath('agent.n8n_token')
            ->assertJsonMissingPath('zapi.token');
    }
}
