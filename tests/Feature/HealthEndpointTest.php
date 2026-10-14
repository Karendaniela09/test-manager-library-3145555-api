<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_the_exact_public_response(): void
    {
        $this->get('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }
}
