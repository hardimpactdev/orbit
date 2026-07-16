<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stores renders and applies env only to the selected workspace', function (): void {
    $caller = Node::factory()->create([
        'host' => '10.6.0.120',
        'wireguard_address' => '10.6.0.120',
    ]);
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.81',
        ]);
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $node->id,
        'permissions' => json_encode(['workspace:read', 'workspace:write'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'billing',
        'path' => '/home/orbit/apps/billing',
        'runtime' => 'php',
        'php_version' => '8.5',
    ]);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            path: '/home/orbit/apps/billing-development',
            document_root: null,
            domain: 'billing-development.test',
        ),
    ]);
    $stagingNode = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-staging-1',
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.82',
        ]);
    $stagingInstance = AppInstance::factory()->for($app)->create([
        'name' => 'staging',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $stagingNode->id,
            path: '/srv/orbit/apps/billing-staging',
            document_root: null,
            domain: 'billing-staging.example.com',
        ),
    ]);
    $stagingInstance
        ->envVariables()
        ->create([
            'key' => 'APP_ENV',
            'value' => 'staging',
            'secret' => false,
        ]);
    $workspace = Workspace::factory()
        ->for($app)
        ->for($instance, 'appInstance')
        ->create([
            'name' => 'feature-mail',
            'path' => '/worktrees/feature-mail',
        ]);
    $workspace
        ->envVariables()
        ->create([
            'key' => 'EXISTING_VALUE',
            'value' => 'preserved',
            'secret' => false,
        ]);
    $databasePassword = fake()->sha256();
    $connection = DatabaseConnection::factory()->for($node)->create([
        'slug' => 'billing-db',
        'driver' => 'pgsql',
        'host' => 'postgres.internal',
        'port' => 5432,
        'database' => 'billing',
        'username' => 'billing',
        'credentials' => ['password' => $databasePassword],
    ]);
    DatabaseConnectionTarget::factory()
        ->for($connection, 'connection')
        ->forWorkspace($workspace)
        ->create(['env_prefix' => 'DB']);
    Workspace::factory()
        ->for($app)
        ->for($instance, 'appInstance')
        ->create([
            'name' => 'feature-billing',
            'path' => '/worktrees/feature-billing',
        ]);

    $shell = new WorkspaceEnvControllerRecordingRemoteShell;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(\App\Services\Ca\OrbitCaService::class, new readonly class extends \App\Services\Ca\OrbitCaService {
        public function rootCert(): string
        {
            return "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n";
        }
    });

    $response = test()->call(
        'POST',
        '/api/workspaces/feature-mail/env?app=billing&instance=development',
        [
            'key' => 'APP_ENV',
            'value' => 'production',
            'apply' => true,
        ],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '10.6.0.120',
        ],
        json_encode([
            'key' => 'APP_ENV',
            'value' => 'production',
            'apply' => true,
        ], JSON_THROW_ON_ERROR),
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.scope', 'workspace')
        ->assertJsonPath('success.data.app', 'billing')
        ->assertJsonPath('success.data.instance', 'development')
        ->assertJsonPath('success.data.workspace', 'feature-mail')
        ->assertJsonPath('success.data.path', '/worktrees/feature-mail/.env')
        ->assertJsonPath('success.data.stored', true)
        ->assertJsonPath('success.data.applied', true)
        ->assertJsonPath('success.data.runtime_restarted', true);

    $envPayloads = array_values(array_filter(
        array_map(
            fn (array $options): ?array => ($options['input'] ?? null) !== null
                ? json_decode((string) $options['input'], associative: true, flags: JSON_THROW_ON_ERROR)
                : null,
            $shell->options,
        ),
        fn (?array $payload): bool => ($payload['path'] ?? null) !== null,
    ));
    $writtenEnvPayload =
        array_values(array_filter(
            $envPayloads,
            static fn (array $payload): bool => ($payload['action'] ?? null) === 'write',
        ))[0] ?? null;

    expect($workspace->fresh()->envVariables()->where('key', 'APP_ENV')->value('value'))
        ->toBe('production')
        ->and($writtenEnvPayload)
        ->toBeArray()
        ->and($writtenEnvPayload['contents'] ?? null)
        ->toContain('APP_ENV=production')
        ->toContain('EXISTING_VALUE=preserved')
        ->toContain('APP_URL=https://feature-mail.billing-development.test')
        ->toContain('DB_HOST=postgres.internal')
        ->not->toContain($databasePassword);

    expect(array_column($envPayloads, 'path'))
        ->toContain('/worktrees/feature-mail/.env')
        ->not->toContain('/home/orbit/apps/billing/.env')
        ->not->toContain('/home/orbit/apps/billing-development/.env')
        ->not->toContain('/worktrees/feature-billing/.env');

    expect($shell->nodeIds)->each->toBe($node->id);
    expect($stagingInstance->fresh()->envVariables()->where('key', 'APP_ENV')->value('value'))
        ->toBe('staging');
});

it('rejects instance-only workspace disambiguation before an unauthorized cross-node write', function (): void {
    $caller = Node::factory()->create([
        'host' => '10.6.0.121',
        'wireguard_address' => '10.6.0.121',
    ]);
    $targetNode = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'target-node',
            'wireguard_address' => '10.44.0.91',
        ]);
    $otherNode = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'other-node',
            'wireguard_address' => '10.44.0.92',
        ]);
    $targetApp = App::factory()->for($targetNode, 'node')->create(['name' => 'billing']);
    $otherApp = App::factory()->for($otherNode, 'node')->create(['name' => 'docs']);
    $targetInstance = AppInstance::factory()->for($targetApp)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $targetNode->id,
            path: '/srv/billing',
            document_root: 'public',
            domain: 'billing.test',
        ),
    ]);
    $otherInstance = AppInstance::factory()->for($otherApp)->create([
        'name' => 'staging',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $otherNode->id,
            path: '/srv/docs',
            document_root: 'public',
            domain: 'docs.test',
        ),
    ]);
    $targetWorkspace = Workspace::factory()
        ->for($targetApp)
        ->for($targetInstance, 'appInstance')
        ->create(['name' => 'shared-name']);
    Workspace::factory()
        ->for($otherApp)
        ->for($otherInstance, 'appInstance')
        ->create(['name' => 'shared-name']);

    $response = test()->call(
        'POST',
        '/api/workspaces/shared-name/env?instance=development',
        [
            'key' => 'OWNER_SCOPE',
            'value' => 'unauthorized',
        ],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '10.6.0.121',
        ],
        json_encode([
            'key' => 'OWNER_SCOPE',
            'value' => 'unauthorized',
        ], JSON_THROW_ON_ERROR),
    );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'app');

    expect($targetWorkspace->fresh()->envVariables)->toBeEmpty();
});

/**
 * @mago-expect lint:file-name
 */
final class WorkspaceEnvControllerRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    /**
     * @var list<int>
     */
    public array $nodeIds = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;
        $this->nodeIds[] = $node->id;

        if (str_contains($script, 'id -u')) {
            return new RemoteShellResult(exitCode: 0, stdout: "1000\n1000\n", stderr: '', durationMs: 1);
        }

        if (str_contains($script, 'env-file.read')) {
            return workspace_env_controller_shell_success(['contents' => 'APP_NAME=Billing'.PHP_EOL]);
        }

        if (str_contains($script, 'env-file.write')) {
            return workspace_env_controller_shell_success(['bytes' => 10]);
        }

        if (str_contains($script, 'app-cache:clear')) {
            return workspace_env_controller_shell_success(['deleted_cache_files' => 1]);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

/**
 * @param  array<string, mixed>  $data
 */
function workspace_env_controller_shell_success(array $data): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode(['success' => ['data' => $data]], JSON_THROW_ON_ERROR)."\n",
        stderr: '',
        durationMs: 1,
    );
}
