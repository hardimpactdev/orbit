<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceEnvironmentSyncAction;
use App\Actions\Instances\UpdateInstanceEnvironmentAction;
use App\Domain\Instances\Environment\ContainerConnectionEnvironment;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketAppEnvProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Models\WebSocketApp;
use App\Models\WebSocketAppTarget;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

const CONNECTION_ENV_SECRET = 'connection-env-password-sentinel';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

/** A running container default with one database recorded by `database:create --instance`, as Instance 24 has. */
function connectionEnvFixture(bool $attach = true): Instance
{
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $instance = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => '.', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => false]);
    app(InstanceContainer::class)->reserve($instance, $node)->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
    if ($attach) {
        $server = DatabaseServer::query()->create(['slug' => 'beast-mysql', 'driver' => 'mysql', 'node_id' => $node->id, 'tag' => '8.4',
            'port' => 3306, 'root_password' => 'root-sentinel', 'status' => 'active']);
        $connection = DatabaseConnection::query()->forceCreate(['slug' => 'servauto', 'driver' => 'mysql', 'node_id' => $node->id,
            'database_server_id' => $server->id, 'owner_instance_id' => $instance->id, 'host' => $node->wireguard_ip, 'port' => 3306,
            'database' => 'servauto', 'test_database' => 'servauto_test', 'username' => 'servauto_app_default', 'password' => CONNECTION_ENV_SECRET]);
        DatabaseConnectionTarget::query()->create(['instance_id' => $instance->id, 'database_connection_id' => $connection->id,
            'prefix' => 'DB', 'network' => 'wireguard']);
    }

    return $instance->refresh();
}

/** A Swamp vault behind the WebSocket that keeps each put. */
function connectionEnvVault(): object
{
    $socket = new class implements WebSocketClient
    {
        /** @var list<array{key: string, value: string}> */
        public array $puts = [];

        /** @var list<array<string, mixed>> */
        private array $frames = [];

        private bool $open = false;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);
            $this->puts[] = ['key' => $frame['payload']['key'], 'value' => (string) ($frame['payload']['value'] ?? '')];

            return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    return $socket;
}

it('starts the container Operation from env:sync with key names, the attached paths and the running units only', function (): void {
    $instance = connectionEnvFixture();
    $running = $instance->processes()->create(['name' => 'queue', 'runtime' => 'systemd', 'runtime_config' => ['command' => ['node', 'worker.js']],
        'status' => 'active', 'desired_state' => 'running', 'working_directory' => '/srv/workspace']);
    $stopped = $instance->processes()->create(['name' => 'idle', 'runtime' => 'systemd', 'runtime_config' => ['command' => ['node', 'idle.js']],
        'status' => 'active', 'desired_state' => 'stopped', 'working_directory' => '/srv/workspace']);
    $instance->node->accessibleNodes()->syncWithoutDetaching([$instance->node_id]);

    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip])
        ->postJson('/api/v1/instances/'.$instance->id.'/environment/sync', ['force' => false])
        ->assertAccepted()->assertJsonPath('data.kind', ContainerConnectionEnvironment::Kind);

    $operation = Operation::query()->where('kind', ContainerConnectionEnvironment::Kind)->sole();
    $plan = $operation->input['connection_environment'];
    $full = app(InstanceContainer::class)->intent($instance, $instance->runtime()->sole());
    expect(Operation::query()->where('kind', 'instance.sync_environment')->exists())->toBeFalse()
        ->and($operation->lock_key)->toBe(OperationStore::lockKey('instance', $instance->id))
        ->and($plan['keys'])->toBe(['DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PASSWORD', 'DB_PORT', 'DB_USERNAME'])
        ->and($plan['egress'])->toBe([['action' => 'allow', 'destination' => '10.44.0.7/32', 'protocol' => 'tcp', 'destination_port' => '3306', 'state' => 'enabled']])
        ->and(array_intersect(array_map('json_encode', $full['acl_rules']['egress']), array_map('json_encode', $plan['egress'])))->toHaveCount(1)
        ->and($plan['firewall'])->toBe([$full['firewall'][0]])
        ->and($plan['bridge_endpoints'])->toBe([['host' => '10.44.0.7', 'port' => 3306, 'docker' => false]])
        ->and($plan['refresh']['units'])->toContain(app(SystemdProcessRenderer::class)->unitName($running))
        ->and($plan['refresh']['units'])->not->toContain(app(SystemdProcessRenderer::class)->unitName($stopped))
        ->and($operation->input['container']['environment_file'])->toBe('/srv/workspace/.env')
        ->and(json_encode($operation->input))->not->toContain(CONNECTION_ENV_SECRET)->not->toContain('servauto_app_default');
});

it('hands the rendered lines to the run only through one vault copy', function (): void {
    $instance = connectionEnvFixture();
    $vault = connectionEnvVault();
    $operation = app(ContainerConnectionEnvironment::class)->start($instance, null, null);
    $control = containerDefaultNode('control', '10.44.0.209', false);
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);

    $input = app(ContainerConnectionEnvironment::class)->input($operation);
    $put = collect($vault->puts)->sole();
    $lines = json_decode($put['value'], true, 512, JSON_THROW_ON_ERROR)['lines'];
    expect($put['key'])->toBe('instance-environment-'.$operation->id)
        ->and($lines)->toBe(['DB_CONNECTION' => 'DB_CONNECTION="mysql"', 'DB_DATABASE' => 'DB_DATABASE="servauto"', 'DB_HOST' => 'DB_HOST="10.44.0.7"',
            'DB_PASSWORD' => 'DB_PASSWORD="'.CONNECTION_ENV_SECRET.'"', 'DB_PORT' => 'DB_PORT="3306"', 'DB_USERNAME' => 'DB_USERNAME="servauto_app_default"'])
        ->and($input['execution_secrets'])->toBe(['connection_environment' => ['vault' => 'orbit-control-inputs', 'key' => 'instance-environment-'.$operation->id]])
        ->and(json_encode($input['intent']))->not->toContain(CONNECTION_ENV_SECRET)
        ->and(app(DirectOperation::class)->definition(ContainerConnectionEnvironment::Kind)['workflow'])->toBe('operation-instance-connection-environment');
});

it('refuses a changed set of connections at the run start instead of writing other keys', function (): void {
    $instance = connectionEnvFixture();
    connectionEnvVault();
    $operation = app(ContainerConnectionEnvironment::class)->start($instance, null, null);
    $redis = DatabaseConnection::query()->forceCreate(['slug' => 'cache', 'driver' => 'redis', 'node_id' => $instance->node_id,
        'host' => '10.44.0.7', 'port' => 6379, 'database' => '1']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $instance->id, 'database_connection_id' => $redis->id, 'prefix' => 'REDIS', 'network' => 'wireguard']);

    expect(fn () => app(ContainerConnectionEnvironment::class)->input($operation))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('env.connection_changed'));
});

it('refuses a stopped container and busy lifecycle work before any Operation', function (string $case, string $code): void {
    $instance = connectionEnvFixture();
    match ($case) {
        'stopped' => $instance->runtime()->update(['power_state' => 'stopped']),
        'lifecycle' => $instance->update(['status' => 'checkout_prepared']),
        default => null,
    };
    expect(fn () => app(StartInstanceEnvironmentSyncAction::class)->execute($instance))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe($code));
    expect(Operation::query()->count())->toBe(0);
})->with([['stopped', 'process.target_inactive'], ['lifecycle', 'instance.lifecycle_busy']]);

it('writes an attached private websocket app\'s keys and reconciles its exact path, and a run with no key withdraws a removed app\'s path', function (): void {
    $instance = connectionEnvFixture(false);
    $services = containerDefaultNode('Services', '10.44.0.3', false);
    $services->roles()->create(['role' => RoleName::WebSocket, 'status' => 'active']);
    $app = WebSocketApp::query()->create(['name' => 'tool', 'app_id' => '1001', 'key' => 'reverb-key', 'secret' => CONNECTION_ENV_SECRET, 'credential_generation' => 1]);
    $target = WebSocketAppTarget::query()->create(['websocket_app_id' => $app->id, 'instance_id' => $instance->id]);
    // The attach stores the projected keys; env:sync reads them from the store.
    app(InstanceEnvironmentStore::class)->seedForCreate($instance, app(WebSocketAppEnvProjection::class)->project($app, $target));
    $vault = connectionEnvVault();

    $operation = app(ContainerConnectionEnvironment::class)->start($instance, null, null);

    $plan = $operation->input['connection_environment'];
    expect($plan['keys'])->toBe(['BROADCAST_CONNECTION', 'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME'])
        ->and($plan['websocket'])->toBe([
            'egress' => ['action' => 'allow', 'destination' => '10.44.0.3/32', 'protocol' => 'tcp', 'destination_port' => '8443', 'state' => 'enabled',
                'description' => 'Orbit websocket app'],
            'bridge_endpoint' => ['host' => '10.44.0.3', 'port' => 8443, 'docker' => false],
            'firewall' => ['rule' => 'allow', 'interface_in' => 'orbitinst0', 'from_ip' => '10.253.0.2', 'to_ip' => '10.44.0.3', 'to_port' => '8443',
                'proto' => 'tcp', 'comment' => "orbit:instance-{$instance->id}-websocket", 'route' => 'yes', 'interface_out' => 'orbit']])
        ->and($plan['websocket_comment'])->toBe("orbit:instance-{$instance->id}-websocket")
        ->and($plan['private_names'])->toBe([['name' => 'reverb.orbit', 'host' => '10.44.0.3', 'port' => 8443]])
        ->and(explode(',', $plan['proxy_environment']['NO_PROXY']))->toContain('reverb.orbit')
        // Database paths stay add-only and separate.
        ->and($plan['egress'])->toBe([])->and($plan['firewall'])->toBe([])
        ->and(json_encode($operation->input))->not->toContain(CONNECTION_ENV_SECRET);
    containerDefaultNode('control', '10.44.0.209', false)->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    app(ContainerConnectionEnvironment::class)->input($operation);
    $lines = json_decode(collect($vault->puts)->firstWhere('key', 'instance-environment-'.$operation->id)['value'], true, 512, JSON_THROW_ON_ERROR)['lines'];
    expect($lines['REVERB_HOST'])->toBe('REVERB_HOST="reverb.orbit"')->and($lines['REVERB_PORT'])->toBe('REVERB_PORT="8443"');

    // The app is removed: the next sync has no key to write, still runs, and its plan has no websocket path, so the run withdraws it.
    $operation->forceFill(['status' => 'succeeded', 'lock_key' => null])->save();
    $target->delete();
    $removed = app(ContainerConnectionEnvironment::class)->start($instance->refresh(), null, null);
    expect($removed->input['connection_environment']['keys'])->toBe([])
        ->and($removed->input['connection_environment']['websocket'])->toBeNull()
        ->and($removed->input['connection_environment']['private_names'])->toBe([])
        ->and($removed->input['connection_environment']['proxy_environment'])->toBeNull()
        ->and($removed->input['connection_environment']['websocket_comment'])->toBe("orbit:instance-{$instance->id}-websocket");
});

it('keeps the host Instance on the full render path', function (): void {
    $instance = connectionEnvFixture();
    $instance->runtime()->delete();
    expect(fn () => app(StartInstanceEnvironmentSyncAction::class)->execute($instance->refresh()))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->not->toBe('env.connection_missing'));
    expect(Operation::query()->where('kind', ContainerConnectionEnvironment::Kind)->exists())->toBeFalse();
});

it('projects the key names of the result and records the written keys as Orbit-owned', function (): void {
    $instance = connectionEnvFixture();
    $instance->forceFill(['environment_owned_keys' => ['APP_KEY']])->save();
    $operation = app(ContainerConnectionEnvironment::class)->start($instance, null, null);
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'connection-env-proof']])->save();
    $result = ['environment' => ['status' => 'changed', 'written' => ['DB_HOST', 'DB_PORT'], 'added' => ['DB_PASSWORD'], 'kept' => ['DB_USERNAME'], 'unchanged' => ['DB_CONNECTION']],
        'network' => ['egress_added' => 1, 'firewall' => 1], 'restarted' => ['orbit-process-1.service']];

    $projected = app(ContainerConnectionEnvironment::class)->apply($operation, ['status' => 'succeeded', 'run_id' => 'connection-env-proof',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => $result]);

    expect($projected->result)->toBe($result)
        ->and($instance->refresh()->environment_owned_keys)->toBe(['APP_KEY', 'DB_CONNECTION', 'DB_HOST', 'DB_PASSWORD', 'DB_PORT']);
});

it('records nothing as owned after a failed write', function (): void {
    $instance = connectionEnvFixture();
    $operation = app(ContainerConnectionEnvironment::class)->start($instance, null, null);
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'connection-env-fail']])->save();

    $projected = app(ContainerConnectionEnvironment::class)->apply($operation, ['status' => 'failed', 'run_id' => 'connection-env-fail',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['error_code' => 'env.configuration_invalid', 'error_detail' => 'line 4']]);

    expect($projected->error_code)->toBe('env.configuration_invalid')
        ->and($instance->refresh()->environment_owned_keys)->toBeNull();
});

/** The connection fixture with its Route, so env:update and placeholders resolve. */
function pendingKeysFixture(): Instance
{
    $instance = connectionEnvFixture();
    $instance->forceFill(['provisioning_step' => 'active'])->save();
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'default.tool.test', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    app(InstanceEnvironmentStore::class)->seedForCreate($instance, ['APP_NAME' => 'Tool', 'VITE_REVERB_HOST' => 'old.example']);

    return $instance->refresh();
}

/** Run one sync to its end: admission, the vault copy of the lines, and the projection of the given outcome. */
function pendingKeysSync(Instance $instance, string $status = 'succeeded'): array
{
    $vault = connectionEnvVault();
    if (! Node::query()->where('name', 'control')->exists()) {
        containerDefaultNode('control', '10.44.0.209', false)->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    }
    $operation = app(ContainerConnectionEnvironment::class)->start($instance->refresh(), null, null);
    app(ContainerConnectionEnvironment::class)->input($operation);
    $lines = json_decode(collect($vault->puts)->firstWhere('key', 'instance-environment-'.$operation->id)['value'], true, 512, JSON_THROW_ON_ERROR)['lines'];
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'pending-'.$operation->id]])->save();
    $keys = $operation->input['connection_environment']['keys'];
    app(ContainerConnectionEnvironment::class)->apply($operation, ['status' => $status, 'run_id' => 'pending-'.$operation->id,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => $status === 'succeeded' ? ['environment' => ['status' => 'changed', 'written' => $keys, 'added' => [], 'kept' => [], 'unchanged' => []]]
            : ['error_code' => 'env.configuration_invalid']]);
    $operation->forceFill(['lock_key' => null])->save();

    return ['keys' => $keys, 'lines' => $lines];
}

it('marks a key env:update stores on a container Instance for the next sync, also when the stored value was the same', function (): void {
    $instance = pendingKeysFixture();

    $same = app(UpdateInstanceEnvironmentAction::class)->execute($instance, 'VITE_REVERB_HOST', 'old.example');
    app(UpdateInstanceEnvironmentAction::class)->execute($instance, 'APP_NAME', 'Tool 2');

    expect($same->changed)->toBeFalse()
        ->and($instance->refresh()->environment_pending_keys)->toBe(['APP_NAME', 'VITE_REVERB_HOST']);
});

it('marks nothing on a host Instance, whose sync renders the whole file', function (): void {
    $instance = pendingKeysFixture();
    $instance->runtime()->delete();

    app(UpdateInstanceEnvironmentAction::class)->execute($instance->refresh(), 'APP_NAME', 'Tool 2');

    expect($instance->refresh()->environment_pending_keys)->toBeNull();
});

it('writes a pending key once, rendered with its placeholder, lets a connection line win, and leaves a later hand edit alone', function (): void {
    $instance = pendingKeysFixture();
    app(UpdateInstanceEnvironmentAction::class)->execute($instance, 'VITE_REVERB_HOST', 'reverb.{{instance.domain}}');
    // A pending key of a connection's name keeps the connection's line.
    app(UpdateInstanceEnvironmentAction::class)->execute($instance, 'DB_HOST', 'hand.example');

    $first = pendingKeysSync($instance);

    expect($first['keys'])->toContain('VITE_REVERB_HOST')->toContain('DB_HOST')
        ->and($first['lines']['VITE_REVERB_HOST'])->toBe('VITE_REVERB_HOST="reverb.default.tool.test"')
        ->and($first['lines']['DB_HOST'])->toBe('DB_HOST="10.44.0.7"')
        ->and($first['lines'])->not->toHaveKey('APP_NAME')
        ->and($instance->refresh()->environment_pending_keys)->toBeNull();

    // Someone edits the line by hand after that sync: the next sync names no such key, so the writer leaves the line as it is.
    $second = pendingKeysSync($instance);
    expect($second['keys'])->toBe(['DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PASSWORD', 'DB_PORT', 'DB_USERNAME']);
});

it('keeps the pending keys after a failed sync and drops a key whose stored value is gone', function (): void {
    $instance = pendingKeysFixture();
    app(UpdateInstanceEnvironmentAction::class)->execute($instance, 'VITE_REVERB_HOST', 'new.example');
    $instance->refresh()->forceFill(['environment_pending_keys' => ['GONE_KEY', 'VITE_REVERB_HOST']])->save();

    $failed = pendingKeysSync($instance, 'failed');

    expect($failed['keys'])->toContain('VITE_REVERB_HOST')->not->toContain('GONE_KEY')
        ->and($instance->refresh()->environment_pending_keys)->toBe(['GONE_KEY', 'VITE_REVERB_HOST']);

    pendingKeysSync($instance);
    expect($instance->refresh()->environment_pending_keys)->toBe(['GONE_KEY']);
});
