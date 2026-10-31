<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessTarget;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'restart-plan', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.19']);
    $this->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Runtime proof', 'slug' => 'runtime-proof', 'repository_url' => 'https://example.test/runtime.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'proof',
        'environment' => 'development', 'checkout_path' => '/srv/proof', 'provisioning_step' => 'active', 'status' => 'active']);
    $this->runtime = app(ProcessInstanceOperation::class);
});

it('captures only active desired-running opted-in Processes in ID order without member Operations', function (): void {
    $first = runtimeRestartProcess($this->instance, 'first');
    runtimeRestartProcess($this->instance, 'opt-out', ['restart_on_deploy' => false]);
    runtimeRestartProcess($this->instance, 'stopped', ['desired_state' => 'stopped']);
    runtimeRestartProcess($this->instance, 'failed', ['status' => 'failed']);
    $second = runtimeRestartProcess($this->instance, 'second');
    runtimeRestartProcess($this->instance, 'other-owner', ['owner_type' => Node::class, 'owner_id' => $this->node->id]);
    $capture = $this->runtime->capture($this->instance, 'process.restart_instance');
    expect(array_column($capture['members'], 'id'))->toBe([$first->id, $second->id])
        ->and(array_column(array_column($capture['members'], 'input'), 'node_id'))->toBe([$this->node->id, $this->node->id])
        ->and(Operation::query()->count())->toBe(0);
});

it('captures an empty selection without inventing a Process', function (): void {
    expect($this->runtime->capture($this->instance, 'process.restart_instance')['members'])->toBe([])
        ->and(Process::query()->count())->toBe(0);
});

it('uses the exact executor Node without a Gateway recovery writer', function (): void {
    config(['operation_executor.worker_node_ids' => [(string) $this->node->id], 'dependency_detection.services_node_id' => (string) $this->node->id]);
    runtimeRestartProcess($this->instance, 'executor');
    expect($this->runtime->capture($this->instance, 'process.restart_instance')['node_id'])->toBe($this->node->id);
});

it('keeps captured selection when the current inventory changes', function (): void {
    $first = runtimeRestartProcess($this->instance, 'first');
    $input = $this->runtime->capture($this->instance, 'process.restart_instance');
    $operation = new Operation;
    $operation->forceFill(['id' => 17, 'kind' => 'process.restart_instance', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'input' => $input]);
    $first->update(['restart_on_deploy' => false]);
    runtimeRestartProcess($this->instance, 'later');
    expect(array_column($this->runtime->input($operation)['members'], 'id'))->toBe([$first->id]);
});

it('uses a provided admitted Process target without resolving current owner placement', function (): void {
    $process = runtimeRestartProcess($this->instance, 'admitted-target');
    $target = new ProcessTarget($this->node, 'orbit', '/srv/captured', instance: $this->instance);
    $process->owner_type = Node::class;
    $process->owner_id = 999999;
    $input = app(ProcessOperationAdmission::class)->inputFor($process, 'restart', target: $target);
    expect($input['node_id'])->toBe($this->node->id);
});

it('projects native member results without child proof or another Operation', function (): void {
    $process = runtimeRestartProcess($this->instance, 'finished');
    $operation = Operation::query()->create(['kind' => 'process.restart_instance', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->node->id, 'status' => 'running', 'input' => $this->runtime->capture($this->instance, 'process.restart_instance'),
        'state' => ['process_instance_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    $result = ['ready' => true, 'process_ids' => [$process->id], 'members' => [['id' => $process->id, 'ready' => true, 'state' => 'active']]];
    $this->runtime->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => [...$result, 'private_material' => 'private-extra', 'members' => [[...$result['members'][0], 'stdout' => 'private-extra']]]]);
    expect($operation->refresh()->result)->toBe($result)->and($process->refresh()->status->value)->toBe('active')->and(Operation::query()->count())->toBe(1);
});

/** @param array<string,mixed> $attributes */
function runtimeRestartProcess(Instance $instance, string $name, array $attributes = []): Process
{
    return Process::query()->create([...['owner_type' => Instance::MorphAlias, 'owner_id' => $instance->id, 'name' => $name,
        'runtime' => 'systemd', 'restart_policy' => 'never', 'runtime_config' => ['command' => ['/usr/bin/sleep', '300']],
        'status' => 'active', 'desired_state' => 'running', 'restart_on_deploy' => true], ...$attributes]);
}
