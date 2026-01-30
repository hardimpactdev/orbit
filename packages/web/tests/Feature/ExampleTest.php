<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // Create a default environment to satisfy ImplicitEnvironment middleware
        \HardImpact\Orbit\Core\Models\Environment::forceCreate([
            'name' => 'Default',
            'host' => 'localhost',
            'is_local' => true,
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
