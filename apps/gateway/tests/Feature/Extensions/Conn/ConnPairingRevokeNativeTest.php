<?php

declare(strict_types=1);

use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->caller = Node::query()->create(['name' => 'phone', 'status' => 'active', 'platform' => 'ios', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
    $this->other = Node::query()->create(['name' => 'other', 'status' => 'active', 'platform' => 'ios', 'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3']);
    $this->environment = ConnEnvironment::query()->create(['environment_id' => 'revoke-native', 'label' => 'test', 'node_id' => $this->gateway->id, 'url' => 'http://t3.test', 'admin_session' => 'private-native-admin', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $this->pairing = ConnPairing::query()->create(['node_id' => $this->caller->id, 'conn_environment_id' => $this->environment->id, 'client_label' => 'owned-label', 'pairing_link_id' => 'owned-link', 'credential' => 'private-pairing']);
    $this->foreign = ConnPairing::query()->create(['node_id' => $this->other->id, 'conn_environment_id' => $this->environment->id, 'client_label' => 'foreign-label', 'pairing_link_id' => 'foreign-link', 'credential' => 'private-foreign']);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->copies = [];
    $this->copyFails = false;
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = function (array $request): array {
        if ($request['type'] === 'vault.put') {
            $this->copies[] = $request['payload']['value'];

            return [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => $this->copyFails ? 'refused' : 'local_encryption']]]];
        }

        return [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => $this->run]]];
    };
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture.shared.token', 'swamp.read_token' => 'fixture.shared.read']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->url = '/api/v1/conn/environments/revoke-native/pairings/revoke';
});

function projectNativeConnRevoke(object $test, string $status = 'succeeded', ?array $result = null): Operation
{
    $operation = Operation::query()->sole();
    $run = ['id' => $test->run, 'workflowName' => 'operation-conn-pairing-revoke', 'status' => $status,
        'inputs' => ['intent' => $operation->input['intent'], 'execution_secrets' => $operation->input['execution_secrets'], 'operation_id' => $operation->id, 'node_id' => $test->gateway->id, 'kind' => 'conn.pairing_revoke'],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'jobs' => $status === 'cancelled' ? [] : [['name' => 'finish', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode($result ?? ['backend' => 'revoked', 'revoked_sessions' => 1])]]]]]];
    $test->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];

    return app(SwampCommandStep::class)->projectTerminal($operation, $run, ['job' => 'finish', 'step' => 'result']);
}

it('admits the exact pairing set with public intent and one vault copy on replay', function (): void {
    $this->withHeader('Idempotency-Key', 'native-conn-revoke')->postJson($this->url)->assertAccepted();
    $operation = Operation::query()->sole();
    expect($operation->input['intent'])->toBe(['url' => 'http://t3.test', 'labels' => ['owned-label'], 'links' => ['owned-link']]);
    expect($operation->input['pairing_ids'])->toBe([$this->pairing->id]);
    expect($operation->input)->not->toHaveKey('environment');
    $start = $this->socket->sent[1];
    expect($start['payload']['workflowIdOrName'])->toBe('operation-conn-pairing-revoke');
    expect($start['payload']['inputs'])->toBe(['intent' => $operation->input['intent'], 'execution_secrets' => $operation->input['execution_secrets'], 'operation_id' => $operation->id, 'kind' => 'conn.pairing_revoke', 'node_id' => $this->gateway->id]);
    expect($this->copies)->toBe(['private-native-admin']);
    $this->postJson($this->url)->assertAccepted()->assertJsonPath('data.id', $operation->id);
    expect($this->copies)->toHaveCount(1);
    expect(json_encode([$operation->input, $start, Activity::query()->get()->toArray()]))->not->toContain('private-native-admin', 'private-pairing', 'private-foreign');
    Http::assertNothingSent();
});

it('projects terminal revocation only onto the captured pairings and replays once', function (): void {
    $this->postJson($this->url)->assertAccepted();
    $later = ConnPairing::query()->create(['node_id' => $this->caller->id, 'conn_environment_id' => $this->environment->id, 'client_label' => 'later-label', 'credential' => 'private-later']);
    projectNativeConnRevoke($this);
    expect($this->pairing->fresh()->revoked_at)->not->toBeNull();
    expect($this->pairing->fresh()->credential)->toBeNull();
    expect($this->foreign->fresh()->credential)->toBe('private-foreign');
    expect($later->fresh()->credential)->toBe('private-later');
    projectNativeConnRevoke($this);
    expect(array_count_values(array_column($this->socket->sent, 'type'))['workflow.history.get'])->toBe(1);
    Http::assertNothingSent();
});

it('preserves pairing state after provider failure or cancellation', function (string $status): void {
    $this->postJson($this->url)->assertAccepted();
    projectNativeConnRevoke($this, $status, $status === 'failed' ? ['error_code' => 'conn.response_invalid'] : []);
    expect($this->pairing->fresh()->revoked_at)->toBeNull();
    expect($this->pairing->fresh()->credential)->toBe('private-pairing');
    expect(Operation::query()->sole()->status->value)->toBe($status);
})->with(['failed', 'cancelled']);

it('rolls back admission when the shared execution copy is refused', function (): void {
    $this->copyFails = true;
    $this->postJson($this->url)->assertStatus(503)->assertJsonPath('error.code', 'swamp.vault_sync_unavailable');
    expect(Operation::query()->count())->toBe(0);
    expect($this->pairing->fresh()->credential)->toBe('private-pairing');
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put']);
});

it('settles native failure without result output and preserves captured pairings', function (string $status): void {
    $this->postJson($this->url)->assertAccepted();
    $operation = Operation::query()->sole();
    $run = ['id' => $this->run, 'workflowName' => 'operation-conn-pairing-revoke', 'status' => $status,
        'inputs' => ['intent' => $operation->input['intent'], 'execution_secrets' => $operation->input['execution_secrets'], 'operation_id' => $operation->id, 'node_id' => $this->gateway->id, 'kind' => 'conn.pairing_revoke'],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'jobs' => [['name' => 'finish', 'steps' => [['name' => 'result', 'status' => 'skipped']]]]];
    $this->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];

    app(SwampCommandStep::class)->projectTerminal($operation, $run, ['job' => 'finish', 'step' => 'result']);
    app(SwampCommandStep::class)->projectTerminal($operation->fresh(), $run, ['job' => 'finish', 'step' => 'result']);

    expect($operation->fresh()->status->value)->toBe('failed');
    expect($operation->fresh()->error_code)->toBe('operation.native_failed');
    expect($operation->fresh()->result)->toBe(['error_code' => 'operation.native_failed']);
    expect($operation->fresh()->state['swamp_run_id'])->toBe($this->run);
    expect($this->pairing->fresh()->revoked_at)->toBeNull();
    expect($this->pairing->fresh()->credential)->toBe('private-pairing');
    expect($this->foreign->fresh()->revoked_at)->toBeNull();
    expect($this->foreign->fresh()->credential)->toBe('private-foreign');
    expect(array_count_values(array_column($this->socket->sent, 'type'))['workflow.history.get'])->toBe(1);
    Http::assertNothingSent();
})->with(['failed', 'interrupted']);
