<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
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
    $this->node->accessibleNodes()->attach($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
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

it('authorizes the admitted Node without the legacy executor allowlist across shared endpoints', function (): void {
    config(['operation_executor.worker_node_ids' => []]);
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

it('requires the admitted Node identity and rejects caller Node command and input overrides', function (): void {
    $this->postJson($this->path.'/primitive', [...$this->body, 'node_id' => $this->worker->id])->assertUnprocessable();
    $this->postJson($this->path.'/primitive', [...$this->body, 'input' => ['content' => 'foreign.test {}']])->assertUnprocessable();
    $this->postJson($this->path.'/primitive', [...$this->body, 'command' => 'arbitrary'])->assertUnprocessable();
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
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
    expect($operation->state)->toHaveKey('swamp_run_id')->not->toHaveKeys(['steps', 'executor_runs', 'executor_effect_identity', 'executor_lease_node_id']);
    expect($operation->attempts)->toBe(0);
    expect($operation->worker)->toBeNull();

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
