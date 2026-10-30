<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.1', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $services = Node::query()->create(['name' => 'services', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.17', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->node = Node::query()->create(['name' => 'dev', 'public_ssh_host' => '192.0.2.3', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->claimOperation = function (string $kind, string $targetType, int $targetId, array $input): void {
        $this->operation = Operation::query()->create(['kind' => $kind, 'target_type' => $targetType, 'target_id' => $targetId,
            'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'worker' => 'worker', 'sequence' => 1, 'input' => $input]);
        $this->postJson('/api/v1/operation-executor/claim', ['kind' => $kind, 'worker' => 'worker', 'operation_id' => $this->operation->id])->assertOk()->assertJsonPath('data.id', $this->operation->id);
        $this->operation->refresh();
        $this->path = '/api/v1/operation-executor/'.$this->operation->id;
    };
    ($this->claimOperation)('route.caddy_converge', 'node_caddy', $this->node->id, ['reason' => 'dev proof']);
    app()->instance(NodeCaddyfileRenderer::class, new NodeCaddyfileRenderer([]));
    $this->agents = new FakeAgentCommandRunner()->answer('caddy.publish', ['result' => 'published']);
    app()->instance(AgentCommandRunner::class, $this->agents);
    $this->prepare = ['worker' => 'worker', 'key' => 'prepare', 'transport' => 'gateway', 'name' => 'route.caddy-prepare', 'input' => ['reason' => 'dev proof']];
    $this->publish = function (): array {
        $this->postJson($this->path.'/run', $this->prepare)->assertOk()->assertJsonPath('data.prepared', true);
        $descriptor = $this->postJson($this->path.'/heartbeat', ['worker' => 'worker'])->assertOk()->json('data.execution_context.descriptors.1');

        return ['worker' => 'worker', ...array_intersect_key($descriptor, array_flip(['key', 'transport', 'name', 'input']))];
    };
});

it('publishes only the stored whole-Node render and reuses its receipt', function (): void {
    $run = ($this->publish)();
    $response = $this->postJson($this->path.'/run', $run)->assertOk()->assertJsonPath('data.ready', true);
    $this->postJson($this->path.'/run', $run)->assertOk()->assertJsonPath('data', $response->json('data'));
    expect($this->agents->calls)->toHaveCount(1)->and($this->agents->calls[0]['node'])->toBe('dev');
    $result = $response->json('data');
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'published', 'name' => 'caddy-publish', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'succeeded', 'result' => $result])->assertOk()->assertJsonPath('data.status', 'succeeded');
});

it('rejects a Node override and changed Gateway configuration before contacting either Node', function (): void {
    $run = ($this->publish)();
    $this->postJson($this->path.'/run', [...$run, 'node_id' => $this->node->id + 1])->assertUnprocessable();
    $this->postJson($this->path.'/run', [...$run, 'input' => [...$run['input'], 'content' => 'unadmitted.example.test {}']])->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($this->agents->calls)->toBe([]);
});

it('rejects unlisted commands, transports, malformed inputs and changed reasons', function (): void {
    foreach ([
        [...$this->prepare, 'name' => 'route.instance-runtime'],
        [...$this->prepare, 'transport' => 'remote_program', 'name' => 'route.publish-dns'],
        [...$this->prepare, 'input' => ['reason' => 'different']],
        [...$this->prepare, 'input' => ['reason' => 'dev proof', 'script' => 'anything']],
    ] as $run) {
        $this->postJson($this->path.'/run', $run)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    }
    expect($this->agents->calls)->toBe([]);
});

it('refuses bare completion and a forged successful publication step', function (): void {
    $result = ['result' => 'published', 'ready' => true];
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'succeeded', 'result' => $result])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'forged', 'name' => 'caddy-publish', 'status' => 'succeeded', 'result' => $result])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
});

it('keeps execution Services-only', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->postJson($this->path.'/run', $this->prepare)->assertForbidden();
    expect($this->agents->calls)->toBe([]);
});

it('drops undeclared receipt fields and redacts secret-bearing step output', function (): void {
    $this->agents->answer('caddy.publish', ['result' => 'published', 'password' => 'route-private-password']);
    $run = ($this->publish)();
    $this->postJson($this->path.'/run', $run)->assertOk()->assertExactJson(['data' => ['result' => 'published', 'ready' => true], 'meta' => ['request_id' => $this->app['request']->attributes->get('orbit.request_id')]]);
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'output', 'name' => 'prepare', 'status' => 'running', 'output' => 'Authorization: Bearer route-private-token'])->assertOk();
    expect(json_encode($this->operation->fresh()->state))->not->toContain('route-private-password', 'route-private-token');
});

it('withdraws a shared PHP pool even when no Route exists', function (): void {
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create(['name' => 'Proof', 'slug' => 'proof', 'repository_url' => 'https://example.test/proof.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'unrouted', 'checkout_path' => '/srv/proof/unrouted', 'branch' => 'main', 'starting_commit' => str_repeat('a', 40), 'status' => InstanceState::SourceResolved]);
    $removal = InstanceRemoval::query()->create(['id' => (string) Str::uuid(), 'requested_instance_id' => $instance->id, 'requested_name' => $instance->name, 'force' => false, 'inventory_digest' => str_repeat('a', 64), 'total' => 1, 'status' => 'removing', 'current_step' => 'source_preparation']);
    $removal->members()->create(['position' => 0, 'instance_id' => $instance->id, 'project_id' => $project->id, 'node_id' => $this->node->id, 'name' => $instance->name, 'environment' => 'development', 'runtime_published' => false, 'root' => 'public', 'branch' => 'main', 'starting_commit' => $instance->starting_commit, 'source_commit' => $instance->starting_commit, 'repository_identity' => $project->repository_identity, 'common_repository_path' => '/srv/proof/unrouted/.git', 'source_identity' => 'proof-source', 'source_layout' => $instance->source_layout, 'checkout_path' => $instance->checkout_path, 'linked_worktree_paths' => [], 'source_digest' => str_repeat('a', 64)]);
    $instance->update(['status' => InstanceState::Removing]);
    ($this->claimOperation)('route.withdraw_instance', 'instance_routes', $instance->id, ['instance_id' => $instance->id]);
    $this->agents->answer('php.converge-shared-pools', ['changed' => true, 'missing_pools' => []]);
    $this->postJson($this->path.'/run', ['worker' => 'worker', 'key' => 'instance-runtime-prepare', 'transport' => 'gateway', 'name' => 'route.instance-runtime', 'input' => ['instance_id' => $instance->id, 'action' => 'prepare']])->assertOk();
    $descriptors = $this->postJson($this->path.'/heartbeat', ['worker' => 'worker'])->assertOk()->json('data.execution_context.descriptors');
    expect($descriptors[1]['name'])->toBe('php.converge-shared-pools');
    $run = ['worker' => 'worker', ...array_intersect_key($descriptors[1], array_flip(['key', 'transport', 'name', 'input']))];
    $this->postJson($this->path.'/run', $run)->assertOk()->assertJsonPath('data.withdrawn', true);
    expect($this->agents->calls)->toHaveCount(1)->and((array) $this->agents->calls[0]['input']['configurations'])->toBe([]);
    $this->postJson($this->path.'/run', ['worker' => 'worker', ...array_intersect_key($descriptors[2], array_flip(['key', 'transport', 'name', 'input']))])->assertOk()->assertJsonPath('data.ready', true);
});

it('recovers a sanitized Caddy target receipt without a second publication', function (): void {
    $this->agents->answer('caddy.publish', static fn (): array => throw new RuntimeException('Lost reply after publication.'));
    $run = ($this->publish)();
    $this->postJson($this->path.'/run', $run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    $effectKey = 'op-'.$this->operation->id.'.caddy-publish';
    $this->agents->answer('agent.effect-receipt', ['command' => 'caddy.publish', 'effect_key' => $effectKey, 'state' => 'succeeded', 'result' => ['ok' => true, 'data' => ['result' => 'published']], 'result_digest' => str_repeat('a', 64), 'sanitized' => true, 'effect_nonce' => $this->operation->fresh()->state['executor_effect_identity']['nonce'], 'effect_created_at' => $this->operation->fresh()->state['executor_effect_identity']['created_at'], 'exit_code' => 0, 'finished_at' => '2026-10-28T22:00:00Z']);
    $this->getJson($this->path.'/receipts/caddy-publish')->assertOk()->assertJsonPath('data.state', 'succeeded')->assertJsonPath('data.result.ready', true);
    $this->getJson($this->path.'/receipts/caddy-publish')->assertOk()->assertJsonPath('data.result.ready', true);
    expect(array_column($this->agents->calls, 'command'))->toBe(['caddy.publish', 'agent.effect-receipt']);
});

it('recovers private DNS publication through the keyed program receipt without replaying the program', function (): void {
    $this->node->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    ($this->claimOperation)('route.private_dns_publish', 'node_caddy', $this->node->id, ['reason' => 'dev proof']);
    $prepare = [...$this->prepare, 'name' => 'route.private-dns-prepare'];
    $this->postJson($this->path.'/run', $prepare)->assertOk();
    $descriptor = $this->postJson($this->path.'/heartbeat', ['worker' => 'worker'])->assertOk()->json('data.execution_context.descriptors.1');
    $run = ['worker' => 'worker', ...array_intersect_key($descriptor, array_flip(['key', 'transport', 'name', 'input']))];
    $effectKey = 'op-'.$this->operation->id.'.private-dns-publish';
    $programResult = ['outcome' => 'published', 'data' => ['ready' => true, 'digest' => str_repeat('d', 64)], 'log' => 'diagnostic program log'];
    $receipt = ['command' => 'remote_program.route.publish-dns', 'effect_key' => $effectKey, 'state' => 'succeeded', 'result' => ['ok' => true, 'data' => $programResult], 'result_digest' => str_repeat('a', 64), 'sanitized' => true, 'effect_nonce' => $this->operation->fresh()->state['executor_effect_identity']['nonce'], 'effect_created_at' => $this->operation->fresh()->state['executor_effect_identity']['created_at'], 'exit_code' => 0, 'finished_at' => '2026-10-28T22:00:00Z'];
    $this->agents->answer('agent.effect-reserve', ['reserved' => true, 'receipt' => [...$receipt, 'state' => 'running', 'result' => null, 'result_digest' => null, 'exit_code' => null, 'finished_at' => null, 'sanitized' => false]])
        ->answer('agent.effect-complete', static fn (): array => throw new RuntimeException('Lost program receipt reply.'))
        ->answer('agent.effect-receipt', $receipt);
    $remote = Mockery::mock(RemoteProgramRunner::class);
    $remote->shouldReceive('run')->once()->andReturn(new ProgramResult('published', $programResult['data'], $programResult['log']));
    app()->instance(RemoteProgramRunner::class, $remote);
    $this->postJson($this->path.'/run', $run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    $response = $this->getJson($this->path.'/receipts/private-dns-publish')->assertOk()->assertJsonPath('data.result.ready', true);
    expect($response->json('data.result'))->not->toHaveKey('log');
    $this->getJson($this->path.'/receipts/private-dns-publish')->assertOk();
    expect(array_column($this->agents->calls, 'command'))->toBe(['agent.effect-reserve', 'agent.effect-complete', 'agent.effect-receipt'])
        ->and(json_encode($this->operation->fresh()->state))->not->toContain('diagnostic program log');
});

it('proves a render refusal stage without publishing or retaining render diagnostics', function (): void {
    $source = new class implements NodeCaddySiteSource
    {
        public function order(): int
        {
            return 1;
        }

        public function sites(Node $node): array
        {
            throw new RuntimeException('private render diagnostic');
        }
    };
    app()->instance(NodeCaddyfileRenderer::class, new NodeCaddyfileRenderer([$source]));
    $this->postJson($this->path.'/run', $this->prepare)->assertOk()->assertJsonPath('data', ['stage' => 'render', 'error_code' => 'caddy.render_failed']);
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'failed', 'error_code' => 'caddy.render_failed',
        'result' => ['error_code' => 'caddy.render_failed', 'stage' => 'validate']])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'failed', 'error_code' => 'caddy.render_failed',
        'result' => ['error_code' => 'caddy.render_failed', 'stage' => 'render']])->assertOk()->assertJsonPath('data.result.stage', 'render');
    expect($this->agents->calls)->toBe([])->and(json_encode($this->operation->fresh()->state))->not->toContain('private render diagnostic');
});

it('keeps only proven validation refusal stages and refuses forged final stage evidence', function (string $stage, ?string $expected): void {
    $run = ($this->publish)();
    $this->agents->refuse('caddy.publish', 'caddy.publication_failed', ['stage' => $stage, 'message' => 'private target diagnostic']);
    $expectedResult = [...($expected === null ? [] : ['stage' => $expected]), 'error_code' => 'caddy.publication_failed'];
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'failed', 'error_code' => 'caddy.publication_failed',
        'result' => ['error_code' => 'caddy.publication_failed', 'stage' => 'validate']])->assertConflict();
    $this->postJson($this->path.'/run', $run)->assertOk()->assertJsonPath('data', $expectedResult);
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'failed', 'error_code' => 'caddy.publication_failed',
        'result' => ['error_code' => 'caddy.publication_failed', 'stage' => 'render']])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'failed', 'error_code' => 'caddy.publication_failed',
        'result' => $expectedResult])->assertOk()->assertJsonPath('data.result', $expectedResult);
    expect(json_encode($this->operation->fresh()->state))->not->toContain('private target diagnostic');
})->with(['validation' => ['validate', 'validate'], 'reload is a hard failure' => ['reload', null], 'unknown is a hard failure' => ['unknown', null]]);

it('recovers a sanitized validation refusal stage once and preserves it in final child proof', function (): void {
    $run = ($this->publish)();
    $this->agents->answer('caddy.publish', static fn (): array => throw new RuntimeException('Lost reply after validation.'));
    $this->postJson($this->path.'/run', $run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    $effectKey = 'op-'.$this->operation->id.'.caddy-publish';
    $receipt = ['command' => 'caddy.publish', 'effect_key' => $effectKey, 'state' => 'failed', 'result' => [
        'ok' => false, 'error' => ['code' => 'caddy.publication_failed', 'message' => 'private recovery diagnostic', 'details' => ['stage' => 'validate']]],
        'result_digest' => str_repeat('a', 64), 'sanitized' => true, 'effect_nonce' => $this->operation->fresh()->state['executor_effect_identity']['nonce'], 'effect_created_at' => $this->operation->fresh()->state['executor_effect_identity']['created_at'], 'exit_code' => 1, 'finished_at' => '2026-10-28T22:00:00Z'];
    $this->agents->answer('agent.effect-receipt', [...$receipt, 'sanitized' => false]);
    $this->getJson($this->path.'/receipts/caddy-publish')->assertStatus(502);
    $this->agents->answer('agent.effect-receipt', $receipt);
    $this->getJson($this->path.'/receipts/caddy-publish')->assertOk()->assertJsonPath('data.result', ['stage' => 'validate', 'error_code' => 'caddy.publication_failed']);
    $this->getJson($this->path.'/receipts/caddy-publish')->assertOk()->assertJsonPath('data.result.stage', 'validate');
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'failed', 'error_code' => 'caddy.publication_failed',
        'result' => ['error_code' => 'caddy.publication_failed', 'stage' => 'validate']])->assertOk()->assertJsonPath('data.result.stage', 'validate');
    expect(array_column($this->agents->calls, 'command'))->toBe(['caddy.publish', 'agent.effect-receipt', 'agent.effect-receipt'])
        ->and(json_encode($this->operation->fresh()->state))->not->toContain('private recovery diagnostic');
});

it('keeps a common Caddy agent refusal uncertain with no recorded failure stage', function (): void {
    $run = ($this->publish)();
    $this->agents->refuse('caddy.publish', 'agent.effect_conflict', ['stage' => 'validate']);
    $this->postJson($this->path.'/run', $run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    expect($this->operation->fresh()->state['executor_runs']['caddy-publish']['result'])->toBeNull();
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'failed', 'error_code' => 'agent.effect_conflict',
        'result' => ['error_code' => 'agent.effect_conflict', 'stage' => 'validate']])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
});

it('rejects a reused Caddy receipt identity before recording its result', function (string $field): void {
    $run = ($this->publish)();
    $this->agents->answer('caddy.publish', static fn (): array => throw new RuntimeException('Lost reply.'));
    $this->postJson($this->path.'/run', $run)->assertConflict();
    $identity = $this->operation->fresh()->state['executor_effect_identity'];
    $effectKey = 'op-'.$this->operation->id.'.caddy-publish';
    $receipt = ['command' => 'caddy.publish', 'effect_key' => $effectKey, 'state' => 'succeeded', 'result' => ['ok' => true, 'data' => ['result' => 'published']],
        'result_digest' => str_repeat('a', 64), 'sanitized' => true, 'effect_nonce' => $identity['nonce'], 'effect_created_at' => $identity['created_at'],
        'exit_code' => 0, 'finished_at' => '2026-10-28T22:00:00Z'];
    $wrong = $receipt;
    $wrong[$field] = $field === 'effect_nonce' ? (string) Str::uuid() : $identity['created_at'] - 1;
    $this->agents->answer('agent.effect-receipt', $wrong);
    $this->getJson($this->path.'/receipts/caddy-publish')->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    expect($this->operation->fresh()->state['executor_runs']['caddy-publish']['result'])->toBeNull();
    $this->agents->answer('agent.effect-receipt', $receipt);
    $result = $this->getJson($this->path.'/receipts/caddy-publish')->assertOk()->assertJsonPath('data.result.ready', true)->json('data');
    expect($result)->not->toHaveKeys(['effect_nonce', 'effect_created_at', 'sanitized']);
    expect(array_column($this->agents->calls, 'command'))->toBe(['caddy.publish', 'agent.effect-receipt', 'agent.effect-receipt']);
})->with(['effect_nonce', 'effect_created_at']);

it('refuses a changed serving role even when the rendered Caddy version stays the same', function (bool $initiallyServing): void {
    $source = new class implements NodeCaddySiteSource
    {
        public function order(): int
        {
            return 1;
        }

        public function sites(Node $node): array
        {
            return [new CaddySite('proof', 'fixed-site', CaddyListenerRule::WireGuard, ['private.test'], 443, "private.test {\n respond proof\n}")];
        }
    };
    $renderer = new NodeCaddyfileRenderer([$source]);
    app()->instance(NodeCaddyfileRenderer::class, $renderer);
    if ($initiallyServing) {
        $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    }
    $run = ($this->publish)();
    if ($initiallyServing) {
        $this->node->roles()->where('role', RoleName::AppDev)->delete();
    } else {
        $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    }
    expect($renderer->render($this->node->fresh())->version)->toBe($run['input']['version'])
        ->and($run['input']['unserved'])->toBe(! $initiallyServing);
    $this->postJson($this->path.'/run', $run)->assertOk()->assertJsonPath('data.error_code', 'caddy.plan_stale');
    expect($this->agents->calls)->toBe([]);
})->with(['gains Caddy' => false, 'loses Caddy' => true]);

it('prepares private DNS only for an active serving role', function (RoleName $role, LifecycleStatus $status): void {
    Node::query()->where('name', 'services')->firstOrFail()->accessibleNodes()->attach($this->node);
    if ($role === RoleName::Gateway && $status === LifecycleStatus::Active) {
        NodeRole::query()->where('role', RoleName::Gateway)->update(['status' => LifecycleStatus::Failed]);
    }
    $this->node->roles()->create(['role' => $role, 'status' => $status]);
    ($this->claimOperation)('route.private_dns_publish', 'node_route_projection', $this->node->id, ['reason' => 'dev proof']);
    $response = $this->postJson($this->path.'/run', [...$this->prepare, 'name' => 'route.private-dns-prepare']);
    if ($role === RoleName::Vpn && $status === LifecycleStatus::Active) {
        $response->assertOk()->assertJsonPath('data.prepared', true);
    } else {
        $response->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
        expect($this->operation->fresh()->state)->not->toHaveKey('private_dns_plan');
    }
    expect($this->agents->calls)->toBe([]);
})->with([RoleName::Vpn, RoleName::Gateway])->with([LifecycleStatus::Active, LifecycleStatus::Provisioning, LifecycleStatus::Removing, LifecycleStatus::Failed]);
