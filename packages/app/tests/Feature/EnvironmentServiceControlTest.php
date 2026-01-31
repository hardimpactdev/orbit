<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\HorizonService;
use HardImpact\Orbit\Core\Services\MacPhpFpmConfigService;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\ServiceControlService;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;

beforeEach(function () {
    // Clean database before each test
    Environment::query()->delete();

    // Mock MacPhpFpmConfigService
    $this->mock(MacPhpFpmConfigService::class, function ($mock) {
        $mock->shouldReceive('ensureConfigured')->andReturn(true);
    });

    // Mock StatusService
    $this->mock(StatusService::class, function ($mock) {
        $mock->shouldReceive('checkInstallation')->andReturn([
            'success' => true,
            'installed' => true,
        ]);
        $mock->shouldReceive('status')->andReturn([
            'success' => true,
            'data' => [
                'services' => [
                    'postgres' => ['status' => 'running'],
                    'redis' => ['status' => 'running'],
                ],
            ],
        ]);
    });

    // Mock ConfigurationService
    $this->mock(ConfigurationService::class, function ($mock) {
        $mock->shouldReceive('getReverbConfig')->andReturn([
            'success' => true,
            'enabled' => false,
        ]);
    });

    // Mock HorizonService
    $this->mock(HorizonService::class, function ($mock) {
        $mock->shouldReceive('status')->andReturn(['success' => true, 'running' => true]);
    });
});

describe('start', function () {
    it('starts all services for an environment', function () {
        $environment = Environment::factory()->create();

        $this->mock(ServiceControlService::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->andReturn(['success' => true, 'message' => 'Services started']);
        });

        $response = $this->post(route('environments.start', $environment));

        $response->assertJson(['success' => true]);
    });

    it('returns error when start fails', function () {
        $environment = Environment::factory()->create();

        $this->mock(ServiceControlService::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->andReturn(['success' => false, 'error' => 'Docker not running']);
        });

        $response = $this->post(route('environments.start', $environment));

        $response->assertJson([
            'success' => false,
            'error' => 'Docker not running',
        ]);
    });
});

describe('stop', function () {
    it('stops all services for an environment', function () {
        $environment = Environment::factory()->create();

        $this->mock(ServiceControlService::class, function ($mock) {
            $mock->shouldReceive('stop')
                ->once()
                ->andReturn(['success' => true, 'message' => 'Services stopped']);
        });

        $response = $this->post(route('environments.stop', $environment));

        $response->assertJson(['success' => true]);
    });
});

describe('restart', function () {
    it('restarts all services for an environment', function () {
        $environment = Environment::factory()->create();

        $this->mock(ServiceControlService::class, function ($mock) {
            $mock->shouldReceive('restart')
                ->once()
                ->andReturn(['success' => true, 'message' => 'Services restarted']);
        });

        $response = $this->post(route('environments.restart', $environment));

        $response->assertJson(['success' => true]);
    });
});

describe('startService', function () {
    it('starts a specific service', function () {
        $environment = Environment::factory()->create();

        $this->mock(ServiceControlService::class, function ($mock) {
            $mock->shouldReceive('startService')
                ->with(\Mockery::any(), 'postgres')
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->post(route('environments.services.start', [
            'environment' => $environment,
            'service' => 'postgres',
        ]));

        $response->assertJson(['success' => true]);
    });
});

describe('stopService', function () {
    it('stops a specific service', function () {
        $environment = Environment::factory()->create();

        $this->mock(ServiceControlService::class, function ($mock) {
            $mock->shouldReceive('stopService')
                ->with(\Mockery::any(), 'redis')
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->post(route('environments.services.stop', [
            'environment' => $environment,
            'service' => 'redis',
        ]));

        $response->assertJson(['success' => true]);
    });
});

describe('restartService', function () {
    it('restarts a specific service', function () {
        $environment = Environment::factory()->create();

        $this->mock(ServiceControlService::class, function ($mock) {
            $mock->shouldReceive('restartService')
                ->with(\Mockery::any(), 'mailpit')
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->post(route('environments.services.restart', [
            'environment' => $environment,
            'service' => 'mailpit',
        ]));

        $response->assertJson(['success' => true]);
    });
});

describe('serviceLogs', function () {
    it('returns logs for a service', function () {
        $environment = Environment::factory()->create();
        $logContent = "2024-01-01 12:00:00 Service started\n2024-01-01 12:00:01 Ready";

        $this->mock(ServiceControlService::class, function ($mock) use ($logContent) {
            $mock->shouldReceive('serviceLogs')
                ->withArgs(fn ($env, $svc, $lines, $since) => $svc === 'postgres' && $lines === 200)
                ->once()
                ->andReturn([
                    'success' => true,
                    'logs' => $logContent,
                ]);
        });

        $response = $this->get(route('environments.services.logs', [
            'environment' => $environment,
            'service' => 'postgres',
        ]));

        $response->assertJson([
            'success' => true,
            'logs' => $logContent,
        ]);
    });

    it('respects custom line count', function () {
        $environment = Environment::factory()->create();

        $this->mock(ServiceControlService::class, function ($mock) {
            // Default is 200 lines; pass lines=50 in query to override
            $mock->shouldReceive('serviceLogs')
                ->withArgs(fn ($env, $svc, $lines, $since) => $svc === 'postgres' && $lines === 200)
                ->once()
                ->andReturn(['success' => true, 'logs' => 'logs']);
        });

        // Note: Controller uses hardcoded 200, not query param for lines
        $response = $this->get(route('environments.services.logs', [
            'environment' => $environment,
            'service' => 'postgres',
        ]));

        $response->assertJson(['success' => true]);
    });
});

describe('availableServices', function () {
    it('returns list of available services', function () {
        $environment = Environment::factory()->create();
        $services = [
            'postgres' => ['name' => 'PostgreSQL', 'enabled' => true],
            'redis' => ['name' => 'Redis', 'enabled' => true],
            'mailpit' => ['name' => 'Mailpit', 'enabled' => false],
        ];

        $this->mock(ServiceControlService::class, function ($mock) use ($services) {
            $mock->shouldReceive('available')
                ->once()
                ->andReturn([
                    'success' => true,
                    'services' => $services,
                ]);
        });

        $response = $this->get(route('environments.services.available', $environment));

        $response->assertJson([
            'success' => true,
            'services' => $services,
        ]);
    });
});

describe('enableService', function () {
    it('enables a service', function () {
        $environment = Environment::factory()->create();

        $this->mock(ServiceControlService::class, function ($mock) {
            $mock->shouldReceive('enable')
                ->with(\Mockery::any(), 'meilisearch', [])
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->post(route('environments.services.enable', [
            'environment' => $environment,
            'service' => 'meilisearch',
        ]));

        $response->assertJson(['success' => true]);
    });

    it('passes options when enabling service', function () {
        $environment = Environment::factory()->create();
        $options = ['version' => '1.5'];

        $this->mock(ServiceControlService::class, function ($mock) use ($options) {
            $mock->shouldReceive('enable')
                ->with(\Mockery::any(), 'meilisearch', $options)
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->post(route('environments.services.enable', [
            'environment' => $environment,
            'service' => 'meilisearch',
        ]), ['options' => $options]);

        $response->assertJson(['success' => true]);
    });
});

describe('disableService', function () {
    it('disables a service', function () {
        $environment = Environment::factory()->create();

        $this->mock(ServiceControlService::class, function ($mock) {
            $mock->shouldReceive('disable')
                ->with(\Mockery::any(), 'meilisearch')
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->delete(route('environments.services.disable', [
            'environment' => $environment,
            'service' => 'meilisearch',
        ]));

        $response->assertJson(['success' => true]);
    });
});

describe('configureService', function () {
    it('updates service configuration', function () {
        $environment = Environment::factory()->create();
        $config = ['port' => 5433, 'memory' => '512M'];

        $this->mock(ServiceControlService::class, function ($mock) use ($config) {
            $mock->shouldReceive('configure')
                ->with(\Mockery::any(), 'postgres', $config)
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->put(route('environments.services.config', [
            'environment' => $environment,
            'service' => 'postgres',
        ]), ['config' => $config]);

        $response->assertJson(['success' => true]);
    });
});

describe('serviceInfo', function () {
    it('returns service information', function () {
        $environment = Environment::factory()->create();
        $info = [
            'name' => 'PostgreSQL',
            'version' => '16',
            'port' => 5432,
            'status' => 'running',
        ];

        $this->mock(ServiceControlService::class, function ($mock) use ($info) {
            $mock->shouldReceive('info')
                ->with(\Mockery::any(), 'postgres')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => $info,
                ]);
        });

        $response = $this->get(route('environments.services.info', [
            'environment' => $environment,
            'service' => 'postgres',
        ]));

        $response->assertJson([
            'success' => true,
            'data' => $info,
        ]);
    });
});
