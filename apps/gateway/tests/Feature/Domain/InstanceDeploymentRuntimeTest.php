<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeploymentExecution;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceExecutorRuntimeCommand;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Schedule;

function runtimeDeployment(bool $executor, bool $rollback = false): array
{
    [, $node, , $instance] = deployment_api_fixture();
    config(['dependency_detection.services_node_id' => $executor ? $node->id : null, 'operation_executor.worker_node_ids' => []]);
    $operation = Operation::query()->create(['kind' => $rollback ? 'instance.rollback' : 'instance.deploy', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $node->id, 'status' => 'running', 'worker' => 'proof', 'sequence' => 1,
        'started_at' => now(), 'input' => ['instance_id' => $instance->id, 'release' => 'retained',
            'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance, $rollback)]]);

    return [$operation, $instance];
}

it('binds synchronous runtime composition only on executor Nodes for deploy and rollback', function (bool $executor, bool $rollback): void {
    [$operation] = runtimeDeployment($executor, $rollback);
    $descriptors = app(InstanceDeploymentExecution::class)->context($operation)['descriptors'];
    $native = array_values(array_filter($descriptors, fn (array $item): bool => $item['name'] === 'instance.executor-runtime'));
    $children = array_column(array_map(fn (array $item): array => $item['input'], array_filter($descriptors,
        fn (array $item): bool => $item['name'] === 'instance.child-receipt')), 'child_key');
    expect($operation->input['deployment']['runtime_synchronous'])->toBe($executor);
    if ($executor) {
        expect(array_column($native, 'key'))->toBe($rollback ? ['process_restart'] : ['activation.runtime_definitions', 'process_restart'])
            ->and($children)->not->toContain('activation.process_definitions', 'activation.schedule_definitions', 'process_restart');
        foreach ($native as $item) {
            expect($item['transport'])->toBe('gateway')->and($item['resumable'])->toBeFalse();
        }
    } else {
        expect($native)->toBe([])->and($children)->toContain('process_restart');
        if (! $rollback) {
            expect($children)->toContain('activation.process_definitions', 'activation.schedule_definitions');
        }
    }
})->with([false, true])->with([false, true]);

it('publishes the PHP limits module after packages and before pool/cache effects', function (bool $rollback): void {
    [$operation, $instance] = runtimeDeployment(false, $rollback);
    $instance->update(['selected_php_version' => '8.5', ...ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes()]);
    $operation->update(['input' => [...$operation->input, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance, $rollback)]]);
    $home = $operation->input['deployment']['home'];
    $release = ['name' => $rollback ? 'retained' : InstanceDeploymentIntent::releaseName($operation), 'commit' => str_repeat('a', 40)];
    $operation->update(['state' => ['executor_runs' => [($rollback ? 'source.retained' : 'source.prepare') => ['result' => ['release' => $release]]]]]);
    $execution = app(InstanceDeploymentExecution::class);
    $descriptors = array_values(array_filter($execution->context($operation)['descriptors'], fn (array $item): bool => str_starts_with($item['step'], 'php_refresh')));
    expect(array_column($descriptors, 'key'))->toBe(['child.php_refresh.packages', 'php.profile', 'php.converge', 'php.refresh']);
    $profile = $descriptors[1];
    expect($profile['name'])->toBe('php.configure-runtime')->and($profile['input']['version'])->toBe('8.5')
        ->and($profile['input']['runtime_ini'])->toContain('post_max_size = 64M', 'upload_max_filesize = 64M', 'opcache.enable = On');
    expect($execution->received($operation, ['key' => 'php.profile'], ['changed' => true, 'reloaded' => true]))->toBe(['changed' => true]);
    expect(fn () => $execution->beforeRun($operation, ['key' => 'php.converge']))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.completion_unproven'));
})->with([false, true]);

it('keeps executor runtime installs stopped and restarts selected Processes synchronously', function (): void {
    [$operation, $instance] = runtimeDeployment(true);
    $instance->project->processDefinitions()->create(['name' => 'queue', 'environments' => ['production'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/php', 'artisan', 'queue:work']]]);
    $instance->project->scheduleDefinitions()->create(['name' => 'daily', 'environments' => ['production'],
        'spec' => ['command' => '/usr/bin/php artisan schedule:run', 'calendar' => 'daily', 'timeout_seconds' => 60]]);
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldReceive('converge')->once()->withArgs(fn (Process $process): bool => $process->desired_state === DesiredProcessState::Stopped);
    $runtime->shouldReceive('restart')->once()->withArgs(fn (Process $process): bool => $process->owner_id === $instance->id);
    $runtime->shouldReceive('status')->once()->andReturn('running');
    $scheduleRuntime = Mockery::mock(ScheduleRuntimeManager::class);
    $scheduleRuntime->shouldReceive('install')->once()->withArgs(fn (Schedule $schedule): bool => $schedule->desired_timer_state === DesiredTimerState::Disabled);
    app()->instance(ProcessRuntimeManager::class, $runtime);
    app()->instance(ScheduleRuntimeManager::class, $scheduleRuntime);
    $command = app(InstanceExecutorRuntimeCommand::class);
    expect($command->run($operation, ['instance_id' => $instance->id, 'action' => 'install_definitions']))->toBe(['ready' => true, 'process_ids' => []]);
    $process = Process::query()->sole();
    $process->update(['desired_state' => DesiredProcessState::Running, 'restart_on_deploy' => true]);
    expect($command->run($operation, ['instance_id' => $instance->id, 'action' => 'restart']))->toBe(['ready' => true, 'process_ids' => [$process->id]]);
    expect(Operation::query()->count())->toBe(1);
});

it('refuses synchronous runtime dispatch after executor placement changes', function (): void {
    [$operation, $instance] = runtimeDeployment(true);
    config(['dependency_detection.services_node_id' => null]);
    expect(fn () => app(InstanceExecutorRuntimeCommand::class)->run($operation, ['instance_id' => $instance->id, 'action' => 'restart']))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('deployment.identity_changed'));
});
