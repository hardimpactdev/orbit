<?php

declare(strict_types=1);

use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Runtime\OrbitRuntimeContainer;
use App\Services\Runtime\OrbitRuntimeContainerManager;
use App\Services\Runtime\OrbitRuntimeContainerRenderer;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

function runtimeContainerForManagerTest(): OrbitRuntimeContainer
{
    return (new OrbitRuntimeContainerRenderer(new OrbitContainerNames))->render(
        orbitCheckoutPath: '/home/orbit/orbit',
        gatewayDatabasePath: '/home/orbit/orbit/apps/gateway/database/database.sqlite',
    );
}

function runtimeContainerInspectPayload(OrbitRuntimeContainer $container, bool $running = true, ?string $specHash = null): string
{
    return json_encode([
        'State' => [
            'Running' => $running,
        ],
        'Config' => [
            'Labels' => [
                OrbitRuntimeContainer::SpecHashLabel => $specHash ?? $container->specHash(),
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

it('creates the runtime network and container when they are absent', function (): void {
    $container = runtimeContainerForManagerTest();
    $builder = new DockerCommandBuilder;

    Process::fake([
        $builder->networkInspect($container->network()) => Process::result(exitCode: 1),
        $builder->networkCreate($container->network()) => Process::result(),
        $builder->containerInspect($container->name()) => Process::result(exitCode: 1),
        $builder->runDetached($container) => Process::result(),
    ]);

    (new OrbitRuntimeContainerManager($builder))->apply($container);

    Process::assertRan($builder->networkCreate($container->network()));
    Process::assertRan($builder->runDetached($container));
});

it('does not recreate a running container that already matches the rendered spec', function (): void {
    $container = runtimeContainerForManagerTest();
    $builder = new DockerCommandBuilder;

    Process::fake([
        $builder->networkInspect($container->network()) => Process::result(),
        $builder->containerInspect($container->name()) => Process::result(
            output: runtimeContainerInspectPayload($container),
        ),
    ]);

    (new OrbitRuntimeContainerManager($builder))->apply($container);

    Process::assertNotRan($builder->networkCreate($container->network()));
    Process::assertNotRan($builder->containerRemove($container->name()));
    Process::assertNotRan($builder->runDetached($container));
    Process::assertNotRan($builder->containerStart($container->name()));
});

it('starts an existing matching container when it is stopped', function (): void {
    $container = runtimeContainerForManagerTest();
    $builder = new DockerCommandBuilder;

    Process::fake([
        $builder->networkInspect($container->network()) => Process::result(),
        $builder->containerInspect($container->name()) => Process::result(
            output: runtimeContainerInspectPayload($container, running: false),
        ),
        $builder->containerStart($container->name()) => Process::result(),
    ]);

    (new OrbitRuntimeContainerManager($builder))->apply($container);

    Process::assertRan($builder->containerStart($container->name()));
    Process::assertNotRan($builder->containerRemove($container->name()));
    Process::assertNotRan($builder->runDetached($container));
});

it('recreates an existing container when the rendered spec drifts', function (): void {
    $container = runtimeContainerForManagerTest();
    $builder = new DockerCommandBuilder;

    Process::fake([
        $builder->networkInspect($container->network()) => Process::result(),
        $builder->containerInspect($container->name()) => Process::result(
            output: runtimeContainerInspectPayload($container, specHash: 'old-spec'),
        ),
        $builder->containerRemove($container->name()) => Process::result(),
        $builder->runDetached($container) => Process::result(),
    ]);

    (new OrbitRuntimeContainerManager($builder))->apply($container);

    Process::assertRan($builder->containerRemove($container->name()));
    Process::assertRan($builder->runDetached($container));
});
