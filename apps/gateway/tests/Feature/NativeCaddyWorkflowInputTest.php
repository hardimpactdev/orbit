<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Routes\NativeCaddyPublicationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->node = Node::unguarded(fn (): Node => Node::query()->create(['id' => 9, 'name' => 'native-caddy', 'public_ssh_host' => '192.0.2.9',
        'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']));
    $this->gateway = Node::unguarded(fn (): Node => Node::query()->create(['id' => 14, 'name' => 'gateway',
        'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit', 'status' => 'active']));
    $this->gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
    $this->operation = Operation::query()->create(['kind' => 'route.caddy_converge', 'target_type' => 'node_route_projection',
        'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Running, 'sequence' => 1,
        'input' => ['reason' => 'public-native-test'], 'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
    app()->instance(NodeCaddyfileRenderer::class, new NodeCaddyfileRenderer([]));
});

it('captures the complete canonical public config without a worker callback or agent action', function (): void {
    $input = app(NativeCaddyPublicationInput::class)->forOperation($this->operation);
    $publication = $input['caddy_publication'];
    expect($publication)->toHaveKeys(['node_id', 'user', 'caddy', 'control_node_id', 'connection'])->not->toHaveKey('agent_sha256')
        ->and($publication['node_id'])->toBe($this->node->id)
        ->and($publication['control_node_id'])->toBe(14)
        ->and($publication['connection'])->toBe(['nodeHost' => '10.44.0.9', 'nodeUser' => 'orbit',
            'nodeIdentityFile' => app(SshKeyProvider::class)->privateKeyPath(), 'become' => true])
        ->and($publication['caddy']['content'])->toStartWith('# Managed by Orbit: Node Caddy build')
        ->and($publication['caddy']['version'])->toBe(substr(hash('sha256', $publication['caddy']['content']), 0, 32))
        ->and($this->agents->calls)->toBe([])
        ->and($this->operation->fresh()->input)->toBe(['reason' => 'public-native-test']);
});

it('refuses logical proxy identities before rendering for built-in shell', function (int $nodeId): void {
    Node::unguarded(fn (): Node => Node::query()->create(['id' => $nodeId, 'name' => 'proxy-'.$nodeId, 'user' => 'orbit', 'status' => 'active', 'public_ssh_host' => '192.0.2.'.$nodeId]));
    $this->operation->node_id = $nodeId;
    try {
        app(NativeCaddyPublicationInput::class)->forOperation($this->operation);
        test()->fail('A built-in shell step cannot run locally for a logical proxy.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('caddy.placement_unavailable');
    }
    expect($this->agents->calls)->toBe([]);
})->with([1, 18]);

it('does not expose publication data for another kind or an unplaced operation', function (): void {
    $this->operation->kind = 'firewall.inspect';
    expect(fn () => app(NativeCaddyPublicationInput::class)->forOperation($this->operation))->toThrow(ResourceOperationException::class);
    $this->operation->kind = 'route.caddy_converge';
    $this->operation->node_id = null;
    expect(fn () => app(NativeCaddyPublicationInput::class)->forOperation($this->operation))->toThrow(ResourceOperationException::class);
});

it('records shared terminal output without re-reading the current render', function (): void {
    $operation = app(NativeCaddyPublicationProjection::class)->apply($this->operation, [
        'run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'succeeded',
        'started_at' => '2026-10-31T13:00:00Z', 'finished_at' => '2026-10-31T13:00:01Z',
        'result' => ['ready' => true, 'result' => 'unchanged', 'version' => str_repeat('a', 32)],
    ]);
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result)->toBe(['ready' => true, 'result' => 'unchanged', 'version' => str_repeat('a', 32)])
        ->and($this->agents->calls)->toBe([]);
});

it('records a native failure before the selected result step could run', function (): void {
    $operation = app(NativeCaddyPublicationProjection::class)->apply($this->operation, [
        'run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'failed',
        'started_at' => '2026-10-31T13:00:00Z', 'finished_at' => '2026-10-31T13:00:01Z',
        'error_code' => 'operation.native_failed', 'result' => [],
    ]);
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('operation.native_failed')
        ->and($operation->lock_key)->toBeNull()
        ->and($this->agents->calls)->toBe([]);
});

it('dispatches canonical public input through the shared builder with no execution secret', function (): void {
    $this->operation->update(['state' => [], 'status' => OperationStatus::Queued]);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.start-token']);
    app(StartSwampRun::class)->start($this->operation);
    $input = $socket->sent[0]['payload']['inputs'];
    expect($input['intent'])->toBe(app(NativeCaddyPublicationInput::class)->forOperation($this->operation))
        ->and($input['operation_id'])->toBe($this->operation->id)
        ->and($input['node_id'])->toBe($this->node->id)
        ->and($input['kind'])->toBe('route.caddy_converge')
        ->and(json_encode($input['execution_secrets'], JSON_THROW_ON_ERROR))->toBe('{}')
        ->and(app(DirectOperation::class)->definition('route.caddy_converge')['terminal_result'])->toBe(['job' => 'completion', 'step' => 'result'])
        ->and($this->agents->calls)->toBe([]);
});

it('refuses dispatch without an active Gateway worker or admitted Node address', function (): void {
    $this->gateway->roles()->delete();
    expect(fn () => app(NativeCaddyPublicationInput::class)->connectionForNode($this->node))->toThrow(ResourceOperationException::class);
    $this->gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
    $this->node->wireguard_ip = null;
    expect(fn () => app(NativeCaddyPublicationInput::class)->connectionForNode($this->node))->toThrow(ResourceOperationException::class);
});
