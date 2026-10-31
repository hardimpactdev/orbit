<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

it('captures a public role snapshot without account probes effects or private bytes', function (RoleName $role): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('role-native', [$role]);
    $node->forceFill(['agent_secret_hash' => str_repeat('e', 64)])->save();
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agents);
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldNotReceive('resolve');
    app()->instance(ManagedUserAccountResolver::class, $accounts);

    $input = app(NodeRoleDirectInput::class)->capture($node, $node->roles->sole(), false);

    expect($input['node_id'])->toBe($node->id)->and($input['role'])->toBe($role->value)
        ->and($input['footprint']['node_id'])->toBe($node->id)
        ->and($input['footprint'])->not->toHaveKey('secret_hash')
        ->and($input['prerequisites'])->not->toHaveKeys(['user', 'group', 'home'])
        ->and(json_encode($input, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('e', 64));
})->with([RoleName::Database, RoleName::CiRunner]);

it('refuses a foreign assignment before capturing its role payload', function (): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('owner', [RoleName::Database]);
    $foreign = FleetFixtures::node('foreign');
    expect(fn () => app(NodeRoleDirectInput::class)->capture($foreign, $node->roles->sole(), false))
        ->toThrow(ResourceOperationException::class);
});

function boundRoleRoot(string $kind = 'node.role_add'): Operation
{
    FleetFixtures::bind();
    $node = FleetFixtures::node('role-owner', [RoleName::Database]);
    $assignment = $node->roles->sole();
    $snapshot = app(NodeRoleDirectInput::class)->capture($node, $assignment, false);

    return new Operation(['kind' => $kind, 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'running', 'input' => ['node_id' => $node->id, 'role' => 'database', '_role' => $snapshot],
        'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
            'role_intent' => NodeRoleIntent::capture($assignment, false)]]);
}

it('binds only a standalone native role root to its original Node and assignment', function (string $kind): void {
    $operation = boundRoleRoot($kind);
    expect(NodeRoleDirectInput::ownerId($operation))->toBe($operation->node_id)
        ->and(NodeRoleDirectInput::bound($operation))->toBe($operation->input['_role']);
})->with(['node.role_add', 'node.role_converge']);

it('refuses a foreign stale or malformed role root', function (string $mismatch): void {
    $operation = boundRoleRoot();
    $input = $operation->input;
    $state = $operation->state;
    match ($mismatch) {
        'kind' => $operation->kind = 'node.converge',
        'remove' => $operation->kind = 'node.role_remove',
        'terminal' => $operation->status = 'succeeded',
        'target' => $operation->target_id = $operation->node_id + 1,
        'snapshot_node' => $input['_role']['node_id']++,
        'assignment' => $input['_role']['assignment_id']++,
        'malformed' => $input['_role'] = 'invalid',
    };
    $operation->input = $input;
    $operation->state = $state;
    expect(NodeRoleDirectInput::ownerId($operation))->toBeNull();
})->with(['kind', 'remove', 'terminal', 'target', 'snapshot_node', 'assignment', 'malformed']);

it('refuses a role assignment transferred away after admission', function (): void {
    $operation = boundRoleRoot();
    $foreign = FleetFixtures::node('foreign');
    NodeRole::query()->whereKey($operation->input['_role']['assignment_id'])->update(['node_id' => $foreign->id]);
    expect(NodeRoleDirectInput::ownerId($operation))->toBeNull();
});

it('captures app-dev storage and JavaScript intent for the native root', function (): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('unported', [RoleName::AppDev]);
    $snapshot = app(NodeRoleDirectInput::class)->capture($node, $node->roles->sole(), false);

    expect($snapshot['role'])->toBe('app-dev')
        ->and($snapshot['javascript'])->toBeArray()
        ->and($snapshot['managers'])->toHaveCount(2);
});
