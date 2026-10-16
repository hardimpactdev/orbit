<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessRuntimeManager;
use App\Infrastructure\Hibernation\RemoteInstanceRuntimeReadiness;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;

it('waits for observed running status without probing a fallback Vite port', function (): void {
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldReceive('status')->once()->andReturn('running');
    $readiness = new RemoteInstanceRuntimeReadiness(
        runtime: $runtime,
        timeoutSeconds: 5,
    );
    $instance = readiness_instance();
    $process = readiness_process($instance, 'vite');

    $readiness->waitUntilReady($instance, [$process]);
});

it('does not probe the Vite port for a queue Process', function (): void {
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldReceive('status')->once()->andReturn('active');
    $readiness = new RemoteInstanceRuntimeReadiness(
        runtime: $runtime,
        timeoutSeconds: 5,
    );
    $instance = readiness_instance();
    $process = readiness_process($instance, 'queue');

    $readiness->waitUntilReady($instance, [$process]);
});

function readiness_instance(): Instance
{
    $node = new Node([
        'name' => 'app-dev',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $instance = new Instance([
        'name' => 'main',
        'environment' => 'development',
    ]);
    $instance->setRelation('node', $node);

    return $instance;
}

function readiness_process(Instance $instance, string $name): Process
{
    $process = new Process([
        'name' => $name,
        'runtime' => 'systemd',
        'working_directory' => '/home/orbit/apps/docs',
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => 'on-failure',
        'desired_state' => 'running',
        'status' => 'active',
    ]);
    $process->id = 9;
    $process->setRelation('owner', $instance);

    return $process;
}
