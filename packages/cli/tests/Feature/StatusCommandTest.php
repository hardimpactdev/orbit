<?php

use App\Services\CaddyManager;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use App\Services\HorizonManager;
use App\Services\PhpManager;
use App\Services\ProjectScanner;
use App\Services\ServiceManager;

beforeEach(function () {
    $this->configManager = Mockery::mock(ConfigManager::class);
    $this->dockerManager = Mockery::mock(DockerManager::class);
    $this->projectScanner = Mockery::mock(ProjectScanner::class);
    $this->phpManager = Mockery::mock(PhpManager::class);
    $this->serviceManager = Mockery::mock(ServiceManager::class);
    $this->caddyManager = Mockery::mock(CaddyManager::class);
    $this->horizonManager = Mockery::mock(HorizonManager::class);

    $this->app->instance(ConfigManager::class, $this->configManager);
    $this->app->instance(DockerManager::class, $this->dockerManager);
    $this->app->instance(ProjectScanner::class, $this->projectScanner);
    $this->app->instance(PhpManager::class, $this->phpManager);
    $this->app->instance(ServiceManager::class, $this->serviceManager);
    $this->app->instance(CaddyManager::class, $this->caddyManager);
    $this->app->instance(HorizonManager::class, $this->horizonManager);
});

it('shows status with all services running', function () {
    // No FPM sockets - host PHP-FPM not detected
    $this->phpManager->shouldReceive('getSocketPath')->andReturn('/tmp/nonexistent.sock');
    $this->phpManager->shouldReceive('getInstalledVersions')->andReturn([]);

    $this->serviceManager->shouldReceive('getEnabled')->andReturn([
        'dns' => ['enabled' => true],
        'postgres' => ['enabled' => true],
        'redis' => ['enabled' => true],
        'mailpit' => ['enabled' => true],
    ]);

    // Caddy runs on host, not Docker
    $this->caddyManager->shouldReceive('isRunning')->andReturn(true);

    $this->dockerManager->shouldReceive('getAllStatuses')->andReturn([
        'dns' => ['running' => true, 'health' => 'healthy', 'container' => 'orbit-dns'],
        'postgres' => ['running' => true, 'health' => 'healthy', 'container' => 'orbit-postgres'],
        'redis' => ['running' => true, 'health' => 'healthy', 'container' => 'orbit-redis'],
        'mailpit' => ['running' => true, 'health' => 'healthy', 'container' => 'orbit-mailpit'],
    ]);

    $this->projectScanner->shouldReceive('scan')->andReturn([
        ['name' => 'mysite', 'domain' => 'mysite.test', 'path' => '/path/to/mysite', 'php_version' => '8.3', 'has_custom_php' => false],
    ]);
    $this->configManager->shouldReceive('getConfigPath')->andReturn('/home/user/.config/orbit');
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');

    $this->artisan('status')
        ->expectsOutputToContain('Orbit is running')
        ->assertExitCode(0);
});

it('shows status with all services stopped', function () {
    // No FPM sockets - host PHP-FPM not detected
    $this->phpManager->shouldReceive('getSocketPath')->andReturn('/tmp/nonexistent.sock');
    $this->phpManager->shouldReceive('getInstalledVersions')->andReturn([]);

    $this->serviceManager->shouldReceive('getEnabled')->andReturn([
        'dns' => ['enabled' => true],
        'postgres' => ['enabled' => true],
        'redis' => ['enabled' => true],
        'mailpit' => ['enabled' => true],
    ]);

    // Caddy runs on host, not Docker
    $this->caddyManager->shouldReceive('isRunning')->andReturn(false);

    $this->dockerManager->shouldReceive('getAllStatuses')->andReturn([
        'dns' => ['running' => false, 'health' => null, 'container' => 'orbit-dns'],
        'postgres' => ['running' => false, 'health' => null, 'container' => 'orbit-postgres'],
        'redis' => ['running' => false, 'health' => null, 'container' => 'orbit-redis'],
        'mailpit' => ['running' => false, 'health' => null, 'container' => 'orbit-mailpit'],
    ]);

    $this->projectScanner->shouldReceive('scan')->andReturn([]);
    $this->configManager->shouldReceive('getConfigPath')->andReturn('/home/user/.config/orbit');
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');

    $this->artisan('status')
        ->expectsOutputToContain('Orbit is stopped')
        ->assertExitCode(0);
});

it('outputs json when --json flag is used', function () {
    // No FPM sockets - host PHP-FPM not detected
    $this->phpManager->shouldReceive('getSocketPath')->andReturn('/tmp/nonexistent.sock');
    $this->phpManager->shouldReceive('getInstalledVersions')->andReturn([]);

    $this->serviceManager->shouldReceive('getEnabled')->andReturn([
        'dns' => ['enabled' => true],
        'postgres' => ['enabled' => true],
        'redis' => ['enabled' => true],
        'mailpit' => ['enabled' => true],
    ]);

    // Caddy runs on host, not Docker
    $this->caddyManager->shouldReceive('isRunning')->andReturn(true);

    $this->dockerManager->shouldReceive('getAllStatuses')->andReturn([
        'dns' => ['running' => true, 'health' => 'healthy', 'container' => 'orbit-dns'],
        'postgres' => ['running' => true, 'health' => 'healthy', 'container' => 'orbit-postgres'],
        'redis' => ['running' => true, 'health' => 'healthy', 'container' => 'orbit-redis'],
        'mailpit' => ['running' => true, 'health' => 'healthy', 'container' => 'orbit-mailpit'],
    ]);

    $this->projectScanner->shouldReceive('scan')->andReturn([]);
    $this->configManager->shouldReceive('getConfigPath')->andReturn('/home/user/.config/orbit');
    $this->configManager->shouldReceive('getTld')->andReturn('test');
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');

    $this->artisan('status --json')
        ->assertExitCode(0);
});
