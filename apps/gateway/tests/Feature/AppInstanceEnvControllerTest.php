<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    array $permissions = ['app:read', 'app:write', 'database:write'],
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

it('sets lists and renders non-secret app instance env values with database attachments', function (): void {
    $caller = createAppInstanceEnvApiCaller();
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'user' => 'nckrtl',
        ]);
    grantAppInstanceEnvApiAccess($caller, $node);
    $app = App::factory()->for($node, 'node')->create([
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

    $set = appInstanceEnvApiJson('POST', '/api/apps/billing/instances/development/env', [
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
            'app' => 'billing',
            'instance' => 'development',
            'env_prefix' => 'DB',
        ],
        [],
        [],
        ['REMOTE_ADDR' => APP_INSTANCE_ENV_API_CALLER_WG_IP],
    );

    $attach
        ->assertOk()
        ->assertJsonPath('success.data.connection.targets.0.type', 'app_instance')
        ->assertJsonPath('success.data.connection.targets.0.app', 'billing')
        ->assertJsonPath('success.data.connection.targets.0.instance', 'development');

    $list = appInstanceEnvApiJson('GET', '/api/apps/billing/instances/development/env');

    $list
        ->assertOk()
        ->assertJsonPath('success.data.variables.0.key', 'APP_DEBUG')
        ->assertJsonPath('success.data.variables.0.value', 'false');

    $render = appInstanceEnvApiJson('GET', '/api/apps/billing/instances/development/env/render');

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
    $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
    grantAppInstanceEnvApiAccess($caller, $node);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'billing',
        'path' => '/home/orbit/apps/billing',
        'runtime' => 'php',
        'php_version' => '8.5',
    ]);
    AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            path: '/home/orbit/apps/billing-development',
            document_root: $app->document_root,
            domain: 'billing-development.test',
        ),
    ]);

    app()->instance(RemoteShell::class, new AppInstanceEnvControllerRecordingRemoteShell);
    app()->instance(\App\Services\Ca\OrbitCaService::class, new readonly class extends \App\Services\Ca\OrbitCaService {
        public function rootCert(): string
        {
            return "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n";
        }
    });

    $response = appInstanceEnvApiJson(
        'POST',
        '/api/apps/billing/instances/development/env',
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
        ->assertJsonPath('success.data.scope', 'app-instance')
        ->assertJsonPath('success.data.app', 'billing')
        ->assertJsonPath('success.data.instance', 'development')
        ->assertJsonPath('success.data.workspace', null)
        ->assertJsonPath('success.data.path', '/home/orbit/apps/billing-development/.env')
        ->assertJsonPath('success.data.stored', true)
        ->assertJsonPath('success.data.applied', true)
        ->assertJsonPath('success.data.runtime_restarted', true)
        ->assertJsonPath('success.data.apply.env_path', '/home/orbit/apps/billing-development/.env')
        ->assertJsonPath('success.data.apply.cache_cleared', true)
        ->assertJsonPath('success.data.apply.runtime_outcome', 'created');
});

it('rejects secret env writes until secret storage is designed', function (): void {
    $caller = createAppInstanceEnvApiCaller();
    $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
    grantAppInstanceEnvApiAccess($caller, $node);
    $app = App::factory()->for($node, 'node')->create(['name' => 'billing']);
    AppInstance::factory()->for($app)->create(['name' => 'development']);

    $response = appInstanceEnvApiJson('POST', '/api/apps/billing/instances/development/env', [
        'key' => 'API_TOKEN',
        'value' => 'secret',
        'secret' => true,
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'secret');
});

final class AppInstanceEnvControllerRecordingRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (str_contains($script, 'id -u')) {
            return new RemoteShellResult(exitCode: 0, stdout: "1000\n1000\n", stderr: '', durationMs: 1);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
