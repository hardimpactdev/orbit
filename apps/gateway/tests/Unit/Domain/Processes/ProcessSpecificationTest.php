<?php

declare(strict_types=1);

use App\Data\Processes\AddProcessData;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetType;
use App\Models\Node;
use App\Models\Process;

function process_specification_data(array $environment, ?string $user = null): AddProcessData
{
    return new AddProcessData(
        targetType: ProcessTargetType::Node,
        targetId: 1,
        name: 'ai-router',
        runtime: ProcessRuntime::Systemd,
        command: ['/usr/bin/python3', '/var/lib/orbit/ai-router/collector.py'],
        image: null,
        workingDirectory: '/var/lib/orbit/ai-router',
        environment: $environment,
        ports: ['127.0.0.1:8787:8787/tcp'],
        volumes: [],
        restartPolicy: 'unless-stopped',
        start: true,
        user: $user,
    );
}

describe('ProcessUser', function (): void {
    it('distinguishes explicit and derived accounts even with the same directory', function (): void {
        $target = new ProcessTarget(node: new Node(['name' => 'beast']), user: 'orbit', checkoutPath: '/home/orbit');
        $specification = new ProcessSpecification;
        $attributes = $specification->attributes(process_specification_data([], 'svc-runner'), $target);
        $process = new Process($attributes);
        expect($specification->matches($process, $attributes))->toBeTrue()
            ->and($specification->matches($process, $specification->attributes(process_specification_data([], 'other-worker'), $target)))->toBeFalse()
            ->and($specification->matches($process, $specification->attributes(process_specification_data([]), $target)))->toBeFalse();
    });
});

it('persists a systemd environment map in canonical key order', function (): void {
    $target = new ProcessTarget(
        node: new Node(['name' => 'beast']),
        user: 'orbit',
        checkoutPath: '/home/orbit',
        environmentFile: '',
    );

    $attributes = new ProcessSpecification()->attributes(
        process_specification_data([
            'AI_ROUTER_READ_TOKEN' => 'read',
            'AI_ROUTER_CACHE_HOST' => '10.44.0.8',
            'AI_ROUTER_PORT' => '8787',
        ]),
        $target,
    );

    expect($attributes['runtime_config'])->toBe([
        'command' => ['/usr/bin/python3', '/var/lib/orbit/ai-router/collector.py'],
        'environment_file' => '',
        'environment' => [
            'AI_ROUTER_CACHE_HOST' => '10.44.0.8',
            'AI_ROUTER_PORT' => '8787',
            'AI_ROUTER_READ_TOKEN' => 'read',
        ],
    ]);
});

it('omits an empty systemd environment map so existing units stay identical', function (): void {
    $target = new ProcessTarget(
        node: new Node(['name' => 'beast']),
        user: 'orbit',
        checkoutPath: '/home/orbit',
        environmentFile: '',
    );

    $attributes = new ProcessSpecification()->attributes(process_specification_data([]), $target);

    expect($attributes['runtime_config'])->toBe([
        'command' => ['/usr/bin/python3', '/var/lib/orbit/ai-router/collector.py'],
        'environment_file' => '',
    ]);
});
