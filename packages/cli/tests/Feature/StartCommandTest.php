<?php

use App\Enums\ExitCode;
use App\Services\CaddyfileGenerator;
use App\Services\CaddyManager;
use App\Services\HorizonManager;
use App\Services\PhpManager;
use App\Services\ServiceManager;

beforeEach(function () {
    $this->serviceManager = Mockery::mock(ServiceManager::class);
    $this->caddyfileGenerator = Mockery::mock(CaddyfileGenerator::class);
    $this->phpManager = Mockery::mock(PhpManager::class);
    $this->caddyManager = Mockery::mock(CaddyManager::class);
    $this->horizonManager = Mockery::mock(HorizonManager::class);

    $this->app->instance(ServiceManager::class, $this->serviceManager);
    $this->app->instance(CaddyfileGenerator::class, $this->caddyfileGenerator);
    $this->app->instance(PhpManager::class, $this->phpManager);
    $this->app->instance(CaddyManager::class, $this->caddyManager);
    $this->app->instance(HorizonManager::class, $this->horizonManager);
});

it('starts all services successfully', function () {
    // No FPM sockets - FrankenPHP mode
    $this->phpManager->shouldReceive('getSocketPath')->andReturn('/tmp/nonexistent.sock');

    $this->caddyfileGenerator->shouldReceive('generate')->once();
    $this->serviceManager->shouldReceive('startAll')->once()->andReturn(true);

    $this->artisan('start')
        ->expectsOutputToContain('Orbit is running')
        ->assertExitCode(0);
});

it('reports failure when a service fails to start', function () {
    // No FPM sockets - FrankenPHP mode
    $this->phpManager->shouldReceive('getSocketPath')->andReturn('/tmp/nonexistent.sock');

    $this->caddyfileGenerator->shouldReceive('generate')->once();
    $this->serviceManager->shouldReceive('startAll')->once()->andReturn(false);

    $this->artisan('start')
        ->expectsOutputToContain('Some services failed to start')
        ->assertExitCode(ExitCode::ServiceFailed->value);
});

it('outputs json when --json flag is used', function () {
    // No FPM sockets - FrankenPHP mode
    $this->phpManager->shouldReceive('getSocketPath')->andReturn('/tmp/nonexistent.sock');

    $this->caddyfileGenerator->shouldReceive('generate')->once();
    $this->serviceManager->shouldReceive('startAll')->once()->andReturn(true);

    $this->artisan('start --json')
        ->assertExitCode(0);
});
