<?php

declare(strict_types=1);

use App\Services\Runtime\OrbitContainerNames;
use App\Services\Runtime\OrbitRuntimeContainer;
use App\Services\Runtime\OrbitRuntimeContainerRenderer;
use Tests\TestCase;

uses(TestCase::class);

it('renders the Orbit runtime container with deterministic network, env, restart policy, and mounts', function (): void {
    $container = (new OrbitRuntimeContainerRenderer(new OrbitContainerNames))->render(
        orbitCheckoutPath: '/Users/nckrtl/Orbit Repo',
        image: 'orbit-runtime:test',
        environment: [
            'APP_ENV' => 'local',
        ],
    );

    expect($container->name())->toBe('orbit-runtime')
        ->and($container->image())->toBe('orbit-runtime:test')
        ->and($container->network())->toBe('orbit-network')
        ->and($container->restartPolicy())->toBe('unless-stopped')
        ->and($container->networkAliases())->toBe(['orbit-runtime'])
        ->and($container->environment())->toBe([
            'APP_ENV' => 'local',
            'ORBIT_SOURCE_PATH' => OrbitRuntimeContainer::SourcePath,
        ])
        ->and($container->mounts())->toContain([
            'source' => '/Users/nckrtl/Orbit Repo',
            'target' => OrbitRuntimeContainer::SourcePath,
            'read_only' => false,
        ])
        ->and($container->mounts())->toContain([
            'source' => '/var/run/docker.sock',
            'target' => '/var/run/docker.sock',
            'read_only' => false,
        ])
        ->and($container->mounts())->not->toContain([
            'source' => '/Users/nckrtl/Orbit Repo/apps/gateway/database/database.sqlite',
            'target' => OrbitRuntimeContainer::SourcePath.'/apps/gateway/database/database.sqlite',
            'read_only' => false,
        ]);
});

it('renders the gateway database bind mount and gateway env when a gateway database path is supplied', function (): void {
    $container = (new OrbitRuntimeContainerRenderer(new OrbitContainerNames))->render(
        orbitCheckoutPath: '/home/orbit/orbit',
        gatewayDatabasePath: '/home/orbit/orbit/apps/gateway/database/database.sqlite',
    );

    expect($container->environment())->toMatchArray([
        'ORBIT_IS_GATEWAY' => '1',
        'ORBIT_SOURCE_PATH' => OrbitRuntimeContainer::SourcePath,
        'ORBIT_TRUST_WIREGUARD_PROXY_HEADER' => '1',
    ])
        ->and($container->mounts())->toContain([
            'source' => '/home/orbit/orbit/apps/gateway/database/database.sqlite',
            'target' => OrbitRuntimeContainer::SourcePath.'/apps/gateway/database/database.sqlite',
            'read_only' => false,
        ]);
});
