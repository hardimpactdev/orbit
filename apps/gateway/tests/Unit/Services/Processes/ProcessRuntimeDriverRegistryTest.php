<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeDrivers\DockerProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\SupervisorProcessRuntimeDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves concrete drivers by process runtime', function (): void {
    $registry = app(ProcessRuntimeDriverRegistry::class);

    expect($registry->for(ProcessRuntime::Supervisor))
        ->toBeInstanceOf(SupervisorProcessRuntimeDriver::class)
        ->and($registry->for(ProcessRuntime::Docker))
        ->toBeInstanceOf(DockerProcessRuntimeDriver::class);
});
