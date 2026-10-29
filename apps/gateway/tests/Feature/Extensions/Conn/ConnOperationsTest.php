<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Domain\ConnExecutionInput;
use Orbit\Extensions\Conn\Domain\ConnPairingIssueCommand;
use Orbit\Extensions\Conn\Domain\ConnRevocationCommand;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

beforeEach(function (): void {
    // Use the real immutable registry once across this test's HTTP requests.
    $this->app->instance(OperationKinds::class, $this->app->make(OperationKinds::class));
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
    $this->postJson('/api/v1/conn/environments/conn-h-proof/pairings', ['async' => true])->assertAccepted();
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
    expect(json_encode($this->operation->fresh()->getAttributes()))->not->toContain('conn-one-time-sentinel')->not->toContain('fixture-admin-session');
    expect(json_encode($this->operation->steps()->get()->toArray()))->not->toContain('conn-one-time-sentinel');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'http://dev.test/api/auth/pairing-token'
        && $request->hasHeader('Authorization', 'Bearer fixture-admin-session')
        && $request['label'] === $this->pairing->client_label
        && in_array('access:read', $request['scopes'], true)
        && in_array('access:write', $request['scopes'], true)
        && ! in_array('review:write', $request['scopes'], true));

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->getJson('/api/v1/conn/environments/conn-h-proof/pairings/'.$this->pairing->id)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.pairing_url', 'http://dev.test/pair#token=conn-one-time-sentinel');
    expect($this->pairing->fresh()->credential)->toBeNull();
    $this->getJson('/api/v1/conn/environments/conn-h-proof/pairings/'.$this->pairing->id)->assertConflict();
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
        $response = $this->postJson($this->path.'/step', ['worker' => 'conn-h', 'key' => 'forged', 'name' => 'pairing-create', 'status' => 'succeeded',
            'result' => [...$result, 'credential' => $credential]]);
        if (is_array($credential)) {
            $response->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
        } else {
            $response->assertUnprocessable()->assertJsonPath('error.code', 'operation.secret_output_unproven');
        }
    }
    expect($this->operation->steps()->where('status', 'succeeded')->count())->toBe(0);
    expect(json_encode($this->operation->fresh()->getAttributes()))->not->toContain('raw-secret')->not->toContain('conn-one-time-sentinel');
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
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.error_code', 'conn.pairing_outcome_unknown');

    Http::assertSentCount(1);
    expect($this->pairing->fresh()->credential)->toBeNull()->and($this->pairing->fresh()->issue_started_at)->not->toBeNull();
    expect($this->operation->fresh()->state['executor_runs']['pairing-create']['result'])->toBeNull();
});

it('refuses expired sessions and missing environments before admitting another issue', function (): void {
    $this->environment->update(['admin_session_expires_at' => now()->subMinute()]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);

    $this->postJson('/api/v1/conn/environments/conn-h-proof/pairings', ['async' => true])->assertConflict()->assertJsonPath('error.code', 'conn.session_expired');
    $this->postJson('/api/v1/conn/environments/missing/pairings')->assertNotFound();

    expect(Operation::query()->count())->toBe(1)->and(ConnPairing::query()->count())->toBe(1);
    Http::assertNothingSent();
});

it('keeps an invalid generated output out of encrypted state and the keyed receipt', function (): void {
    $credential = 'oversized-output-'.str_repeat('x', 16384);
    Http::fake(['http://dev.test/api/auth/pairing-token' => Http::response(['id' => 'one-time-link',
        'credential' => $credential, 'expiresAt' => now()->addMinutes(5)->toIso8601String()])]);

    $response = $this->postJson($this->path.'/run', $this->run)->assertUnprocessable()->assertJsonPath('error.code', 'conn.pairing_credential_invalid');

    expect($response->getContent())->not->toContain('oversized-output-');
    expect($this->pairing->fresh()->credential)->toBeNull();
    expect($this->operation->fresh()->state['executor_runs']['pairing-create']['result'])->toBeNull();
    Http::assertSentCount(1);
});

it('lets the no-grant originating caller read and cancel only its own Conn Operation', function (): void {
    $path = '/api/v1/conn/operations/'.$this->operation->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->other->wireguard_ip]);
    $this->getJson($path)->assertNotFound();
    $this->call('POST', $path.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertNotFound();
    expect($this->operation->fresh()->cancel_requested_at)->toBeNull();
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->getJson('/api/v1/operations/'.$this->operation->id)->assertForbidden();

    $this->getJson($path)->assertOk()->assertJsonPath('data.id', $this->operation->id)->assertJsonPath('data.kind', 'conn.pairing_create');
    $this->call('POST', $path.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();

    expect($this->operation->fresh()->cancel_requested_at)->not->toBeNull()->and($this->caller->accessibleNodes()->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps the revocation requester separate from its subject for owner access and attribution', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->postJson('/api/v1/conn/environments/conn-h-proof/pairings/revoke', ['node_id' => $this->other->id])->assertAccepted();
    $operation = Operation::query()->where('kind', 'conn.pairing_revoke')->sole();
    expect($operation->target_id)->toBe($this->other->id)->and($operation->callerNodeId())->toBe($this->caller->id)
        ->and($operation->input['subject_node_id'])->toBe($this->other->id);

    $this->getJson('/api/v1/conn/operations/'.$operation->id)->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => $this->other->wireguard_ip]);
    $this->getJson('/api/v1/conn/operations/'.$operation->id)->assertNotFound();
    $this->call('POST', '/api/v1/conn/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertNotFound();
    Http::assertNothingSent();
});

it('keeps a credential unreadable before completion and clears it when expired', function (): void {
    $this->pairing->update(['credential' => 'unread-sentinel', 'pairing_link_id' => 'link', 'expires_at' => now()->addMinute()]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $url = '/api/v1/conn/environments/conn-h-proof/pairings/'.$this->pairing->id;

    $this->getJson($url)->assertConflict();

    expect($this->pairing->fresh()->credential)->toBe('unread-sentinel');
    $this->operation->update(['status' => 'succeeded']);
    $this->travel(2)->minutes();
    $this->getJson($url)->assertConflict();
    expect($this->pairing->fresh()->credential)->toBeNull();
});

it('refuses an already revoked pairing before the issue command contacts its server', function (): void {
    $this->pairing->update(['revoked_at' => now()]);

    expect(fn () => app(ConnPairingIssueCommand::class)->run($this->operation,
        ['environment' => new ConnExecutionInput(['id' => $this->environment->id, 'url' => $this->environment->url, 'admin_session' => 'fixture-admin-session']),
            'pairing_ids' => [$this->pairing->id]]))->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
});

function conn_revocation_admit(object $test, bool $cleanup = false): Operation
{
    $test->withServerVariables(['REMOTE_ADDR' => $cleanup ? $test->services->wireguard_ip : $test->caller->wireguard_ip]);
    $test->postJson('/api/v1/conn/environments/conn-h-proof/'.($cleanup ? 'sessions/cleanup' : 'pairings/revoke'))->assertAccepted();
    $operation = Operation::query()->where('kind', $cleanup ? 'conn.gateway_sessions_revoke' : 'conn.pairing_revoke')->latest('id')->firstOrFail();
    $test->withServerVariables(['REMOTE_ADDR' => $test->services->wireguard_ip]);
    $test->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'conn-h'])->assertOk()->assertJsonPath('data.id', $operation->id);

    return $operation;
}

function conn_revocation_effect(object $test, Operation $operation, string $key, string $action, string $id = ''): array
{
    $path = '/api/v1/operation-executor/'.$operation->id;
    $result = $test->postJson($path.'/run', ['worker' => 'conn-h', 'key' => $key, 'transport' => 'gateway', 'name' => 'conn.revocation',
        'input' => ['environment' => $operation->input['environment'], 'action' => $action, 'id' => $id]])->assertOk()->json('data');
    $test->postJson($path.'/step', ['worker' => 'conn-h', 'key' => $key.'.done', 'name' => $key, 'status' => 'succeeded', 'result' => $result])->assertOk();

    return $result;
}

it('binds every revoke to the owned snapshot and refuses final proof until all exact steps settle', function (): void {
    $this->pairing->update(['pairing_link_id' => 'phone-link', 'credential' => 'phone-sentinel']);
    $other = ConnPairing::query()->create(['node_id' => $this->other->id, 'conn_environment_id' => $this->environment->id,
        'pairing_link_id' => 'other-link', 'client_label' => 'other-label', 'credential' => 'other-sentinel']);
    Http::fake([
        'http://dev.test/api/auth/clients' => Http::response([
            ['sessionId' => 'phone-session', 'client' => ['label' => $this->pairing->client_label], 'current' => false],
            ['sessionId' => 'current', 'client' => ['label' => $this->pairing->client_label], 'current' => true],
            ['sessionId' => 'other-session', 'client' => ['label' => 'other-label'], 'current' => false],
        ]),
        'http://dev.test/api/auth/clients/revoke' => Http::response(['revoked' => true]),
        'http://dev.test/api/auth/pairing-links/revoke' => Http::response(['revoked' => false]),
    ]);
    $operation = conn_revocation_admit($this);
    expect($operation->input['pairing_ids'])->toBe([$this->pairing->id]);
    $path = '/api/v1/operation-executor/'.$operation->id;
    $forged = ['worker' => 'conn-h', 'key' => 'session-0', 'transport' => 'gateway', 'name' => 'conn.revocation',
        'input' => ['environment' => $operation->input['environment'], 'action' => 'session', 'id' => 'other-session']];
    $this->postJson($path.'/run', $forged)->assertUnprocessable();
    Http::assertNothingSent();
    expect(conn_revocation_effect($this, $operation, 'revocation-inspect', 'inspect'))->toBe(['backend' => 'inspected', 'sessions' => ['phone-session'], 'links' => ['phone-link']]);
    $this->postJson($path.'/run', $forged)->assertUnprocessable();
    $final = ['worker' => 'conn-h', 'key' => 'pairing-revoke', 'transport' => 'gateway', 'name' => 'conn.revocation',
        'input' => ['environment' => $operation->input['environment'], 'action' => 'receipt', 'id' => '']];
    $this->postJson($path.'/run', $final)->assertUnprocessable();
    conn_revocation_effect($this, $operation, 'session-0', 'session', 'phone-session');
    $this->postJson($path.'/run', $final)->assertUnprocessable();
    conn_revocation_effect($this, $operation, 'link-0', 'link', 'phone-link');
    $result = $this->postJson($path.'/run', $final)->assertOk()->json('data');
    $this->postJson($path.'/complete', ['worker' => 'conn-h', 'status' => 'succeeded', 'result' => $result])->assertConflict();
    expect($this->pairing->fresh()->revoked_at)->toBeNull();
    $this->postJson($path.'/step', ['worker' => 'conn-h', 'key' => 'final.done', 'name' => 'pairing-revoke', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'conn-h', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect($this->pairing->fresh()->credential)->toBeNull()->and($this->pairing->fresh()->revoked_at)->not->toBeNull()
        ->and($other->fresh()->credential)->toBe('other-sentinel')->and($other->fresh()->revoked_at)->toBeNull();
    Http::assertNotSent(static fn (HttpRequest $request): bool => $request->method() === 'POST' && in_array($request['sessionId'] ?? null, ['current', 'other-session'], true));
});

it('requires Gateway access for cleanup and keeps a newer registration revision pending after completion', function (): void {
    $this->environment->update(['cleanup_version' => 1]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->postJson('/api/v1/conn/environments/conn-h-proof/sessions/cleanup')->assertForbidden();
    Http::assertNothingSent();
    Http::fake(['http://dev.test/api/auth/clients' => Http::response([])]);
    $operation = conn_revocation_admit($this, true);
    expect($operation->input['cleanup_version'])->toBe(1)->and($operation->node_id)->toBe($this->gateway->id);
    conn_revocation_effect($this, $operation, 'revocation-inspect', 'inspect');
    $result = conn_revocation_effect($this, $operation, 'gateway-sessions-revoke', 'receipt');
    $this->environment->update(['cleanup_version' => 2]);
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/complete', ['worker' => 'conn-h', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect($this->environment->fresh()->cleanup_completed_version)->toBe(0);
    $next = conn_revocation_admit($this, true);
    expect($next->input['cleanup_version'])->toBe(2);
    expect(json_encode($operation->fresh()->getAttributes()))->not->toContain('fixture-admin-session');
});

it('does not admit clean revisions and preserves pending cleanup when cancelled', function (): void {
    $this->postJson('/api/v1/conn/environments/conn-h-proof/sessions/cleanup')->assertNoContent();
    $this->environment->update(['cleanup_version' => 1]);
    $operation = conn_revocation_admit($this, true);
    $this->call('POST', '/api/v1/conn/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/complete', ['worker' => 'conn-h', 'status' => 'cancelled', 'result' => []])->assertOk();
    expect($this->environment->fresh()->cleanup_completed_version)->toBe(0);
    Http::assertNothingSent();
});

it('refuses stale hydrated credentials or cleanup revisions before reading or revoking server sessions', function (): void {
    $this->environment->update(['cleanup_version' => 1]);
    $operation = conn_revocation_admit($this, true);
    $command = app(ConnRevocationCommand::class);
    $input = ['environment' => new ConnExecutionInput(['id' => $this->environment->id, 'url' => 'http://dev.test', 'admin_session' => 'fixture-admin-session']), 'action' => 'inspect', 'id' => ''];
    $this->environment->update(['admin_session' => 'new-session']);
    expect($command->run($operation, $input))->toBe(['error_code' => 'conn.environment_changed']);
    $this->environment->update(['admin_session' => 'fixture-admin-session', 'cleanup_version' => 2]);
    expect($command->run($operation, $input))->toBe(['error_code' => 'conn.environment_changed']);
    Http::assertNothingSent();
});
