<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Models\DatabaseConnection;
use App\Models\DatabaseUser;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Vault\Domain\VaultConfiguration;
use Orbit\Extensions\Vault\Domain\VaultCredentials;
use Orbit\Extensions\Vault\Domain\VaultException;
use Orbit\Extensions\Vault\Domain\VaultOperationSecrets;
use Orbit\Extensions\Vault\Domain\VaultSettings;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    $this->caller = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active',
        'platform' => 'linux', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit']));
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->payload = ['url' => 'https://vault.orbit', 'client_id' => 'fixture-client', 'client_secret' => 'fixture-client-secret',
        'master_password' => 'fixture-master-password', 'organization' => 'Orbit', 'collection' => 'Database'];
});

it('admits setup with an encrypted immutable snapshot and no remote or queued PHP work', function (): void {
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
