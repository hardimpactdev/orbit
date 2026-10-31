<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Infrastructure\AgentView\WebSocketClient;
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
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-vault-direct', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->socket = $socket;
    config(['swamp.run_token' => 'fixture.shared.token']);
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
