<?php

declare(strict_types=1);

use App\Actions\Hibernation\ActivateInstanceRuntimeAction;
use App\Actions\Instances\StartInstanceDeployAction;
use App\Actions\Instances\StartInstanceMigrateAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Data\Instances\InstanceData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\InstanceMigration\InstanceMigrateProjection;
use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\InstanceMigration\InstanceMigrationRoute;
use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlanner;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    app(ExtensionStore::class)->set('conn', true);
    app()->instance(TaskVmModelRoute::class, new class implements TaskVmModelRoute
    {
        public function assertReady(): void {}

        public function origin(): string
        {
            return 'http://10.44.0.3:8318';
        }

        public function key(): string
        {
            return 'placeholder-model-key';
        }
    });
    // A Swamp that answers: the start of a run is then not refused, and its reserved row stays, as during a run.
    migrateVault();
});

/** An active checkout Instance, as `instance:create` left it before containers: its checkout under the Node's apps path. */
function migrateCheckout(Project $project, Node $node, string $name = 'default'): Instance
{
    return Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name,
        'checkout_path' => "/home/orbit/apps/{$project->slug}/{$name}", 'branch' => $name === 'default' ? 'main' : $name,
        'status' => InstanceState::Active, 'first_setup_pending' => false]);
}

/** The admitted move; the test has no Swamp to start its run, so the start fails after admission recorded everything. */
function migrateStart(Instance $instance): Operation
{
    try {
        app(StartInstanceMigrateAction::class)->execute($instance, null, null);
    } catch (ResourceOperationException $exception) {
        // The test's Swamp answers the start with a reply the Gateway cannot read.
        if (! str_starts_with($exception->errorCode, 'swamp.')) {
            throw $exception;
        }
    }

    return Operation::query()->where('kind', 'instance.migrate')->where('target_id', $instance->id)->latest('id')->firstOrFail();
}

/**
 * The end of a move's run, projected.
 *
 * @param  array<string, mixed>  $result
 */
function migrateEnd(Operation $operation, string $status, array $result): Operation
{
    $operation->forceFill(['status' => 'running', 'finished_at' => null, 'error_code' => null, 'result' => null, 'state' => ['swamp_run_id' => 'run-1']])->save();

    return app(InstanceMigrateProjection::class)->apply($operation->refresh(), ['status' => $status, 'run_id' => 'run-1',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'error_code' => $result['error_code'] ?? null, 'result' => ['instance_id' => $operation->target_id, ...$result]]);
}

/** A default that has moved: its move admitted and ended with the Route on the container. */
function migrateMovedDefault(Project $project, Node $node): Instance
{
    $default = migrateCheckout($project, $node);
    migrateEnd(migrateStart($default), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);

    return $default->refresh();
}

function migrateDatabaseServer(Node $node, string $slug, DatabaseDriver $driver, int $port): DatabaseServer
{
    $process = Process::query()->forceCreate(['owner_type' => 'node', 'owner_id' => $node->id, 'name' => 'db-'.$slug, 'runtime' => 'docker',
        'runtime_config' => ['image' => 'postgres:18', 'ports' => [$node->wireguard_ip.':'.$port.':5432']], 'status' => 'active']);

    return DatabaseServer::query()->forceCreate(['slug' => $slug, 'driver' => $driver, 'node_id' => $node->id, 'process_id' => $process->id, 'tag' => '18', 'port' => $port,
        'status' => 'active', 'root_password' => 'root-secret']);
}

/** The Instance's database on a server, attached under `$prefix`, as `database:create --instance` records it. */
function migrateServerDatabase(Instance $instance, DatabaseServer $server, string $prefix = 'DB'): DatabaseConnection
{
    $connection = DatabaseConnection::query()->forceCreate(['slug' => $instance->project->slug.'-'.$instance->name.'-'.strtolower($prefix), 'driver' => $server->driver->value,
        'node_id' => $server->node_id, 'database_server_id' => $server->id, 'owner_instance_id' => $instance->id, 'host' => $server->node->wireguard_ip,
        'port' => $server->port, 'database' => 'tool_'.$instance->name, 'username' => 'tool_'.$instance->name, 'password' => 'secret']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $instance->id, 'prefix' => $prefix, 'database_connection_id' => $connection->id, 'network' => 'wireguard']);

    return $connection;
}

/**
 * A Swamp vault behind the WebSocket: it keeps what the Gateway puts and answers reads of `$secrets`.
 *
 * @param  array<string, string>  $secrets
 */
function migrateVault(array $secrets = []): object
{
    $socket = new class($secrets) implements WebSocketClient
    {
        /** @var list<array{key: string, value: string}> */
        public array $puts = [];

        /** @var list<array<string, mixed>> */
        private array $frames = [];

        /** @param array<string, string> $secrets */
        public function __construct(private array $secrets) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);
            if ($frame['type'] === 'vault.read-secret') {
                return [['id' => $frame['id'], 'type' => 'vault.read-secret', 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'],
                    'secretKey' => $frame['payload']['secretKey'], 'vaultType' => 'local_encryption', 'value' => $this->secrets[$frame['payload']['secretKey']] ?? '']]]];
            }
            $this->puts[] = ['key' => $frame['payload']['key'], 'value' => (string) ($frame['payload']['value'] ?? '')];

            return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    return $socket;
}

describe('the admission of a move', function (): void {
    it('reserves a default\'s runtime with no T3 port, and fetches the branch from the checkout itself', function (): void {
        $node = containerDefaultNode();
        $default = migrateCheckout(containerDefaultProject(), $node);

        $operation = migrateStart($default);

        $runtime = InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail();
        $container = ValidatedData::object($operation->input['container']);
        expect($runtime->only(['bridge_address', 't3_port', 'clone_source_instance_id', 'power_state']))
            ->toBe(['bridge_address' => '10.253.0.2', 't3_port' => null, 'clone_source_instance_id' => null, 'power_state' => InstanceRuntimePowerState::Stopped])
            // The checkout stays the Instance's runtime until the run has succeeded.
            ->and($default->refresh()->checkout_path)->toBe('/home/orbit/apps/tool/default')
            ->and($container)->toMatchArray(['name' => 'instance-'.$default->id, 'address' => '10.253.0.2', 'branch' => 'main',
                'repository' => '/home/orbit/apps/tool/default', 'protocols' => ['file'], 'steps' => [], 'clone' => null,
                'mirror' => '/var/lib/orbit/instance-repositories/'.$default->id.'.git'])
            ->and($operation->input['migrate'])->toBe(['checkout' => '/home/orbit/apps/tool/default', 'branch' => 'main', 'default' => true,
                'application' => '/home/orbit/apps/tool/default',
                'awake' => ['directory' => '/dev/shm/orbit/hibernation', 'path' => '/dev/shm/orbit/hibernation/app-instance-'.$default->id.'.awake'],
                'databases' => [], 'processes' => [], 'firewall_prefix' => 'orbit:instance-'.$default->id.'-',
                'retire' => [['path' => '/home/orbit/apps/tool/default', 'mirror' => '/var/lib/orbit/instance-repositories/'.$default->id.'.git']]])
            ->and($operation->node_id)->toBe($node->id);
    });

    it('keeps a default\'s checkout while another checkout Instance of its Project is left on the Node', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $default = migrateCheckout($project, $node);
        migrateCheckout($project, $node, 'feature');

        expect(migrateStart($default)->input['migrate']['retire'])->toBe([]);
    });

    it('refuses what cannot move', function (Closure $arrange, string $code): void {
        $node = containerDefaultNode();
        $instance = $arrange($node);
        $before = Operation::query()->count();

        expect(containerRefusal(fn () => migrateStart($instance)))->toBe($code)
            ->and(Operation::query()->count())->toBe($before);
    })->with([
        'a Node that is not on the build list' => [fn (): Instance => migrateCheckout(containerDefaultProject(), containerDefaultNode('shark', '10.44.0.8', listed: false)), 'instance.migrate_unsupported'],
        'an Instance that already runs in a container' => [fn (Node $node): Instance => migrateMovedDefault(containerDefaultProject(), $node), 'instance.migrate_unsupported'],
        'an Instance that is not active' => [function (Node $node): Instance {
            $instance = migrateCheckout(containerDefaultProject(), $node);
            $instance->update(['status' => InstanceState::SourceResolved]);

            return $instance;
        }, 'instance.migrate_unsupported'],
        'a database server that a container cannot reach by address' => [function (Node $node): Instance {
            $instance = migrateCheckout(containerDefaultProject(), $node);
            migrateServerDatabase($instance, migrateDatabaseServer($node, 'pg', DatabaseDriver::Pgsql, 5432))->forceFill(['host' => 'db.example.test'])->save();

            return $instance;
        }, 'instance.database_clone_unsupported'],
    ]);

    it('refuses while a checkout deployment is unfinished, before reserving a runtime', function (string $status): void {
        $default = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $deploy = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $default->id,
            'node_id' => $default->node_id, 'status' => $status, 'input' => []]);

        expect(containerRefusal(fn () => migrateStart($default)))->toBe('instance.lifecycle_busy')
            ->and($default->runtime()->exists())->toBeFalse()
            ->and(Operation::query()->count())->toBe(1)
            ->and($deploy->refresh()->status->value)->toBe($status);
    })->with(['queued', 'running', 'waiting']);

    it('permits the move after a checkout deployment has finished', function (): void {
        $default = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $default->id,
            'node_id' => $default->node_id, 'status' => 'succeeded', 'input' => []]);

        expect(migrateStart($default)->kind)->toBe('instance.migrate');
    });

    it('refuses a second move of an Instance while its first runs', function (): void {
        $default = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $first = migrateStart($default);
        $first->forceFill(['status' => 'running', 'finished_at' => null])->save();

        expect(containerRefusal(fn () => migrateStart($default)))->toBe('instance.lifecycle_busy')
            ->and(InstanceMigration::running($default))->toBeTrue();
    });
});

describe('the PHP version of a web-serving Project', function (): void {
    /** @param array<string, mixed>|null $composer */
    function migrateSource(?array $composer): void
    {
        app()->instance(ProjectSourceReader::class, new class($composer) implements ProjectSourceReader
        {
            /** @param array<string, mixed>|null $composer */
            public function __construct(private ?array $composer) {}

            public function read(string $repositoryUrl, ProjectSourceAccess $access, string $branch, array $paths): array
            {
                $files = array_fill_keys($paths, null);
                if ($this->composer !== null) {
                    // The application's composer.json and its framework entry point, as regular files.
                    $files = array_map(static fn (): array => ['mode' => '100644', 'object' => str_repeat('a', 40), 'content' => ''], $files);
                    $files['composer.json'] = ['mode' => '100644', 'object' => str_repeat('a', 40), 'content' => json_encode($this->composer, JSON_THROW_ON_ERROR)];
                }

                return ['head' => str_repeat('b', 40), 'files' => $files];
            }
        });
    }

    it('refuses without reading source when the Project has no recorded PHP version', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject(ProjectType::LaravelApp);
        app()->instance(ProjectSourceReader::class, new class implements ProjectSourceReader
        {
            public function read(string $repositoryUrl, ProjectSourceAccess $access, string $branch, array $paths): array
            {
                throw new RuntimeException('Migration must not infer the PHP version.');
            }
        });
        $default = migrateCheckout($project, $node);

        expect(containerRefusal(fn () => migrateStart($default)))->toBe('project.source_defaults_incomplete')
            ->and($project->refresh()->php_version)->toBeNull()
            ->and(InstanceRuntime::query()->count())->toBe(0);
    });

    it('names the command that records the PHP version from source', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject(ProjectType::LaravelApp);
        migrateSource(null);
        $default = migrateCheckout($project, $node);

        expect(fn () => migrateStart($default))->toThrow(function (ResourceOperationException $exception) use ($project): void {
            expect($exception->errorCode)->toBe('project.source_defaults_incomplete')
                ->and($exception->getMessage())->toContain("orbit project:update {$project->id} --source-access=github_app");
        });
        expect(InstanceRuntime::query()->count())->toBe(0);
    });

    it('names the same source refresh command for a gh_cli Project', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject(ProjectType::LaravelApp);
        $project->forceFill(['source_access' => ProjectSourceAccess::GhCli])->save();
        $default = migrateCheckout($project, $node);

        expect(fn () => migrateStart($default))->toThrow(function (ResourceOperationException $exception) use ($project): void {
            expect($exception->errorCode)->toBe('project.source_defaults_incomplete')
                ->and($exception->getMessage())->toContain("orbit project:update {$project->id} --source-access=github_app");
        });
    });
});

describe('the default-first rule', function (): void {
    it('refuses a non-default Instance before its default has moved', function (Closure $arrange): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $arrange($project, $node);
        $feature = migrateCheckout($project, $node, 'feature');

        expect(containerRefusal(fn () => migrateStart($feature)))->toBe('instance.default_not_migrated')
            ->and(InstanceRuntime::query()->where('instance_id', $feature->id)->exists())->toBeFalse();
    })->with([
        'no default' => [fn (): null => null],
        'a checkout default' => [fn (Project $project, Node $node): Instance => migrateCheckout($project, $node)],
        'a default whose own move still runs' => [function (Project $project, Node $node): Operation {
            $operation = migrateStart(migrateCheckout($project, $node));
            $operation->forceFill(['status' => 'running', 'finished_at' => null])->save();

            return $operation;
        }],
    ]);

    it('refuses a non-default move while Conn is disabled before reserving its runtime', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        migrateMovedDefault($project, $node);
        $feature = migrateCheckout($project, $node, 'feature');
        app(ExtensionStore::class)->set('conn', false);

        expect(containerRefusal(fn () => migrateStart($feature)))->toBe('operation.kind_unavailable')
            ->and($feature->runtime()->exists())->toBeFalse();
    });

    it('gives a non-default Instance a T3 port and T3\'s files once its default has moved, with no clone source', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        migrateMovedDefault($project, $node);
        $feature = migrateCheckout($project, $node, 'feature');

        $operation = migrateStart($feature);

        $runtime = InstanceRuntime::query()->where('instance_id', $feature->id)->firstOrFail();
        $clone = ValidatedData::object(ValidatedData::object($operation->input['container'])['clone']);
        expect($runtime->only(['bridge_address', 't3_port', 'clone_source_instance_id']))
            ->toBe(['bridge_address' => '10.253.0.3', 't3_port' => 3800, 'clone_source_instance_id' => null])
            // Nothing is copied from the default: the container comes from the image.
            ->and(array_keys($clone))->toBe(['t3', 'model_environment', 'files', 'links'])
            ->and($clone['t3'])->toMatchArray(['port' => 3800, 'listen' => '10.44.0.7', 'url' => 'http://10.44.0.7:3800', 'label' => 'tool / feature', 'title' => 'tool'])
            ->and($clone['files'][0]['content'])->toContain("ORBIT_INSTANCE=feature\n", "ORBIT_PROJECT_ID={$project->id}\n", "ORBIT_PROJECT_NAME=\"tool\"\n", "ORBIT_NODE=beast\n")
            ->and($operation->input['container'])->toMatchArray(['config' => ['limits.cpu' => '4', 'limits.memory' => '8GiB', 'limits.processes' => '4096'],
                'root_disk_pool' => 'orbit-instances', 'root_disk_size' => '40GiB'])
            ->and(array_column($operation->input['container']['firewall'], 'comment'))->toBe(["orbit:instance-{$feature->id}-t3-task-vms", "orbit:instance-{$feature->id}-t3"])
            ->and($operation->input['container']['branch'])->toBe('feature')
            ->and($operation->input['migrate']['default'])->toBeFalse();
    });

    it('lets a feature that is still a checkout keep its site and its deployments after the default has moved', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        migrateMovedDefault($project, $node);
        $feature = migrateCheckout($project, $node, 'feature');

        expect($feature->runtime()->exists())->toBeFalse()
            ->and(InstanceMigration::running($feature))->toBeFalse();
        InstanceContainer::assertCheckout($feature);
    });

    it('keeps the default repository for a sibling whose failed restore retained a runtime reservation', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $default = migrateCheckout($project, $node);
        $first = migrateCheckout($project, $node, 'first');
        $last = migrateCheckout($project, $node, 'last');
        migrateEnd(migrateStart($default), 'succeeded', ['ready' => true, 'moved' => true, 'commit' => str_repeat('d', 40)]);
        migrateEnd(migrateStart($first), 'failed', ['ready' => false, 'moved' => false, 'restored' => false, 'failed_step' => 'route']);

        $second = migrateStart($last);

        expect($second->input['migrate']['retire'])->toBe([['path' => $last->checkout_path, 'mirror' => InstanceContainer::mirror($last)]]);
        migrateEnd($second, 'succeeded', ['ready' => true, 'moved' => true, 'commit' => str_repeat('e', 40), 't3' => migrateServer($last)]);
        // Once the other sibling has actually moved, retrying the failed move is the last checkout.
        expect(migrateStart($first)->input['migrate']['retire'])->toBe([
            ['path' => $first->checkout_path, 'mirror' => InstanceContainer::mirror($first)],
            ['path' => '/home/orbit/apps/tool/default', 'mirror' => InstanceContainer::mirror($default), 'common_of' => $first->checkout_path],
        ]);
    });

    it('removes the default\'s old checkout with the last checkout Instance of the Project, when the worktree belongs to it', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $default = migrateCheckout($project, $node);
        $feature = migrateCheckout($project, $node, 'feature');
        $other = migrateCheckout($project, $node, 'other');
        migrateEnd(migrateStart($default), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);

        $first = migrateStart($feature);
        migrateEnd($first, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('e', 40), 't3' => migrateServer($feature)]);
        $last = migrateStart($other);

        expect($first->input['migrate']['retire'])->toBe([['path' => '/home/orbit/apps/tool/feature', 'mirror' => InstanceContainer::mirror($feature)]])
            ->and($last->input['migrate']['retire'])->toBe([
                ['path' => '/home/orbit/apps/tool/other', 'mirror' => InstanceContainer::mirror($other)],
                ['path' => '/home/orbit/apps/tool/default', 'mirror' => InstanceContainer::mirror($default), 'common_of' => '/home/orbit/apps/tool/other'],
            ]);
    });
});

/** @return array<string, mixed> */
function migrateServer(Instance $instance): array
{
    migrateVault(['nckrtl-instance-t3-model-register-session-gateway-'.$instance->id.'-token' => 'gateway-session-value']);

    return ['environment_id' => 'env-'.$instance->name, 'server_version' => '0.1.7', 'expires_at' => '2026-12-10T10:00:00.000Z',
        'gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-gateway-'.$instance->id.'-token'],
        'swamp_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-swamp-'.$instance->id.'-token']];
}

describe('the databases of a move', function (): void {
    it('leaves a database on its server and gives the container its way to every server of the Instance', function (): void {
        $node = containerDefaultNode();
        $default = migrateCheckout(containerDefaultProject(), $node);
        $valkey = migrateServerDatabase($default, migrateDatabaseServer($node, 'cache', DatabaseDriver::Redis, 6379), 'REDIS');
        $database = migrateServerDatabase($default, migrateDatabaseServer($node, 'pg', DatabaseDriver::Pgsql, 5432));

        $operation = migrateStart($default);

        $container = ValidatedData::object($operation->input['container']);
        $rule = ['rule' => 'allow', 'interface_in' => 'orbitinst0', 'from_ip' => '10.253.0.2', 'to_ip' => '10.44.0.7', 'proto' => 'tcp'];
        expect($container['acl_rules']['egress'])->toBe([
            ['action' => 'allow', 'destination' => '10.44.0.7/32', 'protocol' => 'tcp', 'destination_port' => '5432', 'state' => 'enabled'],
            ['action' => 'allow', 'destination' => '10.44.0.7/32', 'protocol' => 'tcp', 'destination_port' => '6379', 'state' => 'enabled']])
            ->and($container['firewall'])->toEqual([[...$rule, 'to_port' => '5432', 'comment' => "orbit:instance-{$default->id}-database"],
                [...$rule, 'to_port' => '6379', 'comment' => "orbit:instance-{$default->id}-database-{$valkey->id}"]])
            // The move proves each way from inside the container, `DB` first.
            ->and($operation->input['migrate']['databases'])->toBe([['host' => '10.44.0.7', 'port' => 5432], ['host' => '10.44.0.7', 'port' => 6379]])
            // No second database: the Instance keeps the one it has.
            ->and(DatabaseConnection::query()->where('owner_instance_id', $default->id)->pluck('id')->all())->toEqualCanonicalizing([$valkey->id, $database->id]);
    });

    it('still lets a clone of the moved default copy its database with a template copy on the same server', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $default = migrateCheckout($project, $node);
        $server = migrateDatabaseServer($node, 'pg', DatabaseDriver::Pgsql, 5432);
        $database = migrateServerDatabase($default, $server);
        migrateEnd(migrateStart($default), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);

        $plan = app(InstanceDatabaseClonePlanner::class)->plan($project, 'feature', container: true);

        expect($plan?->server?->id)->toBe($server->id)
            ->and($plan?->connection->id)->toBe($database->id)
            ->and($plan?->source->id)->toBe($default->id);

        // This fixture confirms vault calls, but leaves native run starts unconfirmed.
        try {
            containerCreate($project, $node, 'feature');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('swamp.start_uncertain');
        }
        $clone = Operation::query()->where('kind', 'instance.create')->latest('id')->firstOrFail();
        $input = app(DevelopmentInstanceCreateProjection::class)->input($clone);
        $fill = $input['intent']['server_database']['fill'];

        expect($fill)->toContain('CREATE DATABASE "tool_feature" TEMPLATE "tool_default" OWNER "tool_feature"')
            ->and($fill)->toContain('ALTER DATABASE "tool_default" ALLOW_CONNECTIONS true');
    });

    it('drops the record of a SQLite file that moved with the files, and points the stored .env at the workspace', function (): void {
        $node = containerDefaultNode();
        $default = migrateCheckout(containerDefaultProject(), $node);
        $sqlite = DatabaseConnection::query()->forceCreate(['slug' => 'tool-default', 'driver' => 'sqlite', 'node_id' => $node->id, 'owner_instance_id' => $default->id,
            'path' => '/home/orbit/apps/tool/default/database/database.sqlite']);
        DatabaseConnectionTarget::query()->create(['instance_id' => $default->id, 'prefix' => 'DB', 'database_connection_id' => $sqlite->id, 'network' => 'wireguard']);
        app(InstanceEnvironmentStore::class)->seedForCreate($default, ['APP_NAME' => 'tool', 'DB_DATABASE' => '/home/orbit/apps/tool/default/database/database.sqlite']);

        $operation = migrateStart($default);
        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);

        expect($operation->input['sqlite_connection_ids'])->toBe([$sqlite->id])
            ->and($operation->input['container']['firewall'])->toBe([])
            ->and(DatabaseConnection::query()->whereKey($sqlite->id)->exists())->toBeFalse()
            ->and(DatabaseConnectionTarget::query()->where('instance_id', $default->id)->exists())->toBeFalse()
            ->and(app(InstanceEnvironmentStore::class)->values($default))->toBe(['APP_NAME' => 'tool', 'DB_DATABASE' => '/srv/workspace/database/database.sqlite'])
            // No record is left that would refuse a clone of this default.
            ->and(app(InstanceDatabaseClonePlanner::class)->plan($default->project, 'feature', container: true))->toBeNull();
    });
});

describe('the end of a move', function (): void {
    it('makes the container a default\'s runtime: the workspace is its checkout, it runs, and its seed commit is recorded', function (): void {
        $node = containerDefaultNode();
        $default = migrateCheckout(containerDefaultProject(), $node);
        $operation = migrateStart($default);

        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);

        $runtime = InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail();
        expect($default->refresh()->checkout_path)->toBe(InstanceContainer::workspaceOnNode($default))
            ->and($default->status)->toBe(InstanceState::Active)
            ->and($runtime->only(['power_state', 'seed_commit']))->toBe(['power_state' => InstanceRuntimePowerState::Running, 'seed_commit' => str_repeat('d', 40)])
            ->and($operation->refresh()->status->value)->toBe('succeeded')
            ->and(InstanceMigration::running($default))->toBeFalse();
    });

    it('records a non-default Instance\'s T3 server from the run\'s result, and no seed commit', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        migrateMovedDefault($project, $node);
        $feature = migrateCheckout($project, $node, 'feature');
        $operation = migrateStart($feature);

        // An admitted move must finish even if Conn is disabled while its run is in flight.
        app(ExtensionStore::class)->set('conn', false);
        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('e', 40), 't3' => migrateServer($feature)]);

        $environment = ConnEnvironment::query()->where('environment_id', 'env-feature')->firstOrFail();
        expect($environment->instance_id)->toBe($feature->id)
            ->and($environment->url)->toBe('http://10.44.0.7:3800')
            ->and($environment->admin_session)->toBe('gateway-session-value')
            ->and(InstanceRuntime::query()->where('instance_id', $feature->id)->firstOrFail()->only(['power_state', 'seed_commit']))
            ->toBe(['power_state' => InstanceRuntimePowerState::Running, 'seed_commit' => null])
            ->and($feature->refresh()->checkout_path)->toBe(InstanceContainer::workspaceOnNode($feature));
    });

    it('keeps an earlier reservation after a refused retirement preflight, and releases only a fresh one', function (bool $retry, string $code, string $message): void {
        $default = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        if ($retry) {
            $previous = migrateStart($default);
            migrateEnd($previous, 'failed', ['ready' => false, 'moved' => false, 'restored' => false, 'failed_step' => 'files']);
        }
        $operation = migrateStart($default);
        $result = ['ready' => false, 'moved' => false, 'restored' => true, 'preflight_refused' => true,
            'failed_step' => 'quiesce', 'error_code' => $code, 'error_message' => $message];

        migrateEnd($operation, 'failed', $result);

        expect($operation->input['had_runtime_reservation'])->toBe($retry)
            ->and($default->runtime()->exists())->toBe($retry)
            ->and($default->refresh()->checkout_path)->toBe('/home/orbit/apps/tool/default')
            ->and($operation->refresh()->error_code)->toBe($code)
            ->and($operation->result['error_message'])->toBe($message);
    })->with(['new move' => false, 'retry after failed restore' => true])->with([
        'worktrees or changes' => ['instance.migrate_retirement_blocked', 'Cannot retire checkout "/fixture". Other worktrees (1 total; first 20): ["/detached"].'],
        'unpushed commits' => ['instance.migrate_unpushed_commits', 'Cannot retire checkout "/fixture". Unpushed branches or detached HEAD (1 total): [{"branch": "spare", "commits": 2}].'],
        'stashes' => ['instance.migrate_stashes_present', 'Cannot retire checkout "/fixture". Stashes: 2.'],
    ]);

    it('leaves a checkout Instance as it was when the move failed and restored', function (): void {
        $node = containerDefaultNode();
        $default = migrateCheckout(containerDefaultProject(), $node);
        $operation = migrateStart($default);

        migrateEnd($operation, 'failed', ['ready' => false, 'moved' => false, 'restored' => true, 'failed_step' => 'files', 'error_code' => 'instance.database_unreachable']);

        expect(InstanceRuntime::query()->where('instance_id', $default->id)->exists())->toBeFalse()
            ->and($default->refresh()->only(['checkout_path', 'status']))->toBe(['checkout_path' => '/home/orbit/apps/tool/default', 'status' => InstanceState::Active])
            ->and($operation->refresh()->error_code)->toBe('instance.database_unreachable');
        // The same command moves it again, at the address that is free again.
        expect(migrateStart($default)->id)->not->toBe($operation->id)
            ->and(InstanceRuntime::query()->where('instance_id', $default->id)->value('bridge_address'))->toBe('10.253.0.2');
    });

    it('keeps the reserved address and database rules on retry after a failed restore even when a lower address is freed', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $earlier = migrateCheckout($project, $node, 'earlier');
        $occupied = app(InstanceContainer::class)->reserve($earlier, $node);
        $default = migrateCheckout($project, $node);
        migrateServerDatabase($default, migrateDatabaseServer($node, 'pg', DatabaseDriver::Pgsql, 5432));
        $operation = migrateStart($default);
        $reserved = $default->runtime()->firstOrFail();

        migrateEnd($operation, 'failed', ['ready' => false, 'moved' => false, 'restored' => false, 'failed_step' => 'route', 'error_code' => 'instance.migrate_route_failed']);
        $occupied->delete();
        $retry = migrateStart($default);

        expect($retry->id)->not->toBe($operation->id)
            ->and($default->runtime()->firstOrFail()->id)->toBe($reserved->id)
            ->and($retry->input['container']['address'])->toBe('10.253.0.3')
            ->and($retry->input['container']['firewall'])->toBe($operation->input['container']['firewall'])
            ->and($default->refresh()->checkout_path)->toBe('/home/orbit/apps/tool/default');
    });

    it('releases only a new reservation when Swamp cannot start the run', function (bool $retry): void {
        $default = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $reserved = null;
        if ($retry) {
            $operation = migrateStart($default);
            $reserved = $default->runtime()->firstOrFail();
            migrateEnd($operation, 'failed', ['ready' => false, 'moved' => false, 'restored' => false, 'failed_step' => 'route', 'error_code' => 'instance.migrate_route_failed']);
        }
        config(['swamp.run_token' => '']);

        $admitted = app(StartInstanceMigrateAction::class)->execute($default, null, null);
        expect($admitted->status->value)->toBe('queued');
        Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->operationId === $admitted->id);

        expect(containerRefusal(fn () => (new StartAdmittedSwampRun($admitted->id))->handle(app(StartSwampRun::class), app(ExpireOperationAction::class))))->toBe('swamp.start_unavailable')
            ->and($default->runtime()->exists())->toBe($retry)
            ->and($default->runtime()->first()?->id)->toBe($reserved?->id);
    })->with(['first attempt' => false, 'failed restore retry' => true]);

    it('keeps the Instance in its container when only the removal of the checkout failed', function (string $code): void {
        $node = containerDefaultNode();
        $default = migrateCheckout(containerDefaultProject(), $node);
        $operation = migrateStart($default);

        migrateEnd($operation, 'failed', ['ready' => false, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40),
            'failed_step' => 'retire', 'error_code' => $code, 'error_message' => 'Retirement refused without changing the source.']);

        expect($default->refresh()->checkout_path)->toBe(InstanceContainer::workspaceOnNode($default))
            ->and(InstanceRuntime::query()->where('instance_id', $default->id)->value('seed_commit'))->toBe(str_repeat('d', 40))
            ->and($operation->refresh()->status->value)->toBe('failed')
            ->and($operation->error_code)->toBe($code)
            ->and($operation->result['error_message'])->toBe('Retirement refused without changing the source.');
    })->with(['instance.migrate_retire_failed', 'instance.migrate_retirement_blocked', 'instance.migrate_unpushed_commits', 'instance.migrate_stashes_present']);

    it('records and exposes retained Git after retirement, including when a later retirement fails', function (bool $failed): void {
        $default = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        expect(InstanceData::fromModel($default)->toArray())->toMatchArray(['retained_git_path' => null, 'retained_git_at' => null]);
        $operation = migrateStart($default);
        $path = '/home/orbit/apps/tool/default.orbit-retired-git';
        $at = '2026-11-10T19:00:00+00:00';

        migrateEnd($operation, $failed ? 'failed' : 'succeeded', [
            'ready' => ! $failed, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40),
            'retained_git_path' => $path, 'retained_git_at' => $at,
            ...($failed ? ['failed_step' => 'retire', 'error_code' => 'instance.migrate_retire_failed'] : []),
        ]);

        expect($default->refresh()->retained_git_path)->toBe($path)
            ->and($default->retained_git_at?->toIso8601String())->toBe($at)
            ->and(InstanceData::fromModel($default)->toArray())->toMatchArray(['retained_git_path' => $path, 'retained_git_at' => $at]);
    })->with(['completed retirement' => false, 'later retirement failed' => true]);

    it('reverses the retained Git columns without touching Node data', function (): void {
        $migration = require database_path('migrations/2027_02_04_000001_add_retained_git_to_instances.php');
        $migration->down();
        expect(Schema::hasColumn('instances', 'retained_git_path'))->toBeFalse()
            ->and(Schema::hasColumn('instances', 'retained_git_at'))->toBeFalse();

        $migration->up();
        expect(Schema::hasColumn('instances', 'retained_git_path'))->toBeTrue()
            ->and(Schema::hasColumn('instances', 'retained_git_at'))->toBeTrue();
    });

    it('refuses a succeeded run that does not prove the move', function (array $result): void {
        $default = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $operation = migrateStart($default);

        expect(fn () => migrateEnd($operation, 'succeeded', $result))->toThrow(ResourceOperationException::class, 'The move did not prove its complete terminal result.');
        expect($default->refresh()->checkout_path)->toBe('/home/orbit/apps/tool/default');
    })->with([
        'not moved' => [['ready' => true, 'moved' => false, 'restored' => false, 'commit' => str_repeat('d', 40)]],
        'no commit' => [['ready' => true, 'moved' => true, 'restored' => false]],
    ]);
});

describe('a reserved runtime row', function (): void {
    /** The Instance's own Route, active, as its create left it. */
    function migrateRoute(Instance $instance): Route
    {
        $route = Route::query()->create(['kind' => 'app', 'project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'provenance' => 'explicit',
            'publication' => 'private', 'domain' => $instance->name.'.tool.test', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active', 'sites_published' => true]);

        return $route;
    }

    it('does not turn the Instance\'s site: the checkout serves until the move has reached the container', function (): void {
        $node = containerDefaultNode();
        $default = migrateCheckout(containerDefaultProject(ProjectType::LaravelApp, '8.5'), $node);
        $default->update(['selected_php_version' => '8.5', 'source_is_laravel' => true, 'root' => 'public']);
        $route = migrateRoute($default);
        $sites = app(DevelopmentSiteRepository::class);
        $operation = Operation::query()->create(['kind' => 'instance.migrate', 'target_type' => 'instance', 'target_id' => $default->id, 'node_id' => $node->id,
            'status' => 'running', 'input' => []]);
        app(InstanceContainer::class)->reserve($default, $node);

        // While the move runs, and after a move that failed, whatever its restore did.
        expect($sites->workloadSite($default->refresh(), $route)->localHttpUpstream)->toBeNull();
        $operation->forceFill(['status' => 'failed', 'result' => ['moved' => false, 'restored' => false]])->save();
        expect($sites->workloadSite($default->refresh(), $route)->phpVersion)->toBe('8.5')
            ->and(containerRefusal(fn () => app(StartInstanceDeployAction::class)->execute($default, 'test', null)))->toBe('instance.lifecycle_busy');

        // A move that reached the container, also one whose removal of the checkout failed.
        $operation->forceFill(['status' => 'failed', 'result' => ['moved' => true]])->save();
        expect($sites->workloadSite($default->refresh(), $route)->localHttpUpstream)->toBe('10.253.0.2:8080');
    });

    it('goes with a run that could not start, so the Instance is the checkout Instance it was', function (): void {
        // No Swamp answers.
        app()->forgetInstance(WebSocketClient::class);
        config(['swamp.address' => null]);
        $default = migrateCheckout(containerDefaultProject(), containerDefaultNode());

        $admitted = app(StartInstanceMigrateAction::class)->execute($default, null, null);
        expect($admitted->status->value)->toBe('queued');
        Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->operationId === $admitted->id);

        expect(containerRefusal(fn () => (new StartAdmittedSwampRun($admitted->id))->handle(app(StartSwampRun::class), app(ExpireOperationAction::class))))->toBe('swamp.start_unavailable')
            ->and(InstanceRuntime::query()->where('instance_id', $default->id)->exists())->toBeFalse()
            ->and(Operation::query()->where('kind', 'instance.migrate')->where('target_id', $default->id)->value('status')->value)->toBe('failed');
        InstanceContainer::assertCheckout($default);
    });
});

describe('while a move runs', function (): void {
    it('starts no wake and takes no deployment, so nothing writes the checkout or the database', function (): void {
        $default = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        migrateStart($default)->forceFill(['status' => 'running', 'finished_at' => null])->save();
        $markers = Mockery::mock(HibernationMarkerStore::class);
        $markers->shouldNotReceive('markAwake', 'isCold');
        app()->instance(HibernationMarkerStore::class, $markers);

        app(ActivateInstanceRuntimeAction::class)->execute($default);

        expect(containerRefusal(fn () => app(StartInstanceDeployAction::class)->execute($default, 'test', null)))->toBe('instance.lifecycle_busy');
    });

    it('holds the Route\'s Node as every Route write does', function (): void {
        expect(RoutePublicationHolds::applies('instance.migrate'))->toBeTrue();
    });
});

it('starts the run with the container, the checkout and the model key in the vault only', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    migrateMovedDefault($project, $node);
    $feature = migrateCheckout($project, $node, 'feature');
    $operation = migrateStart($feature);
    $vault = app(WebSocketClient::class);

    $run = app(InstanceMigrateProjection::class)->input($operation);

    $put = collect($vault->puts)->firstWhere('key', 'instance-migrate-model-'.$operation->id);
    expect($run['execution_secrets'])->toBe(['process_environments' => ['vault' => 'orbit-control-inputs', 'key' => 'instance-migrate-processes-'.$operation->id], 'model_environment' => ['vault' => 'orbit-control-inputs', 'key' => 'instance-migrate-model-'.$operation->id]])
        ->and($put['value'])->toContain("ORBIT_MODEL_KEY=placeholder-model-key\n")
        ->and(json_encode($run['intent']))->not->toContain('placeholder-model-key')
        ->and(array_keys($run['intent']))->toBe(['instance_id', 'instance_name', 'ssh_execution', 'container', 'migrate', 'route_mutations'])
        ->and($run['intent']['route_mutations'])->toBeNull()
        ->and(app(DirectOperation::class)->definition('instance.migrate')['workflow'])->toBe('operation-instance-migrate');
});

it('gives a default\'s run a model copy that holds no key', function (): void {
    $operation = migrateStart(migrateCheckout(containerDefaultProject(), containerDefaultNode()));
    $vault = app(WebSocketClient::class);

    app(InstanceMigrateProjection::class)->input($operation);

    expect(collect($vault->puts)->firstWhere('key', 'instance-migrate-model-'.$operation->id)['value'])->not->toContain('placeholder-model-key');
});

it('publishes the checkout\'s site before the move and the container\'s after it, with the way back', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $default = migrateCheckout($project, $node);
    $default->update(['selected_php_version' => '8.5', 'source_is_laravel' => true, 'root' => 'public']);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $node->id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'default.tool.test', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $default->id, 'position' => 0]);
    $route->update(['status' => 'active', 'sites_published' => true]);
    app()->instance(LeafCertificateSigner::class, new class implements LeafCertificateSigner
    {
        public function rootCertificate(): string
        {
            return 'root-certificate';
        }
    });
    $routes = app(InstanceMigrationRoute::class);

    $before = $routes->before($route);
    $runtime = app(InstanceContainer::class)->reserve($default, $node);
    $capture = $routes->capture($default, $route, $runtime, $before);

    $mutations = $capture['route_mutations'];
    $publication = collect($mutations['forward']['publications'])->firstWhere('node_id', $node->id);
    $caddy = $mutations['artifacts']['caddy'];
    expect($before['route']['sites'][0]->phpVersion)->toBe('8.5')
        ->and($before['route']['sites'][0]->localHttpUpstream)->toBeNull()
        ->and(json_encode($caddy[$publication['before']['caddy']]))->toContain('php_fastcgi')->not->toContain('10.253.0.2:8080')
        ->and(json_encode($caddy[$publication['after']['caddy']]))->toContain('reverse_proxy 10.253.0.2:8080')->not->toContain('php_fastcgi')
        // The Route keeps its Node, its domain and its certificate: nothing is signed, and no .env is written.
        ->and($mutations['forward']['certificates'])->toBe([])
        ->and($mutations['forward']['url_writes'])->toBe([])
        ->and($mutations['restore']['publications'])->toHaveCount(count($mutations['forward']['publications']))
        ->and($capture['execution_secrets'])->toBe([])
        ->and($publication['serves_routes'])->toBeTrue();
});

function migrationWorker(Instance $instance, string $name = 'worker', string $desired = 'running'): Process
{
    return Process::query()->forceCreate(['owner_type' => 'instance', 'owner_id' => $instance->id, 'name' => $name,
        'runtime' => 'systemd', 'working_directory' => $instance->checkout_path, 'status' => 'active', 'desired_state' => $desired, 'systemd_activation_hash' => 'old-host-hash',
        'runtime_config' => ['command' => ['/usr/bin/env', 'node', 'worker.js'], 'environment_file' => $instance->checkout_path.'/.env', 'restart_policy' => 'always',
            'environment' => ['QUEUE_TOKEN' => 'migration-process-secret']]]);
}

describe('Processes moving into the container', function (): void {
    it('keeps existing Process ids and desired states, rendering their workspace and delivering values only through the vault', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $running = migrationWorker($instance);
        $stopped = migrationWorker($instance, 'stopped', 'stopped');
        $stopped->update(['working_directory' => $instance->checkout_path.'/nested', 'runtime_config' => [
            'command' => ['/usr/bin/env', 'node', $instance->checkout_path.'/nested/worker.js']]]);
        $vault = migrateVault();

        $operation = migrateStart($instance);
        $run = app(InstanceMigrateProjection::class)->input($operation);
        $units = $run['intent']['container']['processes'];

        expect(array_column($units, 'id'))->toBe([$running->id, $stopped->id])
            ->and(array_column($units, 'desired'))->toBe(['running', 'stopped'])
            ->and($units[0]['content'])->toContain("WorkingDirectory=/srv/workspace\n")
            ->and(json_encode($run['intent']))->not->toContain('migration-process-secret')
            ->and($run['execution_secrets']['process_environments']['key'])->toBe('instance-migrate-processes-'.$operation->id)
            ->and(collect($vault->puts)->firstWhere('key', 'instance-migrate-processes-'.$operation->id)['value'])->toContain('migration-process-secret')
            ->and($operation->input['migrate']['processes'][0])->toMatchArray(['name' => $units[0]['name'],
                'path' => '/etc/systemd/system/'.$units[0]['name'], 'environment_directory' => '/etc/orbit/processes/'.$running->id, 'desired' => 'running']);

        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);

        expect($running->refresh()->status)->toBe(LifecycleStatus::Active)
            ->and($running->systemd_activation_hash)->toBe(hash('sha256', $units[0]['content']))
            ->and($running->working_directory)->toBe('/srv/workspace')
            ->and($running->runtime_config['environment_file'])->toBe('/srv/workspace/.env')
            ->and($stopped->refresh()->desired_state->value)->toBe('stopped')
            ->and($stopped->working_directory)->toBe('/srv/workspace/nested')
            ->and($stopped->runtime_config['command'][2])->toBe('/srv/workspace/nested/worker.js')
            ->and(Process::query()->where('owner_id', $instance->id)->count())->toBe(2);
    });

    it('keeps the host activation record after a restored failure', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $worker = migrationWorker($instance);
        $operation = migrateStart($instance);

        migrateEnd($operation, 'failed', ['ready' => false, 'moved' => false, 'restored' => true,
            'failed_step' => 'processes', 'error_code' => 'instance.migrate_processes_failed']);

        expect($worker->refresh()->systemd_activation_hash)->toBe('old-host-hash')
            ->and($worker->status)->toBe(LifecycleStatus::Active)
            ->and($instance->runtime()->exists())->toBeFalse();
    });

    it('refuses migration while an owned Process operation or removal is unfinished', function (bool $removing): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $worker = migrationWorker($instance);
        if ($removing) {
            $worker->update(['status' => LifecycleStatus::Removing]);
        } else {
            Operation::query()->forceCreate(['kind' => 'process.restart', 'target_type' => 'process', 'target_id' => $worker->id,
                'node_id' => $instance->node_id, 'status' => 'running', 'input' => []]);
        }

        expect(containerRefusal(fn () => migrateStart($instance)))->toBe('instance.lifecycle_busy')
            ->and($instance->runtime()->exists())->toBeFalse();
    })->with(['operation' => false, 'removal' => true]);

    it('refuses an unresolved retired runtime after a failed Process runtime switch', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $worker = migrationWorker($instance);
        $worker->forceFill(['status' => LifecycleStatus::Failed, 'retired_runtime' => 'docker'])->save();
        Operation::query()->forceCreate(['kind' => 'process.update', 'target_type' => 'process', 'target_id' => $worker->id,
            'node_id' => $instance->node_id, 'status' => 'failed', 'input' => ['retired_runtime' => 'docker']]);

        expect(containerRefusal(fn () => migrateStart($instance)))->toBe('instance.lifecycle_busy')
            ->and($instance->runtime()->exists())->toBeFalse()
            ->and($worker->refresh()->retired_runtime?->value)->toBe('docker')
            ->and(Operation::query()->count())->toBe(1);
    });

    it('keeps another Instance runtime cleanup outside its migration admission', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $instance = migrateCheckout($project, $node);
        $other = migrateCheckout($project, $node, 'other');
        $worker = migrationWorker($other);
        $worker->forceFill(['retired_runtime' => 'docker'])->save();

        $operation = migrateStart($instance);

        expect($operation->status->value)->toBe('queued')
            ->and($operation->input['container']['processes'])->toBe([])
            ->and($worker->refresh()->retired_runtime?->value)->toBe('docker');
    });

    it('refuses existing Node-bound presets before migration, including stopped ones', function (string $desired): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['vite_port' => 5173]);
        $worker = migrationWorker($instance, 'vite', $desired);
        $worker->update(['runtime_config' => [...$worker->runtime_config, 'preset' => 'vp-dev']]);

        expect(containerRefusal(fn () => migrateStart($instance)))->toBe('process.target_unsupported')
            ->and($instance->runtime()->exists())->toBeFalse()
            ->and(Operation::query()->count())->toBe(0);
    })->with(['running', 'stopped']);

    it('refuses Process changes while migration owns the Instance', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $worker = migrationWorker($instance);
        migrateStart($instance);

        expect(containerRefusal(fn () => app(ProcessOperationAdmission::class)->inputFor($worker, 'restart')))->toBe('instance.lifecycle_busy');
    });

    it('keeps Docker Processes outside the supported container runtime', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        Process::query()->forceCreate(['owner_type' => 'instance', 'owner_id' => $instance->id, 'name' => 'redis',
            'runtime' => 'docker', 'runtime_config' => ['image' => 'redis:7', 'command' => ['redis-server']], 'status' => 'active']);

        expect(containerRefusal(fn () => migrateStart($instance)))->toBe('process.target_unsupported')
            ->and($instance->runtime()->exists())->toBeFalse();
    });
});
