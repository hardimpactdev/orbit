<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceDatabaseIntent;
use App\Domain\Instances\InstanceLifecycleCompletion;
use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Instances\InstanceLifecycleState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;

beforeEach(function (): void {
    $project = Project::query()->create(['name' => 'Lifecycle', 'slug' => 'lifecycle',
        'repository_url' => 'https://github.com/example/lifecycle.git', 'default_branch' => 'main', 'root' => 'public']);
    $node = Node::query()->create(['name' => 'dev', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10', 'user' => 'orbit']);
    NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::AppDev, 'status' => 'active']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'name' => 'feature', 'checkout_path' => '/srv/apps/lifecycle/feature', 'source_layout' => 'checkout',
        'status' => InstanceState::CheckoutPrepared, 'first_setup_pending' => true]);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'domain' => 'feature.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $this->operation = new Operation(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $node->id, 'input' => ['instance_id' => $this->instance->id, 'project_id' => $project->id,
            'name' => 'feature', 'default_branch' => 'main', 'branch_override' => null,
            'repository_identity' => $project->repository_identity, 'checkout' => $this->instance->checkout_path]]);
    $this->state = app(InstanceLifecycleState::class);
});

it('projects a resolved source exactly once and refuses a changed retained commit', function (): void {
    $receipt = ['branch' => 'feature', 'commit' => str_repeat('a', 40)];
    $this->state->resolved($this->operation, $receipt);
    $this->state->resolved($this->operation, $receipt);
    expect($this->instance->refresh()->status)->toBe(InstanceState::SourceResolved)
        ->and($this->instance->starting_commit)->toBe($receipt['commit']);
    expect(fn () => $this->state->resolved($this->operation, [...$receipt, 'commit' => str_repeat('b', 40)]))
        ->toThrow(ResourceOperationException::class);
    expect($this->instance->refresh()->starting_commit)->toBe($receipt['commit']);
});

it('rejects invalid branch and commit evidence without advancing the Instance', function (array $receipt): void {
    expect(fn () => $this->state->resolved($this->operation, $receipt))->toThrow(ResourceOperationException::class);
    expect($this->instance->refresh()->status)->toBe(InstanceState::CheckoutPrepared)
        ->and($this->instance->starting_commit)->toBeNull();
})->with([
    [['branch' => 'other', 'commit' => str_repeat('a', 40)]],
    [['branch' => 'feature', 'commit' => 'HEAD']],
    [['branch' => 'feature', 'commit' => str_repeat('A', 40)]],
]);

it('refuses an Operation for another Node before state projection', function (): void {
    $this->operation->node_id++;
    expect(fn () => $this->state->prepared($this->operation, ['created' => true]))->toThrow(ResourceOperationException::class);
    expect($this->instance->refresh()->status)->toBe(InstanceState::CheckoutPrepared);
});

it('returns only classified evidence and rejects a later profile change', function (): void {
    $this->state->resolved($this->operation, ['branch' => 'feature', 'commit' => str_repeat('a', 40)]);
    $receipt = ['kind' => 'composer', 'composer' => base64_encode(json_encode(['require' => ['php' => '^8.5', 'laravel/framework' => '^13.0']], JSON_THROW_ON_ERROR)), 'entry_point' => 'regular'];
    $result = $this->state->classified($this->operation, $receipt);
    expect(array_keys($result))->toBe(['php_version', 'laravel'])->and($result['laravel'])->toBeTrue()
        ->and($this->instance->refresh()->source_is_laravel)->toBeTrue();
    expect(fn () => $this->state->classified($this->operation, ['kind' => 'none']))->toThrow(ResourceOperationException::class);
    expect($this->instance->refresh()->source_is_laravel)->toBeTrue();
});

it('keeps setup pending at activation until its child result is proven', function (): void {
    $this->state->activated($this->operation, true);
    expect($this->instance->refresh()->status)->toBe(InstanceState::Active)
        ->and($this->instance->failed_step)->toBe('setup')->and($this->instance->first_setup_pending)->toBeTrue();
});

it('never records a renamed branch before a requested Route change is active', function (): void {
    $this->instance->update(['status' => InstanceState::Active, 'branch' => 'feature']);
    $this->operation->kind = 'instance.rename';
    $this->operation->input = [...$this->operation->input, 'branch' => 'renamed', 'domain' => 'renamed.test'];
    expect(fn () => $this->state->renamed($this->operation, ['source_verified' => true, 'branch' => 'renamed']))
        ->toThrow(ResourceOperationException::class);
    expect($this->instance->refresh()->branch)->toBe('feature')->and($this->instance->branch_override)->toBeNull();
});

it('keeps completed creation evidence when the development HEAD advances', function (): void {
    $this->instance->update(['status' => InstanceState::Active, 'branch' => 'feature', 'starting_commit' => str_repeat('a', 40),
        'first_setup_pending' => false, 'provisioning_step' => 'active']);
    $this->operation->input = [...$this->operation->input, 'initial_status' => 'active', 'first_setup_pending' => false];
    $before = $this->instance->refresh()->getAttributes();
    $result = app(InstanceLifecycleExecution::class)->received($this->operation,
        ['worker' => 'proof', 'key' => 'source-inspect', 'transport' => 'instance_lifecycle',
            'name' => 'instance.inspect-development-source', 'input' => []],
        ['branch' => 'feature', 'commit' => str_repeat('b', 40)]);
    expect($result)->toBe(['source_verified' => true, 'branch' => 'feature'])
        ->and($this->instance->refresh()->getAttributes())->toBe($before);
    app(InstanceLifecycleCompletion::class)->complete($this->operation,
        OperationOutcome::failed('instance.source_busy'));
    expect($this->instance->refresh()->getAttributes())->toBe($before);
});

it('refuses incomplete retained creation evidence even when source inspection succeeds', function (string $branch, ?string $commit): void {
    $this->instance->update(['status' => InstanceState::Active, 'branch' => $branch, 'starting_commit' => $commit]);
    expect(fn () => $this->state->retainedSource($this->operation))->toThrow(ResourceOperationException::class);
})->with([['other', str_repeat('a', 40)], ['feature', null], ['feature', 'HEAD']]);

it('preserves the owning database descriptor including its execution Node and complete input', function (bool $clone): void {
    $this->operation->input = [...$this->operation->input, 'database_clone_source_id' => $clone ? 386 : null];
    $descriptor = ['key' => 'database', 'kind' => $clone ? 'database.clone' : 'database.create',
        'node_id' => 991, 'input' => ['intent_id' => 901, 'owner_instance_id' => $this->instance->id, 'server_id' => 17]];
    $this->operation->state = ['lifecycle_database' => $descriptor];
    expect(app(InstanceDatabaseIntent::class)->child($this->operation, 'database'))->toBe($descriptor);
})->with([false, true]);

it('refuses a prepared database child with another owner or effect kind', function (string $change): void {
    $descriptor = ['key' => 'database', 'kind' => 'database.create', 'node_id' => 991,
        'input' => ['intent_id' => 901, 'owner_instance_id' => $this->instance->id]];
    match ($change) {
        'owner' => $descriptor['input']['owner_instance_id']++,
        'kind' => $descriptor['kind'] = 'database.destroy_owned',
        'intent' => $descriptor['input']['intent_id'] = '901',
    };
    $this->operation->state = ['lifecycle_database' => $descriptor];
    expect(fn () => app(InstanceDatabaseIntent::class)->child($this->operation, 'database'))->toThrow(ResourceOperationException::class);
})->with(['owner', 'kind', 'intent']);

it('pins a seeded worktree private branch to its admitted preparation identity', function (): void {
    $this->operation->input = [...$this->operation->input, 'prepare_id' => 'attempt-proof', 'seed_commit' => str_repeat('a', 40),
        'branch_override' => 'main'];
    $receipt = ['branch' => 'orbit/instance/attempt-proof', 'commit' => str_repeat('a', 40)];

    $this->state->resolved($this->operation, $receipt);
    expect($this->instance->refresh()->branch)->toBe($receipt['branch']);
    $this->instance->update(['status' => InstanceState::Active]);
    expect($this->state->retainedSource($this->operation)['branch'])->toBe($receipt['branch']);
    expect(fn () => $this->state->resolved($this->operation, [...$receipt, 'branch' => 'orbit/instance/another-attempt']))
        ->toThrow(ResourceOperationException::class);
});
