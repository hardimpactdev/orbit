<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Project;
use App\Services\Ca\OrbitCaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const APP_INSTANCE_ENV_API_CALLER_WG_IP = '10.6.0.118';

function createAppInstanceEnvApiCaller(): Node
{
    return Node::factory()->create([
        'name' => 'instance-env-caller',
        'host' => APP_INSTANCE_ENV_API_CALLER_WG_IP,
        'wireguard_address' => APP_INSTANCE_ENV_API_CALLER_WG_IP,
    ]);
}

/**
 * @param  list<string>  $permissions
 */
function grantAppInstanceEnvApiAccess(
    Node $caller,
    Node $serving,
    array $permissions = ['instance:read', 'instance:write', 'database:write'],
): void {
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $serving->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
/**
 * @param  array<string, string>  $server
 */
function appInstanceEnvApiJson(string $method, string $uri, array $data = [], array $server = []): TestResponse
{
    return test()->call(
        $method,
        $uri,
        $data,
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_INSTANCE_ENV_API_CALLER_WG_IP,
        ] + $server,
        $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR),
    );
}

it('authorizes app instance env against the selected instance node', function (): void {
    $caller = createAppInstanceEnvApiCaller();
    $logicalNode = Node::factory()->appDev()->create(['name' => 'logical-app-node']);
    $instanceNode = Node::factory()->appDev()->create(['name' => 'instance-node']);
    $app = Project::factory()->for($logicalNode, 'node')->create(['name' => 'billing']);
    AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $instanceNode->id,
            path: '/srv/billing-development',
            document_root: 'public',
            domain: 'billing-development.test',
        ),
    ]);
    grantAppInstanceEnvApiAccess($caller, $logicalNode, ['instance:read']);

    appInstanceEnvApiJson('GET', '/api/projects/billing/instances/development/env')
        ->assertForbidden();

    grantAppInstanceEnvApiAccess($caller, $instanceNode, ['instance:read']);

    appInstanceEnvApiJson('GET', '/api/projects/billing/instances/development/env')
        ->assertOk()
        ->assertJsonPath('success.data.project', 'billing')
        ->assertJsonPath('success.data.instance', 'development');
});

it('authorizes hostname app selectors against the selected instance node', function (): void {
    $caller = createAppInstanceEnvApiCaller();
    $logicalNode = Node::factory()->appDev()->create(['name' => 'hostname-logical-node']);
    $instanceNode = Node::factory()->appDev()->create(['name' => 'hostname-instance-node']);
    $app = Project::factory()->for($logicalNode, 'node')->create([
        'name' => 'billing',
        'domain' => 'billing.test',
    ]);
    AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $instanceNode->id,
            path: '/srv/billing-development',
            document_root: 'public',
            domain: 'billing-development.test',
        ),
    ]);
    grantAppInstanceEnvApiAccess($caller, $logicalNode, ['instance:read']);

    appInstanceEnvApiJson('GET', '/api/projects/billing.test/instances/development/env')
        ->assertForbidden();

    grantAppInstanceEnvApiAccess($caller, $instanceNode, ['instance:read']);

    appInstanceEnvApiJson('GET', '/api/projects/billing.test/instances/development/env')
        ->assertOk()
        ->assertJsonPath('success.data.project', 'billing')
        ->assertJsonPath('success.data.instance', 'development');
});

it('sets lists and renders non-secret app instance env values with database attachments', function (): void {
    $caller = createAppInstanceEnvApiCaller();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'user' => 'nckrtl',
        ]);
    grantAppInstanceEnvApiAccess($caller, $node);
    $app = Project::factory()->for($node, 'node')->create([
        'name' => 'billing',
        'domain' => 'craft-starterkit-react.test',
    ]);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            path: $app->path,
            document_root: $app->document_root,
            domain: $app->domain,
        ),
    ]);
    $connection = DatabaseConnection::factory()->for($node)->create([
        'slug' => 'billing-db',
        'driver' => 'pgsql',
        'host' => 'postgres.internal',
        'port' => 5432,
        'database' => 'billing',
        'username' => 'billing',
        'credentials' => ['password' => 'secret-password'],
    ]);

    $set = appInstanceEnvApiJson('POST', '/api/projects/billing/instances/development/env', [
        'key' => 'APP_DEBUG',
        'value' => 'false',
    ]);

    $set
        ->assertOk()
        ->assertJsonPath('success.data.variable.key', 'APP_DEBUG')
        ->assertJsonPath('success.data.variable.value', 'false');

    $attach = $this->call(
        'POST',
        '/api/database-connections/billing-db/targets',
        [
            'instance' => 'billing.development',
            'env_prefix' => 'DB',
        ],
        [],
        [],
        ['REMOTE_ADDR' => APP_INSTANCE_ENV_API_CALLER_WG_IP],
    );

    $attach
        ->assertOk()
        ->assertJsonPath('success.data.connection.targets.0.type', 'instance')
        ->assertJsonPath('success.data.connection.targets.0.project', 'billing')
        ->assertJsonPath('success.data.connection.targets.0.instance', 'development');

    $list = appInstanceEnvApiJson('GET', '/api/projects/billing/instances/development/env');

    $list
        ->assertOk()
        ->assertJsonPath('success.data.variables.0.key', 'APP_DEBUG')
        ->assertJsonPath('success.data.variables.0.value', 'false');

    $render = appInstanceEnvApiJson('GET', '/api/projects/billing/instances/development/env/render');

    $render
        ->assertOk()
        ->assertJsonPath('success.data.variables.APP_URL.value', 'https://craft-starterkit-react.test')
        ->assertJsonPath('success.data.variables.APP_DEBUG.value', 'false')
        ->assertJsonPath('success.data.variables.DB_CONNECTION.value', 'pgsql')
        ->assertJsonPath('success.data.variables.DB_PASSWORD.secret', true)
        ->assertJsonPath('success.data.variables.VITE_APP_URL.value', 'https://craft-starterkit-react.test')
        ->assertJsonPath(
            'success.data.variables.VITE_DEV_SERVER_KEY.value',
            '/home/nckrtl/.config/orbit/certs/craft-starterkit-react.test.key',
        )
        ->assertJsonPath(
            'success.data.variables.VITE_DEV_SERVER_CERT.value',
            '/home/nckrtl/.config/orbit/certs/craft-starterkit-react.test.crt',
        )
        ->assertJsonPath('success.data.variables.VITE_VALET_HOST.value', 'craft-starterkit-react.test');

    expect($render->getContent())
        ->not
        ->toContain('secret-password')
        ->and($connection->fresh()->targets()->where('app_instance_id', $instance->id)->exists())
        ->toBeTrue();
});

it('applies set env values to the remote app runtime when apply is requested', function (): void {
    $caller = createAppInstanceEnvApiCaller();
    $projectNode = Node::factory()
        ->appDev()
        ->create([
            'name' => 'project-app-dev',
            'user' => 'project-runtime',
        ]);
    $instanceNode = Node::factory()
        ->appDev()
        ->create([
            'name' => 'instance-app-dev',
            'user' => 'instance-runtime',
        ]);
    grantAppInstanceEnvApiAccess($caller, $instanceNode);
    $app = Project::factory()->for($projectNode, 'node')->create([
        'name' => 'billing',
        'path' => '/home/orbit/apps/billing',
        'domain' => 'billing-project.test',
        'runtime' => 'php',
        'php_version' => '8.5',
    ]);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $instanceNode->id,
            path: '/home/orbit/apps/billing-development',
            document_root: $app->document_root,
            domain: 'billing-development.test',
        ),
    ]);
    $databaseCredential = Str::random(24);
    $connection = DatabaseConnection::factory()->for($instanceNode)->create([
        'slug' => 'billing-db',
        'driver' => 'pgsql',
        'host' => 'postgres.internal',
        'database' => 'billing',
        'username' => 'billing',
        'credentials' => ['password' => $databaseCredential],
    ]);
    DatabaseConnectionTarget::factory()
        ->for($connection, 'connection')
        ->forAppInstance($instance)
        ->create();

    $shell = new AppInstanceEnvControllerRecordingRemoteShell;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(OrbitCaService::class, new readonly class extends OrbitCaService {
        public function rootCert(): string
        {
            return "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n";
        }
    });

    appInstanceEnvApiJson(
        'POST',
        '/api/projects/billing/instances/development/env',
        [
            'key' => 'APP_NAME',
            'value' => 'Billing',
        ],
    )->assertOk();

    $response = appInstanceEnvApiJson(
        'POST',
        '/api/projects/billing/instances/development/env',
        [
            'key' => 'MAIL_MAILER',
            'value' => 'smtp',
            'apply' => true,
        ],
        [],
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.variable.key', 'MAIL_MAILER')
        ->assertJsonPath('success.data.variable.value', 'smtp')
        ->assertJsonPath('success.data.scope', 'instance')
        ->assertJsonPath('success.data.project', 'billing')
        ->assertJsonPath('success.data.instance', 'development')
        ->assertJsonPath('success.data.workspace', null)
        ->assertJsonPath('success.data.path', '/home/orbit/apps/billing-development/.env')
        ->assertJsonPath('success.data.stored', true)
        ->assertJsonPath('success.data.applied', true)
        ->assertJsonPath('success.data.runtime_restarted', true)
        ->assertJsonPath('success.data.apply.env_path', '/home/orbit/apps/billing-development/.env')
        ->assertJsonPath('success.data.apply.cache_cleared', true)
        ->assertJsonPath('success.data.apply.runtime_outcome', 'created');

    $writePayload = collect($shell->options)
        ->pluck('input')
        ->filter()
        ->map(fn (string $input): array => json_decode($input, associative: true, flags: JSON_THROW_ON_ERROR))
        ->firstWhere('action', 'write');

    expect($writePayload)->toBeArray();

    $contents = is_array($writePayload) ? $writePayload['contents'] ?? null : null;

    expect($contents)
        ->toBeString()
        ->toContain('APP_NAME=Billing')
        ->toContain('MAIL_MAILER=smtp')
        ->toContain("DB_PASSWORD={$databaseCredential}")
        ->toContain('APP_URL=https://billing-development.test')
        ->toContain('VITE_APP_URL=https://billing-development.test')
        ->toContain('VITE_VALET_HOST=billing-development.test')
        ->toContain(
            'VITE_DEV_SERVER_KEY=/home/instance-runtime/.config/orbit/certs/billing-development.test.key',
        )
        ->toContain(
            'VITE_DEV_SERVER_CERT=/home/instance-runtime/.config/orbit/certs/billing-development.test.crt',
        );

    expect($contents)
        ->not->toContain('billing-project.test')
        ->not->toContain('/home/project-runtime/.config/orbit/certs/');

    expect($response->getContent())->not->toContain($databaseCredential);
});

it('rejects secret env writes until secret storage is designed', function (): void {
    $caller = createAppInstanceEnvApiCaller();
    $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
    grantAppInstanceEnvApiAccess($caller, $node);
    $app = Project::factory()->for($node, 'node')->create(['name' => 'billing']);
    AppInstance::factory()->for($app)->create(['name' => 'development']);

    $response = appInstanceEnvApiJson(
        method: 'POST',
        uri: '/api/projects/billing/instances/development/env',
        data: [
            'key' => 'API_TOKEN',
            'value' => 'secret',
            'secret' => true,
        ],
    );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'secret');
});

final class AppInstanceEnvControllerRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->options[] = $options;

        if (str_contains($script, 'id -u')) {
            return new RemoteShellResult(exitCode: 0, stdout: "1000\n1000\n", stderr: '', durationMs: 1);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
