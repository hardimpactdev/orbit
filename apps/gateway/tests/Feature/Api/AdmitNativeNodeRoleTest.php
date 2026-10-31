<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    FleetFixtures::node('reserved-light-node');
    $this->node = FleetFixtures::node('native-role', [RoleName::AppDev]);
    $this->node->forceFill(['agent_secret_hash' => str_repeat('e', 64)])->save();
    config(['swamp_workers.nodes' => [$this->node->id], 'swamp.direct_writers' => ['node.role_add' => 'direct']]);
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

it('records native role and footprint intent without an executor hold child or host call', function (): void {
    FleetFixtures::node('metrics', [RoleName::Metrics]);
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::Database);
    expect($operation->input['_role']['node_id'])->toBe($this->node->id)
        ->and($operation->input['_role']['role'])->toBe('database')->and($operation->input['_role']['created'])->toBeTrue()
        ->and($operation->input['secret_hash'])->toBe(['$secret' => 'node.agent_secret_hash', 'id' => $this->node->id])
        ->and($operation->input['_role']['footprint'])->not->toHaveKey('secret_hash')
        ->and($operation->attempts)->toBe(0)->and($operation->worker)->toBeNull()->and($operation->lock_key)->toBeNull()
        ->and($operation->input)->not->toHaveKey('worker_credentials');
    expect(json_encode($operation->input, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('e', 64));
    $state = $operation->state;
    $state['swamp_run_id'] = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $operation->state = $state;
    expect(NodeRoleDirectInput::ownerId($operation))->toBe($this->node->id);
});

it('refuses unsupported or unplaced role roots before recording an Operation or assignment', function (string $reason): void {
    $role = RoleName::Database;
    match ($reason) {
        'workerless' => config(['swamp_workers.nodes' => []]),
        'identity' => $this->node->forceFill(['agent_secret_hash' => null])->save(),
    };
    $before = Operation::query()->count();
    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($this->node, $role))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe($before)->and($this->node->roles()->where('role', RoleName::Database)->exists())->toBeFalse();
})->with(['workerless', 'identity']);

it('rolls back the assignment and intent when an applicable footprint contribution is unsupported', function (): void {
    $artifact = new FakeFootprintArtifact('unsupported-contribution', str_repeat('a', 64));
    FleetFixtures::bind([$artifact]);
    app()->instance(NodeConvergeInput::class, new NodeConvergeInput(app(NodeFootprint::class), app(AgentDelivery::class), [$artifact]));
    $before = Operation::query()->count();
    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::Database))
        ->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe($before)->and($this->node->roles()->where('role', RoleName::Database)->exists())->toBeFalse();
});

it('refuses a database role with no WireGuard address before recording intent or effects', function (): void {
    $this->node->update(['wireguard_ip' => null]);
    try {
        app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::Database);
        test()->fail('The missing WireGuard address was accepted.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('database.wireguard_ip_missing');
    }
    expect(Operation::query()->count())->toBe(0)->and($this->node->roles()->where('role', RoleName::Database)->exists())->toBeFalse();
});

it('derives the direct writer from the ported role without a caller toggle', function (RoleName $role): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, $role);
    expect($operation->kind)->toBe('node.role_add')
        ->and($operation->input['_role'])->not->toHaveKeys(['metrics_node_id', 'metrics_impact']);
})->with([RoleName::Database, RoleName::CiRunner]);
