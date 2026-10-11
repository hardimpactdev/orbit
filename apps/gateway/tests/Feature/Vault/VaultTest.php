<?php

declare(strict_types=1);

use App\Actions\Vault\SyncVaultAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Vault\DatabaseCredentialCatalog;
use App\Domain\Vault\VaultCipher;
use App\Domain\Vault\VaultClient;
use App\Domain\Vault\VaultCollection;
use App\Domain\Vault\VaultException;
use App\Domain\Vault\VaultSettings;
use App\Models\DatabaseConnection;
use App\Models\DatabaseUser;
use App\Models\Node;
use App\Models\Setting;
use App\Models\VaultItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeVaultClient;

const VAULT_DB_SECRET = 'database-password-sentinel';

beforeEach(function (): void {
    $this->gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);

    $this->vault = new FakeVaultClient;
    $this->app->instance(VaultClient::class, $this->vault);

    $this->connection = DatabaseConnection::query()->create([
        'slug' => 'ohdear',
        'driver' => DatabaseDriver::Mysql,
        'host' => '10.44.0.7',
        'port' => 3306,
        'database' => 'ohdear',
        'username' => 'ohdear_main',
        'password' => VAULT_DB_SECRET,
    ]);
});

function vault_setup(array $overrides = []): TestResponse
{
    return test()->putJson('/api/v1/vault', array_merge([
        'url' => 'https://vault.orbit/',
        'client_id' => 'user.gateway',
        'client_secret' => FakeVaultClient::ClientSecret,
        'master_password' => FakeVaultClient::MasterPassword,
        'organization' => 'Orbit',
        'collection' => 'Databases',
    ], $overrides));
}

describe('vault:setup', function (): void {
    it('checks the account, stores the setup encrypted, and never returns a secret', function (): void {
        $response = vault_setup()->assertOk();

        $response
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.url', 'https://vault.orbit')
            ->assertJsonPath('data.email', 'gateway@example.test')
            ->assertJsonPath('data.organization', ['id' => FakeVaultClient::OrganizationId, 'name' => 'Orbit'])
            ->assertJsonPath('data.collection', ['id' => FakeVaultClient::CollectionId, 'name' => 'Databases'])
            ->assertJsonPath('data.items', 0)
            ->assertJsonPath('data.pending', 1);

        $stored = (string) Setting::query()->where('key', 'vault.configuration')->value('value');

        expect($response->getContent())
            ->not->toContain(FakeVaultClient::ClientSecret)
            ->not->toContain(FakeVaultClient::MasterPassword)
            ->and($stored)->not->toContain(FakeVaultClient::MasterPassword)
            ->and(app(VaultSettings::class)->configuration()?->credentials->masterPassword)->toBe(FakeVaultClient::MasterPassword);
    });

    it('keeps the device identifier when setup runs again', function (): void {
        vault_setup()->assertOk();
        vault_setup()->assertOk();

        expect($this->vault->credentials[0]->deviceIdentifier)->toBe($this->vault->credentials[1]->deviceIdentifier);
    });

    it('accepts an organization and collection by ID', function (): void {
        vault_setup(['organization' => FakeVaultClient::OrganizationId, 'collection' => FakeVaultClient::CollectionId])
            ->assertOk()
            ->assertJsonPath('data.collection.name', 'Databases');
    });

    it('refuses a wrong master password and stores nothing', function (): void {
        vault_setup(['master_password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'vault.login_failed');

        expect(app(VaultSettings::class)->configuration())->toBeNull();
    });

    it('refuses an unknown organization or collection, and a read-only collection', function (array $overrides, string $code): void {
        vault_setup($overrides)->assertStatus(422)->assertJsonPath('error.code', $code);
    })->with([
        'organization' => [['organization' => 'Missing'], 'vault.organization_missing'],
        'collection' => [['collection' => 'Missing'], 'vault.collection_missing'],
        'collection of another organization' => [['organization' => 'Other'], 'vault.collection_missing'],
        'read only' => [['collection' => 'Read only'], 'vault.collection_read_only'],
    ]);

    it('refuses a URL that is not HTTPS', function (): void {
        vault_setup(['url' => 'http://vault.orbit'])->assertStatus(422);
    });

    it('refuses to move to another collection while items are published', function (): void {
        vault_setup()->assertOk();
        $this->postJson('/api/v1/vault/sync')->assertOk();
        $this->vault->collections[1] = new VaultCollection('col-2', FakeVaultClient::OrganizationId, 'Read only', false);

        vault_setup(['collection' => 'Read only'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vault.items_published');
    });
});

describe('vault:status', function (): void {
    it('reports an unconfigured Gateway', function (): void {
        $this->getJson('/api/v1/vault')
            ->assertOk()
            ->assertJsonPath('data', [
                'configured' => false,
                'url' => null,
                'email' => null,
                'organization' => null,
                'collection' => null,
                'items' => 0,
                'pending' => 0,
                'skipped' => [],
                'last_synced_at' => null,
                'last_error' => null,
            ]);
    });

    it('lists credentials that get no item', function (): void {
        DatabaseConnection::query()->create(['slug' => 'half', 'driver' => DatabaseDriver::Pgsql, 'host' => '10.44.0.7', 'database' => 'half']);
        DatabaseConnection::query()->create(['slug' => 'cache', 'driver' => DatabaseDriver::Redis, 'host' => '10.44.0.7']);
        DatabaseUser::query()->create(['database_connection_id' => $this->connection->id, 'username' => 'legacy', 'privileges' => 'SELECT']);
        DatabaseUser::query()->create(['database_connection_id' => $this->connection->id, 'username' => 'ohdear_main', 'privileges' => 'ALL']);
        vault_setup()->assertOk();

        $this->getJson('/api/v1/vault')
            ->assertOk()
            ->assertJsonPath('data.skipped', [
                ['source' => 'database-user:1', 'slug' => 'ohdear', 'username' => 'legacy', 'reason' => 'password_unknown'],
                ['source' => 'database-connection:2', 'slug' => 'half', 'username' => null, 'reason' => 'incomplete'],
            ]);
    });
});

describe('vault:sync', function (): void {
    beforeEach(function (): void {
        vault_setup()->assertOk();
    });

    it('publishes connections and extra users as Database items', function (): void {
        $pgsql = DatabaseConnection::query()->create([
            'slug' => 'reports',
            'driver' => DatabaseDriver::Pgsql,
            'host' => 'db.example.test',
            'database' => 'reports',
            'username' => 'reports',
            'password' => 'pg-secret',
        ]);
        $user = DatabaseUser::query()->create([
            'database_connection_id' => $this->connection->id,
            'username' => 'reporting',
            'password' => 'reporting-secret',
            'privileges' => 'SELECT',
        ]);

        $response = $this->postJson('/api/v1/vault/sync')
            ->assertOk()
            ->assertJsonPath('data.items', 3)
            ->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.last_error', null);

        expect($response->getContent())->not->toContain(VAULT_DB_SECRET)
            ->and($response->json('data.last_synced_at'))->toBeString();

        $byName = collect($this->vault->items)->mapWithKeys(fn (array $item, string $id): array => [$item['cipher']->name => $id]);

        expect($byName->keys()->sort()->values()->all())->toBe(['ohdear', 'ohdear (reporting)', 'reports'])
            ->and($this->vault->fields($byName['ohdear']))->toBe([
                'vault.schema' => '1',
                'type' => 'database',
                'driver' => 'mysql',
                'host' => '10.44.0.7',
                'port' => '3306',
                'database' => 'ohdear',
                'username' => 'ohdear_main',
                'password' => VAULT_DB_SECRET,
                'tls' => 'disabled',
                'orbit.source' => 'database-connection:'.$this->connection->id,
            ])
            ->and($this->vault->fields($byName['reports']))->toMatchArray(['driver' => 'postgresql', 'port' => '5432', 'orbit.source' => 'database-connection:'.$pgsql->id])
            ->and($this->vault->fields($byName['ohdear (reporting)']))->toMatchArray([
                'username' => 'reporting',
                'password' => 'reporting-secret',
                'database' => 'ohdear',
                'orbit.source' => 'database-user:'.$user->id,
            ])
            ->and($this->vault->items[$byName['ohdear']]['cipher']->notes)->toBe(DatabaseCredentialCatalog::Notes)
            ->and($this->vault->items[$byName['ohdear']]['collections'])->toBe([FakeVaultClient::CollectionId])
            ->and(collect($this->vault->items[$byName['ohdear']]['cipher']->fields)->firstWhere('name', 'password')['hidden'])->toBeTrue();

        $stored = VaultItem::query()->pluck('fingerprint')->implode(' ');
        expect($stored)->not->toContain(VAULT_DB_SECRET);
    });

    it('rewrites a changed password and permanently deletes removed credentials', function (): void {
        $user = DatabaseUser::query()->create([
            'database_connection_id' => $this->connection->id,
            'username' => 'reporting',
            'password' => 'reporting-secret',
            'privileges' => 'SELECT',
        ]);
        $this->postJson('/api/v1/vault/sync')->assertOk();
        $cipherId = VaultItem::query()->where('source', 'database-connection:'.$this->connection->id)->value('cipher_id');

        $this->connection->update(['password' => 'rotated']);
        $user->delete();

        $this->postJson('/api/v1/vault/sync')->assertOk()->assertJsonPath('data.items', 1);

        expect($this->vault->items)->toHaveCount(1)
            ->and($this->vault->fields($cipherId)['password'])->toBe('rotated');

        $this->connection->delete();
        $this->postJson('/api/v1/vault/sync')->assertOk()->assertJsonPath('data.items', 0);

        expect($this->vault->items)->toBe([]);
    });

    it('recreates an item that is gone and rewrites one someone changed', function (): void {
        $this->postJson('/api/v1/vault/sync')->assertOk();
        $first = (string) VaultItem::query()->value('cipher_id');

        $this->vault->touch($first);
        $this->vault->items[$first]['cipher'] = new VaultCipher('edited', 'edited', []);
        $this->postJson('/api/v1/vault/sync')->assertOk();

        expect($this->vault->items[$first]['cipher']->name)->toBe('ohdear');

        unset($this->vault->items[$first]);
        $this->postJson('/api/v1/vault/sync')->assertOk()->assertJsonPath('data.items', 1);

        expect($this->vault->items)->toHaveCount(1)
            ->and(VaultItem::query()->value('cipher_id'))->not->toBe($first);
    });

    it('keeps the published state when a write fails and reports the error', function (): void {
        $this->vault->failWrites = new VaultException('vault.unreachable', 'Vaultwarden answered with HTTP 500.', 502);

        $this->postJson('/api/v1/vault/sync')
            ->assertOk()
            ->assertJsonPath('data.items', 0)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.last_error', ['code' => 'vault.unreachable', 'message' => 'Vaultwarden answered with HTTP 500.']);

        $this->vault->failWrites = null;
        $this->postJson('/api/v1/vault/sync')->assertOk()->assertJsonPath('data.last_error', null)->assertJsonPath('data.items', 1);
    });

    it('returns a login failure and records it', function (): void {
        $this->vault->failOpen = new VaultException('vault.unreachable', 'Vaultwarden did not answer.', 502);

        $this->postJson('/api/v1/vault/sync')->assertStatus(502)->assertJsonPath('error.code', 'vault.unreachable');

        $this->getJson('/api/v1/vault')->assertJsonPath('data.last_error.code', 'vault.unreachable');
    });

    it('refuses a sync before setup', function (): void {
        $this->deleteJson('/api/v1/vault', ['keep_items' => true])->assertOk();

        $this->postJson('/api/v1/vault/sync')->assertStatus(409)->assertJsonPath('error.code', 'vault.not_configured');
    });
});

describe('orbit:vault-sync', function (): void {
    it('does nothing before setup', function (): void {
        $this->artisan('orbit:vault-sync')->assertSuccessful();

        expect($this->vault->opens)->toBe(0);
    });

    it('contacts Vaultwarden only for changes and the hourly check', function (): void {
        Carbon::setTestNow('2026-10-11 12:00:00');
        vault_setup()->assertOk();
        $opens = $this->vault->opens;

        $this->artisan('orbit:vault-sync')->assertSuccessful();
        expect($this->vault->opens)->toBe($opens + 1)
            ->and($this->vault->items)->toHaveCount(1);

        Carbon::setTestNow('2026-10-11 12:01:00');
        $this->artisan('orbit:vault-sync')->assertSuccessful();
        expect($this->vault->opens)->toBe($opens + 1);

        $this->connection->update(['password' => 'rotated']);
        Carbon::setTestNow('2026-10-11 12:02:00');
        $this->artisan('orbit:vault-sync')->assertSuccessful();
        expect($this->vault->opens)->toBe($opens + 2);

        Carbon::setTestNow('2026-10-11 13:03:00');
        $this->artisan('orbit:vault-sync')->assertSuccessful();
        expect($this->vault->opens)->toBe($opens + 3);
    });

    it('fails when a write fails', function (): void {
        vault_setup()->assertOk();
        $this->vault->failWrites = new VaultException('vault.unreachable', 'Vaultwarden answered with HTTP 500.', 502);

        $this->artisan('orbit:vault-sync')->assertFailed();
    });

    it('skips a run while another holds the lock', function (): void {
        vault_setup()->assertOk();
        $lock = Cache::lock('orbit:vault-sync', 60);
        $lock->get();

        $this->artisan('orbit:vault-sync')->assertSuccessful();
        expect($this->vault->items)->toBe([]);

        $lock->release();
        expect(app(SyncVaultAction::class)->scheduled())->toBeNull();
    });
});

describe('vault:teardown', function (): void {
    beforeEach(function (): void {
        vault_setup()->assertOk();
        $this->postJson('/api/v1/vault/sync')->assertOk();
    });

    it('deletes the published items and the setup', function (): void {
        $this->deleteJson('/api/v1/vault')
            ->assertOk()
            ->assertJsonPath('data.configured', false);

        expect($this->vault->items)->toBe([])
            ->and(VaultItem::query()->exists())->toBeFalse()
            ->and(Setting::query()->where('key', 'like', 'vault.%')->exists())->toBeFalse();
    });

    it('keeps the items when asked', function (): void {
        $this->deleteJson('/api/v1/vault', ['keep_items' => true])->assertOk();

        expect($this->vault->items)->toHaveCount(1)
            ->and(VaultItem::query()->exists())->toBeFalse();
    });

    it('keeps the setup when Vaultwarden cannot delete the items', function (): void {
        $this->vault->failWrites = new VaultException('vault.unreachable', 'Vaultwarden answered with HTTP 500.', 502);

        $this->deleteJson('/api/v1/vault')->assertStatus(502);

        $this->getJson('/api/v1/vault')->assertJsonPath('data.configured', true)->assertJsonPath('data.items', 1);
    });

    it('refuses a teardown before setup', function (): void {
        $this->deleteJson('/api/v1/vault', ['keep_items' => true])->assertOk();

        $this->deleteJson('/api/v1/vault')->assertStatus(409)->assertJsonPath('error.code', 'vault.not_configured');
    });
});
