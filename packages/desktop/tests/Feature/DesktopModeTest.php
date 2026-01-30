<?php

declare(strict_types=1);

namespace Tests\Feature;

use HardImpact\Orbit\Core\Models\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesktopModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('orbit.multi_environment')) {
            $this->markTestSkipped('Skipping DesktopModeTest in web mode.');
        }

        // Ensure desktop mode
        config(['orbit.multi_environment' => true]);
    }

    public function test_sites_page_loads_with_route_parameter(): void
    {
        $environment = createEnvironment();

        $response = $this->get("/environments/{$environment->id}/sites");

        $response->assertStatus(200);
    }

    public function test_environment_management_accessible(): void
    {
        $this->get('/environments')->assertStatus(200);
        $this->get('/environments/create')->assertStatus(200);
    }

    public function test_all_desktop_features_accessible(): void
    {
        $environment = createEnvironment();

        // Mock services to avoid real SSH/Process calls
        $this->mock(\HardImpact\Orbit\Services\OrbitCli\StatusService::class, function ($mock) {
            $mock->shouldReceive('checkInstallation')->andReturn([
                'installed' => true,
                'version' => '0.0.1',
                'path' => '/usr/local/bin/orbit',
            ]);
        });

        $this->mock(\HardImpact\Orbit\Services\DoctorService::class, function ($mock) {
            $mock->shouldReceive('runChecks')->andReturn([
                'success' => true,
                'status' => 'healthy',
                'checks' => [],
                'summary' => [],
            ]);
        });

        $this->get("/environments/{$environment->id}/services")->assertStatus(200);
        $this->get("/environments/{$environment->id}/settings")->assertStatus(200);
        $this->get("/environments/{$environment->id}/workspaces")->assertStatus(200);
        $this->get("/environments/{$environment->id}/doctor")->assertStatus(200);
    }

    public function test_inertia_props_multi_environment_true(): void
    {
        $environment = createEnvironment();

        $response = $this->get("/environments/{$environment->id}/sites");

        $response->assertInertia(fn ($page) => $page->where('multi_environment', true)
            ->where('currentEnvironment', null)
        );
    }

    public function test_dashboard_shows_environment_list(): void
    {
        createEnvironment(['name' => 'Env 1']);
        createEnvironment(['name' => 'Env 2', 'is_default' => false]);
        createEnvironment(['name' => 'Env 3', 'is_default' => false]);

        $response = $this->get('/');

        // Dashboard redirects to default environment or first environment
        $response->assertStatus(302);
    }
}
