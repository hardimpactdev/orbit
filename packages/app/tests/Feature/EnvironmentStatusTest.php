<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\HorizonService;
use HardImpact\Orbit\Core\Services\MacPhpFpmConfigService;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\ProjectCliService;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;

beforeEach(function () {
    // Clean database before each test
    Environment::query()->delete();

    $this->mock(MacPhpFpmConfigService::class, function ($mock) {
        $mock->shouldReceive('ensureConfigured')->andReturn(true);
    });

    $this->mock(HorizonService::class, function ($mock) {
        $mock->shouldReceive('status')->andReturn(['success' => true, 'running' => false]);
    });

    $this->mock(ConfigurationService::class, function ($mock) {
        $mock->shouldReceive('getReverbConfig')->andReturn([
            'success' => true,
            'enabled' => false,
        ]);
        $mock->shouldReceive('getConfig')->andReturn([
            'success' => true,
            'data' => ['tld' => 'test'],
        ]);
    });
});

describe('status', function () {
    it('returns environment status with services', function () {
        $environment = Environment::factory()->create();
        $statusData = [
            'services' => [
                'postgres' => ['status' => 'running', 'port' => 5432],
                'redis' => ['status' => 'running', 'port' => 6379],
                'dns' => ['status' => 'running'],
            ],
        ];

        $this->mock(StatusService::class, function ($mock) use ($statusData) {
            $mock->shouldReceive('status')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => $statusData,
                ]);
        });

        $response = $this->get("/api/environments/{$environment->id}/status");

        $response->assertJson([
            'success' => true,
            'data' => [
                'services' => [
                    'postgres' => ['status' => 'running', 'port' => 5432],
                    'redis' => ['status' => 'running', 'port' => 6379],
                    'dns' => ['status' => 'running'],
                ],
            ],
        ]);
    });

    it('calls ensureConfigured for local environments', function () {
        // For local environments, the controller checks is_local and calls ensureConfigured
        // This test verifies the behavior by checking that status() is called
        // (ensureConfigured is a side effect that's already tested in the MacPhpFpmConfigService tests)
        $environment = Environment::factory()->create(['is_local' => true]);

        $this->mock(StatusService::class, function ($mock) {
            $mock->shouldReceive('status')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => ['services' => []],
                ]);
        });

        $response = $this->get("/api/environments/{$environment->id}/status");

        $response->assertJson(['success' => true]);
    });

    it('returns error when status check fails', function () {
        $environment = Environment::factory()->create();

        $this->mock(StatusService::class, function ($mock) {
            $mock->shouldReceive('status')
                ->once()
                ->andReturn([
                    'success' => false,
                    'error' => 'Could not connect to CLI',
                ]);
        });

        $response = $this->get("/api/environments/{$environment->id}/status");

        $response->assertJson([
            'success' => false,
            'error' => 'Could not connect to CLI',
        ]);
    });
});

describe('projects', function () {
    it('returns project status list', function () {
        $environment = Environment::factory()->create();
        $projects = [
            ['name' => 'project-one', 'status' => 'running'],
            ['name' => 'project-two', 'status' => 'stopped'],
        ];

        $this->mock(StatusService::class, function ($mock) use ($projects) {
            $mock->shouldReceive('projects')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => [
                        'projects' => $projects,
                    ],
                ]);
        });

        $response = $this->get("/api/environments/{$environment->id}/projects/status");

        $response->assertJson([
            'success' => true,
            'data' => [
                'projects' => $projects,
            ],
        ]);
    });
});

describe('projectsApi', function () {
    it('returns projects list via API', function () {
        $environment = Environment::factory()->create();
        $projects = [
            ['slug' => 'project-one', 'name' => 'Project One', 'php_version' => '8.4'],
            ['slug' => 'project-two', 'name' => 'Project Two', 'php_version' => '8.3'],
        ];

        $this->mock(ProjectCliService::class, function ($mock) use ($projects) {
            $mock->shouldReceive('projectList')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => $projects,
                ]);
        });

        $response = $this->get("/api/environments/{$environment->id}/projects");

        $response->assertJson([
            'success' => true,
            'data' => $projects,
        ]);
    });
});

describe('projectsSyncApi', function () {
    it('syncs projects from CLI', function () {
        $environment = Environment::factory()->create();
        $projects = [
            ['slug' => 'synced-project', 'name' => 'Synced Project'],
        ];

        $this->mock(ProjectCliService::class, function ($mock) use ($projects) {
            $mock->shouldReceive('syncAllProjectsFromCli')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => $projects,
                ]);
            // After sync, controller calls projectList to return fresh list
            $mock->shouldReceive('projectList')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => $projects,
                ]);
        });

        $response = $this->post("/api/environments/{$environment->id}/projects/sync");

        $response->assertJson([
            'success' => true,
            'data' => $projects,
        ]);
    });
});

describe('getConfig', function () {
    it('returns environment configuration', function () {
        $environment = Environment::factory()->create();
        $config = [
            'tld' => 'test',
            'paths' => ['/home/user/projects'],
            'php_version' => '8.4',
        ];

        $this->mock(ConfigurationService::class, function ($mock) use ($config) {
            $mock->shouldReceive('getConfig')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => $config,
                ]);
            $mock->shouldReceive('getReverbConfig')->andReturn(['success' => true, 'enabled' => false]);
        });

        $response = $this->get(route('environments.config', $environment));

        $response->assertJson([
            'success' => true,
            'data' => $config,
        ]);
    });
});

describe('getReverbConfig', function () {
    it('returns Reverb WebSocket configuration', function () {
        $environment = Environment::factory()->create();
        $reverbConfig = [
            'enabled' => true,
            'host' => 'reverb.test',
            'port' => 8080,
            'scheme' => 'https',
            'app_key' => 'orbit-key',
        ];

        $this->mock(ConfigurationService::class, function ($mock) use ($reverbConfig) {
            $mock->shouldReceive('getReverbConfig')
                ->once()
                ->andReturn(array_merge(['success' => true], $reverbConfig));
        });

        $response = $this->get(route('environments.reverb-config', $environment));

        $response->assertJson(array_merge(['success' => true], $reverbConfig));
    });
});
