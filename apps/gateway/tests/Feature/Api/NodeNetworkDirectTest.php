<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeNetworkCommandInput;
use App\Domain\Nodes\NodeNetworkParticipantInput;
use App\Domain\Nodes\NodeNetworkProjection;
use App\Domain\Nodes\NodeWireGuardInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Nodes\NodeVpnConfigurationSource;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Cluster;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

function nativeNetworkFixture(): array
{
    FleetFixtures::bind();
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));
    $home = config('orbit.home');
    @mkdir($home.'/wireguard', 0700, true);
    file_put_contents($home.'/wireguard/private.key', base64_encode(str_repeat('p', 32)));
    file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('h', 32)));
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: 'orbit');
    $hub = FleetFixtures::node('native-hub', [RoleName::Vpn]);
    $context = FleetFixtures::node('native-peer', [RoleName::CiRunner]);
    $context->update(['wireguard_public_key' => base64_encode(str_repeat('c', 32)), 'tld' => 'departing.test']);
    config(['swamp_workers.nodes' => []]);
    expect(app(DirectOperation::class)->definition('node.network_converge'))->not->toBeNull();
    $snapshot = app(NodeWireGuardInput::class)->capture($context, $context->id);
    $input = ['_wireguard' => $snapshot, 'configuration' => ['$secret' => 'node.vpn_configuration', 'id' => $hub->id], 'participant_node_ids' => [$context->id, $hub->id],
        'private_bindings' => ['configuration' => ['owner_node_id' => $hub->id, 'read_node_ids' => [$hub->id], 'write_node_ids' => []]]];
    $operation = Operation::query()->create(['kind' => 'node.network_converge', 'target_type' => 'node', 'target_id' => $context->id, 'node_id' => $context->id, 'input' => $input, 'status' => 'running', 'sequence' => 1, 'attempts' => 0, 'state' => ['swamp_run_id' => 'ff3b7992-7376-4ac7-a49f-ed45bedc8de5']]);

    return [$operation, $context, $hub, $snapshot, new NodeVpnConfigurationSource(new VpnConfigurationRepository(app(VpnSettings::class), $home))];
}

it('places the hub on its exact logical worker without a Gateway SSH identity', function (): void {
    [$operation, $peer, $hub, $snapshot] = nativeNetworkFixture();
    expect($snapshot)->toMatchArray(['executor_node_id' => $hub->id, 'hub_id' => $hub->id, 'context_node_id' => $peer->id, 'excluded_node_id' => $peer->id]);
    expect($snapshot)->not->toHaveKeys(['transport', 'private_key', 'bootstrap_identity']);
    expect(NodeWireGuardInput::bound($operation))->toBe($snapshot);
});

it('hydrates the existing private key for its immutable admitted hub owner', function (): void {
    [$operation, $peer, $hub, $snapshot, $source] = nativeNetworkFixture();
    $value = $source->resolve($operation, $hub->id, '', 'configuration');
    expect($source->ownerId($operation))->toBe($hub->id);
    expect($value->value)->toMatchArray(['snapshot' => $snapshot, 'private_key' => base64_encode(str_repeat('p', 32))]);
    expect(json_encode($value))->not->toContain(base64_encode(str_repeat('p', 32)));
    expect(array_column($value->value['peers'], 'node_id'))->toContain($peer->id);
});

it('lets only the admitted hub participant read network root status without posting a result', function (): void {
    [$operation, $peer, $hub, $snapshot] = nativeNetworkFixture();
    $path = '/api/v1/direct-operations/'.$operation->id;
    $query = '?run_id='.$operation->state['swamp_run_id'];
    $this->withServerVariables(['REMOTE_ADDR' => $hub->wireguard_ip])
        ->getJson($path.$query)->assertOk()
        ->assertJsonPath('data.node_id', $peer->id)
        ->assertJsonPath('data.kind', 'node.network_converge')
        ->assertJsonPath('data.input.hub', $snapshot)
        ->assertJsonMissingPath('data.input.network');
    $this->postJson($path.'/result', ['run_id' => $operation->state['swamp_run_id'],
        'status' => 'running', 'started_at' => now()->toISOString(), 'result' => []])->assertForbidden();
    $foreign = FleetFixtures::node('network-unadmitted');
    $this->withServerVariables(['REMOTE_ADDR' => $foreign->wireguard_ip])
        ->getJson($path.$query)->assertForbidden();
});

it('refuses changed hub authority before private hydration', function (string $case): void {
    [$operation, $peer, $hub, , $source] = nativeNetworkFixture();
    $input = $operation->input;
    match ($case) {
        'caller' => $input['private_bindings']['configuration']['read_node_ids'] = [$peer->id],
        'owner' => $input['private_bindings']['configuration']['owner_node_id'] = $peer->id,
        'participant' => $input['participant_node_ids'] = [$peer->id],
        'target' => $operation->target_id = $hub->id,
        'excluded' => $input['_wireguard']['excluded_node_id'] = $hub->id,
    };
    $operation->input = $input;
    expect(fn () => $source->resolve($operation, $hub->id, '', 'configuration'))->toThrow(ResourceOperationException::class);
})->with(['caller', 'owner', 'participant', 'target', 'excluded']);

it('reads current public peers after admission inside the native hub lock', function (): void {
    [$operation, $peer, $hub, , $source] = nativeNetworkFixture();
    $fresh = FleetFixtures::node('native-fresh', [RoleName::Database]);
    $fresh->update(['wireguard_public_key' => base64_encode(str_repeat('f', 32))]);
    $peer->update(['status' => 'removing']);
    $value = $source->resolve($operation, $hub->id, '', 'configuration')->value;
    expect(array_column($value['peers'], 'node_id'))->toContain($fresh->id)->not->toContain($peer->id);
});

it('captures removal router and DNS policy without children or peer installation', function (): void {
    [, $context] = nativeNetworkFixture();
    $cluster = Cluster::query()->create(['name' => 'native-network', 'state' => 'active']);
    $context->update(['cluster_id' => $cluster->id, 'lan_ip' => '192.0.2.11']);
    $router = FleetFixtures::node('native-router');
    $router->update(['cluster_id' => $cluster->id, 'lan_ip' => '192.0.2.10', 'wireguard_public_key' => base64_encode(str_repeat('r', 32))]);
    $router->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $before = Operation::query()->count();
    $input = app(NodeNetworkParticipantInput::class)->capture($router, $context, true);
    expect($input['router_lan'])->toMatchArray(['expand' => false, 'prune' => true]);
    expect(array_column($input['router_lan']['desired'], 'comment'))->not->toContain('orbit:router-lan-https:'.$context->id);
    expect($input['peer_dns']['domains'])->not->toContain('departing.test');
    expect($input)->not->toHaveKeys(['configuration', 'private_key', 'children', 'runs']);
    expect(Operation::query()->count())->toBe($before);
});

it('does not change a peer key when a native terminal callback conflicts', function (): void {
    [$operation, $peer] = nativeNetworkFixture();
    $original = $peer->wireguard_public_key;
    $operation->update(['status' => 'succeeded', 'result' => ['network_proven' => true], 'finished_at' => now()]);
    expect(fn () => app(NodeNetworkProjection::class)->apply($operation, [
        'run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => now()->subSecond()->toISOString(), 'finished_at' => now()->toISOString(),
        'result' => ['network_proven' => true, 'public_key' => base64_encode(str_repeat('n', 32))],
    ]))->toThrow(ResourceOperationException::class);
    expect($peer->refresh()->wireguard_public_key)->toBe($original);
});

it('does not assign a peer key on a cancelled native callback', function (): void {
    [$operation, $peer] = nativeNetworkFixture();
    $original = $peer->wireguard_public_key;
    $projected = app(NodeNetworkProjection::class)->apply($operation, [
        'run_id' => $operation->state['swamp_run_id'], 'status' => 'cancelled',
        'started_at' => now()->subSecond()->toISOString(), 'finished_at' => now()->toISOString(),
        'result' => ['public_key' => base64_encode(str_repeat('n', 32))],
    ]);
    expect($projected->status->value)->toBe('cancelled');
    expect($peer->refresh()->wireguard_public_key)->toBe($original);
});

it('restores only the admitted public peer key during native failure recovery', function (): void {
    [$operation, $peer] = nativeNetworkFixture();
    $original = $peer->wireguard_public_key;
    $operation->update(['input' => [...$operation->input, '_network' => ['existing_key' => $original]]]);
    $peer->update(['wireguard_public_key' => base64_encode(str_repeat('n', 32))]);
    app(NodeNetworkProjection::class)->apply($operation, [
        'run_id' => $operation->state['swamp_run_id'], 'status' => 'running',
        'started_at' => now()->toISOString(),
        'result' => ['network_proven' => false, 'restore_peer_key' => true],
    ]);
    expect($peer->refresh()->wireguard_public_key)->toBe($original);
});

it('does not grant the VPN source to deleted child and rollback kinds', function (string $kind): void {
    [$operation, , , , $source] = nativeNetworkFixture();
    $operation->kind = $kind;
    expect(NodeWireGuardInput::bound($operation))->toBeNull();
    expect($source->ownerId($operation))->toBeNull();
})->with(['node.enrollment_restore', 'node.network_rollback', 'node.wireguard_key']);

it('admits one idempotent public network root with immutable participants', function (): void {
    [$existing, $peer, $hub] = nativeNetworkFixture();
    $existing->delete();
    $gateway = FleetFixtures::node('native-gateway', [RoleName::Gateway]);
    config(['swamp_workers.nodes' => [$peer->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])->withHeader('Idempotency-Key', 'native-network-root');
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-node-network-converge', 'runId' => 'ff3b7992-7376-4ac7-a49f-ed45bedc8de5',
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake.run.token']);
    $path = "/api/v1/nodes/{$peer->id}/network/converge";
    $first = $this->post($path, [], ['Accept' => 'application/json'])->assertAccepted()
        ->assertJsonPath('data.kind', 'node.network_converge');
    $this->post($path, [], ['Accept' => 'application/json'])->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
    $operation = Operation::query()->sole();
    expect($operation->node_id)->toBe($peer->id);
    expect($operation->input['participant_node_ids'])->toBe([$peer->id, $hub->id]);
    expect($operation->input['private_bindings']['configuration'])->toBe([
        'owner_node_id' => $hub->id, 'read_node_ids' => [$hub->id], 'write_node_ids' => [],
    ]);
    expect($operation->input['_network']['existing_key'])->toBe($peer->wireguard_public_key);
    expect($operation->request_id)->not->toBeNull();
    expect($operation->parent_operation_id)->toBeNull();
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-node-network-converge');
});

it('refuses foreign public network callers before intent', function (): void {
    [$existing, $peer] = nativeNetworkFixture();
    $existing->delete();
    FleetFixtures::node('native-gateway', [RoleName::Gateway]);
    $foreign = FleetFixtures::node('native-foreign');
    $this->withServerVariables(['REMOTE_ADDR' => $foreign->wireguard_ip]);
    $this->post("/api/v1/nodes/{$peer->id}/network/converge", [], ['Accept' => 'application/json'])->assertForbidden();
    expect(Operation::query()->exists())->toBeFalse();
});

it('refuses public network programs credentials and caller placement before intent', function (string $field): void {
    [$existing, $peer] = nativeNetworkFixture();
    $existing->delete();
    $gateway = FleetFixtures::node('native-gateway', [RoleName::Gateway]);
    config(['swamp_workers.nodes' => [$peer->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $this->postJson("/api/v1/nodes/{$peer->id}/network/converge", [$field => 'untrusted'])->assertUnprocessable();
    expect(Operation::query()->exists())->toBeFalse();
})->with(['program', 'configuration', 'worker_credentials', 'participant_node_ids']);

it('refuses an unselected public network target before intent', function (): void {
    [$existing, $peer] = nativeNetworkFixture();
    $existing->delete();
    $gateway = FleetFixtures::node('native-gateway', [RoleName::Gateway]);
    config(['swamp_workers.nodes' => [$gateway->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $this->post("/api/v1/nodes/{$peer->id}/network/converge", [], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'node.network_unsupported');
    expect(Operation::query()->exists())->toBeFalse();
});

it('captures the command workflow envelope without hydrating execution secrets', function (): void {
    [$operation, $peer, $hub, $snapshot] = nativeNetworkFixture();
    $network = ['schema_version' => 1, 'node_id' => $peer->id, 'existing_key' => $peer->wireguard_public_key,
        'peer' => ['endpoint' => 'vpn.example.test:51820', 'domains' => ['orbit']],
        'trust' => ['desired' => [], 'remove' => [], 'enable' => false]];
    $names = ['configuration' => ['vault' => 'orbit', 'key' => 'hub-configuration-generation']];
    $operation->update(['input' => [...$operation->input, '_network' => $network, 'execution_secrets' => $names]]);
    $envelope = app(NodeNetworkCommandInput::class)->capture($operation);
    expect($envelope)->toBe(['intent' => ['schema_version' => 1, 'node_id' => $peer->id,
        'participant_node_ids' => [$peer->id, $hub->id], 'network' => $network, 'hub' => $snapshot],
        'execution_secrets' => $names]);
    expect(json_encode($envelope))->not->toContain('private_key', 'node.vpn_configuration', '$secret');
});
