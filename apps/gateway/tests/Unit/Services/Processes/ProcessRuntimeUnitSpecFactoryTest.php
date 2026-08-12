<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Processes;

use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessRuntimeUnitSpecFactory;
use Tests\TestCase;

uses(TestCase::class);

it('formats one resolved systemd unit without resolving placement', function (): void {
    $node = new Node([
        'name' => 'app-development',
        'platform' => 'ubuntu_24-04',
        'user' => 'orbit',
    ]);
    $app = new App([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
    ]);
    $app->setRelation('node', $node);
    $process = new Process([
        'name' => 'vite',
        'command' => 'npm run dev',
        'restart_policy' => ProcessRestartPolicy::Always,
        'runtime' => ProcessRuntime::Systemd,
    ]);
    $process->setRelation('owner', $node);

    $specification = app(ProcessRuntimeUnitSpecFactory::class)
        ->make(ProcessRuntime::Systemd, $node, $app, $process)
        ->toArray();

    expect($specification)
        ->toMatchArray([
            'name' => 'vite',
            'config_path' => '/etc/systemd/system/vite.service',
            'restart_policy' => 'always',
        ])
        ->and($specification['config_hash'])
        ->toHaveLength(64)
        ->and($specification['environment_lines'])
        ->toContain(
            'Environment="HOME=/home/orbit"',
        )
        ->and($specification)
        ->not->toHaveKey('label');
});

it('includes the launchd label only for a resolved launchd unit', function (): void {
    $node = new Node([
        'name' => 'mac-app',
        'platform' => 'macos_26-5-1',
        'user' => 'nckrtl',
    ]);
    $app = new App([
        'name' => 'runtime',
        'path' => '/Users/nckrtl',
    ]);
    $app->setRelation('node', $node);
    $process = new Process([
        'name' => 'scheduler',
        'command' => 'php artisan schedule:work',
        'restart_policy' => ProcessRestartPolicy::Always,
        'runtime' => ProcessRuntime::Launchd,
    ]);
    $process->setRelation('owner', $node);

    $specification = app(ProcessRuntimeUnitSpecFactory::class)
        ->make(ProcessRuntime::Launchd, $node, $app, $process)
        ->toArray();

    expect($specification)->toMatchArray([
        'name' => 'scheduler',
        'config_path' => '/Users/nckrtl/Library/LaunchAgents/dev.hardimpact.orbit.scheduler.plist',
        'label' => 'dev.hardimpact.orbit.scheduler',
    ]);
});
