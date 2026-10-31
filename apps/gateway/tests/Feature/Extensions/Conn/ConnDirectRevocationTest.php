<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
});

function directConnRevocation(object $test, string $kind = 'conn.gateway_sessions_revoke'): void
{
    Http::preventStrayRequests();
    $test->caller = Node::query()->create(['name' => 'phone', 'status' => 'active', 'platform' => 'ios', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
    $test->other = Node::query()->create(['name' => 'other', 'status' => 'active', 'platform' => 'ios', 'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3']);
    $test->environment = ConnEnvironment::query()->create(['environment_id' => 'revoke-proof', 'label' => 'test', 'node_id' => $test->gateway->id, 'url' => 'http://t3.test', 'admin_session' => 'private-revoke-admin', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now(), 'cleanup_version' => 1]);
    $test->pairing = ConnPairing::query()->create(['node_id' => $test->caller->id, 'conn_environment_id' => $test->environment->id, 'client_label' => 'phone', 'pairing_link_id' => 'phone-link', 'credential' => 'private-pairing']);
    $test->foreign = ConnPairing::query()->create(['node_id' => $test->other->id, 'conn_environment_id' => $test->environment->id, 'client_label' => 'other', 'pairing_link_id' => 'other-link', 'credential' => 'private-other']);
    $test->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-conn-direct', 'runId' => $test->run]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture.shared.token']);
    $cleanup = $kind === 'conn.gateway_sessions_revoke';
    $test->withServerVariables(['REMOTE_ADDR' => $cleanup ? $test->gateway->wireguard_ip : $test->caller->wireguard_ip]);
    $test->postJson('/api/v1/conn/environments/revoke-proof/'.($cleanup ? 'sessions/cleanup' : 'pairings/revoke'))->assertAccepted();
    $test->operation = Operation::query()->sole();
    $test->path = '/api/v1/direct-operations/'.$test->operation->id;
    $test->withServerVariables(['REMOTE_ADDR' => $test->gateway->wireguard_ip]);
}

it('binds Gateway cleanup to its registration revision and refuses stale private input with 409', function (): void {
    directConnRevocation($this, 'conn.gateway_sessions_revoke');
    $this->getJson($this->path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input.labels', ['Orbit Gateway'])->assertJsonPath('data.input.links', []);
    expect(app(OperationKinds::class)->definition('conn.gateway_sessions_revoke'))->not->toHaveKeys(['runs', 'execution', 'completion_contract']);
    $this->environment->update(['cleanup_version' => 2]);

    foreach (['?run_id=', '/private-material?run_id='] as $suffix) {
        $this->getJson($this->path.$suffix.$this->run)->assertConflict()->assertJsonPath('error.code', 'conn.environment_changed');
    }
    expect($this->environment->fresh()->cleanup_completed_version)->toBe(0);
    Http::assertNothingSent();
});

it('projects only the admitted cleanup revision and leaves a newer registration pending', function (bool $changed): void {
    directConnRevocation($this, 'conn.gateway_sessions_revoke');
    if ($changed) {
        $this->environment->update(['cleanup_version' => 2]);
    }

    $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => ['backend' => 'revoked', 'revoked_sessions' => 0]])->assertOk();

    expect($this->environment->fresh()->cleanup_completed_version)->toBe($changed ? 0 : 1);
    expect($this->pairing->fresh()->revoked_at)->toBeNull();
    expect($this->foreign->fresh()->revoked_at)->toBeNull();
    Http::assertNothingSent();
})->with([false, true]);

it('keeps cleanup privileged, preserves a cancelled revision without marking cleanup complete', function (): void {
    directConnRevocation($this, 'conn.gateway_sessions_revoke');
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->postJson('/api/v1/conn/environments/revoke-proof/sessions/cleanup')->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->call('POST', '/api/v1/conn/operations/'.$this->operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();
    $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'cancelled', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => []])->assertOk();
    expect($this->environment->fresh()->cleanup_completed_version)->toBe(0);
    Http::assertNothingSent();
});
