<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeWireGuardKeyProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Nodes\NativeNodeProvisioningLock;
use App\Models\Operation;
use Orbit\Sdk\Requests\Nodes\SetNodeWireGuardKeyRequest;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-10-31T12:00:00Z'));
    FleetFixtures::bind();
    $this->hub = FleetFixtures::node('key-hub', [RoleName::Vpn]);
    $this->gateway = $this->markAsGateway(FleetFixtures::node('key-gateway', [RoleName::Gateway]));
    $this->peer = FleetFixtures::node('key-peer');
    $this->peer->update(['ssh_host_fingerprint' => null, 'wireguard_public_key' => base64_encode(str_repeat('a', 32))]);
    $this->old = $this->peer->wireguard_public_key;
    $this->key = base64_encode(str_repeat('b', 32));
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $agent = Mockery::mock(AgentCommandRunner::class);
    $agent->shouldNotReceive('run', 'runOver', 'install');
    app()->instance(AgentCommandRunner::class, $agent);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture.run.token', 'swamp_workers.nodes' => []]);
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: 'orbit');
    $this->endpoint = "/api/v1/nodes/{$this->peer->id}/wireguard-key";
    @mkdir(config('orbit.home').'/wireguard', 0700, true);
    file_put_contents(config('orbit.home').'/wireguard/public.key', base64_encode(str_repeat('h', 32)));
});

it('captures the exact prior entry before key mutation and starts only its native root', function (): void {
    $ack = $this->patchJson("/api/v1/nodes/{$this->peer->id}/wireguard-key", ['wireguard_public_key' => $this->key])->assertAccepted();
    record_fixture($ack, 'nodes/node-wireguard-key/updated', SetNodeWireGuardKeyRequest::class, 'PATCH /api/v1/nodes/{node}/wireguard-key');
    $operation = Operation::query()->findOrFail($ack->json('data.id'));
    expect($operation->kind)->toBe('node.wireguard_key')
        ->and($operation->input['hub_intent']['before']['public_key'])->toBe($this->old)
        ->and($operation->input['public_key'])->toBe($this->key)
        ->and($this->peer->refresh()->wireguard_public_key)->toBe($this->old)
        ->and($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-node-wireguard-key');
    $input = app(NodeWireGuardKeyProjection::class)->input($operation);
    expect($input['intent']['prepared_peer'])->toBe(['node_id' => $this->peer->id, 'public_key' => $this->key]);
});

it('commits only native success and makes terminal replay idempotent', function (string $status): void {
    $ack = $this->patchJson("/api/v1/nodes/{$this->peer->id}/wireguard-key", ['wireguard_public_key' => $this->key])->assertAccepted();
    $operation = Operation::query()->findOrFail($ack->json('data.id'));
    $data = ['run_id' => $operation->state['swamp_run_id'], 'status' => $status, 'started_at' => now()->toISOString(),
        'finished_at' => now()->toISOString(), 'error_code' => $status === 'succeeded' ? null : 'wireguard.hub_conflict',
        'result' => ['projected' => $status === 'succeeded']];
    $projection = app(NodeWireGuardKeyProjection::class);
    $projection->apply($operation, $data);
    $projection->apply($operation->refresh(), $data);
    expect($this->peer->refresh()->wireguard_public_key)->toBe($status === 'succeeded' ? $this->key : $this->old);
})->with(['succeeded', 'failed', 'cancelled']);

it('preserves managed and Gateway key refusals before an operation exists', function (bool $gateway): void {
    if ($gateway) {
        $this->peer->roles()->create(['role' => RoleName::Gateway]);
    } else {
        $this->peer->update(['ssh_host_fingerprint' => 'SHA256:pinned']);
    }
    $response = $this->patchJson("/api/v1/nodes/{$this->peer->id}/wireguard-key", ['wireguard_public_key' => $this->key])->assertConflict();
    if ($gateway) {
        record_fixture($response, 'nodes/node-wireguard-key/gateway-refused', SetNodeWireGuardKeyRequest::class, 'PATCH /api/v1/nodes/{node}/wireguard-key');
    }
    expect(Operation::query()->exists())->toBeFalse()->and($this->peer->refresh()->wireguard_public_key)->toBe($this->old);
})->with([false, true]);

it('rejects invalid bodies before writes or projection', function (string $body): void {
    $this->call('PATCH', $this->endpoint, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $body)
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(Operation::query()->exists())->toBeFalse()
        ->and($this->peer->refresh()->wireguard_public_key)->toBe($this->old)
        ->and($this->socket->sent)->toBe([]);
})->with([
    'missing' => '{}', 'null' => '{"wireguard_public_key":null}', 'empty' => '{"wireguard_public_key":""}',
    'number' => '{"wireguard_public_key":42}', 'array' => '{"wireguard_public_key":[]}',
    'malformed' => '{"wireguard_public_key":"invalid"}',
    'short' => json_encode(['wireguard_public_key' => base64_encode(str_repeat('a', 31))], JSON_THROW_ON_ERROR),
    'long' => json_encode(['wireguard_public_key' => base64_encode(str_repeat('a', 33))], JSON_THROW_ON_ERROR),
    'whitespace' => json_encode(['wireguard_public_key' => ' '.base64_encode(str_repeat('a', 32))], JSON_THROW_ON_ERROR),
    'noncanonical' => '{"wireguard_public_key":"'.str_repeat('A', 42).'B="}',
    'unknown' => '{"wireguard_public_key":"'.base64_encode(str_repeat('a', 32)).'","private_key":"do not record"}',
    'duplicate' => '{"wireguard_public_key":"bad","wireguard_public_key":"'.base64_encode(str_repeat('a', 32)).'"}',
    'escaped duplicate' => '{"wireguard_public_key":"bad","wireguard_public_\\u006bey":"'.base64_encode(str_repeat('a', 32)).'"}',
    'not object' => '[]', 'broken json' => '{',
]);

it('preserves the managed boundary on both platforms in every state', function (string $platform, string $status): void {
    $this->peer->update(['platform' => $platform, 'status' => $status, 'ssh_host_fingerprint' => 'SHA256:pinned']);
    $this->patchJson($this->endpoint, ['wireguard_public_key' => $this->key])
        ->assertConflict()->assertJsonPath('error.code', 'node.wireguard_key_managed');
    expect(Operation::query()->exists())->toBeFalse()->and($this->socket->sent)->toBe([]);
})->with(['linux', 'macos'])->with(['active', 'failed', 'provisioning', 'removing']);

it('requires an address and respects lifecycle contention before admission', function (): void {
    new NativeNodeProvisioningLock()->run($this->peer->name, function (): void {
        $this->patchJson($this->endpoint, ['wireguard_public_key' => $this->key])
            ->assertConflict()->assertJsonPath('error.code', 'node.provisioning_busy');
    });
    $this->peer->update(['wireguard_ip' => null]);
    $this->patchJson($this->endpoint, ['wireguard_public_key' => $this->key])
        ->assertUnprocessable()->assertJsonPath('error.code', 'node.wireguard_ip_required');
    expect(Operation::query()->exists())->toBeFalse()->and($this->socket->sent)->toBe([]);
});

it('refuses another peer or hub key without disclosing its owner', function (bool $hub): void {
    $other = FleetFixtures::node('hidden-peer');
    $other->update(['wireguard_public_key' => base64_encode(str_repeat('z', 32))]);
    $key = $hub ? base64_encode(str_repeat('h', 32)) : $other->wireguard_public_key;
    $this->patchJson($this->endpoint, ['wireguard_public_key' => $key])
        ->assertConflict()->assertJsonPath('error.code', 'node.wireguard_key_conflict')->assertDontSee('hidden-peer');
    expect(Operation::query()->exists())->toBeFalse()->and($this->peer->refresh()->wireguard_public_key)->toBe($this->old);
})->with([false, true]);

it('denies a caller without Node access before admitting a mutation', function (): void {
    $caller = FleetFixtures::node('key-no-access');
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this->patchJson($this->endpoint, ['wireguard_public_key' => $this->key])->assertForbidden();
    expect(Operation::query()->exists())->toBeFalse()->and($this->socket->sent)->toBe([]);
});

it('reapplies an unchanged key through the native hub root', function (): void {
    $this->patchJson($this->endpoint, ['wireguard_public_key' => $this->old])->assertAccepted();
    expect(Operation::query()->sole()->input['public_key'])->toBe($this->old)
        ->and($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-node-wireguard-key');
});
