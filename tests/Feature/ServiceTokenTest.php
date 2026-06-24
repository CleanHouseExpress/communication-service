<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ServiceTokenTest extends TestCase
{
    public function test_internal_health_requires_service_token(): void
    {
        $this->getJson('/api/internal/health')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Service token is required.']);
    }

    public function test_internal_health_rejects_invalid_service_token(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('X-Service-Token', 'invalid-token')
            ->getJson('/api/internal/health')
            ->assertForbidden()
            ->assertJson(['message' => 'Service token is invalid.']);
    }

    public function test_internal_health_accepts_x_service_token(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('X-Service-Token', 'valid-token')
            ->getJson('/api/internal/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'authenticated' => true,
            ]);
    }

    public function test_internal_health_accepts_bearer_service_token(): void
    {
        config(['communication.service_token' => 'valid-token']);

        $this->withHeader('Authorization', 'Bearer valid-token')
            ->getJson('/api/internal/health')
            ->assertOk();
    }

    public function test_service_token_is_not_accepted_from_query_string_and_does_not_leak(): void
    {
        Log::spy();
        config(['communication.service_token' => 'valid-token']);

        $this->getJson('/api/internal/health?service_token=valid-token')
            ->assertUnauthorized()
            ->assertJsonMissing(['valid-token']);

        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }
}
