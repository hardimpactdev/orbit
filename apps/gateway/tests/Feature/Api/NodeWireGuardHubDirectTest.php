<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeWireGuardHubProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    $this->hub = FleetFixtures::node('standalone-hub', [RoleName::Vpn]);
    $this->gateway = $this->markAsGateway(FleetFixtures::node('hub-gateway', [RoleName::Gateway]));
    $this->foreign = FleetFixtures::node('hub-foreign');
    Node::query()->forceCreate(['id' => 209, 'name' => 'swamp', 'status' => 'active', 'public_ssh_host' => '192.0.2.209']);
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

it('admits VPN1 work on Node209 without a target worker or reading a private key', function (): void {
    unlink(config('orbit.home').'/wireguard/private.key');
    $path = "/api/v1/nodes/{$this->hub->id}/wireguard-hub/converge";
    $first = $this->withHeader('Idempotency-Key', 'hub-native-once')->post($path, [], ['Accept' => 'application/json'])
        ->assertAccepted()->assertJsonPath('data.kind', 'node.wireguard_hub_converge')
        ->assertJsonPath('data.attempts', 0);
    $this->post($path, [], ['Accept' => 'application/json'])->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
    $operation = Operation::query()->sole();
    expect($this->hub->id)->toBe(1)->and($operation->node_id)->toBe(209)
        ->and($operation->target_id)->toBe(1)->and($operation->parent_operation_id)->toBeNull()
        ->and($operation->lock_key)->toBeNull()->and($operation->worker)->toBeNull()
        ->and($operation->input['participant_node_ids'])->toBe([209, 1])
        ->and($operation->input)->not->toHaveKeys(['configuration', 'private_bindings'])
        ->and($operation->input['_hub_inventory']['hub_id'])->toBe(1);
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->socket->sent[0]['payload'])->toMatchArray(['workflowIdOrName' => 'operation-node-wireguard-hub-converge',
        'inputs' => ['operation_id' => $operation->id, 'node_id' => 209, 'kind' => 'node.wireguard_hub_converge', 'intent' => $operation->input['_hub_inventory'], 'execution_secrets' => (object) []]]);
});

it('captures complete public inventory once without exposing the hub private configuration', function (): void {
    $ack = $this->post("/api/v1/nodes/{$this->hub->id}/wireguard-hub/converge", [], ['Accept' => 'application/json'])->assertAccepted();
    $operation = Operation::query()->findOrFail($ack->json('data.id'));
    $captured = app(NodeWireGuardHubProjection::class)->input($operation);
    expect($captured['intent']['hub_id'])->toBe($this->hub->id)->and($captured['execution_secrets'])->toBe([])
        ->and(json_encode($captured))->not->toContain('private_key');
    $this->foreign->update(['wireguard_public_key' => base64_encode(str_repeat('n', 32))]);
    expect(app(NodeWireGuardHubProjection::class)->input($operation))->toBe($captured);
});

it('refuses standalone root drift without private material', function (string $field): void {
    $ack = $this->post("/api/v1/nodes/{$this->hub->id}/wireguard-hub/converge", [], ['Accept' => 'application/json'])->assertAccepted();
    $operation = Operation::query()->findOrFail($ack->json('data.id'));
    $operation->{$field} = $this->foreign->id;
    expect(fn () => app(NodeWireGuardHubProjection::class)->input($operation))->toThrow(ResourceOperationException::class);
})->with(['target_id', 'node_id']);

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

it('projects bounded native outcomes without mutating peer records and rejects conflicting completion', function (): void {
    $ack = $this->post("/api/v1/nodes/{$this->hub->id}/wireguard-hub/converge", [], ['Accept' => 'application/json'])->assertAccepted();
    $operation = Operation::query()->findOrFail($ack->json('data.id'));
    $before = $this->foreign->refresh()->getAttributes();
    $data = ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => now()->toISOString(),
        'finished_at' => now()->toISOString(), 'error_code' => null, 'result' => ['projected' => true, 'private_key' => 'not-a-result']];
    $projection = app(NodeWireGuardHubProjection::class);
    expect($projection->apply($operation, $data)->result)->toBe(['projected' => true]);
    $projection->apply($operation, $data);
    expect($this->foreign->refresh()->getAttributes())->toBe($before);
    $data['result'] = ['projected' => false];
    expect(fn () => $projection->apply($operation, $data))->toThrow(ResourceOperationException::class);
});

it('admits the active hub without a worker on that target', function (): void {
    $this->hub->roles()->delete();
    $this->foreign->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $ack = $this->post("/api/v1/nodes/{$this->foreign->id}/wireguard-hub/converge", [], ['Accept' => 'application/json'])->assertAccepted();
    $operation = Operation::query()->findOrFail($ack->json('data.id'));
    expect($operation->node_id)->toBe(209)->and($operation->target_id)->toBe($this->foreign->id);
});
