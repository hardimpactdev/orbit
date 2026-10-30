<?php

declare(strict_types=1);

use App\Actions\Routes\RouteChildAdmission;
use App\Actions\Routes\StartRouteVerificationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->control = Node::query()->create(['public_ssh_host' => '192.0.2.1', 'name' => 'control', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit']);
    $this->control->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $cluster = Cluster::query()->create(['name' => 'dev', 'status' => 'active']);
    $this->router = Node::query()->create(['public_ssh_host' => '192.0.2.9', 'name' => 'router', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'wireguard_ip' => '10.44.0.9', 'lan_ip' => '192.168.6.9', 'cluster_id' => $cluster->id, 'user' => 'orbit']);
    $this->router->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $workload = Node::query()->create(['public_ssh_host' => '192.0.2.18', 'name' => 'workload', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.18', 'lan_ip' => '192.168.6.18', 'cluster_id' => $cluster->id, 'user' => 'orbit']);
    $project = Project::query()->create(['name' => 'Demo', 'slug' => 'demo', 'repository_url' => 'https://example.test/demo.git']);
    $this->instance = Instance::query()->create(['checkout_path' => '/home/orbit/code/demo', 'node_id' => $workload->id, 'project_id' => $project->id, 'name' => 'dev', 'status' => 'active']);
    $this->route = Route::query()->create(['cluster_id' => $cluster->id, 'project_id' => $project->id, 'domain' => 'demo.private.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->update(['status' => 'active']);
    $this->input = ['route_id' => $this->route->id, 'instance_id' => $this->instance->id];
    $manifest = tempnam(sys_get_temp_dir(), 'route-agent-');
    file_put_contents($manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($manifest, sys_get_temp_dir()));
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
    $this->socket = new FakeRoutePublicationSocket;
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-route-verification', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['dependency_detection.services_node_id' => $this->control->id, 'swamp.run_token' => 'fixture.fake', 'swamp.direct_writers' => []]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->control->wireguard_ip]);
});

it('defaults verification to direct and refuses the removed writer', function (): void {
    expect(app(DirectOperation::class)->writer('route.workload_verify'))->toBe('direct')
        ->and(app(DirectOperation::class)->writer('route.public_verify'))->toBe('direct');
    config(['swamp.direct_writers' => ['route.workload_verify' => 'polled']]);
    expect(fn () => app(StartRouteVerificationAction::class)->execute('route.workload_verify', $this->router, $this->input))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('captures the validated hop and pin without a Gateway effect or execution hold', function (): void {
    config(['swamp.direct_writers' => ['route.workload_verify' => 'direct']]);
    $operation = app(StartRouteVerificationAction::class)->execute('route.workload_verify', $this->router, $this->input);
    expect($operation->input['verification'])->toBe(['protocol' => 'tls', 'probe' => ['address' => '10.44.0.18', 'port' => 443, 'domain' => 'demo.private.test'], 'agent_sha256' => str_repeat('a', 64)])
        ->and($operation->state['route_verification_writer'])->toBe('direct')->and($operation->lock_key)->toBeNull()
        ->and($operation->worker)->toBeNull()->and($this->agents->calls)->toBe([]);
    $this->instance->node->update(['lan_ip' => '192.168.6.88', 'wireguard_ip' => '10.44.0.88']);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertOk()
        ->assertJsonPath('data.input.probe.address', '10.44.0.18');
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
    expect($this->agents->calls)->toBe([]);
});

it('refuses first reads if native evidence is unavailable before the start reply', function (): void {
    config(['swamp.direct_writers' => ['route.workload_verify' => 'direct']]);
    $reply = $this->socket->reply;
    $readBeforeBinding = false;
    $this->socket->reply = function (array $request) use ($reply, &$readBeforeBinding): array {
        $operation = Operation::query()->latest('id')->firstOrFail();
        $path = '/api/v1/direct-operations/'.$operation->id;
        app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket(false));
        $this->getJson($path.'?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.run_unverified');
        $this->postJson($path.'/result', ['run_id' => $this->runId, 'status' => 'running', 'started_at' => '2026-10-30T15:00:00.000Z'])->assertConflict();
        expect($operation->fresh()->state)->not->toHaveKey('swamp_run_id');
        $readBeforeBinding = true;
        app()->instance(WebSocketClient::class, $this->socket);

        return $reply($request);
    };
    $operation = app(StartRouteVerificationAction::class)->execute('route.workload_verify', $this->router, $this->input);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertOk();
    expect($readBeforeBinding)->toBeTrue()->and($operation->fresh()->state['swamp_run_id'])->toBe($this->runId)
        ->and($this->agents->calls)->toBe([]);
});

it('projects only bounded results and refuses another Node or run', function (): void {
    config(['swamp.direct_writers' => ['route.workload_verify' => 'direct']]);
    $operation = app(StartRouteVerificationAction::class)->execute('route.workload_verify', $this->router, $this->input);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $body = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T15:00:00.000Z', 'finished_at' => '2026-10-30T15:00:01.000Z', 'result' => ['ready' => true, 'stderr' => 'private-diagnostic']];
    $this->postJson($path.'/result', [...$body, 'run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92677'])->assertConflict();
    config(['operation_executor.worker_node_ids' => [$this->instance->node_id]]);
    $this->instance->node->accessibleNodes()->attach($this->control);
    $this->withServerVariables(['REMOTE_ADDR' => $this->instance->node->wireguard_ip]);
    $this->getJson($path.'?run_id='.$this->runId)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->control->wireguard_ip]);
    $this->postJson($path.'/result', [...$body, 'result' => ['ready' => false]])->assertUnprocessable();
    $this->postJson($path.'/result', $body)->assertOk()->assertDontSee('private-diagnostic');
    expect($operation->fresh()->result)->toBe(['ready' => true])->and($this->agents->calls)->toBe([]);
});

it('refuses foreign placement and worker-selected probe fields before recording intent', function (): void {
    foreach ([[$this->instance->node, $this->input], [$this->router, [...$this->input, 'address' => '192.0.2.99']]] as [$node, $input]) {
        expect(fn () => app(StartRouteVerificationAction::class)->execute('route.workload_verify', $node, $input))->toThrow(ResourceOperationException::class);
    }
    expect(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('admits a prospective target only through its owning Route parent snapshot', function (): void {
    config(['swamp.direct_writers' => ['route.workload_verify' => 'direct']]);
    $prospective = Instance::query()->create(['node_id' => $this->instance->node_id, 'project_id' => $this->instance->project_id,
        'name' => 'prospective', 'checkout_path' => '/home/orbit/code/prospective', 'status' => 'active']);
    $input = ['route_id' => $this->route->id, 'instance_id' => $prospective->id];
    expect(fn () => app(StartRouteVerificationAction::class)->execute('route.workload_verify', $this->router, $input))->toThrow(ResourceOperationException::class);
    $parent = Operation::query()->create(['kind' => 'route.target_set', 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->router->id, 'status' => 'running', 'sequence' => 1, 'input' => [], 'state' => ['route_plan' => ['prepared_targets' => [
            ['route_id' => $this->route->id, 'instance_id' => $prospective->id, 'node_id' => $prospective->node_id],
        ]]]]);
    $operation = app(RouteChildAdmission::class)->admit($parent,
        ['kind' => 'route.workload_verify', 'node_id' => $this->router->id, 'input' => $input]);
    expect($operation->input['verification']['probe']['address'])->toBe('10.44.0.18')
        ->and($operation->node_id)->toBe($this->router->id)->and($operation->state['route_verification_writer'])->toBe('direct')
        ->and($this->agents->calls)->toBe([]);
});

it('captures public TLS verification only on the stored Ingress', function (): void {
    $this->control->update(['cluster_id' => $this->router->cluster_id, 'architecture' => 'x86_64', 'lan_ip' => '192.168.6.1']);
    $this->control->roles()->create(['role' => RoleName::Ingress, 'status' => 'active', 'cluster_id' => $this->router->cluster_id]);
    config(['swamp.direct_writers' => ['route.public_verify' => 'direct']]);
    expect(fn () => app(StartRouteVerificationAction::class)->execute('route.public_verify', $this->router, ['route_id' => $this->route->id]))->toThrow(ResourceOperationException::class);
    $operation = app(StartRouteVerificationAction::class)->execute('route.public_verify', $this->control, ['route_id' => $this->route->id]);
    expect($operation->input['verification']['protocol'])->toBe('tls')
        ->and($operation->input['verification']['probe'])->toBe(['address' => '10.44.0.9', 'port' => 443, 'domain' => 'demo.private.test'])
        ->and($operation->node_id)->toBe($this->control->id)->and($this->agents->calls)->toBe([]);
});

it('probes a colocated Router and workload over its stored WireGuard address', function (): void {
    $this->instance->update(['node_id' => $this->router->id]);
    config(['swamp.direct_writers' => ['route.workload_verify' => 'direct']]);
    $operation = app(StartRouteVerificationAction::class)->execute('route.workload_verify', $this->router, $this->input);
    expect($operation->input['verification']['protocol'])->toBe('tls')
        ->and($operation->input['verification']['probe'])->toBe(['address' => '10.44.0.9', 'port' => 443, 'domain' => 'demo.private.test'])
        ->and($operation->node_id)->toBe($this->router->id)->and($this->agents->calls)->toBe([]);
});

it('refuses a workload without a published WireGuard endpoint before recording intent', function (): void {
    $this->instance->node->update(['wireguard_ip' => null]);
    config(['swamp.direct_writers' => ['route.workload_verify' => 'direct']]);
    expect(fn () => app(StartRouteVerificationAction::class)->execute('route.workload_verify', $this->router, $this->input))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});
