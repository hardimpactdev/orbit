<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

it('starts selected executor footprints directly without PHP execution or child admission', function (string $peer, int $id): void {
    FleetFixtures::bind([new FakeFootprintArtifact('tmpfiles', 'current')]);
    $node = Node::query()->forceCreate(['id' => $id, 'name' => 'executor-'.$peer, 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.9', 'ssh_host_fingerprint' => 'SHA256:test', 'status' => 'active']);
    $gateway = FleetFixtures::node('gateway', [RoleName::Gateway]);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    $runner->shouldNotReceive('install');
    app()->instance(AgentCommandRunner::class, $runner);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-node-converge-direct', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp_workers.nodes' => [$node->id], 'swamp.direct_writers' => ['node.converge' => 'direct'], 'swamp.run_token' => 'fake.token']);
    config($peer === 'services'
        ? ['dependency_detection.services_node_id' => (string) $node->id]
        : ['operation_executor.worker_node_ids' => [(string) $node->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $this->postJson('/api/v1/nodes/'.$node->id.'/converge', ['force' => true])->assertAccepted()->assertJsonPath('data.kind', 'node.converge');
    $operation = Operation::query()->sole();
    expect($operation->state['node_converge_writer'])->toBe('direct');
    expect($socket->sent)->toHaveCount(1);
    expect($operation->lock_key)->toBeNull();
    expect($operation->worker)->toBeNull();
    expect($operation->attempts)->toBe(0);
})->with(['services', 'worker'])->with([1, 18, 13]);

it('keeps Nodes outside the rollout polled even when the caller requests native execution', function (int $id): void {
    $node = Node::query()->forceCreate(['id' => $id, 'name' => 'workerless', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.9', 'ssh_host_fingerprint' => 'SHA256:test', 'status' => 'active']);
    $gateway = FleetFixtures::node('gateway', [RoleName::Gateway]);
    config(['swamp_workers.nodes' => [], 'swamp.direct_writers' => ['node.converge' => 'direct']]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $this->postJson('/api/v1/nodes/'.$id.'/converge', ['native_footprint' => true])->assertAccepted();
    $operation = Operation::query()->sole();
    expect($operation->state['node_converge_writer'])->toBe('polled');
    expect($operation->input)->not->toHaveKey('native_footprint');
    expect($operation->input)->not->toHaveKey('_converge');
})->with([1, 18]);
