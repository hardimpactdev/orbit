<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeDatabaseServerAdmin;
use Tests\Support\FakeValkeyAclAgent;
use Tests\Support\FakeValkeyServerAdmin;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    app()->instance(DatabaseServerAdmin::class, new FakeDatabaseServerAdmin);
    $this->valkey = new FakeValkeyServerAdmin;
    app()->instance(ValkeyServerAdmin::class, $this->valkey);
    $this->programs = new FakeValkeyAclAgent(app(AgentCommandRunner::class));
    app()->instance(AgentCommandRunner::class, $this->programs);

    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'tld' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->gateway = $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);

    $this->dbNode = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.80',
    ]);
});

/** @return array<string, mixed> */
function valkey_server_request(Node $node, array $overrides = []): array
{
    return ['slug' => 'beast-valkey', 'node_id' => $node->id, 'driver' => 'redis', ...$overrides];
}

describe('database:server:create --driver=redis', function (): void {

    it('refuses the slug of a server with another driver', function (): void {
        $this->postJson('/api/v1/database-servers', ['slug' => 'beast-db', 'node_id' => $this->dbNode->id, 'port' => 6379])
            ->assertAccepted()
            ->assertJsonPath('data.kind', 'database_server.create');

        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode, ['slug' => 'beast-db', 'tag' => '8.4', 'port' => 6379]))
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_slug_conflict');

        expect(DatabaseServer::query()->sole()->driver->value)->toBe('mysql')
            ->and($this->programs->runs)->toBe([]);
    });

    it('validates the driver and the adoption fields', function (array $body): void {
        $this->postJson('/api/v1/database-servers', $body)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect(DatabaseServer::query()->exists())->toBeFalse()
            ->and($this->programs->runs)->toBe([]);
    })->with([
        'pgsql' => fn (): array => valkey_server_request($this->dbNode, ['driver' => 'sqlite']),
        'sqlite' => fn (): array => valkey_server_request($this->dbNode, ['driver' => 'sqlite']),
        'unknown driver' => fn (): array => valkey_server_request($this->dbNode, ['driver' => 'valkey']),
        'process without connection' => fn (): array => valkey_server_request($this->dbNode, ['process_id' => 1]),
        'connection without process' => fn (): array => valkey_server_request($this->dbNode, ['connection' => 'services-valkey']),
        'process with port' => fn (): array => valkey_server_request($this->dbNode, ['process_id' => 1, 'connection' => 'services-valkey', 'port' => 6379]),
        'string process' => fn (): array => valkey_server_request($this->dbNode, ['process_id' => '1', 'connection' => 'services-valkey']),
        'bad connection slug' => fn (): array => valkey_server_request($this->dbNode, ['process_id' => 1, 'connection' => 'Services Valkey']),
    ]);

    it('lists and shows the driver of each server', function (): void {
        $this->postJson('/api/v1/database-servers', ['slug' => 'beast-mysql', 'node_id' => $this->dbNode->id])->assertAccepted();
        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode))->assertAccepted();

        $this->getJson('/api/v1/database-servers')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'beast-mysql')
            ->assertJsonPath('data.0.driver', 'mysql')
            ->assertJsonPath('data.1.slug', 'beast-valkey')
            ->assertJsonPath('data.1.driver', 'redis');

        $this->getJson('/api/v1/database-servers/beast-valkey')
            ->assertOk()
            ->assertJsonPath('data.driver', 'redis')
            ->assertJsonPath('data.databases', []);
    });
});

describe('the Process of a Valkey server', function (): void {
    beforeEach(function (): void {
        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode))->assertAccepted();
        $this->server = DatabaseServer::query()->sole();
        $this->process = Process::query()->sole();
    });

    it('refuses process:update before it changes anything', function (): void {
        $before = $this->process->getAttributes();

        $this->patchJson("/api/v1/processes/{$this->process->id}", [
            'runtime' => 'docker',
            'image' => 'valkey/valkey:8.1',
            'command' => ['valkey-server', '--appendonly', 'yes'],
            'environment' => ['VALKEY_EXTRA_FLAGS' => '--aclfile /dev/null'],
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'process.required_by_database_server');

        expect($this->process->refresh()->getAttributes())->toBe($before)
            ->and($this->runtime->convergedProcessIds)->toBe([]);
    });

});

describe('database:server:create --process --connection', function (): void {
    beforeEach(function (): void {
        $this->otherNode = Node::query()->create([
            'name' => 'shark',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.81',
            'public_ssh_port' => 22,
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.81',
        ]);
        $this->process = Process::query()->create([
            'owner_type' => Node::class,
            'owner_id' => $this->dbNode->id,
            'name' => 'valkey',
            'runtime' => ProcessRuntime::Docker,
            'working_directory' => null,
            'runtime_config' => [
                'image' => 'valkey/valkey:8.0',
                'command' => ['sh', '-c', 'exec valkey-server --appendonly yes --requirepass "$VALKEY_PASSWORD"'],
                'environment' => ['VALKEY_PASSWORD' => 'queue-secret'],
                'ports' => ['10.44.0.80:6379:6379'],
                'volumes' => [['source' => 'services-valkey-data', 'target' => '/data', 'read_only' => false]],
            ],
            'restart_policy' => 'unless-stopped',
            'desired_state' => DesiredProcessState::Running,
            'status' => LifecycleStatus::Active,
        ]);
        $this->connection = DatabaseConnection::query()->create([
            'slug' => 'services-valkey',
            'driver' => 'redis',
            'node_id' => $this->dbNode->id,
            'host' => '10.44.0.80',
            'port' => 6379,
            'password' => 'queue-secret',
        ]);
        $this->valkey->passwords['10.44.0.80:6379'] = 'queue-secret';
        $this->valkey->users['default'] = ValkeyAclUser::withPassword('default', ValkeyAclUser::ADMIN_RULES, 'queue-secret');
        $this->adoption = valkey_server_request($this->dbNode, [
            'slug' => 'services-valkey',
            'process_id' => $this->process->id,
            'connection' => 'services-valkey',
        ]);
    });

    it('refuses adoption that would replace persistence, database indexes, or default authentication', function (string $field, string $value): void {
        if ($field === 'passwords') {
            $this->valkey->users['default'] = new ValkeyAclUser('default', ValkeyAclUser::ADMIN_RULES, [hash('sha256', 'queue-secret'), hash('sha256', $value)]);
        } else {
            $this->valkey->configuration[$field] = $value;
        }
        $before = $this->process->refresh()->getAttributes();

        $this->postJson('/api/v1/database-servers', $this->adoption)->assertConflict()
            ->assertJsonPath('error.code', 'database.server_adoption_refused');

        expect(DatabaseServer::query()->exists())->toBeFalse()
            ->and($this->process->refresh()->getAttributes())->toBe($before)
            ->and($this->connection->refresh()->database_server_id)->toBeNull()
            ->and($this->programs->runs)->toBe([])
            ->and($this->runtime->restarted)->toBe([]);
    })->with([
        'working directory' => ['dir', '/data/custom'],
        'RDB-only persistence' => ['appendonly', 'no'],
        'append directory' => ['appenddirname', 'custom-aof'],
        'append filename' => ['appendfilename', 'custom.aof'],
        'snapshot filename' => ['dbfilename', 'custom.rdb'],
        'database count' => ['databases', '32'],
        'extra password' => ['passwords', 'second-secret'],
    ]);

    it('refuses adoption that would overwrite existing ACL users', function (): void {
        $this->valkey->users['queue'] = new ValkeyAclUser('queue', 'on ~queue:* -@all +@list', []);
        $this->postJson('/api/v1/database-servers', $this->adoption)->assertConflict()->assertJsonPath('error.code', 'database.server_adoption_refused');
        expect($this->programs->runs)->toBe([])->and(DatabaseServer::query()->exists())->toBeFalse();
    });

    it('refuses concurrent provisioning before any remote mutation', function (): void {
        $lock = Cache::lock('database-server:provision:services-valkey', 900);
        $lock->get();
        try {
            $this->postJson('/api/v1/database-servers', $this->adoption)->assertConflict()->assertJsonPath('error.code', 'database.server_in_use');
            expect($this->programs->runs)->toBe([])->and(DatabaseServer::query()->exists())->toBeFalse();
        } finally {
            $lock->release();
        }
    });

    it('refuses another Process for an adopted slug', function (): void {
        $this->postJson('/api/v1/database-servers', $this->adoption)->assertAccepted();

        $this->postJson('/api/v1/database-servers', [...$this->adoption, 'process_id' => $this->process->id + 1])
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_slug_conflict');
    });

    it('refuses an adoption that fails a check, names the check, and changes nothing', function (Closure $arrange, string $message): void {
        $arrange->call($this);
        $process = $this->process->refresh()->getAttributes();
        $servers = DatabaseServer::query()->count();

        $this->postJson('/api/v1/database-servers', $this->adoption)
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_adoption_refused')
            ->assertJsonPath('error.message', fn (string $actual): bool => str_contains($actual, $message));

        expect(DatabaseServer::query()->count())->toBe($servers)
            ->and($this->process->refresh()->getAttributes())->toBe($process)
            ->and($this->connection->refresh()->database_server_id)->toBeNull()
            ->and($this->programs->runs)->toBe([])
            ->and($this->runtime->convergedProcessIds)->toBe([]);
    })->with([
        'a mysql driver' => [function (): void {
            $this->adoption['driver'] = 'mysql';
        }, 'Only a redis server can adopt a Process.'],
        'no driver' => [function (): void {
            unset($this->adoption['driver']);
        }, 'Only a redis server can adopt a Process.'],
        'a missing Process' => [function (): void {
            $this->adoption['process_id'] = 9999;
        }, 'is not a Docker Process of Node [beast]'],
        'a Process of another Node' => [function (): void {
            $this->process->update(['owner_id' => $this->otherNode->id]);
        }, 'is not a Docker Process of Node [beast]'],
        'a systemd Process' => [function (): void {
            $this->process->update(['runtime' => ProcessRuntime::Systemd]);
        }, 'is not a Docker Process of Node [beast]'],
        'another image' => [function (): void {
            $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'image' => 'redis:8']]);
        }, 'does not run a valkey/valkey image'],
        'another repository' => [function (): void {
            $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'image' => 'valkey/valkey-bundle:8.1']]);
        }, 'does not run a valkey/valkey image'],
        'no volume at /data' => [function (): void {
            $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'volumes' => []]]);
        }, 'has no Docker volume at /data'],
        'a host path at /data' => [function (): void {
            $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'volumes' => [['source' => '/srv/valkey', 'target' => '/data', 'read_only' => false]]]]);
        }, 'has no Docker volume at /data'],
        'a port outside WireGuard' => [function (): void {
            $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'ports' => ['127.0.0.1:6379:6379']]]);
        }, 'does not publish container port 6379 on the WireGuard address of Node [beast]'],
        'another container port' => [function (): void {
            $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'ports' => ['10.44.0.80:6379:6380']]]);
        }, 'does not publish container port 6379 on the WireGuard address of Node [beast]'],
        'a Process that a server owns' => [function (): void {
            DatabaseServer::query()->create([
                'slug' => 'older-valkey',
                'driver' => 'redis',
                'node_id' => $this->dbNode->id,
                'process_id' => $this->process->id,
                'tag' => '8.1',
                'port' => 6379,
                'root_password' => 'other-secret',
                'status' => LifecycleStatus::Active,
            ]);
        }, 'already runs Database server [older-valkey]'],
        'a missing connection' => [function (): void {
            $this->adoption['connection'] = 'missing';
        }, 'Database connection [missing] is not a redis connection.'],
        'a mysql connection' => [function (): void {
            $this->connection->update(['driver' => 'mysql', 'database' => 'app', 'username' => 'app']);
        }, 'Database connection [services-valkey] is not a redis connection.'],
        'a connection to another host' => [function (): void {
            $this->connection->update(['host' => '10.44.0.81']);
        }, 'Database connection [services-valkey] does not point to 10.44.0.80:6379.'],
        'a connection to another port' => [function (): void {
            $this->connection->update(['port' => 6380]);
        }, 'Database connection [services-valkey] does not point to 10.44.0.80:6379.'],
        'a connection without a password' => [function (): void {
            $this->connection->update(['password' => null]);
        }, 'The password of Database connection [services-valkey] does not log in as default.'],
        'a connection with an ACL user' => [function (): void {
            $this->connection->update(['username' => 'queue']);
        }, 'The password of Database connection [services-valkey] does not log in as default.'],
        'a password that does not log in' => [function (): void {
            $this->valkey->passwords['10.44.0.80:6379'] = 'rotated-secret';
        }, 'The password of Database connection [services-valkey] does not log in as default.'],
    ]);
});

describe('database:server:create on an active Valkey server', function (): void {
    it('refuses a repair while another server Operation holds the target', function (): void {
        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode))->assertAccepted();
        DatabaseServer::query()->sole()->update(['status' => LifecycleStatus::Active]);

        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode))
            ->assertConflict()
            ->assertJsonPath('error.code', 'operation.target_busy');

        expect($this->valkey->calls)->toBe(['login 10.44.0.80:6379'])
            ->and($this->programs->runs)->toBe([]);
    });
});

describe('database:server:close boundary', function (): void {
    it('refuses MySQL and missing servers', function (): void {
        $this->postJson('/api/v1/database-servers', ['slug' => 'beast-mysql', 'node_id' => $this->dbNode->id])->assertAccepted();
        $this->postJson('/api/v1/database-servers/beast-mysql/close')->assertUnprocessable()->assertJsonPath('error.code', 'database.driver_unsupported');
        $this->postJson('/api/v1/database-servers/missing/close')->assertNotFound();
    });

    it('requires an active peer', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->postJson('/api/v1/database-servers/missing/close')->assertForbidden();
    });
});
