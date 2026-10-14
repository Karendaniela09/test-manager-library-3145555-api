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

    public function test_protected_endpoint_rejects_missing_bearer_token_in_contract_format(): void
    {
        $this->getJson('/api/v1/categories')
            ->assertUnauthorized()
            ->assertExactJson([
                'code' => 'UNAUTHENTICATED',
                'message' => 'Autenticación requerida.',
                'details' => [],
            ]);
    }
}
