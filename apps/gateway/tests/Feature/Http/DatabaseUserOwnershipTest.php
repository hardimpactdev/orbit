<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Domain\DatabaseServers\DatabaseStatements;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
 * database:user:create runs as the server's admin and sets the named user's password and grants. A user belongs to one
 * connection, so the command refuses every name that belongs to someone else before any Operation is recorded: the
 * admin login and the engine's system roles, the owner of any connection, and a user another connection recorded.
 * On the server, the batch refuses an account that Orbit has no record of for this connection.
 */

beforeEach(function (): void {
    $node = static fn (string $name, string $ip): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    $gateway = $node('gateway', '10.44.0.1');
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $gateway->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $dbNode = $node('db', '10.44.0.12');
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    foreach (['mysql' => ['mysql:8.4', 'mysqld', 3307], 'pgsql' => ['postgres:18', 'postgres', 5433]] as $driver => [$image, $command, $port]) {
        $server = DatabaseServer::query()->create([
            'slug' => "beast-{$driver}", 'driver' => $driver, 'node_id' => $dbNode->id, 'tag' => explode(':', $image)[1], 'port' => $port,
            'status' => 'active', 'root_password' => 'root-secret-sentinel',
        ]);
        $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $dbNode->id, 'name' => "beast-{$driver}", 'runtime' => 'docker',
            'runtime_config' => ['image' => $image, 'command' => [$command]], 'desired_state' => 'running', 'status' => 'active', 'keep_alive' => true]);
        $server->update(['process_id' => $process->id]);
        foreach (['shop', 'blog'] as $name) {
            DatabaseConnection::query()->create([
                'slug' => "{$name}-{$driver}", 'driver' => $driver, 'node_id' => $dbNode->id, 'host' => '10.44.0.12', 'port' => $port,
                'database' => $name, 'username' => "{$name}_owner", 'password' => 'owner-secret-sentinel', 'database_server_id' => $server->id,
            ]);
        }
        DatabaseUser::query()->create(['database_connection_id' => DatabaseConnection::query()->where('slug', "blog-{$driver}")->value('id'),
            'username' => 'blog_move', 'password' => 'blog-move-secret-sentinel', 'privileges' => 'x']);
        DatabaseUser::query()->create(['database_connection_id' => DatabaseConnection::query()->where('slug', "shop-{$driver}")->value('id'),
            'username' => 'shop_move', 'password' => 'shop-move-old-sentinel', 'privileges' => 'x']);
    }
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });
});

$create = fn (string $driver, string $username) => test()->postJson("/api/v1/database-connections/shop-{$driver}/users", [
    'username' => $username, 'password' => 'caller-secret-sentinel',
]);

it('refuses the admin login and system roles before any run', function (string $driver, string $username) use ($create): void {
    $create($driver, $username)->assertStatus(422)->assertJsonPath('error.code', 'database.user_reserved');

    expect(Operation::query()->count())->toBe(0)->and(DatabaseExecutionIntent::query()->count())->toBe(0);
})->with([
    'mysql root' => ['mysql', 'root'],
    'mysql root in capitals' => ['mysql', 'ROOT'],
    'mysql sys' => ['mysql', 'sys'],
    'mysql mysql' => ['mysql', 'mysql'],
    'pgsql postgres' => ['pgsql', 'postgres'],
    'pgsql system role' => ['pgsql', 'pg_read_all_data'],
    'pgsql public' => ['pgsql', 'public'],
]);

it('refuses the owner of any connection before any run', function (string $driver, string $username) use ($create): void {
    $create($driver, $username)->assertStatus(409)->assertJsonPath('error.code', 'database.name_conflict');

    expect(Operation::query()->count())->toBe(0);
})->with([
    'mysql own owner' => ['mysql', 'shop_owner'],
    'mysql other owner' => ['mysql', 'blog_owner'],
    'pgsql other owner' => ['pgsql', 'blog_owner'],
]);

it('refuses a user that another connection recorded before any run', function (string $driver) use ($create): void {
    $create($driver, 'blog_move')->assertStatus(409)->assertJsonPath('error.code', 'database.name_conflict')
        ->assertJsonPath('error.message', "User [blog_move] belongs to another connection on Database server [beast-{$driver}].");

    expect(Operation::query()->count())->toBe(0)
        ->and(DatabaseUser::query()->where('username', 'blog_move')->whereHas('databaseConnection', fn ($q) => $q->where('driver', $driver))->sole()->password)->toBe('blog-move-secret-sentinel');
})->with(['mysql', 'pgsql']);

it('refuses a name that is not a plain identifier before any run', function (string $username) use ($create): void {
    $create('mysql', $username)->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    expect(Operation::query()->count())->toBe(0);
})->with([
    'system account with a dot' => ['mysql.sys'],
    'account with a host' => ["root'@'%"],
    'quote' => ["x'y"],
    'hyphen' => ['debian-sys-maint'],
    'leading digit' => ['1user'],
    'too long' => [str_repeat('a', 33)],
]);

it('creates a new user behind the server guard, without the password in the Operation', function (string $driver) use ($create): void {
    $response = $create($driver, 'reporting')->assertAccepted()->assertJsonPath('data.kind', 'database_user.create');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $intent = DatabaseExecutionIntent::recorded($operation->input['intent_id']);
    $input = $intent->payload['commands']['database-apply']['input'];
    $apply = app(DatabaseStatements::class)->user($input, $input['payload']['password'], $input['payload']['salt'])['apply'];

    expect($input['known_user'])->toBeFalse()
        ->and($apply)->toContain('database.name_conflict')
        ->and(strpos($apply, 'database.name_conflict'))->toBeLessThan(strpos($apply, $driver === 'mysql' ? 'CREATE USER' : 'CREATE ROLE'))
        ->and($response->getContent())->not->toContain('caller-secret-sentinel')
        ->and(json_encode($operation->input))->not->toContain('caller-secret-sentinel')
        ->and(json_encode($operation->result))->not->toContain('caller-secret-sentinel')
        ->and($intent->payload['commands']['database-apply']['protected'])->toBe(['payload'])
        ->and($intent->payload['commands']['database-apply']['redactions'])->toBe(['caller-secret-sentinel']);
})->with(['mysql', 'pgsql']);

it('updates a user this connection recorded', function (string $driver) use ($create): void {
    $response = $create($driver, 'shop_move')->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $input = DatabaseExecutionIntent::recorded($operation->input['intent_id'])->payload['commands']['database-apply']['input'];

    expect($input['known_user'])->toBeTrue()->and($input['username'])->toBe('shop_move')
        ->and(json_encode($operation->input))->not->toContain('caller-secret-sentinel');
})->with(['mysql', 'pgsql']);

it('stops a MySQL batch on an account this connection did not record', function (): void {
    $input = ['driver' => 'mysql', 'username' => 'stranger', 'database' => 'shop', 'owner_username' => 'shop_owner',
        'read_only' => false, 'replication' => false, 'known_user' => false];
    $apply = app(DatabaseStatements::class)->user($input, 'p', base64_encode('0123456789abcdef'))['apply'];
    $guard = "SELECT 1 FROM mysql.user WHERE User='stranger'";

    // The marker names the refusal. The stop statement fails on any server, whatever tables its users made, so the
    // batch stops before CREATE USER and ALTER USER.
    expect($apply)->toStartWith("SELECT 'database.name_conflict' FROM DUAL WHERE EXISTS ({$guard});\n"
        ."SET @orbit_guard = IF(EXISTS ({$guard}), 'DO (SELECT 1 UNION ALL SELECT 2)', 'DO 0');\n"
        ."PREPARE orbit_guard FROM @orbit_guard;\nEXECUTE orbit_guard;\nDEALLOCATE PREPARE orbit_guard;\nCREATE USER IF NOT EXISTS 'stranger'@'%'")
        ->and(app(DatabaseStatements::class)->user([...$input, 'known_user' => true], 'p', base64_encode('0123456789abcdef'))['apply'])
        ->toStartWith("CREATE USER IF NOT EXISTS 'stranger'@'%'")
        ->and($apply)->not->toContain('orbit_name_conflict');
});

it('stops a MySQL database create with a statement no table can satisfy', function (): void {
    $create = app(DatabaseStatements::class)->create(['driver' => 'mysql', 'username' => 'shop', 'databases' => ['shop'], 'test_prefix' => null,
        'locale' => null, 'index' => null, 'patterns' => [], 'allow' => []], 'p', base64_encode('0123456789abcdef'))['create'];

    expect($create)->toContain("'DO (SELECT 1 UNION ALL SELECT 2)', 'DO 0');\nPREPARE orbit_guard FROM @orbit_guard;\nEXECUTE orbit_guard;\n")
        ->and($create)->not->toContain('orbit_name_conflict')
        ->and(strpos($create, 'EXECUTE orbit_guard'))->toBeLessThan((int) strpos($create, 'CREATE USER'));
});

it('refuses a database whose derived user is the admin login', function (): void {
    $this->postJson('/api/v1/database-connections', ['slug' => 'root', 'server' => 'beast-mysql'])
        ->assertStatus(422)->assertJsonPath('error.code', 'database.user_reserved');

    expect(DatabaseConnection::query()->where('slug', 'root')->exists())->toBeFalse()
        ->and(Operation::query()->count())->toBe(0);
});

it('runs one user create per server at a time', function () use ($create): void {
    $create('mysql', 'reporting')->assertAccepted();

    $this->postJson('/api/v1/database-connections/blog-mysql/users', ['username' => 'reporting', 'password' => 'other-secret-sentinel'])
        ->assertStatus(409)->assertJsonPath('error.code', 'operation.target_busy');
    $create('pgsql', 'reporting')->assertAccepted();

    expect(Operation::query()->where('kind', 'database_user.create')->count())->toBe(2);
});

it('keeps a managed SQL connection on its own database and user', function (string $driver, array $patch): void {
    $this->patchJson("/api/v1/database-connections/shop-{$driver}", $patch)
        ->assertStatus(409)->assertJsonPath('error.code', 'database.server_connection_managed');

    $connection = DatabaseConnection::query()->where('slug', "shop-{$driver}")->sole();
    expect($connection->database)->toBe('shop')->and($connection->username)->toBe('shop_owner')
        ->and(Operation::query()->count())->toBe(0);
})->with([
    'mysql another database' => ['mysql', ['database' => 'blog']],
    'mysql the admin user' => ['mysql', ['username' => 'root']],
    'mysql another owner' => ['mysql', ['username' => 'blog_owner']],
    'pgsql another database' => ['pgsql', ['database' => 'blog']],
]);

it('never drops the admin login that an old record names', function (string $driver, string $admin): void {
    $blog = DatabaseConnection::query()->where('slug', "blog-{$driver}")->sole();
    DatabaseUser::query()->create(['database_connection_id' => $blog->id, 'username' => $admin, 'password' => 'old-record', 'privileges' => 'x']);

    $prepared = app(DropServerDatabaseAction::class)->prepareMany([$blog]);

    expect($prepared[0]->input['users'])->toBe(['blog_owner', 'blog_move']);
})->with([['mysql', 'root'], ['pgsql', 'postgres']]);
