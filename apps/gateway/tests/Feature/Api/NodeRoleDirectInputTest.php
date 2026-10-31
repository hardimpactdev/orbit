<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsFleetCapture;
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

it('records the owning unchanged Metrics comparison for a database addition while app-dev remains', function (): void {
    FleetFixtures::bind();
    FleetFixtures::node('metrics', [RoleName::Metrics]);
    FleetFixtures::node('vpn', [RoleName::Vpn]);
    $node = FleetFixtures::node('role-native', [RoleName::AppDev]);
    $before = app(MetricsFleetCapture::class)->capture();
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => 'active']);

    $input = app(NodeRoleDirectInput::class)->capture($node->refresh(), $assignment, true, $before);

    expect($input['metrics_impact']['required'])->toBeFalse()
        ->and($input['metrics_impact']['before_sha256'])->toBe($input['metrics_impact']['after_sha256']);
});

it('requires the complete pre-transition Metrics capture instead of inventing unchanged proof', function (): void {
    FleetFixtures::bind();
    FleetFixtures::node('metrics', [RoleName::Metrics]);
    $node = FleetFixtures::node('role-native', [RoleName::Database]);

    expect(fn () => app(NodeRoleDirectInput::class)->capture($node, $node->roles->sole(), false))
        ->toThrow(ResourceOperationException::class);
});

function boundRoleRoot(string $kind = 'node.role_add'): Operation
{
    FleetFixtures::bind();
    $node = FleetFixtures::node('role-owner', [RoleName::Database]);
    $assignment = $node->roles->sole();
    $snapshot = app(NodeRoleDirectInput::class)->capture($node, $assignment, false);

    return new Operation(['kind' => $kind, 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'running', 'parent_operation_id' => null, 'input' => ['node_id' => $node->id, 'role' => 'database', '_role' => $snapshot],
        'state' => ['node_role_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
            'role_intent' => NodeRoleIntent::capture($assignment, false)]]);
}

it('binds only a standalone native role root to its original Node and assignment', function (string $kind): void {
    $operation = boundRoleRoot($kind);
    expect(NodeRoleDirectInput::ownerId($operation))->toBe($operation->node_id)
        ->and(NodeRoleDirectInput::bound($operation))->toBe($operation->input['_role']);
})->with(['node.role_add', 'node.role_remove']);

it('refuses an unbound foreign stale parent or polled role root', function (string $mismatch): void {
    $operation = boundRoleRoot();
    $input = $operation->input;
    $state = $operation->state;
    match ($mismatch) {
        'kind' => $operation->kind = 'node.role_converge',
        'parent' => $operation->parent_operation_id = 1,
        'polled' => $state['node_role_writer'] = 'polled',
        'unbound' => $state['swamp_run_id'] = null,
        'terminal' => $operation->status = 'succeeded',
        'target' => $operation->target_id = $operation->node_id + 1,
        'snapshot_node' => $input['_role']['node_id']++,
        'footprint_node' => $input['_role']['footprint']['node_id']++,
        'pin' => $input['_role']['footprint']['agent_sha256'] = str_repeat('b', 64),
        'assignment' => $input['_role']['assignment_id']++,
        'role' => $input['role'] = 'ci-runner',
        'created' => $state['role_intent']['created'] = true,
        'private_ref' => $input['_role']['footprint']['secret_hash'] = ['$secret' => 'node.agent_secret_hash', 'id' => $operation->node_id],
        'malformed' => $input['_role'] = 'invalid',
    };
    $operation->input = $input;
    $operation->state = $state;
    expect(NodeRoleDirectInput::ownerId($operation))->toBeNull();
})->with(['kind', 'parent', 'polled', 'unbound', 'terminal', 'target', 'snapshot_node', 'footprint_node', 'pin', 'assignment', 'role', 'created', 'private_ref', 'malformed']);

it('refuses a role assignment transferred away after admission', function (): void {
    $operation = boundRoleRoot();
    $foreign = FleetFixtures::node('foreign');
    NodeRole::query()->whereKey($operation->input['_role']['assignment_id'])->update(['node_id' => $foreign->id]);
    expect(NodeRoleDirectInput::ownerId($operation))->toBeNull();
});
