<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_service_status(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => 'communication-gateway',
            ]);
    }

    public function test_version_endpoint_returns_service_version(): void
    {
        $this->getJson('/api/version')
            ->assertOk()
            ->assertJson([
                'service' => 'communication-gateway',
                'version' => '0.1.0',
            ]);
    }
}
