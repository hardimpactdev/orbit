<?php

declare(strict_types=1);

use App\Actions\Instances\DeployInstanceAction;
use App\Actions\Instances\InstanceDeploymentConfigResolver;
use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Instances\RollbackInstanceAction;
use App\Domain\Instances\Deployment\DeploymentCancellation;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentFailureBoundary;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentReleaseState;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Instances\Deployment\InlineDeploymentSteps;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentResult;
use App\Domain\Instances\Environment\InstanceEnvironmentSynchronizer;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

it('restarts only active opted-in Instance Processes on the selected release after PHP refresh', function (bool $php, bool $rollback): void {
    $instance = orb219_deployment_instance([
        ['name' => 'finish', 'phase' => 'after_activation', 'command' => 'finish', 'timeout_seconds' => 30],
    ], php: $php);
    $trace = new Orb219DeploymentTrace;
    $attributes = [
        'owner_type' => (new Instance)->getMorphClass(), 'owner_id' => $instance->id, 'name' => 'horizon',
        'runtime' => 'systemd', 'working_directory' => '/home/orbit-app-1/current',
        'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'horizon']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ];
    $first = Process::query()->create($attributes);
    $second = Process::query()->create([...$attributes, 'name' => 'queue']);
    foreach ([
        ['name' => 'opt-out', 'restart_on_deploy' => false],
        ['name' => 'stopped', 'desired_state' => 'stopped'],
        ['name' => 'failed', 'status' => 'failed'],
        ['name' => 'provisioning', 'status' => 'provisioning'],
        ['name' => 'other-instance', 'owner_id' => $instance->id + 1],
        ['name' => 'node-worker', 'owner_type' => Node::class],
    ] as $override) {
        Process::query()->create([...$attributes, ...$override]);
    }
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldReceive('restart')->twice()->andReturnUsing(function (Process $fresh) use ($instance, $trace): void {
        $trace->entries[] = 'restart:'.$fresh->id.':'.basename($instance->refresh()->checkout_path);
    });
    $runtime->shouldReceive('status')->twice()->andReturn('running');
    app()->instance(ProcessRuntimeManager::class, $runtime);
    [$deploy, $rollbackAction] = orb219_actions($trace);
    $release = $rollback ? 'retained' : 'fresh';
    $events = [];

    $request = new DeploymentRequest(
        output: function (DeploymentEvent $event) use (&$events): void {
            $events[] = $event;
        },
    );
    $result = $rollback ? $rollbackAction->execute($instance, $release, $request) : $deploy->execute($instance, new InlineDeploymentSteps, $request);

    expect($result->succeeded)->toBeTrue()
        ->and($trace->entries)->toBe([
            'lock:1', 'selected:initial',
            ...($rollback ? ['retained:retained'] : ['prepare:main', 'environment']),
            'activate:'.$release,
            ...($php ? ['cache:'.$release] : []),
            'restart:'.$first->id.':'.$release, 'restart:'.$second->id.':'.$release,
            ...($rollback ? [] : ['step:finish:finish', 'prune:fresh:initial']),
        ])
        ->and(array_values(array_map(fn (DeploymentEvent $event): string => $event->value,
            array_filter($events, fn (DeploymentEvent $event): bool => $event->step === 'process_restart'))))
        ->toBe(["Restarted Process {$first->id}.\n", "Restarted Process {$second->id}.\n"]);
})->with(['deploy PHP' => [true, false], 'deploy non-PHP' => [false, false], 'rollback PHP' => [true, true], 'rollback non-PHP' => [false, true]]);

it('fails after activation and reports a Process restart failure without running later steps', function (bool $rollback): void {
    $instance = orb219_deployment_instance([
        ['name' => 'finish', 'phase' => 'after_activation', 'command' => 'finish', 'timeout_seconds' => 30],
    ]);
    $process = Process::query()->create([
        'owner_type' => (new Instance)->getMorphClass(), 'owner_id' => $instance->id, 'name' => 'horizon',
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'horizon']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ]);
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldReceive('restart')->once()->andThrow(new ProcessOperationException('restart', 'process.restart_failed', 'secret runtime output'));
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $trace = new Orb219DeploymentTrace;
    [$deploy, $rollbackAction] = orb219_actions($trace);
    $release = $rollback ? 'retained' : 'fresh';
    $events = [];

    $request = new DeploymentRequest(
        output: function (DeploymentEvent $event) use (&$events): void {
            $events[] = $event;
        },
    );
    $result = $rollback ? $rollbackAction->execute($instance, $release, $request) : $deploy->execute($instance, new InlineDeploymentSteps, $request);

    expect($result->succeeded)->toBeFalse()
        ->and($result->failure?->boundary)->toBe(DeploymentFailureBoundary::AfterActivation)
        ->and($result->failure?->errorCode)->toBe('process.restart_failed')
        ->and($result->selectedRelease?->name)->toBe($release)
        ->and($trace->entries)->not->toContain('step:finish:finish', 'prune:fresh:initial')
        ->and($process->refresh()->status)->toBe(LifecycleStatus::Failed)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->value)->toBe("Failed to restart Process {$process->id}: process.restart_failed.\n")
        ->and($events[0]->stream)->toBe(DeploymentOutputStream::Stderr)
        ->and($events[0]->important)->toBeTrue();
})->with([false, true]);

it('honours rollback cancellation only before activation', function (string $cancelAt, bool $finishes): void {
    $instance = orb219_deployment_instance([], php: true);
    $process = Process::query()->create([
        'owner_type' => $instance->getMorphClass(), 'owner_id' => $instance->id, 'name' => 'horizon',
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'horizon']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ]);
    $trace = new Orb219DeploymentTrace;
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    if ($finishes) {
        $runtime->shouldReceive('restart')->once()->andReturnUsing(function (Process $fresh) use ($trace): void {
            $trace->entries[] = 'restart:'.$fresh->id;
        });
        $runtime->shouldReceive('status')->once()->andReturn('running');
    } else {
        $runtime->shouldNotReceive('restart');
    }
    app()->instance(ProcessRuntimeManager::class, $runtime);
    [, $rollback] = orb219_actions($trace);
    $cancellation = new DeploymentCancellation(fn (): bool => in_array($cancelAt, $trace->entries, true));

    $result = $rollback->execute($instance, 'retained', new DeploymentRequest(cancellation: $cancellation));

    expect($cancellation->requested())->toBeTrue()
        ->and($result->succeeded)->toBe($finishes)
        ->and($result->failure?->errorCode)->toBe($finishes ? null : 'deployment.cancelled')
        ->and($result->selectedRelease?->name)->toBe($finishes ? 'retained' : 'initial')
        ->and($trace->entries)->toBe([
            'lock:1', 'selected:initial', 'retained:retained',
            ...($finishes ? ['activate:retained', 'cache:retained', 'restart:'.$process->id] : ['selected:initial']),
        ])
        ->and($trace->runtimeEvents)->toBe($finishes ? ['converge:retained', 'cache:retained'] : []);
})->with([
    'before activation' => ['retained:retained', false],
    'after activation' => ['activate:retained', true],
    'after PHP refresh' => ['cache:retained', true],
]);

it('does not restart a Process stopped before the deployment acquires its runtime lease', function (): void {
    $instance = orb219_deployment_instance([]);
    $process = Process::query()->create([
        'owner_type' => $instance->getMorphClass(), 'owner_id' => $instance->id, 'name' => 'worker',
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/sleep', '60']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ]);
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldNotReceive('restart');
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $lease = Mockery::mock(ProcessRuntimeLease::class);
    $lease->shouldReceive('run')->once()->andReturnUsing(function (Process $fresh, Closure $work): mixed {
        $fresh->update(['desired_state' => DesiredProcessState::Stopped]);

        return $work($fresh->refresh());
    });
    app()->instance(ProcessRuntimeLease::class, $lease);
    [$deploy] = orb219_actions(new Orb219DeploymentTrace);
    $events = [];

    $result = $deploy->execute($instance, new InlineDeploymentSteps, new DeploymentRequest(
        output: function (DeploymentEvent $event) use (&$events): void {
            $events[] = $event;
        },
    ));

    expect($result->succeeded)->toBeTrue()
        ->and($process->refresh()->desired_state)->toBe(DesiredProcessState::Stopped)
        ->and(array_filter($events, fn (DeploymentEvent $event): bool => $event->step === 'process_restart'))->toBe([]);
});

it('captures one configuration and preserves the complete deployment order', function (): void {
    $instance = orb219_deployment_instance([
        ['name' => 'prepare-one', 'phase' => 'before_activation', 'command' => 'old-one', 'timeout_seconds' => 30],
        ['name' => 'prepare-two', 'phase' => 'before_activation', 'command' => 'old-two', 'timeout_seconds' => 30],
        ['name' => 'finish', 'phase' => 'after_activation', 'command' => 'old-three', 'timeout_seconds' => 30],
    ], php: true);
    $instance->update(['deployment_branch' => 'release']);
    $trace = new Orb219DeploymentTrace;
    $environment = new Orb219EnvironmentSynchronizer($trace, function () use ($instance): void {
        $instance->update(['deployment_branch' => 'next-release']);
        store_deploy_steps($instance, [
            ['name' => 'replacement', 'phase' => 'before_activation', 'command' => 'new', 'timeout_seconds' => 30],
        ]);
    });
    [$deploy] = orb219_actions($trace, $environment);

    $result = $deploy->execute($instance, new InlineDeploymentSteps);

    expect($result->succeeded)
        ->toBeTrue()
        ->and($trace->entries)
        ->toBe([
            'lock:1',
            'selected:initial',
            'prepare:release',
            'environment',
            'step:prepare-one:old-one',
            'step:prepare-two:old-two',
            'activate:fresh',
            'cache:fresh',
            'step:finish:old-three',
            'prune:fresh:initial',
        ])
        ->and($result->release?->name)
        ->toBe('fresh')
        ->and($result->selectedRelease?->name)
        ->toBe('fresh')
        ->and($result->commands)
        ->toHaveCount(3)
        ->and($instance->refresh()->checkout_path)
        ->toBe('/home/orbit-app-1/releases/fresh')
        ->and(print_r($result, return: true))
        ->not->toContain('prepare-one-stdout', 'prepare-one-stderr')
        ->toContain('[OUTPUT]');
});

it('reconciles PHP after deployment and rollback select the release and before cache refresh', function (string $root): void {
    $instance = orb219_deployment_instance([], php: true);
    $instance->update(['root' => $root]);
    $trace = new Orb219DeploymentTrace(firstDeployment: true);
    [$deploy, $rollback] = orb219_actions($trace);

    expect($deploy->execute($instance, new InlineDeploymentSteps)->succeeded)->toBeTrue();
    expect($rollback->execute($instance, 'retained')->succeeded)->toBeTrue();
    expect($trace->runtimeEvents)->toBe(['converge:fresh', 'cache:fresh', 'converge:retained', 'cache:retained']);
})->with(['root public' => 'public', 'nested Laravel' => 'server/web/public']);

it('reports a failed PHP reconciliation without refreshing the cache after selection', function (bool $rollback, string $release): void {
    $instance = orb219_deployment_instance([], php: true);
    $trace = new Orb219DeploymentTrace(failAt: 'converge:'.$release);
    [$deploy, $rollbackAction] = orb219_actions($trace);

    $result = $rollback ? $rollbackAction->execute($instance, 'retained') : $deploy->execute($instance, new InlineDeploymentSteps);

    expect($result->succeeded)->toBeFalse()
        ->and($result->failure?->boundary)->toBe(DeploymentFailureBoundary::CacheRefresh)
        ->and($result->selectedRelease?->name)->toBe($release)
        ->and($trace->runtimeEvents)->toBe(['converge:'.$release]);
})->with(['deployment' => [false, 'fresh'], 'rollback' => [true, 'retained']]);

it('installs captured runtime definitions only after deployment selects the release', function (): void {
    $instance = orb219_deployment_instance([]);
    $definition = $instance->project->processDefinitions()->create([
        'name' => 'queue',
        'environments' => ['production'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/php', 'artisan', 'queue:work']],
    ]);
    $instance->update(['runtime_definitions_captured_at' => now()]);
    $process = Process::query()->create([
        'owner_type' => Instance::MorphAlias,
        'owner_id' => $instance->id,
        'source_definition_id' => $definition->id,
        'name' => 'queue',
        'runtime' => 'systemd',
        'working_directory' => $instance->production_home.'/current',
        'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'queue:work']],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Stopped,
        'status' => LifecycleStatus::Provisioning,
    ]);
    $processRuntime = Mockery::mock(ProcessRuntimeManager::class);
    $processRuntime->shouldReceive('converge')
        ->once()
        ->with(Mockery::on(function (Process $runtimeProcess) use ($instance): bool {
            return $runtimeProcess->id === Process::query()->where('owner_id', $instance->id)->value('id')
                && $instance->refresh()->checkout_path === '/home/orbit-app-1/releases/fresh';
        }));
    $scheduleRuntime = Mockery::mock(ScheduleRuntimeManager::class);
    $scheduleRuntime->shouldNotReceive('install');
    $definitions = new InstantiateProjectRuntimeDefinitionsAction(
        app(ProcessAdmissionLock::class),
        new ProcessTargetResolver,
        new ProcessSpecification,
        $processRuntime,
        $scheduleRuntime,
    );
    [$deploy] = orb219_actions(new Orb219DeploymentTrace, definitions: $definitions);

    $result = $deploy->execute($instance, new InlineDeploymentSteps);

    expect($result->succeeded)->toBeTrue()
        ->and($process->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and($process->desired_state)->toBe(DesiredProcessState::Stopped);
});

it('runs no application command or cache refresh for an empty non-PHP deployment', function (): void {
    $instance = orb219_deployment_instance([]);
    $trace = new Orb219DeploymentTrace;
    [$deploy] = orb219_actions($trace);

    $result = $deploy->execute($instance, new InlineDeploymentSteps);

    expect($result->succeeded)
        ->toBeTrue()
        ->and($trace->entries)
        ->toBe([
            'lock:1',
            'selected:initial',
            'prepare:main',
            'environment',
            'activate:fresh',
            'prune:fresh:initial',
        ])
        ->and($result->commands)
        ->toBe([]);
});

it('reports removed and in-use releases and a failed cleanup without failing a deployment', function (bool $fails): void {
    $instance = orb219_deployment_instance([]);
    $trace = new Orb219DeploymentTrace(failAt: $fails ? 'prune:fresh:initial' : null);
    [$deploy] = orb219_actions($trace);
    $events = [];

    $result = $deploy->execute($instance, new InlineDeploymentSteps, new DeploymentRequest(static function (DeploymentEvent $event) use (&$events): void {
        $events[] = $event;
    }));

    expect($result->succeeded)->toBeTrue()
        ->and($result->selectedRelease?->name)->toBe('fresh')
        ->and(array_map(static fn (DeploymentEvent $event): array => [$event->step, $event->stream, $event->value], $events))
        ->toBe($fails
            ? [['cleanup', DeploymentOutputStream::Stderr, "Release cleanup failed; a later deployment will retry cleanup.\n"]]
            : [
                ['cleanup', DeploymentOutputStream::Stdout, "Removed old release old.\n"],
                ['cleanup', DeploymentOutputStream::Stderr, "Kept old release busy: a running process still uses it. Stop or restart its remaining processes to free it.\n"],
            ]);
})->with(['removed' => false, 'failed' => true]);

it('keeps the observed old selection when deployment fails before activation', function (): void {
    $instance = orb219_deployment_instance([
        ['name' => 'fail', 'phase' => 'before_activation', 'command' => 'false', 'timeout_seconds' => 30],
    ]);
    $trace = new Orb219DeploymentTrace(failAt: 'step:fail');
    [$deploy] = orb219_actions($trace);

    $result = $deploy->execute($instance, new InlineDeploymentSteps);

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->boundary)
        ->toBe(DeploymentFailureBoundary::BeforeActivation)
        ->and($result->failure?->errorCode)
        ->toBe('deployment.step_failed')
        ->and($result->selectedRelease?->name)
        ->toBe('initial')
        ->and($instance->refresh()->checkout_path)
        ->toBe('/home/orbit-app-1/releases/initial')
        ->and($trace->entries)
        ->not->toContain('activate:fresh');
});

it('keeps the new selection and persistent effects when deployment fails after activation', function (): void {
    $instance = orb219_deployment_instance([
        ['name' => 'persist', 'phase' => 'before_activation', 'command' => 'touch marker', 'timeout_seconds' => 30],
        ['name' => 'fail', 'phase' => 'after_activation', 'command' => 'false', 'timeout_seconds' => 30],
        ['name' => 'later', 'phase' => 'after_activation', 'command' => 'must-not-run', 'timeout_seconds' => 30],
    ]);
    $trace = new Orb219DeploymentTrace(failAt: 'step:fail');
    [$deploy] = orb219_actions($trace);

    $result = $deploy->execute($instance, new InlineDeploymentSteps);

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->boundary)
        ->toBe(DeploymentFailureBoundary::AfterActivation)
        ->and($result->selectedRelease?->name)
        ->toBe('fresh')
        ->and($instance->refresh()->checkout_path)
        ->toBe('/home/orbit-app-1/releases/fresh')
        ->and($trace->entries)
        ->toContain('step:persist:touch marker', 'activate:fresh', 'step:fail:false')
        ->not->toContain('step:later:must-not-run');
});

it('reports the observed selection when deployment activation switches before failing', function (): void {
    $instance = orb219_deployment_instance([]);
    $trace = new Orb219DeploymentTrace(
        failAt: 'activate:fresh',
        switchBeforeActivationFailure: true,
    );
    [$deploy] = orb219_actions($trace);

    $result = $deploy->execute($instance, new InlineDeploymentSteps);

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->boundary)
        ->toBe(DeploymentFailureBoundary::Activation)
        ->and($result->failure?->errorCode)
        ->toBe('deployment.step_failed')
        ->and($result->selectedRelease?->name)
        ->toBe('fresh')
        ->and($instance->refresh()->checkout_path)
        ->toBe('/home/orbit-app-1/releases/fresh')
        ->and($trace->entries)
        ->toBe([
            'lock:1',
            'selected:initial',
            'prepare:main',
            'environment',
            'activate:fresh',
            'selected:fresh',
        ]);
});

it('reports the observed selection when rollback activation switches before failing', function (): void {
    $instance = orb219_deployment_instance([]);
    $trace = new Orb219DeploymentTrace(
        failAt: 'activate:retained',
        switchBeforeActivationFailure: true,
    );
    [, $rollback] = orb219_actions($trace);

    $result = $rollback->execute($instance, 'retained');

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->boundary)
        ->toBe(DeploymentFailureBoundary::Activation)
        ->and($result->failure?->errorCode)
        ->toBe('deployment.step_failed')
        ->and($result->selectedRelease?->name)
        ->toBe('retained')
        ->and($instance->refresh()->checkout_path)
        ->toBe('/home/orbit-app-1/releases/retained')
        ->and($trace->entries)
        ->toBe([
            'lock:1',
            'selected:initial',
            'retained:retained',
            'activate:retained',
            'selected:retained',
        ]);
});

it('preserves the activation failure and last known selection when reinspection fails', function (): void {
    $instance = orb219_deployment_instance([]);
    $trace = new Orb219DeploymentTrace(
        failAt: 'activate:fresh',
        switchBeforeActivationFailure: true,
        failActivationReinspection: true,
    );
    [$deploy] = orb219_actions($trace);

    $result = $deploy->execute($instance, new InlineDeploymentSteps);

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->boundary)
        ->toBe(DeploymentFailureBoundary::Activation)
        ->and($result->failure?->errorCode)
        ->toBe('deployment.step_failed')
        ->and($result->selectedRelease?->name)
        ->toBe('initial')
        ->and($instance->refresh()->checkout_path)
        ->toBe('/home/orbit-app-1/releases/initial')
        ->and($trace->entries)
        ->toBe([
            'lock:1',
            'selected:initial',
            'prepare:main',
            'environment',
            'activate:fresh',
            'selected:failed',
        ]);
});

it('reports each infrastructure failure boundary with its observed selection', function (
    string $failure,
    DeploymentFailureBoundary $boundary,
    string $selected,
): void {
    $instance = orb219_deployment_instance([], php: str_starts_with($failure, 'cache:'));
    $trace = new Orb219DeploymentTrace(failAt: $failure);
    [$deploy] = orb219_actions($trace);

    $result = $deploy->execute($instance, new InlineDeploymentSteps);

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->boundary)
        ->toBe($boundary)
        ->and($result->selectedRelease?->name)
        ->toBe($selected);
})->with([
    'release preparation' => ['prepare:main', DeploymentFailureBoundary::Preparation, 'initial'],
    'environment synchronization' => ['environment', DeploymentFailureBoundary::Environment, 'initial'],
    'atomic activation' => ['activate:fresh', DeploymentFailureBoundary::Activation, 'initial'],
    'PHP cache refresh' => ['cache:fresh', DeploymentFailureBoundary::CacheRefresh, 'fresh'],
]);

it('reports a deployment or rollback that ran out of time as deployment.deadline_exceeded', function (bool $rollback, string $failAt): void {
    $instance = orb219_deployment_instance([]);
    [$deploy, $rollbackAction] = orb219_actions(new Orb219DeploymentTrace(failAt: $failAt));

    $result = $rollback ? $rollbackAction->execute($instance, 'retained') : $deploy->execute($instance, new InlineDeploymentSteps);

    expect($result->succeeded)->toBeFalse()
        ->and($result->failure?->errorCode)->toBe('deployment.deadline_exceeded');
})->with([
    'deployment' => [false, 'deadline:prepare:main'],
    'rollback' => [true, 'deadline:retained:retained'],
]);

it('selects retained code without fetching, synchronizing, running steps, or changing data', function (): void {
    $instance = orb219_deployment_instance([
        ['name' => 'ignored', 'phase' => 'before_activation', 'command' => 'ignored', 'timeout_seconds' => 30],
    ], php: true);
    $trace = new Orb219DeploymentTrace;
    [, $rollback] = orb219_actions($trace);
    $instance->environmentValues()->create(['env_key' => 'DATABASE_URL', 'env_value' => 'kept']);

    $result = $rollback->execute($instance, 'retained');

    expect($result->succeeded)
        ->toBeTrue()
        ->and($result->release?->name)
        ->toBe('retained')
        ->and($trace->entries)
        ->toBe([
            'lock:1',
            'selected:initial',
            'retained:retained',
            'activate:retained',
            'cache:retained',
        ])
        ->and($instance->refresh()->checkout_path)
        ->toBe('/home/orbit-app-1/releases/retained')
        ->and(InstanceEnvironmentValue::query()->sole()->env_value)
        ->toBe('kept');
});

it('refuses an unsafe rollback name before any remote mutation', function (): void {
    $instance = orb219_deployment_instance([]);
    $trace = new Orb219DeploymentTrace;
    [, $rollback] = orb219_actions($trace);

    $result = $rollback->execute($instance, '../foreign');

    expect($result->succeeded)
        ->toBeFalse()
        ->and($result->failure?->boundary)
        ->toBe(DeploymentFailureBoundary::RollbackSelection)
        ->and($result->failure?->errorCode)
        ->toBe('rollback.release_invalid')
        ->and($trace->entries)
        ->toBe(['lock:1', 'selected:initial']);
});

it('uses the same action for a first deployment and adds no deployment history store', function (): void {
    $instance = orb219_deployment_instance([]);
    $trace = new Orb219DeploymentTrace(firstDeployment: true);
    [$deploy] = orb219_actions($trace);

    $result = $deploy->execute($instance, new InlineDeploymentSteps);
    $tables = collect(DB::select("select name from sqlite_master where type = 'table'"))->pluck('name');

    expect($result->succeeded)
        ->toBeTrue()
        ->and($result->selectedRelease?->name)
        ->toBe('fresh')
        ->and($trace->entries)
        ->toContain('selected:none', 'activate:fresh')
        ->and($tables->contains(
            static fn (string $name): bool => str_contains($name, 'deployment_run')
                || str_contains($name, 'deployment_output')
                || str_contains($name, 'deployment_snapshot'),
        ))
        ->toBeFalse();
});

/** @return array{DeployInstanceAction, RollbackInstanceAction} */
function orb219_actions(
    Orb219DeploymentTrace $trace,
    ?InstanceEnvironmentSynchronizer $environment = null,
    ?InstantiateProjectRuntimeDefinitionsAction $definitions = null,
): array {
    $lock = new Orb219DeploymentLock($trace);
    $remote = new Orb219ProductionDeployment($trace);
    $runtime = new Orb219PhpRuntime($trace);
    $deadline = new CommandDeadline;
    $resolver = app(InstanceDeploymentConfigResolver::class);
    $environment ??= new Orb219EnvironmentSynchronizer($trace);
    $definitions ??= app(InstantiateProjectRuntimeDefinitionsAction::class);

    return [
        new DeployInstanceAction($resolver, $lock, $environment, $remote, $runtime, $definitions, $deadline),
        new RollbackInstanceAction($resolver, $lock, $remote, $runtime, $deadline),
    ];
}

/** @param list<array{name: string, phase: string, command: string, timeout_seconds: int}> $steps */
function orb219_deployment_instance(array $steps, bool $php = false): Instance
{
    $node = Node::query()->create([
        'name' => 'deployment-node',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.219',
        'wireguard_ip' => '10.44.0.219',
        'user' => 'orbit',
    ]);
    NodeRole::query()->create([
        'node_id' => $node->id,
        'role' => RoleName::AppProd,
        'status' => LifecycleStatus::Active,
    ]);
    $project = Project::query()->create([
        'name' => 'Deployment',
        'slug' => 'deployment',
        'repository_url' => 'https://example.test/deployment.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => '/home/orbit-app-1/releases/initial',
        'production_user' => 'orbit-app-1',
        'production_home' => '/home/orbit-app-1',
        'root' => 'public',
        'branch' => 'main',
        'selected_php_version' => $php ? '8.5' : null,
        'source_is_laravel' => $php,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    store_deploy_steps($instance, $steps);

    return $instance;
}

final class Orb219DeploymentTrace
{
    /** @var list<string> */
    public array $entries = [];

    /** @var list<string> */
    public array $runtimeEvents = [];

    public ?string $remoteSelection = null;

    public bool $activationFailed = false;

    public function __construct(
        public ?string $failAt = null,
        public bool $firstDeployment = false,
        public bool $switchBeforeActivationFailure = false,
        public bool $failActivationReinspection = false,
    ) {}
}

final readonly class Orb219DeploymentLock implements InstanceEnvironmentOperationLock
{
    public function __construct(private Orb219DeploymentTrace $trace) {}

    public function run(array $instanceIds, Closure $operation): mixed
    {
        $this->trace->entries[] = 'lock:'.implode(',', $instanceIds);

        return $operation();
    }
}

final readonly class Orb219EnvironmentSynchronizer implements InstanceEnvironmentSynchronizer
{
    public function __construct(
        private Orb219DeploymentTrace $trace,
        private ?Closure $after = null,
    ) {}

    public function execute(Instance $instance): InstanceEnvironmentResult
    {
        $this->trace->entries[] = 'environment';

        if ($this->trace->failAt === 'environment') {
            throw new ResourceOperationException('env.sync_failed', 'Injected environment failure.', 409);
        }

        if ($this->after !== null) {
            ($this->after)();
        }

        return new InstanceEnvironmentResult($instance->id, 'sync', false, 0);
    }
}

final readonly class Orb219ProductionDeployment implements ProductionDeployment
{
    public function __construct(private Orb219DeploymentTrace $trace) {}

    public function prepare(Instance $instance, string $branch): DeploymentRelease
    {
        $this->record("prepare:{$branch}");

        return $this->release('fresh');
    }

    public function executeStep(
        Instance $instance,
        DeploymentRelease $release,
        DeploymentStep $step,
        DeploymentRequest $request,
    ): CommandResult {
        $this->record("step:{$step->name}:{$step->command}", "step:{$step->name}");

        return new CommandResult(0, "{$step->name}-stdout", "{$step->name}-stderr", 1, false);
    }

    public function activate(Instance $instance, DeploymentRelease $release): DeploymentRelease
    {
        $entry = "activate:{$release->name}";
        $this->trace->entries[] = $entry;

        if ($this->trace->failAt === $entry) {
            $this->trace->activationFailed = true;

            if ($this->trace->switchBeforeActivationFailure) {
                $this->trace->remoteSelection = $release->name;
            }

            throw new ResourceOperationException('deployment.step_failed', 'Injected deployment failure.', 409);
        }

        return $release;
    }

    public function selected(Instance $instance): ?DeploymentRelease
    {
        if ($this->trace->activationFailed && $this->trace->failActivationReinspection) {
            $this->trace->entries[] = 'selected:failed';

            throw new ResourceOperationException('deployment.selection_inspection_failed', 'Injected selection inspection failure.', 409);
        }

        $name = $this->trace->firstDeployment
            ? 'none'
            : ($this->trace->remoteSelection ?? basename($instance->checkout_path));
        $this->record("selected:{$name}");

        return $this->trace->firstDeployment ? null : $this->release($name);
    }

    public function retained(Instance $instance, string $name): DeploymentRelease
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $name) !== 1) {
            throw new ResourceOperationException('rollback.release_invalid', 'Invalid retained release.', 422);
        }

        $this->record("retained:{$name}");

        return $this->release($name);
    }

    public function releases(Instance $instance): DeploymentReleaseState
    {
        return new DeploymentReleaseState(['initial', 'retained'], 'initial');
    }

    public function prune(Instance $instance, DeploymentRelease $selected, ?DeploymentRelease $previous): array
    {
        $this->record('prune:'.$selected->name.':'.($previous->name ?? 'none'));

        return ['removed' => ['old'], 'in_use' => ['busy']];
    }

    private function record(string $entry, ?string $failureKey = null): void
    {
        $this->trace->entries[] = $entry;

        if ($this->trace->failAt === 'deadline:'.($failureKey ?? $entry)) {
            throw new CommandDeadline()->exceeded();
        }

        if ($this->trace->failAt === ($failureKey ?? $entry)) {
            throw new ResourceOperationException('deployment.step_failed', 'Injected deployment failure.', 409);
        }
    }

    private function release(string $name): DeploymentRelease
    {
        return new DeploymentRelease($name, "/home/orbit-app-1/releases/{$name}", str_repeat('a', 40));
    }
}

final readonly class Orb219PhpRuntime implements ProductionPhpRuntimeManager
{
    public function __construct(private Orb219DeploymentTrace $trace) {}

    public function converge(Instance $instance, ?Route $activating = null): void
    {
        $entry = 'converge:'.basename($instance->checkout_path);
        $this->trace->runtimeEvents[] = $entry;
        if ($this->trace->failAt === $entry) {
            throw new ResourceOperationException('app-prod.php_runtime_convergence_failed', 'Injected runtime convergence failure.', 409);
        }
    }

    public function convergeMonitoring(Instance $instance, bool $enabled): void {}

    public function refreshCache(Instance $instance): void
    {
        $entry = 'cache:'.basename($instance->checkout_path);
        $this->trace->runtimeEvents[] = $entry;
        $this->trace->entries[] = $entry;

        if ($this->trace->failAt === $entry) {
            throw new ResourceOperationException('app-prod.php_cache_refresh_failed', 'Injected cache failure.', 409);
        }
    }

    public function remove(Instance $instance): void {}
}
