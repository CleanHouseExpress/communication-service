<?php

namespace Tests\Feature;

use Tests\TestCase;

class RateLimitTest extends TestCase
{
    public function test_internal_api_rate_limit_is_applied(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/health')->assertOk();
        }

        $this->getJson('/api/health')->assertStatus(429);
    }
}
