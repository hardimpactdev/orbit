<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Nodes\NodeRoleRuntimeSource;
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
    config(['swamp_workers.nodes' => [$this->node->id]]);
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
    expect($operation->lock_key)->toBeNull()->and($operation->worker)->toBeNull()
        ->and($operation->input)->not->toHaveKey('worker_credentials')
        ->and($operation->input['_role']['role'])->toBe($role);
    expect($first->getContent())->not->toContain(str_repeat('e', 64), 'secret_hash');
})->with(['database', 'ci-runner']);

it('admits the swamp role as a Caddy role of its own Node and keeps it a singleton', function (): void {
    $ack = $this->postJson("/api/v1/nodes/{$this->node->id}/roles", ['role' => 'swamp'])->assertAccepted();
    $snapshot = Operation::query()->findOrFail($ack->json('data.id'))->input['_role'];
    expect($snapshot['role'])->toBe('swamp')
        ->and($snapshot['publication']['caddy_node_ids'])->toBe([$this->node->id])
        ->and($snapshot['prerequisites']['packages'])->toContain('caddy')
        ->and($snapshot['metrics_fleet'])->toBeNull()
        ->and($snapshot['runtime'])->toBe(['role' => 'swamp', 'node_id' => $this->node->id, 'assignment_id' => $snapshot['assignment_id'],
            'agent_sha256' => $snapshot['agent_sha256'], 'user' => $this->node->user, 'workers' => ['a', 'b'], 'concurrency' => 8, 'scheduled_runs' => 4]);
    expect(app(NodeRoleRuntimeSource::class)->resolveRoleMaterial($snapshot, $this->node->id)->value)->toBeNull();

    $other = FleetFixtures::node('role-http-other');
    $other->forceFill(['agent_secret_hash' => str_repeat('e', 64)])->save();
    config(['swamp_workers.nodes' => [$this->node->id, $other->id]]);
    $this->postJson("/api/v1/nodes/{$other->id}/roles", ['role' => 'swamp'])->assertUnprocessable();
});

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

it('serves each role publication participant fresh public data and denies private reads and result writes', function (string $kind, string $field): void {
    $dns = FleetFixtures::node('role-native-dns', [RoleName::Vpn]);
    $foreign = FleetFixtures::node('role-native-foreign', [RoleName::Database]);
    $dns->accessibleNodes()->attach($this->gateway);
    $foreign->accessibleNodes()->attach($this->gateway);
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $operation = Operation::query()->create(['kind' => $kind, 'node_id' => $this->node->id,
        'target_type' => 'node', 'target_id' => $this->node->id, 'status' => 'running', 'sequence' => 1,
        'input' => ['participant_node_ids' => [$this->node->id, $dns->id],
            $field => ['publication' => ['caddy_node_ids' => [], 'php_node_ids' => [], 'dns_node_ids' => [$dns->id]]],
            'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $this->node->id],
            'runtime_material' => ['$secret' => 'node.role_runtime', 'id' => $this->node->id]],
        'state' => ['swamp_run_id' => $run]]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $dns->wireguard_ip])
        ->getJson("/api/v1/direct-operations/{$operation->id}?run_id={$run}")
        ->assertOk()->assertJsonPath('data.node_id', $this->node->id)
        ->assertJsonPath('data.input.node_id', $dns->id)
        ->assertJsonPath('data.input.private_dns_publication.node_id', $dns->id);
    expect($response->json('data.input'))->not->toHaveKeys(['secret_hash', 'runtime_material', '_role', '_role_remove', '_relocate']);
    $this->getJson("/api/v1/direct-operations/{$operation->id}/private-material?run_id={$run}")->assertForbidden();
    $this->postJson("/api/v1/direct-operations/{$operation->id}/result", ['run_id' => $run, 'status' => 'succeeded'])->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $foreign->wireguard_ip])
        ->getJson("/api/v1/direct-operations/{$operation->id}?run_id={$run}")->assertForbidden();
})->with([
    ['node.role_add', '_role'],
]);

it('replays converge_existing under the exact native convergence kind', function (): void {
    $body = ['role' => 'app-dev', 'converge_existing' => true];
    $first = $this->withHeader('Idempotency-Key', 'native-role-converge-once')
        ->postJson("/api/v1/nodes/{$this->node->id}/roles", $body)->assertAccepted()
        ->assertJsonPath('data.kind', 'node.role_converge');
    $this->withHeader('Idempotency-Key', 'native-role-converge-once')
        ->postJson("/api/v1/nodes/{$this->node->id}/roles", $body)->assertAccepted()
        ->assertJsonPath('data.id', $first->json('data.id'));
    expect(Operation::query()->where('kind', 'node.role_converge')->count())->toBe(1);
});
