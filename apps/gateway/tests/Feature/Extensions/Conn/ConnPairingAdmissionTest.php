<?php

declare(strict_types=1);

use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Tests\Support\FakeRoutePublicationSocket;

it('returns the native Operation immediately and replays admission without consuming its credential', function (): void {
    Http::preventStrayRequests();
    Sleep::fake();
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $caller = Node::query()->create(['name' => 'phone', 'status' => 'active', 'platform' => 'ios', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
    ConnEnvironment::query()->create(['environment_id' => 'native-admission', 'label' => 'proof', 'url' => 'http://dev.test',
        'admin_session' => 'fixture-session', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => $request['type'] === 'vault.put' ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-conn-pairing-create', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture.shared.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this->withHeader('Idempotency-Key', 'conn-admission');

    $response = $this->postJson('/api/v1/conn/environments/native-admission/pairings')->assertAccepted()
        ->assertJsonPath('data.kind', 'conn.pairing_create')->assertJsonMissingPath('data.pairing_url');
    $operation = Operation::query()->sole();
    expect($operation->node_id)->toBe($gateway->id);
    $pairing = ConnPairing::query()->sole();
    $pairing->update(['pairing_link_id' => 'fixture-link', 'credential' => 'one-time-sentinel', 'expires_at' => now()->addMinutes(5)]);
    $operation->update(['status' => 'succeeded']);
    $this->postJson('/api/v1/conn/environments/native-admission/pairings')->assertAccepted()->assertJsonPath('data.id', $response->json('data.id'));

    $this->assertDatabaseCount('operations', 1);
    expect($pairing->fresh()->credential)->toBe('one-time-sentinel');
    expect($socket->sent)->toHaveCount(2);
    $path = '/api/v1/conn/environments/native-admission/pairings/'.$pairing->id;
    $this->getJson($path)->assertOk()->assertJsonPath('data.pairing_url', 'http://dev.test/pair#token=one-time-sentinel');
    $this->getJson($path)->assertConflict();
    Sleep::assertNeverSlept();
    Http::assertNothingSent();
});
