<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Domain\ConnExecutionInput;
use Orbit\Extensions\Conn\Domain\ConnRevocationCommand;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Tests\Support\FakeRoutePublicationSocket;

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
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-conn-direct', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture.shared.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->postJson('/api/v1/conn/environments/conn-h-proof/pairings', ['async' => true])->assertAccepted();
    $this->operation = Operation::query()->sole();
    $this->pairing = ConnPairing::query()->sole();

});

it('refuses expired sessions and missing environments before admitting another issue', function (): void {
    $this->environment->update(['admin_session_expires_at' => now()->subMinute()]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);

    $this->postJson('/api/v1/conn/environments/conn-h-proof/pairings', ['async' => true])->assertConflict()->assertJsonPath('error.code', 'conn.session_expired');
    $this->postJson('/api/v1/conn/environments/missing/pairings')->assertNotFound();

    expect(Operation::query()->count())->toBe(1)->and(ConnPairing::query()->count())->toBe(1);
    Http::assertNothingSent();
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
    $this->withServerVariables(['REMOTE_ADDR' => $this->services->wireguard_ip]);
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

it('records an invalid clients read as a terminal inspection failure without revoking anything', function (): void {
    $this->environment->update(['cleanup_version' => 1]);
    Http::fake(['http://dev.test/api/auth/clients' => Http::response('<!doctype html><title>Conn</title>', 200, ['Content-Type' => 'text/html'])]);
    $operation = conn_revocation_admit($this, cleanup: true);
    $path = '/api/v1/operation-executor/'.$operation->id;
    $run = ['worker' => 'conn-h', 'key' => 'revocation-inspect', 'transport' => 'gateway', 'name' => 'conn.revocation',
        'input' => ['environment' => $operation->input['environment'], 'action' => 'inspect', 'id' => '']];
    $result = $this->postJson($path.'/run', $run)->assertOk()->assertJsonPath('data.error_code', 'conn.response_invalid')->json('data');
    $this->postJson($path.'/run', $run)->assertOk()->assertJsonPath('data', $result);
    $this->postJson($path.'/step', ['worker' => 'conn-h', 'key' => 'inspect.failed', 'name' => 'revocation-inspect',
        'status' => 'failed', 'result' => $result, 'error_code' => 'conn.response_invalid'])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'conn-h', 'status' => 'failed', 'result' => $result,
        'error_code' => 'conn.response_invalid'])->assertOk()->assertJsonPath('data.status', 'failed');
    expect($operation->fresh()->lock_key)->toBeNull()
        ->and($this->environment->fresh()->cleanup_completed_version)->toBe(0);
    Http::assertSentCount(1);
    Http::assertNotSent(static fn (HttpRequest $request): bool => $request->method() === 'POST');
});
