<?php

use App\Services\DockerManager;

beforeEach(function () {
    $this->dockerManager = Mockery::mock(DockerManager::class);
    $this->app->instance(DockerManager::class, $this->dockerManager);
});

it('shows logs for a container', function () {
    $this->dockerManager->shouldReceive('logs')
        ->with('orbit-reverb', true)
        ->once();

    $this->artisan('logs orbit-reverb')
        ->expectsOutputToContain('Showing logs for orbit-reverb')
        ->assertExitCode(0);
});

it('can disable follow mode', function () {
    $this->dockerManager->shouldReceive('logs')
        ->with('orbit-postgres', false)
        ->once();

    $this->artisan('logs orbit-postgres --no-follow')
        ->expectsOutputToContain('Showing logs for orbit-postgres')
        ->assertExitCode(0);
});
