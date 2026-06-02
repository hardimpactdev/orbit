<?php

declare(strict_types=1);

use App\Services\Gateway\GatewaySwarmStackRenderer;
use App\Services\Schedules\OrbitSchedulerProgramRenderer;

it('builds the orbit scheduler Swarm definition without requiring a node context', function (): void {
    $parameters = collect((new ReflectionMethod(OrbitSchedulerProgramRenderer::class, 'definition'))->getParameters())
        ->map(fn ($parameter): string => $parameter->getName())
        ->all();

    expect($parameters)->toBe(['sleepSeconds']);

    $definition = (new OrbitSchedulerProgramRenderer)->definition(sleepSeconds: 60);

    expect($definition)->toBe([
        'service' => GatewaySwarmStackRenderer::SchedulerService,
        'stack_service' => 'orbit_orbit-scheduler',
        'command' => 'php artisan orbit-scheduler --sleep-seconds=60',
        'replicas' => 1,
        'update_order' => 'stop-first',
    ]);
});

it('renders recovery scripts for the orbit-scheduler Swarm service', function (): void {
    $script = (new OrbitSchedulerProgramRenderer)->installScript(sleepSeconds: 60);

    expect($script)
        ->toContain("sudo docker service inspect 'orbit_orbit-scheduler' >/dev/null")
        ->toContain("sudo docker service scale 'orbit_orbit-scheduler=1' >/dev/null")
        ->toContain('orbit-scheduler')
        ->not->toContain('orbit'.'-runtime')
        ->not->toContain('supervisor')
        ->not->toContain('/etc/supervisor');
});
