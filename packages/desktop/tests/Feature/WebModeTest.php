<?php

declare(strict_types=1);

namespace Tests\Feature;

use HardImpact\Orbit\Models\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('orbit.multi_environment')) {
            $this->markTestSkipped('Skipping WebModeTest in desktop mode.');
        }

        // Create local environment
        createEnvironment([
            'is_local' => true,
            'name' => 'Local',
            'host' => 'localhost',
        ]);
    }

    public function test_dashboard_redirects_to_projects(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/projects');
    }

    public function test_projects_page_loads_with_implicit_environment(): void
    {
        $response = $this->get('/projects');

        $response->assertStatus(200);
    }

    public function test_services_page_loads_with_implicit_environment(): void
    {
        $response = $this->get('/services');

        $response->assertStatus(200);
    }

    public function test_desktop_only_routes_return_403(): void
    {
        $this->get('/environments')->assertStatus(403);
        $this->get('/environments/create')->assertStatus(403);
        $this->get('/ssh-keys/available')->assertStatus(403);
    }

    public function test_inertia_props_include_current_environment(): void
    {
        $response = $this->get('/projects');

        $response->assertInertia(fn ($page) => $page->has('currentEnvironment')
            ->where('multi_environment', false)
        );
    }
}
