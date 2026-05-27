<?php

declare(strict_types=1);

use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Runtime\OrbitRuntimeContainerRenderer;
use Tests\TestCase;

uses(TestCase::class);

it('builds escaped docker run commands for rendered runtime containers', function (): void {
    $container = (new OrbitRuntimeContainerRenderer(new OrbitContainerNames))->render(
        orbitCheckoutPath: "/Users/nckrtl/Orbit Repo/it's fine",
        gatewayDatabasePath: '/Users/nckrtl/Orbit Repo/apps/gateway/database/database.sqlite',
        image: "orbit-runtime:sha'abc",
        environment: [
            'APP_NAME' => "Orbit's runtime",
        ],
    );

    $command = (new DockerCommandBuilder)->runDetached($container);

    expect($command)->toStartWith('docker run -d ')
        ->toContain('--pull '.escapeshellarg('never'))
        ->toContain('--name '.escapeshellarg('orbit-runtime'))
        ->toContain('--restart '.escapeshellarg('unless-stopped'))
        ->toContain('--network '.escapeshellarg('orbit-network'))
        ->toContain('--network-alias '.escapeshellarg('orbit-runtime'))
        ->toContain('--env '.escapeshellarg("APP_NAME=Orbit's runtime"))
        ->toContain('--env '.escapeshellarg('ORBIT_SOURCE_PATH=/opt/orbit'))
        ->toContain('--mount '.escapeshellarg("type=bind,source=/Users/nckrtl/Orbit Repo/it's fine,target=/opt/orbit"))
        ->toContain('--mount '.escapeshellarg('type=bind,source=/Users/nckrtl/Orbit Repo/apps/gateway/database/database.sqlite,target=/opt/orbit/apps/gateway/database/database.sqlite'))
        ->toContain('--mount '.escapeshellarg('type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock'))
        ->toEndWith(' '.escapeshellarg("orbit-runtime:sha'abc"));
});

it('quotes docker mount fields containing csv separators and quotes', function (): void {
    $container = (new OrbitRuntimeContainerRenderer(new OrbitContainerNames))->render(
        orbitCheckoutPath: '/Users/nckrtl/Orbit, "Repo"',
        gatewayDatabasePath: '/Users/nckrtl/Orbit, "Repo"/apps/gateway/database/database.sqlite',
    );

    $command = (new DockerCommandBuilder)->runDetached($container);

    expect($command)
        ->toContain('--mount '.escapeshellarg('type=bind,"source=/Users/nckrtl/Orbit, ""Repo""",target=/opt/orbit'))
        ->toContain('--mount '.escapeshellarg('type=bind,"source=/Users/nckrtl/Orbit, ""Repo""/apps/gateway/database/database.sqlite",target=/opt/orbit/apps/gateway/database/database.sqlite'));
});

it('emits route-artifact mounts, port publishing, and extra hosts for orbit-caddy containers', function (): void {
    $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50');

    $command = (new DockerCommandBuilder)->runDetached($container);

    expect($command)->toStartWith('docker run -d ')
        ->toContain('--name '.escapeshellarg('orbit-caddy'))
        ->toContain('--publish '.escapeshellarg('10.6.0.50:80:80'))
        ->toContain('--publish '.escapeshellarg('10.6.0.50:443:443'))
        ->toContain('--publish '.escapeshellarg('10.6.0.50:443:443/udp'))
        ->toContain('--add-host '.escapeshellarg('host.docker.internal:host-gateway'))
        ->toContain('--mount '.escapeshellarg('type=bind,source=/etc/caddy/sites,target=/etc/caddy/sites,readonly'))
        ->toContain('--mount '.escapeshellarg('type=bind,source=/etc/orbit,target=/etc/orbit,readonly'))
        ->toContain('--mount '.escapeshellarg('type=bind,source=/home,target=/home,readonly'));
});

it('escapes docker lifecycle command arguments', function (): void {
    $builder = new DockerCommandBuilder;
    $unsafeName = "orbit runtime'; rm -rf /";

    expect($builder->containerInspect($unsafeName))
        ->toBe('docker container inspect --format '.escapeshellarg('{{json .}}').' '.escapeshellarg($unsafeName))
        ->and($builder->containerRemove($unsafeName))
        ->toBe('docker rm -f '.escapeshellarg($unsafeName))
        ->and($builder->containerStart($unsafeName))
        ->toBe('docker start '.escapeshellarg($unsafeName))
        ->and($builder->networkCreate($unsafeName))
        ->toContain(escapeshellarg($unsafeName));
});
