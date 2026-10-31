<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceLifecycleChildren;
use App\Domain\Instances\InstanceLifecycleCommand;
use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationOutcome;
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

it('uses bound native list outcomes for parent rollback policy', function (string $outcome, string $code): void {
    $child = preparationListChild($this->parent, 'setup', result: ['instance_id' => $this->instance->id,
        'outcome' => $outcome, 'error_code' => $code, 'failed_step' => 'setup.install']);
    $child->update(['error_code' => $code, 'state' => ['instance_step_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    expect(app(InstanceLifecycleChildren::class)->stepOutcome($this->parent, 'setup')['outcome'])->toBe($outcome);
    expect(app(InstanceLifecycleChildren::class)->rollbackSetup($this->parent))->toBe(in_array($outcome, ['failed', 'missing', 'deadline'], true));
})->with([
    ['failed', 'instance.setup_step_failed'], ['busy', 'instance.lifecycle_busy'],
    ['missing', 'instance.setup_step_unavailable'], ['deadline', 'command.deadline_exceeded'],
    ['unconfirmed', 'instance.step_execution_unconfirmed'],
]);

it('refuses unbound or mismatched native outcomes as failure proof', function (string $change): void {
    $code = 'instance.setup_step_failed';
    $child = preparationListChild($this->parent, 'setup', result: ['instance_id' => $this->instance->id,
        'outcome' => 'failed', 'error_code' => $code, 'failed_step' => 'setup.install']);
    $child->update(['error_code' => $code, 'state' => ['instance_step_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    match ($change) {
        'unbound' => $child->update(['state' => ['instance_step_writer' => 'direct']]),
        'polled' => $child->update(['state' => []]),
        'running' => $child->update(['status' => 'running']),
        'foreign' => $child->update(['result' => [...$child->result, 'instance_id' => $child->target_id + 1]]),
        'error' => $child->update(['error_code' => 'different.failure']),
    };
    expect(app(InstanceLifecycleChildren::class)->stepOutcome($this->parent, 'setup')['outcome'] ?? null)->toBe($change === 'running' ? null : 'unconfirmed');
})->with(['unbound', 'polled', 'running', 'foreign', 'error']);

it('retains source while any child is nonterminal even after a confirmed refusal', function (string $status): void {
    $this->parent->update(['state' => ['executor_runs' => ['source-prepare' => ['result' => ['error_code' => 'instance.source_step_failed']]]]]);
    preparationListChild($this->parent, 'setup', status: $status);

    expect(app(InstanceLifecycleChildren::class)->canRemoveFailedCreate($this->parent))->toBeFalse();
    $this->assertModelExists($this->instance);
})->with(['queued', 'running', 'waiting']);

it('requires proven teardown success before removing a failed setup checkout', function (): void {
    $setup = preparationListChild($this->parent, 'setup');
    $setup->update(['error_code' => 'instance.setup_step_failed', 'result' => ['instance_id' => $this->instance->id, 'outcome' => 'failed', 'error_code' => 'instance.setup_step_failed', 'failed_step' => 'setup.install'], 'state' => ['instance_step_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);

    $teardown = preparationListChild($this->parent, 'rollback-teardown', status: 'succeeded', result: ['outcome' => 'succeeded']);
    $policy = app(InstanceLifecycleChildren::class);
    expect($policy->canRemoveFailedCreate($this->parent))->toBeFalse();
    $teardown->update(['result' => ['instance_id' => $this->instance->id, 'outcome' => 'succeeded', 'no_op' => true], 'state' => ['instance_step_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);

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

it('admits normal failed-create removal after a proven native Git refusal', function (): void {
    $this->parent->update(['state' => ['executor_runs' => ['source-resolve' => ['transport' => 'instance_lifecycle',
        'name' => 'instance.resolve-development-source', 'step' => 'source-resolve',
        'result' => ['error_code' => 'instance.git_failed']]]]]);

    expect(app(InstanceLifecycleChildren::class)->canRemoveFailedCreate($this->parent))->toBeTrue();
    app(InstanceLifecycleExecution::class)->assertCompletion($this->parent,
        OperationOutcome::failed('instance.git_failed'));
});
