<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\DatabaseServers\DatabaseOperationExecution;
use App\Domain\Instances\InstanceDatabaseIntent;
use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Instances\RemovalLifecycleExecution;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Instance;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;

require_once __DIR__.'/../../Support/ServerDatabaseFixtures.php';
require_once __DIR__.'/../../Support/DatabaseCreationProofs.php';

uses(ServerDatabaseFixtures::class);

/** Earlier lifecycle work is already proven; these tests exercise the actual database owner and B boundary. */
function ownedRemovalPrerequisites(Operation $parent, Instance $member): void
{
    foreach (['removal-accept', 'removal-'.$member->id.'-runtime-intent'] as $key) {
        ownedRemovalCheckpoint($parent, $key);
    }
    foreach (['cleanup-instance-' => 'route.cleanup_instance'] as $prefix => $kind) {
        $key = $prefix.$member->id;
        $input = ['instance_id' => $member->id];
        $child = Operation::query()->create(['kind' => $kind, 'target_type' => 'instance', 'target_id' => $member->id,
            'node_id' => $member->node_id, 'input' => $input, 'status' => 'succeeded']);
        $snapshot = ['intent' => ['key' => $key, 'kind' => $kind, 'node_id' => $member->node_id, 'input' => $input],
            'admitted' => ['parent_operation_id' => $parent->id, 'key' => $key, 'kind' => $kind, 'node_id' => $member->node_id,
                'target_type' => 'instance', 'target_id' => $member->id, 'input' => $input, 'run_constraints' => []]];
        $child->forceFill(['parent_operation_id' => $parent->id, 'child_key' => $key, 'child_admission' => $snapshot,
            'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();
    }
}

function ownedRemovalCheckpoint(Operation $parent, string $key): void
{
    $execution = app(InstanceLifecycleExecution::class);
    $descriptor = $execution->descriptor($key, 'gateway', 'instance.lifecycle-state', ['action' => 'configuration', 'operation_id' => $parent->id]);
    $state = $parent->state ?? [];
    $state['lifecycle_bindings'][$key] = $descriptor;
    $state['executor_runs'][$key] = [...$descriptor, 'result' => str_ends_with($key, '-runtime-intent') ? ['process_ids' => [], 'schedule_ids' => []] : ['ready' => true]];
    $parent->update(['state' => $state]);
    $parent->steps()->firstOrCreate(['name' => $key], ['position' => $parent->steps()->count() + 1, 'sequence' => 1, 'status' => 'succeeded', 'resumable' => true]);
}

beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    $this->instance->update(['node_id' => $this->gateway->id]);
    $this->parent = app(StartOperationAction::class)->execute('instance.destroy', 'instance', $this->instance->id, $this->instance->node_id,
        ['instance_id' => $this->instance->id, 'project_id' => $this->instance->project_id, 'additional_route_ids' => [],
            'members' => [['instance_id' => $this->instance->id, 'node_id' => $this->instance->node_id, 'route_id' => null,
                'run_teardown' => false, 'row_deleted_at' => null]]], null, null);
    $this->executor = app(SwampOperationExecutor::class);
    $this->parent = $this->executor->claim('instance.destroy', 'removal-parent', $this->gateway->id, $this->parent->id);
    ownedRemovalPrerequisites($this->parent, $this->instance);
    $this->databaseRun = function (Operation $operation, string $worker): array {
        $operation = $this->executor->claim($operation->kind, $worker, $this->gateway->id, $operation->id);
        foreach (app(DatabaseOperationExecution::class)->context($operation)['descriptors'] as $descriptor) {
            $result = $this->executor->run($operation, ['worker' => $worker, 'key' => $descriptor['key'],
                'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'input' => $descriptor['input']], $this->gateway->id);
            $this->executor->step($operation, ['worker' => $worker, 'key' => $descriptor['key'].'.finish', 'name' => $descriptor['step'],
                'status' => 'succeeded', 'result' => $result], $this->gateway->id);
        }
        $this->executor->complete($operation, ['worker' => $worker, 'status' => 'succeeded', 'result' => $result], $this->gateway->id);

        return $result;
    };
});

it('retains the member row while its real owned wrapper runs and proves empty or SQLite-only removal', function (bool $sqlite): void {
    $owned = $sqlite ? DatabaseConnection::query()->create(['slug' => 'owned-sqlite', 'driver' => 'sqlite', 'node_id' => $this->instance->node_id,
        'path' => $this->instance->checkout_path.'/database.sqlite', 'owner_instance_id' => $this->instance->id]) : null;
    $foreign = DatabaseConnection::query()->create(['slug' => 'foreign-sqlite', 'driver' => 'sqlite', 'node_id' => $this->instance->node_id,
        'path' => '/srv/foreign/database.sqlite']);
    $policy = app(InstanceDatabaseIntent::class);
    $key = 'remove-databases-'.$this->instance->id;
    expect($policy->remove($this->parent, $this->instance->id))->toBe(['database_required' => true]);
    ownedRemovalCheckpoint($this->parent, 'removal-'.$this->instance->id.'-database-intent');
    $descriptor = $policy->child($this->parent, $key);
    $intent = DatabaseExecutionIntent::recorded($descriptor['input']['intent_id']);
    expect($descriptor['input'])->toBe([...$intent->payload['admission']['input'], 'intent_id' => $intent->id]);
    expect($intent->parent_operation_id)->toBe($this->parent->id)->and($intent->target_id)->toBe($this->instance->id);
    expect($intent->payload['projection']['children'])->toBe([]);
    $count = DatabaseExecutionIntent::query()->count();
    $policy->remove($this->parent, $this->instance->id);
    expect($policy->child($this->parent, $key))->toBe($descriptor)->and(DatabaseExecutionIntent::query()->count())->toBe($count);
    $wrapper = $this->executor->child($this->parent, ['worker' => 'removal-parent', 'key' => $key], $this->gateway->id)['operation'];
    expect($wrapper->kind)->toBe('database.destroy_owned')->and($wrapper->input)->toBe($descriptor['input'])
        ->and($wrapper->target_id)->toBe($this->instance->id)->and($wrapper->node_id)->toBe($this->instance->node_id);
    $guard = fn () => app(RemovalLifecycleExecution::class)->beforeRun($this->parent, ['worker' => 'removal-parent',
        'key' => 'removal-'.$this->instance->id.'-row-delete', 'transport' => 'gateway', 'name' => 'instance.lifecycle-state', 'input' => []]);
    expect($guard)->toThrow(ResourceOperationException::class);
    $this->executor->wait($this->parent, 'removal-parent', [$key], $this->gateway->id);
    expect($this->parent->refresh()->worker)->toBeNull()->and($this->parent->lock_key)->not->toBeNull();
    $this->assertModelExists($this->instance);
    $runner = new FakeAgentCommandRunner()->answer('instance.copy-sqlite', []);
    app()->instance(AgentCommandRunner::class, $runner);
    expect(($this->databaseRun)($wrapper, 'database-wrapper'))->toBe(['backend' => 'absent']);
    $this->parent = $this->executor->claim('instance.destroy', 'resumed-removal', $this->gateway->id, $this->parent->id);
    $guard();
    if ($owned !== null) {
        $this->assertModelMissing($owned);
    }
    $this->assertModelExists($foreign);
    $this->assertModelExists($this->instance);
})->with(['empty' => [false], 'SQLite-only' => [true]]);

it('binds each removal member wrapper and its per-server child to the database owners recorded targets and Nodes', function (): void {
    $other = $this->instance->replicate();
    $other->name = 'other-member';
    $other->checkout_path = '/srv/orbit/dlf/other-member';
    $other->save();
    $this->parent->update(['input' => [...$this->parent->input, 'members' => [...$this->parent->input['members'],
        ['instance_id' => $other->id, 'node_id' => $other->node_id, 'route_id' => null, 'run_teardown' => false, 'row_deleted_at' => null]]]]);
    ownedRemovalPrerequisites($this->parent, $other);
    foreach ([$this->instance, $other] as $member) {
        $owned = DatabaseConnection::query()->create(['slug' => 'owned-'.$member->id, 'driver' => 'mysql', 'database_server_id' => $this->server->id,
            'database' => 'owned_'.$member->id, 'username' => 'owned_user_'.$member->id, 'owner_instance_id' => $member->id]);
        databaseCreatedProof($owned);
        $policy = app(InstanceDatabaseIntent::class);
        $policy->remove($this->parent, $member->id);
        $key = 'remove-databases-'.$member->id;
        ownedRemovalCheckpoint($this->parent, 'removal-'.$member->id.'-database-intent');
        $descriptor = $policy->child($this->parent, $key);
        $wrapper = $this->executor->child($this->parent, ['worker' => 'removal-parent', 'key' => $key], $this->gateway->id)['operation'];
        expect($wrapper->target_id)->toBe($member->id)->and($wrapper->node_id)->toBe($member->node_id)->and($wrapper->input)->toBe($descriptor['input']);
        $wrapper = $this->executor->claim('database.destroy_owned', 'wrapper-'.$member->id, $this->gateway->id, $wrapper->id);
        $nested = app(DatabaseOperationExecution::class)->childContext($wrapper)['children'][0];
        $sql = $this->executor->child($wrapper, ['worker' => 'wrapper-'.$member->id, 'key' => $nested['key']], $this->gateway->id)['operation'];
        expect($sql->kind)->toBe('database.destroy')->and($sql->target_id)->toBe($member->id)
            ->and($sql->input)->toBe($nested['input'])->and($sql->node_id)->toBe($this->server->node_id)->not->toBe($wrapper->node_id);
        $this->executor->wait($wrapper, 'wrapper-'.$member->id, [$nested['key']], $this->gateway->id);
        expect($wrapper->refresh()->worker)->toBeNull();
        $this->assertModelExists($member);
        $this->assertModelExists($owned);
    }
});
