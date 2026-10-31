<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeWireGuardHubProjection;
use App\Domain\Nodes\NodeWireGuardInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Nodes\NodeVpnConfigurationSource;
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

it('admits one idempotent root on logical VPN1 without selecting a worker or reading a private key', function (): void {
    unlink(config('orbit.home').'/wireguard/private.key');
    $path = "/api/v1/nodes/{$this->hub->id}/wireguard-hub/converge";
    $first = $this->withHeader('Idempotency-Key', 'hub-native-once')->post($path, [], ['Accept' => 'application/json'])
        ->assertAccepted()->assertJsonPath('data.kind', 'node.wireguard_hub_converge')
        ->assertJsonPath('data.attempts', 0);
    $this->post($path, [], ['Accept' => 'application/json'])->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
    $operation = Operation::query()->sole();
    expect($this->hub->id)->toBe(1)->and($operation->node_id)->toBe(1)
        ->and($operation->target_id)->toBe(1)->and($operation->parent_operation_id)->toBeNull()
        ->and($operation->lock_key)->toBeNull()->and($operation->worker)->toBeNull()
        ->and($operation->input['participant_node_ids'])->toBe([1])
        ->and($operation->input['private_bindings']['configuration'])->toBe(['owner_node_id' => 1, 'read_node_ids' => [1], 'write_node_ids' => []])
        ->and($operation->input['_wireguard'])->toMatchArray(['executor_node_id' => 1, 'hub_id' => 1, 'context_node_id' => 1, 'excluded_node_id' => null]);
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->socket->sent[0]['payload'])->toMatchArray(['workflowIdOrName' => 'operation-node-wireguard-hub-converge',
        'inputs' => ['operation_id' => $operation->id, 'node_id' => 1, 'kind' => 'node.wireguard_hub_converge']]);
});

it('hydrates only the admitted hub and renders fresh peers inside the existing private source', function (): void {
    $ack = $this->post("/api/v1/nodes/{$this->hub->id}/wireguard-hub/converge", [], ['Accept' => 'application/json'])->assertAccepted();
    $operation = Operation::query()->findOrFail($ack->json('data.id'));
    $run = $operation->state['swamp_run_id'];
    $public = app(NodeWireGuardHubProjection::class)->input($operation);
    expect($public)->toBe($operation->input['_wireguard'])->not->toHaveKeys(['configuration', 'private_key', 'connection']);
    $fresh = FleetFixtures::node('hub-fresh-peer');
    $fresh->update(['wireguard_public_key' => base64_encode(str_repeat('f', 32))]);
    $source = app(NodeVpnConfigurationSource::class);
    expect($source->ownerId($operation))->toBe($this->hub->id);
    $private = $source->resolve($operation, $this->hub->id, '', 'configuration');
    expect(array_column($private->value['peers'], 'node_id'))->toContain($fresh->id);
    expect($private->value)->not->toHaveKey('connection');
    expect(json_encode($private))->not->toContain(base64_encode(str_repeat('p', 32)));
    $this->withServerVariables(['REMOTE_ADDR' => $this->hub->wireguard_ip])
        ->getJson("/api/v1/direct-operations/{$operation->id}?run_id={$run}")->assertOk()
        ->assertJsonPath('data.input.hub_id', $this->hub->id)->assertJsonMissingPath('data.input.private_key');
    $this->withServerVariables(['REMOTE_ADDR' => $this->foreign->wireguard_ip])
        ->getJson("/api/v1/direct-operations/{$operation->id}/private-material?run_id={$run}")->assertForbidden();
});

it('refuses standalone root or private binding drift without reading material', function (string $case): void {
    $ack = $this->post("/api/v1/nodes/{$this->hub->id}/wireguard-hub/converge", [], ['Accept' => 'application/json'])->assertAccepted();
    $operation = Operation::query()->findOrFail($ack->json('data.id'));
    $input = $operation->input;
    match ($case) {
        'target' => $operation->target_id = $this->foreign->id,
        'root' => $operation->node_id = $this->foreign->id,
        'context' => $input['_wireguard']['context_node_id'] = $this->foreign->id,
        'executor' => $input['_wireguard']['executor_node_id'] = $this->gateway->id,
        'exclude' => $input['_wireguard']['excluded_node_id'] = $this->hub->id,
        'reader' => $input['private_bindings']['configuration']['read_node_ids'] = [$this->gateway->id],
    };
    $operation->input = $input;
    expect(NodeWireGuardInput::bound($operation))->toBeNull()
        ->and(app(NodeVpnConfigurationSource::class)->ownerId($operation))->toBeNull();
})->with(['target', 'root', 'context', 'executor', 'exclude', 'reader']);

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
