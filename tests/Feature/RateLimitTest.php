<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    public function test_internal_api_rate_limit_is_applied(): void
    {
        RateLimiter::clear('127.0.0.1');

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/health')->assertOk();
        }

        $this->getJson('/api/health')->assertStatus(429);
    }
}
