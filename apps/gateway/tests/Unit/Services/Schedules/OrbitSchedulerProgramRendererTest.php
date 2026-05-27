<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Schedules\OrbitSchedulerProgramRenderer;

it('builds the orbit scheduler runtime definition for the gateway container', function (): void {
    $node = new Node([
        'name' => 'gateway-1',
        'user' => 'deploy',
        'orbit_path' => '/srv/orbit',
    ]);

    $definition = (new OrbitSchedulerProgramRenderer)->definition($node, sleepSeconds: 60);

    expect($definition)->toBe([
        'container' => 'orbit-runtime',
        'command' => 'orbit orbit-scheduler --sleep-seconds=60',
        'restart_policy' => 'unless-stopped',
    ]);
});

it('renders restart scripts for the orbit-runtime scheduler path', function (): void {
    $node = new Node([
        'user' => null,
        'orbit_path' => '/home/orbit/orbit',
    ]);

    $script = (new OrbitSchedulerProgramRenderer)->installScript($node, sleepSeconds: 60);

    expect($script)
        ->toContain("sudo docker inspect 'orbit-runtime' >/dev/null")
        ->toContain("sudo docker restart 'orbit-runtime' >/dev/null")
        ->toContain("sudo docker exec --detach 'orbit-runtime' sh -lc 'exec orbit orbit-scheduler --sleep-seconds=60' >/dev/null")
        ->not->toContain('supervisor')
        ->not->toContain('/etc/supervisor')
        ->not->toContain('php artisan orbit-scheduler');
});
