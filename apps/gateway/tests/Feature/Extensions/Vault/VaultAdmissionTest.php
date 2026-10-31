<?php

declare(strict_types=1);

use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseUser;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Vault\Actions\SyncVaultAction;
use Orbit\Extensions\Vault\Domain\DatabaseCredentialCatalog;
use Orbit\Extensions\Vault\Domain\VaultConfiguration;
use Orbit\Extensions\Vault\Domain\VaultCredentials;
use Orbit\Extensions\Vault\Domain\VaultException;
use Orbit\Extensions\Vault\Domain\VaultOperationCompletion;
use Orbit\Extensions\Vault\Domain\VaultOperationSecrets;
use Orbit\Extensions\Vault\Domain\VaultSettings;
use Orbit\Extensions\Vault\Models\VaultItem;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    $this->caller = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active',
        'platform' => 'linux', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit']));
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-vault-direct', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture.shared.token']);
    $this->payload = ['url' => 'https://vault.orbit', 'client_id' => 'fixture-client', 'client_secret' => 'fixture-client-secret',
        'master_password' => 'fixture-master-password', 'organization' => 'Orbit', 'collection' => 'Database'];
});

it('admits setup with an encrypted immutable snapshot and no remote or queued PHP work', function (): void {
    if (! OperationKinds::supports('list_of')) {
        $this->putJson('/api/v1/vault', $this->payload)->assertConflict()->assertJsonPath('error.code', 'operation.kind_unavailable');
        expect(Operation::query()->count())->toBe(0)->and(app(VaultSettings::class)->configuration())->toBeNull();

        return;
    }
    $response = $this->withHeader('Idempotency-Key', 'vault-setup')->putJson('/api/v1/vault', $this->payload)->assertAccepted();
    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('vault.setup')->and($operation->status)->toBe(OperationStatus::Queued)
        ->and($operation->node_id)->toBe($this->caller->id)
        ->and($operation->input)->toBe(['configuration' => ['$secret' => 'vault.credentials', 'id' => $this->caller->id]])
        ->and(app(VaultSettings::class)->configuration())->toBeNull();
    $snapshot = app(VaultOperationSecrets::class)->snapshot($operation, $operation->input['configuration']);
    expect($snapshot['client_secret'])->toBe($this->payload['client_secret']);
    foreach (['client_secret', 'master_password'] as $key) {
        expect($response->getContent())->not->toContain($this->payload[$key]);
        expect(Setting::query()->where('key', 'vault.operation.'.$operation->id)->sole()->value)->not->toContain($this->payload[$key]);
    }
    $this->putJson('/api/v1/vault', $this->payload)->assertAccepted()->assertJsonPath('data.id', $response->json('data.id'));
    expect(Operation::query()->count())->toBe(1);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('refuses a reference to another record or source', function (): void {
    if (! OperationKinds::supports('list_of')) {
        $this->putJson('/api/v1/vault', $this->payload)->assertConflict()->assertJsonPath('error.code', 'operation.kind_unavailable');
        expect(Operation::query()->count())->toBe(0)->and(app(VaultSettings::class)->configuration())->toBeNull();

        return;
    }
    $this->putJson('/api/v1/vault', $this->payload)->assertAccepted();
    $operation = Operation::query()->sole();
    expect(fn () => app(VaultOperationSecrets::class)->snapshot($operation, ['$secret' => 'vault.credentials', 'id' => $operation->target_id + 1]))
        ->toThrow(VaultException::class);
    expect(fn () => app(VaultOperationSecrets::class)->snapshot($operation, ['$secret' => 'database.password', 'id' => $operation->target_id]))
        ->toThrow(VaultException::class);
});

it('keeps unconfigured status as a local read and refuses sync and teardown before setup', function (): void {
    $this->getJson('/api/v1/vault')->assertOk()->assertJsonPath('data.configured', false);
    $this->postJson('/api/v1/vault/sync')->assertConflict()->assertJsonPath('error.code', 'vault.not_configured');
    $this->deleteJson('/api/v1/vault')->assertConflict()->assertJsonPath('error.code', 'vault.not_configured');
    Http::assertNothingSent();
});

it('refuses an untrusted origin before storing intent', function (string $url): void {
    $this->putJson('/api/v1/vault', [...$this->payload, 'url' => $url])->assertUnprocessable();
    expect(Operation::query()->count())->toBe(0);
})->with(['http://vault.orbit', 'https://example.test', 'https://vault.orbit/path', 'https://user:pass@vault.orbit']);

it('reports incomplete and password-unknown credentials without contacting Vaultwarden', function (): void {
    app(VaultSettings::class)->put(new VaultConfiguration(
        new VaultCredentials('https://vault.orbit', 'fixture-client', 'fixture-secret', 'fixture-password', 'fixture-device'),
        'fixture@example.test', 'fixture-org', 'Orbit', 'fixture-collection', 'Database'));
    $connection = DatabaseConnection::query()->create(['slug' => 'complete', 'driver' => 'mysql', 'host' => '10.44.0.7', 'database' => 'complete', 'username' => 'owner', 'password' => 'fixture-db-password']);
    $unknown = DatabaseUser::query()->create(['database_connection_id' => $connection->id, 'username' => 'legacy', 'privileges' => 'SELECT']);
    $incomplete = DatabaseConnection::query()->create(['slug' => 'half', 'driver' => 'pgsql', 'host' => '10.44.0.7', 'database' => 'half']);
    DatabaseConnection::query()->create(['slug' => 'cache', 'driver' => 'redis', 'host' => '10.44.0.7']);
    $response = $this->getJson('/api/v1/vault')->assertOk()->assertJsonPath('data.skipped', [
        ['source' => 'database-user:'.$unknown->id, 'slug' => 'complete', 'username' => 'legacy', 'reason' => 'password_unknown'],
        ['source' => 'database-connection:'.$incomplete->id, 'slug' => 'half', 'username' => null, 'reason' => 'incomplete'],
    ]);
    expect($response->getContent())->not->toContain('fixture-db-password', 'fixture-secret', 'fixture-password');
    Http::assertNothingSent();
});

it('retains only the declared Vault ledger lists with C enabled', function (): void {
    $operation = new Operation(['kind' => 'vault.sync', 'target_type' => 'vault', 'target_id' => $this->caller->id, 'node_id' => $this->caller->id]);
    $data = ['backend' => 'synced', 'sources' => ['database-connection:7'], 'cipher_ids' => ['fixture-cipher'],
        'revisions' => ['fixture-revision'], 'fingerprints' => [str_repeat('a', 64)]];
    $safe = app(OperationExecutionContract::class)->result($operation,
        ['outcome' => 'succeeded', 'data' => [...$data, 'password' => 'fixture-secret'], 'token' => 'fixture-secret']);

    expect($safe)->toBe(['outcome' => 'succeeded', 'data' => $data]);
});

it('refuses Vault results outside the shared C limits before projecting the ledger', function (string $limit): void {
    $operation = new Operation(['kind' => 'vault.sync', 'target_type' => 'vault', 'target_id' => $this->caller->id, 'node_id' => $this->caller->id]);
    $data = ['backend' => 'synced', 'sources' => ['source'], 'cipher_ids' => ['cipher'], 'revisions' => ['revision'], 'fingerprints' => ['fingerprint']];
    if ($limit === 'items') {
        $data['sources'] = array_fill(0, 4097, 'source');
    } elseif ($limit === 'string') {
        $data['sources'] = [str_repeat('a', 1025)];
    } else {
        foreach (['sources', 'cipher_ids', 'revisions', 'fingerprints'] as $field) {
            $data[$field] = array_fill(0, 4096, str_repeat('a', 64));
        }
    }

    expect(fn () => app(OperationExecutionContract::class)->result($operation, ['outcome' => 'succeeded', 'data' => $data]))
        ->toThrow(ResourceOperationException::class);
    expect(VaultItem::query()->count())->toBe(0);
})->with(['items', 'string', 'encoded-size']);

it('projects proven setup and sync metadata and retains it until successful teardown', function (): void {
    $this->freezeTime();
    $this->putJson('/api/v1/vault', $this->payload)->assertAccepted();
    $setup = Operation::query()->sole();
    $completion = app(VaultOperationCompletion::class);
    $settings = app(VaultSettings::class);

    $completion->complete($setup, OperationOutcome::succeeded(['data' => ['backend' => 'configured',
        'email' => 'fixture@example.test', 'organization_id' => 'fixture-org', 'organization_name' => 'Orbit',
        'collection_id' => 'fixture-collection', 'collection_name' => 'Database']]));

    expect($settings->configuration()?->collectionId)->toBe('fixture-collection')
        ->and($settings->configuration()?->credentials->clientSecret)->toBe($this->payload['client_secret'])
        ->and($settings->lastVerifiedAt())->toBeNull();
    expect(Setting::query()->where('key', 'vault.operation.'.$setup->id)->exists())->toBeFalse();
    VaultItem::query()->create(['source' => 'obsolete', 'cipher_id' => 'old', 'revision_date' => 'old', 'fingerprint' => 'old']);
    $sync = new Operation(['kind' => 'vault.sync']);

    $completion->complete($sync, OperationOutcome::succeeded(['data' => ['backend' => 'synced',
        'sources' => ['database-connection:7'], 'cipher_ids' => ['fixture-cipher'],
        'revisions' => ['fixture-revision'], 'fingerprints' => [str_repeat('a', 64)]]]));

    $this->assertDatabaseMissing('vault_items', ['source' => 'obsolete']);
    $this->assertDatabaseHas('vault_items', ['source' => 'database-connection:7', 'cipher_id' => 'fixture-cipher',
        'revision_date' => 'fixture-revision', 'fingerprint' => str_repeat('a', 64)]);
    expect($settings->lastVerifiedAt()?->toIso8601ZuluString())->toBe(now()->toIso8601ZuluString())
        ->and($settings->lastSyncedAt())->toBe(now()->toIso8601ZuluString());
    $teardown = new Operation(['kind' => 'vault.teardown']);

    $completion->complete($teardown, OperationOutcome::failed('vault.teardown_failed'));

    expect($settings->configuration()?->collectionId)->toBe('fixture-collection')
        ->and($settings->lastError()['code'])->toBe('vault.teardown_failed');
    $this->assertDatabaseCount('vault_items', 1);

    $completion->complete($teardown, OperationOutcome::succeeded(['data' => ['backend' => 'absent']]));

    expect($settings->configuration())->toBeNull()->and($settings->lastSyncedAt())->toBeNull()
        ->and($settings->lastVerifiedAt())->toBeNull()->and($settings->lastError())->toBeNull();
    $this->assertDatabaseCount('vault_items', 0);
    Http::assertNothingSent();
});

it('deletes terminal credential snapshots while retaining another unfinished Operation snapshot', function (string $action, string $status): void {
    $operation = Operation::query()->create(['kind' => 'vault.'.$action, 'target_type' => 'vault', 'target_id' => $this->caller->id,
        'node_id' => $this->caller->id, 'status' => 'running', 'input' => []]);
    $pending = Operation::query()->create(['kind' => 'vault.sync', 'target_type' => 'vault', 'target_id' => $this->caller->id,
        'node_id' => $this->caller->id, 'status' => 'queued', 'input' => []]);
    $secrets = app(VaultOperationSecrets::class);
    $secrets->store($operation, $this->payload);
    $secrets->store($pending, $this->payload);

    app(VaultOperationCompletion::class)->complete($operation, $status === 'failed'
        ? OperationOutcome::failed('vault.test_failed') : OperationOutcome::cancelled());

    expect(Setting::query()->where('key', 'vault.operation.'.$operation->id)->exists())->toBeFalse();
    expect($secrets->snapshot($pending, ['$secret' => 'vault.credentials', 'id' => $this->caller->id])['client_secret'])->toBe($this->payload['client_secret']);
})->with(['setup', 'sync', 'teardown'])->with(['failed', 'cancelled']);

it('removes historical and orphaned credential snapshots after proven teardown', function (): void {
    $secrets = app(VaultOperationSecrets::class);
    $historical = Operation::query()->create(['kind' => 'vault.sync', 'target_type' => 'vault', 'target_id' => $this->caller->id,
        'node_id' => $this->caller->id, 'status' => 'succeeded', 'input' => []]);
    $secrets->store($historical, $this->payload);
    $orphan = Operation::query()->create(['kind' => 'vault.setup', 'target_type' => 'vault', 'target_id' => $this->caller->id,
        'node_id' => $this->caller->id, 'status' => 'failed', 'input' => []]);
    $secrets->store($orphan, $this->payload);
    $orphan->delete();
    $teardown = Operation::query()->create(['kind' => 'vault.teardown', 'target_type' => 'vault', 'target_id' => $this->caller->id,
        'node_id' => $this->caller->id, 'status' => 'running', 'input' => []]);
    $secrets->store($teardown, $this->payload);

    app(VaultOperationCompletion::class)->complete($teardown, OperationOutcome::succeeded(['data' => ['backend' => 'absent']]));

    expect(Setting::query()->where('key', 'like', 'vault.%')->exists())->toBeFalse();
});

it('removes the sync credential snapshot after proven ledger projection', function (): void {
    $operation = Operation::query()->create(['kind' => 'vault.sync', 'target_type' => 'vault', 'target_id' => $this->caller->id,
        'node_id' => $this->caller->id, 'status' => 'running', 'input' => []]);
    app(VaultOperationSecrets::class)->store($operation, $this->payload);

    app(VaultOperationCompletion::class)->complete($operation, OperationOutcome::succeeded(['data' => ['backend' => 'synced',
        'sources' => [], 'cipher_ids' => [], 'revisions' => [], 'fingerprints' => []]]));

    expect(Setting::query()->where('key', 'vault.operation.'.$operation->id)->exists())->toBeFalse();
});

it('skips every unfinished Vault Operation without admitting another or contacting Vaultwarden', function (string $status): void {
    app(VaultSettings::class)->put(new VaultConfiguration(new VaultCredentials('https://vault.orbit', 'fixture-client', 'fixture-secret', 'fixture-password', 'fixture-device'),
        'fixture@example.test', 'fixture-org', 'Orbit', 'fixture-collection', 'Database'));
    $operation = Operation::query()->create(['kind' => 'vault.sync', 'target_type' => 'vault', 'target_id' => $this->caller->id,
        'node_id' => $this->caller->id, 'status' => $status, 'input' => []]);

    expect(app(SyncVaultAction::class)->scheduled())->toBeNull();

    expect(Operation::query()->count())->toBe(1)->and($operation->fresh()->status->value)->toBe($status);
    Http::assertNothingSent();
})->with(['queued', 'running', 'waiting']);

it('keeps a failure exit code until a later collection completion proves success', function (): void {
    $settings = app(VaultSettings::class);
    $settings->put(new VaultConfiguration(new VaultCredentials('https://vault.orbit', 'fixture-client', 'fixture-secret', 'fixture-password', 'fixture-device'),
        'fixture@example.test', 'fixture-org', 'Orbit', 'fixture-collection', 'Database'));
    $this->freezeTime();
    $settings->recordSuccess(CarbonImmutable::now()->subHour());
    $settings->recordError('vault.sync_failed', 'The collection could not be updated.');

    $this->artisan('orbit:vault-sync')->assertFailed();

    $operation = Operation::query()->sole();
    $this->getJson('/api/v1/vault')->assertJsonPath('data.last_error.code', 'vault.sync_failed')
        ->assertJsonPath('data.last_synced_at', now()->subHour()->toIso8601ZuluString());
    app(VaultOperationCompletion::class)->complete($operation, OperationOutcome::succeeded(['data' => ['backend' => 'synced',
        'sources' => [], 'cipher_ids' => [], 'revisions' => [], 'fingerprints' => []]]));
    $operation->update(['status' => 'succeeded']);

    $this->artisan('orbit:vault-sync')->assertSuccessful();
    expect($settings->lastError())->toBeNull()->and($settings->lastSyncedAt())->toBe(now()->toIso8601ZuluString());
    Http::assertNothingSent();
});

it('counts local credential intent changes without constructing an execution plan', function (): void {
    app(VaultSettings::class)->put(new VaultConfiguration(
        new VaultCredentials('https://vault.orbit', 'fixture-client', 'fixture-secret', 'fixture-password', 'fixture-device'),
        'fixture@example.test', 'fixture-org', 'Orbit', 'fixture-collection', 'Database'));
    DatabaseConnection::query()->create(['slug' => 'pending', 'driver' => 'mysql', 'host' => '10.44.0.7', 'database' => 'pending',
        'username' => 'owner', 'password' => 'fixture-password']);
    $this->getJson('/api/v1/vault')->assertOk()->assertJsonPath('data.pending', 1);
    $configuration = app(VaultSettings::class)->configuration();
    $catalog = app(DatabaseCredentialCatalog::class)->build()['items'];
    $source = array_key_first($catalog);
    $item = VaultItem::query()->create(['source' => $source, 'cipher_id' => 'cipher', 'revision_date' => 'revision',
        'fingerprint' => $catalog[$source]->fingerprint($configuration->organizationId, $configuration->collectionId, config('app.key'))]);
    $this->getJson('/api/v1/vault')->assertOk()->assertJsonPath('data.pending', 0);
    $item->update(['fingerprint' => 'changed']);
    VaultItem::query()->create(['source' => 'orphan', 'cipher_id' => 'orphan', 'revision_date' => 'revision', 'fingerprint' => 'orphan']);
    $this->getJson('/api/v1/vault')->assertOk()->assertJsonPath('data.pending', 2);
    Http::assertNothingSent();
});
