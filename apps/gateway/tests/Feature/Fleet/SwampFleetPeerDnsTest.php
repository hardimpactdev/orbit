<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\FleetKitCapabilities;
use App\Domain\Fleet\FleetOperationExecution;
use App\Domain\Fleet\RecipeOperationCompletion;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentCommandRunner;

/** @return array{Node, Node, Node} */
function fleetPeerDnsNodes($test): array
{
    $nodes = [];
    foreach (['gateway' => '1', 'dev' => '2', 'services' => '3'] as $name => $ip) {
        $node = Node::query()->create(['name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.'.$ip, 'user' => 'orbit', 'architecture' => 'x86_64',
            'wireguard_ip' => '10.44.0.'.$ip, 'wireguard_public_key' => str_repeat('A', 43).'=', 'ssh_host_fingerprint' => 'SHA256:managed']);
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

function fleetDnsOperation($test): Operation
{
    [, $node] = fleetPeerDnsNodes($test);
    $response = $test->withHeader('Idempotency-Key', 'peer-dns-proof')->postJson('/api/v1/fleet/nodes/'.$node->id.'/peer-dns')->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $test->withoutHeader('Idempotency-Key')->postJson('/api/v1/operation-executor/claim', ['kind' => 'fleet.peer_dns', 'worker' => 'proof'])->assertOk();

    return $operation;
}

function fleetDnsRun(Operation $operation): array
{
    return ['worker' => 'proof', 'key' => 'peer-dns-verify', 'transport' => 'agent', 'name' => 'wireguard.inspect-peer-dns', 'input' => ['server' => $operation->input['server']]];
}

it('records keyed peer DNS intent without PHP execution and lists only eligible targets', function (): void {
    Queue::fake();
    [$gateway, $node] = fleetPeerDnsNodes($this);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);

    $this->getJson('/api/v1/fleet/peer-dns/targets')->assertOk()->assertJsonPath('data', [$node->id]);
    foreach (range(1, 2) as $_) {
        $this->withHeader('Idempotency-Key', 'peer-dns-proof')->postJson('/api/v1/fleet/nodes/'.$node->id.'/peer-dns')->assertAccepted();
    }
    expect(Operation::query()->count())->toBe(1)
        ->and(Operation::query()->sole()->node_id)->toBe($node->id)
        ->and(Operation::query()->sole()->status)->toBe(OperationStatus::Queued)
        ->and($agents->calls)->toBe([]);
    Queue::assertNothingPushed();
    expect(Operation::query()->sole()->lock_key)->toBe('peer_dns:'.$node->id);
    $this->withHeader('Idempotency-Key', 'peer-dns-other-request')->postJson('/api/v1/fleet/nodes/'.$node->id.'/peer-dns')
        ->assertConflict()->assertJsonPath('error.code', 'operation.target_busy')
        ->assertJsonPath('error.details.operation_id', Operation::query()->sole()->id);
    $this->withoutHeader('Idempotency-Key')->postJson('/api/v1/fleet/nodes/'.$gateway->id.'/peer-dns')->assertUnprocessable()->assertJsonPath('error.code', 'vpn.peer_dns_unmanaged');
});

it('denies fleet maintenance reads without Services identity', function (): void {
    [, $node] = fleetPeerDnsNodes($this);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->getJson('/api/v1/fleet/peer-dns/targets')
        ->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
});

it('refuses other targets commands transports schema and changed admitted policy before execution', function (string $fault): void {
    $operation = fleetDnsOperation($this);
    $data = fleetDnsRun($operation);
    match ($fault) {
        'target' => $data['node_id'] = $operation->node_id + 1,
        'agent' => $data['name'] = 'cli.self-update',
        'remote' => [$data['transport'], $data['name']] = ['remote_program', 'peer-dns'],
        'schema' => $data['input']['server'] = 42,
        'intent' => $data['input']['server'] = '10.44.0.90',
    };

    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', $data)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
})->with(['target', 'agent', 'remote', 'schema', 'intent']);

it('requires recorded proof and the exact admitted DNS policy before successful completion', function (string $fault): void {
    $operation = fleetDnsOperation($this);
    $result = fleetDnsProof();
    if ($fault === 'wrong-policy') {
        $result['domains'] = 'Link 2 (orbit): ~.';
    }
    if ($fault !== 'bare') {
        if ($fault !== 'forged') {
            $agents = new FakeAgentCommandRunner;
            $agents->answer('wireguard.inspect-peer-dns', $result);
            app()->instance(AgentCommandRunner::class, $agents);
            $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', fleetDnsRun($operation))->assertOk();
        }
        $step = $this->postJson('/api/v1/operation-executor/'.$operation->id.'/step', ['worker' => 'proof', 'key' => 'verify:finish',
            'name' => 'peer-dns-verify', 'status' => 'succeeded', 'result' => $result]);
        if ($fault === 'forged') {
            $step->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
        } else {
            $step->assertOk();
        }
    }

    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/complete', ['worker' => 'proof', 'status' => 'succeeded', 'result' => $result])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
})->with(['bare', 'forged', 'wrong-policy']);

it('reuses a proven Node receipt across keyed retries and records one completion', function (): void {
    $operation = fleetDnsOperation($this);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('wireguard.inspect-peer-dns', fleetDnsProof());
    app()->instance(AgentCommandRunner::class, $agents);
    foreach (range(1, 2) as $_) {
        $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', fleetDnsRun($operation))->assertOk();
    }
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/step', ['worker' => 'proof', 'key' => 'verify:finish',
        'name' => 'peer-dns-verify', 'status' => 'succeeded', 'result' => fleetDnsProof()])->assertOk();
    foreach (range(1, 2) as $_) {
        $this->postJson('/api/v1/operation-executor/'.$operation->id.'/complete', ['worker' => 'proof', 'status' => 'succeeded', 'result' => fleetDnsProof()])
            ->assertOk()->assertJsonPath('data.status', 'succeeded');
    }
    expect($agents->calls)->toHaveCount(1);
});

it('redacts secret-bearing run step output and completion values before persistence', function (): void {
    $operation = fleetDnsOperation($this);
    $secret = 'ghp_'.str_repeat('a', 36);
    $exposed = $secret;
    $agents = new FakeAgentCommandRunner;
    $agents->answer('wireguard.inspect-peer-dns', [...fleetDnsProof(), 'state' => $exposed, 'credential' => $secret, 'environment' => ['TOKEN' => $secret]]);
    app()->instance(AgentCommandRunner::class, $agents);
    $response = $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', fleetDnsRun($operation))->assertOk();
    expect($response->getContent())->not->toContain($secret, 'credential', 'environment');
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/step', ['worker' => 'proof', 'key' => 'failure', 'name' => 'peer-dns-verify',
        'status' => 'failed', 'error_code' => 'vpn.peer_dns_reconcile_failed', 'result' => ['state' => $exposed], 'output' => $exposed])->assertOk();
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/complete', ['worker' => 'proof', 'status' => 'failed',
        'error_code' => 'vpn.peer_dns_reconcile_failed', 'result' => ['state' => $exposed, 'credential' => $secret]])->assertOk();
    expect(json_encode($operation->refresh()->toArray()))->not->toContain($secret)
        ->and($operation->steps()->sole()->output)->not->toContain($secret);
});

it('refuses both fleet admission routes to an access-authorized peer that is not an executor', function (string $effect): void {
    [, $node] = fleetPeerDnsNodes($this);
    NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->postJson('/api/v1/fleet/nodes/'.$node->id.'/'.$effect, ['automatic' => false])
        ->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
    expect(Operation::query()->count())->toBe(0)->and($agents->calls)->toBe([]);
})->with(['recipe', 'peer-dns']);

it('refuses an actual automatic recipe run after the window closes or roles become restrictive', function (): void {
    [, $node] = fleetPeerDnsNodes($this);
    $kinds = OperationKinds::definitions();
    // Exercise the live run guard independently of child capability registration.
    $kinds['fleet.recipe'] = [
        'executor' => 'swamp', 'completion' => RecipeOperationCompletion::class,
        'execution' => FleetOperationExecution::class,
        'runs' => [['transport' => 'gateway', 'name' => 'fleet.recipe-begin', 'input_fields' => [], 'input_values' => []]],
        'completion_contract' => ['step' => 'recipe-begin', 'transport' => 'gateway', 'name' => 'fleet.recipe-begin', 'result' => ['started' => true]],
        'result_fields' => ['started', 'error_code'],
    ];
    app()->instance(OperationKinds::class, new OperationKinds(app(), $kinds));
    $node->update(['settings' => ['machine_recipe' => ['version' => 'previous']]]);
    NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $operation = app(StartOperationAction::class)->execute('fleet.recipe', 'node', $node->id, $node->id,
        ['node_id' => $node->id, 'automatic' => true, 'daytime' => true, 'has_php' => false], null, null);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'fleet.recipe', 'worker' => 'window-proof'])->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-10-28T08:30:00Z'));
    // Renew the test lease on the Gateway clock used for the refusal.
    $operation->forceFill(['updated_at' => now()])->save();
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', ['worker' => 'window-proof', 'key' => 'recipe-begin',
        'transport' => 'gateway', 'name' => 'fleet.recipe-begin', 'input' => []])
        ->assertConflict()->assertJsonPath('error.code', 'fleet.window_closed');
    expect($node->refresh()->settings['machine_recipe']['version'])->toBe('previous');
    $this->getJson('/api/v1/fleet/nodes/'.$node->id.'/visit-policy')->assertOk()->assertJsonPath('data.daytime', false)->assertJsonPath('data.window_open', false);
});

it('returns kind unavailable to Services without holding a Node when the visit kit is missing', function (): void {
    if (FleetKitCapabilities::visitsSupported()) {
        test()->markTestSkipped('This base supports the complete visit contract.');
    }
    [, $node] = fleetPeerDnsNodes($this);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson('/api/v1/fleet/nodes/'.$node->id.'/recipe', ['automatic' => true])
        ->assertConflict()->assertJsonPath('error.code', 'operation.kind_unavailable');
    expect(Operation::query()->count())->toBe(0)->and($agents->calls)->toBe([]);
});

it('requires explicit automatic true on the executor recipe route', function (array $payload): void {
    [, $node] = fleetPeerDnsNodes($this);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson('/api/v1/fleet/nodes/'.$node->id.'/recipe', $payload)->assertUnprocessable();
    expect(Operation::query()->count())->toBe(0)->and($agents->calls)->toBe([]);
})->with([[[]], [['automatic' => false]], [['automatic' => 1]], [['automatic' => '1']], [['automatic' => 'true']]]);
