<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    $this->node = FleetFixtures::node('native-role', [RoleName::AppDev]);
    $this->node->forceFill(['agent_secret_hash' => str_repeat('e', 64)])->save();
    config(['swamp_workers.nodes' => [$this->node->id], 'swamp.direct_writers' => ['node.role_add' => 'direct']]);
    app()->instance(DirectOperation::class, new DirectOperation([new class implements DirectOperationContribution
    {
        public function definitions(): array
        {
            return ['node.role_add' => ['workflow' => 'operation-node-role-add-direct', 'writer_config' => 'swamp.direct_writers',
                'writer_state' => 'node_role_writer', 'writer_scope' => ['parent_operation_id' => null, 'native_role' => true],
                'projection' => NativeRoleAdmissionOnlyProjection::class]];
        }
    }]));
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
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::Database, native: true);
    expect($operation->state['node_role_writer'])->toBe('direct')->and($operation->input['native_role'])->toBeTrue()
        ->and($operation->input['_role']['metrics_impact']['required'])->toBeFalse()
        ->and($operation->input['_role']['role'])->toBe('database')->and($operation->input['_role']['created'])->toBeTrue()
        ->and($operation->input['secret_hash'])->toBe(['$secret' => 'node.agent_secret_hash', 'id' => $this->node->id])
        ->and($operation->input['_role']['footprint'])->not->toHaveKey('secret_hash')
        ->and($operation->attempts)->toBe(0)->and($operation->worker)->toBeNull()->and($operation->lock_key)->toBeNull()
        ->and($operation->children()->count())->toBe(0)->and($operation->input)->not->toHaveKey('worker_credentials');
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
        'app-dev-publication' => $role = RoleName::AppDev,
        'first-role' => $this->node->roles()->delete(),
        'parent' => app(OperationIdempotency::class)->parent = new Operation(['id' => 999]),
    };
    $before = Operation::query()->count();
    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($this->node, $role, native: true))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe($before)->and($this->node->roles()->where('role', RoleName::Database)->exists())->toBeFalse();
})->with(['workerless', 'identity', 'app-dev-publication', 'first-role', 'parent']);

it('rolls back the assignment and intent when an applicable footprint contribution is unsupported', function (): void {
    $artifact = new FakeFootprintArtifact('unsupported-contribution', str_repeat('a', 64));
    FleetFixtures::bind([$artifact]);
    app()->instance(NodeConvergeInput::class, new NodeConvergeInput(app(NodeFootprint::class), app(AgentDelivery::class), [$artifact]));
    $before = Operation::query()->count();
    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::Database, native: true))
        ->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe($before)->and($this->node->roles()->where('role', RoleName::Database)->exists())->toBeFalse();
});

/** Admission fixtures never project an execution outcome. The owning root supplies its real projection. */
final class NativeRoleAdmissionOnlyProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return NodeRoleDirectInput::bound($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        throw new LogicException('This fixture covers admission only.');
    }
}
