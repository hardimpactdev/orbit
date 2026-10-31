<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
});

function directConnRevocation(object $test, string $kind = 'conn.pairing_revoke'): void
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
    config(['swamp.run_token' => 'fixture.shared.token', 'swamp.direct_writers' => [], 'operation_executor.worker_node_ids' => [$test->gateway->id]]);
    $cleanup = $kind === 'conn.gateway_sessions_revoke';
    $test->withServerVariables(['REMOTE_ADDR' => $cleanup ? $test->gateway->wireguard_ip : $test->caller->wireguard_ip]);
    $test->postJson('/api/v1/conn/environments/revoke-proof/'.($cleanup ? 'sessions/cleanup' : 'pairings/revoke'))->assertAccepted();
    $test->operation = Operation::query()->sole();
    $test->path = '/api/v1/direct-operations/'.$test->operation->id;
    $test->withServerVariables(['REMOTE_ADDR' => $test->gateway->wireguard_ip]);
}

it('admits revocation directly with only owned labels and links and no legacy claim', function (): void {
    directConnRevocation($this);

    $this->getJson($this->path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input.labels', ['phone'])->assertJsonPath('data.input.links', ['phone-link']);
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.environment.admin_session', 'private-revoke-admin');

    expect(app(DirectOperation::class)->writer('conn.pairing_revoke'))->toBe('direct');
    expect(app(OperationKinds::class)->definition('conn.pairing_revoke'))->not->toHaveKeys(['runs', 'completion', 'execution', 'completion_contract']);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'conn.pairing_revoke', 'worker' => 'old-worker'])->assertUnprocessable();
    expect($this->operation->fresh()->attempts)->toBe(0);
    expect($this->operation->fresh()->lock_key)->toBeNull();
    Http::assertNothingSent();
});

it('projects successful revocation only onto the admitted pairings without secret state', function (): void {
    directConnRevocation($this);

    $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => ['backend' => 'revoked', 'revoked_sessions' => 1]])->assertOk();

    expect($this->pairing->fresh()->revoked_at)->not->toBeNull();
    expect($this->pairing->fresh()->credential)->toBeNull();
    expect($this->foreign->fresh()->credential)->toBe('private-other');
    expect(json_encode([$this->operation->fresh()->toArray(), $this->operation->steps()->get()->toArray(), Activity::query()->get()->toArray()]))->not->toContain('private-revoke-admin')->not->toContain('private-pairing')->not->toContain('private-other');
    Http::assertNothingSent();
});

it('refuses a foreign run, a changed pairing owner and the deleted revocation writer', function (): void {
    directConnRevocation($this);
    $this->getJson($this->path.'/private-material?run_id=20e8138a-cf7f-4c6c-85fd-ff561b575ac8')->assertConflict();
    $this->pairing->update(['node_id' => $this->other->id]);
    $this->getJson($this->path.'?run_id='.$this->run)->assertUnprocessable();
    config(['swamp.direct_writers' => ['conn.pairing_revoke' => 'polled']]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->postJson('/api/v1/conn/environments/revoke-proof/pairings/revoke')->assertServiceUnavailable()->assertJsonPath('error.code', 'operation.writer_invalid');
    $this->assertDatabaseCount('operations', 1);
    Http::assertNothingSent();
});
