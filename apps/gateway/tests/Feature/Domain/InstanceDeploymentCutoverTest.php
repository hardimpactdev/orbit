<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceStepListAction;
use App\Actions\Routes\ConvergeRouteAction;
use App\Actions\Routes\ConvergeRouteTargetSetAction;
use App\Actions\Routes\DestroyRouteAction;
use App\Actions\Routes\UpdateRouteAction;
use App\Data\Routes\SetRouteTargetsData;
use App\Data\Routes\UpdateRouteData;
use App\Domain\Instances\Deployment\InstanceDeploymentAvailability;
use App\Domain\Instances\Deployment\InstanceDeploymentExecution;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Projects\NativeProjectUpdateProjectionMutator;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Tests\Support\FakeAgentCommandRunner;

function cutoverDeployment(): Operation
{
    [, $node, , $instance] = deployment_api_fixture();
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $node->id, 'status' => 'running', 'worker' => 'proof', 'sequence' => 1, 'started_at' => now(),
        'lock_key' => 'instance:'.$instance->id,
        'input' => ['instance_id' => $instance->id, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)]]);
    app(InstanceDeploymentExecution::class)->beforeRun($operation, ['key' => 'source.previous']);

    return $operation;
}

it('rejects the release when an execution dependency is missing or still PHP', function (string $missing): void {
    $definitions = OperationKinds::definitions();
    foreach (['instance.clone', 'instance.synchronize_environment', 'route.converge', 'instance.sync_environment',
        'tool.manager_converge', 'process.install_instance_definitions', 'schedule.install_instance_definitions',
        'node.prepare_php_packages', 'process.restart_instance'] as $kind) {
        $definitions[$kind] = ['executor' => 'swamp'];
    }
    InstanceDeploymentAvailability::assertReleaseReady($definitions);
    unset($definitions[$missing]);
    expect(fn () => InstanceDeploymentAvailability::assertReleaseReady($definitions))->toThrow(LogicException::class, $missing);
    $definitions[$missing] = stdClass::class;
    expect(fn () => InstanceDeploymentAvailability::assertReleaseReady($definitions))->toThrow(LogicException::class, $missing);
})->with(['process.restart_instance', 'process.install_instance_definitions', 'schedule.install_instance_definitions',
    'node.prepare_php_packages', 'route.converge', 'instance.clone', 'instance.synchronize_environment']);

it('finishes failed or cancelled history from proven selection without a reachable Node', function (bool $activated, string $status): void {
    $operation = cutoverDeployment();
    $home = $operation->input['deployment']['home'];
    $candidate = ['name' => InstanceDeploymentIntent::releaseName($operation), 'path' => $home.'/releases/'.InstanceDeploymentIntent::releaseName($operation), 'commit' => str_repeat('a', 40)];
    $previous = ['name' => 'previous', 'path' => $home.'/releases/previous', 'commit' => str_repeat('b', 40)];
    $runs = [
        'source.prepare' => ['name' => 'instance.prepare-release', 'transport' => 'instance-deploy-agent', 'result' => ['release' => $candidate]],
        'source.previous' => ['name' => 'instance.inspect-release', 'transport' => 'instance-deploy-agent', 'result' => ['previous' => $previous]],
    ];
    if ($activated) {
        $runs['activation'] = ['name' => 'instance.activate-release', 'transport' => 'instance-deploy-agent', 'result' => $candidate];
    }
    $operation->update(['state' => [...$operation->state, 'executor_lease_node_id' => $operation->node_id, 'executor_runs' => $runs]]);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $expected = app(InstanceDeploymentExecution::class)->context($operation)['result'];
    expect($expected['selected_release'])->toBe($activated ? $candidate['name'] : 'previous');

    $finished = app(SwampOperationExecutor::class)->complete($operation, ['worker' => 'proof', 'status' => $status,
        'result' => $expected, 'error_code' => $status === 'failed' ? 'deployment.interrupted' : null], $operation->node_id);

    expect($finished->lock_key)->toBeNull()->and($finished->status->value)->toBe($status);
    $row = InstanceDeployment::query()->findOrFail($expected['deployment_id']);
    expect($row->status)->toBe('failed')->and($row->selected_release)->toBe($activated ? $candidate['name'] : 'previous');
    expect($agents->calls)->toBe([]);
})->with([true, false])->with(['failed', 'cancelled']);

it('checks the smoke rollback target SHA before accepting its retained release', function (): void {
    $operation = cutoverDeployment();
    $operation->update(['kind' => 'instance.rollback', 'input' => [...$operation->input, 'target' => ['release' => 'passing', 'commit' => str_repeat('b', 40)]]]);

    expect(fn () => app(InstanceDeploymentExecution::class)->received($operation,
        ['key' => 'source.retained', 'name' => 'instance.inspect-release'], ['release' => ['name' => 'passing', 'commit' => str_repeat('c', 40)]]))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('deployment.receipt_invalid'));
    expect($operation->refresh()->state['steps'] ?? [])->toBe([]);
});

it('interrupts legacy Operations and history once at migration while preserving new intent', function (string $kind, string $status): void {
    $operation = cutoverDeployment();
    $new = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $operation->target_id,
        'node_id' => $operation->node_id, 'status' => 'queued', 'sequence' => 1, 'input' => $operation->input]);
    $operation->update(['kind' => $kind, 'status' => $status, 'current_step' => 'activation', 'input' => ['instance_id' => $operation->target_id]]);
    $operation->steps()->create(['sequence' => 1, 'position' => 1, 'name' => 'activation', 'status' => 'running', 'resumable' => true]);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $migration = require database_path('migrations/2027_01_06_000002_interrupt_legacy_instance_deployments.php');

    $migration->up();
    $sequence = $operation->refresh()->sequence;
    $migration->up();

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)->and($operation->error_code)->toBe('deployment.interrupted')
        ->and($operation->lock_key)->toBeNull()->and($operation->sequence)->toBe($sequence)
        ->and($operation->steps()->sole()->status->value)->toBe('failed');
    expect(InstanceDeployment::query()->sole()->status)->toBe('failed')->and(InstanceDeployment::query()->sole()->error_code)->toBe('deployment.interrupted');
    expect($new->refresh()->status)->toBe(OperationStatus::Queued);
    expect($agents->calls)->toBe([]);
    expect(fn () => InstanceDeploymentIntent::admitted($operation))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('deployment.interrupted'));
})->with(['instance.deploy', 'instance.rollback'])->with(['queued', 'running', 'waiting']);

it('refuses legacy production Route transitions before any mutation or Node call', function (string $caller): void {
    [, $node, $project, $instance] = deployment_api_fixture();
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $node->id, 'domain' => 'cutover.example.test',
        'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $before = $route->refresh()->getRawOriginal();
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $call = match ($caller) {
        'domain' => fn () => app(ConvergeRouteAction::class)->execute($route, 'changed.example.test'),
        'update' => fn () => app(UpdateRouteAction::class)->execute($route, new UpdateRouteData(true, 'changed.example.test', false, null)),
        'targets' => fn () => app(ConvergeRouteTargetSetAction::class)->execute($route, new SetRouteTargetsData([], [])),
        'remove' => fn () => app(DestroyRouteAction::class)->executeForOperator($route, false),
        'project-slug' => fn () => app(NativeProjectUpdateProjectionMutator::class)->preflightSlug($project, 'changed'),
        'project-root' => fn () => app(NativeProjectUpdateProjectionMutator::class)->preflightRoot($project, 'changed'),
    };

    expect($call)->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.kind_unavailable'));
    expect($route->refresh()->getRawOriginal())->toBe($before);
    expect(Route::query()->count())->toBe(1);
    expect($agents->calls)->toBe([]);
})->with(['domain', 'update', 'targets', 'remove', 'project-slug', 'project-root']);

it('keeps a newly activated Instance when the retained setup wait expires', function (): void {
    $instance = dev935_instance();
    $operation = app(StartInstanceStepListAction::class)->execute($instance, LifecyclePhase::Setup, 'proof', null);
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }
    Sleep::fake();
    Sleep::whenFakingSleep(function (): never {
        throw new ResourceOperationException('operation.await_timeout', 'The caller wait ended.', 409,
            details: ['operation_id' => Operation::query()->sole()->id]);
    });
    try {
        expect(fn () => app(StartInstanceStepListAction::class)->await($operation))
            ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.await_timeout')
                ->and($failure->details)->toBe(['operation_id' => Operation::query()->sole()->id, 'outcome' => 'unconfirmed']));
        test()->assertModelExists($instance);
        expect(Operation::query()->sole()->status)->toBe(OperationStatus::Queued)->and(Operation::query()->sole()->cancel_requested_at)->toBeNull();
    } finally {
        Sleep::fake(false);
    }
});
