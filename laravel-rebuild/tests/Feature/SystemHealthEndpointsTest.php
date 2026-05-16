<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemHealthEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok_with_checks(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'lavita-ur-laravel-rebuild')
            ->assertJsonPath('checks.app', 'ok')
            ->assertJsonPath('checks.database', 'ok');
    }

    public function test_ready_endpoint_returns_ready_when_database_is_available(): void
    {
        $response = $this->getJson('/api/ready');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('service', 'lavita-ur-laravel-rebuild');
    }
}
