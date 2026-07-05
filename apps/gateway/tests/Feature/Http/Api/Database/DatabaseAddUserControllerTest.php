<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Services\RemoteShell\RemoteShellMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const DATABASE_ADD_USER_CALLER_WG_IP = '10.9.0.137';

function createDatabaseAddUserCallerNode(): Node
{
    return Node::factory()->create([
        'name' => 'database-add-user-caller',
        'host' => DATABASE_ADD_USER_CALLER_WG_IP,
        'wireguard_address' => DATABASE_ADD_USER_CALLER_WG_IP,
    ]);
}

function assignDatabaseAddUserGatewayRole(Node $node): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);
}

function createManagedMysqlProcess(Node $node, array $overrides = []): Process
{
    return Process::factory()
        ->forOwner($node)
        ->create(array_merge([
            'name' => 'mysql8',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'service' => 'mysql',
                'version' => '8.3',
                'endpoint' => [
                    'host' => '10.6.0.42',
                    'port' => 3308,
                ],
            ],
        ], $overrides));
}

/**
 * @param  array<string, string>  $metadata
 */
function assert_approved_database_add_user_remote_shell_metadata(array $metadata, string $processName): void
{
    expect($metadata)
        ->not
        ->toHaveKeys(['operation', 'process'])
        ->and($metadata)
        ->toMatchArray([
            'ORBIT_OPERATION_ID' => 'database.add-user',
            'ORBIT_TOOL_SERVICE' => $processName,
        ]);

    $validator = new RemoteShellMetadata;

    foreach ($metadata as $key => $value) {
        $validator->validate($key, $value);
    }
}

it('adds a mysql user through a managed docker process and stores the connection without leaking secrets', function (): void {
    $caller = createDatabaseAddUserCallerNode();
    assignDatabaseAddUserGatewayRole($caller);
    $node = createTestAppHostNode(['name' => 'beast']);
    createManagedMysqlProcess($node);
    $shell = new DatabaseAddUserRemoteShell(new RemoteShellResult(
        exitCode: 0,
        stdout: '',
        stderr: '',
        durationMs: 12,
    ));

    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/database-connections/dlf-leden/users',
        [
            'service' => 'mysql8',
            'node' => 'beast',
            'database' => 'dlf_leden',
            'username' => 'dlf_leden',
            'password' => 'super-secret',
        ],
        [],
        [],
        ['REMOTE_ADDR' => DATABASE_ADD_USER_CALLER_WG_IP],
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.connection.slug', 'dlf-leden')
        ->assertJsonPath('success.data.connection.driver', 'mysql')
        ->assertJsonPath('success.data.connection.host', '10.6.0.42')
        ->assertJsonPath('success.data.connection.port', 3308)
        ->assertJsonPath('success.data.connection.database', 'dlf_leden')
        ->assertJsonPath('success.data.connection.username', 'dlf_leden')
        ->assertJsonMissingPath('success.data.connection.credentials');

    $connection = DatabaseConnection::query()->where('slug', 'dlf-leden')->firstOrFail();

    expect($connection->credentials)
        ->toBe(['password' => 'super-secret'])
        ->and($response->getContent())
        ->not->toContain('super-secret')->and($shell->script)->toContain(
            'internal:database-add-user',
        )->and($shell->script)
        ->not->toContain('super-secret')->and($shell->options['input'])->toContain(
            '"container":"mysql8"',
        )->and($shell->options['input'])->toContain(
            '"database":"dlf_leden"',
        )->and($shell->options['input'])->toContain('"username":"dlf_leden"');
});

it('updates an existing connection after converging the mysql user', function (): void {
    $caller = createDatabaseAddUserCallerNode();
    assignDatabaseAddUserGatewayRole($caller);
    $node = createTestAppHostNode(['name' => 'beast']);
    createManagedMysqlProcess($node);
    DatabaseConnection::factory()->create([
        'slug' => 'dlf-leden',
        'driver' => 'mysql',
        'host' => '10.6.0.10',
        'port' => 3306,
        'database' => 'old_database',
        'username' => 'old_user',
        'credentials' => ['password' => 'old-secret'],
    ]);

    app()->instance(RemoteShell::class, new DatabaseAddUserRemoteShell(new RemoteShellResult(
        exitCode: 0,
        stdout: '',
        stderr: '',
        durationMs: 12,
    )));

    $response = $this->call(
        'POST',
        '/api/database-connections/dlf-leden/users',
        [
            'service' => 'mysql8',
            'node' => 'beast',
            'database' => 'dlf_leden',
            'username' => 'dlf_leden',
            'password' => 'new-secret',
        ],
        [],
        [],
        ['REMOTE_ADDR' => DATABASE_ADD_USER_CALLER_WG_IP],
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.connection.host', '10.6.0.42')
        ->assertJsonPath('success.data.connection.database', 'dlf_leden');

    $connection = DatabaseConnection::query()->where('slug', 'dlf-leden')->firstOrFail();

    expect($connection->credentials)->toBe(['password' => 'new-secret']);
});

it('passes approved remote-shell metadata when converging a renamed managed mysql8 process', function (): void {
    $caller = createDatabaseAddUserCallerNode();
    assignDatabaseAddUserGatewayRole($caller);
    $node = createTestAppHostNode(['name' => 'beast']);
    createManagedMysqlProcess($node);
    $password = fake()->password();
    $shell = new DatabaseAddUserRemoteShell(new RemoteShellResult(
        exitCode: 0,
        stdout: '',
        stderr: '',
        durationMs: 12,
    ));

    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/database-connections/dngdmt/users',
        [
            'service' => 'mysql8',
            'node' => 'beast',
            'database' => 'dngdmt',
            'username' => 'dngdmt',
            'password' => $password,
        ],
        [],
        [],
        ['REMOTE_ADDR' => DATABASE_ADD_USER_CALLER_WG_IP],
    );

    $response->assertOk();

    assert_approved_database_add_user_remote_shell_metadata(
        $shell->options['metadata'] ?? [],
        processName: 'mysql8',
    );
});

it('fails before remote convergence for non docker managed mysql processes', function (): void {
    $caller = createDatabaseAddUserCallerNode();
    assignDatabaseAddUserGatewayRole($caller);
    $node = createTestAppHostNode(['name' => 'beast']);
    createManagedMysqlProcess($node, ['runtime' => ProcessRuntime::DockerSwarm]);
    $password = fake()->password();
    $shell = new DatabaseAddUserRemoteShell(new RemoteShellResult(
        exitCode: 0,
        stdout: '',
        stderr: '',
        durationMs: 12,
    ));

    app()->instance(RemoteShell::class, $shell);

    $response = $this->call(
        'POST',
        '/api/database-connections/dlf-leden/users',
        [
            'service' => 'mysql8',
            'node' => 'beast',
            'database' => 'dlf_leden',
            'username' => 'dlf_leden',
            'password' => $password,
        ],
        [],
        [],
        ['REMOTE_ADDR' => DATABASE_ADD_USER_CALLER_WG_IP],
    );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'runtime');

    expect($shell->script)->toBe('');
});

final class DatabaseAddUserRemoteShell implements RemoteShell
{
    public string $script = '';

    /** @var array<string, mixed> */
    public array $options = [];

    public function __construct(
        private readonly RemoteShellResult $result,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->script = $script;
        $this->options = $options;

        return $this->result;
    }
}
