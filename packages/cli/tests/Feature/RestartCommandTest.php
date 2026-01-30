<?php

use App\Contracts\CaddyfileGeneratorInterface;
use App\Services\CaddyManager;
use App\Services\HorizonManager;
use App\Services\PhpManager;
use App\Services\ServiceManager;

beforeEach(function () {
    $this->serviceManager = Mockery::mock(ServiceManager::class);
    $this->caddyfileGenerator = Mockery::mock(CaddyfileGeneratorInterface::class);
    $this->phpManager = Mockery::mock(PhpManager::class);
    $this->caddyManager = Mockery::mock(CaddyManager::class);
    $this->horizonManager = Mockery::mock(HorizonManager::class);

    $this->app->instance(ServiceManager::class, $this->serviceManager);
    $this->app->instance(CaddyfileGeneratorInterface::class, $this->caddyfileGenerator);
    $this->app->instance(PhpManager::class, $this->phpManager);
    $this->app->instance(CaddyManager::class, $this->caddyManager);
    $this->app->instance(HorizonManager::class, $this->horizonManager);
});

it('restarts all services by calling stop and start', function () {
    // No FPM sockets - FrankenPHP mode
    $this->phpManager->shouldReceive('getSocketPath')->andReturn('/tmp/nonexistent.sock');

    // Stop phase
    $this->serviceManager->shouldReceive('stopAll')->once()->andReturn(true);

    // Start phase
    $this->caddyfileGenerator->shouldReceive('generate')->once();
    $this->serviceManager->shouldReceive('startAll')->once()->andReturn(true);

    $this->artisan('restart')
        ->assertExitCode(0);
});

it('outputs json when --json flag is used', function () {
    // No FPM sockets - FrankenPHP mode
    $this->phpManager->shouldReceive('getSocketPath')->andReturn('/tmp/nonexistent.sock');

    // Stop phase
    $this->serviceManager->shouldReceive('stopAll')->once()->andReturn(true);

    // Start phase
    $this->caddyfileGenerator->shouldReceive('generate')->once();
    $this->serviceManager->shouldReceive('startAll')->once()->andReturn(true);

    $this->artisan('restart --json')
        ->assertExitCode(0);
});
