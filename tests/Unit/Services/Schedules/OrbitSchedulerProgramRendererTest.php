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
    $program = base64_decode((string) str($script)->match("/printf %s\\s+'([^']+)'/")->toString(), true);

    expect($script)
        ->toContain("sudo tee '/etc/supervisor/conf.d/orbit_scheduler.conf' >/dev/null")
        ->toContain("sudo supervisorctl update 'orbit_scheduler'")
        ->and($program)->toContain('[program:orbit_scheduler]')
        ->and($program)->toContain("command=/bin/bash -lc 'php artisan orbit-scheduler --sleep-seconds=60'")
        ->and($program)->toContain('autostart=true')
        ->and($program)->toContain('stdout_logfile=/home/orbit/.config/orbit/logs/orbit_scheduler.log');
});
