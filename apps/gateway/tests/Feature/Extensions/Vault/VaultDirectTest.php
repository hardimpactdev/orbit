<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Vault\Domain\VaultConfiguration;
use Orbit\Extensions\Vault\Domain\VaultCredentials;
use Orbit\Extensions\Vault\Domain\VaultSettings;
use Orbit\Extensions\Vault\Models\VaultItem;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->outsider = Node::query()->create(['name' => 'other', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => $request['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->socket = $socket;
    config(['swamp.run_token' => 'fixture.shared.token', 'swamp.read_token' => 'fixture.shared.read']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->payload = ['url' => 'https://vault.orbit', 'client_id' => 'fixture-client', 'client_secret' => 'private-vault-client', 'master_password' => 'private-vault-master', 'organization' => 'Orbit', 'collection' => 'Test'];
});

function admitDirectVault(object $test, string $action = 'setup'): void
{
    if ($action !== 'setup') {
        app(VaultSettings::class)->put(new VaultConfiguration(new VaultCredentials('https://vault.orbit', 'fixture-client', 'private-vault-client', 'private-vault-master', 'fixture-device'), 'fixture@example.test', 'org', 'Orbit', 'collection', 'Test'));
    }
    match ($action) {
        'setup' => $test->putJson('/api/v1/vault', $test->payload)->assertAccepted(),
        'sync' => $test->postJson('/api/v1/vault/sync')->assertAccepted(),
        'teardown' => $test->deleteJson('/api/v1/vault')->assertAccepted(),
    };
    $test->operation = Operation::query()->sole();
    $test->path = '/api/v1/direct-operations/'.$test->operation->id;
}

function directVaultProjection(object $test, array $result): array
{
    return ['run_id' => $test->run, 'status' => 'succeeded', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => $result];
}

it('starts setup directly and projects encrypted settings only after proven safe metadata', function (): void {
    admitDirectVault($this, 'setup');
    expect(app(DirectOperation::class)->writer('vault.setup'))->toBe('direct');
    expect(app(OperationKinds::class)->definition('vault.setup'))->not->toHaveKeys(['runs', 'completion', 'completion_contract']);
    $start = $this->socket->sent[1]['payload'];
    expect($start['workflowIdOrName'])->toBe('operation-vault-setup');
    expect($start['inputs'])->toBe([...$this->operation->input, 'operation_id' => $this->operation->id, 'kind' => 'vault.setup', 'node_id' => $this->gateway->id]);
    expect(json_encode($start))->not->toContain('private-vault-client', 'private-vault-master');
    expect(app(VaultSettings::class)->configuration())->toBeNull();
    $result = ['outcome' => 'succeeded', 'data' => ['backend' => 'configured', 'email' => 'fixture@example.test', 'organization_id' => 'org', 'organization_name' => 'Orbit', 'collection_id' => 'collection', 'collection_name' => 'Test']];
    $run = ['id' => $this->run, 'workflowName' => 'operation-vault-setup', 'status' => 'succeeded', 'inputs' => $start['inputs'],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'jobs' => [['name' => 'setup', 'steps' => [['name' => 'Verify the account', 'outputs' => ['stdout' => json_encode($result)]]]]]];
    $this->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];
    app(SwampCommandStep::class)->projectTerminal($this->operation, $run, ['job' => 'setup', 'step' => 'Verify the account']);
    expect(app(VaultSettings::class)->configuration()?->credentials->clientSecret)->toBe('private-vault-client');
    expect(Setting::query()->where('key', 'vault.operation.'.$this->operation->id)->exists())->toBeFalse();
    expect(Setting::query()->where('key', 'vault.configuration')->value('value'))->not->toContain('private-vault-client');
    expect(json_encode([$this->operation->fresh()->toArray(), Activity::query()->get()->toArray()]))->not->toContain('private-vault-client')->not->toContain('private-vault-master');
    app(SwampCommandStep::class)->projectTerminal($this->operation->fresh(), $run, ['job' => 'setup', 'step' => 'Verify the account']);
    expect(array_count_values(array_column($this->socket->sent, 'type'))['workflow.history.get'])->toBe(1);
    Http::assertNothingSent();
});

it('fails setup without projecting successful verification when native cleanup fails', function (): void {
    app(VaultSettings::class)->put(new VaultConfiguration(new VaultCredentials('https://vault.orbit', 'old-client', 'old-secret', 'old-password', 'old-device'), 'old@example.test', 'old-org', 'Old', 'old-collection', 'Old'));
    admitDirectVault($this, 'setup');
    $run = ['id' => $this->run, 'workflowName' => 'operation-vault-setup', 'status' => 'failed',
        'inputs' => [...$this->operation->input, 'operation_id' => $this->operation->id, 'node_id' => $this->gateway->id, 'kind' => 'vault.setup'],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'jobs' => [['name' => 'setup', 'steps' => [
            ['name' => 'Verify the account', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode(['outcome' => 'succeeded', 'data' => ['backend' => 'configured', 'email' => 'fixture@example.test', 'organization_id' => 'org', 'organization_name' => 'Orbit', 'collection_id' => 'collection', 'collection_name' => 'Test']])]],
            ['name' => 'Clear the session', 'status' => 'failed', 'error' => 'probe deletion failed'],
        ]]]];
    $this->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];
    app(SwampCommandStep::class)->projectTerminal($this->operation, $run, ['job' => 'setup', 'step' => 'Verify the account']);
    expect($this->operation->fresh()->status->value)->toBe('failed');
    expect($this->operation->fresh()->error_code)->toBe('operation.native_failed');
    expect(app(VaultSettings::class)->configuration()?->collectionId)->toBe('old-collection');
    expect(Setting::query()->where('key', 'vault.operation.'.$this->operation->id)->exists())->toBeFalse();
});

it('projects a setup refusal from a typed failure or one native CLI step without stdout', function (string $step, string $detail, string $code): void {
    admitDirectVault($this, 'setup');
    $run = ['id' => $this->run, 'workflowName' => 'operation-vault-setup', 'status' => 'failed',
        'inputs' => [...$this->operation->input, 'operation_id' => $this->operation->id, 'node_id' => $this->gateway->id, 'kind' => 'vault.setup'],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'jobs' => [['name' => 'setup', 'steps' => [['name' => $step, 'status' => 'failed', 'error' => $detail]]]]];
    $this->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];
    app(SwampCommandStep::class)->projectTerminal($this->operation, $run, ['job' => 'setup', 'step' => 'Verify the account']);
    expect($this->operation->fresh()->status->value)->toBe('failed');
    expect($this->operation->fresh()->error_code)->toBe($code);
    expect(app(VaultSettings::class)->configuration())->toBeNull();
})->with([
    ['Log in', 'Command exited with code 1', 'vault.login_failed'],
    ['Unlock the vault', 'Command exited with code 1', 'vault.login_failed'],
    ['Select the organization', 'vault.organization_missing', 'vault.organization_missing'],
    ['Select the collection', 'vault.collection_missing', 'vault.collection_missing'],
    ['Probe write access', 'Command exited with code 1', 'vault.collection_read_only'],
    ['Select the collection', 'vault.items_published', 'vault.items_published'],
    ['Verify the account', 'vault.result_invalid', 'vault.result_invalid'],
    ['Check account KDF', 'vault.kdf_unsupported', 'vault.kdf_unsupported'],
    ['Prepare public data', 'vault.intent_changed', 'vault.intent_changed'],
    ['Clear the session', 'Command exited with code 1', 'operation.native_failed'],
]);

function vaultTeardownReceipt(object $test, string $status, array $steps): void
{
    $run = ['id' => $test->run, 'workflowName' => 'operation-vault-teardown', 'status' => $status,
        'inputs' => [...$test->operation->input, 'operation_id' => $test->operation->id, 'node_id' => $test->gateway->id, 'kind' => 'vault.teardown'],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(), 'jobs' => [['name' => 'cleanup', 'steps' => $steps]]];
    $test->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];
    app(SwampCommandStep::class)->projectTerminal($test->operation, $run, ['job' => 'cleanup', 'step' => 'Confirm teardown', 'native' => true]);
}

it('keeps items with a completed record and no Swamp or vault call', function (): void {
    app(VaultSettings::class)->put(new VaultConfiguration(new VaultCredentials('https://vault.orbit', 'client', 'secret', 'password', 'device'), 'test@example.test', 'org', 'Orbit', 'collection', 'Test'));
    VaultItem::query()->create(['source' => 'database-user:7', 'cipher_id' => 'published', 'revision_date' => 'revision', 'fingerprint' => 'fingerprint']);
    $this->deleteJson('/api/v1/vault', ['keep_items' => true])->assertAccepted()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result.data.backend', 'absent');
    expect($this->socket->sent)->toBe([]);
    expect(app(VaultSettings::class)->configuration())->toBeNull();
    $this->assertDatabaseCount('vault_items', 0);
    expect(Operation::query()->sole()->state['swamp_run_id'] ?? null)->toBeNull();
});

it('starts teardown with public intent and vault names and projects one native terminal result', function (): void {
    VaultItem::query()->create(['source' => 'database-user:7', 'cipher_id' => 'published', 'revision_date' => 'revision', 'fingerprint' => 'fingerprint']);
    admitDirectVault($this, 'teardown');
    $start = current(array_filter($this->socket->sent, static fn (array $frame): bool => $frame['type'] === 'workflow.run'));
    expect($start['payload']['inputs']['intent']['ledger'][0]['cipher_id'])->toBe('published');
    expect($start['payload']['inputs']['execution_secrets']['configuration']['key'])->toStartWith('vault-teardown-');
    expect(json_encode($start))->not->toContain('private-vault-client')->not->toContain('private-vault-master');
    expect(Setting::query()->where('key', 'vault.operation.'.$this->operation->id)->exists())->toBeFalse();
    vaultTeardownReceipt($this, 'succeeded', [['name' => 'Delete the items-0', 'status' => 'succeeded'], ['name' => 'Confirm teardown', 'status' => 'succeeded']]);
    expect($this->operation->fresh()->result)->toBe(['outcome' => 'succeeded', 'data' => ['backend' => 'absent']]);
    expect(app(VaultSettings::class)->configuration())->toBeNull();
    $this->assertDatabaseCount('vault_items', 0);
    expect(array_count_values(array_column($this->socket->sent, 'type'))['workflow.history.get'])->toBe(1);
});

it('records all completed deletions after a later failure, including beyond 256 steps', function (): void {
    VaultItem::query()->insert(array_map(static fn (int $index): array => ['source' => 'database-user:'.$index, 'cipher_id' => 'cipher-'.$index, 'revision_date' => 'revision', 'fingerprint' => 'fingerprint'], range(0, 300)));
    admitDirectVault($this, 'teardown');
    $steps = array_map(static fn (int $index): array => ['name' => 'Delete the items-'.$index, 'status' => 'succeeded'], range(0, 299));
    $steps[] = ['name' => 'Delete the items-300', 'status' => 'failed', 'error' => 'Command exited with code 1'];
    $steps[] = ['name' => 'Remove CLI session', 'status' => 'succeeded'];
    $steps[] = ['name' => 'Confirm teardown', 'status' => 'failed', 'error' => 'Vault teardown or CLI session cleanup failed.'];
    vaultTeardownReceipt($this, 'failed', $steps);
    expect($this->operation->fresh()->error_code)->toBe('vault.unreachable');
    expect(app(VaultSettings::class)->configuration())->not->toBeNull();
    expect(VaultItem::query()->sole()->cipher_id)->toBe('cipher-300');
});

it('refuses a shared item before deletion and preserves the setup and ledger', function (): void {
    VaultItem::query()->create(['source' => 'database-user:7', 'cipher_id' => 'published', 'revision_date' => 'revision', 'fingerprint' => 'fingerprint']);
    admitDirectVault($this, 'teardown');
    vaultTeardownReceipt($this, 'failed', [['name' => 'Select published items', 'status' => 'failed', 'error' => 'vault.item_shared'], ['name' => 'Delete the items-0', 'status' => 'skipped'], ['name' => 'Confirm teardown', 'status' => 'failed', 'error' => 'Vault teardown or CLI session cleanup failed.']]);
    expect($this->operation->fresh()->error_code)->toBe('vault.item_shared');
    expect(app(VaultSettings::class)->configuration())->not->toBeNull();
    $this->assertDatabaseCount('vault_items', 1);
});

it('keeps a later setup when a native teardown terminal event is replayed', function (): void {
    admitDirectVault($this, 'teardown');
    $steps = [['name' => 'Confirm teardown', 'status' => 'succeeded']];
    vaultTeardownReceipt($this, 'succeeded', $steps);
    app(VaultSettings::class)->put(new VaultConfiguration(new VaultCredentials('https://vault.orbit', 'new-client', 'new-private-client', 'new-private-master', 'new-device'), 'new@example.test', 'new-org', 'New', 'new-collection', 'New'));
    vaultTeardownReceipt($this, 'succeeded', $steps);
    expect(app(VaultSettings::class)->configuration()?->collectionId)->toBe('new-collection');
});

it('refuses record-only retention while a sync holds the Vault lock', function (): void {
    admitDirectVault($this, 'sync');
    $sent = count($this->socket->sent);
    $this->deleteJson('/api/v1/vault', ['keep_items' => true])->assertConflict()->assertJsonPath('error.code', 'vault.sync_busy');
    expect(app(VaultSettings::class)->configuration())->not->toBeNull();
    expect(count($this->socket->sent))->toBe($sent);
});
