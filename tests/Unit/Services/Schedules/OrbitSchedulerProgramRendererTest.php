<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Schedules\OrbitSchedulerProgramRenderer;

it('builds the orbit scheduler supervisor program definition for a node', function (): void {
    $node = new Node([
        'name' => 'app-1',
        'ssh_user' => 'orbit',
        'user' => 'deploy',
        'orbit_path' => '/srv/orbit',
    ]);

    $definition = (new OrbitSchedulerProgramRenderer)->definition($node);

    expect($definition->name)->toBe('orbit_scheduler');
    expect($definition->directory)->toBe('/srv/orbit');
    expect($definition->command)->toBe('php artisan orbit-scheduler');
    expect($definition->user)->toBe('deploy');
    expect($definition->restartPolicy)->toBe('true');
    expect($definition->stdoutLogFile)->toBe('/home/deploy/.config/orbit/logs/orbit_scheduler.log');
    expect($definition->autostart)->toBeTrue();
});

it('renders install scripts for the orbit scheduler supervisor program', function (): void {
    $node = new Node([
        'ssh_user' => 'orbit',
        'user' => null,
        'orbit_path' => '/home/orbit/orbit',
    ]);

    $script = (new OrbitSchedulerProgramRenderer)->installScript($node, sleepSeconds: 60);

    expect($script)
        ->toContain("sudo tee '/etc/supervisor/conf.d/orbit_scheduler.conf' >/dev/null")
        ->toContain('[program:orbit_scheduler]')
        ->toContain("command=/bin/bash -lc 'php artisan orbit-scheduler --sleep-seconds=60'")
        ->toContain('autostart=true')
        ->toContain('stdout_logfile=/home/orbit/.config/orbit/logs/orbit_scheduler.log')
        ->toContain("sudo supervisorctl update 'orbit_scheduler'");
});
