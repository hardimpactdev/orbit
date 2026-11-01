<?php

declare(strict_types=1);

use App\Actions\Routes\StartRouteNodeOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Routes\NativeRouteLanInput;
use App\Domain\Routes\NativeRouteLanRemovalProjection;
use App\Domain\Routes\RouteOperationProjection;
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
    $this->control = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-probe-pin', 'public_ssh_host' => '192.0.2.1', 'name' => 'control', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit']);
    $this->control->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $cluster = Cluster::query()->create(['name' => 'dev', 'status' => 'active']);
    $this->router = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-probe-pin', 'public_ssh_host' => '192.0.2.9', 'name' => 'router', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'wireguard_ip' => '10.44.0.9', 'lan_ip' => '192.168.6.9', 'cluster_id' => $cluster->id, 'user' => 'orbit']);
    $this->router->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $workload = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-workload-pin', 'public_ssh_host' => '192.0.2.18', 'name' => 'workload', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'wireguard_ip' => '10.44.0.18', 'lan_ip' => '192.168.6.18', 'cluster_id' => $cluster->id, 'user' => 'orbit']);
    $workload->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
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
    $this->socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['dependency_detection.services_node_id' => $this->control->id, 'swamp.run_token' => 'fixture.fake', 'swamp.direct_writers' => []]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->control->wireguard_ip]);
});

it('freezes the validated hop and the probing Node pins without a Gateway effect', function (): void {
    $operation = app(StartRouteNodeOperationAction::class)->execute('route.workload_verify', $this->router, $this->input);
    $inputs = $this->socket->sent[0]['payload']['inputs'];
    expect($inputs['intent'])->toBe(['node_id' => $this->router->id, 'protocol' => 'tls',
        'probe' => ['address' => '10.44.0.18', 'port' => 443, 'domain' => 'demo.private.test'],
        ...app(NativeCaddyPublicationInput::class)->connectionForNode($this->router)])
        ->and($operation->lock_key)->toBeNull()->and($operation->worker)->toBeNull()->and($this->agents->calls)->toBe([]);
    $this->router->update(['wireguard_ip' => '10.44.0.77', 'ssh_host_key' => 'changed']);
    $this->instance->node->update(['wireguard_ip' => '10.44.0.88']);
    expect($inputs['intent']['connection']['nodeHost'])->toBe('10.44.0.9')
        ->and($inputs['intent']['probe']['address'])->toBe('10.44.0.18')
        ->and(app(RouteOperationProjection::class)->input($operation)['intent'])->toBe($inputs['intent']);
});

it('refuses a probing Node without admitted host pins before dispatch', function (): void {
    $this->router->update(['ssh_host_key' => null]);
    expect(fn () => app(StartRouteNodeOperationAction::class)->execute('route.workload_verify', $this->router->fresh(), $this->input))
        ->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('rejects executor callbacks and projects only the native reader result', function (): void {
    $operation = app(StartRouteNodeOperationAction::class)->execute('route.workload_verify', $this->router, $this->input);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $body = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T15:00:00.000Z',
        'finished_at' => '2026-10-30T15:00:01.000Z', 'result' => ['ready' => true, 'stderr' => 'private-diagnostic']];
    $this->postJson($path.'/result', $body)->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
    $this->getJson($path.'?run_id='.$this->runId)->assertForbidden();
    app(RouteOperationProjection::class)->apply($operation, $body);
    expect($operation->fresh()->result)->toBe(['ready' => true])->and($this->agents->calls)->toBe([]);
});

it('refuses a skipped probe instead of reporting an unverified Route ready', function (): void {
    $operation = app(StartRouteNodeOperationAction::class)->execute('route.workload_verify', $this->router, $this->input);
    $operation->update(['input' => [...$operation->input, 'verification' => [
        'protocol' => 'skip', 'probe' => null, 'agent_sha256' => str_repeat('a', 64),
    ]]]);

    expect(fn () => app(RouteOperationProjection::class)->input($operation))->toThrow(ResourceOperationException::class);
    expect($operation->fresh()->result)->toBeNull()->and($this->agents->calls)->toBe([]);
});

it('refuses foreign placement and worker-selected probe fields before recording intent', function (): void {
    foreach ([[$this->instance->node, $this->input], [$this->router, [...$this->input, 'address' => '192.0.2.99']]] as [$node, $input]) {
        expect(fn () => app(StartRouteNodeOperationAction::class)->execute('route.workload_verify', $node, $input))->toThrow(ResourceOperationException::class);
    }
    expect(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('captures public TLS verification only on the stored Ingress', function (): void {
    $this->control->update(['cluster_id' => $this->router->cluster_id, 'architecture' => 'x86_64', 'lan_ip' => '192.168.6.1']);
    $this->control->roles()->create(['role' => RoleName::Ingress, 'status' => 'active', 'cluster_id' => $this->router->cluster_id]);
    config(['swamp.direct_writers' => ['route.public_verify' => 'direct']]);
    expect(fn () => app(StartRouteNodeOperationAction::class)->execute('route.public_verify', $this->router, ['route_id' => $this->route->id]))->toThrow(ResourceOperationException::class);
    $operation = app(StartRouteNodeOperationAction::class)->execute('route.public_verify', $this->control, ['route_id' => $this->route->id]);
    expect($operation->input['verification']['protocol'])->toBe('tls')
        ->and($operation->input['verification']['probe'])->toBe(['address' => '10.44.0.9', 'port' => 443, 'domain' => 'demo.private.test'])
        ->and($operation->node_id)->toBe($this->control->id)->and($this->agents->calls)->toBe([]);
});

it('probes a colocated Router and workload over its stored WireGuard address', function (): void {
    $this->instance->update(['node_id' => $this->router->id]);
    config(['swamp.direct_writers' => ['route.workload_verify' => 'direct']]);
    $operation = app(StartRouteNodeOperationAction::class)->execute('route.workload_verify', $this->router, $this->input);
    expect($operation->input['verification']['protocol'])->toBe('tls')
        ->and($operation->input['verification']['probe'])->toBe(['address' => '10.44.0.9', 'port' => 443, 'domain' => 'demo.private.test'])
        ->and($operation->node_id)->toBe($this->router->id)->and($this->agents->calls)->toBe([]);
});

it('refuses a workload without a published WireGuard endpoint before recording intent', function (): void {
    $this->instance->node->update(['wireguard_ip' => null]);
    config(['swamp.direct_writers' => ['route.workload_verify' => 'direct']]);
    expect(fn () => app(StartRouteNodeOperationAction::class)->execute('route.workload_verify', $this->router, $this->input))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('admits LAN removal directly only for its withdrawn Route and records no Gateway effect', function (): void {
    $this->route->update(['status' => 'retiring', 'sites_published' => false]);
    $operation = app(StartRouteNodeOperationAction::class)->execute('route.lan_remove', $this->instance->node, ['route_id' => $this->route->id]);
    $intent = ['route_id' => $this->route->id, 'node_id' => $this->instance->node_id, 'migrate' => true, 'rules' => [['port' => 443, 'protocol' => 'tcp', 'source' => '192.168.6.9', 'ensure' => 'absent', 'model_name' => '94980c57a7e648b2']]];
    expect($operation->input['lan_removal'])->toBe($intent)
        ->and($operation->worker)->toBeNull()->and($operation->lock_key)->toBeNull()->and($this->agents->calls)->toBe([]);
    $inputs = $this->socket->sent[0]['payload']['inputs'];
    expect($inputs['intent'])->toBe([...$intent, ...app(NativeCaddyPublicationInput::class)->connectionForNode($this->instance->node)])->and($inputs['operation_id'])->toBe($operation->id)
        ->and($inputs['node_id'])->toBe($this->instance->node_id)->and($inputs['kind'])->toBe('route.lan_remove')
        ->and(json_encode($inputs['execution_secrets'], JSON_THROW_ON_ERROR))->toBe('{}')
        ->and(app(DirectOperation::class)->definition('route.lan_remove')['terminal_result'])->toBe(['job' => 'withdrawal', 'step' => 'remove-lan']);
    $projected = app(NativeRouteLanRemovalProjection::class)->apply($operation, ['run_id' => $this->runId,
        'status' => 'succeeded', 'result' => ['ready' => true, 'migrated_node_ids' => [$this->instance->node_id], 'stderr' => 'private-diagnostic'],
        'started_at' => '2026-10-31T03:00:00.000Z', 'finished_at' => '2026-10-31T03:00:01.000Z']);
    expect($projected->result)->toBe(['ready' => true, 'migrated_node_ids' => [$this->instance->node_id]])->and($projected->status->value)->toBe('succeeded')
        ->and(app(NativeRouteLanInput::class)->migrationPending($this->instance->node_id))->toBeFalse()->and($this->agents->calls)->toBe([]);
});

it('refuses LAN removal on a foreign Node or a Route still publishing sites', function (): void {
    $this->route->update(['sites_published' => true]);
    expect(fn () => app(StartRouteNodeOperationAction::class)->execute('route.lan_remove', $this->instance->node, ['route_id' => $this->route->id]))
        ->toThrow(ResourceOperationException::class);
    $this->route->update(['status' => 'retiring', 'sites_published' => false]);
    expect(fn () => app(StartRouteNodeOperationAction::class)->execute('route.lan_remove', $this->router, ['route_id' => $this->route->id]))
        ->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('keeps the admission snapshot through dispatch and projects terminal output without a callback', function (): void {
    $this->route->update(['status' => 'retiring', 'sites_published' => false]);
    $operation = app(StartRouteNodeOperationAction::class)->execute('route.lan_remove', $this->instance->node, ['route_id' => $this->route->id]);
    $this->route->update(['status' => 'active']);
    $this->instance->node->update(['lan_ip' => '192.168.6.99']);
    $projection = app(NativeRouteLanRemovalProjection::class);
    expect($projection->input($operation)['intent']['rules'])->toBe([['port' => 443, 'protocol' => 'tcp', 'source' => '192.168.6.9', 'ensure' => 'absent', 'model_name' => '94980c57a7e648b2']]);
    $projected = $projection->apply($operation, ['run_id' => $this->runId, 'status' => 'failed',
        'result' => ['error_code' => 'firewall.withdrawal_failed'], 'error_code' => 'firewall.withdrawal_failed',
        'started_at' => '2026-10-31T03:00:00Z', 'finished_at' => '2026-10-31T03:00:01Z']);
    expect($projected->status->value)->toBe('failed')->and($projected->error_code)->toBe('firewall.withdrawal_failed')
        ->and(app(NativeRouteLanInput::class)->migrationPending($this->instance->node_id))->toBeTrue()
        ->and($this->agents->calls)->toBe([]);
});

it('refuses LAN removal without a stored destination before recording intent', function (): void {
    $this->route->update(['status' => 'retiring', 'sites_published' => false]);
    $this->instance->node->update(['lan_ip' => null]);
    expect(fn () => app(StartRouteNodeOperationAction::class)->execute('route.lan_remove', $this->instance->node->fresh(), ['route_id' => $this->route->id]))
        ->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});
