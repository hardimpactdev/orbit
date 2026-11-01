<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\NodeRolePlacement;
use App\Domain\Nodes\NodeRoleRemovalProjection;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Nodes\NodeAgentHashSource;
use App\Models\Cluster;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    $this->gateway = FleetFixtures::node('lifecycle-gateway', [RoleName::Gateway]);
    $this->node = FleetFixtures::node('lifecycle-host', [RoleName::Database, RoleName::CiRunner]);
    $this->node->forceFill(['agent_secret_hash' => str_repeat('e', 64)])->save();
    config(['swamp_workers.nodes' => [$this->gateway->id, $this->node->id]]);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agents);
    DB::beginTransaction();
});
afterEach(function (): void {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

function roleLifecycleData(string $status, array $result = [], ?string $error = null): array
{
    return ['run_id' => '405c68f5-e089-46db-bbeb-03a458b1c6c4', 'status' => $status,
        'started_at' => '2026-10-31T10:30:00Z', 'finished_at' => '2026-10-31T10:31:00Z', 'error_code' => $error, 'result' => $result];
}

it('admits convergence with the same target snapshot and read-only hash custody', function (): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::Database, true);
    expect($operation->kind)->toBe('node.role_converge')->and(NodeRoleDirectInput::ownerId($operation))->toBe($this->node->id)
        ->and(app(NodeAgentHashSource::class)->ownerId($operation))->toBe($this->node->id);
    expect(fn () => app(NodeAgentHashSource::class)->store($operation, $this->node->id, str_repeat('a', 64)))->toThrow(ResourceOperationException::class);
});

it('keeps the removing assignment until native completion then deletes only that row once', function (): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->remove($this->node, RoleName::CiRunner, true);
    $assignment = $this->node->roles()->where('role', RoleName::CiRunner)->sole();
    expect($assignment->status->value)->toBe('removing')->and($operation->input)->not->toHaveKey('runtime_material');
    $operation->state = [...$operation->state, 'swamp_run_id' => roleLifecycleData('running')['run_id']];
    $operation->save();
    $projection = app(NodeRoleRemovalProjection::class);
    $projection->apply($operation, roleLifecycleData('running'));
    expect($assignment->fresh())->not->toBeNull();
    $projection->apply($operation, roleLifecycleData('succeeded', ['removed' => true]));
    expect($assignment->fresh())->toBeNull()->and($this->node->roles()->where('role', RoleName::Database)->exists())->toBeTrue();
    $sequence = $operation->refresh()->sequence;
    $projection->apply($operation, roleLifecycleData('succeeded', ['removed' => true]));
    expect($operation->refresh()->sequence)->toBe($sequence);
});

it('refuses offline assertions without false cleanup and retains failed removal rows', function (): void {
    expect(fn () => app(AdmitNodeRoleOperationAction::class)->remove($this->node, RoleName::Database, true, offline: true))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and($this->node->roles()->where('role', RoleName::Database)->sole()->status->value)->toBe('active');
    $operation = app(AdmitNodeRoleOperationAction::class)->remove($this->node, RoleName::CiRunner, true);
    $operation->state = [...$operation->state, 'swamp_run_id' => roleLifecycleData('running')['run_id']];
    $operation->save();
    app(NodeRoleRemovalProjection::class)->apply($operation, roleLifecycleData('failed', ['failed_step' => 'runtime', 'error_code' => 'role.runtime_failed'], 'role.runtime_failed'));
    expect($this->node->roles()->where('role', RoleName::CiRunner)->sole()->failed_step)->toBe('remove:runtime');
});

it('preserves the confirmed relocation target when later source cleanup fails', function (): void {
    $assignment = $this->node->roles()->where('role', RoleName::Database)->sole();
    $snapshot = app(NodeRoleDirectInput::class)->capture($this->node, $assignment, false);
    $assignment->claimConvergence();
    $operation = Operation::query()->create(['kind' => 'node.role_relocate', 'target_type' => 'node', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'status' => 'running', 'sequence' => 1,
        'input' => ['_relocate' => ['target' => $snapshot, 'source' => ['node_id' => $this->gateway->id],
            'certificates' => $snapshot['certificates'], 'publication' => $snapshot['publication'], 'metrics_fleet' => null, 'withdrawal_grace_seconds' => 0]],
        'state' => ['swamp_run_id' => roleLifecycleData('running')['run_id']]]);
    $projection = app(NodeRoleDirectProjection::class);
    $projection->apply($operation, roleLifecycleData('running', ['target_completed' => true, 'footprint' => ['artifacts' => []]]));
    expect($assignment->refresh()->status->value)->toBe('active');
    $projection->apply($operation, roleLifecycleData('failed', ['error_code' => 'node_role.source_cleanup_failed', 'failed_step' => 'source'], 'node_role.source_cleanup_failed'));
    expect($assignment->refresh()->status->value)->toBe('active')->and($operation->refresh()->status->value)->toBe('failed');
});

it('recognizes only approved managed logical proxy Nodes without enrolling workers', function (): void {
    $placement = app(NodeRolePlacement::class);
    config(['swamp_workers.nodes' => []]);
    expect($this->gateway->id)->toBe(1)->and($placement->allows($this->gateway))->toBeTrue()->and($placement->allows($this->node))->toBeFalse();
    $this->gateway->update(['ssh_host_fingerprint' => null]);
    expect($placement->allows($this->gateway->refresh()))->toBeFalse();
});

it('captures and completes every mutable role removal without PHP effects', function (string $name): void {
    $role = RoleName::from($name);
    $node = FleetFixtures::node('remove-'.$name, $role === RoleName::Ingress ? [] : [$role]);
    if ($role === RoleName::Ingress) {
        $cluster = Cluster::query()->create(['name' => 'role-removal']);
        $node->forceFill(['cluster_id' => $cluster->id])->save();
        $node->roles()->create(['role' => $role, 'status' => 'active', 'cluster_id' => $cluster->id]);
        $node->load('roles');
    }
    config(['swamp_workers.nodes' => [$this->gateway->id, $this->node->id, $node->id]]);
    $assignment = $node->roles->sole();
    if ($role === RoleName::Analytics) {
        $storage = analytics_storage_processes();
        $storage['node']->update(['architecture' => 'x86_64']);
        app(AnalyticsRoleSettingsRepository::class)->store($node,
            new AnalyticsRoleSettings($storage['postgres']->id, $storage['clickhouse']->id));
    }
    app(NodeRoleRuntimeInput::class)->prepare($node, $assignment);
    $operation = app(AdmitNodeRoleOperationAction::class)->remove($node, $role, true);
    expect($operation->input['_role_remove']['role'])->toBe($name)
        ->and($operation->input['_role_remove'])->toHaveKeys(['publication', 'certificates', 'metrics_fleet', 'runtime']);
    $operation->state = [...$operation->state, 'swamp_run_id' => roleLifecycleData('running')['run_id']];
    $operation->save();
    app(NodeRoleRemovalProjection::class)->apply($operation, roleLifecycleData('succeeded', ['removed' => true]));
    expect($node->roles()->where('role', $role)->exists())->toBeFalse();
})->with(['gateway', 'app-dev', 'app-prod', 'ingress', 'metrics', 'database', 'websocket', 'analytics', 'ci-runner', 'swamp']);

it('admits both relocation Nodes and confines runtime and hash reads to the target', function (): void {
    $target = FleetFixtures::node('relocation-target');
    $target->forceFill(['agent_secret_hash' => str_repeat('c', 64)])->save();
    config(['swamp_workers.nodes' => [$target->id, $this->gateway->id]]);
    $assignment = $this->gateway->roles()->where('role', RoleName::Gateway)->sole();
    $operation = app(AdmitNodeRoleOperationAction::class)->relocate($target, RoleName::Gateway, true);
    expect($assignment->refresh()->node_id)->toBe($target->id)
        ->and($operation->input['_relocate']['source_node_id'])->toBe($this->gateway->id)
        ->and($operation->input['participant_node_ids'])->toContain($target->id, $this->gateway->id)
        ->and(app(NodeAgentHashSource::class)->ownerId($operation))->toBe($target->id);
    $view = app(NodeRoleDirectProjection::class)->input($operation);
    expect($view['source_removal']['node_id'])->toBe($this->gateway->id)
        ->and($view['node_id'])->toBe($target->id)
        ->and($operation->input['runtime_material']['id'])->toBe($target->id);
});
