<?php

declare(strict_types=1);

use App\Actions\Hibernation\ActivateInstanceRuntimeAction;
use App\Actions\Instances\StartInstanceDeployAction;
use App\Actions\Instances\StartInstanceMigrateAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Operations\ReadOperationAction;
use App\Data\Instances\InstanceData;
use App\Data\Operations\OperationData;
use App\Actions\Schedules\CreateScheduleAction;
use App\Actions\Schedules\RunScheduleAction;
use App\Actions\Schedules\ShowScheduleLogsAction;
use App\Data\Schedules\AddScheduleData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\InstanceMigration\InstanceMigrateProjection;
use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\InstanceMigration\InstanceMigrationRoute;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlanner;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceAttachedTargets;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceEgress;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Logs\LogStreamTargetResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\VpDevPreset;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\Projects\ProjectType;
use App\Domain\Schedules\ScheduleControlProjection;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Schedules\ScheduleRuntimeAccount;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Infrastructure\Swamp\SwampReadBudget;
use App\Infrastructure\Swamp\SwampVaultExecutionCopy;
use App\Jobs\Operations\ReconcileSwampOperations;
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
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
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
                'environment' => ['targets' => [], 'node_addresses' => ['10.44.0.7', '10.44.0.7'],
                    'proxy_policy' => ['private_v4' => InstanceEgress::PrivateV4, 'private_domains' => ['orbit']]],
                'databases' => [], 'processes' => [], 'schedules' => [], 'firewall_prefix' => 'orbit:instance-'.$default->id.'-',
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

            public function read(string $repositoryUrl, ProjectSourceAccess $access, string $branch, array $paths, ?string $commit = null): array
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
            public function read(string $repositoryUrl, ProjectSourceAccess $access, string $branch, array $paths, ?string $commit = null): array
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
    it('keeps grants bridge endpoints and probes byte-identical to b111d0c009 with duplicate attachments', function (): void {
        $node = containerDefaultNode();
        $default = migrateCheckout(containerDefaultProject(), $node);
        migrateServerDatabase($default, migrateDatabaseServer($node, 'cache', DatabaseDriver::Redis, 6379), 'REDIS');
        $server = migrateDatabaseServer($node, 'pg', DatabaseDriver::Pgsql, 5432);
        $database = migrateServerDatabase($default, $server);
        $services = containerDefaultNode('Services', '10.44.0.3', false);
        migrateServerDatabase($default, migrateDatabaseServer($services, 'catalog', DatabaseDriver::Pgsql, 5434), 'FOOD_CATALOG');
        DatabaseConnectionTarget::query()->create([
            'instance_id' => $default->id, 'prefix' => 'DB_ALIAS', 'database_connection_id' => $database->id, 'network' => 'wireguard',
        ]);
        migrateServerDatabase($default, $server, 'REPORTING');
        $sqlite = DatabaseConnection::query()->create([
            'slug' => 'workspace', 'driver' => DatabaseDriver::Sqlite, 'path' => $default->checkout_path.'/database.sqlite',
        ]);
        DatabaseConnectionTarget::query()->create([
            'instance_id' => $default->id, 'prefix' => 'SQLITE', 'database_connection_id' => $sqlite->id, 'network' => 'wireguard',
        ]);

        $displayed = InstanceAttachedTargets::forInstance($default);
        $operation = migrateStart($default);
        $container = $operation->input['container'];
        $host = app(InstanceHostIntent::class)->for($node);
        expect($container['nic_acl'])->toBe('orbitinst0-proxy')->and($host['nic_acl'])->toBe($container['nic_acl']);
        $network = [
            'acl_rules' => $container['acl_rules'],
            'firewall' => $container['firewall'],
            'bridge_endpoints' => $container['bridge_endpoints'],
            'probes' => $operation->input['migrate']['databases'],
            'node_bridge_endpoints' => $host['bridge_endpoints'],
            'bridge_ingress' => $host['ingress'],
        ];
        $path = base_path('tests/Fixtures/InstanceMigration/b111-attached-endpoints.json');
        if (getenv('ORBIT_FIXTURES') === 'record') {
            file_put_contents($path, json_encode($network, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }

        $baseline = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect(json_encode($network, JSON_THROW_ON_ERROR))->toBe(json_encode($baseline, JSON_THROW_ON_ERROR));

        expect($displayed->pluck('id')->all())->toBe([2, 1, 3, 4, 5, 6])
            ->and($displayed->pluck('database_connection_id')->all())->toBe([2, 1, 3, 2, 4, 5])
            ->and($displayed->pluck('prefix')->all())->toBe(['DB', 'REDIS', 'FOOD_CATALOG', 'DB_ALIAS', 'REPORTING', 'SQLITE']);

        $connections = $displayed->map(static fn (DatabaseConnectionTarget $target): DatabaseConnection => $target->databaseConnection);
        $probes = $connections->reject(static fn (DatabaseConnection $connection): bool => $connection->driver === DatabaseDriver::Sqlite)
            ->unique('id')->values()
            ->map(static fn (DatabaseConnection $connection): array => ['host' => $connection->host, 'port' => $connection->port]);
        expect($probes->all())->toBe($operation->input['migrate']['databases']);

        $endpoints = $probes->unique(static fn (array $endpoint): string => $endpoint['host'].':'.$endpoint['port'])->values()->all();
        $grants = array_map(static fn (array $grant): array => [
            'host' => substr($grant['destination'], 0, -3), 'port' => (int) $grant['destination_port'],
        ], $container['acl_rules']['egress']);
        expect($endpoints)->toBe($grants);

        $sqliteIds = $connections->filter(static fn (DatabaseConnection $connection): bool => $connection->driver === DatabaseDriver::Sqlite)
            ->unique('id')->pluck('id')->values()->all();
        expect($sqliteIds)->toBe($operation->input['sqlite_connection_ids']);
    });

    it('leaves a database on its server and gives the container its way to every server of the Instance', function (): void {
        $node = containerDefaultNode();
        $default = migrateCheckout(containerDefaultProject(), $node);
        $valkey = migrateServerDatabase($default, migrateDatabaseServer($node, 'cache', DatabaseDriver::Redis, 6379), 'REDIS');
        $database = migrateServerDatabase($default, migrateDatabaseServer($node, 'pg', DatabaseDriver::Pgsql, 5432));
        $services = containerDefaultNode('Services', '10.44.0.3', false);
        $catalog = migrateServerDatabase($default, migrateDatabaseServer($services, 'catalog', DatabaseDriver::Pgsql, 5434), 'FOOD_CATALOG');
        $shownTargets = InstanceData::fromModel($default)->toArray()['attached_targets'];

        $operation = migrateStart($default);

        $container = ValidatedData::object($operation->input['container']);
        $rule = ['rule' => 'allow', 'interface_in' => 'orbitinst0', 'from_ip' => '10.253.0.2', 'to_ip' => '10.44.0.7', 'proto' => 'tcp'];
        expect($container['acl_rules']['egress'])->toBe([
            ['action' => 'allow', 'destination' => '10.44.0.7/32', 'protocol' => 'tcp', 'destination_port' => '5432', 'state' => 'enabled'],
            ['action' => 'allow', 'destination' => '10.44.0.7/32', 'protocol' => 'tcp', 'destination_port' => '6379', 'state' => 'enabled'],
            ['action' => 'allow', 'destination' => '10.44.0.3/32', 'protocol' => 'tcp', 'destination_port' => '5434', 'state' => 'enabled']])
            ->and($container['firewall'])->toEqual([[...$rule, 'to_port' => '5432', 'comment' => "orbit:instance-{$default->id}-database"],
                [...$rule, 'to_port' => '6379', 'comment' => "orbit:instance-{$default->id}-database-{$valkey->id}"],
                [...$rule, 'to_ip' => '10.44.0.3', 'to_port' => '5434', 'route' => 'yes', 'interface_out' => 'orbit', 'comment' => "orbit:instance-{$default->id}-database-{$catalog->id}"]])
            // The move proves each way from inside the container, `DB` first.
            ->and($operation->input['migrate']['databases'])->toBe([
                ['host' => '10.44.0.7', 'port' => 5432], ['host' => '10.44.0.7', 'port' => 6379], ['host' => '10.44.0.3', 'port' => 5434],
            ])
            ->and(array_column($shownTargets, 'id'))->toBe([$database->id, $valkey->id, $catalog->id])
            ->and(array_map(static fn (array $target): array => ['host' => $target['host'], 'port' => $target['port']], $shownTargets))
            ->toBe($operation->input['migrate']['databases'])
            ->and($operation->input['migrate']['environment']['targets'])->toBe($operation->input['migrate']['databases'])
            ->and($container['bridge_endpoints'])->toBe([
                ['host' => '10.44.0.7', 'port' => 5432, 'docker' => true],
                ['host' => '10.44.0.7', 'port' => 6379, 'docker' => true],
                ['host' => '10.44.0.3', 'port' => 5434, 'docker' => false],
            ])
            // No second database: the Instance keeps the one it has.
            ->and(DatabaseConnection::query()->where('owner_instance_id', $default->id)->pluck('id')->all())->toEqualCanonicalizing([$valkey->id, $database->id, $catalog->id]);
    });

    it('uses the container access endpoints for the guard despite a different target projection without copying credentials', function (): void {
        $node = containerDefaultNode();
        $node->update(['lan_ip' => '192.168.50.7', 'tld' => 'private.test']);
        $default = migrateCheckout(containerDefaultProject(), $node);
        $server = migrateDatabaseServer($node, 'catalog', DatabaseDriver::Mysql, 3308);
        $database = migrateServerDatabase($default, $server, 'FOOD_CATALOG_DB');
        migrateServerDatabase($default, $server, 'ANALYTICS');
        $default->databaseConnectionTargets()->update(['network' => 'lan']);

        $operation = migrateStart($default);

        expect($operation->input['migrate']['environment'])->toBe([
            'targets' => [['host' => '10.44.0.7', 'port' => 3308]],
            'node_addresses' => ['10.44.0.7', '192.168.50.7', '10.44.0.7'],
            'proxy_policy' => ['private_v4' => InstanceEgress::PrivateV4, 'private_domains' => ['orbit', 'private.test']],
        ]);
        expect($operation->input['migrate']['databases'])->toBe([['host' => '10.44.0.7', 'port' => 3308], ['host' => '10.44.0.7', 'port' => 3308]])
            ->and($operation->input['container']['acl_rules']['egress'])->toBe([['action' => 'allow', 'destination' => '10.44.0.7/32',
                'protocol' => 'tcp', 'destination_port' => '3308', 'state' => 'enabled']])
            ->and($operation->input['container']['firewall'][0]['to_ip'])->toBe('10.44.0.7');
        expect(json_encode($operation->input['migrate']['environment'], JSON_THROW_ON_ERROR))
            ->not->toContain($database->password, 'root-secret', 'username', 'password');
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
    it('admits a routed migrated default deployment without a policy tick', function (): void {
        $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('root-certificate');
        $default = migrateCheckout(containerDefaultProject(ProjectType::LaravelApp, '8.5'), containerDefaultNode());
        $route = migrateRoute($default);
        migrateSource(['require' => ['php' => '^8.5', 'laravel/framework' => '^13.0']]);
        $operation = migrateStart($default);

        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $deploy = app(StartInstanceDeployAction::class)->execute($default->refresh(), 'manual', null);

        expect($route->refresh()->status->value)->toBe('active');
        expect($deploy->input['deployment'])->toMatchArray(['variant' => 'container', 'projection_route_id' => null]);
        $this->assertDatabaseHas('instances', ['id' => $default->id, 'development_projection_pending' => false]);
    });

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

    it('keeps an earlier reservation after a refused preflight, and releases only a fresh one', function (bool $retry, string $code, string $message): void {
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
        'service host' => ['instance.migrate_env_service_unreachable', '.env: DB_HOST=127.0.0.1:3308. Attach the exact target, correct the .env, or use a non-network driver.'],
    ]);

    it('records a named symlink preflight refusal without changing checkout state or choosing a repair', function (string $reason, string $target): void {
        $default = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $operation = migrateStart($default);
        $checkout = $default->checkout_path;
        $message = 'Cannot migrate checkout "'.$checkout.'". Unsupported symlinks: '.json_encode([
            ['path' => 'public/storage', 'target' => $target, 'reason' => $reason],
            ['path' => 'local-package', 'target' => $target, 'reason' => $reason],
        ], JSON_THROW_ON_ERROR).'.';

        migrateEnd($operation, 'failed', ['ready' => false, 'moved' => false, 'restored' => true, 'preflight_refused' => true,
            'failed_step' => 'quiesce', 'error_code' => 'instance.migrate_symlink_unsupported', 'error_message' => $message]);

        expect($operation->input['migrate']['checkout'])->toBe($checkout)
            ->and($operation->input['container']['repository'])->toBe($checkout)
            ->and($operation->input['container']['workspace'])->toBe('/srv/workspace')
            ->and($operation->input['container']['steps'])->toBe([])
            ->and($operation->refresh()->error_code)->toBe('instance.migrate_symlink_unsupported')
            ->and($operation->result['error_message'])->toBe($message)
            ->and($default->refresh()->only(['checkout_path', 'status']))->toBe(['checkout_path' => $checkout, 'status' => InstanceState::Active])
            ->and($default->runtime()->exists())->toBeFalse();
    })->with(['external' => ['outside checkout', '/shared/storage'], 'broken' => ['broken', '/missing/other-checkout/storage']]);

    it('leaves a checkout Instance as it was when the move failed and restored', function (): void {
        $node = containerDefaultNode();
        $default = migrateCheckout(containerDefaultProject(), $node);
        $operation = migrateStart($default);

        migrateEnd($operation, 'failed', ['ready' => false, 'moved' => false, 'restored' => true, 'failed_step' => 'container', 'error_code' => 'instance.database_unreachable']);

        expect(InstanceRuntime::query()->where('instance_id', $default->id)->exists())->toBeFalse()
            ->and($default->refresh()->only(['checkout_path', 'status']))->toBe(['checkout_path' => '/home/orbit/apps/tool/default', 'status' => InstanceState::Active])
            ->and($operation->refresh()->error_code)->toBe('instance.database_unreachable');
        $this->assertDatabaseHas('instances', ['id' => $default->id, 'development_projection_pending' => true]);
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
        $this->assertDatabaseHas('instances', ['id' => $default->id, 'development_projection_pending' => true]);
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

/** The supported preset as recorded on checkout Nodes, including the surveyed literal-port command. */
function migrationVpDev(Instance $instance, string $desired = 'running', bool $surveyed = false, string $name = 'vite'): Process
{
    $instance->update(['vite_port' => 5195]);
    $process = migrationWorker($instance, $name, $desired);
    $process->update(['restart_policy' => 'on-failure', 'runtime_config' => [
        'preset' => 'vp-dev',
        'command' => $surveyed
            ? ['/usr/bin/env', '-u', 'VITE_DEV_SERVER_CERT', '-u', 'VITE_DEV_SERVER_KEY', '/home/orbit/.vite-plus/bin/vp', 'dev', '--host=127.0.0.1', '--port=5195', '--strictPort']
            : VpDevPreset::command(),
        'environment_file' => $instance->checkout_path.'/.env',
        'environment' => ['QUEUE_TOKEN' => 'migration-process-secret', 'CACHE_PATH' => $instance->checkout_path.'/storage/cache'],
    ]]);

    return $process;
}

describe('Processes moving into the container', function (): void {
    it('reads Process records once per unit, environment or source render', function (string $render): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        migrationVpDev($instance);
        migrationWorker($instance);
        $operation = migrateStart($instance);
        $processes = app(ContainerProcesses::class);

        DB::enableQueryLog();
        try {
            $result = match ($render) {
                'units' => $processes->units($instance),
                'environments' => $processes->environments($instance, $operation->input['container']['processes']),
                'source' => $processes->source($instance),
            };
            $reads = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from "processes"'));
            expect($result)->not->toBeEmpty();
            expect($reads)->toHaveCount(1);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    })->with(['units', 'environments', 'source']);

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

    it('carries the preset identity and desired state through the shared Process path', function (string $desired, bool $surveyed, ?int $version): void {
        $instance = migrateCheckout(containerDefaultProject(ProjectType::LaravelApp, '8.5'), containerDefaultNode());
        $instance->update(['selected_php_version' => '8.5', 'source_is_laravel' => true, 'root' => 'public']);
        $route = Route::query()->create(['kind' => 'app', 'project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'provenance' => 'explicit',
            'publication' => 'private', 'domain' => 'default.tool.test', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active', 'sites_published' => true]);
        app()->instance(LeafCertificateSigner::class, new class implements LeafCertificateSigner
        {
            public function rootCertificate(): string
            {
                return 'root-certificate';
            }
        });
        $preset = migrationVpDev($instance, $desired, $surveyed);
        if ($version !== null) {
            $preset->update(['runtime_config' => [...$preset->runtime_config, 'preset_version' => $version]]);
        }
        $preset->update(['working_directory' => $instance->checkout_path.'/frontend']);
        $vault = migrateVault();

        $operation = migrateStart($instance);
        $run = app(InstanceMigrateProjection::class)->input($operation);
        $unit = $run['intent']['container']['processes'][0];

        expect($unit)->toMatchArray(['id' => $preset->id, 'name' => "orbit-process-{$preset->id}-vite.service", 'desired' => $desired]);
        expect($unit['content'])->toContain("User=orbit\n", "WorkingDirectory=/srv/workspace/frontend\n", '"/opt/orbit-runtime/current/bin/vp" "dev" "--host=0.0.0.0" "--port=5195" "--strictPort" "--base=/__orbit/vite/"')
            ->toContain("After=network.target\n")
            ->not->toContain('network-online.target', '/etc/orbit/vite/', '/home/orbit/.vite-plus/', '--host=127.0.0.1');
        expect($unit['content'])->toContain('Environment=ORBIT_DEV_SERVER_ORIGIN=https://default.tool.test/__orbit/vite',
            'Environment=ORBIT_DEV_SERVER_HOST=default.tool.test', 'Environment=ORBIT_DEV_SERVER_PATH=/__orbit/vite', 'Environment=ORBIT_DEV_SERVER_PORT=5195');
        expect($run['intent']['container']['web_config']['content'])->toContain('env HTTPS on', 'env REQUEST_SCHEME https',
            "remote_ip 10.253.0.1/32\n        header X-Forwarded-Proto https");
        expect($run['intent']['migrate']['processes'][0])->toMatchArray(['name' => $unit['name'], 'desired' => $desired]);
        expect(json_encode($run['intent']))->not->toContain('migration-process-secret');
        expect(collect($vault->puts)->firstWhere('key', 'instance-migrate-processes-'.$operation->id)['value'])
            ->toContain('migration-process-secret', '/srv/workspace/storage/cache');
        expect($preset->refresh()->working_directory)->toBe($instance->checkout_path.'/frontend');

        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);

        expect($preset->refresh()->runtime_config)->toMatchArray(['preset' => 'vp-dev', 'environment_file' => '/srv/workspace/.env']);
        expect($preset->working_directory)->toBe('/srv/workspace/frontend')
            ->and($preset->desired_state->value)->toBe($desired)
            ->and($preset->systemd_activation_hash)->toBe(hash('sha256', $unit['content']));
        expect($instance->refresh()->vite_port)->toBe(5195);
        expect(Process::query()->where('owner_id', $instance->id)->count())->toBe(1);
    })->with([
        'catalogue running' => ['running', false, null], 'catalogue stopped' => ['stopped', false, null],
        'surveyed running' => ['running', true, null], 'surveyed stopped' => ['stopped', true, null],
        'explicit known version' => ['running', false, 1],
    ]);

    it('keeps the preset default environment file when the stored option is absent or null', function (bool $missing): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $preset = migrationVpDev($instance);
        $config = $preset->runtime_config;
        $config['environment_file'] = null;
        if ($missing) {
            unset($config['environment_file']);
        }
        $preset->update(['runtime_config' => $config]);

        $operation = migrateStart($instance);

        expect($operation->input['container']['processes'][0]['content'])->toContain("EnvironmentFile=-/srv/workspace/.env\n", '"ORBIT_DEV_SERVER_PORT=5195"');
        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        expect($preset->refresh()->runtime_config['environment_file'])->toBe('/srv/workspace/.env');
    })->with(['missing' => true, 'null' => false]);

    it('refuses unsupported preset intent before reserving a runtime or stopping a Process', function (string $change, string $code, string $reason): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $preset = migrationVpDev($instance, 'stopped');
        $config = $preset->runtime_config;
        match ($change) {
            'unknown preset' => $config['preset'] = 'future-dev',
            'unknown version' => $config['preset_version'] = 99,
            'null version' => $config['preset_version'] = null,
            'custom command' => $config['command'] = ['/usr/bin/env', 'node', 'worker.js'],
            'external cwd' => $preset->working_directory = '/opt/external-app',
            'prefix sibling cwd' => $preset->working_directory = $instance->checkout_path.'-other',
            'traversal cwd' => $preset->working_directory = $instance->checkout_path.'/../other',
            'external env file' => $config['environment_file'] = '/etc/private/app.env',
            'traversal env file' => $config['environment_file'] = $instance->checkout_path.'/../private.env',
            'external env value' => $config['environment']['CACHE_PATH'] = '/opt/private/cache',
            'unsupported options' => $config['user'] = 'root',
        };
        $preset->runtime_config = $config;
        $preset->save();

        try {
            migrateStart($instance);
            $this->fail('The unsupported Process was admitted.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe($code);
            expect($exception->getMessage())->toContain((string) $preset->id, $preset->name, $reason);
        }

        expect($instance->runtime()->exists())->toBeFalse();
        expect(Operation::query()->count())->toBe(0);
        expect($preset->refresh()->systemd_activation_hash)->toBe('old-host-hash')
            ->and($preset->desired_state->value)->toBe('stopped');
    })->with([
        'unknown preset' => ['unknown preset', 'process.container_preset_unsupported', 'preset'],
        'unknown version' => ['unknown version', 'process.container_preset_unsupported', 'version'],
        'null version' => ['null version', 'process.container_preset_unsupported', 'version'],
        'bypass with custom command' => ['custom command', 'process.container_command_unsupported', 'command'],
        'external cwd' => ['external cwd', 'process.container_path_unsupported', 'working directory'],
        'prefix sibling cwd' => ['prefix sibling cwd', 'process.container_path_unsupported', 'working directory'],
        'traversal cwd' => ['traversal cwd', 'process.container_path_unsupported', 'working directory'],
        'external env file' => ['external env file', 'process.container_path_unsupported', 'environment'],
        'traversal env file' => ['traversal env file', 'process.container_path_unsupported', 'environment'],
        'external env value' => ['external env value', 'process.container_path_unsupported', 'environment'],
        'unsupported options' => ['unsupported options', 'process.container_preset_unsupported', 'unsupported options'],
    ]);

    it('refuses a preset port conflict before downtime', function (string $conflict): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $preset = migrationVpDev($instance);
        if ($conflict === 'missing') {
            $instance->update(['vite_port' => null]);
        } elseif ($conflict === 'reserved') {
            $instance->update(['vite_port' => 8080]);
        } elseif ($conflict === 'duplicate') {
            migrationVpDev($instance, 'stopped', false, 'second-vite');
        } else {
            $worker = migrationWorker($instance);
            $worker->update(['runtime_config' => $conflict === 'environment'
                ? ['command' => ['/usr/bin/env', 'node', 'worker.js'], 'environment' => ['PORT' => '5195']]
                : ['command' => ['/usr/bin/env', 'node', 'worker.js', '--port=5195']]]);
        }

        try {
            migrateStart($instance);
            $this->fail('The conflicting Process port was admitted.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('process.container_port_conflict');
            expect($exception->getMessage())->toContain((string) $preset->id, $preset->name, 'port');
        }

        expect($instance->runtime()->exists())->toBeFalse();
        expect(Operation::query()->count())->toBe(0);
        expect($preset->refresh()->systemd_activation_hash)->toBe('old-host-hash');
    })->with(['missing', 'reserved', 'duplicate', 'environment', 'command']);

    it('restores the source preset record and state after a failed move', function (string $desired): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $preset = migrationVpDev($instance, $desired, true);
        $sourceConfig = $preset->runtime_config;
        $sourceDirectory = $preset->working_directory;
        $operation = migrateStart($instance);

        migrateEnd($operation, 'failed', ['ready' => false, 'moved' => false, 'restored' => true,
            'failed_step' => 'processes', 'error_code' => 'instance.migrate_processes_failed']);

        expect($preset->refresh()->runtime_config)->toBe($sourceConfig);
        expect($preset->working_directory)->toBe($sourceDirectory)
            ->and($preset->systemd_activation_hash)->toBe('old-host-hash')
            ->and($preset->desired_state->value)->toBe($desired)
            ->and($preset->status)->toBe(LifecycleStatus::Active);
        expect($instance->runtime()->exists())->toBeFalse();
    })->with(['running', 'stopped']);

    it('admits the assigned preset port only from the Node and preserves it in deployment intent', function (bool $withPreset): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['vite_port' => 5195]);
        if ($withPreset) {
            migrationVpDev($instance);
        }

        $operation = migrateStart($instance);
        $ingress = $operation->input['container']['acl_rules']['ingress'];
        $expected = ['action' => 'allow', 'source' => '10.253.0.1/32', 'protocol' => 'tcp', 'destination_port' => '5195', 'state' => 'enabled'];
        expect(in_array($expected, $ingress, true))->toBe($withPreset);
        expect(array_values(array_filter($ingress, static fn (array $rule): bool => $rule['destination_port'] === '5195')))->toBe($withPreset ? [$expected] : []);

        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $deployment = app(InstanceDeploymentIntent::class)->snapshot($instance->refresh());

        expect($deployment['variant'])->toBe('container');
        expect($deployment['container']['acl_rules']['ingress'])->toBe($ingress);
    })->with(['with preset' => true, 'without preset' => false]);

    it('starts a moved preset through container systemd without Node Vite preparation', function (string $verb): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $preset = migrationVpDev($instance, 'stopped');
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $instance->refresh()->update(['provisioning_step' => 'active']);

        $input = app(ProcessOperationAdmission::class)->inputFor($preset->refresh(), $verb);

        expect($input['variant'])->toBe('systemd');
        expect($input['container'])->toMatchArray(['instance_id' => $instance->id, 'name' => 'instance-'.$instance->id]);
        expect($input['vite'])->toBeNull();
        expect($input)->not->toHaveKey('vite_plan');
        expect($instance->refresh()->vite_port)->toBe(5195);
    })->with(['start', 'restart']);

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

it('captures the Node endpoint union from the same attached targets as each NIC, deduplicates it and excludes other Nodes', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $first = migrateCheckout($project, $node);
    $second = migrateCheckout($project, $node, 'second');
    $otherNode = containerDefaultNode('other-host', '10.44.0.99');
    $other = migrateCheckout($project, $otherNode, 'other');
    $containers = app(InstanceContainer::class);
    foreach ([$first, $second, $other] as $instance) {
        $containers->reserve($instance, $instance->node);
    }
    $sql = migrateDatabaseServer($node, 'union-sql', DatabaseDriver::Pgsql, 5432);
    migrateServerDatabase($first, $sql);
    migrateServerDatabase($second, $sql);
    migrateServerDatabase($second, migrateDatabaseServer($node, 'union-cache', DatabaseDriver::Redis, 6379), 'REDIS');
    migrateServerDatabase($other, migrateDatabaseServer($otherNode, 'other-sql', DatabaseDriver::Pgsql, 5434));
    $want = [['host' => '10.44.0.7', 'port' => 5432, 'docker' => true], ['host' => '10.44.0.7', 'port' => 6379, 'docker' => true]];

    expect($containers->nodeEndpoints($node))->toBe($want)
        ->and(app(InstanceHostIntent::class)->for($node)['bridge_endpoints'])->toBe($want);

    foreach ([fn (): array => app(InstanceHostIntent::class)->for($node), fn (): array => $containers->intent($first, $first->runtime)] as $capture) {
        $this->travelTo(now()->setTimestamp(1800000000)->microsecond(123456));
        $readTargets = false;
        DB::listen(function (QueryExecuted $query) use (&$readTargets): void {
            if (! $readTargets && str_contains($query->sql, 'database_connection_targets')) {
                $readTargets = true;
                $this->travel(1)->seconds();
            }
        });

        $captured = $capture();

        expect($readTargets)->toBeTrue()
            ->and($captured['bridge_admitted_at'])->toBe(1800000000123456)
            ->and(now()->format('Uu'))->toBe('1800000001123456');
    }

    DatabaseConnectionTarget::query()->where('instance_id', $first->id)->delete();
    expect($containers->nodeEndpoints($node))->toBe($want);
    DatabaseConnectionTarget::query()->where('instance_id', $second->id)->delete();
    expect($containers->nodeEndpoints($node))->toBe([]);
});

it('projects the native restored end state across each post-quiesce exit', function (string $exit, string $nativeStatus, bool $expired): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $instance->update(['provisioning_step' => 'active']);
    $worker = migrationWorker($instance);
    $operation = migrateStart($instance);
    $run = '0c69f6e8-bc50-4819-bcab-f3972557d2cc';
    $started = now()->subHours(2)->toISOString();
    $finished = now()->toISOString();
    $operation->forceFill(['status' => 'running', 'attempts' => 1, 'started_at' => $started,
        'finished_at' => null, 'error_code' => null, 'cancel_requested_at' => $exit === 'cancel' ? now()->subHours(2)->addMinute() : null,
        'state' => ['swamp_run_id' => $run]])->save();
    if ($expired) {
        app(ExpireOperationAction::class)->execute($operation);
        $operation->refresh()->update(['lock_key' => null, 'state' => [...$operation->state,
            'native_cancel_confirmed_at' => now()->toISOString()]]);
    }
    $result = ['instance_id' => $instance->id, 'moved' => false, 'restored' => true, 'ready' => false,
        'failed_step' => 'processes', 'error_code' => $exit === 'deadline-only' ? 'operation.timed_out' : 'instance.migrate_processes_failed'];
    $cancelled = in_array($exit, ['deadline-only', 'cancel'], true);
    $nativeResult = $result;
    if ($exit === 'deadline-only') {
        $nativeResult['error_code'] = 'instance.migrate_processes_failed';
    }
    $history = ['id' => $run, 'workflowName' => 'operation-instance-migrate', 'status' => $nativeStatus,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'instance.migrate'],
        'startedAt' => $started, 'completedAt' => $finished, 'jobs' => [['name' => 'migrate', 'steps' => [
            ['name' => 'quiesce', 'status' => 'succeeded', 'startedAt' => $started, 'completedAt' => $started],
            ['name' => 'container-processes', 'status' => 'failed', 'startedAt' => $started, 'completedAt' => $finished,
                'error' => $cancelled ? 'Command exited with code -1' : ($exit === 'step timeout' ? 'Command exited with code 124' : $exit)],
            ['name' => 'restore', 'status' => 'succeeded', 'startedAt' => $finished, 'completedAt' => $finished],
            ['name' => 'finish', 'status' => 'succeeded', 'startedAt' => $finished, 'completedAt' => $finished,
                'outputs' => ['stdout' => json_encode($nativeResult, JSON_THROW_ON_ERROR)]],
        ]]]];
    $frame = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $message) use (&$frame): void {
        $frame = $message;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frame, $history): array {
        return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $frame['type'] === 'workflow.history.get' ? $history : ['results' => []]]]];
    });
    app(SwampConnection::class)->release();
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'read-token']);
    try {
        if ($expired) {
            // Exercise the production selector after cancellation is confirmed, with no target lock.
            $projected = app(ReadOperationAction::class)->execute($operation->refresh());
        } else {
            $projected = app(SwampCommandStep::class)->projectTerminal($operation->refresh(), ['id' => $run],
                ['job' => 'migrate', 'step' => 'finish'], atDeadline: true);
        }

        expect($projected->result)->toBe($result)
            ->and($projected->error_code)->toBe($exit === 'cancel' ? null : $result['error_code'])
            ->and($projected->status->value)->toBe($exit === 'cancel' ? 'cancelled' : ($nativeStatus === 'interrupted' ? 'interrupted' : 'failed'))
            ->and($projected->lock_key)->toBeNull()
            ->and($instance->runtime()->exists())->toBeFalse()
            ->and($worker->refresh()->systemd_activation_hash)->toBe('old-host-hash')
            ->and(app(ProcessTargetResolver::class)->forProcess($worker)->container)->toBeNull();
        $steps = OperationData::summary($projected)->steps;
        expect(array_map(static fn ($step) => $step->name, $steps))->toBe(['quiesce', 'container-processes', 'restore', 'finish'])
            ->and($steps[2]->status)->toBe('succeeded')->and($steps[2]->finished_at)->toBe($finished);
    } finally {
        app(SwampConnection::class)->release();
    }
})->with([
    'step failure' => ['step failure', 'failed', false],
    'step timeout' => ['step timeout', 'failed', false],
    'native step failure after expiry' => ['late step failure', 'failed', true],
    'deadline-caused native cancellation with restored end state' => ['deadline-only', 'cancelled', true],
    'Operation read after deadline and confirmed cancel without a target lock' => ['deadline', 'cancelled', true],
    'confirmed cancel' => ['cancel', 'cancelled', false],
    'lost worker' => ['lost worker', 'interrupted', false],
]);

it('renders FrankenPHP after the container network target without waiting for network-online', function (): void {
    $instance = migrateCheckout(containerDefaultProject(ProjectType::LaravelApp, '8.5'), containerDefaultNode());
    $operation = migrateStart($instance);
    $content = $operation->input['container']['units'][0]['content'];
    expect($content)->toContain("After=network.target\n")->not->toContain('network-online.target');
});

it('review late restored history must preserve a newer migrate reservation', function (): void {
    $exit = 'deadline';
    $nativeStatus = 'cancelled';
    $expired = true;
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $instance->update(['provisioning_step' => 'active']);
    $worker = migrationWorker($instance);
    $operation = migrateStart($instance);
    $run = '0c69f6e8-bc50-4819-bcab-f3972557d2cc';
    $started = now()->subHours(2)->toISOString();
    $finished = now()->toISOString();
    $operation->forceFill(['status' => 'running', 'attempts' => 1, 'started_at' => $started,
        'finished_at' => null, 'error_code' => null, 'state' => ['swamp_run_id' => $run]])->save();
    if ($expired) {
        app(ExpireOperationAction::class)->execute($operation);
        $operation->refresh()->update(['lock_key' => null, 'state' => [...$operation->state,
            'native_cancel_confirmed_at' => now()->toISOString()]]);
    }
    $retry = migrateStart($instance);
    expect($retry->id)->not->toBe($operation->id);
    $reservedId = $instance->runtime()->firstOrFail()->id;
    $result = ['instance_id' => $instance->id, 'moved' => false, 'restored' => true, 'ready' => false,
        'failed_step' => 'processes', 'error_code' => $exit === 'deadline-only' ? 'operation.timed_out' : 'instance.migrate_processes_failed'];
    $nativeResult = $result;
    if ($exit === 'deadline-only') {
        $nativeResult['error_code'] = 'instance.migrate_processes_failed';
    }
    $history = ['id' => $run, 'workflowName' => 'operation-instance-migrate', 'status' => $nativeStatus,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'instance.migrate'],
        'startedAt' => $started, 'completedAt' => $finished, 'jobs' => [['name' => 'migrate', 'steps' => [
            ['name' => 'quiesce', 'status' => 'succeeded', 'startedAt' => $started, 'completedAt' => $started],
            ['name' => 'container-processes', 'status' => 'failed', 'startedAt' => $started, 'completedAt' => $finished,
                'error' => $exit === 'step timeout' ? 'Command exited with code 124' : $exit],
            ['name' => 'restore', 'status' => 'succeeded', 'startedAt' => $finished, 'completedAt' => $finished],
            ['name' => 'finish', 'status' => 'succeeded', 'startedAt' => $finished, 'completedAt' => $finished,
                'outputs' => ['stdout' => json_encode($nativeResult, JSON_THROW_ON_ERROR)]],
        ]]]];
    $frame = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $message) use (&$frame): void {
        $frame = $message;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frame, $history): array {
        return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $frame['type'] === 'workflow.history.get' ? $history : ['results' => []]]]];
    });
    app(SwampConnection::class)->release();
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'read-token']);
    try {
        if ($expired) {
            // Exercise the production selector after cancellation is confirmed, with no target lock.
            $projected = app(ReadOperationAction::class)->execute($operation->refresh());
        } else {
            $projected = app(SwampCommandStep::class)->projectTerminal($operation->refresh(), ['id' => $run],
                ['job' => 'migrate', 'step' => 'finish'], atDeadline: true);
        }

        expect($projected->result)->toBe($result)
            ->and($projected->error_code)->toBe($result['error_code'])
            ->and($projected->lock_key)->toBeNull()
            ->and($instance->runtime()->firstOrFail()->id)->toBe($reservedId)
            ->and($instance->runtime()->firstOrFail()->operation_id)->toBe($retry->id)
            ->and($worker->refresh()->systemd_activation_hash)->toBe('old-host-hash')
            ->and($instance->runtime()->firstOrFail()->seed_commit)->toBeNull();
        $steps = OperationData::summary($projected)->steps;
        expect(array_map(static fn ($step) => $step->name, $steps))->toBe(['quiesce', 'container-processes', 'restore', 'finish'])
            ->and($steps[2]->status)->toBe('succeeded')->and($steps[2]->finished_at)->toBe($finished);
    } finally {
        app(SwampConnection::class)->release();
    }
});

it('settles the real pinned decoder receipt with native abort or independent phase failure evidence', function (string $mode, bool $expiry, bool $userCancel, string $status, ?string $code, bool $cancelAfterFailure = false, bool $expireDuringRead = false): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = migrateStart($instance);
    $history = json_decode((string) file_get_contents(base_path('tests/Fixtures/instance-migrate/pinned-'.$mode.'.json')), true, flags: JSON_THROW_ON_ERROR);
    if ($cancelAfterFailure) {
        $history['status'] = 'cancelled';
        $history['jobs'][0]['steps'][] = ['name' => 'later-phase', 'status' => 'failed',
            'error' => 'Command exited with code -1', 'completedAt' => now()->toISOString()];
    }
    $run = $history['id'];
    $history['workflowName'] = 'operation-instance-migrate';
    $history['inputs'] = ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'instance.migrate'];
    $history['startedAt'] = now()->subHours(2)->toISOString();
    $history['completedAt'] = now()->toISOString();
    $operation->forceFill(['status' => 'running', 'attempts' => 1, 'started_at' => $history['startedAt'],
        'cancel_requested_at' => $userCancel ? now()->subMinute() : null, 'state' => ['swamp_run_id' => $run]])->save();
    if ($expiry) {
        app(ExpireOperationAction::class)->execute($operation);
    }
    $native = json_decode($history['jobs'][0]['steps'][array_search('finish', array_column($history['jobs'][0]['steps'], 'name'))]['outputs']['stdout'], true, flags: JSON_THROW_ON_ERROR);
    expect($native['error_code'])->toBe('instance.migrate_processes_failed');
    $frame = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $message) use (&$frame): void {
        $frame = $message;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frame, $history, $operation, $expireDuringRead): array {
        if ($expireDuringRead && $frame['type'] === 'workflow.history.get') {
            app(ExpireOperationAction::class)->execute($operation->refresh());
        }

        return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $frame['type'] === 'workflow.history.get' ? $history : ['results' => []]]]];
    });
    app(SwampConnection::class)->release();
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'read-token']);
    try {
        $settled = app(SwampCommandStep::class)->projectTerminal($operation->refresh(), ['id' => $run], ['job' => 'migrate', 'step' => 'finish']);
        expect($settled->status->value)->toBe($status)->and($settled->error_code)->toBe($code)
            ->and($settled->result['restored'])->toBeTrue()->and($settled->result['moved'])->toBeFalse()
            ->and($instance->runtime()->exists())->toBeFalse();
        if ($mode === 'cancel' && $code !== 'operation.timed_out') {
            expect($settled->result)->toBe($native);
        }
    } finally {
        app(SwampConnection::class)->release();
    }
})->with([
    'expiry during history read' => ['cancel', false, false, 'failed', 'operation.timed_out', false, true],
    'user cancellation before expiry during history read' => ['cancel', false, true, 'cancelled', null, false, true],
    'deadline abort' => ['cancel', true, false, 'failed', 'operation.timed_out'],
    'user abort' => ['cancel', false, true, 'cancelled', null],
    'user abort before expiry' => ['cancel', true, true, 'cancelled', null],
    'independent exit 42' => ['failure', true, false, 'failed', 'instance.migrate_processes_failed'],
    'independent exit 124' => ['timeout', true, false, 'failed', 'instance.migrate_processes_failed'],
    'independent failure before later cancel' => ['failure', true, false, 'failed', 'instance.migrate_processes_failed', true],
]);

it('fences a direct stale migrate projector by the runtime owner under the Instance lock', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $old = migrateStart($instance);
    $old->forceFill(['status' => 'running', 'attempts' => 1, 'started_at' => now()->subHours(2), 'state' => ['swamp_run_id' => 'old-root']])->save();
    app(ExpireOperationAction::class)->execute($old);
    $old->refresh()->update(['lock_key' => null]);
    $new = migrateStart($instance);
    $runtime = $instance->runtime()->firstOrFail();

    $settled = app(InstanceMigrateProjection::class)->apply($old->refresh(), ['run_id' => 'old-root', 'status' => 'failed',
        'started_at' => now()->subHours(2)->toISOString(), 'finished_at' => now()->toISOString(),
        'result' => ['moved' => false, 'restored' => true, 'ready' => false]]);

    expect($settled->result)->toBe(['moved' => false, 'restored' => true, 'ready' => false])
        ->and($instance->runtime()->firstOrFail()->id)->toBe($runtime->id)
        ->and($runtime->refresh()->operation_id)->toBe($new->id);
});

it('backfills the owner of an existing migrate runtime from the latest admission', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = migrateStart($instance);
    $migration = require database_path('migrations/2027_02_04_000002_add_operation_owner_to_instance_runtimes.php');
    $migration->down();
    $migration->up();

    expect($instance->runtime()->firstOrFail()->operation_id)->toBe($operation->id);
});

it('adversarial late moved migration does not hold a write transaction during the vault read', function (bool $expireBeforeRead, string $nativeStatus): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    migrateMovedDefault($project, $node);
    $instance = migrateCheckout($project, $node, 'feature');
    $operation = migrateStart($instance);
    $t3 = migrateServer($instance);
    $root = '0c69f6e8-bc50-4819-bcab-f3972557d2cc';
    $operation->update(['status' => 'running', 'attempts' => 1, 'started_at' => now()->subHours(2),
        'state' => [...$operation->state, 'swamp_run_id' => $root]]);
    if ($expireBeforeRead) {
        app(ExpireOperationAction::class)->execute($operation);
    }
    $operation->refresh();
    $result = ['instance_id' => $instance->id, 'moved' => true, 'restored' => false, 'ready' => true,
        'commit' => str_repeat('e', 40), 't3' => $t3];
    $history = ['id' => $root, 'workflowName' => 'operation-instance-migrate', 'status' => $nativeStatus,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $node->id, 'kind' => 'instance.migrate'],
        'startedAt' => now()->subHours(2)->toISOString(), 'completedAt' => now()->toISOString(),
        'jobs' => [['name' => 'migrate', 'steps' => [['name' => 'finish', 'status' => 'succeeded',
            'outputs' => ['stdout' => json_encode($result, JSON_THROW_ON_ERROR)]]]]]];
    if ($nativeStatus === 'cancelled') {
        array_unshift($history['jobs'][0]['steps'], ['name' => 'retire', 'status' => 'failed',
            'error' => 'Command exited with code -1', 'outputs' => ['exitCode' => -1]]);
    }
    $frame = [];
    $level = null;
    $baseline = DB::transactionLevel();
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $message) use (&$frame): void {
        $frame = $message;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frame, &$level, $history, $operation, $expireBeforeRead): array {
        if ($frame['type'] === 'vault.read-secret') {
            $level = DB::transactionLevel();
            if (! $expireBeforeRead) {
                app(ExpireOperationAction::class)->execute($operation->refresh());
            }
            $value = ['vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['secretKey'],
                'vaultType' => 'local_encryption', 'value' => 'gateway-session-value'];
        } else {
            $value = $frame['type'] === 'workflow.history.get' ? $history : ['results' => []];
        }

        return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $value]]];
    });
    app(SwampConnection::class)->release();
    app()->instance(WebSocketClient::class, $socket);
    app()->forgetInstance(SwampVaultExecutionCopy::class);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'read-token', 'swamp.run_token' => 'run-token']);
    try {
        $settled = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $root], ['job' => 'migrate', 'step' => 'finish']);
        expect($settled->status->value)->toBe($nativeStatus === 'cancelled' ? 'failed' : 'succeeded')
            ->and($settled->error_code)->toBe($nativeStatus === 'cancelled' ? 'operation.timed_out' : null)
            ->and($settled->state['native_timeout']['native_terminal_status'])->toBe($nativeStatus);
        expect($level)->toBe($baseline);
    } finally {
        app(SwampConnection::class)->release();
    }
})->with([
    'expired before vault read' => [true, 'succeeded'],
    'expires during vault read' => [false, 'succeeded'],
    'expires during a cancellation receipt vault read' => [false, 'cancelled'],
]);

it('review an already settled pre-fix timeout keeps a stale runtime but permits a new migrate admission', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $worker = migrationWorker($instance);
    $old = migrateStart($instance);
    $old->update(['status' => 'failed', 'finished_at' => now()->subHours(2), 'error_code' => 'operation.timed_out',
        'lock_key' => null, 'result' => ['error_code' => 'operation.timed_out'],
        'state' => ['native_timeout' => ['native_terminal_status' => 'cancelled']]]);
    $runtime = $instance->runtime()->firstOrFail();
    $runtime->update(['operation_id' => null, 'power_state' => 'stopped', 'seed_commit' => null]);
    $migration = require database_path('migrations/2027_02_04_000002_add_operation_owner_to_instance_runtimes.php');
    $migration->down();
    $migration->up();
    expect($runtime->refresh()->operation_id)->toBe($old->id)
        ->and($instance->runtime()->exists())->toBeTrue()
        ->and(app(ProcessTargetResolver::class)->forInspection($worker)->container)->not->toBeNull();
    $retry = migrateStart($instance);
    expect($retry->id)->toBeGreaterThan($old->id)
        ->and($retry->status->value)->toBe('queued')
        ->and($retry->input['had_runtime_reservation'])->toBeTrue()
        ->and($instance->runtime()->firstOrFail()->id)->toBe($runtime->id)
        ->and($runtime->refresh()->operation_id)->toBe($retry->id);
});

function demandMigrate(Instance $instance): Operation
{
    $operation = migrateStart($instance);
    $operation->update(['status' => 'failed', 'error_code' => 'operation.timed_out', 'attempts' => 1,
        'started_at' => now()->subHours(2), 'finished_at' => now()->subHour(), 'lock_key' => null,
        'state' => ['swamp_run_id' => '0c69f6e8-bc50-4819-bcab-f3972557d2cc',
            'native_timeout' => ['budget_seconds' => 5100, 'deadline' => now()->subMinutes(35)->toISOString(), 'native_terminal_status' => 'unproven'],
            'native_timeout_restore' => ['cancel_requested' => false, 'native_failure' => false],
            'native_cancel_confirmed_at' => now()->subHour()->toISOString()]]);

    return $operation;
}

function demandMigrateHistory(Operation $operation, Closure $read): void
{
    $result = ['instance_id' => $operation->target_id, 'moved' => false, 'restored' => true, 'ready' => false,
        'error_code' => 'instance.migrate_processes_failed'];
    $history = ['id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-instance-migrate', 'status' => 'cancelled',
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'instance.migrate'],
        'startedAt' => now()->subHours(2)->toISOString(), 'completedAt' => now()->toISOString(),
        'jobs' => [['name' => 'migrate', 'steps' => [
            ['name' => 'container-processes', 'status' => 'failed', 'error' => 'Command exited with code -1'],
            ['name' => 'finish', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode($result, JSON_THROW_ON_ERROR)]],
        ]]]];
    $frame = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $message) use (&$frame): void {
        $frame = $message;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frame, $history, $read): array {
        if ($frame['type'] === 'workflow.history.get') {
            $read();
        }

        return [['id' => $frame['id'], 'type' => $frame['type'],
            'payload' => ['data' => $frame['type'] === 'workflow.history.get' ? $history : ['results' => []]]]];
    });
    app(SwampConnection::class)->release();
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'read-token']);
}

it('does not select released overdue migrates in the shared reconciler', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = demandMigrate($instance);
    for ($i = 0; $i < 8; $i++) {
        $operation->replicate()->save();
    }
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldNotReceive('connect', 'send');
    app(SwampConnection::class)->release();
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'read-token']);
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect($operation->refresh()->state)->not->toHaveKey('native_reconcile')
        ->and($operation->error_code)->toBe('operation.timed_out')
        ->and($instance->runtime()->exists())->toBeTrue();
});

it('settles an overdue migrate from native finish when operation show reads it', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = demandMigrate($instance);
    $reads = 0;
    $baseline = DB::transactionLevel();
    demandMigrateHistory($operation, function () use (&$reads, $baseline): void {
        $reads++;
        expect(DB::transactionLevel())->toBe($baseline);
    });
    $settled = app(ReadOperationAction::class)->execute($operation);
    expect($settled->result['restored'])->toBeTrue()
        ->and($settled->error_code)->toBe('operation.timed_out')
        ->and($instance->runtime()->exists())->toBeFalse()->and($reads)->toBe(1);
});

it('settles the previous overdue migrate before the next migrate reserves its runtime', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $old = demandMigrate($instance);
    $runtime = $instance->runtime()->firstOrFail()->id;
    $reads = 0;
    $baseline = DB::transactionLevel();
    demandMigrateHistory($old, function () use (&$reads, $baseline): void {
        $reads++;
        expect(DB::transactionLevel())->toBe($baseline);
    });
    $next = migrateStart($instance);
    expect($old->refresh()->result['restored'])->toBeTrue()
        ->and($instance->runtime()->firstOrFail()->id)->not->toBe($runtime)
        ->and($instance->runtime()->firstOrFail()->operation_id)->toBe($next->id)
        ->and($reads)->toBe(1);
});

it('returns the stored overdue migrate when the shared read allowance is empty', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = demandMigrate($instance);
    $budget = app(SwampReadBudget::class);
    for ($i = 0; $i < SwampReadBudget::PerMinute; $i++) {
        expect($budget->take('other-kind:'.$i, 30))->toBeTrue();
    }
    $before = $operation->getAttributes();
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldNotReceive('connect', 'send');
    app(SwampConnection::class)->release();
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'read-token']);
    $stored = app(ReadOperationAction::class)->execute($operation);
    expect($stored->getAttributes())->toBe($before)
        ->and($operation->refresh()->getAttributes())->toBe($before)
        ->and($instance->runtime()->exists())->toBeTrue();
/** @param array<string,mixed> $attributes */
function migrationSchedule(Instance $instance, array $attributes = [], ?ScheduleRuntimeAccount $account = null): Schedule
{
    $instance->project->update(['php_version' => '8.5']);
    app()->instance(ScheduleRuntimeAccountResolver::class, new class($account) implements ScheduleRuntimeAccountResolver
    {
        public function __construct(private ?ScheduleRuntimeAccount $account) {}

        public function resolve(Node $node, string $user): ScheduleRuntimeAccount
        {
            return $this->account ?? new ScheduleRuntimeAccount($user, $user, '/home/'.$user, '/bin/bash');
        }
    });

    return Schedule::query()->create([
        'target_type' => 'instance', 'target_id' => $instance->id, 'host_node_id' => $instance->node_id,
        'name' => 'nightly', 'calendar' => '*-*-* 03:00:00', 'command' => 'php artisan schedule:run', 'timeout_seconds' => 900,
        'desired_timer_state' => 'enabled', 'status' => 'active', 'trigger' => 'node',
        'last_run_at' => '2026-11-08 03:00:00', 'last_run_status' => 'success', ...$attributes,
    ]);
}

describe('migrate registered Schedule timers', function (): void {
    beforeEach(function (): void {
        $this->freezeTime();
    });
    it('keeps enabled and paused Schedule identity, calendar, timeout and history at cutover', function (string $desired): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $schedule = migrationSchedule($instance, ['command' => 'php '.$instance->checkout_path.'/artisan schedule:run', 'desired_timer_state' => $desired]);
        $before = $schedule->refresh()->getAttributes();

        $operation = migrateStart($instance);
        $plan = $operation->input['migrate']['schedules'][0];

        expect($plan)->toMatchArray(['id' => $schedule->id, 'name' => 'nightly', 'source_directory' => $instance->checkout_path,
            'directory' => '/srv/workspace', 'user' => 'orbit', 'group' => 'orbit', 'home' => '/home/orbit', 'shell' => '/bin/bash',
            'container_user' => 'orbit', 'calendar' => '*-*-* 03:00:00', 'timeout_seconds' => 900, 'desired' => $desired,
            'mapped_command_hash' => hash('sha256', 'php /srv/workspace/artisan schedule:run')]);
        expect($plan['source_command_paths'])->toBe([$instance->checkout_path.'/artisan']);
        $script = "# X-Orbit-Schedule-ID={$schedule->id}\n# X-Orbit-Execution-Context=".json_encode([
            'user' => 'orbit', 'group' => 'orbit', 'home' => '/home/orbit', 'working_directory' => $instance->checkout_path,
            'shell' => '/bin/bash', 'login_shell' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n".$schedule->command."\n";
        expect($plan['source_script_hash'])->toBe(hash('sha256', $script));
        expect(json_encode($operation->input, JSON_THROW_ON_ERROR))->not->toContain('php '.$instance->checkout_path.'/artisan');
        expect($schedule->refresh()->command)->toBe($before['command']);

        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);

        expect($schedule->refresh()->command)->toBe('php /srv/workspace/artisan schedule:run');
        expect(array_diff_assoc($schedule->getAttributes(), $before))->toBe(['command' => 'php /srv/workspace/artisan schedule:run']);
    })->with(['enabled', 'disabled']);

    it('keeps source Schedule intent after rollback', function (string $desired): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $schedule = migrationSchedule($instance, ['command' => 'php '.$instance->checkout_path.'/artisan schedule:run', 'desired_timer_state' => $desired]);
        $before = $schedule->refresh()->getAttributes();
        $operation = migrateStart($instance);

        migrateEnd($operation, 'failed', ['ready' => false, 'moved' => false, 'restored' => true,
            'failed_step' => 'files', 'error_code' => 'instance.migrate_files_failed']);

        expect($schedule->refresh()->getAttributes())->toBe($before);
        expect($instance->runtime()->exists())->toBeFalse();
    })->with(['enabled', 'disabled']);

    it('records a persistent timer fire during migration and replays it after admission reopens', function (bool $moved): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['provisioning_step' => 'active']);
        $schedule = migrationSchedule($instance, ['command' => 'php '.$instance->checkout_path.'/artisan schedule:run']);
        $source = $instance->checkout_path;
        $migration = migrateStart($instance);
        $key = 'systemd-invocation-id';
        $admit = fn (): Operation => app(OperationIdempotency::class)->under('schedule.run', $key, 'timer-fingerprint',
            fn (): Operation => app(RunScheduleAction::class)->admit($schedule, $instance->node));

        $fire = $admit();
        expect($fire->status)->toBe(OperationStatus::Waiting)
            ->and($fire->input)->toMatchArray(['id' => $schedule->id, 'deferred_instance_migration' => $migration->id]);
        expect($admit()->id)->toBe($fire->id);
        expect(Operation::query()->where('kind', 'schedule.run')->count())->toBe(1);
        Queue::assertNotPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id);

        $this->travel(11)->minutes();
        expect(app(ExpireOperationAction::class)->execute($fire)->status)->toBe(OperationStatus::Waiting);

        migrateEnd($migration, $moved ? 'succeeded' : 'failed', $moved
            ? ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]
            : ['ready' => false, 'moved' => false, 'restored' => true, 'failed_step' => 'files', 'error_code' => 'instance.migrate_files_failed']);

        $fire->refresh();
        expect($fire->status)->toBe(OperationStatus::Queued)
            ->and($fire->idempotency_key)->toBe($key)
            ->and($fire->input['working_directory'])->toBe($moved ? '/srv/workspace' : $source)
            ->and($fire->input['command_hash'])->toBe(hash('sha256', 'php '.($moved ? '/srv/workspace' : $source).'/artisan schedule:run'))
            ->and(isset($fire->input['container']))->toBe($moved);
        expect($fire->started_at?->toDateTimeString())->toBe(now()->toDateTimeString());
        expect(InstanceMigration::running($instance))->toBeFalse();
        Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id);
    })->with(['cutover' => true, 'rollback' => false]);

    it('records the deferred fire failure if rollback leaves admission closed', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['provisioning_step' => 'active']);
        $schedule = migrationSchedule($instance);
        $migration = migrateStart($instance);
        $fire = app(RunScheduleAction::class)->admit($schedule, $instance->node);

        migrateEnd($migration, 'failed', ['ready' => false, 'moved' => false, 'restored' => false,
            'failed_step' => 'restore', 'error_code' => 'instance.migrate_restore_failed']);

        expect($fire->refresh()->status)->toBe(OperationStatus::Failed)
            ->and($fire->error_code)->toBe('instance.lifecycle_busy')
            ->and($schedule->refresh()->last_run_status->value)->toBe('error');
        Queue::assertNotPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id);
    });

    it('replays a deferred fire after a definite migration start refusal', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['provisioning_step' => 'active']);
        $schedule = migrationSchedule($instance);
        $migration = migrateStart($instance);
        $fire = app(RunScheduleAction::class)->admit($schedule, $instance->node);

        app(StartSwampRun::class)->refuse($migration, new ResourceOperationException('swamp.start_enqueue_failed', 'No run started.', 503));

        expect($fire->refresh()->status)->toBe(OperationStatus::Queued)
            ->and($fire->input['working_directory'])->toBe($instance->checkout_path);
        Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id);
    });

    it('records extensionless interpreter operands as paths for remote containment checks', function (string $command, string $path): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        migrationSchedule($instance, ['command' => $command]);

        $plan = migrateStart($instance)->input['migrate']['schedules'][0];

        expect($plan['source_command_paths'])->toBe([$instance->checkout_path.'/'.$path]);
    })->with(['php worker' => ['php worker', 'worker'], 'node worker' => ['node worker', 'worker'],
        'bun script path' => ['bun scripts/worker', 'scripts/worker']]);

    it('refuses unsupported Schedule intent before reserving a runtime', function (string $change, string $code, string $reason): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $account = match ($change) {
            'shell' => new ScheduleRuntimeAccount('orbit', 'orbit', '/home/orbit', '/bin/zsh'),
            'group' => new ScheduleRuntimeAccount('orbit', 'work', '/home/orbit', '/bin/bash'),
            'home' => new ScheduleRuntimeAccount('orbit', 'orbit', '/opt/orbit', '/bin/bash'),
            default => null,
        };
        $schedule = migrationSchedule($instance, [], $account);
        match ($change) {
            'native' => $schedule->update(['trigger' => 'swamp']),
            'failed' => $schedule->update(['status' => 'failed']),
            'removing' => $schedule->update(['status' => 'removing']),
            'other Node' => $schedule->update(['host_node_id' => containerDefaultNode('other', '10.44.0.8')->id]),
            'user' => $instance->node->update(['user' => 'nick']),
            'working directory' => $instance->update(['source_is_laravel' => true, 'root' => '../external/public']),
            'external command' => $schedule->update(['command' => '/opt/private/run-scheduler']),
            'sibling command' => $schedule->update(['command' => 'php '.$instance->checkout_path.'-other/artisan schedule:run']),
            'traversal command' => $schedule->update(['command' => 'php '.$instance->checkout_path.'/../other/artisan schedule:run']),
            'relative traversal' => $schedule->update(['command' => '../private/run-scheduler']),
            'nested relative traversal' => $schedule->update(['command' => 'php scripts/../../private/run.php']),
            'filesystem root' => $schedule->update(['command' => 'cd /; php script.php']),
            'dynamic command' => $schedule->update(['command' => 'php "$APP_ROOT/artisan" schedule:run']),
            'assignment prefix' => $schedule->update(['command' => 'X='.$instance->checkout_path.' host-only-tool']),
            'ambiguous interpreter' => $schedule->update(['command' => 'php -d auto_prepend_file=worker main']),
            'ambiguous bun' => $schedule->update(['command' => 'bun run worker']),
            'unknown executable' => $schedule->update(['command' => 'my-host-only-command']),
            'compound command' => $schedule->update(['command' => 'true && php artisan schedule:run']),
            'pipeline' => $schedule->update(['command' => 'php artisan schedule:run | echo done']),
            'shell invocation' => $schedule->update(['command' => 'bash -c "php artisan schedule:run"']),
            'inline evaluation' => $schedule->update(['command' => 'php -r "exit(0)"']),
            'inline node' => $schedule->update(['command' => 'node --eval=process.exit']),
            'external flag' => $schedule->update(['command' => 'php artisan --file=/etc/private/config']),
            'missing PHP' => $instance->project->update(['php_version' => null]),
            'unavailable PHP' => $instance->project->update(['php_version' => '99.0']),
            'literal path list' => $schedule->update(['command' => 'php artisan --config='.$instance->checkout_path.'/config:/etc/private']),
            'inline PHP long' => $schedule->update(['command' => 'php --run=die']),
            'shell', 'group', 'home' => null,
        };

        try {
            migrateStart($instance);
            $this->fail('The unsupported Schedule was admitted.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe($code);
            expect($exception->getMessage())->toContain($schedule->id, $schedule->name, $reason);
        }

        expect($instance->runtime()->exists())->toBeFalse();
        expect(Operation::query()->count())->toBe(0);
    })->with([
        'native trigger' => ['native', 'schedule.container_trigger_unsupported', 'native workflow'],
        'failed Schedule' => ['failed', 'schedule.container_state_unsupported', 'not active'],
        'removing Schedule' => ['removing', 'schedule.container_state_unsupported', 'not active'],
        'different host Node' => ['other Node', 'schedule.container_state_unsupported', 'this Node'],
        'missing container user' => ['user', 'schedule.container_account_unsupported', 'user'],
        'different group' => ['group', 'schedule.container_account_unsupported', 'group'],
        'different home' => ['home', 'schedule.container_account_unsupported', 'home'],
        'different shell' => ['shell', 'schedule.container_shell_unsupported', 'shell'],
        'external working directory' => ['working directory', 'schedule.container_path_unsupported', 'working directory'],
        'external executable' => ['external command', 'schedule.container_command_unsupported', 'absolute path'],
        'checkout prefix sibling' => ['sibling command', 'schedule.container_command_unsupported', 'absolute path'],
        'checkout traversal' => ['traversal command', 'schedule.container_command_unsupported', 'dot-segment'],
        'relative traversal' => ['relative traversal', 'schedule.container_command_unsupported', 'dot-segment'],
        'nested traversal' => ['nested relative traversal', 'schedule.container_command_unsupported', 'dot-segment'],
        'filesystem root' => ['filesystem root', 'schedule.container_command_unsupported', 'absolute path'],
        'shell expansion' => ['dynamic command', 'schedule.container_command_unsupported', 'shell expansion'],
        'assignment prefix' => ['assignment prefix', 'schedule.container_command_unsupported', 'assignment prefix'],
        'interpreter options' => ['ambiguous interpreter', 'schedule.container_command_unsupported', 'direct script operand'],
        'bun subcommand' => ['ambiguous bun', 'schedule.container_command_unsupported', 'direct script operand'],
        'unknown executable' => ['unknown executable', 'schedule.container_command_unsupported', 'bare executable'],
        'compound invocation' => ['compound command', 'schedule.container_command_unsupported', 'one literal invocation'],
        'pipeline' => ['pipeline', 'schedule.container_command_unsupported', 'one literal invocation'],
        'shell invocation' => ['shell invocation', 'schedule.container_command_unsupported', 'bare executable'],
        'inline evaluation' => ['inline evaluation', 'schedule.container_command_unsupported', 'one literal invocation'],
        'inline node evaluation' => ['inline node', 'schedule.container_command_unsupported', 'inline evaluation'],
        'external flag path' => ['external flag', 'schedule.container_command_unsupported', 'absolute path'],
        'missing selected PHP' => ['missing PHP', 'schedule.container_command_unsupported', 'selected PHP'],
        'unavailable selected PHP' => ['unavailable PHP', 'schedule.container_command_unsupported', 'selected PHP'],
        'literal path list' => ['literal path list', 'schedule.container_command_unsupported', 'one literal workspace path'],
        'inline PHP long option' => ['inline PHP long', 'schedule.container_command_unsupported', 'inline evaluation'],
    ]);

    it('carries literal workspace commands, quoted arguments, flags and null redirects', function (string $form): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $command = match ($form) {
            'absolute' => $instance->checkout_path.'/bin/scheduler "nightly report" --config='.$instance->checkout_path.'/config/jobs.json > /dev/null 2>&1',
            'relative' => 'bin/scheduler worker.php --config=config/jobs.json >> /dev/null',
        };
        migrationSchedule($instance, ['command' => $command]);
        $plan = migrateStart($instance)->input['migrate']['schedules'][0];

        expect($plan['source_command_paths'])->toBe(match ($form) {
            'absolute' => [$instance->checkout_path.'/bin/scheduler', $instance->checkout_path.'/config/jobs.json'],
            'relative' => [$instance->checkout_path.'/bin/scheduler', $instance->checkout_path.'/worker.php', $instance->checkout_path.'/config/jobs.json'],
        });
        expect($plan['mapped_command_hash'])->toBe(hash('sha256', str_replace($instance->checkout_path, '/srv/workspace', $command)));
    })->with(['absolute', 'relative']);

    it('refuses an unfinished Schedule run before downtime', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $schedule = migrationSchedule($instance);
        Operation::query()->create(['kind' => 'schedule.run', 'target_type' => 'instance_schedules', 'target_id' => $instance->id,
            'node_id' => $instance->node_id, 'status' => 'running', 'input' => ['id' => $schedule->id]]);

        expect(containerRefusal(fn () => migrateStart($instance)))->toBe('schedule.container_state_unsupported');
        expect($instance->runtime()->exists())->toBeFalse();
        expect(Operation::query()->where('kind', 'instance.migrate')->exists())->toBeFalse();
    });

    it('refuses Schedule changes while migration owns the Instance', function (string $verb): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $schedule = migrationSchedule($instance);
        migrateStart($instance);

        expect(containerRefusal(fn () => app(ScheduleOperationAdmission::class)->inputFor($schedule, $verb)))->toBe('instance.lifecycle_busy');
    })->with(['run', 'enable', 'destroy', 'create']);

    it('refuses a new Schedule while migration owns its Instance', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        migrateStart($instance);

        expect(containerRefusal(fn () => app(CreateScheduleAction::class)->admit(
            new AddScheduleData(ScheduleTargetType::Instance, $instance->id,
                'new-scheduler', '* * * * *', 'php artisan schedule:run'),
        )))->toBe('instance.lifecycle_busy');
        expect(Schedule::query()->exists())->toBeFalse();
        expect(Operation::query()->where('kind', 'schedule.create')->exists())->toBeFalse();
    });

    it('names the Schedule when its source account is unavailable', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $schedule = migrationSchedule($instance);
        app()->instance(ScheduleRuntimeAccountResolver::class, new class implements ScheduleRuntimeAccountResolver
        {
            public function resolve(Node $node, string $user): ScheduleRuntimeAccount
            {
                throw new ResourceOperationException('schedule.target_unavailable', 'No current account fact.');
            }
        });

        try {
            migrateStart($instance);
            $this->fail('The unavailable account was admitted.');
        } catch (ResourceOperationException $error) {
            expect($error->errorCode)->toBe('schedule.container_account_unsupported');
            expect($error->getMessage())->toContain($schedule->id, $schedule->name, 'account is unavailable');
        }
        expect($instance->runtime()->exists())->toBeFalse();
    });

    it('runs a moved Schedule in its container while its host timer and journal retain their identity', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['source_is_laravel' => true, 'root' => 'apps/site/public']);
        $schedule = migrationSchedule($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $instance->refresh()->update(['provisioning_step' => 'active']);
        app()->instance(ScheduleRuntimeAccountResolver::class, new class implements ScheduleRuntimeAccountResolver
        {
            public function resolve(Node $node, string $user): ScheduleRuntimeAccount
            {
                throw new LogicException('A container Schedule must not read a host account fact.');
            }
        });

        $admission = app(ScheduleOperationAdmission::class);
        $input = $admission->inputFor($schedule->refresh(), 'run');
        $destroy = $admission->inputFor($schedule, 'destroy');
        $operation = new Operation;
        $operation->forceFill(['id' => 123, 'kind' => 'schedule.run', 'status' => 'running', 'target_type' => 'instance_schedules',
            'target_id' => $instance->id, 'node_id' => $instance->node_id, 'input' => $input]);
        $native = app(ScheduleControlProjection::class)->input($operation);

        expect($input)->toMatchArray(['working_directory' => '/srv/workspace/apps/site', 'user' => 'orbit', 'shell' => '/bin/bash',
            'home' => '/home/orbit', 'group' => 'orbit', 'container' => ['project' => 'orbit-instances', 'name' => 'instance-'.$instance->id]]);
        expect($destroy['working_directory'])->toBe('/');
        expect($native['intent']['container'])->toBe($input['container']);
        expect(containerRefusal(fn () => app(LogStreamTargetResolver::class)->forSchedule($schedule)))->toBe('schedule.target_unsupported');
    });
    it('records later runs on the same Schedule after migration', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $schedule = migrationSchedule($instance, ['desired_timer_state' => 'disabled']);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $instance->refresh()->update(['provisioning_step' => 'active']);
        $input = app(ScheduleOperationAdmission::class)->inputFor($schedule->refresh(), 'run');
        $operation = Operation::query()->create(['kind' => 'schedule.run', 'target_type' => 'instance_schedules', 'target_id' => $instance->id,
            'node_id' => $instance->node_id, 'status' => 'running', 'input' => $input, 'state' => ['swamp_run_id' => 'schedule-run-1']]);

        app(ScheduleControlProjection::class)->apply($operation, ['status' => 'succeeded', 'run_id' => 'schedule-run-1',
            'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String()]);

        expect($schedule->refresh()->last_run_status->value)->toBe('success');
        expect($schedule->last_run_at?->toIso8601String())->toBe(now()->toIso8601String());
        expect($schedule->desired_timer_state->value)->toBe('disabled');
        expect($operation->refresh()->status->value)->toBe('succeeded');
    });
});

/** A Schedule journal read's real event, history and sensitive-value protocol, without contacting Swamp. */
function migrationScheduleLogSocket(?string $fault = null): object
{
    $socket = new class($fault) implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $sent = [];

        private string $request = '';

        private const string Run = '123e4567-e89b-42d3-a456-426614174000';

        public function __construct(private ?string $fault) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->sent[] = $message;
            if ($message['type'] === 'workflow.run') {
                $this->request = $message['payload']['inputs']['intent']['read']['request'];
            }
        }

        public function receive(float $timeoutSeconds): array
        {
            $message = end($this->sent);
            $type = $message['type'];
            if ($type === 'workflow.run') {
                return [['id' => $message['id'], 'type' => 'event', 'event' => ['kind' => 'completed', 'run' => [
                    'id' => self::Run, 'workflowName' => 'operation-process-container', 'status' => $this->fault === 'failed run' ? 'failed' : 'succeeded',
                ]]]];
            }
            if ($type === 'workflow.history.get') {
                $request = $this->fault === 'wrong request' ? self::Run : $this->request;
                $keyRequest = $this->fault === 'wrong key' ? self::Run : $this->request;

                return [['id' => $message['id'], 'type' => $type, 'payload' => ['data' => [
                    'id' => self::Run, 'workflowName' => 'operation-process-container', 'status' => 'succeeded',
                    'inputs' => ['kind' => 'schedule.inspect', 'intent' => ['read' => ['request' => $request]]],
                    'jobs' => [['name' => 'process', 'steps' => [['name' => 'Read Process result', 'status' => 'succeeded',
                        'outputs' => ['inspection' => ['vault' => 'orbit-control-inputs',
                            'key' => 'nckrtl-process-command-assets-'.self::Run.'-inspection-inspection-'.$keyRequest.'-value']]]]]],
                ]]]];
            }
            if ($type === 'vault.read-secret') {
                return [['id' => $message['id'], 'type' => $type, 'payload' => ['data' => [
                    'vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['secretKey'], 'vaultType' => 'local_encryption',
                    'value' => json_encode(['state' => 'active', 'output' => $this->fault === 'oversized' ? str_repeat('é', 2097153) : "old service output\nnew command schedule-secret-sentinel\n", 'truncated' => $this->fault === 'truncated'], JSON_THROW_ON_ERROR),
                ]]]];
            }

            throw new LogicException('Unexpected journal request '.$type);
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token', 'swamp.read_token' => 'read-token']);

    return $socket;
}

describe('migrated Schedule logs', function (): void {
    it('reads retained and new command lines through a private request-bound journal read and redacts values', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $schedule = migrationSchedule($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $instance->refresh()->update(['provisioning_step' => 'active']);
        $instance->environmentValues()->create(['env_key' => 'CUSTOM_SECRET', 'env_value' => 'schedule-secret-sentinel']);
        $socket = migrationScheduleLogSocket();

        $logs = app(ShowScheduleLogsAction::class)->execute($schedule->refresh(), 2);

        expect($logs->output)->toBe("old service output\nnew command [REDACTED]\n");
        expect($logs->truncated)->toBeFalse();
        expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-process-container');
        expect($socket->sent[0]['payload']['inputs']['intent']['schedule'])->toBe(['id' => $schedule->id]);
        expect($socket->sent[0]['payload']['inputs']['intent']['read']['lines'])->toBe(2);
        expect(array_column($socket->sent, 'type'))->toBe(['workflow.run', 'workflow.history.get', 'vault.read-secret']);
        expect(json_encode($socket->sent, JSON_THROW_ON_ERROR))->not->toContain('schedule-secret-sentinel');
    });

    it('preserves the byte-bounded whole-entry truncation result from the Node', function (string $fault): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $schedule = migrationSchedule($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $instance->refresh()->update(['provisioning_step' => 'active']);
        migrationScheduleLogSocket($fault);
        if ($fault === 'oversized') {
            expect(containerRefusal(fn () => app(ShowScheduleLogsAction::class)->execute($schedule->refresh(), 2)))->toBe('schedule.logs_failed');

            return;
        }

        $logs = app(ShowScheduleLogsAction::class)->execute($schedule->refresh(), 2);

        expect($logs->truncated)->toBeTrue()
            ->and($logs->output)->toBe("old service output\nnew command schedule-secret-sentinel\n");
    })->with(['truncated', 'oversized']);

    it('refuses a failed or mismatched migrated Schedule journal read', function (string $fault, string $code): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $schedule = migrationSchedule($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $instance->refresh()->update(['provisioning_step' => 'active']);
        $socket = migrationScheduleLogSocket($fault);

        expect(containerRefusal(fn () => app(ShowScheduleLogsAction::class)->execute($schedule->refresh(), 2)))->toBe($code);
        expect(array_column($socket->sent, 'type'))->not->toContain('vault.read-secret');
    })->with([
        'failed read' => ['failed run', 'schedule.logs_failed'],
        'different request' => ['wrong request', 'swamp.terminal_result_unavailable'],
        'different vault key' => ['wrong key', 'schedule.logs_failed'],
    ]);
});
