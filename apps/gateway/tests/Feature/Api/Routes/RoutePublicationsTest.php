<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    config(['swamp.direct_writers' => ['route.caddy_converge' => 'direct', 'route.private_dns_publish' => 'direct']]);
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->gateway = $gateway;
    $this->worker = Node::query()->create(['name' => 'services', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.17', 'status' => LifecycleStatus::Active]);
    $this->worker->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $this->worker->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->node = Node::query()->create(['public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'name' => 'private-dev', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'status' => LifecycleStatus::Active]);
    $this->operation = Operation::query()->create(['kind' => 'route.caddy_converge', 'target_type' => 'node_route_projection',
        'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued,
        'sequence' => 1, 'state' => ['route_publication_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'input' => ['reason' => 'private proof']]);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->body = ['run_id' => $this->runId];
    $this->agents = new FakeAgentCommandRunner()->answer('caddy.publish', ['result' => 'published']);
    app()->instance(AgentCommandRunner::class, $this->agents);
    app()->instance(NodeCaddyfileRenderer::class, new NodeCaddyfileRenderer([]));
});

it('returns a public current render without executing Caddy in the Gateway', function (): void {
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()
        ->assertJsonPath('data.input.caddy_publication.node_id', $this->node->id)
        ->assertJsonPath('data.input.caddy_publication.user', 'orbit')
        ->assertJsonPath('data.input.caddy_publication.caddy.unserved', true);
    $this->postJson($this->path.'/primitive', $this->body)->assertUnprocessable();
    expect($this->agents->calls)->toBe([]);
    expect($this->operation->fresh()->state)->toBe(['route_publication_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']);
    expect($this->operation->fresh()->worker)->toBeNull();
});

it('binds enrolled Node workers to their own intent across shared endpoints', function (): void {
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['operation_executor.worker_node_ids' => [$this->node->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk();
    $this->postJson($this->path.'/primitive', $this->body)->assertUnprocessable();
    $running = [...$this->body, 'status' => 'running', 'started_at' => '2026-10-30T12:00:00.000Z'];
    $this->postJson($this->path.'/result', $running)->assertOk();
    foreach ([$this->worker->id, null] as $foreignNode) {
        $this->operation->update(['node_id' => $foreignNode]);
        $this->getJson($this->path.'?run_id='.$this->runId)->assertForbidden();
        $this->postJson($this->path.'/primitive', $this->body)->assertForbidden();
        $this->postJson($this->path.'/result', $running)->assertForbidden();
    }
    expect($this->agents->calls)->toBe([]);
});

it('refuses every worker request until the Gateway start response binds the run', function (): void {
    $this->operation->update(['state' => ['route_publication_writer' => 'direct']]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    $this->postJson($this->path.'/primitive', $this->body)->assertConflict();
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'running', 'started_at' => '2026-10-30T12:00:00.000Z'])->assertConflict();
    expect($this->operation->fresh()->state)->not->toHaveKey('swamp_run_id');
    expect($this->operation->steps()->count())->toBe(0);
    expect($this->agents->calls)->toBe([]);

    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'fixture.fake']);
    app(StartSwampRun::class)->start($this->operation);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk();
    expect($this->operation->fresh()->state['swamp_run_id'])->toBe($this->runId);
});

it('reads only approved public data and refuses the removed family relay URLs', function (): void {
    $this->operation->update(['input' => ['password' => 'private-input-sentinel', 'command' => 'foreign']]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()
        ->assertJsonPath('data.input.caddy_publication.node_id', $this->node->id)->assertDontSee('private-input-sentinel')->assertDontSee('foreign');
    $this->getJson('/api/v1/route-publications/'.$this->operation->id.'?run_id='.$this->runId)->assertNotFound();
    $this->postJson('/api/v1/route-publications/'.$this->operation->id.'/publish', $this->body)->assertNotFound();
    expect($this->agents->calls)->toBe([]);
});

it('refuses missing peers malformed run IDs and undeclared kinds on shared endpoints', function (): void {
    $this->getJson($this->path)->assertUnprocessable();
    $this->getJson($this->path.'?run_id=foreign')->assertUnprocessable();
    $this->operation->update(['kind' => 'tool.install']);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertUnprocessable();
    $this->postJson($this->path.'/primitive', $this->body)->assertUnprocessable();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.200']);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertForbidden();
    expect($this->agents->calls)->toBe([]);
});

it('excludes direct publications from discovery and refuses the replaced executor paths', function (): void {
    $response = $this->getJson('/api/v1/operation-executor/claimable')->assertOk();
    expect(collect($response->json('data.kinds'))->firstWhere('kind', 'route.caddy_converge'))->toBeNull();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'route.caddy_converge', 'worker' => 'old', 'operation_id' => $this->operation->id])->assertUnprocessable();
    $this->postJson('/api/v1/operation-executor/'.$this->operation->id.'/heartbeat', ['worker' => 'old'])->assertUnprocessable();
    expect($this->agents->calls)->toBe([]);
});

it('requires the existing executor identity and rejects caller Node command and input overrides', function (): void {
    $this->postJson($this->path.'/primitive', [...$this->body, 'node_id' => $this->worker->id])->assertUnprocessable();
    $this->postJson($this->path.'/primitive', [...$this->body, 'input' => ['content' => 'foreign.test {}']])->assertUnprocessable();
    $this->postJson($this->path.'/primitive', [...$this->body, 'command' => 'arbitrary'])->assertUnprocessable();
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->postJson($this->path.'/primitive', $this->body)->assertForbidden();
    expect($this->agents->calls)->toBe([]);
});

it('projects only typed safe Swamp results and associates the native run without a lease', function (): void {
    $started = ['run_id' => $this->runId, 'status' => 'running', 'started_at' => '2026-10-30T12:00:00.000Z'];
    $this->postJson($this->path.'/result', $started)->assertOk();
    $finished = [...$started, 'status' => 'succeeded', 'finished_at' => '2026-10-30T12:00:01.000Z',
        'result' => ['result' => 'published', 'ready' => true, 'password' => 'private-test-password']];
    $this->postJson($this->path.'/result', $finished)->assertOk()->assertJsonPath('data.result', ['result' => 'published', 'ready' => true]);
    $this->postJson($this->path.'/result', $finished)->assertOk();
    $operation = $this->operation->fresh();
    expect($operation->state)->toHaveKeys(['swamp_run_id', 'steps'])->not->toHaveKeys(['executor_runs', 'executor_effect_identity', 'executor_lease_node_id']);
    expect($operation->attempts)->toBe(0);
    expect($operation->worker)->toBeNull();
    expect($operation->steps()->first()->status->value)->toBe('succeeded');
    expect(json_encode($operation->toArray()))->not->toContain('private-test-password');
});

it('refuses an unproven success invalid stage and another run before projection', function (): void {
    $body = [...$this->body, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z'];
    $this->postJson($this->path.'/result', [...$body, 'result' => []])->assertUnprocessable();
    $this->postJson($this->path.'/result', [...$body, 'result' => ['ready' => true, 'stage' => 'reload']])->assertUnprocessable();
    $this->operation->update(['state' => ['route_publication_writer' => 'direct', 'swamp_run_id' => $this->runId]]);
    $this->postJson($this->path.'/primitive', ['run_id' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38'])->assertConflict();
    expect($this->agents->calls)->toBe([]);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
});

it('publishes DNS only on an active VPN owner using the unkeyed fixed program', function (): void {
    $this->operation->update(['kind' => 'route.private_dns_publish']);
    $runner = Mockery::mock(RemoteProgramRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn ($node, $program, $input): bool => $node->id === $this->node->id
        && $program->name === 'route.publish-dns' && array_keys($input) === ['records', 'catalog'])
        ->andReturn(new ProgramResult('published', ['ready' => true, 'digest' => str_repeat('b', 64)], ''));
    app()->instance(RemoteProgramRunner::class, $runner);
    $this->postJson($this->path.'/primitive', $this->body)->assertUnprocessable();
    $this->node->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $this->postJson($this->path.'/primitive', $this->body)->assertOk()->assertJsonPath('data.ready', true);
});

it('submits once after commit and records a native run without acquiring a target hold', function (): void {
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake.token']);
    DB::beginTransaction();
    $operation = app(StartOperationAction::class)->execute('route.caddy_converge', 'node_route_projection', $this->node->id, $this->node->id, ['reason' => 'proof'], 'test', null);
    expect($socket->sent)->toBe([]);
    DB::commit();
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0])->toMatchArray(['type' => 'workflow.run', 'payload' => [
        'workflowIdOrName' => 'operation-route-caddy', 'inputs' => ['operation_id' => $operation->id, 'kind' => 'route.caddy_converge', 'node_id' => $this->node->id],
        'runtimeTags' => ['orbit_start_key' => 'operation:'.$operation->id],
    ]]);
    expect($operation->fresh()->lock_key)->toBeNull();
    expect($operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
    expect($socket->closed)->toBeTrue();
});

it('keeps an unreadable native history uncertain without resubmitting or reporting a false failure', function (): void {
    $this->operation->update(['state' => array_diff_key($this->operation->state, ['swamp_run_id' => true])]);
    $socket = new FakeRoutePublicationSocket(false);
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake.token']);
    expect(fn () => app(StartSwampRun::class)->start($this->operation))->toThrow(ResourceOperationException::class);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run', 'workflow.run.search']);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
    expect($this->operation->fresh()->attempts)->toBe(0);
    expect($socket->closed)->toBeTrue();
});

it('defaults each proven direct Route kind to its only writer and refuses the removed writer', function (string $kind): void {
    config(['swamp.direct_writers' => []]);
    expect(app(DirectOperation::class)->writer($kind))->toBe('direct');
    config(['swamp.direct_writers' => [$kind => 'polled']]);
    $before = Operation::query()->count();
    expect(fn () => app(StartOperationAction::class)->execute($kind, 'node_route_projection', $this->node->id, $this->node->id, [], 'test', null))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe($before);
    expect($this->agents->calls)->toBe([]);
})->with(['route.caddy_converge', 'route.private_dns_publish', 'route.workload_verify', 'route.public_verify']);

it('gives pre-rollout Operations no removed publication executor', function (): void {
    $this->operation->update(['state' => null]);
    $this->postJson($this->path.'/primitive', $this->body)->assertUnprocessable();
    expect(fn () => app(OperationExecutionContract::class)->context($this->operation->fresh()))->toThrow(ResourceOperationException::class, 'no Gateway execution contract');
    expect($this->agents->calls)->toBe([]);
});

it('reads tagged admitted intent before the start reply without establishing a run binding', function (): void {
    $this->operation->update(['state' => ['route_publication_writer' => 'direct', 'swamp_start_key' => 'operation:'.$this->operation->id]]);
    $before = $this->operation->fresh()->getAttributes();
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'workflow.run.search', 'id' => $message['id'], 'payload' => ['total' => 1, 'data' => ['results' => [[
        'workflowName' => 'operation-route-caddy', 'runId' => $this->runId, 'status' => 'running',
        'tags' => ['orbit_start_key' => 'operation:'.$this->operation->id],
        'inputs' => ['operation_id' => $this->operation->id, 'kind' => $this->operation->kind, 'node_id' => $this->operation->node_id],
    ]]]]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.fixture.token']);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.id', $this->operation->id);
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0]['payload'])->toBe(['workflow' => 'operation-route-caddy', 'inputs' => ['operation_id' => (string) $this->operation->id], 'tags' => ['orbit_start_key' => 'operation:'.$this->operation->id], 'limit' => 2]);
    expect($this->operation->fresh()->getAttributes())->toBe($before);
    expect($this->agents->calls)->toBe([]);
});

it('refuses unbound identities that native history cannot prove without submission', function (string $mode): void {
    $this->operation->update(['state' => ['route_publication_writer' => 'direct', 'swamp_start_key' => 'operation:'.$this->operation->id]]);
    $before = $this->operation->fresh()->getAttributes();
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = function (array $message) use ($mode): array {
        $run = ['workflowName' => 'operation-route-caddy', 'runId' => $this->runId, 'status' => 'running',
            'tags' => ['orbit_start_key' => 'operation:'.$this->operation->id],
            'inputs' => ['operation_id' => $this->operation->id, 'kind' => $this->operation->kind, 'node_id' => $this->operation->node_id]];
        $runs = match ($mode) {
            'empty' => [], 'ambiguous' => [$run, $run],
            'foreign-run' => [[...$run, 'runId' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38']],
            'foreign-workflow' => [[...$run, 'workflowName' => 'other']],
            'foreign-tag' => [[...$run, 'tags' => ['orbit_start_key' => 'operation:foreign']]],
            'foreign-operation' => [[...$run, 'inputs' => [...$run['inputs'], 'operation_id' => 99999]]],
            'foreign-node' => [[...$run, 'inputs' => [...$run['inputs'], 'node_id' => 99999]]],
            'foreign-kind' => [[...$run, 'inputs' => [...$run['inputs'], 'kind' => 'other']]],
            'extra-input' => [[...$run, 'inputs' => [...$run['inputs'], 'foreign' => true]]],
            'typed-input' => [[...$run, 'inputs' => [...$run['inputs'], 'operation_id' => (string) $this->operation->id]]],
            'malformed-input' => [[...$run, 'inputs' => null]],
            'malformed-status' => [[...$run, 'status' => null]],
            default => [$run],
        };
        if ($mode === 'lost') {
            return [];
        }
        if ($mode === 'error') {
            return [['type' => 'error', 'id' => $message['id'], 'error' => ['message' => 'private.fixture.token']]];
        }

        return [['type' => 'workflow.run.search', 'id' => $message['id'], 'payload' => ['total' => $mode === 'truncated' ? 2 : count($runs), 'data' => ['results' => $runs]]]];
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.fixture.token']);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.run_unverified')->assertDontSee('private.fixture.token');
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run.search']);
    expect($this->operation->fresh()->getAttributes())->toBe($before);
    expect($this->agents->calls)->toBe([]);
})->with(['empty', 'ambiguous', 'foreign-run', 'foreign-workflow', 'foreign-tag', 'foreign-operation', 'foreign-node', 'foreign-kind', 'extra-input', 'typed-input', 'malformed-input', 'malformed-status', 'lost', 'error', 'truncated']);

it('keeps an early native result when the start acknowledgement later records the binding', function (): void {
    $this->operation->update(['state' => ['route_publication_writer' => 'direct', 'swamp_start_key' => 'operation:'.$this->operation->id]]);
    $verify = new FakeRoutePublicationSocket;
    $verify->reply = fn (array $message): array => [['type' => 'workflow.run.search', 'id' => $message['id'], 'payload' => ['total' => 1, 'data' => ['results' => [[
        'workflowName' => 'operation-route-caddy', 'runId' => $this->runId, 'status' => 'running',
        'tags' => ['orbit_start_key' => 'operation:'.$this->operation->id],
        'inputs' => ['operation_id' => $this->operation->id, 'kind' => $this->operation->kind, 'node_id' => $this->operation->node_id],
    ]]]]]];
    $start = new FakeRoutePublicationSocket;
    $start->reply = function (array $message) use ($verify): array {
        app()->instance(WebSocketClient::class, $verify);
        $this->postJson($this->path.'/result', [...$this->body, 'status' => 'succeeded',
            'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['ready' => true]])->assertOk();
        expect($this->operation->fresh()->state)->not->toHaveKey('swamp_run_id');

        return [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-route-caddy', 'runId' => $this->runId]]];
    };
    app()->instance(WebSocketClient::class, $start);
    config(['swamp.run_token' => 'private.fixture.token']);
    app(StartSwampRun::class)->start($this->operation);
    $op = $this->operation->fresh();
    expect($op->state['swamp_run_id'])->toBe($this->runId);
    expect($op->state['swamp_start_key'])->toBe('operation:'.$op->id);
    expect($op->state['steps']['caddy-publish'])->toBe(['ready' => true]);
    expect($op->status->value)->toBe('succeeded')->and($op->result)->toBe(['ready' => true]);
    expect($op->steps()->count())->toBe(1);
    expect(array_column($start->sent, 'type'))->toBe(['workflow.run']);
    expect(array_column($verify->sent, 'type'))->toBe(['workflow.run.search']);
});

it('renders current public state again on each read without persisting the rendered data', function (): void {
    $source = Mockery::mock(NodeCaddySiteSource::class);
    $source->shouldReceive('order')->andReturn(1);
    $source->shouldReceive('sites')->andReturnUsing(fn (Node $node): array => [new CaddySite('test', 'current', CaddyListenerRule::WireGuard, ['preview.test'], 443, "preview.test { respond {$node->name} }")]);
    app()->instance(NodeCaddyfileRenderer::class, new NodeCaddyfileRenderer([$source]));
    $before = $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->json('data.input.caddy_publication');
    $this->node->update(['name' => 'updated-survivor']);
    $after = $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->json('data.input.caddy_publication');
    expect($after['caddy']['content'])->toContain('updated-survivor')->not->toContain('private-dev');
    expect($after['caddy']['version'])->not->toBe($before['caddy']['version']);
    expect($after['node_id'])->toBe($this->node->id);
    expect($this->operation->fresh()->input)->toBe(['reason' => 'private proof']);
    expect($this->agents->calls)->toBe([]);
});

it('exposes only a bounded render refusal without diagnostics or an executable primitive', function (): void {
    $source = Mockery::mock(NodeCaddySiteSource::class);
    $source->shouldReceive('order')->andReturn(1);
    $source->shouldReceive('sites')->andThrow(new RuntimeException('private-render-sentinel'));
    app()->instance(NodeCaddyfileRenderer::class, new NodeCaddyfileRenderer([$source]));
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()
        ->assertJsonPath('data.input.caddy_publication.error_code', 'caddy.render_failed')
        ->assertJsonPath('data.input.caddy_publication.stage', 'render')->assertDontSee('private-render-sentinel');
    $this->postJson($this->path.'/primitive', $this->body)->assertUnprocessable();
    expect($this->agents->calls)->toBe([]);
});
