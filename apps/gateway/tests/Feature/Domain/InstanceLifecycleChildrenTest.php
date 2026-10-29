<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceLifecycleChildren;
use App\Domain\Instances\InstanceLifecycleCommand;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;

/** A persisted Gateway admission snapshot, without invoking an absent downstream family executor. */
function preparationListChild(Operation $parent, string $key, string $kind = 'instance.run_steps', string $status = 'failed', array $result = []): Operation
{
    $phase = str_contains($key, 'teardown') ? 'teardown' : 'setup';
    $input = ['instance_id' => $parent->target_id, 'source' => 'project', 'list' => $phase, 'configuration_digest' => str_repeat('a', 64)];
    $child = Operation::query()->create(['kind' => $kind, 'target_type' => 'instance', 'target_id' => $parent->target_id,
        'node_id' => $parent->node_id, 'input' => $input,
        'status' => $status, 'result' => $result]);
    $snapshot = ['intent' => ['key' => $key, 'kind' => $kind, 'node_id' => $parent->node_id, 'input' => $input],
        'admitted' => ['parent_operation_id' => $parent->id, 'key' => $key, 'kind' => $kind, 'node_id' => $parent->node_id,
            'target_type' => 'instance', 'target_id' => $parent->target_id, 'input' => $input, 'run_constraints' => []]];
    $child->forceFill(['parent_operation_id' => $parent->id, 'child_key' => $key, 'child_admission' => $snapshot, 'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();

    return $child;
}

beforeEach(function (): void {
    $project = Project::query()->create(['name' => 'Cleanup proof', 'slug' => 'cleanup-proof', 'root' => '.',
        'repository_url' => 'https://github.com/example/cleanup-proof.git']);
    $node = Node::query()->create(['name' => 'cleanup-dev', 'public_ssh_host' => '192.0.2.10', 'user' => 'orbit', 'status' => 'active']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'proof',
        'checkout_path' => '/srv/apps/cleanup-proof/proof', 'status' => 'active']);
    $this->parent = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $node->id, 'status' => 'running', 'input' => ['instance_id' => $this->instance->id, 'initial_status' => 'reserved']]);
});

it('does not authorize setup rollback from a posted failed or deadline outcome', function (string $status, string $claimed): void {
    preparationListChild($this->parent, 'setup', status: $status, result: ['outcome' => $claimed]);
    preparationListChild($this->parent, 'rollback-teardown', status: 'succeeded', result: ['outcome' => 'succeeded']);

    expect(app(InstanceLifecycleChildren::class)->rollbackSetup($this->parent))->toBeFalse();
    expect(app(InstanceLifecycleChildren::class)->canRemoveFailedCreate($this->parent))->toBeFalse();
    $this->assertModelExists($this->instance);
})->with([['failed', 'failed'], ['failed', 'deadline'], ['cancelled', 'failed'], ['cancelled', 'deadline']]);

it('requires a step-list kind before classifying a keyed child outcome', function (): void {
    preparationListChild($this->parent, 'setup', 'route.update', 'succeeded', ['outcome' => 'failed']);

    expect(fn () => app(InstanceLifecycleChildren::class)->stepOutcome($this->parent, 'setup'))->toThrow(ResourceOperationException::class);
});

it('takes setup failure classification from the proven transport receipt instead of the terminal body', function (array $receipt, string $expected): void {
    $child = preparationListChild($this->parent, 'setup', result: ['outcome' => 'failed']);
    $child->update(['state' => ['executor_runs' => ['setup.install' => ['transport' => 'agent', 'name' => 'instance.run-lifecycle-step',
        'step' => 'setup.install', 'input' => ['step' => 'install'], 'started' => true, 'result' => $receipt]]]]);
    $child->steps()->create(['name' => 'setup.install', 'position' => 1, 'sequence' => 1, 'status' => 'failed', 'resumable' => false]);

    expect(app(InstanceLifecycleChildren::class)->stepOutcome($this->parent, 'setup')['outcome'])->toBe($expected);
    expect(app(InstanceLifecycleChildren::class)->rollbackSetup($this->parent))->toBe(in_array($expected, ['failed', 'missing', 'deadline'], true));
})->with([
    'failed command' => [['exit_code' => 1, 'timed_out' => false], 'failed'],
    'busy command' => [['exit_code' => 75, 'timed_out' => false], 'busy'],
    'missing command' => [['exit_code' => 127, 'timed_out' => false], 'missing'],
    'bounded deadline' => [['exit_code' => 124, 'timed_out' => true, 'budget_seconds' => 10, 'timeout_seconds' => 30], 'deadline'],
    'unconfirmed receipt' => [['error_code' => 'operation.execution_uncertain'], 'unconfirmed'],
]);

it('does not use a mismatched or unfinished run as failure proof', function (string $change): void {
    $child = preparationListChild($this->parent, 'setup', result: ['outcome' => 'deadline']);
    $run = ['transport' => 'agent', 'name' => 'instance.run-lifecycle-step', 'step' => 'setup.install',
        'input' => ['step' => 'install'], 'started' => true, 'result' => ['exit_code' => 1, 'timed_out' => false]];
    match ($change) {
        'transport' => $run['transport'] = 'gateway', 'name' => $run['name'] = 'process.control',
        'step' => $run['step'] = 'setup.other', 'unconfirmed' => $run['result'] = null,
        'missing-step' => null,
    };
    $child->update(['state' => ['executor_runs' => ['setup.install' => $run]]]);
    if ($change !== 'missing-step') {
        $child->steps()->create(['name' => 'setup.install', 'position' => 1, 'sequence' => 1, 'status' => 'failed', 'resumable' => false]);
    }

    expect(app(InstanceLifecycleChildren::class)->stepOutcome($this->parent, 'setup')['outcome'])->toBe('unconfirmed');
})->with(['transport', 'name', 'step', 'unconfirmed', 'missing-step']);

it('retains source while any child is nonterminal even after a confirmed refusal', function (string $status): void {
    $this->parent->update(['state' => ['executor_runs' => ['source-prepare' => ['result' => ['error_code' => 'instance.source_step_failed']]]]]);
    preparationListChild($this->parent, 'setup', status: $status);

    expect(app(InstanceLifecycleChildren::class)->canRemoveFailedCreate($this->parent))->toBeFalse();
    $this->assertModelExists($this->instance);
})->with(['queued', 'running', 'waiting']);

it('requires proven teardown success before removing a failed setup checkout', function (): void {
    $setup = preparationListChild($this->parent, 'setup');
    $setup->update(['state' => ['executor_runs' => ['setup.install' => ['transport' => 'agent', 'name' => 'instance.run-lifecycle-step',
        'step' => 'setup.install', 'input' => ['step' => 'install'], 'started' => true, 'result' => ['exit_code' => 1, 'timed_out' => false]]]]]);
    $setup->steps()->create(['name' => 'setup.install', 'position' => 1, 'sequence' => 1, 'status' => 'failed', 'resumable' => false]);
    $teardown = preparationListChild($this->parent, 'rollback-teardown', status: 'succeeded', result: ['outcome' => 'succeeded']);
    $policy = app(InstanceLifecycleChildren::class);
    expect($policy->canRemoveFailedCreate($this->parent))->toBeFalse();
    $teardown->update(['state' => ['executor_runs' => ['lifecycle.receipt' => ['transport' => 'gateway', 'name' => 'instance.step-list-receipt',
        'step' => 'lifecycle_receipt', 'result' => ['instance_id' => $this->instance->id, 'outcome' => 'succeeded']]]]]);
    $teardown->steps()->create(['name' => 'lifecycle_receipt', 'position' => 1, 'sequence' => 1, 'status' => 'succeeded', 'resumable' => true]);
    expect($policy->canRemoveFailedCreate($this->parent))->toBeTrue();
});

it('retains checkout after refused environment synchronization while setup is still running', function (): void {
    $environment = preparationListChild($this->parent, 'environment-sync', 'instance.sync_environment');
    $environment->update(['error_code' => 'instance.environment_sync_failed']);
    $setup = preparationListChild($this->parent, 'setup', status: 'running');
    $policy = app(InstanceLifecycleChildren::class);
    expect($policy->canRemoveFailedCreate($this->parent))->toBeFalse();
    $setup->update(['status' => 'cancelled']);
    expect($policy->canRemoveFailedCreate($this->parent))->toBeFalse();
});

it('authorizes source cleanup only for explicitly known confirmed refusal codes', function (): void {
    $policy = app(InstanceLifecycleChildren::class);
    expect($policy->confirmedRefusal(['error_code' => 'instance.source_step_failed']))->toBeTrue();
    expect($policy->confirmedRefusal(['error_code' => 'future.unknown_failure']))->toBeFalse();
    expect($policy->confirmedRefusal(['error_code' => 'instance.source_step_failed', 'outcome' => 'unconfirmed']))->toBeFalse();
});

it('refuses a Gateway state transition for another Operation id before projection', function (): void {
    $command = app(InstanceLifecycleCommand::class);
    expect(fn () => $command->run($this->parent, ['action' => 'configuration', 'operation_id' => $this->parent->id + 1]))
        ->toThrow(ResourceOperationException::class);
    expect($command->run($this->parent, ['action' => 'configuration', 'operation_id' => $this->parent->id]))->toBe(['written' => true]);
    expect($this->parent->refresh()->state)->toBeNull();
});
