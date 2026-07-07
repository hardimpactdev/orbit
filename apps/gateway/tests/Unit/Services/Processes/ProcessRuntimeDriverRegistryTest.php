<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeDrivers\DockerProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\DockerSwarmProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\SystemdProcessRuntimeDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves concrete drivers by process runtime', function (): void {
    $registry = app(ProcessRuntimeDriverRegistry::class);

    expect($registry->for(ProcessRuntime::Docker))
        ->toBeInstanceOf(DockerProcessRuntimeDriver::class)
        ->and($registry->for(ProcessRuntime::DockerSwarm))
        ->toBeInstanceOf(DockerSwarmProcessRuntimeDriver::class)
        ->and($registry->for(ProcessRuntime::Systemd))
        ->toBeInstanceOf(SystemdProcessRuntimeDriver::class);
});

it('resolves launchd runtime value and will map to its driver once registered', function (): void {
    $registry = app(ProcessRuntimeDriverRegistry::class);

    // will fail until enum case + driver registration
    expect(ProcessRuntime::Launchd->value)->toBe('launchd');
    expect($registry->for(ProcessRuntime::Launchd))
        ->toBeInstanceOf(\App\Services\Processes\ProcessRuntimeDrivers\LaunchdProcessRuntimeDriver::class);
});
