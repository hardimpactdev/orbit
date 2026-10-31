<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->worker = Node::query()->create(['name' => 'swamp', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.17', 'wireguard_ip' => '10.44.0.17']);
    $this->worker->accessibleNodes()->attach($this->gateway);
    $this->caller = Node::query()->create(['name' => 'phone', 'status' => 'active', 'platform' => 'ios', 'wireguard_ip' => '10.44.0.77', 'public_ssh_host' => '10.44.0.77']);
    $this->environment = ConnEnvironment::query()->create(['environment_id' => 'direct-proof', 'label' => 'test', 'node_id' => $this->worker->id, 'url' => 'http://t3.test', 'admin_session' => 'private-admin-sentinel', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-conn-direct', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['operation_executor.worker_node_ids' => [$this->gateway->id], 'dependency_detection.services_node_id' => $this->worker->id, 'swamp.run_token' => 'private.shared.token', 'swamp.direct_writers' => ['conn.pairing_create' => 'direct']]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->postJson('/api/v1/conn/environments/direct-proof/pairings', ['async' => true])->assertAccepted();
    $this->operation = Operation::query()->sole();
    $this->pairing = ConnPairing::query()->sole();
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->result = ['backend' => 'issued', 'pairing_id' => $this->pairing->id, 'pairing_link_id' => 'one-time-link', 'expires_at' => now()->addMinutes(5)->format('Y-m-d\TH:i:s.v\Z')];
    $this->output = ['run_id' => $this->run, 'outputs' => ['credential' => 'private-pairing-sentinel']];
});

it('starts only pairing creation directly by default and freezes the selected writer', function (): void {
    $direct = app(DirectOperation::class);
    config(['swamp.direct_writers' => []]);
    expect($direct->definition('conn.pairing_create')['workflow'])->toBe('operation-conn-direct');
    expect($direct->writer('conn.pairing_create'))->toBe('direct');
    expect($direct->writer('conn.pairing_revoke'))->toBe('direct');
    expect($direct->writer('conn.gateway_sessions_revoke'))->toBe('direct');
    expect(app(OperationKinds::class)->directOnly('conn.pairing_create'))->toBeTrue();
    config(['swamp.direct_writers' => []]);
    expect(DirectOperation::owns($this->operation->fresh()))->toBeTrue();
    expect($this->socket->sent[0]['payload']['inputs'])->toBe(['operation_id' => $this->operation->id, 'kind' => 'conn.pairing_create', 'node_id' => $this->gateway->id]);
    expect($this->operation->state)->not->toHaveKeys(['executor_runs', 'executor_lease_node_id']);
    Http::assertNothingSent();
});

it('keeps private transport out of Operations and Activity and preserves read-once pairing', function (): void {
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.environment.admin_session', 'private-admin-sentinel');
    foreach ([['backend' => 'issuing'], $this->result] as $result) {
        $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'running', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => $result])->assertOk();
    }
    $this->postJson($this->path.'/secret-output', $this->output)->assertOk()->assertJsonPath('data.stored.credential', ['$secret' => 'conn.pairing.credential', 'id' => $this->pairing->id]);
    expect(DB::table('conn_pairings')->where('id', $this->pairing->id)->value('credential'))->not->toContain('private-pairing-sentinel');
    $this->postJson('/api/v1/direct-operations/'.$this->operation->id.'/result', ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => $this->result])->assertOk();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Succeeded);
    expect(json_encode([$this->operation->fresh()->toArray(), $this->operation->steps()->get()->toArray(), Activity::query()->get()->toArray()]))->not->toContain('private-pairing-sentinel')->not->toContain('private-admin-sentinel');
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $url = '/api/v1/conn/environments/direct-proof/pairings/'.$this->pairing->id;
    $this->getJson($url)->assertOk()->assertJsonPath('data.pairing_url', 'http://t3.test/pair#token=private-pairing-sentinel');
    $this->getJson($url)->assertConflict();
    Http::assertNothingSent();
});

it('refuses a foreign run, caller, changed owner and terminal credential access', function (): void {
    $this->getJson($this->path.'/private-material?run_id=20e8138a-cf7f-4c6c-85fd-ff561b575ac8')->assertConflict();
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->pairing->update(['node_id' => $this->worker->id]);
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertUnprocessable();
    $this->postJson($this->path.'/secret-output', $this->output)->assertConflict();
    $this->pairing->update(['node_id' => $this->caller->id]);
    $this->operation->update(['status' => OperationStatus::Failed]);
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertConflict();
    $this->postJson($this->path.'/secret-output', $this->output)->assertConflict();
});

it('rejects raw credentials and unproven metadata in result projection', function (): void {
    foreach ([['backend' => 'issued', 'credential' => 'private-pairing-sentinel'], ['backend' => 'revoked', 'revoked_sessions' => 1], $this->result] as $result) {
        $response = $this->postJson('/api/v1/direct-operations/'.$this->operation->id.'/result', ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => $result]);
        expect($response->status())->toBeIn([409, 422]);
    }
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
});

it('retains the existing credential store replay and revoked-owner rules', function (): void {
    foreach ([['backend' => 'issuing'], $this->result] as $result) {
        $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'running', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => $result])->assertOk();
    }
    $this->postJson($this->path.'/secret-output', $this->output)->assertOk();
    $this->postJson($this->path.'/secret-output', ['run_id' => $this->run, 'outputs' => ['credential' => 'different-credential']])->assertConflict()->assertJsonPath('error.code', 'conn.pairing_outcome_unknown');
    $this->pairing->update(['revoked_at' => now()]);
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertUnprocessable();
    $this->postJson($this->path.'/secret-output', $this->output)->assertConflict();
});

it('refuses a different final expiry and retains the recorded issue metadata', function (): void {
    $this->result['expires_at'] = '2030-01-01T12:05:00.123Z';
    foreach ([['backend' => 'issuing'], $this->result] as $result) {
        $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'running', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => $result])->assertOk();
    }
    $this->postJson($this->path.'/secret-output', $this->output)->assertOk();

    $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => [...$this->result, 'expires_at' => '2030-01-01T12:06:00.123Z']])->assertUnprocessable()->assertJsonPath('error.code', 'operation.result_invalid');

    expect($this->operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($this->pairing->fresh()->expires_at->toIso8601String())->toBe('2030-01-01T12:05:00+00:00');
    $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => $this->result])->assertOk();
});

it('refuses the removed polled writer instead of admitting another pairing', function (): void {
    config(['swamp.direct_writers' => ['conn.pairing_create' => 'polled']]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);

    $this->postJson('/api/v1/conn/environments/direct-proof/pairings', ['async' => true])->assertServiceUnavailable()->assertJsonPath('error.code', 'operation.writer_invalid');

    $this->assertDatabaseCount('operations', 1);
    $this->assertDatabaseCount('conn_pairings', 1);
    Http::assertNothingSent();
});

it('refuses legacy claims and execution contracts after deleting the pairing issue relay', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);

    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'conn.pairing_create', 'worker' => 'old-worker'])->assertUnprocessable()->assertJsonPath('error.code', 'operation.executor_invalid');

    expect($this->operation->fresh()->worker)->toBeNull();
    expect($this->operation->fresh()->attempts)->toBe(0);
    expect($this->operation->fresh()->lock_key)->toBeNull();
    expect(app(OperationKinds::class)->definition('conn.pairing_create'))->not->toHaveKeys(['runs', 'completion', 'completion_contract']);
    Http::assertNothingSent();
});

it('exposes recorded issue start without a credential so losing native data cannot authorize another issue', function (): void {
    $this->getJson($this->path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input.issue_started', false)->assertJsonPath('data.input.issued', false);
    $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'running', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => ['backend' => 'issuing']])->assertOk();
    $this->getJson($this->path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input.issue_started', true)->assertJsonPath('data.input.issued', false);
    expect($this->pairing->fresh()->issue_started_at)->not->toBeNull();
    expect($this->pairing->fresh()->credential)->toBeNull();
    Http::assertNothingSent();
});
