<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
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
    $receipts = $result ?? ['session_receipt' => ['valid' => true, 'completed' => [0], 'revoked' => 1], 'link_receipt' => ['valid' => true, 'completed' => [0], 'revoked' => 1]];
    $run = ['id' => $test->run, 'workflowName' => 'operation-conn-pairing-revoke', 'status' => $status,
        'inputs' => ['intent' => $operation->input['intent'], 'execution_secrets' => $operation->input['execution_secrets'], 'operation_id' => $operation->id, 'node_id' => $test->gateway->id, 'kind' => 'conn.pairing_revoke'],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'jobs' => $status === 'cancelled' ? [] : [
            ['name' => 'session-replies', 'steps' => [['name' => 'Read session revocations', 'status' => isset($receipts['session_receipt']) ? 'succeeded' : 'skipped', 'outputs' => ['stdout' => json_encode($receipts['session_receipt'] ?? [])]]]],
            ['name' => 'link-replies', 'steps' => [['name' => 'Read link revocations', 'status' => isset($receipts['link_receipt']) ? 'succeeded' : 'skipped', 'outputs' => ['stdout' => json_encode($receipts['link_receipt'] ?? [])]]]],
            ['name' => 'cleanup', 'steps' => [['name' => 'Confirm revocation', 'status' => $status === 'succeeded' ? 'succeeded' : 'failed']]],
        ]];
    $test->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];

    return app(SwampCommandStep::class)->projectTerminal($operation, $run, app(OperationKinds::class)->definition('conn.pairing_revoke')['terminal_result']);
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
    projectNativeConnRevoke($this, $status, []);
    expect($this->pairing->fresh()->revoked_at)->toBeNull();
    expect($this->pairing->fresh()->credential)->toBe('private-pairing');
    expect(Operation::query()->sole()->status->value)->toBe($status);
})->with(['failed', 'cancelled']);

it('fails the admitted Operation before its run when the shared execution copy is refused', function (): void {
    $this->copyFails = true;
    $this->postJson($this->url)->assertStatus(503)->assertJsonPath('error.code', 'swamp.vault_sync_unavailable');
    expect(Operation::query()->sole())->status->toBe(OperationStatus::Failed)->error_code->toBe('swamp.vault_sync_unavailable');
    expect($this->pairing->fresh()->credential)->toBe('private-pairing');
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put']);
});

it('settles native failure without result output and preserves captured pairings', function (string $status): void {
    $this->postJson($this->url)->assertAccepted();
    $operation = Operation::query()->sole();
    $run = ['id' => $this->run, 'workflowName' => 'operation-conn-pairing-revoke', 'status' => $status,
        'inputs' => ['intent' => $operation->input['intent'], 'execution_secrets' => $operation->input['execution_secrets'], 'operation_id' => $operation->id, 'node_id' => $this->gateway->id, 'kind' => 'conn.pairing_revoke'],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'jobs' => [['name' => 'cleanup', 'steps' => [['name' => 'Confirm revocation', 'status' => 'skipped']]]]];
    $this->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];

    app(SwampCommandStep::class)->projectTerminal($operation, $run, app(OperationKinds::class)->definition('conn.pairing_revoke')['terminal_result']);
    app(SwampCommandStep::class)->projectTerminal($operation->fresh(), $run, app(OperationKinds::class)->definition('conn.pairing_revoke')['terminal_result']);

    expect($operation->fresh()->status->value)->toBe($status);
    expect($operation->fresh()->error_code)->toBe('conn.revocation_failed');
    expect($operation->fresh()->result)->toBe(['error_code' => 'conn.revocation_failed']);
    expect($operation->fresh()->state['swamp_run_id'])->toBe($this->run);
    expect($this->pairing->fresh()->revoked_at)->toBeNull();
    expect($this->pairing->fresh()->credential)->toBe('private-pairing');
    expect($this->foreign->fresh()->revoked_at)->toBeNull();
    expect($this->foreign->fresh()->credential)->toBe('private-foreign');
    expect(array_count_values(array_column($this->socket->sent, 'type'))['workflow.history.get'])->toBe(1);
    Http::assertNothingSent();
})->with(['failed', 'interrupted']);

it('records completed pairings on partial link failure and leaves the rest open', function (): void {
    $second = ConnPairing::query()->create(['node_id' => $this->caller->id, 'conn_environment_id' => $this->environment->id, 'client_label' => 'second-label', 'pairing_link_id' => 'second-link', 'credential' => 'private-second']);
    $this->postJson($this->url)->assertAccepted();
    projectNativeConnRevoke($this, 'failed', ['session_receipt' => ['valid' => true, 'completed' => [0, 1], 'revoked' => 2], 'link_receipt' => ['valid' => false, 'completed' => [0], 'revoked' => 1]]);
    expect($this->pairing->fresh()->revoked_at)->not->toBeNull();
    expect($this->pairing->fresh()->credential)->toBeNull();
    expect($second->fresh()->revoked_at)->toBeNull();
    expect($second->fresh()->credential)->toBe('private-second');
    expect($this->foreign->fresh()->credential)->toBe('private-foreign');
});

it('admits one revocation per environment and refuses overlap before a second vault copy', function (): void {
    $this->postJson($this->url)->assertAccepted();
    $this->postJson($this->url, ['node_id' => $this->other->id])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    expect(Operation::query()->count())->toBe(1);
    expect($this->copies)->toHaveCount(1);
});

it('refuses an expired recorded admin session without starting a run', function (): void {
    $this->environment->update(['admin_session_expires_at' => now()->subMinute()]);
    $this->postJson($this->url)->assertConflict()->assertJsonPath('error.code', 'conn.session_expired');
    expect(Operation::query()->count())->toBe(0);
    expect($this->copies)->toBe([]);
});
