<?php

use App\Services\DockerManager;

beforeEach(function () {
    $this->dockerManager = Mockery::mock(DockerManager::class);
    $this->app->instance(DockerManager::class, $this->dockerManager);
});

it('shows logs for a container', function () {
    $this->dockerManager->shouldReceive('logs')
        ->with('orbit-caddy', true)
        ->once();

    $this->artisan('logs orbit-caddy')
        ->expectsOutputToContain('Showing logs for orbit-caddy')
        ->assertExitCode(0);
});

it('can disable follow mode', function () {
    $this->dockerManager->shouldReceive('logs')
        ->with('orbit-php-83', false)
        ->once();

    $this->artisan('logs orbit-php-83 --no-follow')
        ->expectsOutputToContain('Showing logs for orbit-php-83')
        ->assertExitCode(0);
});
