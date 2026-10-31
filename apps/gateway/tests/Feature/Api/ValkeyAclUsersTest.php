<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseConnectionRemovalGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeRoutePublicationSocket;

const VALKEY_ACL_ADMIN_SECRET = 'valkey-admin-secret-4b2e';

beforeEach(function (): void {
    config(['swamp.run_token' => 'fake.token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => $sent['payload']['workflowIdOrName'], 'runId' => Str::uuid()->toString()]]];
    app()->instance(WebSocketClient::class, $socket);

    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'user' => 'orbit',
        'tld' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->gateway = $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);

    $this->dbNode = Node::query()->create([
        'name' => 'services',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.80',
    ]);
    $this->server = DatabaseServer::query()->create([
        'slug' => 'services-valkey',
        'driver' => 'redis',
        'node_id' => $this->dbNode->id,
        'tag' => '8.1',
        'port' => 6380,
        'root_password' => VALKEY_ACL_ADMIN_SECRET,
        'status' => LifecycleStatus::Active,
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function valkey_acl_user_request(array $overrides = []): array
{
    return [
        'slug' => 'gateway-queue',
        'server' => 'services-valkey',
        'patterns' => ['orbit-gateway:*', 'orbit-gateway-horizon:*'],
        'allow' => ['@connection', '@list', 'eval', 'client|setname'],
        ...$overrides,
    ];
}

function valkey_acl_user_instance(Node $node): Instance
{
    $project = Project::query()->create([
        'name' => 'DLF',
        'slug' => 'dlf',
        'repository_url' => 'https://example.test/dlf.git',
        'default_branch' => 'main',
    ]);

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'feature-x',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/dlf/feature-x',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
}

function valkey_acl_recorded(DatabaseServer $server, ?string $username = 'gateway-queue'): DatabaseConnection
{
    $connection = DatabaseConnection::query()->create(['slug' => 'gateway-queue', 'driver' => 'redis',
        'database_server_id' => $server->id, 'node_id' => $server->node_id, 'host' => $server->node->wireguard_ip,
        'port' => $server->port, 'username' => $username, 'password' => 'fixture-password']);
    $connection->users()->create(['username' => 'gateway-queue', 'privileges' => 'on ~queue:* -@all +@list']);

    return $connection;
}

/** @param list<string> $secrets */
function valkey_acl_user_assert_no_secret(TestResponse $response, array $secrets): void
{
    $activity = Activity::query()->where('request_id', $response->headers->get('X-Orbit-Request-Id'))->sole();

    foreach ($secrets as $secret) {
        expect($response->getContent())->not->toContain($secret)
            ->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))->not->toContain($secret);
    }
}

describe('database:create --server on a Valkey server', function (): void {
    it('admits protected ACL creation without invoking the former PHP writer', function (): void {
        $response = $this->postJson('/api/v1/database-connections', valkey_acl_user_request(['database' => 1]))
            ->assertAccepted()->assertJsonPath('data.kind', 'database.create');
        $connection = DatabaseConnection::query()->sole();
        expect($connection->database)->toBe('1')
            ->and($connection->username)->toBe('gateway-queue')
            ->and($connection->database_server_id)->toBe($this->server->id)
            ->and($connection->password)->toHaveLength(40);
        valkey_acl_user_assert_no_secret($response, [$connection->password, VALKEY_ACL_ADMIN_SECRET]);
    });

    it('records no database index without one', function (): void {
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())
            ->assertAccepted()->assertJsonPath('data.kind', 'database.create');
        expect(DatabaseConnection::query()->sole()->database)->toBeNull();
    });

    it('refuses a username that a connection on the server already has', function (): void {
        DatabaseConnection::query()->create([
            'slug' => 'services-valkey',
            'driver' => 'redis',
            'database_server_id' => $this->server->id,
            'host' => '10.44.0.80',
            'port' => 6380,
            'username' => 'gateway-queue',
            'password' => 'other-secret',
        ]);

        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.name_conflict');

    });

    it('refuses an existing slug and a server that is not active before any admin command', function (): void {
        DatabaseConnection::query()->create(['slug' => 'gateway-queue', 'driver' => 'sqlite', 'path' => '/tmp/queue.sqlite']);

        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.slug_conflict');

        $this->server->update(['status' => LifecycleStatus::Provisioning]);

        $this->postJson('/api/v1/database-connections', valkey_acl_user_request(['slug' => 'other']))
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_inactive');

    });

    it('refuses an Instance with database.driver_unsupported', function (): void {
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request(['instance_id' => valkey_acl_user_instance($this->dbNode)->id]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'database.driver_unsupported');

        expect(DatabaseConnection::query()->exists())->toBeFalse();
    });

    it('refuses an invalid slug, pattern, rule, or database index', function (array $overrides, string $field): void {
        $this->postJson('/api/v1/database-connections', [...valkey_acl_user_request(), ...$overrides])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonValidationErrors($field, 'error.details');

    })->with([
        'the admin user' => [['slug' => 'default'], 'slug'],
        'no patterns' => [['patterns' => []], 'patterns'],
        'a pattern string' => [['patterns' => 'orbit-gateway:*'], 'patterns'],
        'a pattern map' => [['patterns' => ['a' => 'orbit-gateway:*']], 'patterns'],
        '33 patterns' => [['patterns' => array_map(static fn (int $index): string => "p{$index}:*", range(1, 33))], 'patterns'],
        'a pattern with a space' => [['patterns' => ['orbit gateway:*']], 'patterns.0'],
        'a pattern with a control character' => [['patterns' => ["orbit-gateway:*\n"]], 'patterns.0'],
        'a pattern that starts with ~' => [['patterns' => ['~orbit-gateway:*']], 'patterns.0'],
        'a read pattern' => [['patterns' => ['%R~orbit-gateway:*']], 'patterns.0'],
        'a channel pattern' => [['patterns' => ['&orbit-gateway:*']], 'patterns.0'],
        'every key' => [['patterns' => ['*']], 'patterns.0'],
        'a pattern that starts with a glob' => [['patterns' => ['?rbit-gateway:*']], 'patterns.0'],
        'a pattern that starts with a character class' => [['patterns' => ['[o]rbit-gateway:*']], 'patterns.0'],
        'a pattern that starts with an escape' => [['patterns' => ['\\*']], 'patterns.0'],
        'a long pattern' => [['patterns' => [str_repeat('k', 256)]], 'patterns.0'],
        'no rules' => [['allow' => []], 'allow'],
        '65 rules' => [['allow' => array_fill(0, 65, 'get')], 'allow'],
        'a rule with a plus' => [['allow' => ['+@list']], 'allow.0'],
        'a deny rule' => [['allow' => ['-@all']], 'allow.0'],
        'an uppercase rule' => [['allow' => ['@List']], 'allow.0'],
        'two rules in one' => [['allow' => ['get set']], 'allow.0'],
        'a key pattern as a rule' => [['allow' => ['~*']], 'allow.0'],
        'an empty subcommand' => [['allow' => ['client|']], 'allow.0'],
        'a password rule' => [['allow' => ['>secret']], 'allow.0'],
        'every command' => [['allow' => ['@all']], 'allow.0'],
        'the scripting commands' => [['allow' => ['@scripting']], 'allow.0'],
        'a string index' => [['database' => '1'], 'database'],
        'a negative index' => [['database' => -1], 'database'],
        'a large index' => [['database' => 1000], 'database'],
        'a host' => [['host' => '10.44.0.80'], 'host'],
        'a username' => [['username' => 'gateway-queue'], 'username'],
        'a password' => [['password' => 'chosen'], 'password'],
    ]);

    it('reports a missing server before it checks the Valkey fields', function (): void {
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request(['server' => 'missing', 'patterns' => []]))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'database.server_missing');
    });
});

describe('the Valkey fields elsewhere', function (): void {
    it('refuses patterns, rules, and a database index on a MySQL server', function (array $overrides): void {
        DatabaseServer::query()->create([
            'slug' => 'beast-mysql',
            'node_id' => $this->dbNode->id,
            'tag' => '8.4',
            'port' => 3306,
            'root_password' => 'mysql-root-secret',
            'status' => LifecycleStatus::Active,
        ]);

        $this->postJson('/api/v1/database-connections', ['slug' => 'reporting', 'server' => 'beast-mysql', ...$overrides])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect(DatabaseConnection::query()->exists())->toBeFalse();
    })->with([
        'patterns' => [['patterns' => ['reporting:*']]],
        'rules' => [['allow' => ['@read']]],
        'a database index' => [['database' => 1]],
    ]);

    it('refuses patterns and rules on a registered connection', function (string $field): void {
        $this->postJson('/api/v1/database-connections', [
            'slug' => 'cache',
            'driver' => 'redis',
            'host' => '10.44.0.80',
            $field => ['@read'],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect(DatabaseConnection::query()->exists())->toBeFalse();
    })->with(['patterns', 'allow']);

    it('refuses database:user:create on a connection of a Valkey server', function (): void {
        valkey_acl_recorded($this->server);

        $this->postJson('/api/v1/database-connections/gateway-queue/users', [
            'username' => 'reporting',
            'password' => 'reporting-secret',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'database.driver_unsupported');

        expect(DatabaseUser::query()->count())->toBe(1);
    });
});

describe('database:destroy on a Valkey server', function (): void {
    it('admits removal without touching the ACL or deleting records', function (?string $username): void {
        $connection = valkey_acl_recorded($this->server, $username);
        $this->deleteJson('/api/v1/database-connections/gateway-queue')
            ->assertAccepted()->assertJsonPath('data.kind', 'database.destroy');
        $this->assertModelExists($connection);
    })->with(['managed user' => ['gateway-queue'], 'no username' => [null], 'default' => ['default']]);
});

it('runs removal guards before deleting a managed ACL user or its records', function (): void {
    valkey_acl_recorded($this->server);
    $guard = new class implements DatabaseConnectionRemovalGuard
    {
        public function assertRemovable(DatabaseConnection $connection): void
        {
            throw new ResourceOperationException('database_connection.test_required', 'The connection is in use.', 409);
        }
    };
    app()->instance($guard::class, $guard);
    app()->tag($guard::class, DatabaseConnectionRemovalGuard::class);

    $this->deleteJson('/api/v1/database-connections/gateway-queue')->assertConflict()
        ->assertJsonPath('error.code', 'database_connection.test_required');

    expect(DatabaseConnection::query()->where('slug', 'gateway-queue')->exists())->toBeTrue()
        ->and(DatabaseUser::query()->where('username', 'gateway-queue')->exists())->toBeTrue();
});
