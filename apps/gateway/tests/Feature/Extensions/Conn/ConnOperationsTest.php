<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

beforeEach(function (): void {
    if (! OperationKinds::supports('secret_outputs')) {
        $this->markTestSkipped('The Conn creation integration requires Kit H.');
    }
    $this->freezeTime();
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit']));
    $this->services = Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.17', 'wireguard_ip' => '10.44.0.17', 'user' => 'orbit']);
    $this->services->accessibleNodes()->attach($this->gateway);
    config(['dependency_detection.services_node_id' => $this->services->id]);
    $this->caller = Node::query()->create(['name' => 'phone', 'status' => 'active', 'platform' => 'ios', 'public_ssh_host' => '10.44.0.77', 'wireguard_ip' => '10.44.0.77']);
    $this->other = Node::query()->create(['name' => 'laptop', 'status' => 'active', 'platform' => 'macos', 'public_ssh_host' => '10.44.0.78', 'wireguard_ip' => '10.44.0.78']);
    $this->environment = ConnEnvironment::query()->create(['environment_id' => 'conn-h-proof', 'label' => 'dev', 'node_id' => $this->services->id,
        'url' => 'http://dev.test', 'admin_session' => 'fixture-admin-session', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->postJson('/api/v1/conn/environments/conn-h-proof/pairings')->assertAccepted();
    $this->operation = Operation::query()->sole();
    $this->pairing = ConnPairing::query()->sole();
    $this->path = '/api/v1/operation-executor/'.$this->operation->id;
    $this->reference = ['$secret' => 'conn.pairing.credential', 'id' => $this->pairing->id];
    $this->withServerVariables(['REMOTE_ADDR' => $this->services->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'conn.pairing_create', 'worker' => 'conn-h'])->assertOk();
    $this->run = ['worker' => 'conn-h', 'key' => 'pairing-create', 'transport' => 'gateway', 'name' => 'conn.pairing-issue',
        'input' => ['environment' => $this->operation->input['environment'], 'pairing_ids' => [$this->pairing->id]]];
});

it('stores one encrypted output and preserves its exact reference through replay, steps and completion', function (): void {
    Http::fake(['http://dev.test/api/auth/pairing-token' => Http::response(['id' => 'one-time-link',
        'credential' => 'conn-one-time-sentinel', 'expiresAt' => now()->addMinutes(5)->toIso8601String()])]);

    $response = $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.credential', $this->reference);
    $result = $response->json('data');
    $encrypted = DB::table('conn_pairings')->where('id', $this->pairing->id)->value('credential');
    expect($encrypted)->not->toContain('conn-one-time-sentinel');
    expect($this->pairing->fresh()->credential)->toBe('conn-one-time-sentinel');
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data', $result);
    expect(DB::table('conn_pairings')->where('id', $this->pairing->id)->value('credential'))->toBe($encrypted);
    $this->postJson($this->path.'/step', ['worker' => 'conn-h', 'key' => 'pairing-create.done', 'name' => 'pairing-create', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'conn-h', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect(json_encode($this->operation->fresh()->getAttributes()))->not->toContain('conn-one-time-sentinel', 'fixture-admin-session');
    expect(json_encode($this->operation->steps()->get()->toArray()))->not->toContain('conn-one-time-sentinel');
    Http::assertSentCount(1);

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->getJson('/api/v1/conn/environments/conn-h-proof/pairings/'.$this->pairing->id)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.pairing_url', 'http://dev.test/pair#token=conn-one-time-sentinel');
    $list = $this->getJson('/api/v1/conn/environments/conn-h-proof/pairings')->assertOk();
    expect($list->getContent())->not->toContain('conn-one-time-sentinel');
    $this->withServerVariables(['REMOTE_ADDR' => $this->other->wireguard_ip]);
    $this->getJson('/api/v1/conn/environments/conn-h-proof/pairings/'.$this->pairing->id)->assertNotFound();
});

it('refuses a changed run or raw submitted credential and requires proven step completion', function (): void {
    Http::fake(['http://dev.test/api/auth/pairing-token' => Http::response(['id' => 'one-time-link',
        'credential' => 'conn-one-time-sentinel', 'expiresAt' => now()->addMinutes(5)->toIso8601String()])]);
    $this->postJson($this->path.'/run', [...$this->run, 'input' => ['environment' => ['$secret' => 'conn.environment', 'id' => $this->environment->id + 1], 'pairing_ids' => [$this->pairing->id]]])->assertUnprocessable();
    Http::assertNothingSent();
    $result = $this->postJson($this->path.'/run', $this->run)->assertOk()->json('data');
    $this->postJson($this->path.'/complete', ['worker' => 'conn-h', 'status' => 'succeeded', 'result' => $result])->assertConflict()
        ->assertJsonPath('error.code', 'operation.completion_unproven');
    foreach (['raw-secret', true, ['$secret' => 'conn.pairing.credential', 'id' => $this->pairing->id + 1]] as $credential) {
        $this->postJson($this->path.'/step', ['worker' => 'conn-h', 'key' => 'forged', 'name' => 'pairing-create', 'status' => 'succeeded',
            'result' => [...$result, 'credential' => $credential]])->assertUnprocessable();
    }
    expect($this->operation->steps()->where('status', 'succeeded')->count())->toBe(0);
    expect(json_encode($this->operation->fresh()->getAttributes()))->not->toContain('raw-secret', 'conn-one-time-sentinel');
});

it('refuses a changed output owner before calling the pairing server', function (): void {
    $this->pairing->update(['node_id' => $this->other->id]);

    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.secret_output_unavailable');

    expect($this->pairing->fresh()->credential)->toBeNull();
    Http::assertNothingSent();
});

it('keeps an uncertain issue fenced and never mints a replacement automatically', function (): void {
    Http::fake(['http://dev.test/api/auth/pairing-token' => Http::failedConnection()]);

    $this->postJson($this->path.'/run', $this->run)->assertServerError();
    $this->postJson($this->path.'/run', $this->run)->assertConflict();

    Http::assertSentCount(1);
    expect($this->pairing->fresh()->credential)->toBeNull()->and($this->pairing->fresh()->issue_started_at)->not->toBeNull();
    expect($this->operation->fresh()->state['executor_runs']['pairing-create']['result'])->toBeNull();
});
