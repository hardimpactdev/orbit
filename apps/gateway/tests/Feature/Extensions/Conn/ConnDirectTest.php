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
    config(['dependency_detection.services_node_id' => $this->worker->id, 'swamp.run_token' => 'private.shared.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->postJson('/api/v1/conn/environments/direct-proof/pairings')->assertAccepted();
    $this->operation = Operation::query()->sole();
    $this->pairing = ConnPairing::query()->sole();
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->result = ['backend' => 'issued', 'pairing_id' => $this->pairing->id, 'pairing_link_id' => 'one-time-link', 'expires_at' => now()->addMinutes(5)->format('Y-m-d\TH:i:s.v\Z')];
    $this->output = ['run_id' => $this->run, 'outputs' => ['credential' => 'private-pairing-sentinel']];
});

it('starts each Conn kind through its fixed native workflow', function (): void {
    $direct = app(DirectOperation::class);
    expect($direct->definition('conn.pairing_create')['workflow'])->toBe('operation-conn-direct');
    expect($direct->writer('conn.pairing_create'))->toBe('direct');
    expect($direct->writer('conn.pairing_revoke'))->toBe('direct');
    expect($direct->writer('conn.gateway_sessions_revoke'))->toBe('direct');
    expect(app(OperationKinds::class)->directOnly('conn.pairing_create'))->toBeTrue();
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
    expect(json_encode([$this->operation->fresh()->toArray(), Activity::query()->get()->toArray()]))->not->toContain('private-pairing-sentinel')->not->toContain('private-admin-sentinel');
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
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Running);
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

it('exposes recorded issue start without a credential so losing native data cannot authorize another issue', function (): void {
    $this->getJson($this->path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input.issue_started', false)->assertJsonPath('data.input.issued', false);
    $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'running', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => ['backend' => 'issuing']])->assertOk();
    $this->getJson($this->path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input.issue_started', true)->assertJsonPath('data.input.issued', false);
    expect($this->pairing->fresh()->issue_started_at)->not->toBeNull();
    expect($this->pairing->fresh()->credential)->toBeNull();
    Http::assertNothingSent();
});

it('refuses private bytes embedded in public pairing link metadata', function (): void {
    $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'running', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => ['backend' => 'issuing']])->assertOk();
    $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'running', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'),
        'result' => [...$this->result, 'pairing_link_id' => 'prefix-private-admin-sentinel-suffix']])->assertUnprocessable();
    expect($this->pairing->fresh()->pairing_link_id)->toBeNull();
});

it('replays recorded terminal pairing metadata after the credential is consumed and revoked', function (): void {
    foreach ([['backend' => 'issuing'], $this->result] as $result) {
        $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'running', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => $result])->assertOk();
    }
    $this->postJson($this->path.'/secret-output', $this->output)->assertOk();
    $projection = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => $this->result];
    $this->postJson($this->path.'/result', $projection)->assertOk();
    $this->pairing->refresh()->update(['credential' => null, 'revoked_at' => now()]);
    $this->environment->update(['admin_session' => 'new-private-session']);

    $this->postJson($this->path.'/result', $projection)->assertOk();
    $this->postJson($this->path.'/result', [...$projection, 'result' => [...$this->result, 'pairing_link_id' => 'changed']])->assertConflict();

    expect($this->pairing->fresh()->credential)->toBeNull();
    expect($this->pairing->fresh()->revoked_at)->not->toBeNull();
    Http::assertNothingSent();
});

it('records shared native dispatch failure without issue or credential effects', function (): void {
    $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'failed', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'),
        'error_code' => 'operation.native_dispatch_failed', 'result' => ['error_code' => 'operation.native_dispatch_failed']])->assertOk();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Failed);
    expect($this->pairing->fresh()->issue_started_at)->toBeNull();
    expect($this->pairing->fresh()->credential)->toBeNull();
    Http::assertNothingSent();
});
