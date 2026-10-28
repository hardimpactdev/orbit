<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleIntent;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\NodeSettingsOperationCompletion;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), array_fill_keys(['node.role_add', 'node.role_remove', 'node.role_relocate'], [
        'executor' => 'swamp', 'completion' => NodeSettingsOperationCompletion::class,
        'runs' => [], 'completion_contract' => ['step' => 'intent-only', 'transport' => 'gateway', 'name' => 'intent-only', 'result' => []],
        'result_fields' => ['error_code'],
    ])));
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

it('records removal as an existing assignment and retains it for cleanup proof', function (): void {
    $node = FleetFixtures::node('role-intent-removal', [RoleName::WebSocket]);
    $assignment = $node->roles->sole();

    $operation = app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::WebSocket, true);

    expect($operation)->toBeInstanceOf(Operation::class);
    expect($operation->state['role_intent'])->toMatchArray(['assignment_id' => $assignment->id, 'created' => false, 'source_node_id' => $node->id]);
    expect($assignment->refresh()->status)->toBe(LifecycleStatus::Removing);
    expect($operation->state['role_dependencies'])->toBe(['instance_ids' => [], 'workspace_ids' => [], 'process_ids' => [], 'summaries' => []]);
});

it('probes offline belief before retaining local role effects', function (bool $unreachable): void {
    $node = FleetFixtures::node('role-intent-offline', [RoleName::WebSocket]);
    app()->instance(NodeReachabilityProbe::class, new class($unreachable) implements NodeReachabilityProbe
    {
        public function __construct(private bool $unreachable) {}

        public function degradation(Node $node): ?ExporterDegradationReason
        {
            return $this->unreachable ? ExporterDegradationReason::Unreachable : null;
        }
    });

    $operation = app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::WebSocket, true, false, true);

    expect($operation->state['role_offline'])->toBe($unreachable);
    expect($operation->state['role_intent']['created'])->toBeFalse();
    expect($operation->input['offline'])->toBeTrue();
})->with([false, true]);

it('records the original assignment before relocation and keeps that evidence after transfer replay', function (): void {
    $source = FleetFixtures::node('role-intent-admit-source', [RoleName::WebSocket]);
    $target = FleetFixtures::node('role-intent-admit-target');
    $assignment = $source->roles->sole();
    $identity = app(OperationIdempotency::class);
    $identity->kind = 'node.role_relocate';
    $identity->key = 'retained-relocation';
    $identity->fingerprint = 'retained-relocation-fingerprint';
    $operation = app(AdmitNodeRoleOperationAction::class)->relocate($target, RoleName::WebSocket, true, $source);
    expect($assignment->refresh()->node_id)->toBe($source->id);
    expect($assignment->status)->toBe(LifecycleStatus::Active);
    $assignment->update(['node_id' => $target->id]);

    $replayed = app(AdmitNodeRoleOperationAction::class)->relocate($target, RoleName::WebSocket, true, $source);

    expect($replayed->id)->toBe($operation->id);
    expect($replayed->state['role_intent'])->toBe([
        'assignment_id' => $assignment->id, 'created' => false, 'role' => 'websocket', 'source_node_id' => $source->id, 'target_node_id' => $target->id,
        'role_configuration' => ['cluster_id' => null, 'source_settings' => ['scope_type' => 'node', 'scope_id' => $source->id],
            'target_settings' => ['scope_type' => 'node', 'scope_id' => $target->id]],
    ]);
});

it('refuses unconsented removal before changing a role or recording an Operation', function (): void {
    $node = FleetFixtures::node('role-intent-remove-consent', [RoleName::WebSocket]);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::WebSocket))->toThrow(NodeRoleValidationException::class);

    expect(Operation::query()->where('node_id', $node->id)->count())->toBe(0);
    expect($node->roles()->sole()->status)->toBe(LifecycleStatus::Active);
});

it('returns a queued role Operation over HTTP and replays before changing the assignment again', function (): void {
    $gateway = FleetFixtures::node('role-http-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('role-http-target', [RoleName::AppDev]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $headers = ['Idempotency-Key' => 'role-http-new'];

    $response = $this->postJson("/api/v1/nodes/{$node->id}/roles", ['role' => 'websocket', 'converge_existing' => false], $headers)
        ->assertAccepted()->assertJsonPath('data.kind', 'node.role_add')->assertJsonPath('data.status', 'queued');
    $id = $response->json('data.id');
    $this->postJson("/api/v1/nodes/{$node->id}/roles", ['role' => 'websocket', 'converge_existing' => false], $headers)
        ->assertAccepted()->assertJsonPath('data.id', $id);

    expect(Operation::query()->where('kind', 'node.role_add')->count())->toBe(1);
    expect($node->roles()->where('role', RoleName::WebSocket)->sole()->status)->toBe(LifecycleStatus::Provisioning);
});

it('records role removal over HTTP without awaiting teardown or deleting its assignment', function (): void {
    $gateway = FleetFixtures::node('remove-http-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('remove-http-target', [RoleName::WebSocket]);
    $assignment = $node->roles->sole();
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);

    $this->deleteJson("/api/v1/nodes/{$node->id}/roles/websocket", ['force' => true, 'purge_data' => false, 'offline' => false])
        ->assertAccepted()->assertJsonPath('data.kind', 'node.role_remove')->assertJsonPath('data.status', 'queued');

    expect($assignment->refresh()->status)->toBe(LifecycleStatus::Removing);
    $this->assertModelExists($assignment);
});

it('records relocation over HTTP while the original source remains serving', function (): void {
    $gateway = FleetFixtures::node('relocate-http-gateway', [RoleName::Gateway]);
    $source = FleetFixtures::node('relocate-http-source', [RoleName::WebSocket]);
    $target = FleetFixtures::node('relocate-http-target');
    $assignment = $source->roles->sole();
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);

    $this->postJson("/api/v1/nodes/{$target->id}/roles/websocket/relocate", ['force' => true, 'from' => $source->id])
        ->assertAccepted()->assertJsonPath('data.kind', 'node.role_relocate')->assertJsonPath('data.status', 'queued');

    expect($assignment->refresh()->node_id)->toBe($source->id);
    expect($assignment->status)->toBe(LifecycleStatus::Active);
});

it('admits provisioning role children without a second first-role network transition', function (): void {
    $node = FleetFixtures::node('role-provisioning-child', status: LifecycleStatus::Provisioning);

    $operation = app(AdmitNodeRoleOperationAction::class)->add($node, RoleName::AppDev, true, duringProvisioning: true);

    expect($operation->state['role_first_managed'])->toBeFalse();
    expect($operation->state['role_intent'])->toMatchArray(['created' => true, 'source_node_id' => $node->id, 'role' => 'app-dev']);
});
