<?php

namespace Tests\Feature;

use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function test_the_public_health_endpoint_returns_only_service_status(): void
    {
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'clientele-group-erp-api')
            ->assertJsonStructure(['time_utc']);
    }
}
