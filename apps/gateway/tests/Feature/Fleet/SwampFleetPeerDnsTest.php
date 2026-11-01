<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

/** @return array{Node, Node, Node} */
function fleetPeerDnsNodes($test): array
{
    $nodes = [];
    foreach (['gateway' => '1', 'dev' => '2', 'services' => '3'] as $name => $ip) {
        $node = Node::query()->create(['name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.'.$ip, 'user' => 'orbit', 'architecture' => 'x86_64',
            'wireguard_ip' => '10.44.0.'.$ip, 'wireguard_public_key' => str_repeat('A', 43).'=', 'ssh_host_fingerprint' => 'SHA256:managed', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB']);
        $nodes[$name] = $node;
    }
    NodeRole::query()->create(['node_id' => $nodes['gateway']->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $nodes['dev']->id, 'role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    foreach ($nodes as $node) {
        $node->accessibleNodes()->attach($nodes['gateway']);
        if ($node->id !== $nodes['dev']->id) {
            $node->accessibleNodes()->attach($nodes['dev']);
        }
    }
    $nodes['dev']->accessibleNodes()->attach($nodes['dev']);
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', dnsServer: '10.44.0.1');
    config(['dependency_detection.services_node_id' => $nodes['services']->id]);
    $test->withServerVariables(['REMOTE_ADDR' => $nodes['services']->wireguard_ip]);

    return [$nodes['gateway'], $nodes['dev'], $nodes['services']];
}

/** @return array<string, mixed> */
function fleetDnsProof(): array
{
    return ['state' => "orbit\n10.44.0.1\norbit", 'hooks' => 'PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \\~orbit',
        'dns' => 'Link 2 (orbit): 10.44.0.1', 'domains' => 'Link 2 (orbit): ~orbit', 'default_route' => 'Link 2 (orbit): no', 'uplink' => true];
}

beforeEach(function (): void {
    app()->instance(AgentDelivery::class, new AgentDelivery(__DIR__.'/../../Fixtures/fleet-agent-delivery.json', sys_get_temp_dir()));
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-fleet-peer-dns', 'runId' => $this->run,
    ]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture.direct', 'swamp.direct_writers' => []]);
});

function fleetDnsOperation($test): Operation
{
    [, $node] = fleetPeerDnsNodes($test);
    $response = $test->withHeader('Idempotency-Key', 'peer-dns-proof')->postJson('/api/v1/fleet/nodes/'.$node->id.'/peer-dns')->assertAccepted();

    $test->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

    return Operation::query()->findOrFail($response->json('data.id'));
}

function fleetDnsProjection($test, string $status = 'succeeded'): array
{
    return ['run_id' => $test->run, 'status' => $status, 'started_at' => '2026-10-30T12:00:00.000Z',
        'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => fleetDnsProof()];
}

it('starts keyed peer DNS intent directly without a Gateway execution lock and lists eligible targets', function (): void {
    Queue::fake();
    [$gateway, $node] = fleetPeerDnsNodes($this);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);

    $this->getJson('/api/v1/fleet/peer-dns/targets')->assertOk()->assertJsonPath('data', [$node->id]);
    $targets = $this->getJson('/api/v1/fleet/peer-dns/plan')->assertOk()->json('data');
    expect($targets)->toHaveCount(1)
        ->and($targets[0]['node_id'])->toBe($node->id)
        ->and($targets[0]['configuration_digest'])->toMatch('/^[a-f0-9]{64}$/');
    expect(Operation::query()->count())->toBe(0);
    foreach (range(1, 2) as $_) {
        $this->withHeader('Idempotency-Key', 'peer-dns-proof')->postJson('/api/v1/fleet/nodes/'.$node->id.'/peer-dns')->assertAccepted();
    }
    expect(Operation::query()->count())->toBe(1)
        ->and(Operation::query()->sole()->node_id)->toBe($node->id)
        ->and(Operation::query()->sole()->status)->toBe(OperationStatus::Running)
        ->and($agents->calls)->toBe([]);
    Queue::assertNothingPushed();
    expect(Operation::query()->sole()->lock_key)->toBeNull();
    expect(Operation::query()->sole()->state)->toHaveKey('swamp_run_id');
    expect(Operation::query()->sole()->attempts)->toBe(0);
    expect($this->socket->sent)->toHaveCount(1);
    $this->withHeader('Idempotency-Key', 'peer-dns-other-request')->postJson('/api/v1/fleet/nodes/'.$node->id.'/peer-dns')->assertAccepted();
    $this->withoutHeader('Idempotency-Key')->postJson('/api/v1/fleet/nodes/'.$gateway->id.'/peer-dns')->assertUnprocessable()->assertJsonPath('error.code', 'vpn.peer_dns_unmanaged');
});

it('passes a complete public pinned policy to the one native root', function (): void {
    $operation = fleetDnsOperation($this);
    $input = $this->socket->sent[0]['payload']['inputs'];
    expect($input['intent'])->toBe($operation->input['intent']);
    expect($input['intent']['ssh']['target']['host'])->toBe(Node::query()->findOrFail($operation->node_id)->wireguard_ip);
    expect($input['intent']['ssh']['target']['host_keys'])->toBe(['ssh-ed25519 '.Node::query()->findOrFail($operation->node_id)->ssh_host_key]);
    expect(json_encode($input['execution_secrets']))->toBe('{}');
    expect($input['intent'])->not->toHaveKeys(['agent_sha256', 'private_key']);
});

it('changes the peer DNS digest only when the effective admitted policy changes', function (): void {
    [, $node] = fleetPeerDnsNodes($this);
    $read = fn (): string => $this->getJson('/api/v1/fleet/peer-dns/plan')->assertOk()->json('data.0.configuration_digest');
    $original = $read();
    $node->update(['name' => 'renamed-dev']);
    expect($read())->toBe($original);

    foreach ([
        ['dns_server_override' => '10.44.0.4'],
        ['wireguard_public_key' => str_repeat('B', 43).'='],
        ['tld' => 'project.test'],
    ] as $change) {
        $previous = $read();
        $node->update($change);
        expect($read())->not->toBe($previous);
    }
    expect(Operation::query()->count())->toBe(0);
});

it('permits maintenance plan reads over an existing directed access edge', function (): void {
    [, $node] = fleetPeerDnsNodes($this);

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->getJson('/api/v1/fleet/peer-dns/plan')
        ->assertOk();
    expect(Operation::query()->count())->toBe(0);
});

it('keeps explicit peer DNS reconciliation admitting the planned Node ids', function (): void {
    [, $node] = fleetPeerDnsNodes($this);

    $this->artisan('orbit:peer-dns-reconcile')->assertSuccessful();

    expect(Operation::query()->where('kind', 'fleet.peer_dns')->pluck('node_id')->all())->toBe([$node->id]);
});

it('uses directed Node access rather than a private Services identity', function (): void {
    [, $node] = fleetPeerDnsNodes($this);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->getJson('/api/v1/fleet/peer-dns/targets')
        ->assertOk();
});

it('accepts the exact bound native policy without an execution receipt and replays idempotently', function (): void {
    $operation = fleetDnsOperation($this);
    $path = '/api/v1/direct-operations/'.$operation->id.'/result';
    foreach (range(1, 2) as $_) {
        $this->postJson($path, fleetDnsProjection($this))->assertOk()->assertJsonPath('data.status', 'succeeded');
    }
    expect($operation->fresh()->result)->toBe(fleetDnsProof());
    expect($operation->fresh()->worker)->toBeNull();
    expect($operation->fresh()->attempts)->toBe(0);
    expect($operation->fresh()->state)->not->toHaveKey('executor_runs');
});

it('refuses changed policies missing uplink wrong runs and execution overrides', function (string $fault): void {
    $operation = fleetDnsOperation($this);
    $data = fleetDnsProjection($this);
    match ($fault) {
        'run' => $data['run_id'] = 'fabd4059-fb6c-49d8-940e-01ae58aa3f38',
        'node' => $data['node_id'] = $operation->node_id + 1,
        'command' => $data['command'] = 'wireguard.repair-peer-dns',
    };
    $response = $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', $data);
    $fault === 'run' ? $response->assertConflict() : $response->assertUnprocessable();
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
})->with(['run', 'node', 'command']);

it('projects bounded refusal and cancellation without retaining raw diagnostics', function (string $status): void {
    $operation = fleetDnsOperation($this);
    $data = [...fleetDnsProjection($this, $status), 'result' => []];
    if ($status === 'failed') {
        $data['error_code'] = 'vpn.peer_dns_uplink_missing';
        $data['result'] = ['error_code' => $data['error_code'], 'state' => 'raw diagnostic'];
    }
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', $data)->assertOk();
    expect($operation->fresh()->result)->toBe($status === 'failed' ? ['error_code' => 'vpn.peer_dns_uplink_missing'] : []);
})->with(['failed', 'cancelled']);

it('projects native unreadable and prepublication repair refusals without diagnostics', function (string $code): void {
    $operation = fleetDnsOperation($this);
    $data = [...fleetDnsProjection($this, 'failed'), 'error_code' => $code,
        'result' => ['error_code' => $code, 'state' => 'private native diagnostic', 'message' => 'private native diagnostic']];

    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', $data)->assertOk()->assertJsonPath('data.status', 'failed');
    expect($operation->fresh()->result)->toBe(['error_code' => $code]);
    expect(json_encode($operation->fresh()->toArray()))->not->toContain('private native diagnostic');
})->with(['vpn.peer_dns_unreadable', 'vpn.peer_dns_repair_failed']);

it('deletes peer DNS claims receipts and Gateway primitive execution', function (): void {
    $operation = fleetDnsOperation($this);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'fleet.peer_dns', 'worker' => 'retired'])->assertNotFound();
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/primitive', ['run_id' => $this->run])->assertUnprocessable();
    expect(app(OperationKinds::class)->directOnly('fleet.peer_dns'))->toBeTrue();
    expect(app(OperationKinds::class)->definition('fleet.peer_dns'))->not->toHaveKeys(['runs', 'completion_contract', 'completion']);
});

it('admits peer DNS through its normal directed Node access without an executor role', function (): void {
    [, $node] = fleetPeerDnsNodes($this);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->postJson('/api/v1/fleet/nodes/'.$node->id.'/peer-dns', [])->assertAccepted();
    expect(Operation::query()->count())->toBe(1)->and($agents->calls)->toBe([]);
});

it('requires explicit automatic true on the executor recipe route', function (array $payload): void {
    [, $node] = fleetPeerDnsNodes($this);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson('/api/v1/fleet/nodes/'.$node->id.'/recipe', $payload)->assertUnprocessable();
    expect(Operation::query()->count())->toBe(0)->and($agents->calls)->toBe([]);
})->with([[[]], [['automatic' => false]], [['automatic' => 1]], [['automatic' => '1']], [['automatic' => 'true']]]);
