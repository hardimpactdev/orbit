<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeNetworkParticipantInput;
use App\Domain\Nodes\NodeNetworkProjection;
use App\Domain\Nodes\NodeWireGuardInput;
use App\Domain\Nodes\NodeWireGuardProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Nodes\NodeVpnConfigurationSource;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Cluster;
use App\Models\Operation;
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
    $current = app(DirectOperation::class);
    $extra = [];
    foreach (['node.network_converge' => NodeNetworkProjection::class, 'node.wireguard_hub_converge' => NodeWireGuardProjection::class] as $kind => $projection) {
        if ($current->definition($kind) === null) {
            $extra[$kind] = ['workflow' => str_replace(['.', '_'], '-', 'operation-'.$kind), 'projection' => $projection, 'private_material' => ['configuration' => 'node.vpn_configuration']];
        }
    }
    if ($extra !== []) {
        $contribution = new readonly class($extra) implements DirectOperationContribution
        {
            public function __construct(private array $definitions) {}

            public function definitions(): array
            {
                return $this->definitions;
            }
        };
        app()->instance(DirectOperation::class, new DirectOperation([$contribution]));
    }
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
