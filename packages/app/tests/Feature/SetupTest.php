<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\SetupService;

beforeEach(function () {
    // Clean database before each test
    Environment::query()->delete();

    // Set desktop mode (multi_environment = true) for setup tests
    config()->set('orbit.multi_environment', true);
});

describe('setup page', function () {
    it('shows setup page when setup is needed', function () {
        // Mock SetupService to indicate setup is needed
        $this->mock(SetupService::class, function ($mock) {
            $mock->shouldReceive('needsSetup')->andReturn(true);
            $mock->shouldReceive('getStatus')->andReturn([
                'needs_setup' => true,
                'cli_installed' => false,
                'cli_version' => null,
                'has_local_environment' => false,
                'has_services' => false,
                'has_tld' => false,
                'steps' => [
                    'check_prerequisites' => 'Checking prerequisites',
                    'download_cli' => 'Downloading Orbit CLI',
                    'install_cli' => 'Installing CLI',
                    'init_services' => 'Initializing services',
                    'configure_tld' => 'Configuring TLD',
                    'create_environment' => 'Creating local environment',
                ],
            ]);
        });

        $response = $this->get(route('setup.index'));

        $response->assertInertia(fn ($page) => $page
            ->component('Setup')
            ->has('status')
            ->where('status.needs_setup', true)
            ->where('status.cli_installed', false));
    });

    it('returns setup status via API', function () {
        $this->mock(SetupService::class, function ($mock) {
            $mock->shouldReceive('getStatus')->andReturn([
                'needs_setup' => true,
                'cli_installed' => false,
                'cli_version' => null,
                'has_local_environment' => false,
                'has_services' => false,
                'has_tld' => false,
                'steps' => [],
            ]);
        });

        $response = $this->get(route('setup.check'));

        $response->assertJson([
            'needs_setup' => true,
            'cli_installed' => false,
        ]);
    });
});

describe('setup run', function () {
    it('runs setup with default TLD', function () {
        $this->mock(SetupService::class, function ($mock) {
            $mock->shouldReceive('runSetupSync')
                ->with('test')
                ->once()
                ->andReturn([
                    'success' => true,
                    'steps' => [
                        'check_prerequisites' => ['step' => 1, 'total' => 6, 'message' => 'Checking prerequisites', 'success' => true],
                        'download_cli' => ['step' => 2, 'total' => 6, 'message' => 'Downloading Orbit CLI', 'success' => true],
                        'install_cli' => ['step' => 3, 'total' => 6, 'message' => 'Installing CLI', 'success' => true],
                        'init_services' => ['step' => 4, 'total' => 6, 'message' => 'Initializing services', 'success' => true],
                        'configure_tld' => ['step' => 5, 'total' => 6, 'message' => 'Configuring TLD', 'success' => true],
                        'create_environment' => ['step' => 6, 'total' => 6, 'message' => 'Creating local environment', 'success' => true],
                    ],
                    'error' => null,
                ]);
        });

        $response = $this->postJson(route('setup.run'));

        $response->assertJson([
            'success' => true,
        ]);
    });

    it('runs setup with custom TLD', function () {
        $this->mock(SetupService::class, function ($mock) {
            $mock->shouldReceive('runSetupSync')
                ->with('dev')
                ->once()
                ->andReturn([
                    'success' => true,
                    'steps' => [],
                    'error' => null,
                ]);
        });

        $response = $this->postJson(route('setup.run'), ['tld' => 'dev']);

        $response->assertJson([
            'success' => true,
        ]);
    });

    it('returns error when setup fails', function () {
        $this->mock(SetupService::class, function ($mock) {
            $mock->shouldReceive('runSetupSync')
                ->once()
                ->andReturn([
                    'success' => false,
                    'steps' => [
                        'check_prerequisites' => ['step' => 1, 'total' => 6, 'message' => 'Checking prerequisites', 'success' => true],
                        'download_cli' => ['step' => 2, 'total' => 6, 'message' => 'Downloading Orbit CLI', 'success' => true],
                        'install_cli' => ['step' => 3, 'total' => 6, 'message' => 'Installing CLI', 'success' => false, 'error' => 'Download failed'],
                    ],
                    'error' => 'Download failed',
                ]);
        });

        $response = $this->postJson(route('setup.run'));

        $response->assertJson([
            'success' => false,
            'error' => 'Download failed',
        ]);
    });
});

describe('setup redirect middleware', function () {
    it('redirects to setup when needed', function () {
        $this->mock(SetupService::class, function ($mock) {
            $mock->shouldReceive('needsSetup')->andReturn(true);
        });

        $response = $this->get('/');

        $response->assertRedirect(route('setup.index'));
    });

    it('does not redirect when setup is not needed', function () {
        // Create a local environment
        Environment::factory()->create([
            'is_local' => true,
            'is_default' => true,
            'status' => Environment::STATUS_ACTIVE,
        ]);

        $this->mock(SetupService::class, function ($mock) {
            $mock->shouldReceive('needsSetup')->andReturn(false);
        });

        // Need to also mock status service for the dashboard
        $this->mock(\HardImpact\Orbit\Core\Services\OrbitCli\StatusService::class, function ($mock) {
            $mock->shouldReceive('checkInstallation')->andReturn([
                'installed' => true,
                'version' => '1.0.0',
            ]);
            $mock->shouldReceive('status')->andReturn([
                'success' => true,
                'data' => ['services' => [], 'projects' => []],
            ]);
        });

        $this->mock(\HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService::class, function ($mock) {
            $mock->shouldReceive('getConfig')->andReturn([
                'success' => true,
                'data' => ['tld' => 'test'],
            ]);
            $mock->shouldReceive('getReverbConfig')->andReturn([
                'success' => true,
                'enabled' => false,
            ]);
        });

        $response = $this->get('/');

        // Should redirect to environment show, not setup
        $response->assertRedirectContains('environments');
    });

    it('does not redirect setup routes', function () {
        $this->mock(SetupService::class, function ($mock) {
            $mock->shouldReceive('needsSetup')->andReturn(true);
            $mock->shouldReceive('getStatus')->andReturn([
                'needs_setup' => true,
                'cli_installed' => false,
                'cli_version' => null,
                'has_local_environment' => false,
                'has_services' => false,
                'has_tld' => false,
                'steps' => [],
            ]);
        });

        $response = $this->get(route('setup.index'));

        // Should NOT redirect (stay on setup page)
        $response->assertOk();
    });
});
