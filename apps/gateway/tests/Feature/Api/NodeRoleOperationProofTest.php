<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRoleIntent;
use App\Domain\Nodes\NodeRoleOperationCompletion;
use App\Domain\Nodes\NodeRoleOperationExecution;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
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
