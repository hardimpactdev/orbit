<?php

declare(strict_types=1);

use App\Data\RuntimeBackend\SupervisorProgramDefinition;
use App\Services\RuntimeBackend\SupervisorProgramRenderer;

it('renders supervisor program definitions for runtime backend enactment', function (): void {
    $program = new SupervisorProgramDefinition(
        name: 'orbit_docs_main_vite',
        directory: '/home/orbit/apps/docs',
        command: "npm run dev -- --host=0.0.0.0 --title='Orbit'",
        user: 'orbit',
        restartPolicy: 'unexpected',
        stdoutLogFile: '/home/orbit/.config/orbit/logs/orbit_docs_main_vite.log',
        environment: [
            'APP_URL' => 'https://docs.test',
            'QUOTED' => 'value with "quotes"',
            'WINDOWS_PATH' => 'C:\\tools',
        ],
    );

    $rendered = (new SupervisorProgramRenderer)->render($program);

    expect($rendered)
        ->toContain('[program:orbit_docs_main_vite]')
        ->toContain('directory=/home/orbit/apps/docs')
        ->toContain("command=/bin/bash -lc 'npm run dev -- --host=0.0.0.0 --title='\\''Orbit'\\'''")
        ->toContain('user=orbit')
        ->toContain('autostart=false')
        ->toContain('autorestart=unexpected')
        ->toContain('stdout_logfile=/home/orbit/.config/orbit/logs/orbit_docs_main_vite.log')
        ->toContain('environment=APP_URL="https://docs.test",QUOTED="value with \"quotes\"",WINDOWS_PATH="C:\\\\tools"')
        ->toEndWith("\n");
});

it('renders install scripts for supervisor program definitions', function (): void {
    $program = new SupervisorProgramDefinition(
        name: 'orbit_scheduler',
        directory: '/Users/orbit/orbit',
        command: 'php artisan orbit-scheduler',
        user: 'orbit',
        restartPolicy: 'true',
        stdoutLogFile: '/Users/orbit/.config/orbit/logs/orbit_scheduler.log',
    );

    $script = (new SupervisorProgramRenderer)->renderInstallScript($program);
    $content = base64_decode((string) str($script)->match("/printf %s\\s+'([^']+)'/")->toString(), true);

    expect($script)
        ->toContain('sudo mkdir -p /etc/supervisor/conf.d')
        ->toContain("sudo tee '/etc/supervisor/conf.d/orbit_scheduler.conf' >/dev/null")
        ->toContain("sudo supervisorctl update 'orbit_scheduler'")
        ->and($content)->toBe((new SupervisorProgramRenderer)->render($program))
        ->and($content)->toContain('[program:orbit_scheduler]')
        ->and($content)->toContain("command=/bin/bash -lc 'php artisan orbit-scheduler'");
});

it('rejects unsafe supervisor program names', function (): void {
    $program = new SupervisorProgramDefinition(
        name: '../orbit',
        directory: '/home/orbit/apps/docs',
        command: 'php artisan queue:work',
        user: 'orbit',
        restartPolicy: 'true',
        stdoutLogFile: '/home/orbit/.config/orbit/logs/orbit.log',
    );

    (new SupervisorProgramRenderer)->render($program);
})->throws(InvalidArgumentException::class, 'Unsafe Supervisor program name: ../orbit');
