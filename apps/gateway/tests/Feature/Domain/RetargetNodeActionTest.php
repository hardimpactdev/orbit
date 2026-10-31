<?php

declare(strict_types=1);

use App\Actions\Nodes\RetargetNodeAction;
use App\Data\Nodes\RetargetNodeData;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\NodeRetargetProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Nodes\NativeNodeProvisioningLock;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    $this->gateway = $this->markAsGateway(FleetFixtures::node('retarget-gateway', [RoleName::Gateway]));
    app(GatewayServingHost::class)->remember($this->gateway);
    $this->hub = FleetFixtures::node('retarget-hub', [RoleName::Vpn]);
    $this->peer = FleetFixtures::node('retarget-peer');
    $this->peer->update(['user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAA',
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('a', 43)]);
    $this->prior = $this->peer->only(['public_ssh_host', 'public_ssh_port', 'wireguard_public_key']);
    $this->identity = config('orbit.home').'/retarget-identity';
    file_put_contents($this->identity, 'synthetic-retarget-identity');
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn($this->identity);
    app()->instance(SshKeyProvider::class, $keys);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'runOver');
    app()->instance(AgentCommandRunner::class, $agents);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static fn (array $request): array => $request['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => '2371ac7a-5a0b-4a14-9261-8456cb658fae']]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture-token']);
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: 'orbit');
    @mkdir(config('orbit.home').'/wireguard', 0700, true);
    file_put_contents(config('orbit.home').'/wireguard/public.key', base64_encode(str_repeat('h', 32)));
});

it('captures immutable candidate and exact hub context without modifying the current endpoint', function (): void {
    $operation = app(RetargetNodeAction::class)->execute(new RetargetNodeData($this->peer->name, '192.0.2.100', 2222));
    expect($operation->kind)->toBe('node.retarget')->and($operation->node_id)->toBe($this->gateway->id)
        ->and($operation->target_id)->toBe($this->peer->id)
        ->and($operation->input['intent']['target']['host'])->toBe('192.0.2.100')
        ->and($operation->input['intent']['hub']['context_node_id'])->toBe($this->peer->id)
        ->and($this->peer->refresh()->only(array_keys($this->prior)))->toBe($this->prior)
        ->and(json_encode($operation->input))->not->toContain('synthetic-retarget-identity');
});

it('verifies an existing role owner through WireGuard without scheduling peer or hub changes', function (): void {
    $this->peer->roles()->create(['role' => RoleName::CiRunner]);
    $operation = app(RetargetNodeAction::class)->execute(new RetargetNodeData($this->peer->name, '192.0.2.100', 2222));
    expect($operation->input['intent']['has_roles'])->toBeTrue()
        ->and($operation->input['intent']['target']['host'])->toBe($this->peer->wireguard_ip)
        ->and($operation->input['intent']['target']['port'])->toBe(22)
        ->and($operation->input['intent'])->not->toHaveKeys(['hub', 'network']);
});

it('commits the new endpoint only on terminal success, once', function (string $status): void {
    $operation = app(RetargetNodeAction::class)->execute(new RetargetNodeData($this->peer->name, '192.0.2.100', 2222));
    $hosts = Mockery::mock(KnownHostsStore::class);
    $status === 'succeeded' ? $hosts->shouldReceive('put')->once() : $hosts->shouldNotReceive('put');
    app()->instance(KnownHostsStore::class, $hosts);
    $data = ['run_id' => $operation->state['swamp_run_id'], 'status' => $status, 'started_at' => now()->toISOString(),
        'finished_at' => now()->toISOString(), 'error_code' => $status === 'succeeded' ? null : 'node.retarget_failed',
        'result' => ['retargeted' => $status === 'succeeded', 'restored' => $status !== 'succeeded']];
    $projection = app(NodeRetargetProjection::class);
    $projection->apply($operation, $data);
    $projection->apply($operation->refresh(), $data);
    expect($this->peer->refresh()->public_ssh_host)->toBe($status === 'succeeded' ? '192.0.2.100' : $this->prior['public_ssh_host']);
})->with(['succeeded', 'failed', 'cancelled']);

it('rejects missing, inactive, and unpinned Nodes before copying credentials', function (string $state): void {
    if ($state === 'missing') {
        $this->peer->delete();
    } elseif ($state === 'fingerprint') {
        $this->peer->update(['ssh_host_fingerprint' => null]);
    } elseif ($state === 'unpinned') {
        $this->peer->update(['ssh_host_key' => null]);
    } else {
        $this->peer->update(['status' => $state]);
    }
    expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData($this->peer->name, '192.0.2.100', 22)))
        ->toThrow(ResourceOperationException::class);
    expect(Operation::query()->exists())->toBeFalse()->and($this->socket->sent)->toBe([]);
})->with(['missing', 'failed', 'provisioning', 'removing', 'unpinned', 'fingerprint']);

it('rejects malformed endpoint intent before copying credentials', function (string $host, int $port): void {
    expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData($this->peer->name, $host, $port)))
        ->toThrow(ResourceOperationException::class);
    expect(Operation::query()->exists())->toBeFalse()->and($this->socket->sent)->toBe([]);
})->with([['', 22], ['bad host', 22], ['192.0.2.100', 0], ['192.0.2.100', 65536]]);

it('honors the existing lifecycle guard before admission', function (): void {
    new NativeNodeProvisioningLock()->run($this->peer->name, function (): void {
        expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData($this->peer->name, '192.0.2.100', 22)))
            ->toThrow(ResourceOperationException::class);
    });
    expect(Operation::query()->exists())->toBeFalse()->and($this->socket->sent)->toBe([])
        ->and($this->peer->refresh()->only(array_keys($this->prior)))->toBe($this->prior);
});

it('admits the console request and leaves the endpoint unchanged until native completion', function (): void {
    $this->artisan('orbit:node-retarget', ['name' => $this->peer->name, 'host' => '192.0.2.100', '--ssh-port' => '2222'])
        ->expectsOutputToContain('Node retarget Operation [')->assertSuccessful();
    expect(Operation::query()->sole()->kind)->toBe('node.retarget')
        ->and($this->peer->refresh()->only(array_keys($this->prior)))->toBe($this->prior);
});

it('reports console contention without remote output or state changes', function (): void {
    new NativeNodeProvisioningLock()->run($this->peer->name, function (): void {
        $this->artisan('orbit:node-retarget', ['name' => $this->peer->name, 'host' => '192.0.2.100'])
            ->expectsOutput('Node retarget failed with error [node.provisioning_busy].')->assertFailed();
    });
    expect(Operation::query()->exists())->toBeFalse()->and($this->socket->sent)->toBe([]);
});
