<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Deployment\InstanceDeploymentExecution;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeploymentTransport;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\VpEnvironment;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;

function deploymentExecutionOperation(): Operation
{
    [, $node, , $instance] = deployment_api_fixture();

    return Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $node->id, 'status' => 'running', 'worker' => 'proof', 'sequence' => 1, 'started_at' => now(),
        'input' => ['instance_id' => $instance->id, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)]]);
}

it('creates one deployment row and reuses it across effect retries', function (): void {
    $operation = deploymentExecutionOperation();
    $execution = app(InstanceDeploymentExecution::class);
    $run = ['key' => 'source.previous', 'worker' => 'proof', 'transport' => 'instance-deploy-agent', 'name' => 'instance.inspect-release', 'input' => []];
    $execution->beforeRun($operation, $run);
    $first = $operation->refresh()->state['deployment_id'];
    $execution->beforeRun($operation, $run);
    expect(InstanceDeployment::query()->count())->toBe(1)->and($operation->refresh()->state['deployment_id'])->toBe($first)
        ->and(InstanceDeployment::query()->sole()->events)->toHaveCount(1);
});

it('refuses changed ownership before creating history or executing a Node command', function (): void {
    $operation = deploymentExecutionOperation();
    $instance = Instance::query()->findOrFail($operation->target_id);
    $instance->project->update(['repository_url' => 'https://example.test/changed.git']);
    expect(fn () => app(InstanceDeploymentExecution::class)->beforeRun($operation, ['key' => 'source.previous']))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('deployment.identity_changed'));
    expect(InstanceDeployment::query()->count())->toBe(0);
});

it('preserves smoke consumer release projections from proven Node receipts', function (): void {
    $operation = deploymentExecutionOperation();
    $execution = app(InstanceDeploymentExecution::class);
    $previous = ['name' => 'previous', 'commit' => str_repeat('b', 40)];
    $operation->state = ['executor_runs' => ['source.previous' => ['result' => ['previous' => [...$previous, 'path' => $operation->input['deployment']['home'].'/releases/previous']]]]];
    $operation->save();
    $result = $execution->received($operation, ['key' => 'source.prepare', 'name' => 'instance.prepare-release'], ['name' => InstanceDeploymentIntent::releaseName($operation), 'commit' => str_repeat('a', 40)]);
    expect($operation->refresh()->state['steps']['source_preparation'])->toBe($result)
        ->and($result['release']['name'])->toBe(InstanceDeploymentIntent::releaseName($operation))
        ->and($result['previous']['name'])->toBe('previous');
});

it('refuses successful completion without all effect evidence', function (): void {
    $operation = deploymentExecutionOperation();
    expect(fn () => app(InstanceDeploymentExecution::class)->assertCompletion($operation, OperationOutcome::succeeded([])))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.completion_unproven'));
});

function deploymentCleanupOperation(): Operation
{
    $operation = deploymentExecutionOperation();
    app(InstanceDeploymentExecution::class)->beforeRun($operation, ['key' => 'source.previous']);
    $home = $operation->input['deployment']['home'];
    $candidate = ['name' => InstanceDeploymentIntent::releaseName($operation), 'path' => $home.'/releases/'.InstanceDeploymentIntent::releaseName($operation), 'commit' => str_repeat('a', 40)];
    $previous = ['name' => 'previous', 'path' => $home.'/releases/previous', 'commit' => str_repeat('b', 40)];
    $operation->update(['state' => [...$operation->state, 'executor_lease_node_id' => 99, 'executor_runs' => [
        'source.prepare' => ['result' => ['release' => $candidate]], 'source.previous' => ['result' => ['previous' => $previous]],
    ]]]);

    return $operation;
}

it('retires runtime references only for confirmed removed releases and records busy cleanup warnings', function (): void {
    $operation = deploymentCleanupOperation();
    $instance = Instance::query()->findOrFail($operation->target_id);
    $home = $operation->input['deployment']['home'];
    $instance->update(['checkout_path' => $home.'/releases/'.InstanceDeploymentIntent::releaseName($operation),
        'vp_environment' => ['checkout_path' => $home.'/releases/old', 'previous' => [
            'checkout_path' => $home.'/releases/busy', 'previous' => ['checkout_path' => $home.'/releases/previous'],
        ]]]);

    $result = app(InstanceDeploymentExecution::class)->received($operation, ['key' => 'cleanup', 'name' => 'instance.prune-releases'], ['removed' => ['old'], 'in_use' => ['busy']]);

    expect($result)->toBe(['removed_count' => 1, 'in_use_count' => 1]);
    expect($instance->refresh()->getAttribute('vp_environment'))->toBe([
        'checkout_path' => $home.'/releases/busy', 'previous' => ['checkout_path' => $home.'/releases/previous', 'previous' => null],
    ]);
    $output = implode('', array_map(static fn (array $event): string => isset($event['value_base64']) ? base64_decode($event['value_base64']) : '', InstanceDeployment::query()->sole()->events));
    expect($output)->toContain('Removed old release old.', 'Kept old release busy: a running process still uses it.');
    expect($operation->refresh()->state['executor_lease_node_id'])->toBe(99);
});

it('refuses ambiguous or protected cleanup receipts before changing runtime references', function (string $fault): void {
    $operation = deploymentCleanupOperation();
    $instance = Instance::query()->findOrFail($operation->target_id);
    $instance->update(['vp_environment' => ['checkout_path' => '/retained/runtime']]);
    $receipt = match ($fault) {
        'path' => ['removed' => ['../outside'], 'in_use' => []],
        'selected' => ['removed' => [InstanceDeploymentIntent::releaseName($operation)], 'in_use' => []],
        'previous' => ['removed' => ['previous'], 'in_use' => []],
        'overlap' => ['removed' => ['old'], 'in_use' => ['old']],
        'missing' => ['removed' => []],
    };

    expect(fn () => app(InstanceDeploymentExecution::class)->received($operation, ['key' => 'cleanup', 'name' => 'instance.prune-releases'], $receipt))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('deployment.receipt_invalid'));
    expect($instance->refresh()->getAttribute('vp_environment'))->toBe(['checkout_path' => '/retained/runtime']);
})->with(['path', 'selected', 'previous', 'overlap', 'missing']);

it('keeps uncertain release preparation bound to its original keyed receipt', function (): void {
    $operation = deploymentExecutionOperation();
    $operation->state = ['executor_runs' => ['source.prepare' => ['started' => true, 'result' => null]]];

    $context = app(InstanceDeploymentExecution::class)->context($operation);

    $prepare = array_find($context['descriptors'], static fn (array $descriptor): bool => $descriptor['key'] === 'source.prepare');
    expect($prepare['name'])->toBe('instance.prepare-release');
    expect($prepare['resumable'])->toBeFalse();
    expect(array_column($context['descriptors'], 'key'))->not->toContain('source.reconcile', 'activation');
});

it('admits one exact environment child only after proven source preparation and retains the parent target hold', function (): void {
    $operation = deploymentExecutionOperation();
    $instance = Instance::query()->findOrFail($operation->target_id);
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => 'child.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $instance->update(['status' => 'active', 'provisioning_step' => 'active', 'source_is_laravel' => false]);
    $operation->update(['input' => [...$operation->input, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)]]);
    $children = app(OperationChildren::class);
    expect(fn () => $children->admit($operation, ['key' => 'environment_sync']))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.child_forbidden'));
    $result = ['release' => ['name' => InstanceDeploymentIntent::releaseName($operation),
        'path' => $instance->production_home.'/releases/'.InstanceDeploymentIntent::releaseName($operation), 'commit' => str_repeat('a', 40)], 'previous' => null];
    $previous = ['previous' => null];
    $operation->update(['state' => ['steps' => ['source_preparation.previous' => $previous, 'source_preparation' => $result], 'executor_runs' => [
        'source.previous' => ['name' => 'instance.inspect-release', 'transport' => 'instance-deploy-agent', 'step' => 'source_preparation.previous', 'result' => $previous],
        'source.prepare' => ['name' => 'instance.prepare-release', 'transport' => 'instance-deploy-agent', 'step' => 'source_preparation', 'result' => $result],
    ]]]);
    foreach (['source_preparation.previous', 'source_preparation'] as $position => $name) {
        $operation->steps()->create(['sequence' => 1, 'position' => $position + 1, 'name' => $name, 'status' => 'succeeded', 'resumable' => true]);
    }
    $first = DB::transaction(fn () => $children->admit($operation, ['key' => 'environment_sync']));
    $replay = $children->admit($operation->fresh(), ['key' => 'environment_sync']);
    expect($first['created'])->toBeTrue()->and($replay['created'])->toBeFalse()
        ->and($first['operation']->id)->toBe($replay['operation']->id)
        ->and($first['operation']->input)->toBe(['instance_id' => $instance->id])
        ->and($first['operation']->parent_operation_id)->toBe($operation->id)
        ->and($first['operation']->node_id)->toBe($operation->node_id);
    expect(fn () => $children->admit($operation, ['key' => 'environment_sync', 'node_id' => $operation->node_id + 1]))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.idempotency_conflict'));
    expect(fn () => app(StartOperationAction::class)->execute('instance.sync_environment', 'instance', $instance->id, $instance->node_id, ['instance_id' => $instance->id], 'test', null))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.target_busy'));
});

it('refuses an incomplete PHP cache receipt rather than authorizing Process restart', function (): void {
    $operation = deploymentExecutionOperation();
    $receipt = app(InstanceDeploymentExecution::class)->received($operation,
        ['key' => 'php.refresh', 'name' => 'php.refresh-production-cache'], ['complete' => false]);
    expect($receipt)->toBe(['error_code' => 'app-prod.php_cache_refresh_failed']);
});

it('refuses Vite store collection when a runtime reference changed after binding', function (): void {
    $operation = deploymentExecutionOperation();
    $instance = Instance::query()->findOrFail($operation->target_id);
    $environment = new VpEnvironment('/opt/orbit/vite-plus', '0.3.3', '24.22.0', 'npm', '11.12.0');
    $instance->update(['vp_environment' => $environment->record('active')]);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    expect(fn () => app(InstanceDeploymentTransport::class)->run($operation,
        ['key' => 'cleanup.vp.proof', 'worker' => 'proof', 'transport' => 'instance-deploy-agent', 'name' => 'tool.prune-vp-store', 'input' => []],
        ['store' => $environment->store, 'references' => []]))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('deployment.vp_references_changed'));
    expect($agents->calls)->toBe([]);
});
