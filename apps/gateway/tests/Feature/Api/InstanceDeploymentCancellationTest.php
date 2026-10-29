<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeploymentExecution;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutionContract;
use App\Models\Operation;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

function cancellationDeployment($test, string $kind): Operation
{
    [$caller, $node, , $instance] = deployment_api_fixture();
    config(['dependency_detection.services_node_id' => $caller->id, 'operation_executor.worker_node_ids' => []]);
    $test->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $node->update(['ssh_host_fingerprint' => 'SHA256:fixture']);
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $node->id,
        'domain' => 'cancel.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $instance->update(['status' => 'active', 'provisioning_step' => 'active', 'source_is_laravel' => false, 'selected_php_version' => '8.5',
        ...ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes()]);
    $intent = app(InstanceDeploymentIntent::class)->snapshot($instance, $kind === 'instance.rollback');
    $intent['steps'] = [];

    return Operation::query()->create(['kind' => $kind, 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $node->id, 'status' => 'running', 'worker' => 'proof', 'started_at' => now(), 'sequence' => 1,
        'lock_key' => 'instance:'.$instance->id, 'state' => ['executor_lease_node_id' => $caller->id],
        'input' => ['instance_id' => $instance->id, 'release' => 'retained', 'deployment' => $intent]]);
}

/** Supply the earlier proven results; the admission under test still uses its real HTTP and owning-family adapters. */
function proveBeforeDeploymentChild(Operation $operation, string $key): void
{
    $execution = app(InstanceDeploymentExecution::class);
    for ($count = 0; $count < 32; $count++) {
        $descriptor = array_find($execution->context($operation)['descriptors'],
            fn (array $entry): bool => ! isset($operation->state['executor_runs'][$entry['key']]));
        if ($descriptor === null) {
            throw new RuntimeException('The expected deployment child is missing.');
        }
        if ($descriptor['key'] === $key || $descriptor['key'] === 'child.'.$key) {
            return;
        }
        $name = $operation->kind === 'instance.rollback' ? 'retained' : InstanceDeploymentIntent::releaseName($operation);
        $release = ['name' => $name, 'path' => $operation->input['deployment']['home'].'/releases/'.$name, 'commit' => str_repeat('a', 40)];
        if ($descriptor['name'] === 'instance.child-receipt') {
            $child = DB::transaction(fn () => app(OperationChildren::class)->admit($operation, ['key' => $descriptor['input']['child_key']]))['operation'];
            $child->update(['status' => 'succeeded', 'finished_at' => now(), 'result' => ['ready' => true, 'process_ids' => []]]);
            $result = ['child_id' => $child->id, 'child_kind' => $child->kind, 'child_status' => 'succeeded'];
        } else {
            $result = match ($descriptor['key']) {
                'source.previous' => ['previous' => null],
                'source.prepare', 'source.retained' => ['release' => $release, 'previous' => null],
                'activation' => $release,
                'php.profile' => ['changed' => false],
                'php.converge' => ['published' => false],
                'php.refresh' => ['complete' => true],
                default => throw new RuntimeException('Unexpected fixture step '.$descriptor['key']),
            };
        }
        $operation->update(['state' => [...$operation->state,
            'executor_runs' => [...($operation->state['executor_runs'] ?? []), $descriptor['key'] => [
                'name' => $descriptor['name'], 'transport' => $descriptor['transport'], 'step' => $descriptor['step'], 'result' => $result]],
            'steps' => [...($operation->state['steps'] ?? []), $descriptor['step'] => $result]]]);
        $operation->steps()->create(['name' => $descriptor['step'], 'position' => $operation->steps()->count() + 1,
            'sequence' => 1, 'status' => 'succeeded', 'resumable' => true]);
    }
    throw new RuntimeException('The deployment fixture did not reach the child.');
}

it('continues runtime children after cancellation at proven deploy or rollback activation', function (string $kind, string $key, bool $existing): void {
    $operation = cancellationDeployment($this, $kind);
    proveBeforeDeploymentChild($operation, $key);
    $path = '/api/v1/operation-executor/'.$operation->id.'/children';
    if ($existing) {
        $id = $this->postJson($path, ['worker' => 'proof', 'key' => $key])->assertAccepted()->json('data.id');
    }

    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel',
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();
    expect(app(OperationExecutionContract::class)->cancellationAllowsRun($operation->refresh()))->toBeTrue();
    $response = $this->postJson($path, ['worker' => 'proof', 'key' => $key]);
    $existing ? $response->assertOk() : $response->assertAccepted();
    $child = Operation::query()->findOrFail($response->json('data.id'));
    expect($child->status->value)->toBe('queued')->and($child->cancel_requested_at)->toBeNull()
        ->and($child->parent_operation_id)->toBe($operation->id)->and($child->node_id)->toBe($operation->node_id);
    if ($existing) {
        expect($child->id)->toBe($id);
    }
    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel',
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();
    expect($child->refresh()->status->value)->toBe('queued')->and($child->cancel_requested_at)->toBeNull()
        ->and($operation->refresh()->lock_key)->toBe('instance:'.$operation->target_id);
})->with([
    ['instance.deploy', 'activation.process_definitions'], ['instance.deploy', 'activation.schedule_definitions'],
    ['instance.deploy', 'php_refresh.packages'], ['instance.deploy', 'process_restart'],
    ['instance.rollback', 'php_refresh.packages'], ['instance.rollback', 'process_restart'],
])->with([false, true]);

it('does not admit a deployment child when cancelled before proven activation', function (): void {
    $operation = cancellationDeployment($this, 'instance.deploy');
    proveBeforeDeploymentChild($operation, 'environment_sync');
    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel',
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/children', ['worker' => 'proof', 'key' => 'environment_sync'])
        ->assertConflict()->assertJsonPath('error.code', 'operation.cancelled');
    expect($operation->children()->count())->toBe(0);
});

it('admits runtime children when the activation receipt arrives after a cancellation request', function (string $kind): void {
    $operation = cancellationDeployment($this, $kind);
    proveBeforeDeploymentChild($operation, 'activation');
    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel',
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();
    expect(app(OperationExecutionContract::class)->cancellationAllowsRun($operation->refresh()))->toBeFalse();

    proveBeforeDeploymentChild($operation, 'php_refresh.packages');
    expect(app(OperationExecutionContract::class)->cancellationAllowsRun($operation->refresh()))->toBeTrue();
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/children', ['worker' => 'proof', 'key' => 'php_refresh.packages'])
        ->assertAccepted()->assertJsonPath('data.kind', 'node.prepare_php_packages');
})->with(['instance.deploy', 'instance.rollback']);
