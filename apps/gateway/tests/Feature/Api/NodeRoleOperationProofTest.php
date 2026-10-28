<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleIntent;
use App\Domain\Nodes\NodeRoleOperationCompletion;
use App\Domain\Nodes\NodeRoleOperationExecution;
use App\Domain\Nodes\PrepareNodeRoleCommand;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

function roleProofOperation(bool $created = false, bool $optional = false): Operation
{
    $node = FleetFixtures::node('role-proof', [RoleName::WebSocket]);
    $assignment = $node->roles->sole();
    $intent = NodeRoleIntent::capture($assignment, $created);
    $assignment->claimConvergence();
    $plan = [['type' => 'child', 'key' => 'role-runtime', 'kind' => 'websocket.role_converge', 'node_id' => $node->id, 'input' => [], 'optional' => false]];
    if ($optional) {
        $plan[] = ['type' => 'child', 'key' => 'role-cli', 'kind' => 'fleet.cli_converge', 'node_id' => $node->id, 'input' => [], 'optional' => true];
    }
    $children = array_map(static fn (array $step): array => array_intersect_key($step, array_flip(['key', 'kind', 'node_id', 'input'])), $plan);

    return Operation::query()->create(['kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => ['role' => 'websocket', 'converge' => ! $created, '_role_plan' => $plan],
        'state' => ['role_intent' => $intent, 'node_role_prepared' => true, 'child_intents' => $children], 'status' => 'running', 'sequence' => 1, 'attempts' => 1]);
}

function roleProofChild(Operation $parent, string $key, string $status = 'succeeded', ?string $error = null): Operation
{
    $intent = array_find($parent->state['child_intents'], static fn (array $intent): bool => $intent['key'] === $key);
    $identity = ['parent_operation_id' => $parent->id, 'key' => $key, 'kind' => $intent['kind'], 'node_id' => $intent['node_id'],
        'target_type' => 'node', 'target_id' => $intent['node_id'], 'input' => $intent['input'], 'run_constraints' => []];
    $snapshot = ['intent' => $intent, 'admitted' => $identity];
    $child = new Operation;
    $child->forceFill(['kind' => $intent['kind'], 'node_id' => $intent['node_id'], 'target_type' => 'node', 'target_id' => $intent['node_id'],
        'input' => $intent['input'], 'parent_operation_id' => $parent->id, 'child_key' => $key, 'child_admission' => $snapshot,
        'child_fingerprint' => OperationChildIntent::fingerprint($snapshot), 'run_constraints' => [],
        'status' => $status, 'error_code' => $error, 'result' => [], 'sequence' => 1, 'attempts' => 1])->save();

    return $child;
}

it('keeps admission-created evidence when completing a role from stamped child proof', function (bool $created): void {
    $operation = roleProofOperation($created);
    roleProofChild($operation, 'role-runtime');
    $execution = app(NodeRoleOperationExecution::class);
    $result = $execution->result($operation->fresh());

    app(NodeRoleOperationCompletion::class)->complete($operation, OperationOutcome::succeeded($result));

    expect($result)->toBe(['role_proven' => true, 'assignment_id' => $operation->state['role_intent']['assignment_id'], 'created' => $created]);
    $this->assertDatabaseHas('node_roles', ['id' => $operation->state['role_intent']['assignment_id'], 'status' => LifecycleStatus::Active->value]);
})->with([false, true]);

it('never activates a role from caller-posted steps without its child proof', function (): void {
    $operation = roleProofOperation();
    $operation->update(['step_results' => ['role-runtime' => ['role_proven' => true]]]);

    expect(fn () => app(NodeRoleOperationCompletion::class)->complete($operation, OperationOutcome::succeeded([
        'role_proven' => true, 'assignment_id' => $operation->state['role_intent']['assignment_id'], 'created' => false,
    ])))->toThrow(ResourceOperationException::class);

    $this->assertDatabaseHas('node_roles', ['id' => $operation->state['role_intent']['assignment_id'], 'status' => LifecycleStatus::Provisioning->value]);
});

it('refuses an altered created flag even after successful runtime publication', function (): void {
    $operation = roleProofOperation();
    roleProofChild($operation, 'role-runtime');
    $result = app(NodeRoleOperationExecution::class)->result($operation);
    $result['created'] = true;

    expect(fn () => app(NodeRoleOperationCompletion::class)->complete($operation, OperationOutcome::succeeded($result)))->toThrow(ResourceOperationException::class);
});

it('keeps an existing assignment after refused WebSocket publication', function (): void {
    $operation = roleProofOperation(optional: true);
    roleProofChild($operation, 'role-runtime', 'failed', 'websocket.publication_failed');
    $result = app(NodeRoleOperationExecution::class)->result($operation);

    app(NodeRoleOperationCompletion::class)->complete($operation, OperationOutcome::failed('websocket.publication_failed', $result));

    expect($result)->toMatchArray(['created' => false, 'error_code' => 'websocket.publication_failed', 'failed_step' => 'role-runtime']);
    $this->assertDatabaseHas('node_roles', ['id' => $operation->state['role_intent']['assignment_id'], 'node_id' => $operation->node_id, 'status' => 'failed']);
    expect($operation->children()->count())->toBe(1);
});

it('reports optional CLI refusal without failing a proven runtime', function (): void {
    $operation = roleProofOperation(optional: true);
    roleProofChild($operation, 'role-runtime');
    roleProofChild($operation, 'role-cli', 'failed', 'fleet.cli_unavailable');
    $result = app(NodeRoleOperationExecution::class)->result($operation);

    app(NodeRoleOperationCompletion::class)->complete($operation, OperationOutcome::succeeded($result));

    expect($result['follow_up'])->toBe('role-cli:fleet.cli_unavailable');
    $this->assertDatabaseHas('node_roles', ['id' => $operation->state['role_intent']['assignment_id'], 'status' => 'active']);
});

it('withholds later children until the runtime has settled successfully', function (): void {
    $operation = roleProofOperation(optional: true);
    $execution = app(NodeRoleOperationExecution::class);
    expect(array_column($execution->childContext($operation)['children'], 'key'))->toBe(['role-runtime']);
    roleProofChild($operation, 'role-runtime');

    expect(array_column($execution->childContext($operation)['children'], 'key'))->toBe(['role-runtime', 'role-cli']);
});

it('refuses a runtime child with an altered admission snapshot', function (): void {
    $operation = roleProofOperation();
    $child = roleProofChild($operation, 'role-runtime');
    $child->forceFill(['input' => ['created' => true]])->save();

    expect(fn () => app(NodeRoleOperationExecution::class)->result($operation))->toThrow(ResourceOperationException::class);
});

function roleRemovalProofOperation(): Operation
{
    $operation = roleProofOperation();
    $assignment = NodeRole::query()->findOrFail($operation->state['role_intent']['assignment_id']);
    $assignment->update(['status' => 'removing']);
    $step = ['type' => 'child', 'key' => 'role-runtime', 'kind' => 'websocket.role_remove', 'node_id' => $operation->node_id,
        'input' => ['purge_data' => false, 'offline' => false], 'optional' => false];
    $operation->update(['kind' => 'node.role_remove',
        'input' => ['role' => 'websocket', 'purge_data' => false, 'offline' => false, '_role_plan' => [$step]],
        'state' => [...$operation->state, 'child_intents' => [array_intersect_key($step, array_flip(['key', 'kind', 'node_id', 'input']))],
            'role_offline' => false, 'role_dependencies' => ['instance_ids' => [], 'workspace_ids' => [], 'process_ids' => [], 'summaries' => []]]]);
    app(SettingRepository::class)->put(new SettingScope(SettingScopeType::Node, $operation->node_id),
        'websocket.reverb.laravel_app_key', 'retained-role-credential', SettingValueProtection::Secret);

    return $operation;
}

it('finalizes removal and owned Settings only after the runtime child succeeds', function (): void {
    $operation = roleRemovalProofOperation();
    roleProofChild($operation, 'role-runtime');
    $result = app(NodeRoleOperationExecution::class)->result($operation);

    app(NodeRoleOperationCompletion::class)->complete($operation, OperationOutcome::succeeded($result));

    $this->assertDatabaseMissing('node_roles', ['id' => $operation->state['role_intent']['assignment_id']]);
    $this->assertDatabaseMissing('settings', ['scope_type' => 'node', 'scope_id' => $operation->node_id, 'key' => 'websocket.reverb.laravel_app_key']);
});

it('keeps assignment and credentials when removal or its proof fails', function (bool $failed): void {
    $operation = roleRemovalProofOperation();
    if ($failed) {
        roleProofChild($operation, 'role-runtime', 'failed', 'websocket.runtime_remove_failed');
        app(NodeRoleOperationCompletion::class)->complete($operation, OperationOutcome::failed('websocket.runtime_remove_failed',
            app(NodeRoleOperationExecution::class)->result($operation)));
        $this->assertDatabaseHas('node_roles', ['id' => $operation->state['role_intent']['assignment_id'], 'status' => 'failed', 'failed_step' => 'remove:role-runtime']);
    } else {
        expect(fn () => app(NodeRoleOperationCompletion::class)->complete($operation, OperationOutcome::succeeded([
            'role_proven' => true, 'assignment_id' => $operation->state['role_intent']['assignment_id'], 'created' => false,
        ])))->toThrow(ResourceOperationException::class);
        $this->assertDatabaseHas('node_roles', ['id' => $operation->state['role_intent']['assignment_id'], 'status' => 'removing']);
    }

    $this->assertDatabaseHas('settings', ['scope_type' => 'node', 'scope_id' => $operation->node_id, 'key' => 'websocket.reverb.laravel_app_key']);
})->with([false, true]);

it('refuses changed dependencies before removing an assignment or its Settings', function (): void {
    $operation = roleRemovalProofOperation();
    roleProofChild($operation, 'role-runtime');
    $inspector = Mockery::mock(NodeRoleDependencyInspector::class);
    $inspector->shouldReceive('inspect')->once()->andReturn(new NodeRoleDependencySet([123], [], [], []));
    app()->instance(NodeRoleDependencyInspector::class, $inspector);

    expect(fn () => app(NodeRoleOperationCompletion::class)->complete($operation, OperationOutcome::succeeded(app(NodeRoleOperationExecution::class)->result($operation))))
        ->toThrow(ResourceOperationException::class, 'dependencies changed');

    $this->assertDatabaseHas('node_roles', ['id' => $operation->state['role_intent']['assignment_id'], 'status' => 'removing']);
    $this->assertDatabaseHas('settings', ['scope_type' => 'node', 'scope_id' => $operation->node_id, 'key' => 'websocket.reverb.laravel_app_key']);
});

it('requires public SSH recovery only for the last online role outside Cluster ownership', function (string $variant): void {
    $operation = roleRemovalProofOperation();
    $node = Node::query()->findOrFail($operation->node_id);
    if ($variant === 'other-role') {
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'failed']);
    }
    $operation->update(['state' => [...$operation->state, 'node_role_prepared' => false,
        'role_offline' => $variant === 'offline', 'role_owned_by_parent' => $variant === 'cluster']]);

    app(PrepareNodeRoleCommand::class)->run($operation, ['role' => 'websocket', 'purge_data' => false, 'offline' => $variant === 'offline']);

    $plan = $operation->fresh()->input['_role_plan'];
    expect(in_array('role-public-ssh', array_column($plan, 'key'), true))->toBe($variant === 'last-online');
    if ($variant === 'last-online') {
        expect($plan[1])->toMatchArray(['name' => 'firewall.rules', 'result' => ['matches' => true], 'optional' => false]);
    }
})->with(['last-online', 'other-role', 'offline', 'cluster']);

function firstRoleNetworkProofOperation(): Operation
{
    $operation = roleProofOperation();
    $network = ['type' => 'child', 'key' => 'role-network', 'kind' => 'node.network_converge', 'node_id' => $operation->node_id,
        'input' => ['roleless_operator' => false], 'optional' => false];
    $commit = ['type' => 'run', 'key' => 'role-network-commit', 'transport' => 'agent', 'name' => 'wireguard.commit-peer',
        'input' => ['public_key' => base64_encode(str_repeat('a', 32))], 'result' => [], 'optional' => false];
    $plan = [$network, ...$operation->input['_role_plan'], $commit];
    $operation->update(['input' => [...$operation->input, '_role_plan' => $plan],
        'state' => [...$operation->state, 'role_first_managed' => true, 'child_intents' => [
            array_intersect_key($network, array_flip(['key', 'kind', 'node_id', 'input'])), ...$operation->state['child_intents'],
            ['key' => 'role-network-rollback', 'kind' => 'node.network_rollback', 'node_id' => $operation->node_id, 'input' => []]]]]);
    roleProofChild($operation, 'role-network')->update(['result' => ['network_proven' => true, 'retained' => true, 'public_key' => base64_encode(str_repeat('a', 32))]]);

    return $operation;
}

it('keeps a first-role failure open until its peer compensation is proven', function (bool $rollbackFails): void {
    $operation = firstRoleNetworkProofOperation();
    roleProofChild($operation, 'role-runtime', 'failed', 'websocket.publication_failed');
    $execution = app(NodeRoleOperationExecution::class);
    expect(array_column($execution->childContext($operation)['children'], 'kind'))->toContain('node.network_rollback');
    expect(fn () => $execution->result($operation))->toThrow(ResourceOperationException::class);
    expect(fn () => $execution->assertCompletion($operation, OperationOutcome::failed('websocket.publication_failed', [])))
        ->toThrow(ResourceOperationException::class);
    roleProofChild($operation, 'role-network-rollback', $rollbackFails ? 'failed' : 'succeeded', $rollbackFails ? 'wireguard.peer_rollback_failed' : null);

    $result = $execution->result($operation);

    expect($result)->toMatchArray(['error_code' => $rollbackFails ? 'vpn.peer_rollback_failed' : 'websocket.publication_failed',
        'failed_step' => $rollbackFails ? 'role-network-rollback' : 'role-runtime']);
})->with([false, true]);

it('projects cancellation from the compensation child without requiring another parent effect', function (): void {
    $operation = firstRoleNetworkProofOperation();
    $operation->update(['cancel_requested_at' => now()]);
    $execution = app(NodeRoleOperationExecution::class);
    expect($execution->context($operation)['result'])->toBe(['role_rollback_required' => true]);
    roleProofChild($operation, 'role-network-rollback');

    $result = $execution->context($operation)['result'];
    unset($result['role_rollback_required']);
    $execution->assertCompletion($operation, OperationOutcome::cancelled($result));

    expect($result)->toMatchArray(['role_proven' => true, 'error_code' => 'operation.cancelled', 'failed_step' => 'operation']);
});

it('does not restore a peer transaction already committed after successful runtime proof', function (): void {
    $operation = firstRoleNetworkProofOperation();
    roleProofChild($operation, 'role-runtime');
    $operation->update(['state' => [...$operation->state, 'executor_runs' => ['role-network-commit' => [
        'transport' => 'agent', 'name' => 'wireguard.commit-peer', 'result' => []]]], 'cancel_requested_at' => now()]);

    expect(array_column(app(NodeRoleOperationExecution::class)->childContext($operation)['children'], 'kind'))->not->toContain('node.network_rollback');
});

it('reports an occupied CI host as failure before removing its firewall', function (string $outcome): void {
    $operation = roleProofOperation();
    $operation->update(['input' => [...$operation->input, '_role_plan' => [['type' => 'run', 'key' => 'role-ci-runner',
        'transport' => 'agent', 'name' => 'ci-runner.host', 'input' => ['action' => 'remove'], 'result' => ['outcome' => 'retained'], 'optional' => false]]]]);

    expect(app(NodeRoleOperationExecution::class)->received($operation, ['key' => 'role-ci-runner', 'name' => 'ci-runner.host'], ['outcome' => $outcome]))
        ->toBe(['error_code' => 'ci-runner.host_failed']);
})->with(['occupied', 'drift', '']);
