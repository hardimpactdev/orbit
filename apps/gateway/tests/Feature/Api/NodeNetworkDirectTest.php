<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeNetworkConvergeInput;
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
    $snapshot = app(NodeNetworkConvergeInput::class)->capture($context, false);
    $input = ['intent' => $snapshot, 'participant_node_ids' => [209, $context->id, $hub->id]];
    $operation = Operation::query()->create(['kind' => 'node.network_converge', 'target_type' => 'node', 'target_id' => $context->id, 'node_id' => $context->id, 'input' => $input, 'status' => 'running', 'sequence' => 1, 'attempts' => 0, 'state' => ['swamp_run_id' => 'ff3b7992-7376-4ac7-a49f-ed45bedc8de5']]);

    return [$operation, $context, $hub, $snapshot, new NodeVpnConfigurationSource(new VpnConfigurationRepository(app(VpnSettings::class), $home))];
}

it('captures pinned peer and hub SSH inputs without private material or worker selection', function (): void {
    [$operation, $peer, $hub] = nativeNetworkFixture();
    expect($operation->input['intent']['ssh']['target']['host'])->toBe($peer->wireguard_ip);
    expect($operation->input['intent']['hub_ssh']['target']['host'])->toBe($hub->wireguard_ip);
    expect(app(DirectOperation::class)->definition('node.network_converge'))->toMatchArray([
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'network', 'step' => 'result'],
    ])->not->toHaveKey('private_material');
    expect(NodeWireGuardInput::bound($operation))->toBeNull();
    expect(json_encode(app(NodeNetworkProjection::class)->input($operation)))
        ->not->toContain(base64_encode(str_repeat('p', 32)));
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

it('leaves the recorded key unchanged when the native root fails after a host effect', function (): void {
    [$operation, $peer] = nativeNetworkFixture();
    $original = $peer->wireguard_public_key;
    app(NodeNetworkProjection::class)->apply($operation, [
        'run_id' => $operation->state['swamp_run_id'], 'status' => 'failed',
        'started_at' => now()->subSecond()->toISOString(), 'finished_at' => now()->toISOString(),
        'error_code' => 'operation.native_failed', 'result' => [],
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
    expect($operation->input['participant_node_ids'])->toBe([209, $peer->id, $hub->id]);
    expect($operation->input)->not->toHaveKeys(['configuration', 'private_bindings', '_wireguard']);
    expect($operation->input['intent']['existing_key'])->toBe($peer->wireguard_public_key);
    expect($socket->sent[0]['payload']['inputs']['intent'])->toBe($operation->input['intent']);
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

it('refuses a target with no recorded SSH pin before intent', function (): void {
    [$existing, $peer] = nativeNetworkFixture();
    $existing->delete();
    $gateway = FleetFixtures::node('native-gateway', [RoleName::Gateway]);
    $peer->update(['ssh_host_key' => null]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $this->post("/api/v1/nodes/{$peer->id}/network/converge", [], ['Accept' => 'application/json'])
        ->assertServiceUnavailable()->assertJsonPath('error.code', 'swamp.ssh_target_unavailable');
    expect(Operation::query()->exists())->toBeFalse();
});
