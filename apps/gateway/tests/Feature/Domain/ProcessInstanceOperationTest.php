<?php

declare(strict_types=1);

use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'restart-plan', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.19']);
    $project = Project::query()->create(['name' => 'Runtime proof', 'slug' => 'runtime-proof', 'repository_url' => 'https://example.test/runtime.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'proof',
        'environment' => 'development', 'checkout_path' => '/srv/proof', 'provisioning_step' => 'active', 'status' => 'active']);
    $root = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->node->id, 'status' => 'running', 'input' => []]);
    $this->operation = new Operation;
    $this->operation->forceFill(['kind' => 'process.restart_instance', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->node->id, 'status' => 'running', 'parent_operation_id' => $root->id, 'child_key' => 'restart-processes',
        'input' => ['instance_id' => $this->instance->id], 'state' => ['runtime_children' => []]])->save();
    $this->runtime = app(ProcessInstanceOperation::class);
});

it('selects only active desired-running opted-in Processes in ID order', function (): void {
    $first = runtimeRestartProcess($this->instance, 'first');
    runtimeRestartProcess($this->instance, 'opt-out', ['restart_on_deploy' => false]);
    runtimeRestartProcess($this->instance, 'stopped', ['desired_state' => 'stopped']);
    runtimeRestartProcess($this->instance, 'failed', ['status' => 'failed']);
    $second = runtimeRestartProcess($this->instance, 'second');
    runtimeRestartProcess($this->instance, 'other-owner', ['owner_type' => Node::class, 'owner_id' => $this->node->id]);

    $plan = $this->runtime->plan($this->instance, 'process.restart_instance');

    expect(array_column(array_column($plan, 'input'), 'process_id'))->toBe([$first->id, $second->id]);
    expect(array_column($plan, 'kind'))->toBe(['process.restart', 'process.restart']);
    expect(array_column($plan, 'node_id'))->toBe([$this->node->id, $this->node->id]);
});

it('returns an empty Process ID list when no Process needs restarting', function (): void {
    expect($this->runtime->run($this->operation, ['instance_id' => $this->instance->id]))->toBe(['ready' => true, 'process_ids' => []]);
});

it('reports exact restarted IDs from verified child targets and refuses an invented completion list', function (): void {
    $first = runtimeRestartProcess($this->instance, 'first');
    $second = runtimeRestartProcess($this->instance, 'second');
    $plan = $this->runtime->plan($this->instance, 'process.restart_instance');
    $this->operation->update(['state' => ['runtime_children' => $plan]]);
    runtimeRestartChild($this->operation, $plan[0]);
    runtimeRestartChild($this->operation, $plan[1]);

    $result = $this->runtime->run($this->operation, ['instance_id' => $this->instance->id]);

    expect($result)->toBe(['ready' => true, 'process_ids' => [$first->id, $second->id]]);
    $this->runtime->assertCompletion($this->operation, OperationOutcome::succeeded($result));
    expect(fn () => $this->runtime->assertCompletion($this->operation, OperationOutcome::succeeded(['ready' => true, 'process_ids' => [$second->id, $first->id]])))
        ->toThrow(ResourceOperationException::class);
    expect(fn () => $this->runtime->assertCompletion($this->operation, OperationOutcome::succeeded(['ready' => true, 'process_ids' => [(string) $first->id, (string) $second->id]])))
        ->toThrow(ResourceOperationException::class);
});

it('refuses missing failed and modified restart evidence', function (string $case): void {
    runtimeRestartProcess($this->instance, 'first');
    $plan = $this->runtime->plan($this->instance, 'process.restart_instance');
    $this->operation->update(['state' => ['runtime_children' => $plan]]);
    if ($case !== 'missing') {
        $child = runtimeRestartChild($this->operation, $plan[0]);
        $child->update($case === 'failed' ? ['status' => 'failed'] : ['target_id' => $child->target_id + 1]);
    }

    expect(fn () => $this->runtime->run($this->operation, ['instance_id' => $this->instance->id]))->toThrow(ResourceOperationException::class);
})->with(['missing', 'failed', 'modified']);

it('uses stamped parent metadata and refuses parent identity supplied in input', function (): void {
    $this->operation->input = ['instance_id' => $this->instance->id, 'parent_operation_id' => $this->operation->parent_operation_id];

    expect(fn () => $this->runtime->context($this->operation))->toThrow(ResourceOperationException::class);
});

/** @param array<string,mixed> $attributes */
function runtimeRestartProcess(Instance $instance, string $name, array $attributes = []): Process
{
    return Process::query()->create([...['owner_type' => Instance::MorphAlias, 'owner_id' => $instance->id, 'name' => $name,
        'runtime' => 'systemd', 'restart_policy' => 'never', 'runtime_config' => ['command' => ['sleep', '300']],
        'status' => 'active', 'desired_state' => 'running', 'restart_on_deploy' => true], ...$attributes]);
}

/** @param array{key:string,kind:string,node_id:int,input:array<string,mixed>} $intent */
function runtimeRestartChild(Operation $parent, array $intent): Operation
{
    $child = new Operation;
    $identity = ['parent_operation_id' => $parent->id, 'key' => $intent['key'], 'kind' => $intent['kind'],
        'node_id' => $intent['node_id'], 'target_type' => 'process', 'target_id' => $intent['input']['process_id'], 'input' => $intent['input'], 'run_constraints' => []];
    $snapshot = ['intent' => $intent, 'admitted' => $identity];
    $child->forceFill([...array_diff_key($identity, ['key' => true]), 'child_key' => $intent['key'], 'status' => 'succeeded',
        'child_admission' => $snapshot, 'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();

    return $child;
}
