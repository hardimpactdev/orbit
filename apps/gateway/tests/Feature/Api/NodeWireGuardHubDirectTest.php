<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeWireGuardHubProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    $this->hub = FleetFixtures::node('standalone-hub', [RoleName::Vpn]);
    $this->gateway = $this->markAsGateway(FleetFixtures::node('hub-gateway', [RoleName::Gateway]));
    $this->foreign = FleetFixtures::node('hub-foreign');
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agents);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fake.run.token', 'swamp_workers.nodes' => []]);
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: 'orbit');
    $home = config('orbit.home');
    @mkdir($home.'/wireguard', 0700, true);
    file_put_contents($home.'/wireguard/private.key', base64_encode(str_repeat('p', 32)));
    file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('h', 32)));
});

it('admits one idempotent run on VPN1 with the full public intent and never reads a private key', function (): void {
    unlink(config('orbit.home').'/wireguard/private.key');
    $path = "/api/v1/nodes/{$this->hub->id}/wireguard-hub/converge";
    $first = $this->withHeader('Idempotency-Key', 'hub-native-once')->post($path, [], ['Accept' => 'application/json'])
        ->assertAccepted()->assertJsonPath('data.kind', 'node.wireguard_hub_converge')
        ->assertJsonPath('data.attempts', 0);
    $this->post($path, [], ['Accept' => 'application/json'])->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
    $operation = Operation::query()->sole();
    expect($operation->node_id)->toBe($this->hub->id)->and($operation->target_id)->toBe($this->hub->id)
        ->and($operation->input['participant_node_ids'])->toBe([$this->hub->id])
        ->and($operation->input)->not->toHaveKeys(['_wireguard', 'configuration', 'private_bindings'])
        ->and($operation->input['intent']['hub_id'])->toBe($this->hub->id)
        ->and($operation->input['intent']['ssh']['target']['host'])->toBe($this->hub->wireguard_ip);
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->socket->sent[0]['payload'])->toMatchArray(['workflowIdOrName' => 'operation-node-wireguard-hub-converge',
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $this->hub->id, 'kind' => 'node.wireguard_hub_converge',
            'intent' => $operation->input['intent'], 'execution_secrets' => (object) []]]);
});

it('admits a hub that has no Node worker, since Swamp reaches it over SSH from 209', function (): void {
    $this->hub->update(['architecture' => 'aarch64']);
    $this->post("/api/v1/nodes/{$this->hub->id}/wireguard-hub/converge", [], ['Accept' => 'application/json'])->assertAccepted();
    expect(Operation::query()->sole()->input['intent']['hub_id'])->toBe($this->hub->id);
});

it('lists every other active peer by public key and address, and nothing private', function (): void {
    $fresh = FleetFixtures::node('hub-fresh-peer');
    $fresh->update(['wireguard_public_key' => base64_encode(str_repeat('f', 32))]);
    $leaving = FleetFixtures::node('hub-leaving-peer');
    $leaving->update(['wireguard_public_key' => base64_encode(str_repeat('l', 32)), 'status' => LifecycleStatus::Removing]);
    $this->post("/api/v1/nodes/{$this->hub->id}/wireguard-hub/converge", [], ['Accept' => 'application/json'])->assertAccepted();
    $intent = Operation::query()->sole()->input['intent'];
    expect($intent['peer_keys'])->toContain($fresh->wireguard_public_key)->not->toContain($leaving->wireguard_public_key)
        ->and($intent['peer_args'])->toContain('peer', $fresh->wireguard_public_key, 'allowed-ips', $fresh->wireguard_ip.'/32')
        ->and(count($intent['peer_args']))->toBe(4 * count($intent['peer_keys']))
        ->and(json_encode(Operation::query()->sole()->input))->not->toContain(base64_encode(str_repeat('p', 32)));
    // One `wg set orbit` argv: peer, key, allowed-ips, address per peer, in Node order.
    $index = array_search($fresh->wireguard_public_key, $intent['peer_args'], true);
    expect(array_slice($intent['peer_args'], $index - 1, 4))->toBe(['peer', $fresh->wireguard_public_key, 'allowed-ips', $fresh->wireguard_ip.'/32']);
});

it('rejects foreign callers and nonhub targets before intent', function (bool $foreignCaller): void {
    if ($foreignCaller) {
        $this->withServerVariables(['REMOTE_ADDR' => $this->foreign->wireguard_ip]);
    }
    $node = $foreignCaller ? $this->hub : $this->gateway;
    $response = $this->post("/api/v1/nodes/{$node->id}/wireguard-hub/converge", [], ['Accept' => 'application/json']);
    $foreignCaller ? $response->assertForbidden() : $response->assertUnprocessable();
    expect(Operation::query()->exists())->toBeFalse()->and($this->socket->sent)->toBe([]);
})->with([false, true]);

it('rejects caller programs private keys and placement before intent', function (string $field): void {
    $this->postJson("/api/v1/nodes/{$this->hub->id}/wireguard-hub/converge", [$field => 'untrusted'])->assertUnprocessable();
    expect(Operation::query()->exists())->toBeFalse()->and($this->socket->sent)->toBe([]);
})->with(['configuration', 'private_key', 'executor_node_id']);

it('projects a succeeded run as projected without touching peer records', function (): void {
    $ack = $this->post("/api/v1/nodes/{$this->hub->id}/wireguard-hub/converge", [], ['Accept' => 'application/json'])->assertAccepted();
    $operation = Operation::query()->findOrFail($ack->json('data.id'));
    $before = $this->foreign->refresh()->getAttributes();
    $data = ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => now()->toISOString(),
        'finished_at' => now()->toISOString(), 'error_code' => null, 'result' => ['private_key' => 'not-a-result']];
    expect(app(NodeWireGuardHubProjection::class)->apply($operation, $data)->result)->toBe(['projected' => true]);
    expect($this->foreign->refresh()->getAttributes())->toBe($before);
});
