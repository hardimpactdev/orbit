<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Actions\RegisterConnEnvironmentAction;
use Orbit\Extensions\Conn\Data\RegisterConnEnvironmentData;
use Orbit\Extensions\Conn\Domain\T3AdminSession;
use Orbit\Extensions\Conn\Domain\T3EnvironmentDescriptor;
use Orbit\Extensions\Conn\Domain\T3ServerClient;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->caller = Node::query()->create(['name' => 'phone', 'status' => 'active', 'platform' => 'ios', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
    $this->environment = ConnEnvironment::query()->create(['environment_id' => 'revoke-proof', 'label' => 'test', 'node_id' => $this->gateway->id, 'url' => 'http://t3.test', 'admin_session' => 'private-revoke-admin', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now(), 'cleanup_version' => 1]);
    $this->pairing = ConnPairing::query()->create(['node_id' => $this->caller->id, 'conn_environment_id' => $this->environment->id, 'client_label' => 'phone-label', 'pairing_link_id' => 'phone-link', 'credential' => 'private-pairing']);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->copies = [];
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = function (array $request): array {
        if ($request['type'] === 'vault.put') {
            $this->copies[] = $request['payload']['value'];

            return [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        return [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-conn-direct', 'runId' => $this->run]]];
    };
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture.shared.token', 'swamp.read_token' => 'fixture.shared.read']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->url = '/api/v1/conn/environments/revoke-proof/sessions/cleanup';
});

function nativeGatewayCleanup(object $test, string $status = 'succeeded', array $receipt = ['valid' => true, 'revoked' => 2]): Operation
{
    $operation = Operation::query()->sole();
    $run = ['id' => $test->run, 'workflowName' => 'operation-conn-direct', 'status' => $status,
        'inputs' => ['intent' => $operation->input['intent'], 'execution_secrets' => $operation->input['execution_secrets'], 'operation_id' => $operation->id, 'node_id' => $test->gateway->id, 'kind' => 'conn.gateway_sessions_revoke'],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'jobs' => [['name' => 'conn', 'steps' => [['name' => 'Revoke old sessions', 'status' => $status,
            'outputs' => ['Read session revocations' => ['stdout' => json_encode($receipt, JSON_THROW_ON_ERROR)]]]]]]];

    $test->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];

    return app(SwampCommandStep::class)->projectTerminal($operation, $run, app(OperationKinds::class)->definition($operation->kind)['terminal_result']);
}

it('admits complete public cleanup intent and a vault reference without a Gateway callback', function (): void {
    $this->withHeader('Idempotency-Key', 'cleanup-native')->postJson($this->url)->assertAccepted();
    $operation = Operation::query()->sole();
    expect($operation->input['intent'])->toBe(['url' => 'http://t3.test', 'labels' => ['Orbit Gateway'], 'links' => [], 'revision' => 1]);
    expect($operation->input)->not->toHaveKey('environment');
    expect($operation->lock_key)->toBe('conn_environment:'.$this->environment->id);
    expect($this->copies)->toBe(['private-revoke-admin']);
    $this->postJson($this->url)->assertAccepted()->assertJsonPath('data.id', $operation->id);
    expect($this->copies)->toHaveCount(1);
    expect(json_encode([$operation->input, $this->socket->sent[1], Activity::query()->get()->toArray()]))->not->toContain('private-revoke-admin', 'private-pairing');
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->run)->assertForbidden();
    $this->getJson($path.'/private-material?run_id='.$this->run)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    Http::assertNothingSent();
});

it('projects only the admitted cleanup revision and preserves pairings', function (bool $changed): void {
    $this->postJson($this->url)->assertAccepted();
    if ($changed) {
        $this->environment->update(['cleanup_version' => 2]);
    }
    $operation = nativeGatewayCleanup($this);
    expect($operation->result)->toBe(['backend' => 'revoked', 'revoked_sessions' => 2]);
    expect($this->environment->fresh()->cleanup_completed_version)->toBe($changed ? 0 : 1);
    expect($this->pairing->fresh()->revoked_at)->toBeNull();
    Http::assertNothingSent();
})->with([false, true]);

it('leaves failed or cancelled cleanup pending and rejects an incomplete success receipt', function (string $status): void {
    $this->postJson($this->url)->assertAccepted();
    $operation = nativeGatewayCleanup($this, $status);
    expect($operation->status->value)->toBe($status);
    expect($this->environment->fresh()->cleanup_completed_version)->toBe(0);
    if ($status !== 'cancelled') {
        expect($operation->error_code)->toBe('conn.revocation_failed');
    }
})->with(['failed', 'cancelled']);

it('refuses success without a valid native session receipt', function (): void {
    $this->postJson($this->url)->assertAccepted();
    expect(fn () => nativeGatewayCleanup($this, receipt: ['valid' => false]))->toThrow(ResourceOperationException::class);
    expect($this->environment->fresh()->cleanup_completed_version)->toBe(0);
});

it('keeps cleanup privileged and excludes simultaneous cleanup attempts', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])->postJson($this->url)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])->postJson($this->url)->assertAccepted();
    $this->postJson($this->url)->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    expect(Operation::query()->count())->toBe(1);
});

it('validates registration outside a transaction and rejects a revocation that completed during validation', function (bool $concurrent): void {
    $baseline = DB::transactionLevel();
    $client = Mockery::mock(T3ServerClient::class);
    $client->shouldReceive('describe')->once()->andReturn(new T3EnvironmentDescriptor('revoke-proof', 'test', '0.9.1'));
    $client->shouldReceive('session')->once()->andReturnUsing(function () use ($baseline, $concurrent): T3AdminSession {
        expect(DB::transactionLevel())->toBe($baseline);
        if ($concurrent) {
            Operation::query()->create(['kind' => 'conn.gateway_sessions_revoke', 'target_type' => 'conn_environment', 'target_id' => $this->environment->id,
                'node_id' => $this->gateway->id, 'status' => 'failed', 'sequence' => 1, 'input' => ['conn_environment_id' => $this->environment->id]]);
        }

        return new T3AdminSession('candidate-session', CarbonImmutable::now()->addDay(), ['access:read', 'access:write']);
    });
    $action = new RegisterConnEnvironmentAction($client);
    $data = new RegisterConnEnvironmentData('revoke-proof', 'test', 'http://t3.test', 'candidate-session');
    if ($concurrent) {
        expect(fn () => $action->execute($this->gateway, $data))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('conn.environment_changed'));
        expect($this->environment->fresh()->admin_session)->toBe('private-revoke-admin');
    } else {
        expect($action->execute($this->gateway, $data)->admin_session)->toBe('candidate-session');
    }
})->with([false, true]);

it('admits cleanup after registration commits and keeps its normal response', function (): void {
    $baseline = DB::transactionLevel();
    $reply = $this->socket->reply;
    $this->socket->reply = function (array $request) use ($baseline, $reply): array {
        expect(DB::transactionLevel())->toBe($baseline);
        expect($this->environment->fresh()->admin_session)->toBe('new-admin-session');

        return $reply($request);
    };
    Http::fake([
        'http://t3.test/.well-known/t3/environment' => Http::response(['environmentId' => 'revoke-proof', 'label' => 'test', 'serverVersion' => '0.9.1']),
        'http://t3.test/api/auth/session' => Http::response(['authenticated' => true, 'expiresAt' => now()->addDay()->toIso8601String(), 'scopes' => ['access:read', 'access:write']]),
    ]);
    $this->postJson('/api/v1/conn/environments', ['environment_id' => 'revoke-proof', 'label' => 'test', 'url' => 'http://t3.test', 'admin_session' => 'new-admin-session'])
        ->assertOk()->assertJsonPath('data.cleanup_version', 2)->assertJsonPath('data.cleanup_pending', true)->assertJsonMissingPath('data.admin_session');
    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('conn.gateway_sessions_revoke')->and($operation->input['cleanup_version'])->toBe(2)
        ->and($operation->input['intent']['revision'])->toBe(2)->and($this->copies)->toBe(['new-admin-session']);
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run']);
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/auth/clients'));
    $this->postJson('/api/v1/conn/environments', ['environment_id' => 'revoke-proof', 'label' => 'test', 'url' => 'http://t3.test', 'admin_session' => 'another-session'])
        ->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    expect($this->environment->fresh()->admin_session)->toBe('new-admin-session');
});

it('preserves committed registration when native cleanup cannot start', function (): void {
    $reply = $this->socket->reply;
    $this->socket->reply = fn (array $request): array => $request['type'] === 'vault.put' ? $reply($request) : [['type' => 'error', 'id' => $request['id']]];
    Http::fake([
        'http://t3.test/.well-known/t3/environment' => Http::response(['environmentId' => 'revoke-proof', 'label' => 'test', 'serverVersion' => '0.9.1']),
        'http://t3.test/api/auth/session' => Http::response(['authenticated' => true, 'expiresAt' => now()->addDay()->toIso8601String(), 'scopes' => ['access:read', 'access:write']]),
    ]);
    $this->postJson('/api/v1/conn/environments', ['environment_id' => 'revoke-proof', 'label' => 'test', 'url' => 'http://t3.test', 'admin_session' => 'new-admin-session'])
        ->assertOk()->assertJsonPath('data.cleanup_pending', true);
    expect($this->environment->fresh()->admin_session)->toBe('new-admin-session')
        ->and($this->environment->fresh()->cleanup_completed_version)->toBe(0);
    $operation = Operation::query()->sole();
    expect($operation->status->value)->toBe('failed')->and($operation->error_code)->toBe('swamp.start_refused');
});

it('answers no content without a run when the cleanup revision is complete', function (): void {
    $this->environment->update(['cleanup_completed_version' => 1]);
    $this->postJson($this->url)->assertNoContent();
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});
