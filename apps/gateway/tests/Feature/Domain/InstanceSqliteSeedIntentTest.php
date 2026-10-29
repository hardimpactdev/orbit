<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceSqliteSeedIntent;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseExecutionIntent;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), [
        ...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
    ]));
    $project = Project::query()->create(['name' => 'Seed proof', 'slug' => 'seed-proof', 'root' => '.', 'type' => 'monorepo',
        'repository_url' => 'https://github.com/example/seed-proof.git']);
    $source = Node::query()->create(['name' => 'seed-source', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.9']);
    $destination = Node::query()->create(['name' => 'seed-target', 'public_ssh_host' => '192.0.2.10', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.10']);
    $source->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $destination->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $this->candidate = Instance::query()->create(['project_id' => $project->id, 'node_id' => $source->id, 'name' => 'default',
        'checkout_path' => '/srv/apps/seed-proof/default', 'status' => 'active']);
    $this->target = Instance::query()->create(['project_id' => $project->id, 'node_id' => $destination->id, 'name' => 'preview',
        'checkout_path' => '/home/orbit-seed/releases/initial', 'production_home' => '/home/orbit-seed', 'production_user' => 'orbit-seed',
        'clone_candidate_id' => $this->candidate->id, 'source_is_laravel' => false, 'status' => InstanceState::SourceResolved,
        'provisioning_step' => 'clone-environment-synchronized']);
    $this->parent = Operation::query()->create(['kind' => 'instance.clone', 'node_id' => $destination->id, 'target_type' => 'instance',
        'target_id' => $this->target->id, 'status' => 'running', 'worker' => 'seed-parent', 'lock_key' => 'instance:'.$this->target->id,
        'input' => ['instance_id' => $this->target->id, 'project_id' => $project->id, 'checkout' => $this->target->checkout_path,
            'candidate_id' => $this->candidate->id, 'candidate_node_id' => $source->id, 'candidate_environment' => 'development',
            'candidate_path' => $this->candidate->checkout_path, 'candidate_user' => 'orbit', 'initial_completed' => false,
            'initial_provisioning_step' => 'clone-reserved', 'production_home' => $this->target->production_home,
            'production_user' => $this->target->production_user, 'sqlite_source_path' => $this->candidate->checkout_path.'/database.sqlite']]);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);
    $this->proof = function (string $key, array $result): void {
        $state = $this->parent->state ?? [];
        $descriptor = ['transport' => 'gateway', 'name' => 'instance.lifecycle-state', 'step' => $key, 'input' => []];
        $state['lifecycle_bindings'][$key] = $descriptor;
        $state['executor_runs'][$key] = [...$descriptor, 'result' => $result];
        $this->parent->update(['state' => $state]);
        $this->parent->steps()->create(['name' => $key, 'position' => $this->parent->steps()->count(), 'sequence' => 1,
            'status' => 'succeeded', 'resumable' => true]);
    };
});

it('records the database owner seed once with exact source and destination placements and no Node work', function (): void {
    $this->parent->update(['state' => ['executor_lease_node_id' => 17, 'executor_runs' => ['existing' => ['result' => ['ready' => true]]]]]);
    $seed = app(InstanceSqliteSeedIntent::class);
    expect($seed->prepareClone($this->parent))->toBe(['prepared' => true, 'seed_required' => true]);
    $first = $seed->cloneChild($this->parent->refresh());
    $seed->prepareClone($this->parent);
    expect($seed->cloneChild($this->parent->refresh()))->toBe($first);
    expect(DatabaseExecutionIntent::query()->count())->toBe(3);
    $intent = DatabaseExecutionIntent::query()->findOrFail($first['input']['intent_id']);
    expect($first)->toMatchArray(['key' => 'clone-sqlite', 'kind' => 'database.sqlite_seed', 'node_id' => $this->target->node_id]);
    expect($intent->target_type)->toBe('instance')->and($intent->target_id)->toBe($this->target->id);
    expect($intent->payload['commands']['prepare-target']['input'])->toMatchArray(['base' => '/home/orbit-seed', 'target_user' => 'orbit-seed']);
    expect(array_column($intent->payload['projection']['children'], 'node_id'))->toBe([$this->candidate->node_id, $this->candidate->node_id]);
    expect($this->parent->state['executor_lease_node_id'])->toBe(17);
    expect(DatabaseConnection::query()->count())->toBe(0)->and(DatabaseConnectionTarget::query()->count())->toBe(0);
});

it('admits the exact stored seed child and attaches only after that stamped child succeeds', function (): void {
    $seed = app(InstanceSqliteSeedIntent::class);
    $prepared = $seed->prepareClone($this->parent);
    ($this->proof)('clone-environment', ['environment_copied' => true]);
    ($this->proof)('clone-sqlite-intent', $prepared);
    $expected = $seed->cloneChild($this->parent);
    $child = DB::transaction(fn (): Operation => app(OperationChildren::class)->admit($this->parent, ['key' => 'clone-sqlite'])['operation']);
    expect($child->input)->toBe($expected['input'])->and($child->target_id)->toBe($this->target->id)
        ->and($child->parent_operation_id)->toBe($this->parent->id)->and($child->node_id)->toBe($this->target->node_id);
    expect(fn () => $seed->attachClone($this->parent))->toThrow(ResourceOperationException::class);
    expect(DatabaseConnectionTarget::query()->count())->toBe(0);

    $child->update(['status' => 'succeeded']);
    expect($seed->attachClone($this->parent))->toBe(['attached' => true]);
    expect($seed->attachClone($this->parent))->toBe(['attached' => true]);
    $connection = DatabaseConnection::query()->sole();
    expect($connection->owner_instance_id)->toBe($this->target->id)->and($connection->node_id)->toBe($this->target->node_id)
        ->and($connection->path)->toBe('/home/orbit-seed/database.sqlite');
    expect(DatabaseConnectionTarget::query()->sole()->database_connection_id)->toBe($connection->id);
    expect($this->target->refresh()->provisioning_step)->toBe('clone-sqlite-attached');
});

it('does not adopt an unrelated successful seed Operation as proof', function (): void {
    $seed = app(InstanceSqliteSeedIntent::class);
    $seed->prepareClone($this->parent);
    Operation::query()->create(['kind' => 'database.sqlite_seed', 'target_type' => 'instance', 'target_id' => $this->target->id,
        'node_id' => $this->target->node_id, 'input' => $seed->cloneChild($this->parent)['input'], 'status' => 'succeeded']);

    expect(fn () => $seed->attachClone($this->parent))->toThrow(ResourceOperationException::class);
    expect(DatabaseConnection::query()->count())->toBe(0);
});

it('retains a completed seed checkpoint without preparing another snapshot', function (): void {
    $this->parent->update(['input' => [...$this->parent->input, 'initial_provisioning_step' => 'clone-sqlite-prepared']]);
    $this->target->update(['provisioning_step' => 'clone-sqlite-prepared']);
    $seed = app(InstanceSqliteSeedIntent::class);

    expect($seed->prepareClone($this->parent))->toBe(['prepared' => true, 'seed_required' => false]);
    expect($seed->attachClone($this->parent))->toBe(['attached' => true]);
    expect(DatabaseExecutionIntent::query()->count())->toBe(0);
    expect(DatabaseConnectionTarget::query()->sole()->instance_id)->toBe($this->target->id);
});

it('refuses foreign or changed SQLite connection placement without replacing its attachment', function (string $change): void {
    $this->parent->update(['input' => [...$this->parent->input, 'initial_provisioning_step' => 'clone-sqlite-prepared']]);
    $attributes = ['slug' => 'seed-proof-preview', 'driver' => 'sqlite', 'owner_instance_id' => $this->target->id,
        'node_id' => $this->target->node_id, 'path' => '/home/orbit-seed/database.sqlite'];
    $attributes[$change] = match ($change) {
        'owner_instance_id' => $this->candidate->id,
        'node_id' => $this->candidate->node_id,
        'path' => '/home/orbit-seed/foreign.sqlite',
        'driver' => 'mysql',
    };
    $connection = DatabaseConnection::query()->create($attributes);

    expect(fn () => app(InstanceSqliteSeedIntent::class)->attachClone($this->parent))->toThrow(ResourceOperationException::class);
    expect(DatabaseConnection::query()->sole()->id)->toBe($connection->id)->and(DatabaseConnectionTarget::query()->count())->toBe(0);
})->with(['owner_instance_id', 'node_id', 'path', 'driver']);

it('refuses a changed candidate placement before preparing a database intent', function (): void {
    $other = Node::query()->create(['name' => 'other-source', 'public_ssh_host' => '192.0.2.11', 'user' => 'orbit']);
    $this->candidate->update(['node_id' => $other->id]);

    expect(fn () => app(InstanceSqliteSeedIntent::class)->prepareClone($this->parent))->toThrow(ResourceOperationException::class);
    expect(DatabaseExecutionIntent::query()->count())->toBe(0);
});
