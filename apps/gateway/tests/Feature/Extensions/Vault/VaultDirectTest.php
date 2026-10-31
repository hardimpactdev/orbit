<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Vault\Domain\VaultConfiguration;
use Orbit\Extensions\Vault\Domain\VaultCredentials;
use Orbit\Extensions\Vault\Domain\VaultSettings;
use Orbit\Extensions\Vault\Domain\VaultSyncProjection;
use Orbit\Extensions\Vault\Models\VaultItem;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->runner = new class implements ProcessRunner
    {
        public array $calls = [];

        public array $values = [];

        public bool $fail = false;

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->calls[] = $invocation;
            $this->values[] = stream_get_contents($invocation->protectedInput->stream());

            return new CommandResult($this->fail ? 1 : 0, '{}', '', 1, false);
        }
    };
    app()->instance(ProcessRunner::class, $this->runner);
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->outsider = Node::query()->create(['name' => 'other', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->socket = $socket;
    config(['swamp.run_token' => 'fixture.shared.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->payload = ['url' => 'https://vault.orbit', 'client_id' => 'fixture-client', 'client_secret' => 'private-vault-client', 'master_password' => 'private-vault-master', 'organization' => 'Orbit', 'collection' => 'Test'];
});

function admitDirectVault(object $test, string $action = 'sync'): void
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

it('starts sync with public intent and a separate names-only execution copy', function (): void {
    admitDirectVault($this);
    $input = $this->operation->input;
    expect($input)->toHaveKeys(['intent', 'execution_secrets'])
        ->and($input['intent'])->toHaveKeys(['url', 'client_id', 'device_identifier', 'organization_id', 'collection_id', 'ca_file', 'wanted', 'ledger'])
        ->and($input['intent'])->not->toHaveKeys(['client_secret', 'master_password'])
        ->and($input['execution_secrets']['configuration']['vault'])->toBe('orbit-control-inputs')
        ->and($input['execution_secrets']['configuration']['key'])->toStartWith('vault-sync-');
    expect(app(DirectOperation::class)->projection($this->operation)->input($this->operation))->toBe($input);
    expect($this->socket->sent[0]['payload']['inputs'])->toBe([...$input,
        'operation_id' => $this->operation->id, 'kind' => 'vault.sync', 'node_id' => $this->gateway->id]);
    expect($this->runner->values)->toHaveCount(1);
    expect(json_decode($this->runner->values[0], true)['master_password'])->toBe('private-vault-master');
    expect(Setting::query()->where('key', 'vault.operation.'.$this->operation->id)->exists())->toBeFalse();
    expect(json_encode([$input, $this->socket->sent, $this->runner->calls[0]->arguments]))
        ->not->toContain('private-vault-client')->not->toContain('private-vault-master');
    $this->postJson('/api/v1/vault/sync')->assertAccepted()->assertJsonPath('data.id', $this->operation->id);
    expect($this->runner->values)->toHaveCount(1);
    Http::assertNothingSent();
});

it('rolls back sync admission when the shared execution copy cannot be stored', function (): void {
    app(VaultSettings::class)->put(new VaultConfiguration(new VaultCredentials('https://vault.orbit', 'fixture-client', 'private-vault-client', 'private-vault-master', 'fixture-device'), 'fixture@example.test', 'org', 'Orbit', 'collection', 'Test'));
    $this->runner->fail = true;
    $this->postJson('/api/v1/vault/sync')->assertStatus(503)->assertJsonPath('error.code', 'swamp.vault_sync_unavailable');
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBeEmpty();
});

it('records the native sync ledger from the fixed terminal output without a family callback', function (): void {
    admitDirectVault($this);
    VaultItem::query()->create(['source' => 'orphan', 'cipher_id' => 'old', 'revision_date' => 'old', 'fingerprint' => 'old']);
    $result = ['outcome' => 'succeeded', 'data' => ['backend' => 'synced', 'sources' => ['database:7'], 'cipher_ids' => ['cipher'], 'revisions' => ['revision'], 'fingerprints' => ['fingerprint']]];
    $run = ['id' => $this->run, 'workflowName' => 'operation-vault-sync', 'status' => 'succeeded',
        'inputs' => ['operation_id' => $this->operation->id, 'node_id' => $this->gateway->id, 'kind' => 'vault.sync'],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'jobs' => [['name' => 'sync', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode($result)]]]]]];
    $this->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];

    app(SwampCommandStep::class)->projectTerminal($this->operation, $run, ['job' => 'sync', 'step' => 'result']);

    $this->assertDatabaseMissing('vault_items', ['source' => 'orphan']);
    $this->assertDatabaseHas('vault_items', ['source' => 'database:7', 'cipher_id' => 'cipher']);
    expect($this->operation->fresh()->status->value)->toBe('succeeded');
    expect(app(VaultSettings::class)->lastSyncedAt())->not->toBeNull();
    expect($this->socket->sent)->toHaveCount(2);
    expect($this->socket->sent[1]['type'])->toBe('workflow.history.get');
    Http::assertNothingSent();
});

it('preserves Vault configuration and ledger after a native sync failure', function (): void {
    admitDirectVault($this);
    VaultItem::query()->create(['source' => 'database:7', 'cipher_id' => 'old', 'revision_date' => 'old', 'fingerprint' => 'old']);
    $data = directVaultProjection($this, []);
    $data['status'] = 'failed';
    $data['error_code'] = 'operation.native_failed';
    app(VaultSyncProjection::class)->apply($this->operation, $data);
    expect($this->operation->fresh()->status->value)->toBe('failed');
    expect(app(VaultSettings::class)->configuration())->not->toBeNull();
    $this->assertDatabaseHas('vault_items', ['source' => 'database:7', 'cipher_id' => 'old']);
});

it('starts setup directly and projects encrypted settings only after proven safe metadata', function (): void {
    admitDirectVault($this, 'setup');
    expect(app(DirectOperation::class)->writer('vault.setup'))->toBe('direct');
    expect(app(OperationKinds::class)->definition('vault.setup'))->not->toHaveKeys(['runs', 'completion', 'completion_contract']);
    expect($this->socket->sent[0]['payload']['inputs'])->toBe(['operation_id' => $this->operation->id, 'kind' => 'vault.setup', 'node_id' => $this->gateway->id]);
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertOk()->assertJsonPath('data.configuration.master_password', 'private-vault-master');
    expect(app(VaultSettings::class)->configuration())->toBeNull();
    $this->postJson($this->path.'/result', directVaultProjection($this, ['outcome' => 'succeeded', 'data' => ['backend' => 'configured']]))->assertConflict();
    $this->postJson($this->path.'/result', directVaultProjection($this, ['outcome' => 'succeeded', 'data' => ['backend' => 'configured', 'email' => 'fixture@example.test', 'organization_id' => 'org', 'organization_name' => 'Orbit', 'collection_id' => 'collection', 'collection_name' => 'Test']]))->assertOk();
    expect(app(VaultSettings::class)->configuration()?->credentials->clientSecret)->toBe('private-vault-client');
    expect(Setting::query()->where('key', 'vault.operation.'.$this->operation->id)->exists())->toBeFalse();
    expect(Setting::query()->where('key', 'vault.configuration')->value('value'))->not->toContain('private-vault-client');
    expect(json_encode([$this->operation->fresh()->toArray(), Activity::query()->get()->toArray()]))->not->toContain('private-vault-client')->not->toContain('private-vault-master');
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertConflict();
    Http::assertNothingSent();
});

it('tears down directly and clears settings and ledger only after proven removal', function (): void {
    admitDirectVault($this, 'teardown');
    VaultItem::query()->create(['source' => 'database:7', 'cipher_id' => 'cipher', 'revision_date' => 'revision', 'fingerprint' => 'fingerprint']);
    expect(app(DirectOperation::class)->writer('vault.teardown'))->toBe('direct');
    expect(app(OperationKinds::class)->definition('vault.teardown'))->not->toHaveKeys(['runs', 'completion', 'completion_contract']);
    expect($this->socket->sent[0]['payload']['inputs'])->toBe(['operation_id' => $this->operation->id, 'kind' => 'vault.teardown', 'node_id' => $this->gateway->id]);
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertOk()->assertJsonPath('data.configuration.client_secret', 'private-vault-client');
    $this->postJson($this->path.'/result', directVaultProjection($this, ['outcome' => 'succeeded', 'data' => ['backend' => 'synced']]))->assertConflict();
    expect(app(VaultSettings::class)->configuration())->not->toBeNull();
    $this->assertDatabaseCount('vault_items', 1);
    $this->postJson($this->path.'/result', directVaultProjection($this, ['outcome' => 'succeeded', 'data' => ['backend' => 'absent']]))->assertOk();
    expect(app(VaultSettings::class)->configuration())->toBeNull();
    $this->assertDatabaseCount('vault_items', 0);
    expect(Setting::query()->where('key', 'vault.operation.'.$this->operation->id)->exists())->toBeFalse();
    expect(json_encode([$this->operation->fresh()->toArray(), Activity::query()->get()->toArray()]))->not->toContain('private-vault-client')->not->toContain('private-vault-master');
    Http::assertNothingSent();
});

it('preserves settings and ledger after a failed direct teardown and forgets its snapshot', function (): void {
    admitDirectVault($this, 'teardown');
    VaultItem::query()->create(['source' => 'database:7', 'cipher_id' => 'cipher', 'revision_date' => 'revision', 'fingerprint' => 'fingerprint']);
    $projection = directVaultProjection($this, ['error_code' => 'vault.unreachable']);
    $projection['status'] = 'failed';
    $projection['error_code'] = 'vault.unreachable';
    $this->postJson($this->path.'/result', $projection)->assertOk();
    expect(app(VaultSettings::class)->configuration())->not->toBeNull();
    $this->assertDatabaseCount('vault_items', 1);
    expect(Setting::query()->where('key', 'vault.operation.'.$this->operation->id)->exists())->toBeFalse();
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertConflict();
    Http::assertNothingSent();
});

it('replays a terminal native result without resolving deleted credentials or replacing newer ledger state', function (): void {
    admitDirectVault($this);
    $projection = directVaultProjection($this, ['outcome' => 'succeeded', 'data' => ['backend' => 'synced',
        'sources' => ['database:7'], 'cipher_ids' => ['first'], 'revisions' => ['revision'], 'fingerprints' => ['fingerprint']]]);
    app(VaultSyncProjection::class)->apply($this->operation, $projection);
    expect(Setting::query()->where('key', 'vault.operation.'.$this->operation->id)->exists())->toBeFalse();
    VaultItem::query()->where('source', 'database:7')->update(['cipher_id' => 'newer']);
    app(VaultSettings::class)->clear();

    app(VaultSyncProjection::class)->apply($this->operation, $projection);

    $this->assertDatabaseHas('vault_items', ['source' => 'database:7', 'cipher_id' => 'newer']);
    expect(app(VaultSettings::class)->configuration())->toBeNull();
    expect($this->operation->fresh()->result)->toBe($projection['result']);
    Http::assertNothingSent();
});

it('records and replays a native dispatch failure without private snapshot resolution', function (): void {
    admitDirectVault($this, 'teardown');
    $projection = directVaultProjection($this, ['error_code' => 'operation.native_dispatch_failed']);
    $projection['status'] = 'failed';
    $projection['error_code'] = 'operation.native_dispatch_failed';
    $this->postJson($this->path.'/result', $projection)->assertOk();
    expect(Setting::query()->where('key', 'vault.operation.'.$this->operation->id)->exists())->toBeFalse();

    $this->postJson($this->path.'/result', $projection)->assertOk();

    expect($this->operation->fresh()->status->value)->toBe('failed');
    expect(app(VaultSettings::class)->configuration())->not->toBeNull();
    Http::assertNothingSent();
});

it('preserves a later Vault configuration when a terminal setup or teardown result is replayed', function (string $action): void {
    admitDirectVault($this, $action);
    $result = $action === 'setup'
        ? ['backend' => 'configured', 'email' => 'fixture@example.test', 'organization_id' => 'org', 'organization_name' => 'Orbit', 'collection_id' => 'collection', 'collection_name' => 'Test']
        : ['backend' => 'absent'];
    $projection = directVaultProjection($this, ['outcome' => 'succeeded', 'data' => $result]);
    $this->postJson($this->path.'/result', $projection)->assertOk();
    app(VaultSettings::class)->put(new VaultConfiguration(new VaultCredentials('https://vault.orbit', 'new-client', 'new-private-client', 'new-private-master', 'new-device'),
        'new@example.test', 'new-org', 'New Organization', 'new-collection', 'New Collection'));

    $this->postJson($this->path.'/result', $projection)->assertOk();

    expect(app(VaultSettings::class)->configuration()?->collectionId)->toBe('new-collection');
    expect(app(VaultSettings::class)->configuration()?->credentials->clientSecret)->toBe('new-private-client');
    Http::assertNothingSent();
})->with(['setup', 'teardown']);
