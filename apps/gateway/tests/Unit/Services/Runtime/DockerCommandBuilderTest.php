<?php

declare(strict_types=1);

use App\Services\Apps\AppRuntimeContainer;
use App\Services\Processes\ProcessDockerContainer;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Runtime\OrbitGatewayContainerRenderer;
use App\Services\WebSockets\WebSocketRuntimeContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainer;
use Tests\TestCase;

uses(TestCase::class);

it('builds escaped docker run commands for rendered runtime containers', function (): void {
    $container = new OrbitGatewayContainerRenderer(new OrbitContainerNames)->render(
        orbitCheckoutPath: "/Users/nckrtl/Orbit Repo/it's fine",
        gatewayConfigRoot: "/Users/nckrtl/.config/orbit/it's fine",
        image: "orbit-gateway:sha'abc",
        environment: [
            'APP_NAME' => "Orbit's runtime",
        ],
    );

    $command = new DockerCommandBuilder()->runDetached($container);

    expect($command)
        ->toStartWith('docker run -d ')
        ->toContain('--pull '.escapeshellarg('never'))
        ->toContain('--name '.escapeshellarg('orbit-gateway'))
        ->toContain('--restart '.escapeshellarg('unless-stopped'))
        ->toContain('--network '.escapeshellarg('orbit-network'))
        ->toContain('--network-alias '.escapeshellarg('orbit-gateway'))
        ->toContain('--env '.escapeshellarg("APP_NAME=Orbit's runtime"))
        ->toContain('--env '.escapeshellarg("ORBIT_CONFIG_ROOT=/Users/nckrtl/.config/orbit/it's fine"))
        ->toContain('--env '.escapeshellarg('ORBIT_SOURCE_PATH=/opt/orbit'))
        ->toContain('--mount '.escapeshellarg("type=bind,source=/Users/nckrtl/Orbit Repo/it's fine,target=/opt/orbit"))
        ->toContain(
            '--mount '
                .escapeshellarg(
                    "type=bind,source=/Users/nckrtl/.config/orbit/it's fine,target=/Users/nckrtl/.config/orbit/it's fine",
                ),
        )
        ->toContain('--mount '.escapeshellarg('type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock'))
        ->toEndWith(' '.escapeshellarg("orbit-gateway:sha'abc"));
});

it('quotes docker mount fields containing csv separators and quotes', function (): void {
    $container = new OrbitGatewayContainerRenderer(new OrbitContainerNames)->render(
        orbitCheckoutPath: '/Users/nckrtl/Orbit, "Repo"',
        gatewayConfigRoot: '/Users/nckrtl/.config/Orbit, "Root"',
    );

    $command = new DockerCommandBuilder()->runDetached($container);

    expect($command)
        ->toContain('--mount '.escapeshellarg('type=bind,"source=/Users/nckrtl/Orbit, ""Repo""",target=/opt/orbit'))
        ->toContain(
            '--mount '
                .escapeshellarg(
                    'type=bind,"source=/Users/nckrtl/.config/Orbit, ""Root""","target=/Users/nckrtl/.config/Orbit, ""Root"""',
                ),
        );
});

it('emits numeric docker users for app runtime containers', function (): void {
    $container = new AppRuntimeContainer(
        name: 'orbit-app-docs',
        image: 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
        network: 'orbit-network',
        restartPolicy: 'unless-stopped',
        appSlug: 'docs',
        runtimeUser: 'docs',
        environment: [],
        mounts: [
            [
                'source' => '/home/docs/app',
                'target' => AppRuntimeContainer::SourceTarget,
                'read_only' => false,
            ],
        ],
        networkAliases: ['orbit-app-docs'],
        phpIni: [],
    )->withDockerUser('1001:1002');

    $command = new DockerCommandBuilder()->runDetached($container);

    expect($command)
        ->toContain('--user '.escapeshellarg('1001:1002'))
        ->and($command)
        ->not->toContain('/var/run/docker.sock')->and($command)
        ->not->toContain('--group-add');
});

it('rejects non-numeric docker users for app runtime containers', function (): void {
    $container = new AppRuntimeContainer(
        name: 'orbit-app-docs',
        image: 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
        network: 'orbit-network',
        restartPolicy: 'unless-stopped',
        appSlug: 'docs',
        runtimeUser: 'docs',
        environment: [],
        mounts: [],
        networkAliases: ['orbit-app-docs'],
        phpIni: [],
        extraHosts: ['docs.test' => 'host-gateway'],
    )->withDockerUser('docs');

    expect(fn () => new DockerCommandBuilder()->runDetached($container))
        ->toThrow(InvalidArgumentException::class, 'numeric UID:GID');
});

it('emits development hostname mappings for app and workspace runtime containers', function (): void {
    $app = new AppRuntimeContainer(
        name: 'orbit-app-docs',
        image: 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
        network: 'orbit-network',
        restartPolicy: 'unless-stopped',
        appSlug: 'docs',
        runtimeUser: null,
        environment: [],
        mounts: [],
        networkAliases: ['orbit-app-docs'],
        phpIni: [],
        extraHosts: ['docs.test' => 'host-gateway'],
    );
    $workspace = new WorkspaceRuntimeContainer(
        name: 'orbit-ws-docs-feature',
        image: 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
        network: 'orbit-network',
        restartPolicy: 'unless-stopped',
        appSlug: 'docs',
        workspaceSlug: 'feature',
        environment: [],
        mounts: [],
        networkAliases: ['orbit-ws-docs-feature'],
        phpIni: [],
        extraHosts: ['feature.docs.test' => 'host-gateway'],
    );
    $builder = new DockerCommandBuilder;

    expect($builder->runDetached($app))
        ->toContain('--add-host '.escapeshellarg('docs.test:host-gateway'))
        ->and($builder->runDetached($workspace))
        ->toContain('--add-host '.escapeshellarg('feature.docs.test:host-gateway'));
});

it('emits route-artifact mounts, port publishing, and extra hosts for orbit-caddy containers', function (): void {
    $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50');

    $command = new DockerCommandBuilder()->runDetached($container);

    expect($command)
        ->toStartWith('docker run -d ')
        ->toContain('--name '.escapeshellarg('orbit-caddy'))
        ->toContain('--publish '.escapeshellarg('10.6.0.50:80:80'))
        ->toContain('--publish '.escapeshellarg('10.6.0.50:443:443'))
        ->toContain('--publish '.escapeshellarg('10.6.0.50:443:443/udp'))
        ->toContain('--add-host '.escapeshellarg('host.docker.internal:host-gateway'))
        ->toContain('--mount '.escapeshellarg('type=bind,source=/etc/caddy/sites,target=/etc/caddy/sites,readonly'))
        ->toContain('--mount '.escapeshellarg('type=bind,source=/etc/orbit,target=/etc/orbit,readonly'))
        ->toContain('--mount '.escapeshellarg('type=bind,source=/home,target=/home,readonly'));
});

it('uses the managed target node namespace for Docker E2E Caddy and websocket containers', function (): void {
    $previousNetwork = getenv('ORBIT_E2E_DOCKER_NETWORK');
    $previousNodeContainer = getenv('ORBIT_NODE_CONTAINER');

    putenv('ORBIT_E2E_DOCKER_NETWORK=orbit-e2e-run123');
    putenv('ORBIT_NODE_CONTAINER=orbit-e2e-run123-gateway');

    try {
        $caddy = OrbitCaddyContainer::forPublicIngress(
            '10.6.0.5',
            OrbitContainerNames::forNodeScope('orbit-e2e-run123-prod'),
        );
        $websocket = new WebSocketRuntimeContainer(
            name: 'orbit-e2e-run123-dev-orbit-websocket-app-dev-1',
            image: 'orbit-gateway:current',
            network: 'orbit-e2e-run123',
            restartPolicy: 'unless-stopped',
            backendName: '10.6.0.4',
            valkeyNodeId: 1,
            workingDirectory: '/app',
            command: 'php artisan reverb:start --host=10.6.0.4 --port=8080 --hostname=10.6.0.4',
            environment: [],
            mounts: [],
            networkAliases: [],
        );

        $builder = new DockerCommandBuilder;

        expect($builder->runDetached($caddy))
            ->toContain('--network '.escapeshellarg('container:orbit-e2e-run123-prod'))
            ->not->toContain('container:orbit-e2e-run123-gateway')
            ->not->toContain('--network-alias')->and($builder->runDetached($websocket))->toContain(
                '--network '.escapeshellarg('container:orbit-e2e-run123-dev'),
            )
            ->not->toContain('container:orbit-e2e-run123-gateway')
            ->not->toContain('--network-alias');
    } finally {
        if ($previousNetwork === false) {
            putenv('ORBIT_E2E_DOCKER_NETWORK');
        } else {
            putenv("ORBIT_E2E_DOCKER_NETWORK={$previousNetwork}");
        }

        if ($previousNodeContainer === false) {
            putenv('ORBIT_NODE_CONTAINER');
        } else {
            putenv("ORBIT_NODE_CONTAINER={$previousNodeContainer}");
        }
    }
});

it('keeps app and workspace runtime containers on the Docker E2E network with aliases', function (): void {
    $previousNetwork = getenv('ORBIT_E2E_DOCKER_NETWORK');
    $previousNodeContainer = getenv('ORBIT_NODE_CONTAINER');

    putenv('ORBIT_E2E_DOCKER_NETWORK=orbit-e2e-run123');
    putenv('ORBIT_NODE_CONTAINER=orbit-e2e-run123-gateway');

    try {
        $app = new AppRuntimeContainer(
            name: 'orbit-app-docs',
            image: 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
            network: 'orbit-e2e-run123',
            restartPolicy: 'unless-stopped',
            appSlug: 'docs',
            runtimeUser: 'docs',
            environment: [],
            mounts: [],
            networkAliases: ['orbit-app-docs', 'app-docs'],
            phpIni: [],
            extraHosts: ['docs.test' => 'host-gateway'],
        );
        $workspace = new WorkspaceRuntimeContainer(
            name: 'orbit-ws-docs-feature',
            image: 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
            network: 'orbit-e2e-run123',
            restartPolicy: 'unless-stopped',
            appSlug: 'docs',
            workspaceSlug: 'feature',
            environment: [],
            mounts: [],
            networkAliases: ['orbit-ws-docs-feature', 'ws-docs-feature'],
            phpIni: [],
            extraHosts: ['feature.docs.test' => 'host-gateway'],
        );

        $builder = new DockerCommandBuilder;

        expect($builder->runDetached($app))
            ->toContain('--network '.escapeshellarg('orbit-e2e-run123'))
            ->not->toContain('--network '.escapeshellarg('container:orbit-e2e-run123-gateway'))->toContain(
                '--network-alias '.escapeshellarg('orbit-app-docs'),
            )->toContain('--network-alias '.escapeshellarg('app-docs'))
            ->not->toContain('--add-host');

        expect($builder->runDetached($workspace))
            ->toContain('--network '.escapeshellarg('orbit-e2e-run123'))
            ->not->toContain('--network '.escapeshellarg('container:orbit-e2e-run123-gateway'))->toContain(
                '--network-alias '.escapeshellarg('orbit-ws-docs-feature'),
            )->toContain('--network-alias '.escapeshellarg('ws-docs-feature'))
            ->not->toContain('--add-host');
    } finally {
        if ($previousNetwork === false) {
            putenv('ORBIT_E2E_DOCKER_NETWORK');
        } else {
            putenv("ORBIT_E2E_DOCKER_NETWORK={$previousNetwork}");
        }

        if ($previousNodeContainer === false) {
            putenv('ORBIT_NODE_CONTAINER');
        } else {
            putenv("ORBIT_NODE_CONTAINER={$previousNodeContainer}");
        }
    }
});

it('uses the managed target node namespace without aliases for Docker E2E process containers', function (): void {
    $previousNetwork = getenv('ORBIT_E2E_DOCKER_NETWORK');
    $previousNodeContainer = getenv('ORBIT_NODE_CONTAINER');

    putenv('ORBIT_E2E_DOCKER_NETWORK=orbit-e2e-run123');
    putenv('ORBIT_NODE_CONTAINER=orbit-e2e-run123-dev');

    try {
        $process = new ProcessDockerContainer(
            name: 'orbit_docs_main_queue',
            image: 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
            network: 'orbit-network',
            restartPolicy: 'no',
            appSlug: 'docs',
            workspaceSlug: null,
            processSlug: 'queue',
            workingDirectory: '/app',
            command: 'php artisan queue:work',
            environment: [],
            mounts: [],
            networkAliases: ['orbit_docs_main_queue'],
        );

        expect(new DockerCommandBuilder()->createIdle($process))
            ->toContain('--network '.escapeshellarg('container:orbit-e2e-run123-dev'))
            ->not->toContain('--network-alias');
    } finally {
        if ($previousNetwork === false) {
            putenv('ORBIT_E2E_DOCKER_NETWORK');
        } else {
            putenv("ORBIT_E2E_DOCKER_NETWORK={$previousNetwork}");
        }

        if ($previousNodeContainer === false) {
            putenv('ORBIT_NODE_CONTAINER');
        } else {
            putenv("ORBIT_NODE_CONTAINER={$previousNodeContainer}");
        }
    }
});

it('publishes process service ports without binding the Docker publish to a WireGuard address', function (): void {
    $process = new ProcessDockerContainer(
        name: 'mailpit',
        image: 'axllent/mailpit:latest',
        network: 'orbit-network',
        restartPolicy: 'unless-stopped',
        appSlug: 'beast',
        workspaceSlug: null,
        processSlug: 'mailpit',
        workingDirectory: '/',
        command: '/mailpit',
        environment: [],
        mounts: [],
        networkAliases: ['mailpit'],
        ports: [
            ['published' => 1025, 'target' => 1025, 'protocol' => 'tcp'],
        ],
    );

    $command = new DockerCommandBuilder()->createIdle($process);

    expect($command)
        ->toContain("--publish '1025:1025'")
        ->and($command)
        ->not->toContain('10.6.0.7')->and($command)
        ->not->toContain('8025:8025');
});

it('does not publish ports for app owned docker process containers', function (): void {
    $process = new ProcessDockerContainer(
        name: 'orbit_docs_main_queue',
        image: 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
        network: 'orbit-network',
        restartPolicy: 'no',
        appSlug: 'docs',
        workspaceSlug: null,
        processSlug: 'queue',
        workingDirectory: '/app',
        command: 'php artisan queue:work',
        environment: [],
        mounts: [],
        networkAliases: ['orbit_docs_main_queue'],
    );

    expect(new DockerCommandBuilder()->createIdle($process))->not->toContain('--publish');
});

it('escapes docker lifecycle command arguments', function (): void {
    $builder = new DockerCommandBuilder;
    $unsafeName = "orbit gateway'; rm -rf /";

    expect($builder->containerInspect($unsafeName))
        ->toBe('docker container inspect --format '.escapeshellarg('{{json .}}').' '.escapeshellarg($unsafeName))
        ->and($builder->containerRemove($unsafeName))
        ->toBe('docker rm -f '.escapeshellarg($unsafeName))
        ->and($builder->containerStart($unsafeName))
        ->toBe('docker start '.escapeshellarg($unsafeName))
        ->and($builder->networkCreate($unsafeName))
        ->toContain(escapeshellarg($unsafeName));
});
