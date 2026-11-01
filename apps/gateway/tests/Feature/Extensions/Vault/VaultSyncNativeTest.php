<?php

declare(strict_types=1);

use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Vault\Domain\VaultConfiguration;
use Orbit\Extensions\Vault\Domain\VaultCredentials;
use Orbit\Extensions\Vault\Domain\VaultException;
use Orbit\Extensions\Vault\Domain\VaultOperationCompletion;
use Orbit\Extensions\Vault\Domain\VaultSettings;
use Orbit\Extensions\Vault\Models\VaultItem;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    app(VaultSettings::class)->put(new VaultConfiguration(new VaultCredentials('https://vault.orbit', 'fixture-client', 'private-client', 'private-master', 'fixture-device'), 'fixture@example.test', 'org', 'Orbit', 'collection', 'Test'));
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->copies = [];
    $this->copyFails = false;
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = function (array $request): array {
        if ($request['type'] === 'vault.put') {
            $this->copies[] = json_decode($request['payload']['value'], true, flags: JSON_THROW_ON_ERROR);

            return [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => $this->copyFails ? 'refused' : 'local_encryption']]]];
        }

        return [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => $this->run]]];
    };
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture.shared.token', 'swamp.read_token' => 'fixture.shared.read']);
});

it('starts sync with full public intent and one shared vault execution copy on replay', function (): void {
    DatabaseConnection::query()->create(['slug' => 'published', 'driver' => 'mysql', 'host' => '10.44.0.7', 'database' => 'app', 'username' => 'owner', 'password' => 'private-database-password']);
    $this->withHeader('Idempotency-Key', 'native-vault-sync')->postJson('/api/v1/vault/sync')->assertAccepted();
    $operation = Operation::query()->sole();
    expect($operation->input)->toHaveKeys(['intent', 'execution_secrets']);
    expect($operation->input['intent'])->toHaveKeys(['url', 'client_id', 'organization_id', 'collection_id', 'ca_file', 'wanted', 'ledger']);
    expect($operation->input['intent']['wanted'][0])->toHaveKeys(['source', 'fingerprint'])->not->toHaveKey('cipher');
    expect($this->copies)->toHaveCount(1);
    expect($this->copies[0]['master_password'])->toBe('private-master');
    $start = $this->socket->sent[1];
    expect($start['payload']['workflowIdOrName'])->toBe('operation-vault-sync');
    expect($start['payload']['inputs'])->toBe([...$operation->input, 'operation_id' => $operation->id, 'kind' => 'vault.sync', 'node_id' => $this->gateway->id]);
    expect(json_encode([$operation->input, $start, Activity::query()->get()->toArray()]))->not->toContain('private-client', 'private-master', 'private-database-password');
    expect(Setting::query()->where('key', 'vault.operation.'.$operation->id)->exists())->toBeFalse();
    $this->postJson('/api/v1/vault/sync')->assertAccepted()->assertJsonPath('data.id', $operation->id);
    expect($this->copies)->toHaveCount(1);
    Http::assertNothingSent();
});

it('fails the admitted Operation before its run when the canonical vault copy is refused', function (): void {
    $this->copyFails = true;
    $this->postJson('/api/v1/vault/sync')->assertStatus(503)->assertJsonPath('error.code', 'swamp.vault_sync_unavailable');
    expect(Operation::query()->sole())->status->toBe(OperationStatus::Failed)->error_code->toBe('swamp.vault_sync_unavailable');
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put']);
});

it('projects the native ledger once through the shared fixed terminal selector', function (): void {
    $this->postJson('/api/v1/vault/sync')->assertAccepted();
    $operation = Operation::query()->sole();
    VaultItem::query()->create(['source' => 'orphan', 'cipher_id' => 'old', 'revision_date' => 'old', 'fingerprint' => 'old']);
    $result = ['outcome' => 'succeeded', 'data' => ['backend' => 'synced', 'sources' => ['database:7'], 'cipher_ids' => ['cipher'], 'revisions' => ['revision'], 'fingerprints' => ['fingerprint']]];
    $run = ['id' => $this->run, 'workflowName' => 'operation-vault-sync', 'status' => 'succeeded', 'inputs' => [...$operation->input, 'operation_id' => $operation->id, 'node_id' => $this->gateway->id, 'kind' => 'vault.sync'],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(), 'jobs' => [['name' => 'sync', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode($result)]]]]]];
    $this->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];
    app(SwampCommandStep::class)->projectTerminal($operation, $run, ['job' => 'sync', 'step' => 'result']);
    $this->assertDatabaseMissing('vault_items', ['source' => 'orphan']);
    $this->assertDatabaseHas('vault_items', ['source' => 'database:7', 'cipher_id' => 'cipher']);
    expect($operation->fresh()->status->value)->toBe('succeeded');
    expect(app(VaultSettings::class)->lastSyncedAt())->not->toBeNull();
    VaultItem::query()->where('source', 'database:7')->update(['cipher_id' => 'newer']);
    app(VaultSettings::class)->clear();
    app(SwampCommandStep::class)->projectTerminal($operation->fresh(), $run, ['job' => 'sync', 'step' => 'result']);
    $this->assertDatabaseHas('vault_items', ['source' => 'database:7', 'cipher_id' => 'newer']);
    expect(array_count_values(array_column($this->socket->sent, 'type'))['workflow.history.get'])->toBe(1);
});

it('preserves configuration and ledger when the native run reports failure', function (): void {
    $this->postJson('/api/v1/vault/sync')->assertAccepted();
    $operation = Operation::query()->sole();
    VaultItem::query()->create(['source' => 'database:7', 'cipher_id' => 'keep', 'revision_date' => 'old', 'fingerprint' => 'old']);
    $run = ['id' => $this->run, 'workflowName' => 'operation-vault-sync', 'status' => 'failed', 'inputs' => [...$operation->input, 'operation_id' => $operation->id, 'node_id' => $this->gateway->id, 'kind' => 'vault.sync'],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(), 'jobs' => [['name' => 'sync', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode(['error_code' => 'vault.sync_failed'])]]]]]];
    $this->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];
    app(SwampCommandStep::class)->projectTerminal($operation, $run, ['job' => 'sync', 'step' => 'result']);
    expect($operation->fresh()->status->value)->toBe('failed');
    expect(app(VaultSettings::class)->configuration())->not->toBeNull();
    $this->assertDatabaseHas('vault_items', ['source' => 'database:7', 'cipher_id' => 'keep']);
    expect($this->copies)->toHaveCount(1);
});

it('refuses malformed native ledger lists before replacing recorded items', function (array $sources, array $ids): void {
    $this->postJson('/api/v1/vault/sync')->assertAccepted();
    $operation = Operation::query()->sole();
    VaultItem::query()->create(['source' => 'keep', 'cipher_id' => 'keep', 'revision_date' => 'old', 'fingerprint' => 'old']);
    $outcome = OperationOutcome::succeeded(['data' => ['backend' => 'synced', 'sources' => $sources, 'cipher_ids' => $ids, 'revisions' => array_fill(0, count($sources), 'revision'), 'fingerprints' => array_fill(0, count($sources), 'fingerprint')]]);
    expect(fn () => app(VaultOperationCompletion::class)->complete($operation, $outcome))->toThrow(VaultException::class);
    $this->assertDatabaseHas('vault_items', ['source' => 'keep', 'cipher_id' => 'keep']);
})->with([
    'extra id' => [['source'], ['id', 'extra']],
    'duplicate source' => [['source', 'source'], ['one', 'two']],
]);
