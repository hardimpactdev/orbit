<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    $this->gateway = $this->markAsGateway(FleetFixtures::node('role-gateway'));
    $this->node = FleetFixtures::node('role-http', [RoleName::AppDev]);
    $this->node->forceFill(['agent_secret_hash' => str_repeat('e', 64)])->save();
    $this->node->accessibleNodes()->attach($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    config(['swamp_workers.nodes' => [$this->node->id], 'swamp.direct_writers' => ['node.role_add' => 'direct']]);
    $agent = Mockery::mock(AgentCommandRunner::class);
    $agent->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agent);
    DB::beginTransaction();
});
afterEach(function (): void {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

it('returns one idempotent asynchronous role intent without executing a Node command', function (string $role): void {
    $body = ['role' => $role, 'converge_existing' => false];
    $first = $this->withHeader('Idempotency-Key', 'native-role-http-once')
        ->postJson("/api/v1/nodes/{$this->node->id}/roles", $body)->assertAccepted()
        ->assertJsonPath('data.kind', 'node.role_add')->assertJsonPath('data.status', 'queued')->assertJsonPath('data.attempts', 0);
    $this->withHeader('Idempotency-Key', 'native-role-http-once')->postJson("/api/v1/nodes/{$this->node->id}/roles", $body)
        ->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
    $operation = Operation::query()->findOrFail($first->json('data.id'));
    expect($operation->state['node_role_writer'])->toBe('direct')->and($operation->lock_key)->toBeNull()->and($operation->worker)->toBeNull()
        ->and($operation->children()->count())->toBe(0)->and($operation->input)->not->toHaveKey('worker_credentials')
        ->and($operation->input['_role']['role'])->toBe($role);
    expect($first->getContent())->not->toContain(str_repeat('e', 64), 'secret_hash');
})->with(['database', 'ci-runner']);

it('hydrates only the existing owned hash through the real shared private reader', function (): void {
    $ack = $this->postJson("/api/v1/nodes/{$this->node->id}/roles", ['role' => 'database'])->assertAccepted();
    $operation = Operation::query()->findOrFail($ack->json('data.id'));
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $operation->update(['state' => [...$operation->state, 'swamp_run_id' => $run]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip])
        ->getJson("/api/v1/direct-operations/{$operation->id}/private-material?run_id={$run}")
        ->assertOk()->assertJsonPath('data.secret_hash', str_repeat('e', 64))->assertJsonMissingPath('data.worker_credentials');
    $this->postJson("/api/v1/direct-operations/{$operation->id}/secret-output", ['run_id' => $run, 'outputs' => ['secret_hash' => str_repeat('f', 64)]])
        ->assertUnprocessable();
    expect($this->node->refresh()->agent_secret_hash)->toBe(str_repeat('e', 64));
});
