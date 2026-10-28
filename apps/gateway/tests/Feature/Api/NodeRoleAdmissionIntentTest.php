<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Nodes\NodeRoleIntent;
use App\Domain\Nodes\NodeSettingsOperationCompletion;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), ['node.role_add' => [
        'executor' => 'swamp', 'completion' => NodeSettingsOperationCompletion::class,
        'runs' => [], 'completion_contract' => ['step' => 'intent-only', 'transport' => 'gateway', 'name' => 'intent-only', 'result' => []],
        'result_fields' => ['error_code'],
    ]]));
});

it('retains explicit new-assignment evidence across a restart and status changes', function (): void {
    $node = FleetFixtures::node('role-intent-new', [RoleName::AppDev]);
    $identity = app(OperationIdempotency::class);
    $identity->kind = 'node.role_add';
    $identity->key = 'role-intent-new';
    $identity->fingerprint = 'new-role-fingerprint';
    $operation = app(AdmitNodeRoleOperationAction::class)->add($node, RoleName::WebSocket);
    $intent = $operation->state['role_intent'];
    $assignment = NodeRole::query()->findOrFail($intent['assignment_id']);
    $assignment->markConvergenceFailed('publish', 'websocket.publication_failed');
    $assignment->forceFill(['created_at' => now()->subYears(2), 'updated_at' => now()->subYears(1)])->save();

    $replayed = app(AdmitNodeRoleOperationAction::class)->add($node->fresh(), RoleName::WebSocket);

    expect($replayed->id)->toBe($operation->id);
    expect($replayed->state['role_intent'])->toBe([
        'assignment_id' => $assignment->id, 'created' => true, 'role' => 'websocket', 'source_node_id' => $node->id, 'target_node_id' => null,
        'role_configuration' => ['cluster_id' => null, 'source_settings' => ['scope_type' => 'node', 'scope_id' => $node->id], 'target_settings' => null],
    ]);
    expect(NodeRole::query()->where('node_id', $node->id)->where('role', RoleName::WebSocket)->count())->toBe(1);
});

it('never marks an existing assignment as created when claiming convergence', function (): void {
    $node = FleetFixtures::node('role-intent-existing', [RoleName::WebSocket]);
    $assignment = $node->roles->sole();
    $operation = app(AdmitNodeRoleOperationAction::class)->add($node, RoleName::WebSocket, true);

    expect($operation->fresh()->state['role_intent']['created'])->toBeFalse();
    expect($assignment->refresh()->status)->toBe(LifecycleStatus::Provisioning);
    expect($operation->state['role_intent']['assignment_id'])->toBe($assignment->id);
});

it('rolls back the Operation when a duplicate needs explicit convergence', function (): void {
    $node = FleetFixtures::node('role-intent-duplicate', [RoleName::WebSocket]);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($node, RoleName::WebSocket))->toThrow(RoleAssignmentException::class);

    expect(Operation::query()->where('node_id', $node->id)->count())->toBe(0);
    expect($node->roles()->sole()->status)->toBe(LifecycleStatus::Active);
});

it('leaves assignments unchanged when another Operation holds the Node', function (): void {
    $node = FleetFixtures::node('role-intent-held', [RoleName::WebSocket]);
    Operation::query()->create(['kind' => 'node.settings', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'lock_key' => 'node:'.$node->id, 'input' => [], 'status' => 'queued', 'sequence' => 1, 'attempts' => 0]);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($node, RoleName::WebSocket, true))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));

    expect($node->roles()->sole()->status)->toBe(LifecycleStatus::Active);
});

it('keeps the source assignment and Settings scopes after claim transfer', function (): void {
    $source = FleetFixtures::node('role-intent-source', [RoleName::WebSocket]);
    $target = FleetFixtures::node('role-intent-target');
    $assignment = $source->roles->sole();
    $intent = NodeRoleIntent::capture($assignment, false, $target);
    $operation = Operation::query()->create(['kind' => 'node.role_relocate', 'target_type' => 'node', 'target_id' => $target->id,
        'node_id' => $target->id, 'input' => [], 'state' => ['role_intent' => $intent], 'status' => 'queued', 'sequence' => 1, 'attempts' => 0]);
    $assignment->update(['node_id' => $target->id]);

    expect($operation->fresh()->state['role_intent'])->toBe([
        'assignment_id' => $assignment->id, 'created' => false, 'role' => 'websocket', 'source_node_id' => $source->id, 'target_node_id' => $target->id,
        'role_configuration' => ['cluster_id' => null, 'source_settings' => ['scope_type' => 'node', 'scope_id' => $source->id],
            'target_settings' => ['scope_type' => 'node', 'scope_id' => $target->id]],
    ]);
});
