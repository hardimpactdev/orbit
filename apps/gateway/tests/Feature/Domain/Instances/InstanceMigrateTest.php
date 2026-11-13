<?php

declare(strict_types=1);

use App\Actions\Hibernation\ActivateInstanceRuntimeAction;
use App\Actions\Instances\AttachInstanceNodeServiceTargetAction;
use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Instances\RenameInstanceAction;
use App\Actions\Instances\StartInstanceDeployAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Instances\StartInstanceMigrateAction;
use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Operations\ReadOperationAction;
use App\Actions\Schedules\CreateScheduleAction;
use App\Actions\Schedules\RunScheduleAction;
use App\Actions\Schedules\ShowScheduleLogsAction;
use App\Data\Instances\InstanceData;
use App\Data\Instances\RenameInstanceData;
use App\Data\Operations\OperationData;
use App\Data\Schedules\AddScheduleData;
use App\Data\Schedules\ScheduleData;
use App\Domain\AgentView\AgentNodeView;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\AgentView\AgentViewSubscriberHealth;
use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\InstanceMigration\InstanceMigrateProjection;
use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\InstanceMigration\InstanceMigrationRoute;
use App\Domain\InstanceMigration\MigratedProcessDefinitions;
use App\Domain\InstanceMigration\MigrateSchedules;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlanner;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\DirectInstanceDestroyIntent;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceAttachedTargets;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceEgress;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NativeDevelopmentInstanceInput;
use App\Domain\Instances\NodeServiceTargetOperation;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\VpDevPreset;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\Projects\ProjectType;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Schedules\ScheduleRuntimeAccount;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Http\Requests\ProjectDefinitions\ProcessDefinitionRequest;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Swamp\NativeStepFailure;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Infrastructure\Swamp\SwampReadBudget;
use App\Infrastructure\Swamp\SwampVaultExecutionCopy;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\ProblemCollectorState;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Project;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\WebSocketApp;
use App\Models\WebSocketAppTarget;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

uses(LazilyRefreshDatabase::class);

afterEach(fn () => app(SwampConnection::class)->release());

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
function migrateVault(array $secrets = [], ?string $failKey = null): object
{
    $socket = new class($secrets, $failKey) implements WebSocketClient
    {
        /** @var list<array{key: string, value: string}> */
        public array $puts = [];

        /** @var list<array<string, mixed>> */
        private array $frames = [];

        public array $sentTypes = [];

        /** @param array<string, string> $secrets */
        public function __construct(private array $secrets, private readonly ?string $failKey) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->frames[] = $message;
            $this->sentTypes[] = $message['type'];
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);
            if ($frame['type'] === 'vault.read-secret') {
                return [['id' => $frame['id'], 'type' => 'vault.read-secret', 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'],
                    'secretKey' => $frame['payload']['secretKey'], 'vaultType' => 'local_encryption', 'value' => $this->secrets[$frame['payload']['secretKey']] ?? '']]]];
            }
            $this->puts[] = ['key' => $frame['payload']['key'], 'value' => (string) ($frame['payload']['value'] ?? '')];
            if ($frame['payload']['key'] === $this->failKey) {
                return [['id' => $frame['id'], 'type' => 'error', 'error' => ['code' => 'vault_put_failed']]];
            }

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

/** The shared reader's native history, recovery search and cancellation boundary. */
function migrationTerminalReader(Operation $migration, string $status, bool $noRun = false, ?string $startEvent = null, ?array $result = null): WebSocketClient
{
    app(SwampConnection::class)->release();
    $runId = '77eb3d91-e9b9-42f9-8c35-e1e5837042b2';
    $workflow = app(DirectOperation::class)->definition($migration->kind)['workflow'];
    $inputs = ['operation_id' => $migration->id, 'node_id' => $migration->node_id, 'kind' => $migration->kind];
    $result ??= $status === 'succeeded'
        ? ['instance_id' => $migration->target_id, 'ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]
        : ['ready' => false, 'moved' => false, 'restored' => true];
    $history = ['id' => $runId, 'workflowName' => $workflow, 'status' => $status, 'inputs' => $inputs,
        'startedAt' => ($migration->started_at ?? now())->toISOString(), 'completedAt' => now()->toISOString(),
        'jobs' => [['name' => 'migrate', 'steps' => [['name' => 'finish', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode($result, JSON_THROW_ON_ERROR)]]]]]];
    $search = $noRun ? [] : [['runId' => $runId, 'workflowName' => $workflow, 'inputs' => $inputs,
        'tags' => ['orbit_start_key' => 'operation:'.$migration->id]]];
    $socket = new class($history, $search, $startEvent) implements WebSocketClient
    {
        public array $frames = [];

        public int $connects = 0;

        private ?array $frame = null;

        private bool $open = false;

        public function __construct(private readonly array $history, private readonly array $search, private readonly ?string $startEvent) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->connects++;
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
            $this->frame = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = $this->frame;
            $this->frame = null;
            if ($frame === null) {
                return [];
            }
            if ($frame['type'] === 'workflow.run') {
                return [['id' => $frame['id'], 'type' => 'event', 'event' => ['workflowName' => $this->history['workflowName'],
                    'runId' => $this->history['id'], 'kind' => $this->startEvent]]];
            }
            $payload = match ($frame['type']) {
                'vault.put' => ['data' => ['vaultName' => $frame['payload']['vaultName'],
                    'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']],
                'workflow.history.get' => ['data' => $this->history],
                'workflow.run.search' => ['data' => ['results' => $this->search], 'total' => count($this->search)],
                'run.attach' => ['runId' => $this->history['id'], 'kind' => 'workflow-run'],
                'workflow.cancel' => ['data' => ['runId' => $this->history['id'], 'status' => 'cancellation_requested']],
            };

            return [['id' => $frame['id'], 'type' => $frame['type'] === 'run.attach' ? 'run.attached' : $frame['type'], 'payload' => $payload]];
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
    config(['swamp.read_token' => 'read-token']);

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

        expect(containerRefusal(fn () => migrateStart($default)))->toBe('instance.migrate_settlement_pending')
            ->and(InstanceMigration::running($default))->toBeTrue();
    });
});

describe('migration target ownership', function (): void {
    it('settles a queued migration and its fires when its non-default Instance was already removed', function (string $terminal, string $fireState): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        migrateMovedDefault($project, $node);
        $instance = migrateCheckout($project, $node, 'removed');
        $schedule = $fireState === 'none' ? null : migrationSchedule($instance);
        $migration = migrateStart($instance);
        $fire = $schedule === null ? null : app(RunScheduleAction::class)->admit($schedule, $node);
        expect($migration->status)->toBe(OperationStatus::Queued);
        if ($fire !== null) {
            expect($fire->status)->toBe(OperationStatus::Waiting);
        }
        // Reproduce an already deleted target from before the destroy admission guard.
        $instance->runtime()->delete();
        DB::table('instances')->where('id', $instance->id)->delete();
        if ($fireState === 'deleted') {
            $schedule->delete();
        }
        $this->travel(11)->minutes();

        $ended = $terminal === 'cancelled' ? app(CancelOperationAction::class)->execute($migration)
            : app(ExpireOperationAction::class)->execute($migration);

        expect($ended->refresh()->status)->toBe($terminal === 'cancelled' ? OperationStatus::Cancelled : OperationStatus::Failed)
            ->and($ended->finished_at)->not->toBeNull()
            ->and(Instance::query()->whereKey($instance->id)->exists())->toBeFalse();
        if ($terminal === 'expired') {
            expect($ended->error_code)->toBe('operation.timed_out');
        }
        if ($fire !== null) {
            expect($fire->refresh()->status)->toBe(OperationStatus::Failed)
                ->and($fire->finished_at)->not->toBeNull()
                ->and($fire->error_code)->toBe('schedule.target_unavailable')
                ->and($fire->result['error']['code'])->toBe('schedule.target_unavailable');
            Queue::assertNotPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id);
            if ($fireState === 'retained') {
                expect($schedule->refresh()->last_run_status->value)->toBe('error')
                    ->and($schedule->error_code)->toBe('schedule.target_unavailable');
            } else {
                expect(Schedule::query()->whereKey($schedule->id)->exists())->toBeFalse();
            }
            $settled = $fire->getAttributes();
            $result = $ended->result;
            app(OperationStore::class)->change($ended, ['status' => $ended->status]);
            app(CancelOperationAction::class)->execute($ended);
            app(ExpireOperationAction::class)->execute($ended->refresh());
            expect($fire->refresh()->getAttributes())->toBe($settled)
                ->and($ended->refresh()->result)->toBe($result);
        }
        expect(Operation::query()->where('kind', 'schedule.run')->where('status', OperationStatus::Waiting)->exists())->toBeFalse();
    })->with([
        'cancel without fires' => ['cancelled', 'none'], 'expire without fires' => ['expired', 'none'],
        'cancel with retained Schedule' => ['cancelled', 'retained'], 'expire with retained Schedule' => ['expired', 'retained'],
        'cancel with deleted Schedule' => ['cancelled', 'deleted'], 'expire with deleted Schedule' => ['expired', 'deleted'],
    ]);

    it('blocks forced destroy and names owner recovery after unproven migration expiry', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        migrateMovedDefault($project, $node);
        $instance = migrateCheckout($project, $node, 'retry');
        $migration = migrateStart($instance);
        $migration->update(['status' => OperationStatus::Running, 'attempts' => 1, 'started_at' => now(),
            'state' => [...$migration->state, 'swamp_run_id' => '77eb3d91-e9b9-42f9-8c35-e1e5837042b2']]);
        $reservation = $instance->runtime()->firstOrFail()->id;
        expect(containerRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($instance, true)))->toBe('instance.lifecycle_busy');
        $this->travel(2)->hours();

        $expired = app(ExpireOperationAction::class)->execute($migration);

        expect($expired->error_code)->toBe('operation.timed_out')
            ->and($instance->runtime()->firstOrFail()->id)->toBe($reservation);
        expect(containerRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($instance, true)))->toBe('instance.lifecycle_busy');
        expect(containerRefusal(fn () => migrateStart($instance)))->toBe('instance.migrate_settlement_pending');
        $this->travel(61)->minutes();
        migrationTerminalReader($expired, 'running');
        app()->call([new ReconcileSwampOperations, 'handle']);
        expect(containerRefusal(fn () => migrateStart($instance)))->toBe('instance.migrate_recovery_required')
            ->and($instance->runtime()->firstOrFail()->id)->toBe($reservation);
    });

    it('refuses forced destruction before cleanup and rechecks migration ownership at admission', function (bool $captured): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        migrateMovedDefault($project, $node);
        $instance = migrateCheckout($project, $node, 'moving');
        $destroy = app(StartInstanceDestroyAction::class);
        $snapshot = $captured ? $destroy->snapshot($instance, true) : null;
        $migration = migrateStart($instance);
        $before = $instance->refresh()->getAttributes();
        expect(Schedule::query()->exists())->toBeFalse();

        $action = $captured ? fn () => app(DirectInstanceDestroyIntent::class)->record($snapshot)
            : fn () => $destroy->execute($instance, true);
        expect(containerRefusal($action))->toBe('instance.lifecycle_busy');

        expect($instance->refresh()->getAttributes())->toBe($before)
            ->and($migration->refresh()->status)->toBe(OperationStatus::Queued)
            ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse();
        expect($instance->removalMember()->exists())->toBeFalse();
    })->with(['initial guard' => false, 'snapshot predates migration' => true]);
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

describe('migration without a default seed', function (): void {
    it('admits a non-default before its host default moves without giving it default behavior', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $default = migrateCheckout($project, $node);
        $feature = migrateCheckout($project, $node, 'feature');

        $operation = migrateStart($feature);

        expect($operation->input['migrate'])->toMatchArray([
            'default' => false, 'checkout' => $feature->checkout_path,
            'retire' => [['path' => $feature->checkout_path, 'mirror' => InstanceContainer::mirror($feature)]],
        ]);
        expect($operation->input['container']['clone']['t3']['port'])->toBe(3800);
        expect($feature->runtime()->firstOrFail()->only(['bridge_address', 't3_port', 'clone_source_instance_id']))
            ->toBe(['bridge_address' => '10.253.0.2', 't3_port' => 3800, 'clone_source_instance_id' => null]);
        expect($default->runtime()->exists())->toBeFalse();

        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'commit' => str_repeat('e', 40), 't3' => migrateServer($feature)]);
        expect(migrateStart($default)->input['migrate']['retire'])
            ->toBe([['path' => $default->checkout_path, 'mirror' => InstanceContainer::mirror($default)]]);
    });

    it('refuses a non-default without a default on the same Node before reserving', function (bool $missing): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        if (! $missing) {
            migrateCheckout($project, containerDefaultNode('other', '10.44.0.8'));
        }
        $feature = migrateCheckout($project, $node, 'feature');

        expect(containerRefusal(fn () => migrateStart($feature)))->toBe('instance.default_missing')
            ->and($feature->runtime()->exists())->toBeFalse();
    })->with(['missing' => true, 'another Node' => false]);

    it('keeps the model-router prerequisite before the host default moves', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        migrateCheckout($project, $node);
        $feature = migrateCheckout($project, $node, 'feature');
        app()->forgetInstance(TaskVmModelRoute::class);
        app()->offsetUnset(TaskVmModelRoute::class);

        expect(containerRefusal(fn () => migrateStart($feature)))->toBe('operation.kind_unavailable')
            ->and($feature->runtime()->exists())->toBeFalse();
    });

    it('refuses a non-default move while Conn is disabled before reserving its runtime', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        migrateCheckout($project, $node);
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

    it('admits exactly the private endpoint of an attached websocket app, by its name, and nothing for an Instance without one', function (bool $attached, bool $public): void {
        $node = containerDefaultNode();
        $default = migrateCheckout(containerDefaultProject(), $node);
        $services = containerDefaultNode('Services', '10.44.0.3', false);
        $services->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
        if ($attached) {
            $app = WebSocketApp::query()->create(['name' => 'tool', 'app_id' => '1001', 'key' => 'reverb-key', 'secret' => 'reverb-secret-value', 'credential_generation' => 1]);
            // A public choice names the public app Route; only whether it is set matters to the access plan.
            $route = $public ? Route::query()->create(['kind' => 'app', 'project_id' => $default->project_id, 'node_id' => $node->id,
                'domain' => 'tool.example.com', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']) : null;
            WebSocketAppTarget::query()->create(['websocket_app_id' => $app->id, 'instance_id' => $default->id, 'route_id' => $route?->id]);
        }

        $operation = migrateStart($default);

        $container = ValidatedData::object($operation->input['container']);
        if (! $attached || $public) {
            // An Instance without a private app gets no target, no name and no rule: its plan is as it was.
            expect($container)->not->toHaveKey('private_names')
                ->and($container['config'])->not->toHaveKey('environment.NO_PROXY')
                ->and($container)->not->toHaveKey('proxy_environment')
                ->and($container['bridge_endpoints'])->toBe([])
                ->and($container['acl_rules']['egress'])->toBe([])
                ->and($container['firewall'])->toBe([])
                ->and($operation->input['migrate']['environment']['targets'])->toBe([]);

            return;
        }
        expect($container['private_names'])->toBe([['name' => 'reverb.orbit', 'host' => '10.44.0.3', 'port' => 8443]])
            // The one NO_PROXY composer takes the plan's names, so the publisher connects on the exact path, not through the proxy.
            ->and(explode(',', $container['config']['environment.NO_PROXY']))->toContain('reverb.orbit')
            ->and($container['config']['environment.no_proxy'])->toBe($container['config']['environment.NO_PROXY'])
            // The one guest writer gets the same composed value for the units and login shells.
            ->and($container['proxy_environment'])->toBe(['NO_PROXY' => $container['config']['environment.NO_PROXY'], 'no_proxy' => $container['config']['environment.NO_PROXY']])
            ->and(collect($container['environment'])->first(fn (string $line): bool => str_starts_with($line, 'NO_PROXY=')))->toEndWith(',reverb.orbit')
            ->and($container['bridge_endpoints'])->toBe([['host' => '10.44.0.3', 'port' => 8443, 'docker' => false]])
            ->and($container['acl_rules']['egress'])->toBe([
                ['action' => 'allow', 'destination' => '10.44.0.3/32', 'protocol' => 'tcp', 'destination_port' => '8443', 'state' => 'enabled',
                    'description' => 'Orbit websocket app']])
            ->and($container['firewall'])->toBe([['rule' => 'allow', 'interface_in' => 'orbitinst0', 'from_ip' => '10.253.0.2', 'to_ip' => '10.44.0.3',
                'to_port' => '8443', 'proto' => 'tcp', 'comment' => "orbit:instance-{$default->id}-websocket", 'route' => 'yes', 'interface_out' => 'orbit']])
            // The env check reads the same plan: the address, and the name at the same port. Nothing at 443.
            ->and($operation->input['migrate']['environment']['targets'])->toBe([['host' => '10.44.0.3', 'port' => 8443], ['host' => 'reverb.orbit', 'port' => 8443]])
            ->and(json_encode($operation->input))->not->toContain('reverb-secret-value');
    })->with(['no app' => [false, false], 'private app' => [true, false], 'public app' => [true, true]]);

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
        // The clone's completion runs the fill, from the database plan its create admitted (NativeInstanceCompleteInput).
        $fill = app(NativeDevelopmentInstanceInput::class)->serverDatabase($clone, $clone->input['direct_provisioning']['server_database'])['fill'];

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

    it('keeps a shared attachment with its mark and opens its path, so the moved default\'s clones keep it', function (): void {
        $node = containerDefaultNode();
        $default = migrateCheckout(containerDefaultProject(), $node);
        $remote = containerDefaultNode('services', '10.44.0.3', listed: false);
        $catalogue = DatabaseConnection::query()->forceCreate(['slug' => 'food-reader', 'driver' => 'pgsql', 'node_id' => $remote->id,
            'host' => '10.44.0.3', 'port' => 5434, 'database' => 'food_catalog', 'username' => 'food_catalog_reader', 'password' => 'reader-secret']);
        DatabaseConnectionTarget::query()->create(['instance_id' => $default->id, 'prefix' => 'FOOD_CATALOG', 'database_connection_id' => $catalogue->id,
            'network' => 'wireguard', 'shared' => true]);

        $operation = migrateStart($default);
        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);

        expect(DatabaseConnectionTarget::query()->where('instance_id', $default->id)->sole()->only(['database_connection_id', 'prefix', 'shared']))
            ->toBe(['database_connection_id' => $catalogue->id, 'prefix' => 'FOOD_CATALOG', 'shared' => true])
            ->and(collect($operation->input['container']['firewall'])->firstWhere('to_port', '5434'))->toMatchArray(['to_ip' => '10.44.0.3', 'route' => 'yes']);
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
    expect($run['execution_secrets'])->toBe(['source_credential' => ['vault' => 'orbit-control-inputs', 'key' => 'instance-migrate-source-'.$operation->id], 'process_environments' => ['vault' => 'orbit-control-inputs', 'key' => 'instance-migrate-processes-'.$operation->id], 'model_environment' => ['vault' => 'orbit-control-inputs', 'key' => 'instance-migrate-model-'.$operation->id]])
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
    it('maps a host command name only in the projected migration copy', function (array $command, array $mapped, ?array $expectedExecutables = null): void {
        $instance = migrateCheckout(containerDefaultProject(ProjectType::LaravelApp, '8.5'), containerDefaultNode());
        $worker = migrationWorker($instance);
        $config = $worker->runtime_config;
        $config['command'] = $command;
        $worker->update(['runtime_config' => $config]);
        $operation = migrateStart($instance);
        $unit = $operation->input['container']['processes'][0];
        $executables = $expectedExecutables ?? ($mapped[0] === '/usr/bin/env' ? array_slice($mapped, 0, 2) : [$mapped[0]]);

        expect($unit['content'])->toContain('"'.implode('" "', $mapped).'"')
            ->and($operation->input['migrate']['processes'][0]['executables'])->toBe($executables)
            ->and($worker->refresh()->runtime_config)->toBe($config)
            ->and($worker->systemd_activation_hash)->toBe('old-host-hash');
        // The vault re-renders the same translated public unit, while the host Process is still unchanged.
        expect(app(ContainerProcesses::class)->environments($instance->fresh(), $operation->input['container']['processes'], migrate: true))->toContain('migration-process-secret');

        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        expect($worker->refresh()->runtime_config['command'])->toBe($mapped);
    })->with([
        'versioned PHP' => [['/usr/bin/php8.5', 'artisan', 'queue:work'], ['/opt/orbit-runtime/current/php/8.5/bin/php', 'artisan', 'queue:work']],
        'project PHP' => [['/usr/bin/php', 'artisan', 'queue:work'], ['/opt/orbit-runtime/current/php/8.5/bin/php', 'artisan', 'queue:work']],
        'Vite plus' => [['/home/x/.vite-plus/bin/vp', 'dev'], ['/opt/orbit-runtime/current/bin/vp', 'dev']],
        'env Vite plus' => [['/usr/bin/env', '/home/x/.vite-plus/bin/vp', 'dev'], ['/usr/bin/env', '/opt/orbit-runtime/current/bin/vp', 'dev']],
        'env Node through PATH' => [['/usr/bin/env', 'node', 'worker.js'], ['/usr/bin/env', 'node', 'worker.js'], ['/usr/bin/env']],
        'npm' => [['/usr/bin/npm', 'run', 'start'], ['/opt/orbit-runtime/current/bin/npm', 'run', 'start']],
        'npx' => [['/usr/bin/npx', 'vite'], ['/opt/orbit-runtime/current/bin/npx', 'vite']],
        'bun' => [['/usr/local/bin/bun', 'run', 'start'], ['/opt/orbit-runtime/current/bin/bun', 'run', 'start']],
        'composer' => [['/usr/local/bin/composer', 'run', 'worker'], ['/opt/orbit-runtime/current/bin/composer', 'run', 'worker']],
        'deno' => [['/usr/local/bin/deno', 'run', 'worker.ts'], ['/opt/orbit-runtime/current/bin/deno', 'run', 'worker.ts']],
        'ripgrep' => [['/usr/bin/rg', 'needle'], ['/opt/orbit-runtime/current/bin/rg', 'needle']],
        'Vite Node runtime' => [['/home/x/.vite-plus/js_runtime/node/24/bin/node', 'worker.js'], ['/opt/orbit-runtime/current/bin/node', 'worker.js']],
        'env assignment' => [['/usr/bin/env', 'VAR=1', '/usr/bin/php8.5', 'artisan'], ['/usr/bin/env', 'VAR=1', '/opt/orbit-runtime/current/php/8.5/bin/php', 'artisan'], ['/usr/bin/env', '/opt/orbit-runtime/current/php/8.5/bin/php']],
        'env unset' => [['/usr/bin/env', '-u', 'FOO', '/usr/bin/php8.5', 'artisan'], ['/usr/bin/env', '-u', 'FOO', '/opt/orbit-runtime/current/php/8.5/bin/php', 'artisan'], ['/usr/bin/env', '/opt/orbit-runtime/current/php/8.5/bin/php']],
        'env long unset' => [['/usr/bin/env', '--unset=FOO', '/usr/bin/php8.5', 'artisan'], ['/usr/bin/env', '--unset=FOO', '/opt/orbit-runtime/current/php/8.5/bin/php', 'artisan'], ['/usr/bin/env', '/opt/orbit-runtime/current/php/8.5/bin/php']],
        'env ignore and option end' => [['/usr/bin/env', '-i', '--', 'VAR=1', '/usr/bin/php8.5', 'artisan'], ['/usr/bin/env', '-i', '--', 'VAR=1', '/opt/orbit-runtime/current/php/8.5/bin/php', 'artisan'], ['/usr/bin/env', '/opt/orbit-runtime/current/php/8.5/bin/php']],
        'env split string stays opaque' => [['/usr/bin/env', '-S', '/usr/bin/php8.5 artisan queue:work'], ['/usr/bin/env', '-S', '/usr/bin/php8.5 artisan queue:work'], ['/usr/bin/env']],
        'env long split string stays opaque' => [['/usr/bin/env', '--split-string=/usr/bin/php8.5 artisan'], ['/usr/bin/env', '--split-string=/usr/bin/php8.5 artisan'], ['/usr/bin/env']],
        'unknown env option stays opaque' => [['/usr/bin/env', '--chdir=/host', '/usr/bin/php8.5', 'artisan'], ['/usr/bin/env', '--chdir=/host', '/usr/bin/php8.5', 'artisan'], ['/usr/bin/env']],
    ]);

    it('refuses a Process PHP version absent from the runtime before any migration starts', function (array $command): void {
        $instance = migrateCheckout(containerDefaultProject(ProjectType::LaravelApp, '8.5'), containerDefaultNode());
        $worker = migrationWorker($instance);
        $config = $worker->runtime_config;
        $config['command'] = $command;
        $worker->update(['runtime_config' => $config]);
        try {
            migrateStart($instance);
            $this->fail('The unavailable PHP was admitted.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('instance.migrate_process_binary_unavailable');
            expect($exception->getMessage())->toContain((string) $worker->id, $worker->name, '/usr/bin/php8.4');
        }
        expect($instance->runtime()->exists())->toBeFalse()
            ->and(Operation::query()->where('kind', 'instance.migrate')->exists())->toBeFalse()
            ->and($worker->refresh()->runtime_config)->toBe($config);
    })->with([
        'direct' => [['/usr/bin/php8.4', 'artisan']],
        'env wrapper' => [['/usr/bin/env', '/usr/bin/php8.4', 'artisan']],
    ]);

    it('reports every unavailable Process PHP binary in one admission refusal', function (): void {
        $instance = migrateCheckout(containerDefaultProject(ProjectType::LaravelApp, '8.5'), containerDefaultNode());
        foreach (['worker-a' => '/usr/bin/php8.4', 'worker-b' => '/usr/local/bin/php9.1'] as $name => $path) {
            $worker = migrationWorker($instance, $name);
            $worker->update(['runtime_config' => ['command' => [$path, 'artisan']]]);
        }
        try {
            migrateStart($instance);
            $this->fail('Unavailable PHP versions were admitted.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('instance.migrate_process_binary_unavailable');
            expect($exception->getMessage())->toContain('worker-a', '/usr/bin/php8.4', 'worker-b', '/usr/local/bin/php9.1');
        }
        expect($instance->runtime()->exists())->toBeFalse();
    });

    it('preserves a custom executable with a runtime command basename for the container preflight', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $worker = migrationWorker($instance);
        $worker->update(['runtime_config' => ['command' => ['/opt/custom/vp', 'dev']]]);
        $operation = migrateStart($instance);
        expect($operation->input['container']['processes'][0]['content'])->toContain('"/opt/custom/vp" "dev"')
            ->and($operation->input['migrate']['processes'][0]['executables'])->toBe(['/opt/custom/vp'])
            ->and($operation->input['migrate']['processes'][0]['translations'])->toBe([]);
    });

    it('records each projected Process executable translation and retains it in the operation receipt', function (): void {
        $instance = migrateCheckout(containerDefaultProject(ProjectType::LaravelApp, '8.5'), containerDefaultNode());
        $php = migrationWorker($instance, 'php-worker');
        $php->update(['runtime_config' => ['command' => ['/usr/bin/env', 'VAR=1', '/usr/bin/php8.5', 'artisan']]]);
        $vite = migrationWorker($instance, 'vite-worker');
        $vite->update(['runtime_config' => ['command' => ['/home/x/.vite-plus/bin/vp', 'dev']]]);
        $operation = migrateStart($instance);
        $translations = array_merge(...array_column($operation->input['migrate']['processes'], 'translations'));
        expect($translations)->toBe([
            ['process_id' => $php->id, 'process_name' => 'php-worker', 'from' => '/usr/bin/php8.5', 'to' => '/opt/orbit-runtime/current/php/8.5/bin/php'],
            ['process_id' => $vite->id, 'process_name' => 'vite-worker', 'from' => '/home/x/.vite-plus/bin/vp', 'to' => '/opt/orbit-runtime/current/bin/vp'],
        ]);
        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40), 'process_translations' => $translations]);
        expect($operation->refresh()->result['process_translations'])->toBe($translations);
    });

    it('sends an unknown absolute executable to the pre-quiesce container check without changing it', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $worker = migrationWorker($instance);
        $config = $worker->runtime_config;
        $config['command'] = ['/opt/custom/tool', 'worker'];
        $worker->update(['runtime_config' => $config]);
        $operation = migrateStart($instance);
        expect($operation->input['migrate']['processes'][0]['executables'])->toBe(['/opt/custom/tool'])
            ->and($operation->input['container']['processes'][0]['content'])->toContain('"/opt/custom/tool" "worker"')
            ->and($worker->refresh()->runtime_config)->toBe($config);
    });

    it('reads Process records once per unit, environment or source render', function (string $render): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        migrationVpDev($instance);
        migrationWorker($instance);
        $operation = migrateStart($instance);
        $processes = app(ContainerProcesses::class);

        DB::enableQueryLog();
        try {
            $result = match ($render) {
                'units' => $processes->units($instance, migrate: true),
                'environments' => $processes->environments($instance, $operation->input['container']['processes'], migrate: true),
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

        expect($operation->input['container']['processes'][0]['content'])->not->toContain("EnvironmentFile=-/srv/workspace/.env\n")->toContain('"ORBIT_DEV_SERVER_PORT=5195"');
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
            app()->call([new ReconcileSwampOperations, 'handle']);
            $projected = $operation->refresh();
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
    'job after deadline and confirmed cancel without a target lock' => ['deadline', 'cancelled', true],
    'confirmed cancel' => ['cancel', 'cancelled', false],
    'lost worker' => ['lost worker', 'interrupted', false],
]);

it('renders FrankenPHP after the container network target without waiting for network-online', function (): void {
    $instance = migrateCheckout(containerDefaultProject(ProjectType::LaravelApp, '8.5'), containerDefaultNode());
    $operation = migrateStart($instance);
    $content = $operation->input['container']['units'][0]['content'];
    expect($content)->toContain("After=network.target\n")->not->toContain('network-online.target');
});

it('keeps a newer reservation when a result arrives after recovery ended at the hold limit', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $old = migrateStart($instance);
    $old->update(['status' => 'failed', 'error_code' => 'instance.migrate_result_unavailable', 'finished_at' => now()->subHour(),
        'lock_key' => null, 'result' => ['error_code' => 'instance.migrate_result_unavailable'],
        'state' => ['swamp_run_id' => '0c69f6e8-bc50-4819-bcab-f3972557d2cc', 'native_timeout' => ['native_terminal_status' => 'unproven']]]);
    $retry = migrateStart($instance);
    $runtime = $instance->runtime()->sole();
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldNotReceive('connect', 'send');
    app(SwampConnection::class)->release();
    app()->instance(WebSocketClient::class, $socket);
    app(SwampCommandStep::class)->projectTerminal($old, ['id' => $old->state['swamp_run_id']], ['job' => 'migrate', 'step' => 'finish']);
    expect($old->fresh()->error_code)->toBe('instance.migrate_result_unavailable')
        ->and($runtime->fresh()->operation_id)->toBe($retry->id)
        ->and($instance->runtime()->sole()->id)->toBe($runtime->id);
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
    $old->refresh()->update(['lock_key' => null, 'error_code' => 'instance.migrate_result_unavailable',
        'result' => ['error_code' => 'instance.migrate_result_unavailable']]);
    $new = migrateStart($instance);
    $runtime = $instance->runtime()->firstOrFail();

    $settled = app(InstanceMigrateProjection::class)->apply($old->refresh(), ['run_id' => 'old-root', 'status' => 'failed',
        'started_at' => now()->subHours(2)->toISOString(), 'finished_at' => now()->toISOString(),
        'result' => ['moved' => false, 'restored' => true, 'ready' => false]]);

    expect($settled->result)->toBe(['error_code' => 'instance.migrate_result_unavailable'])
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

function demandMigrateHistory(Operation $operation, Closure $read, ?string $stdout = null, ?string $completedAt = null, string $nativeStatus = 'cancelled'): void
{
    $result = ['instance_id' => $operation->target_id, 'moved' => false, 'restored' => true, 'ready' => false,
        'error_code' => 'instance.migrate_processes_failed'];
    $history = ['id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-instance-migrate', 'status' => $nativeStatus,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'instance.migrate'],
        'startedAt' => now()->subHours(2)->toISOString(), 'completedAt' => $completedAt ?? now()->toISOString(),
        'jobs' => [['name' => 'migrate', 'steps' => [
            ['name' => 'container-processes', 'status' => 'failed', 'error' => 'Command exited with code -1'],
            ['name' => 'finish', 'status' => 'succeeded', 'outputs' => ['stdout' => $stdout ?? json_encode($result, JSON_THROW_ON_ERROR)]],
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

it('recovers an uncertain unbound migrate after expiry through only the reconcile job', function (bool $alreadyExpired): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = migrateStart($instance);
    $workflow = app(DirectOperation::class)->definition($operation->kind)['workflow'];
    $operation->update(['status' => 'running', 'error_code' => 'swamp.start_uncertain', 'attempts' => 0,
        'started_at' => now()->subMinutes(11), 'lock_key' => null,
        'state' => ['swamp_start' => ['key' => 'operation:'.$operation->id, 'workflow' => $workflow]]]);
    if ($alreadyExpired) {
        $operation->update(['status' => 'failed', 'error_code' => 'operation.timed_out', 'finished_at' => now()->subMinute(),
            'state' => [...$operation->state, 'native_timeout' => ['deadline' => now()->subMinute()->toISOString()]]]);
    }
    $result = ['ready' => false, 'moved' => false, 'restored' => true,
        'error_code' => 'instance.migrate_stashes_present', 'error_message' => 'Cannot retire checkout. Stashes: 2.'];
    $socket = migrationTerminalReader($operation, 'failed', result: $result);

    app()->call([new ReconcileSwampOperations, 'handle']);

    expect(array_column($socket->frames, 'type'))->toContain('workflow.run.search', 'workflow.history.get')
        ->and($operation->refresh()->state['swamp_run_id'])->toBe('77eb3d91-e9b9-42f9-8c35-e1e5837042b2')
        ->and($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('instance.migrate_stashes_present')
        ->and($operation->result)->toBe($result)
        ->and($instance->runtime()->exists())->toBeFalse();
})->with(['expires in this pass' => false, 'expired before this pass' => true]);

it('refuses the legacy input GET for a full-input migrate without any Swamp call', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = migrateStart($instance);
    $run = '77eb3d91-e9b9-42f9-8c35-e1e5837042b2';
    $operation->update(['status' => 'running', 'state' => ['swamp_run_id' => $run]]);
    $socket = migrationTerminalReader($operation, 'running');
    $before = $operation->getAttributes();

    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip])
        ->getJson("/api/v1/direct-operations/{$operation->id}?run_id={$run}")
        ->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');

    expect($socket->frames)->toBe([])->and($socket->connects)->toBe(0)
        ->and($operation->refresh()->getAttributes())->toBe($before);
});

it('settles a released overdue migrate through only the reconcile job', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = demandMigrate($instance);
    $reads = 0;
    $baseline = DB::transactionLevel();
    demandMigrateHistory($operation, function () use (&$reads, $baseline): void {
        $reads++;
        expect(DB::transactionLevel())->toBe($baseline);
    });
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect($operation->fresh()->result['restored'])->toBeTrue()
        ->and($operation->fresh()->error_code)->toBe('operation.timed_out')
        ->and($instance->runtime()->exists())->toBeFalse()->and($reads)->toBe(1);
});

it('refuses migrate without a remote read until the job records its previous result', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $old = demandMigrate($instance);
    $runtime = $instance->runtime()->sole()->id;
    $reads = 0;
    demandMigrateHistory($old, function () use (&$reads): void {
        $reads++;
    });
    try {
        migrateStart($instance);
        test()->fail('Admission must wait for the reconciler.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('instance.migrate_settlement_pending')
            ->and($exception->status)->toBe(409)
            ->and($exception->details['previous_operation_id'])->toBe($old->id)
            ->and($exception->details['retryable'])->toBeTrue()
            ->and($exception->getMessage())->toContain("Operation [{$old->id}]", 'reconciler', 'Retry after settlement', 'hold limit')
            ->and($reads)->toBe(0);
    }
    app()->call([new ReconcileSwampOperations, 'handle']);
    $next = migrateStart($instance);
    expect($old->fresh()->result['restored'])->toBeTrue()
        ->and($instance->runtime()->sole()->id)->not->toBe($runtime)
        ->and($instance->runtime()->sole()->operation_id)->toBe($next->id)
        ->and($reads)->toBe(1);
});

it('ends unavailable migrate recovery at the existing sixty minute hold limit and names owner recovery', function (): void {
    $this->freezeTime();
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $old = migrateStart($instance);
    $old->update(['status' => 'running', 'attempts' => 1, 'started_at' => now(), 'lock_key' => 'instance:'.$instance->id,
        'state' => ['swamp_run_id' => '0c69f6e8-bc50-4819-bcab-f3972557d2cc']]);
    $runtimeId = $instance->runtime()->sole()->id;
    $deadline = OperationRunBudgets::deadline($old);
    expect(containerRefusal(fn () => migrateStart($instance)))->toBe('instance.migrate_settlement_pending')
        ->and($old->fresh()->lock_key)->not->toBeNull();
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect')->andThrow(new RuntimeException('fixture unavailable'));
    $socket->shouldReceive('close')->andReturnNull();
    app(SwampConnection::class)->release();
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'read-token', 'swamp.run_token' => 'run-token']);
    $this->travelTo($deadline->addSecond());
    app()->call([new ReconcileSwampOperations, 'handle']);
    $expiredAt = $old->refresh()->finished_at->toISOString();
    expect($old->error_code)->toBe('operation.timed_out')->and($old->lock_key)->not->toBeNull()
        ->and(containerRefusal(fn () => migrateStart($instance)))->toBe('instance.migrate_settlement_pending');
    $this->travel(59)->minutes();
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect($old->fresh()->finished_at->toISOString())->toBe($expiredAt)
        ->and($old->fresh()->lock_key)->not->toBeNull()
        ->and(containerRefusal(fn () => migrateStart($instance)))->toBe('instance.migrate_settlement_pending');
    $this->travel(2)->minutes();
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect($old->fresh()->error_code)->toBe('instance.migrate_result_unavailable')
        ->and($old->fresh()->lock_key)->toBeNull()
        ->and($old->fresh()->result)->not->toHaveKey('restored');
    expect(containerRefusal(fn () => migrateStart($instance)))->toBe('instance.migrate_recovery_required')
        ->and($instance->runtime()->sole()->id)->toBe($runtimeId)
        ->and($instance->runtime()->sole()->operation_id)->toBe($old->id);
});

it('keeps a polled migrate preflight refusal code and message through the real projection', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = migrateStart($instance);
    $operation->update(['status' => 'running', 'attempts' => 1, 'started_at' => now()->subMinute(),
        'state' => ['swamp_run_id' => '0c69f6e8-bc50-4819-bcab-f3972557d2cc']]);
    $result = ['ready' => false, 'moved' => false, 'restored' => true, 'preflight_refused' => true,
        'failed_step' => 'quiesce', 'error_code' => 'instance.migrate_stashes_present',
        'error_message' => 'Cannot retire checkout "/fixture". Stashes: 2.'];
    $reads = 0;
    demandMigrateHistory($operation, function () use (&$reads): void {
        $reads++;
    }, json_encode($result, JSON_THROW_ON_ERROR), nativeStatus: 'failed');
    foreach ([1, 2] as $unused) {
        expect(app(ReadOperationAction::class)->execute($operation)->status)->toBe(OperationStatus::Running);
    }
    expect($reads)->toBe(0);
    app()->call([new ReconcileSwampOperations, 'handle']);
    $shown = app(ReadOperationAction::class)->execute($operation->refresh());
    expect($shown->status)->toBe(OperationStatus::Failed)
        ->and($shown->error_code)->toBe($result['error_code'])
        ->and($shown->result)->toBe($result)
        ->and($reads)->toBe(1);
});

it('does not reopen or renew expiry after invalid late output ends with a named unavailable result', function (): void {
    $this->freezeTime();
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $old = demandMigrate($instance);
    $old->update(['finished_at' => now(), 'lock_key' => 'instance:'.$instance->id]);
    $limit = InstanceMigrateProjection::settlementLimit($old)->toISOString();
    $completed = OperationRunBudgets::deadline($old)->subSecond()->toISOString();
    demandMigrateHistory($old, fn () => null, '{invalid-json', $completed);
    foreach ([30, 29] as $minutes) {
        $this->travel($minutes)->minutes();
        app()->call([new ReconcileSwampOperations, 'handle']);
        expect($old->fresh()->state['migrate_settlement_hold_limit'])->toBe($limit)
            ->and($old->fresh()->error_code)->toBe('instance.migrate_result_unavailable');
    }
    expect(containerRefusal(fn () => migrateStart($instance)))->toBe('instance.migrate_recovery_required');
    $this->travel(2)->minutes();
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect($old->fresh()->error_code)->toBe('instance.migrate_result_unavailable')
        ->and($old->fresh()->lock_key)->toBeNull()
        ->and(containerRefusal(fn () => migrateStart($instance)))->toBe('instance.migrate_recovery_required');
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
});

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
        expect(array_diff_assoc($schedule->getAttributes(), $before))->toBe(['command' => 'php /srv/workspace/artisan schedule:run', 'trigger' => 'container']);
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
        expect($fire->idempotency_key)->toBe($key);
        if ($moved) {
            expect($fire->status)->toBe(OperationStatus::Succeeded)
                ->and($fire->result)->toMatchArray(['skipped' => true, 'reason' => 'container_timer_missed_during_migration']);
            Queue::assertNotPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id);
        } else {
            expect($fire->status)->toBe(OperationStatus::Queued)
                ->and($fire->input['working_directory'])->toBe($source)
                ->and($fire->input['command_hash'])->toBe(hash('sha256', 'php '.$source.'/artisan schedule:run'));
            Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id);
        }
        expect(InstanceMigration::running($instance))->toBeFalse();
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

    it('settles an unstarted migration and releases its deferred fires on cancellation or expiry', function (string $terminal): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['provisioning_step' => 'active']);
        $schedule = migrationSchedule($instance);
        $migration = migrateStart($instance);
        $fire = app(RunScheduleAction::class)->admit($schedule, $instance->node);
        expect($migration->status)->toBe(OperationStatus::Queued);
        $this->travel(11)->minutes();

        $ended = $terminal === 'cancelled' ? app(CancelOperationAction::class)->execute($migration)
            : app(ExpireOperationAction::class)->execute($migration);

        expect($ended->status)->toBe($terminal === 'cancelled' ? OperationStatus::Cancelled : OperationStatus::Failed)
            ->and($ended->lock_key)->toBeNull();
        expect($instance->runtime()->exists())->toBeFalse();
        expect($fire->refresh()->status)->toBe(OperationStatus::Queued)
            ->and($fire->input['working_directory'])->toBe($instance->checkout_path)
            ->and(isset($fire->input['deferred_instance_migration']))->toBeFalse();
        Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id);
        app(CancelOperationAction::class)->execute($fire);
        expect(migrateStart($instance)->input['had_runtime_reservation'])->toBeFalse();
    })->with(['cancelled', 'expired']);

    it('keeps the reservation but settles fires when an uncertain migration expires', function (string $terminal): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['provisioning_step' => 'active']);
        $schedule = migrationSchedule($instance);
        $migration = migrateStart($instance);
        $fire = app(RunScheduleAction::class)->admit($schedule, $instance->node);
        $migration->forceFill(['status' => OperationStatus::Running])->save();
        $this->travel(11)->minutes();

        $terminal === 'cancelled' ? app(CancelOperationAction::class)->execute($migration)
            : app(ExpireOperationAction::class)->execute($migration);

        expect($instance->runtime()->exists())->toBeTrue();
        expect($fire->refresh()->status)->toBe($terminal === 'cancelled' ? OperationStatus::Waiting : OperationStatus::Failed);
        Queue::assertNotPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id);
    })->with(['cancelled', 'expired']);

    it('settles fires at expiry before later native receipts or recovered runs arrive', function (string $status, bool $recover): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['provisioning_step' => 'active']);
        $schedule = migrationSchedule($instance);
        $migration = migrateStart($instance);
        $fire = app(RunScheduleAction::class)->admit($schedule, $instance->node);
        $run = '77eb3d91-e9b9-42f9-8c35-e1e5837042b2';
        $migration->update(['status' => OperationStatus::Running, 'attempts' => 1, 'started_at' => now(),
            'state' => [...$migration->state, ...($recover ? [] : ['swamp_run_id' => $run])]]);
        if ($recover) {
            $migration->update(['error_code' => 'swamp.start_uncertain']);
            migrationTerminalReader($migration, $status);
            app(SwampCommandStep::class)->recoverUnbound($migration);
            expect($migration->refresh()->state['swamp_run_id'])->toBe($run);
        }
        $this->travel(2)->hours();
        $expired = app(ExpireOperationAction::class)->execute($migration);
        expect($expired->error_code)->toBe('operation.timed_out')
            ->and($expired->lock_key)->toBeNull()
            ->and($fire->refresh()->status)->toBe(OperationStatus::Failed);
        $fireSequence = $fire->sequence;
        migrationTerminalReader($expired, $status);
        $reader = app(SwampCommandStep::class);
        $selector = app(DirectOperation::class)->definition($migration->kind)['terminal_result'];

        $ended = $reader->projectTerminal($expired, ['id' => $run, 'status' => $status], $selector);
        $reader->projectTerminal($ended, ['id' => $run, 'status' => $status], $selector);

        expect($ended->refresh()->status)->toBe(match ($status) {
            'succeeded' => OperationStatus::Succeeded, 'interrupted' => OperationStatus::Interrupted, default => OperationStatus::Failed,
        })
            ->and($ended->error_code)->toBe(match ($status) {
                'succeeded' => null, 'cancelled' => 'operation.timed_out', default => 'operation.native_failed',
            })
            ->and($ended->result['moved'])->toBe($status === 'succeeded')
            ->and($ended->result['restored'])->toBe($status !== 'succeeded')
            ->and($ended->lock_key)->toBeNull()
            ->and($ended->state['native_timeout']['native_terminal_status'])->toBe($status);
        expect($instance->runtime()->exists())->toBe($status === 'succeeded');
        expect($fire->refresh()->status)->toBe(OperationStatus::Failed)
            ->and($fire->error_code)->toBe('instance.lifecycle_busy')
            ->and($fire->sequence)->toBe($fireSequence)
            ->and($schedule->refresh()->last_run_status->value)->toBe('error');
        Queue::assertNotPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id);
        app(SwampConnection::class)->release();
        migrateVault();
        if ($status !== 'succeeded') {
            expect(migrateStart($instance)->input['had_runtime_reservation'])->toBeFalse();
        }
    })->with(['late success' => ['succeeded', false], 'late failure' => ['failed', false],
        'late cancellation' => ['cancelled', false], 'crash interruption' => ['interrupted', false],
        'recovered start' => ['interrupted', true]]);

    it('needs no further observation to settle fires after an expired migration cancellation was confirmed', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['provisioning_step' => 'active']);
        $schedule = migrationSchedule($instance);
        $migration = migrateStart($instance);
        $fire = app(RunScheduleAction::class)->admit($schedule, $instance->node);
        $run = '77eb3d91-e9b9-42f9-8c35-e1e5837042b2';
        $migration->update(['status' => OperationStatus::Running, 'attempts' => 1, 'started_at' => now(),
            'state' => [...$migration->state, 'swamp_run_id' => $run]]);
        $this->travel(2)->hours();
        $expired = app(ExpireOperationAction::class)->execute($migration);
        $expired->update(['state' => [...$expired->state, 'native_cancel_confirmed_at' => now()->toISOString()]]);
        $this->travel(20)->minutes();
        $socket = migrationTerminalReader($expired, 'interrupted');

        app()->call([new ReconcileSwampOperations, 'handle']);

        expect(array_column($socket->frames, 'type'))->toContain('workflow.history.get');
        expect($expired->refresh()->error_code)->toBe('operation.native_failed');
        expect($fire->refresh()->status)->toBe(OperationStatus::Failed)
            ->and($fire->error_code)->toBe('instance.lifecycle_busy');
    });

    it('already settled fires when no-run recovery follows an uncertain migration expiry', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['provisioning_step' => 'active']);
        $schedule = migrationSchedule($instance);
        $migration = migrateStart($instance);
        $fire = app(RunScheduleAction::class)->admit($schedule, $instance->node);
        $migration->update(['status' => OperationStatus::Running, 'started_at' => now(), 'error_code' => 'swamp.start_uncertain']);
        $this->travel(11)->minutes();
        $expired = app(ExpireOperationAction::class)->execute($migration);
        migrationTerminalReader($expired, 'interrupted', noRun: true);

        app(SwampCommandStep::class)->recoverUnbound($expired);
        app(SwampCommandStep::class)->recoverUnbound($expired->refresh());

        expect($expired->refresh()->error_code)->toBe('operation.timed_out')
            ->and($expired->lock_key)->toBeNull()
            ->and($expired->state['native_timeout'])->toHaveKey('no_run_verified_at');
        expect($instance->runtime()->exists())->toBeTrue();
        expect($fire->refresh()->status)->toBe(OperationStatus::Failed)
            ->and($fire->error_code)->toBe('instance.lifecycle_busy');
        Queue::assertNotPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id);
    });

    it('keeps deferred fire failures final after lockless stale timeout reconciliation', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['provisioning_step' => 'active']);
        $schedule = migrationSchedule($instance);
        $migration = migrateStart($instance);
        $fire = app(RunScheduleAction::class)->admit($schedule, $instance->node);
        $migration->update(['status' => OperationStatus::Running, 'attempts' => 1, 'started_at' => now(),
            'state' => [...$migration->state, 'swamp_run_id' => '77eb3d91-e9b9-42f9-8c35-e1e5837042b2']]);
        $this->travel(2)->hours();
        $expired = app(ExpireOperationAction::class)->execute($migration);
        $this->travel(61)->minutes();

        $socket = migrationTerminalReader($expired, 'running');
        app()->call([new ReconcileSwampOperations, 'handle']);

        expect(array_column($socket->frames, 'type'))->toContain('workflow.history.get');
        expect($expired->refresh()->error_code)->toBe('instance.migrate_result_unavailable');
        expect($instance->runtime()->exists())->toBeTrue();
        expect($fire->refresh()->status)->toBe(OperationStatus::Failed)
            ->and($fire->error_code)->toBe('instance.lifecycle_busy')
            ->and($schedule->refresh()->last_run_status->value)->toBe('error');
        Queue::assertNotPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id);
    });

    it('replays deferred fires once through the shared reader after manual cancellation of a started migration', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['provisioning_step' => 'active']);
        $schedule = migrationSchedule($instance);
        $migration = migrateStart($instance);
        $fire = app(RunScheduleAction::class)->admit($schedule, $instance->node);
        $run = '77eb3d91-e9b9-42f9-8c35-e1e5837042b2';
        $migration->update(['status' => OperationStatus::Running, 'attempts' => 1, 'started_at' => now(),
            'state' => [...$migration->state, 'swamp_run_id' => $run]]);
        migrationTerminalReader($migration, 'cancelled');

        $cancelled = app(CancelOperationAction::class)->execute($migration);
        expect($cancelled->status)->toBe(OperationStatus::Running)
            ->and($fire->refresh()->status)->toBe(OperationStatus::Waiting);
        $reader = app(SwampCommandStep::class);
        $selector = app(DirectOperation::class)->definition($migration->kind)['terminal_result'];
        $ended = $reader->projectTerminal($cancelled, ['id' => $run, 'status' => 'cancelled'], $selector);
        $reader->projectTerminal($ended, ['id' => $run, 'status' => 'cancelled'], $selector);

        expect($ended->status)->toBe(OperationStatus::Cancelled);
        expect($instance->runtime()->exists())->toBeFalse();
        expect($fire->refresh()->status)->toBe(OperationStatus::Queued)
            ->and($fire->input['working_directory'])->toBe($instance->checkout_path);
        expect(Queue::pushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id))->toHaveCount(1);
    });

    it('settles each deferred fire exactly once across every migration terminal path', function (string $path, bool $queued): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['provisioning_step' => 'active']);
        $schedule = migrationSchedule($instance);
        $migration = migrateStart($instance);
        $fire = app(RunScheduleAction::class)->admit($schedule, $instance->node);
        $run = '77eb3d91-e9b9-42f9-8c35-e1e5837042b2';
        expect($fire->status)->toBe(OperationStatus::Waiting);

        if (in_array($path, ['success', 'rollback', 'failure', 'cancel after start'], true)) {
            migrateEnd($migration, match ($path) {
                'success' => 'succeeded', 'cancel after start' => 'cancelled', default => 'failed',
            }, $path === 'success' ? ['ready' => true, 'moved' => true, 'commit' => str_repeat('d', 40)]
                : ['ready' => false, 'moved' => false, 'restored' => $path !== 'failure', 'error_code' => 'instance.migrate_files_failed']);
        } elseif ($path === 'cancel before start') {
            app(CancelOperationAction::class)->execute($migration);
        } elseif ($path === 'cancel at start') {
            $migration->update(['cancel_requested_at' => now()]);
            app(StartSwampRun::class)->start($migration);
        } elseif ($path === 'start refusal') {
            app(StartSwampRun::class)->refuse($migration, new ResourceOperationException('swamp.start_enqueue_failed', 'No submission.', 503));
        } elseif ($path === 'start worker failure') {
            (new StartAdmittedSwampRun($migration->id))->failed(new RuntimeException('Worker stopped before submission.'));
        } elseif (in_array($path, ['bound failed start', 'bound cancelled start'], true)) {
            migrationTerminalReader($migration, 'failed', startEvent: $path === 'bound failed start' ? 'failed' : 'cancelled');
            try {
                app(StartSwampRun::class)->start($migration);
                $this->fail('The native terminal start event must refuse dispatch.');
            } catch (ResourceOperationException $error) {
                expect($error->errorCode)->toBe('operation.native_dispatch_failed');
            }
            expect($migration->refresh()->state['swamp_run_id'])->toBe($run)
                ->and($migration->error_code)->toBe('operation.native_dispatch_failed');
        } elseif ($path === 'recovered terminal') {
            $migration->update(['status' => OperationStatus::Running, 'started_at' => now(), 'error_code' => 'swamp.start_uncertain']);
            migrationTerminalReader($migration, 'interrupted');
            $reader = app(SwampCommandStep::class);
            $reader->recoverUnbound($migration);
            $reader->projectTerminal($migration->refresh(), ['id' => $run, 'status' => 'interrupted'],
                app(DirectOperation::class)->definition($migration->kind)['terminal_result']);
        } else {
            if ($path !== 'expiry before start') {
                $migration->update(['status' => OperationStatus::Running, 'attempts' => 1, 'started_at' => now(),
                    'lock_key' => in_array($path, ['stale settlement', 'no-run recovery'], true) ? 'instance:'.$instance->id : null,
                    'state' => [...$migration->state, ...($path === 'no-run recovery' ? [] : ['swamp_run_id' => $run])]]);
            }
            $this->travel(2)->hours();
            $expired = app(ExpireOperationAction::class)->execute($migration);
            $sequenceAtExpiry = $fire->refresh()->sequence;
            expect($fire->status)->toBe($path === 'success' ? OperationStatus::Succeeded : ($queued ? OperationStatus::Queued : OperationStatus::Failed));
            if ($path === 'stale settlement') {
                $this->travel(61)->minutes();
                expect(app(ExpireOperationAction::class)->releaseStaleHolds())->toBe(1);
            } elseif ($path === 'no-run recovery') {
                migrationTerminalReader($expired, 'interrupted', noRun: true);
                app(SwampCommandStep::class)->recoverUnbound($expired);
                expect($expired->refresh()->state['native_timeout'])->toHaveKey('no_run_verified_at');
            }
            expect($fire->refresh()->sequence)->toBe($sequenceAtExpiry)
                ->and($expired->refresh()->error_code)->toBe('operation.timed_out');
        }

        $migration->refresh();
        $fire->refresh();
        expect($migration->status->terminal())->toBeTrue();
        expect($fire->status)->toBe($path === 'success' ? OperationStatus::Succeeded : ($queued ? OperationStatus::Queued : OperationStatus::Failed));
        if (! $queued) {
            expect($fire->error_code)->toBe('instance.lifecycle_busy')
                ->and($schedule->refresh()->last_run_status->value)->toBe('error');
        }
        $before = $fire->getAttributes();
        $receipt = $migration->result;
        app(OperationStore::class)->change($migration, ['result' => $receipt]);
        app(OperationStore::class)->change($migration, ['status' => $migration->status]);
        expect($fire->refresh()->getAttributes())->toBe($before)
            ->and($migration->refresh()->result)->toBe($receipt);
        expect(Queue::pushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id))->toHaveCount($queued && $path !== 'success' ? 1 : 0);
        if (! $queued) {
            if (InstanceMigrateProjection::unsettled($migration)) {
                expect(containerRefusal(fn () => migrateStart($instance)))->toBe('instance.migrate_settlement_pending');
                $this->travelTo(InstanceMigrateProjection::settlementLimit($migration)->addSecond());
                migrationTerminalReader($migration, 'running');
                app()->call([new ReconcileSwampOperations, 'handle']);
            }
            app(SwampConnection::class)->release();
            migrateVault();
            if ($migration->fresh()->error_code === 'instance.migrate_result_unavailable') {
                expect(containerRefusal(fn () => migrateStart($instance)))->toBe('instance.migrate_recovery_required');
            } else {
                expect(migrateStart($instance)->input['had_runtime_reservation'])->toBeTrue();
            }
        }
    })->with([
        'success' => ['success', true], 'rollback' => ['rollback', true], 'failure' => ['failure', false],
        'cancel before start' => ['cancel before start', true], 'cancel after start' => ['cancel after start', true],
        'cancel at start' => ['cancel at start', true], 'expiry before start' => ['expiry before start', true],
        'expiry after start' => ['expiry after start', false], 'bound failed start' => ['bound failed start', false],
        'bound cancelled start' => ['bound cancelled start', false], 'start refusal' => ['start refusal', true],
        'start worker failure' => ['start worker failure', true], 'stale settlement' => ['stale settlement', false],
        'recovered terminal' => ['recovered terminal', true], 'no-run recovery' => ['no-run recovery', false],
    ]);

    it('reopens Schedule admission after real execution-copy preparation fails before submission', function (bool $retry): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['provisioning_step' => 'active']);
        $schedule = migrationSchedule($instance);
        $reserved = null;
        if ($retry) {
            migrateEnd(migrateStart($instance), 'failed', ['ready' => false, 'moved' => false, 'restored' => false,
                'error_code' => 'instance.migrate_restore_failed']);
            $reserved = $instance->runtime()->firstOrFail()->id;
        }
        $migration = migrateStart($instance);
        $fire = app(RunScheduleAction::class)->admit($schedule, $instance->node);
        $key = 'instance-migrate-processes-'.$migration->id;
        SwampVaultExecutionCopy::forget('orbit-control-inputs', $key);
        $socket = migrateVault(failKey: $key);

        expect(containerRefusal(fn () => (new StartAdmittedSwampRun($migration->id))->handle(
            app(StartSwampRun::class), app(ExpireOperationAction::class),
        )))->toBe('swamp.vault_sync_unavailable');

        expect($socket->sentTypes)->toContain('vault.put')->not->toContain('workflow.run');
        expect(array_column($socket->puts, 'key'))->toContain($key);
        expect($migration->refresh()->status)->toBe(OperationStatus::Failed)
            ->and($migration->error_code)->toBe('swamp.vault_sync_unavailable')
            ->and($migration->state['native_start_not_submitted'])->toBeTrue();
        expect($instance->runtime()->exists())->toBe($retry)
            ->and($instance->runtime()->first()?->id)->toBe($reserved);
        expect($fire->refresh()->status)->toBe($retry ? OperationStatus::Failed : OperationStatus::Queued);
        if ($retry) {
            expect($fire->error_code)->toBe('instance.lifecycle_busy');
            Queue::assertNotPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id);
        } else {
            expect($fire->input['working_directory'])->toBe($instance->checkout_path);
            Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $fire->id);
            app(CancelOperationAction::class)->execute($fire);
            expect(app(ScheduleOperationAdmission::class)->inputFor($schedule, 'run')['working_directory'])->toBe($instance->checkout_path);
        }
    })->with(['new reservation' => false, 'previous failed restore reservation' => true]);

    it('records extensionless interpreter operands as paths for remote containment checks', function (string $command, string $path): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        migrationSchedule($instance, ['command' => $command]);

        $plan = migrateStart($instance)->input['migrate']['schedules'][0];

        expect($plan['source_command_paths'])->toBe([$instance->checkout_path.'/'.$path]);
    })->with(['php worker' => ['php worker', 'worker'], 'node worker' => ['node worker', 'worker'],
        'bun script path' => ['bun scripts/worker', 'scripts/worker']]);

    it('maps a carried host Schedule account explicitly to orbit', function (string $user, string $group, string $home): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->node->update(['user' => $user]);
        $schedule = migrationSchedule($instance, [], new ScheduleRuntimeAccount($user, $group, $home, '/bin/bash'));
        $operation = migrateStart($instance);
        $plan = $operation->input['migrate']['schedules'][0];
        expect($plan)->toMatchArray(['id' => $schedule->id, 'name' => $schedule->name,
            'container_user' => 'orbit', 'container_home' => '/home/orbit', 'container_shell' => '/bin/bash']);
        $native = app(InstanceMigrateProjection::class)->input($operation);
        expect($native['execution_secrets']['process_environments']['key'])->toBe('instance-migrate-processes-'.$operation->id);
        expect(json_encode($native, JSON_THROW_ON_ERROR))->not->toContain('php artisan schedule:run');
    })->with([['nick', 'work', '/fast/home/nick'], ['orbit', 'work', '/home/orbit'], ['agent', 'agent', '/home/agent']]);

    it('refuses unsupported Schedule intent before reserving a runtime', function (string $change, string $code, string $reason): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $account = match ($change) {
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
            'other host PHP' => $schedule->update(['command' => '/usr/bin/php8.4 artisan schedule:run']),
            'host-only executable' => $schedule->update(['command' => '/usr/bin/host-only-tool artisan']),
            'zsh equals expansion' => $schedule->update(['command' => 'php artisan =php']),
            'group', 'home' => null,
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
        'host PHP of another version' => ['other host PHP', 'schedule.container_command_unsupported', "host PHP [8.4], but the container runs the Project's PHP [8.5]; set the Project's PHP version to 8.4, or start the command with `php` instead of `/usr/bin/php8.4`"],
        'host executable outside the runtime' => ['host-only executable', 'schedule.container_command_unsupported', 'absolute path'],
        'zsh equals expansion' => ['zsh equals expansion', 'schedule.container_command_unsupported', 'zsh expands'],
    ]);

    it('moves a Schedule whose host account has another login shell, and maps host runtime paths to container names', function (string $shell, string $command, string $mapped): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->node->update(['user' => 'nckrtl']);
        $schedule = migrationSchedule($instance, ['command' => $command], new ScheduleRuntimeAccount('nckrtl', 'nckrtl', '/home/nckrtl', $shell));

        $operation = migrateStart($instance);
        $plan = $operation->input['migrate']['schedules'][0];

        expect($plan)->toMatchArray(['id' => $schedule->id, 'shell' => $shell, 'container_user' => 'orbit', 'container_shell' => '/bin/bash',
            'command_hash' => hash('sha256', $command), 'mapped_command_hash' => hash('sha256', $mapped)])
            ->and($plan['source_command_paths'])->toBe([$instance->checkout_path.'/artisan'])
            ->and(json_decode(app(MigrateSchedules::class)->commands($instance, [$plan]), true, flags: JSON_THROW_ON_ERROR))->toBe([$schedule->id => $mapped]);

        app(MigrateSchedules::class)->complete($instance, [$plan]);
        expect($schedule->refresh()->command)->toBe($mapped);
    })->with([
        'zsh account, host PHP of the Project version' => ['/usr/bin/zsh', '/usr/bin/php8.5 artisan schedule:run', 'php artisan schedule:run'],
        'fish account, unversioned host PHP' => ['/usr/bin/fish', '/usr/bin/php artisan schedule:run >> /dev/null 2>&1', 'php artisan schedule:run >> /dev/null 2>&1'],
        'zsh account, runtime name already bare' => ['/bin/zsh', 'php artisan schedule:run', 'php artisan schedule:run'],
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

    it('maps carried container inspection to orbit without resolving a host account', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->update(['source_is_laravel' => true, 'root' => 'apps/site/public']);
        $schedule = migrationSchedule($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $instance->refresh()->update(['provisioning_step' => 'active']);
        $instance->node->update(['user' => 'nick']);
        $target = app(ScheduleTargetResolver::class)->forInspection($schedule->refresh());
        expect($target->user)->toBe('orbit')->and($target->workingDirectory)->toBe('/srv/workspace/apps/site');
    });
    it('refuses container mutations until their local-unit path is available', function (string $verb): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $schedule = migrationSchedule($instance, ['desired_timer_state' => 'disabled']);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $instance->refresh()->update(['provisioning_step' => 'active']);
        $before = $schedule->refresh()->getAttributes();
        $count = Operation::query()->count();
        expect(containerRefusal(fn () => $verb === 'create'
            ? app(CreateScheduleAction::class)->admit(new AddScheduleData(ScheduleTargetType::Instance, $instance->id,
                $schedule->name, $schedule->calendar, $schedule->command, $schedule->timeout_seconds))
            : app(ScheduleOperationAdmission::class)->execute($schedule, $verb)))->toBe('schedule.target_unsupported');
        expect($schedule->refresh()->getAttributes())->toBe($before)->and(Operation::query()->count())->toBe($count);
    })->with(['run', 'enable', 'destroy', 'create']);
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

        /** The process-command-assets definition id; the native key names method, spec and instance prefix. */
        private const string Model = '9f8e7d6c-5b4a-4c3d-8e2f-1a0b9c8d7e6f';

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
                            'key' => 'nckrtl-process-command-assets-'.self::Model.($this->fault === 'old two segments' ? '-inspection-inspection-' : '-inspection-inspection-inspection-').$keyRequest.'-value']]]]]],
                ]]]];
            }
            if ($type === 'vault.read-secret') {
                return [['id' => $message['id'], 'type' => $type, 'payload' => ['data' => [
                    'vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['secretKey'], 'vaultType' => 'local_encryption',
                    'value' => json_encode(['state' => $this->fault === 'stopped' ? 'inactive' : 'active',
                        ...($this->fault === 'stopped' ? ['error_code' => 'schedule.target_inactive'] : []),
                        'last_run_at' => '2026-11-12T12:00:00+00:00', 'last_run_status' => 'success', 'output' => $this->fault === 'oversized' ? str_repeat('é', 2097153) : "old service output\nnew command schedule-secret-sentinel\n", 'truncated' => $this->fault === 'truncated'], JSON_THROW_ON_ERROR),
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
        expect($socket->sent[0]['payload']['inputs']['intent']['container'])->toMatchArray([
            'project' => 'orbit-instances', 'name' => 'instance-'.$instance->id]);
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
        'old two-segment vault key' => ['old two segments', 'schedule.logs_failed'],
    ]);

    it('still shows the stored record and marks the observation unreadable when the journal read fails', function (string $fault): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $schedule = migrationSchedule($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $instance->refresh()->update(['provisioning_step' => 'active']);
        $schedule->forceFill(['last_run_at' => '2026-11-10 08:00:00', 'last_run_status' => 'success'])->save();
        migrationScheduleLogSocket($fault);
        $instance->node->accessibleNodes()->attach($instance->node_id);
        $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);

        $this->getJson('/api/v1/schedules/'.$schedule->id)->assertOk()
            ->assertJsonPath('data.id', $schedule->id)->assertJsonPath('data.command', $schedule->command)
            ->assertJsonPath('data.timer_state', 'unreadable')
            ->assertJsonPath('data.last_run_at', '2026-11-10T08:00:00+00:00')->assertJsonPath('data.last_run_status', 'success');
        $this->getJson('/api/v1/schedules')->assertOk()->assertJsonPath('data.0.timer_state', 'unreadable');
    })->with(['failed run', 'wrong key', 'wrong request']);
});

describe('migrated development Process definitions', function (): void {
    it('migrates a second host Process against the definition captured from its default', function (bool $preset): void {
        $project = containerDefaultProject(ProjectType::NodePackage, '8.5');
        $node = containerDefaultNode();
        $default = migrateCheckout($project, $node);
        $preset ? migrationVpDev($default, name: 'vp-dev') : migrationWorker($default);
        migrateEnd(migrateStart($default), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $definition = ProcessDefinition::query()->sole();
        $definitionBefore = $definition->getAttributes();
        $instance = migrateCheckout($project, $node, 'feature');
        $process = $preset ? migrationVpDev($instance, 'stopped', name: 'vp-dev') : migrationWorker($instance, desired: 'stopped');
        $sourceDirectory = $process->working_directory;
        $sourceConfig = $process->runtime_config;

        $operation = migrateStart($instance);

        expect($process->refresh()->working_directory)->toBe($sourceDirectory)
            ->and($process->runtime_config)->toBe($sourceConfig)
            ->and($process->source_definition_id)->toBeNull();
        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('e', 40), 't3' => migrateServer($instance)]);

        expect($process->refresh()->source_definition_id)->toBe($definition->id)
            ->and($process->working_directory)->toBe('/srv/workspace')
            ->and($process->desired_state->value)->toBe('stopped')
            ->and($instance->processes()->count())->toBe(1);
        expect($definition->refresh()->getAttributes())->toBe($definitionBefore);
        expect(ProcessDefinition::query()->count())->toBe(1);
    })->with(['preset' => true, 'non-preset' => false]);

    it('treats an unmappable definition as a non-match without changing the host Process', function (string $directory): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $process = migrationVpDev($instance, name: 'vp-dev');
        app(InstanceContainer::class)->reserve($instance, $instance->node);
        $attributes = [
            'runtime' => $process->runtime, 'working_directory' => $directory,
            'runtime_config' => [...$process->runtime_config, 'environment_file' => '/srv/workspace/.env',
                'environment' => ['QUEUE_TOKEN' => 'migration-process-secret', 'CACHE_PATH' => '/srv/workspace/storage/cache']],
            'keep_alive' => $process->keep_alive, 'restart_on_deploy' => $process->restart_on_deploy,
        ];
        $before = $process->fresh()->getAttributes();

        expect(app(ContainerProcesses::class)->matchesDefinition($process, $attributes, $instance))->toBeFalse();

        expect($process->refresh()->getAttributes())->toBe($before);
        expect($instance->fresh()->checkout_path)->toBe($instance->checkout_path);
    })->with(['external definition' => '/opt/external-app', 'traversing definition' => '/srv/workspace/../other']);

    it('answers no-match for differing or unrenderable non-preset definition intent', function (array $command): void {
        $project = containerDefaultProject();
        $node = containerDefaultNode();
        $default = migrateCheckout($project, $node);
        migrationWorker($default);
        migrateEnd(migrateStart($default), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $definition = ProcessDefinition::query()->sole();
        $instance = migrateCheckout($project, $node, 'feature');
        $process = migrationWorker($instance);
        app(InstanceContainer::class)->reserve($instance, $node);
        $target = app(ProcessTargetResolver::class)->forInspection($process);
        $attributes = app(InstantiateProjectRuntimeDefinitionsAction::class)->attributesForDefinition($instance, $definition, $target);
        $attributes['runtime_config']['command'] = $command;
        $before = $process->fresh()->getAttributes();

        expect(app(ContainerProcesses::class)->matchesDefinition($process, $attributes, $instance))->toBeFalse();

        expect($process->refresh()->getAttributes())->toBe($before);
        expect($instance->fresh()->checkout_path)->toBe($instance->checkout_path);
    })->with([
        'different command' => [['/usr/bin/env', 'node', 'other-worker.js']],
        'unavailable definition binary' => [['php99.9', 'worker.php']],
    ]);

    it('keeps a real preset definition conflict as name_taken before admitting the move', function (): void {
        $project = containerDefaultProject();
        $node = containerDefaultNode();
        $default = migrateCheckout($project, $node);
        migrationVpDev($default, name: 'vp-dev');
        migrateEnd(migrateStart($default), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $definition = ProcessDefinition::query()->sole();
        $instance = migrateCheckout($project, $node, 'feature');
        $process = migrationVpDev($instance, name: 'vp-dev');
        $process->update(['runtime_config' => [...$process->runtime_config,
            'environment' => [...$process->runtime_config['environment'], 'QUEUE_TOKEN' => 'different-value']]]);
        $before = $process->fresh()->getAttributes();

        try {
            migrateStart($instance);
            $this->fail('A differing Process was admitted against the captured definition.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('process_definition.name_taken');
        }

        expect($process->refresh()->getAttributes())->toBe($before);
        expect($instance->runtime()->exists())->toBeFalse();
        expect(ProcessDefinition::query()->sole()->id)->toBe($definition->id);
    });

    it('still refuses an unsafe host preset before comparison with a captured definition', function (string $suffix): void {
        $project = containerDefaultProject();
        $node = containerDefaultNode();
        $default = migrateCheckout($project, $node);
        migrationVpDev($default, name: 'vp-dev');
        migrateEnd(migrateStart($default), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $instance = migrateCheckout($project, $node, 'feature');
        $process = migrationVpDev($instance, name: 'vp-dev');
        $process->update(['working_directory' => $suffix === 'outside' ? '/opt/external-app' : $instance->checkout_path.$suffix]);

        try {
            migrateStart($instance);
            $this->fail('An unsafe host Process was admitted.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('process.container_path_unsupported');
        }

        expect($instance->runtime()->exists())->toBeFalse();
        expect($process->refresh()->source_definition_id)->toBeNull();
        expect(Operation::query()->where('target_id', $instance->id)->exists())->toBeFalse();
    })->with(['outside checkout' => 'outside', 'parent segment' => '/../other', 'dot segment' => '/./frontend']);

    it('links the same Process only at proven cutover and clones its name-based command', function (string $status, bool $ready): void {
        $instance = migrateCheckout(containerDefaultProject(ProjectType::NodePackage, '8.5'), containerDefaultNode());
        $process = migrationWorker($instance);
        $process->update(['runtime_config' => ['command' => ['/usr/bin/php8.5', 'artisan', 'queue:work'], 'environment_file' => $instance->checkout_path.'/.env'],
            'keep_alive' => true, 'restart_on_deploy' => false]);
        $operation = migrateStart($instance);
        expect(ProcessDefinition::query()->count())->toBe(0);
        expect($process->refresh()->source_definition_id)->toBeNull();

        migrateEnd($operation, $status, ['ready' => $ready, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40),
            ...($ready ? [] : ['failed_step' => 'retire', 'error_code' => 'instance.migrate_retire_failed'])]);

        $definition = ProcessDefinition::query()->sole();
        expect($definition->environments)->toBe(['development']);
        expect($definition->spec)->toMatchArray(['command' => ['php8.5', 'artisan', 'queue:work'], 'keep_alive' => true, 'restart_on_deploy' => false]);
        expect($process->refresh()->source_definition_id)->toBe($definition->id);
        expect($operation->refresh()->status->value)->toBe($status);
        $clone = containerCreate($instance->project, $instance->node, 'f67-child');
        $copy = Process::query()->where('owner_id', $clone->target_id)->sole();
        expect($copy->source_definition_id)->toBe($definition->id);
        expect($copy->runtime_config['command'])->toBe(['php8.5', 'artisan', 'queue:work']);
        expect(containerIntentOf($clone)['processes'][0]['content'])->toContain('"/opt/orbit-runtime/current/php/8.5/bin/php" "artisan" "queue:work"');
    })->with(['success' => ['succeeded', true], 'retirement failed after readiness' => ['failed', false]]);

    it('keeps opaque argv exactly after proven readiness and captures none after failed readiness', function (array $command, bool $passed): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $process = migrationWorker($instance);
        $process->update(['runtime_config' => ['command' => $command]]);
        $operation = migrateStart($instance);

        migrateEnd($operation, $passed ? 'succeeded' : 'failed', $passed
            ? ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]
            : ['ready' => false, 'moved' => false, 'restored' => true, 'failed_step' => 'processes', 'error_code' => 'instance.migrate_processes_failed']);

        expect($process->refresh()->source_definition_id !== null)->toBe($passed);
        expect(ProcessDefinition::query()->count())->toBe($passed ? 1 : 0);
        if ($passed) {
            expect(ProcessDefinition::query()->sole()->spec['command'])->toBe($command);
        }
    })->with([
        'opaque env readiness passed' => [['/usr/bin/env', '-S', '/opt/custom/worker --literal'], true],
        'opaque env readiness failed' => [['/usr/bin/env', '-S', '/opt/custom/worker --literal'], false],
        'unknown custom executable' => [['/opt/custom/node', 'worker.js'], true],
        'nested path is not a runtime binary' => [['/opt/orbit-runtime/current/bin/custom/node', 'worker.js'], true],
    ]);

    it('rolls back definitions links and Process paths together when a later Process conflicts', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $first = migrationWorker($instance, 'first');
        $last = migrationWorker($instance, 'last');
        $operation = migrateStart($instance);
        $production = ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => 'last', 'environments' => ['production'],
            'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/false']]]);
        $before = $production->fresh()->getAttributes();

        expect(fn () => migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]))
            ->toThrow(ResourceOperationException::class);

        expect(ProcessDefinition::query()->count())->toBe(1);
        expect($production->refresh()->getAttributes())->toBe($before);
        expect($first->refresh()->source_definition_id)->toBeNull();
        expect($last->refresh()->source_definition_id)->toBeNull();
        expect($first->working_directory)->toBe($instance->checkout_path);
        expect($instance->refresh()->runtime()->firstOrFail()->seed_commit)->toBeNull();
    });

    it('adopts a compatible same-name direct Process and keeps its desired state and activation', function (): void {
        $this->freezeTime();
        $instance = migrateMovedDefault(containerDefaultProject(), containerDefaultNode());
        $process = migrationWorker($instance, desired: 'stopped');
        $process->update(['working_directory' => '/srv/workspace', 'runtime_config' => ['command' => ['/opt/orbit-runtime/current/bin/node', 'worker.js'], 'environment_file' => '/srv/workspace/.env']]);
        $process->refresh();
        $definition = ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => $process->name, 'environments' => ['development'],
            'spec' => ['runtime' => 'systemd', 'command' => ['node', 'worker.js'], 'restart_policy' => $process->restart_policy]]);
        $before = $process->fresh()->getAttributes();

        app(InstantiateProjectRuntimeDefinitionsAction::class)->captureForDevelopment($instance);

        expect($process->refresh()->getAttributes())->toBe([...$before, 'source_definition_id' => $definition->id]);
        expect(Process::query()->where('owner_id', $instance->id)->count())->toBe(1);
    });

    it('refuses incompatible same-name or foreign-linked Processes without overwriting', function (bool $foreign): void {
        $instance = migrateMovedDefault(containerDefaultProject(), containerDefaultNode());
        $process = migrationWorker($instance);
        $process->refresh();
        $definition = ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => $process->name, 'environments' => ['development'],
            'spec' => ['runtime' => 'systemd', 'command' => ['node', 'worker.js'], 'restart_policy' => $process->restart_policy]]);
        if ($foreign) {
            $other = Project::query()->create(['name' => 'foreign', 'slug' => 'foreign', 'type' => ProjectType::NodePackage, 'repository_url' => 'https://github.com/nckrtl/foreign.git']);
            $source = ProcessDefinition::query()->create(['project_id' => $other->id, 'name' => 'worker', 'environments' => ['development'], 'spec' => $definition->spec]);
            $process->update(['source_definition_id' => $source->id]);
        }
        $before = $process->fresh()->getAttributes();

        expect(fn () => app(InstantiateProjectRuntimeDefinitionsAction::class)->captureForDevelopment($instance))->toThrow(ResourceOperationException::class);

        expect($process->refresh()->getAttributes())->toBe($before);
        expect($instance->refresh()->runtime_definitions_captured_at)->toBeNull();
    })->with(['different command' => false, 'foreign definition' => true]);

    it('edits a complete captured definition through the API and preserves default and clone intent', function (bool $preset): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $process = $preset ? migrationVpDev($instance, surveyed: true) : migrationWorker($instance);
        $process->update(['restart_on_deploy' => false, 'runtime_config' => [
            ...$process->runtime_config,
            ...($preset ? ['preset_version' => 1] : ['required_mount_paths' => [$instance->checkout_path.'/storage']]),
        ]]);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $definition = ProcessDefinition::query()->sole();
        $original = $definition->spec;
        $cloneOperation = containerCreate($instance->project, $instance->node, 'f67-child');
        $clone = Instance::query()->findOrFail($cloneOperation->target_id);
        $copy = Process::query()->where('owner_id', $clone->id)->sole();
        $instance->node->accessibleNodes()->attach($instance->node_id);
        $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);
        $spec = [...$original, 'keep_alive' => true];
        $spec['environment'] = array_fill_keys(array_keys($spec['environment']), '[REDACTED]');
        if (! $preset) {
            $spec['command'] = ['node', 'changed-worker.js'];
        }

        $this->putJson("/api/v1/projects/{$instance->project_id}/process-definitions/{$definition->name}", [
            'name' => $definition->name, 'environments' => ['development'], 'spec' => $spec,
        ])->assertOk()->assertJsonPath('data.spec.environment.QUEUE_TOKEN', '[REDACTED]')
            ->assertJsonPath('data.spec.restart_on_deploy', false);
        $expected = [...$original, 'keep_alive' => true, 'command' => $spec['command']];
        expect($definition->refresh()->spec)->toEqual($expected);

        foreach ([$instance->refresh(), $clone] as $target) {
            app(InstantiateProjectRuntimeDefinitionsAction::class)->refreshForContainer($target);
            $units = app(ContainerProcesses::class)->units($target);
            expect(str_contains($units[0]['content'], 'EnvironmentFile=-/srv/workspace/.env'))->toBe(! $preset);
            expect($units[0]['content'])->toContain($preset ? '--port='.$target->vite_port : '"/opt/orbit-runtime/current/bin/node" "changed-worker.js"');
            if (! $preset) {
                expect($units[0]['content'])->toContain('RequiresMountsFor=/srv/workspace/storage');
            }
            $record = $target->processes()->sole();
            expect($record->restart_on_deploy)->toBeFalse();
            expect($record->keep_alive)->toBeTrue();
            expect($record->runtime_config['environment'])->toEqual($original['environment']);
            $private = app(ContainerProcesses::class)->environments($target, $units);
            expect($private)->toContain('migration-process-secret')->not->toContain('[REDACTED]');
            if ($preset) {
                expect($record->runtime_config['preset_version'])->toBe(1);
                expect($record->isVpDev())->toBeTrue();
            }
        }
        expect($process->refresh()->id)->toBe($process->id);
        expect($copy->refresh()->source_definition_id)->toBe($definition->id);
    })->with(['captured worker' => false, 'captured vp-dev' => true]);

    it('redacts captured definition show update and delete while privately delivering the edited clone', function (bool $preset): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $preset ? migrationVpDev($instance) : migrationWorker($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $definition = ProcessDefinition::query()->sole();
        $instance->node->accessibleNodes()->attach($instance->node_id);
        $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);
        $url = "/api/v1/projects/{$instance->project_id}/process-definitions/{$definition->name}";

        $shown = $this->getJson($url)->assertOk()->assertJsonPath('data.spec.environment.QUEUE_TOKEN', '[REDACTED]');
        expect($shown->getContent())->not->toContain('migration-process-secret');
        $payload = array_intersect_key($shown->json('data'), array_flip(['name', 'environments', 'spec']));
        $payload['spec']['keep_alive'] = true;
        $updated = $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.spec.environment.QUEUE_TOKEN', '[REDACTED]');
        expect($updated->getContent())->not->toContain('migration-process-secret');
        expect($definition->refresh()->spec['environment']['QUEUE_TOKEN'])->toBe('migration-process-secret');

        $payload['spec']['environment'] = ['QUEUE_TOKEN' => 'replacement-process-secret'];
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.spec.environment.QUEUE_TOKEN', '[REDACTED]');
        $cloneOperation = containerCreate($instance->project, $instance->node, 'f67-private');
        $clone = Instance::query()->findOrFail($cloneOperation->target_id);
        $private = app(ContainerProcesses::class)->environments($clone, containerIntentOf($cloneOperation)['processes']);
        expect($private)->toContain('replacement-process-secret')->not->toContain('[REDACTED]');
        expect(json_encode($cloneOperation->input, JSON_THROW_ON_ERROR))->not->toContain('replacement-process-secret');

        $deleted = $this->deleteJson($url)->assertOk()->assertJsonPath('data.spec.environment.QUEUE_TOKEN', '[REDACTED]');
        expect($deleted->getContent())->not->toContain('replacement-process-secret');
        $this->assertModelMissing($definition);
        expect($clone->processes()->sole()->runtime_config['environment']['QUEUE_TOKEN'])->toBe('replacement-process-secret');
    })->with(['captured worker' => false, 'captured vp-dev' => true]);

    it('refuses a redaction placeholder for an unknown environment key without changing captured values', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        migrationWorker($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $definition = ProcessDefinition::query()->sole();
        $before = $definition->spec;
        $instance->node->accessibleNodes()->attach($instance->node_id);

        $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip])
            ->putJson("/api/v1/projects/{$instance->project_id}/process-definitions/{$definition->name}", [
                'name' => $definition->name, 'environments' => ['development'],
                'spec' => [...$before, 'environment' => ['UNKNOWN_TOKEN' => '[REDACTED]']],
            ])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect($definition->refresh()->spec)->toBe($before);
    });

    it('keeps captured-only fields out of production and mixed definition input', function (array $environments, array $extra): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->node->accessibleNodes()->attach($instance->node_id);

        $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip])
            ->postJson("/api/v1/projects/{$instance->project_id}/process-definitions", [
                'name' => 'worker', 'environments' => $environments,
                'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/node', 'worker.js'], ...$extra],
            ])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect(ProcessDefinition::query()->count())->toBe(0);
    })->with([
        'production restart flag' => [['production'], ['restart_on_deploy' => false]],
        'mixed mount intent' => [['development', 'production'], ['required_mount_paths' => ['/srv/workspace/storage']]],
        'production environment file' => [['production'], ['environment_file' => '/srv/workspace/.env']],
        'production systemd environment' => [['production'], ['environment' => ['PRIVATE' => 'not-stored']]],
        'new development preset' => [['development'], ['preset' => 'vp-dev', 'preset_version' => 1]],
    ]);

    it('preserves supported production definition input and redacts private Docker values', function (array $environments, array $spec): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $instance->node->accessibleNodes()->attach($instance->node_id);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip])
            ->postJson("/api/v1/projects/{$instance->project_id}/process-definitions", [
                'name' => 'worker', 'environments' => $environments, 'spec' => $spec,
            ])->assertCreated()->assertJsonPath('data.environments', $environments);
        expect(ProcessDefinition::query()->sole()->spec)->toEqual($spec);
        if (isset($spec['environment'])) {
            $response->assertJsonPath('data.spec.environment.PRIVATE', '[REDACTED]');
            expect($response->getContent())->not->toContain('production-docker-secret');
            $payload = array_intersect_key($response->json('data'), array_flip(['name', 'environments', 'spec']));
            $this->putJson("/api/v1/projects/{$instance->project_id}/process-definitions/worker", $payload)
                ->assertOk()->assertJsonPath('data.spec.environment.PRIVATE', '[REDACTED]');
            expect(ProcessDefinition::query()->sole()->spec['environment'])->toBe(['PRIVATE' => 'production-docker-secret']);
        }
    })->with([
        'production systemd' => [['production'], ['runtime' => 'systemd', 'command' => ['/usr/bin/node', 'worker.js']]],
        'mixed systemd' => [['development', 'production'], ['runtime' => 'systemd', 'command' => ['/usr/bin/node', 'worker.js']]],
        'production Docker' => [['production'], ['runtime' => 'docker', 'image' => 'node:22', 'command' => ['node', 'worker.js'],
            'environment' => ['PRIVATE' => 'production-docker-secret']]],
    ]);

    it('refuses invalid edits of captured preset metadata without replacing intent', function (array $changes): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        migrationVpDev($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $definition = ProcessDefinition::query()->sole();
        $before = $definition->spec;
        $instance->node->accessibleNodes()->attach($instance->node_id);

        $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip])
            ->putJson("/api/v1/projects/{$instance->project_id}/process-definitions/{$definition->name}", [
                'name' => $definition->name, 'environments' => ['development'], 'spec' => [...$before, ...$changes],
            ])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect($definition->refresh()->spec)->toBe($before);
    })->with([
        'custom preset command' => [['command' => ['vp', 'custom']]],
        'unsupported preset version' => [['preset_version' => 2]],
        'missing preset identity' => [['preset' => null]],
        'preset mount override' => [['required_mount_paths' => ['/srv/workspace/storage']]],
        'invalid environment file' => [['environment_file' => '/srv/../private.env']],
    ]);

    it('gives a cloned migrated vp-dev Process its own port and keeps preset and environment intent', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $process = migrationVpDev($instance, surveyed: true);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $definition = ProcessDefinition::query()->sole();
        $cloneOperation = containerCreate($instance->project, $instance->node, 'f67-child');
        $clone = Instance::query()->findOrFail($cloneOperation->target_id);
        $copy = Process::query()->where('owner_id', $clone->id)->sole();

        expect($definition->spec['command'][0])->toBe('vp');
        expect($copy->isVpDev())->toBeTrue();
        expect($copy->runtime_config['environment'])->toBe(['CACHE_PATH' => '/srv/workspace/storage/cache', 'QUEUE_TOKEN' => 'migration-process-secret']);
        expect(containerIntentOf($cloneOperation)['processes'][0]['content'])->toContain('--port='.$clone->vite_port);
        expect($clone->vite_port)->not->toBe($instance->vite_port);
    });

    it('repairs proven migrated defaults once and preserves edited definitions on repeat and down', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $process = migrationWorker($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $process->update(['source_definition_id' => null]);
        ProcessDefinition::query()->delete();
        $migration = require database_path('migrations/2027_02_06_000000_capture_migrated_process_definitions.php');

        $migration->up();
        $definition = ProcessDefinition::query()->sole();
        $definition->update(['spec' => [...$definition->spec, 'command' => ['node', 'edited.js']]]);
        $before = $definition->fresh()->getAttributes();
        $migration->up();
        $migration->down();

        expect(ProcessDefinition::query()->count())->toBe(1);
        expect($process->refresh()->source_definition_id)->toBe($definition->id);
        expect($definition->refresh()->getAttributes())->toBe($before);
    });

    it('records an unprovable historical Process and captures proven rows across Instances', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $first = migrationWorker($instance, 'first');
        $opaque = migrationWorker($instance, 'unprovable-worker');
        $opaque->update(['runtime_config' => ['command' => ['/usr/bin/env', '-S', '/opt/custom/worker --literal']]]);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        Process::query()->whereIn('id', [$first->id, $opaque->id])->update(['source_definition_id' => null]);
        ProcessDefinition::query()->delete();

        $otherProject = $instance->project->replicate(['code']);
        $otherProject->fill(['name' => 'other', 'slug' => 'other', 'repository_url' => 'https://github.com/nckrtl/other.git'])->save();
        $other = migrateCheckout($otherProject, $instance->node);
        $otherProcess = migrationWorker($other);
        migrateEnd(migrateStart($other), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('e', 40)]);
        $otherProcess->update(['source_definition_id' => null]);
        ProcessDefinition::query()->where('project_id', $other->project_id)->delete();
        $migration = require database_path('migrations/2027_02_06_000000_capture_migrated_process_definitions.php');

        $migration->up();
        $migration->up();

        expect(ProcessDefinition::query()->count())->toBe(2);
        expect($first->refresh()->source_definition_id)->not->toBeNull();
        expect($otherProcess->refresh()->source_definition_id)->not->toBeNull();
        expect($opaque->refresh()->source_definition_id)->toBeNull();
        $finding = Activity::query()->where('command', 'instance:migrate:repair')->sole();
        expect($finding->properties->toArray())->toBe([
            'instance_id' => $instance->id, 'process_id' => $opaque->id, 'process_name' => $opaque->name,
            'reason' => "Process [{$opaque->id}:{$opaque->name}] has no provable runtime command for historical capture.",
        ]);
        $instance->node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip])->getJson("/api/v1/activities/{$finding->id}")->assertOk()
            ->assertJsonPath('data.properties.process_id', $opaque->id)
            ->assertJsonPath('data.properties.reason', $finding->properties['reason']);
    });

    it('records every unprovable historical row without capturing any', function (bool $settled): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $first = migrationWorker($instance, 'first');
        $last = migrationWorker($instance, 'last');
        $operation = migrateStart($instance);
        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        Process::query()->whereIn('id', [$first->id, $last->id])->update(['source_definition_id' => null]);
        ProcessDefinition::query()->delete();
        if ($settled) {
            Process::query()->whereIn('id', [$first->id, $last->id])->update(['systemd_activation_hash' => null]);
        } else {
            $operation->refresh()->update(['result' => [...$operation->result, 'restored' => true]]);
        }
        $migration = require database_path('migrations/2027_02_06_000000_capture_migrated_process_definitions.php');

        $migration->up();
        $migration->up();

        expect(ProcessDefinition::query()->count())->toBe(0);
        expect($first->refresh()->source_definition_id)->toBeNull();
        expect($last->refresh()->source_definition_id)->toBeNull();
        $findings = Activity::query()->where('command', 'instance:migrate:repair')->orderBy('id')->get();
        expect($findings)->toHaveCount(2);
        foreach ([$first, $last] as $index => $process) {
            expect($findings[$index]->properties->toArray())->toBe([
                'instance_id' => $instance->id, 'process_id' => $process->id, 'process_name' => $process->name,
                'reason' => $settled
                    ? "Process [{$process->id}:{$process->name}] is not proven by the migrated default's activation."
                    : "Instance [{$instance->id}:{$instance->name}] has no proven settled migration for its direct Processes.",
            ]);
        }
    })->with(['missing activation' => [true], 'unsettled move' => [false]]);

    it('captures a directly created migrated vp-dev using the runtime seed when Instance seed fields are null', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $process = migrationVpDev($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $process->update(['source_definition_id' => null]);
        ProcessDefinition::query()->delete();
        expect($instance->refresh()->seed_path)->toBeNull();
        expect($instance->seed_commit)->toBeNull();
        expect($instance->runtime->seed_commit)->toBe(str_repeat('d', 40));

        app(MigratedProcessDefinitions::class)->repair();

        $definition = ProcessDefinition::query()->sole();
        expect($process->refresh()->source_definition_id)->toBe($definition->id);
        expect($definition->environments)->toBe(['development']);
        expect($definition->spec['preset'])->toBe('vp-dev');
        expect(Activity::query()->where('command', 'instance:migrate:repair')->count())->toBe(0);
    });

    it('records every migrated direct Process when the runtime seed commit is missing', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $first = migrationWorker($instance, 'first');
        $last = migrationWorker($instance, 'last');
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        Process::query()->whereIn('id', [$first->id, $last->id])->update(['source_definition_id' => null]);
        ProcessDefinition::query()->delete();
        $instance->runtime()->update(['seed_commit' => null]);
        $migration = require database_path('migrations/2027_02_06_000000_capture_migrated_process_definitions.php');

        $migration->up();
        $migration->up();

        expect(ProcessDefinition::query()->count())->toBe(0);
        expect($first->refresh()->source_definition_id)->toBeNull();
        expect($last->refresh()->source_definition_id)->toBeNull();
        $findings = Activity::query()->where('command', 'instance:migrate:repair')->orderBy('id')->get();
        expect($findings)->toHaveCount(2);
        foreach ([$first, $last] as $index => $process) {
            expect($findings[$index]->properties->toArray())->toBe([
                'instance_id' => $instance->id, 'process_id' => $process->id, 'process_name' => $process->name,
                'reason' => "Instance [{$instance->id}:{$instance->name}] runtime has no seed commit to prove its migrated Processes.",
            ]);
        }
    });

    it('isolates malformed historical Process data from proven siblings and earlier findings', function (string $damage, string $exception): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $good = migrationWorker($instance, 'good');
        $earlier = migrationWorker($instance, 'earlier-skip');
        $bad = migrationWorker($instance, 'bad');
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        Process::query()->whereIn('id', [$good->id, $earlier->id, $bad->id])->update(['source_definition_id' => null]);
        ProcessDefinition::query()->delete();
        $earlier->forceFill(['systemd_activation_hash' => null])->save();
        match ($damage) {
            'missing command' => $bad->update(['runtime_config' => []]),
            'definition command' => ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => $bad->name,
                'environments' => ['development'], 'spec' => ['runtime' => 'systemd', 'command' => [123], 'environment' => ['TOKEN' => 'private-definition-value']]]),
            'scalar config' => DB::table('processes')->where('id', $bad->id)->update(['runtime_config' => json_encode('private-runtime-value')]),
        };
        $before = $bad->fresh()->getRawOriginal();
        $definitions = ProcessDefinition::query()->count();

        app(MigratedProcessDefinitions::class)->repair();
        app(MigratedProcessDefinitions::class)->repair();

        expect($good->refresh()->source_definition_id)->not->toBeNull();
        expect($bad->fresh()->getRawOriginal())->toBe($before);
        expect(ProcessDefinition::query()->count())->toBe($definitions + 1);
        $findings = Activity::query()->where('command', 'instance:migrate:repair')->orderBy('id')->get();
        expect($findings)->toHaveCount(2);
        expect($findings[0]->properties['process_id'])->toBe($earlier->id);
        expect($findings[1]->properties['process_id'])->toBe($bad->id);
        expect($findings[1]->properties['reason'])->toBe($exception.': Stored data could not be proved.');
        expect($findings->toJson())->not->toContain('private-definition-value', 'private-runtime-value', 'ExecStart');
    })->with([
        'missing command' => ['missing command', ErrorException::class],
        'malformed definition command' => ['definition command', RuntimeException::class],
        'scalar runtime config' => ['scalar config', TypeError::class],
    ]);

    it('records malformed Instance proof data and still captures another migrated Instance', function (string $damage, string $reason): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $first = migrationWorker($instance, 'first');
        $last = migrationWorker($instance, 'last');
        $operation = migrateStart($instance);
        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        Process::query()->whereIn('id', [$first->id, $last->id])->update(['source_definition_id' => null]);
        ProcessDefinition::query()->delete();
        $otherProject = $instance->project->replicate(['code']);
        $otherProject->fill(['name' => 'other', 'slug' => 'other', 'repository_url' => 'https://github.com/nckrtl/other.git'])->save();
        $other = migrateCheckout($otherProject, containerDefaultNode('other', '10.44.0.8'));
        $good = migrationWorker($other);
        migrateEnd(migrateStart($other), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('e', 40)]);
        $good->update(['source_definition_id' => null]);
        ProcessDefinition::query()->where('project_id', $other->project_id)->delete();
        match ($damage) {
            'role cast' => DB::table('node_roles')->insert(['node_id' => $instance->node_id, 'role' => 'retired-private-role', 'status' => 'active']),
            'operation input' => DB::table('operations')->where('id', $operation->id)->update(['input' => json_encode(['container' => 'private-input-value'])]),
            'operation result' => DB::table('operations')->where('id', $operation->id)->update(['result' => json_encode('private-result-value')]),
        };

        app(MigratedProcessDefinitions::class)->repair();
        app(MigratedProcessDefinitions::class)->repair();

        expect($good->refresh()->source_definition_id)->not->toBeNull();
        expect($first->refresh()->source_definition_id)->toBeNull();
        expect($last->refresh()->source_definition_id)->toBeNull();
        expect(ProcessDefinition::query()->count())->toBe(1);
        $findings = Activity::query()->where('command', 'instance:migrate:repair')->orderBy('id')->get();
        expect($findings)->toHaveCount(2);
        foreach ([$first, $last] as $index => $process) {
            expect($findings[$index]->properties->toArray())->toBe([
                'instance_id' => $instance->id, 'process_id' => $process->id, 'process_name' => $process->name,
                'reason' => str_replace('{instance}', (string) $instance->id, $reason),
            ]);
        }
        expect($findings->toJson())->not->toContain('retired-private-role', 'private-input-value', 'private-result-value');
    })->with([
        'invalid Node role' => ['role cast', ValueError::class.': Stored data could not be proved.'],
        'malformed operation input' => ['operation input', InvalidArgumentException::class.': Stored data could not be proved.'],
        'malformed operation result' => ['operation result', 'Instance [{instance}:default] has no proven settled migration for its direct Processes.'],
    ]);

    it('rolls back a historical capture that fails after writing its definition and link', function (bool $constraint): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $good = migrationWorker($instance, 'good');
        $bad = migrationWorker($instance, 'bad');
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        Process::query()->whereIn('id', [$good->id, $bad->id])->update(['source_definition_id' => null]);
        ProcessDefinition::query()->delete();
        $before = $bad->fresh()->getRawOriginal();
        Event::listen('eloquent.updated: '.Process::class, static function (Process $process) use ($bad, $constraint): void {
            if ($process->id === $bad->id && $process->source_definition_id !== null) {
                if ($constraint) {
                    $definition = DB::table('process_definitions')->where('id', $process->source_definition_id)->first();
                    DB::table('process_definitions')->insert((array) $definition);
                }
                throw new RuntimeException('private-observer-value');
            }
        });
        try {
            app(MigratedProcessDefinitions::class)->repair();
        } finally {
            Event::forget('eloquent.updated: '.Process::class);
        }

        expect($good->refresh()->source_definition_id)->not->toBeNull();
        expect($bad->fresh()->getRawOriginal())->toBe($before);
        expect(ProcessDefinition::query()->count())->toBe(1);
        expect(ProcessDefinition::query()->where('name', $bad->name)->exists())->toBeFalse();
        $finding = Activity::query()->where('command', 'instance:migrate:repair')->sole();
        expect($finding->properties['process_id'])->toBe($bad->id);
        expect($finding->properties['reason'])->toBe(($constraint ? UniqueConstraintViolationException::class : RuntimeException::class).': Stored data could not be proved.');
    })->with(['observer failure' => [false], 'data constraint failure' => [true]]);

    it('updates the same historical finding when its skipped Process changes', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $process = migrationWorker($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $activation = $process->refresh()->systemd_activation_hash;
        $process->forceFill(['source_definition_id' => null, 'systemd_activation_hash' => null])->save();
        ProcessDefinition::query()->delete();
        app(MigratedProcessDefinitions::class)->repair();
        $finding = Activity::query()->where('command', 'instance:migrate:repair')->sole();
        $process->forceFill(['systemd_activation_hash' => $activation, 'runtime_config' => ['command' => ['/usr/bin/node', 'changed.js']]])->save();

        app(MigratedProcessDefinitions::class)->repair();

        expect(ProcessDefinition::query()->count())->toBe(0);
        expect($process->refresh()->source_definition_id)->toBeNull();
        expect(Activity::query()->where('command', 'instance:migrate:repair')->sole()->id)->toBe($finding->id);
        expect($finding->refresh()->properties['reason'])->toBe("Process [{$process->id}:{$process->name}] current unit content differs from the admitted unit.");
    });

    it('resolves an earlier historical skip when a later repair captures the Process', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $process = migrationWorker($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $activation = $process->refresh()->systemd_activation_hash;
        $process->forceFill(['source_definition_id' => null, 'systemd_activation_hash' => null])->save();
        ProcessDefinition::query()->delete();
        app(MigratedProcessDefinitions::class)->repair();
        $finding = Activity::query()->where('command', 'instance:migrate:repair')->sole();
        expect($finding->description)->toBe('instance.migrate_process_definition_skipped');
        $skipReason = $finding->properties['reason'];
        $process->forceFill(['systemd_activation_hash' => $activation])->save();

        app(MigratedProcessDefinitions::class)->repair();
        app(MigratedProcessDefinitions::class)->repair();

        expect($process->refresh()->source_definition_id)->toBe(ProcessDefinition::query()->sole()->id);
        expect(Activity::query()->where('command', 'instance:migrate:repair')->sole()->id)->toBe($finding->id);
        expect($finding->refresh()->description)->toBe('instance.migrate_process_definition_captured');
        expect($finding->properties->toArray())->toBe([
            'instance_id' => $instance->id, 'process_id' => $process->id, 'process_name' => $process->name,
            'reason' => 'Captured by a later historical repair.', 'skip_reason' => $skipReason,
        ]);
        $instance->node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip])->getJson("/api/v1/activities/{$finding->id}")
            ->assertOk()
            ->assertJsonPath('data.properties.reason', 'Captured by a later historical repair.')
            ->assertJsonPath('data.properties.skip_reason', $skipReason);
    });

    it('surfaces a database infrastructure failure instead of reporting a skipped row', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $process = migrationWorker($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $process->update(['source_definition_id' => null]);
        ProcessDefinition::query()->delete();

        DB::statement('PRAGMA query_only = ON');
        try {
            expect(fn () => app(MigratedProcessDefinitions::class)->repair())->toThrow(QueryException::class);
        } finally {
            DB::statement('PRAGMA query_only = OFF');
        }

        expect(ProcessDefinition::query()->count())->toBe(0);
        expect($process->refresh()->source_definition_id)->toBeNull();
        expect(Activity::query()->where('command', 'instance:migrate:repair')->count())->toBe(0);
    });

    it('does nothing on a fresh empty repair', function (): void {
        app(MigratedProcessDefinitions::class)->repair();
        app(MigratedProcessDefinitions::class)->repair();

        expect(ProcessDefinition::query()->count())->toBe(0);
    });

    it('accepts runtime command names only in development definition requests', function (array $environments, string $name, bool $accepted): void {
        $request = ProcessDefinitionRequest::create('/', 'POST', content: json_encode(['name' => 'worker', 'environments' => $environments,
            'spec' => ['runtime' => 'systemd', 'command' => [$name, 'worker.js']]], JSON_THROW_ON_ERROR));
        $validator = Validator::make($request->validationData(), $request->rules());
        foreach ($request->after() as $after) {
            $validator->after($after);
        }

        expect($validator->passes())->toBe($accepted);
    })->with([
        'development known' => [['development'], 'node', true],
        'development unknown' => [['development'], 'not-a-runtime-command', false],
        'production name' => [['production'], 'node', false],
        'both environments name' => [['development', 'production'], 'node', false],
        'production absolute' => [['production'], '/usr/bin/node', true],
    ]);
    it('accepts an existing compatible development definition without replacing it', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $process = migrationWorker($instance);
        $process->update(['runtime_config' => ['command' => ['/usr/bin/node', 'worker.js'], 'environment_file' => $instance->checkout_path.'/.env']]);
        $process->refresh();
        $definition = ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => $process->name, 'environments' => ['development'],
            'spec' => ['runtime' => 'systemd', 'command' => ['node', 'worker.js'], 'restart_policy' => $process->restart_policy]]);
        $before = $definition->fresh()->getAttributes();
        $operation = migrateStart($instance);
        expect($process->refresh()->source_definition_id)->toBeNull();

        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);

        expect(ProcessDefinition::query()->count())->toBe(1);
        expect($process->refresh()->source_definition_id)->toBe($definition->id);
        expect($definition->refresh()->getAttributes())->toBe($before);
    });

    it('refuses a demonstrated definition collision before reserving a move or stopping a Process', function (array $environments): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $process = migrationWorker($instance);
        ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => $process->name, 'environments' => $environments,
            'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/false']]]);

        expect(fn () => migrateStart($instance))->toThrow(ResourceOperationException::class);

        expect($instance->runtime()->exists())->toBeFalse();
        expect(Operation::query()->where('kind', 'instance.migrate')->exists())->toBeFalse();
        expect($process->refresh()->source_definition_id)->toBeNull();
        expect($process->systemd_activation_hash)->toBe('old-host-hash');
    })->with(['incompatible development' => [['development']], 'production only' => [['production']], 'shared production' => [['development', 'production']]]);

    it('records a changed or conflicting historical Process and captures its proven sibling', function (string $change): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $first = migrationVpDev($instance);
        $last = migrationWorker($instance, 'last');
        $operation = migrateStart($instance);
        migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        Process::query()->whereIn('id', [$first->id, $last->id])->update(['source_definition_id' => null]);
        ProcessDefinition::query()->delete();
        match ($change) {
            'command changed after activation' => $last->update(['runtime_config' => ['command' => ['/usr/bin/env', 'node', 'different.js']]]),
            'malformed command' => $last->update(['runtime_config' => ['command' => [123]]]),
            'unavailable PHP' => $last->update(['runtime_config' => ['command' => ['/usr/bin/php99.9', 'worker.php']]]),
            'missing activation proof' => $last->forceFill(['systemd_activation_hash' => null])->save(),
            'production definition collision' => ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => $last->name,
                'environments' => ['production'], 'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/false']]]),
        };
        $definitions = ProcessDefinition::query()->count();

        app(MigratedProcessDefinitions::class)->repair();

        expect(ProcessDefinition::query()->count())->toBe($definitions + 1);
        expect($first->refresh()->source_definition_id)->not->toBeNull();
        expect($last->refresh()->source_definition_id)->toBeNull();
        $finding = Activity::query()->where('command', 'instance:migrate:repair')->sole();
        expect($finding->properties['process_id'])->toBe($last->id);
        expect($finding->properties['reason'])->toContain(match ($change) {
            'production definition collision' => 'conflicts with Project',
            'command changed after activation' => 'current unit content differs from the admitted unit.',
            'malformed command' => InvalidArgumentException::class.': Stored data could not be proved.',
            'unavailable PHP' => 'the container runtime does not ship',
            default => "is not proven by the migrated default's activation",
        });
    })->with(['command changed after activation', 'missing activation proof', 'production definition collision', 'malformed command', 'unavailable PHP']);

    it('rejects a claimed cutover whose result instead names failed Process readiness', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $process = migrationWorker($instance);
        $operation = migrateStart($instance);

        expect(fn () => migrateEnd($operation, 'failed', ['ready' => false, 'moved' => true, 'restored' => false,
            'commit' => str_repeat('d', 40), 'failed_step' => 'processes', 'error_code' => 'instance.migrate_processes_failed']))
            ->toThrow(ResourceOperationException::class);

        expect(ProcessDefinition::query()->count())->toBe(0);
        expect($process->refresh()->source_definition_id)->toBeNull();
        expect($instance->refresh()->checkout_path)->toContain('/home/orbit/apps/');
    });

    it('leaves production-owned direct Processes outside historical repair', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        $process = migrationWorker($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        $process->update(['source_definition_id' => null]);
        ProcessDefinition::query()->delete();
        $instance->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
        $before = $process->fresh()->getAttributes();

        app(MigratedProcessDefinitions::class)->repair();

        expect(ProcessDefinition::query()->count())->toBe(0);
        expect($process->refresh()->getAttributes())->toBe($before);
    });

    it('leaves a non-default container direct Process outside historical repair', function (): void {
        $default = migrateMovedDefault(containerDefaultProject(), containerDefaultNode());
        $instance = migrateCheckout($default->project, $default->node, 'feature');
        $process = migrationWorker($instance);
        InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.3', 'runtime_version' => 'r', 'agent_version' => 'a',
            'power_state' => 'running', 'seed_commit' => str_repeat('d', 40)]);
        $before = $process->fresh()->getAttributes();

        app(MigratedProcessDefinitions::class)->repair();

        expect(ProcessDefinition::query()->count())->toBe(0);
        expect($process->refresh()->getAttributes())->toBe($before);
    });

    it('uses fixed 5173 independently in two new preset clones and preserves existing migrated ports', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        migrationVpDev($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        foreach (['f67-a', 'f67-b'] as $name) {
            $create = containerCreate($instance->project, $instance->node, $name);
            $clone = Instance::query()->findOrFail($create->target_id);
            expect($clone->vite_port)->toBe(5173);
            app(InstantiateProjectRuntimeDefinitionsAction::class)->captureForDevelopment($clone);
            expect($clone->fresh()->vite_port)->toBe(5173);
            expect(containerIntentOf($create)['processes'][0]['content'])->toContain('--port=5173');
        }

        expect($instance->refresh()->vite_port)->toBe(5195);
        expect(DB::table('vite_port_assignments')->count())->toBe(0);
    });

    it('refuses a recorded conflict on fixed 5173 without choosing another container port', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        migrationVpDev($instance);
        $other = migrationWorker($instance, 'recorded-listener');
        $other->update(['runtime_config' => ['command' => ['/usr/bin/node', 'worker.js', '--port=5173']]]);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);

        expect(fn () => containerCreate($instance->project, $instance->node, 'f67-conflict'))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('process.container_port_conflict');
        });

        expect(Instance::query()->where('name', 'f67-conflict')->exists())->toBeFalse();
        expect($instance->refresh()->vite_port)->toBe(5195);
    });

    it('refuses schema rollback with duplicate container ports and leaves all schema and data unchanged', function (): void {
        $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
        migrationVpDev($instance);
        migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
        foreach (['f67-a', 'f67-b'] as $name) {
            containerCreate($instance->project, $instance->node, $name);
        }
        $rows = DB::table('instances')->orderBy('id')->get()->toArray();
        $indexes = DB::table('sqlite_master')->where('type', 'index')->orderBy('name')->get()->toArray();
        $migration = require database_path('migrations/2027_02_06_000001_drop_instance_vite_port_uniqueness.php');

        expect(fn () => $migration->down())->toThrow(RuntimeException::class, '1 duplicate nonnull (node_id, vite_port) group(s)');

        expect(DB::table('instances')->orderBy('id')->get()->toArray())->toEqual($rows);
        expect(DB::table('sqlite_master')->where('type', 'index')->orderBy('name')->get()->toArray())->toEqual($indexes);
        expect(DB::table('sqlite_master')->where('name', 'vite_port_assignments_node_id_port_unique')->exists())->toBeTrue();
        expect(DB::table('sqlite_master')->where('name', 'instances_node_id_vite_port_unique')->exists())->toBeFalse();
    });

    it('restores the exact Instance unique index on rollback when no duplicate port pairs exist', function (): void {
        $migration = require database_path('migrations/2027_02_06_000001_drop_instance_vite_port_uniqueness.php');

        $migration->down();

        $index = DB::select("PRAGMA index_info('instances_node_id_vite_port_unique')");
        expect(array_column($index, 'name'))->toBe(['node_id', 'vite_port']);
        expect(collect(DB::select("PRAGMA index_list('instances')"))->firstWhere('name', 'instances_node_id_vite_port_unique')->unique)->toBe(1);
        $migration->up();
        expect(DB::table('sqlite_master')->where('name', 'instances_node_id_vite_port_unique')->exists())->toBeFalse();
    });

    it('keeps the unchanged old host allocator working with only assignment-table uniqueness', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $first = migrateCheckout($project, $node, 'host-a');
        $second = migrateCheckout($project, $node, 'host-b');
        $runtime = $this->mock(VitePortRuntime::class);
        $runtime->shouldReceive('selectPort')->once()->withArgs(fn (Node $target, int $preferred, array $excluded): bool => $target->id === $node->id && $preferred === 5173 && ! in_array(5173, $excluded, true))->andReturn(5173);
        $runtime->shouldReceive('selectPort')->once()->withArgs(fn (Node $target, int $preferred, array $excluded): bool => $target->id === $node->id && $preferred === 5173 && in_array(5173, $excluded, true))->andReturn(5174);

        expect(app(VitePortAllocator::class)->assign($first))->toBe(5173);
        expect(app(VitePortAllocator::class)->assign($second))->toBe(5174);

        expect($first->fresh()->vite_port)->toBe(5173);
        expect($second->fresh()->vite_port)->toBe(5174);
        expect(DB::table('sqlite_master')->where('name', 'instances_node_id_vite_port_unique')->exists())->toBeFalse();
        expect(fn () => DB::table('vite_port_assignments')->where('instance_id', $second->id)->update(['port' => 5173]))
            ->toThrow(UniqueConstraintViolationException::class);
        expect(DB::table('vite_port_assignments')->where('instance_id', $second->id)->value('port'))->toBe(5174);
    });

});

it('keeps a migrated attached Node Process standalone when another service claims it', function (string $claim): void {
    $node = containerDefaultNode();
    $node->forceFill(['ssh_host_fingerprint' => 'fixture-fingerprint'])->save();
    $instance = migrateCheckout(containerDefaultProject(), $node);
    $name = match ($claim) {
        'analytics collector' => PlausibleProcess::NAME,
        'ai-router collector' => AiRouterProcess::NAME,
        default => 'mailpit',
    };
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => $name,
        'runtime' => 'docker', 'runtime_config' => ['image' => 'axllent/mailpit', 'ports' => ['0.0.0.0:1025:1025/tcp']],
        'desired_state' => 'running', 'status' => 'active']);
    $target = app(AttachInstanceNodeServiceTargetAction::class)->execute($instance, $process->id, 'tcp', 1025, 'MAIL_HOST', 'MAIL_PORT');
    migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
    expect($instance->refresh()->runtime)->not->toBeNull();
    $claimNode = $node;
    $settings = null;
    if ($claim !== 'ai-router collector') {
        if ($claim !== 'analytics collector') {
            $claimNode = containerDefaultNode('analytics', '10.44.0.10');
            $claimNode->forceFill(['ssh_host_fingerprint' => 'fixture-fingerprint'])->save();
        }
        $storageNode = containerDefaultNode('storage', '10.44.0.8');
        $storageNode->roles()->create(['role' => RoleName::Database, 'status' => 'active']);
        $postgres = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $storageNode->id, 'name' => 'postgres',
            'runtime' => 'docker', 'runtime_config' => ['image' => 'postgres:16', 'ports' => ['10.44.0.8:5432:5432']],
            'desired_state' => 'running', 'status' => 'active']);
        $clickhouse = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $storageNode->id, 'name' => 'clickhouse',
            'runtime' => 'docker', 'runtime_config' => ['image' => 'clickhouse/clickhouse-server:26', 'ports' => ['10.44.0.8:9000:9000']],
            'desired_state' => 'running', 'status' => 'active']);
        $settings = new AnalyticsRoleSettings($claim === 'analytics postgres' ? $process->id : $postgres->id,
            $claim === 'analytics clickhouse' ? $process->id : $clickhouse->id);
    }
    $processBefore = $process->refresh()->getRawOriginal();
    $runtimeBefore = $instance->runtime->refresh()->getRawOriginal();
    $operationsBefore = Operation::query()->count();
    $rolesBefore = $claimNode->roles()->get()->toArray();
    Queue::fake();
    Illuminate\Support\Facades\Process::preventStrayProcesses();

    try {
        if ($claim === 'ai-router collector') {
            app(AiRouterState::class)->enable($node->id, 'cache', 'https://router.example.com', 'fixture-key', 'fixture-read', 'fixture-control');
        } else {
            app(AdmitNodeRoleOperationAction::class)->add($claimNode, RoleName::Analytics, analytics: $settings);
        }
        test()->fail('A service must not adopt a Process that still serves an attached Instance.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('process.node_service_ownership_conflict');
        expect($exception->getMessage())->toBe("Process [{$name}] is attached to Instance [tool/default] (id {$instance->id}). Detach those Node service targets before claiming ownership of the Process.");
    }

    expect($process->refresh()->getRawOriginal())->toEqual($processBefore);
    expect($instance->runtime->refresh()->getRawOriginal())->toEqual($runtimeBefore);
    expect($claimNode->roles()->get()->toArray())->toBe($rolesBefore);
    expect(app(AnalyticsRoleSettingsRepository::class)->find($claimNode))->toBeNull();
    expect(app(AiRouterState::class)->enabled())->toBeFalse();
    expect(app(AiRouterState::class)->nodeId())->toBeNull();
    expect(Operation::query()->count())->toBe($operationsBefore);
    $this->assertDatabaseHas('instance_node_service_targets', ['id' => $target->id, 'process_id' => $process->id, 'instance_id' => $instance->id]);
    Illuminate\Support\Facades\Process::assertNothingRan();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['analytics collector', 'analytics postgres', 'analytics clickhouse', 'ai-router collector']);

/** @return array{Instance, Process, int} */
function migrateNodeServiceRecord(Node $node, Project $project, string $instanceName = 'default', ?Process $process = null): array
{
    $instance = migrateCheckout($project, $node, $instanceName);
    $instance->update(['starting_commit' => str_repeat('c', 40)]);
    $process ??= Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => AiRouterProcess::NAME,
        'runtime' => 'docker', 'runtime_config' => ['image' => 'axllent/mailpit', 'ports' => ['0.0.0.0:1025:1025/tcp']],
        'desired_state' => 'running', 'status' => 'active']);
    $target = test()->postJson("/api/v1/instances/{$instance->id}/node-service-targets",
        ['process_id' => $process->id, 'host_env_key' => 'MAIL_HOST', 'port_env_key' => 'MAIL_PORT'])->assertOk()->json('data.id');

    return [$instance, $process, $target];
}

function migrateNodeServiceThroughApi(Instance $instance): void
{
    $id = test()->postJson("/api/v1/instances/{$instance->id}/migrate")->assertAccepted()->json('data.id');
    migrateEnd(Operation::query()->findOrFail($id), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false,
        'commit' => str_repeat('d', 40), ...($instance->name === 'default' ? [] : ['t3' => migrateServer($instance)])]);
}

/** @param list<int> $remainingPorts */
function migrateDetachNodeServiceThroughApi(Instance $instance, int $target, array $remainingPorts): void
{
    $response = test()->deleteJson("/api/v1/instances/{$instance->id}/node-service-targets/{$target}")
        ->assertAccepted()->assertJsonPath('data.kind', 'instance.node_service_detach')
        ->assertJsonPath('data.status', 'queued');
    test()->assertDatabaseHas('instance_node_service_targets', ['id' => $target, 'instance_id' => $instance->id]);
    $withdraw = Operation::query()->findOrFail($response->json('data.id'));
    $runId = 'migrated-detach-'.$withdraw->id;
    $withdraw->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => $runId]])->save();

    $completed = app(NodeServiceTargetOperation::class)->apply($withdraw, ['status' => 'succeeded', 'run_id' => $runId,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['node_services' => ['status' => 'applied', 'ports' => $remainingPorts]]]);

    expect($completed->status)->toBe(OperationStatus::Succeeded);
    expect($completed->result['detached'])->toBeTrue();
    test()->assertDatabaseMissing('instance_node_service_targets', ['id' => $target]);
}

it('releases a migrated default Node service record for Process cleanup without changing shared attachments', function (string $cleanup): void {
    $node = containerDefaultNode();
    $node->accessibleNodes()->attach($node);
    $node->forceFill(['ssh_host_fingerprint' => 'fixture-fingerprint'])->save();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $project = containerDefaultProject();
    [$instance, $process, $target] = migrateNodeServiceRecord($node, $project);
    [$sibling, , $shared] = migrateNodeServiceRecord($node, $project, 'consumer', $process);
    $uri = "/api/v1/instances/{$instance->id}/node-service-targets";
    $other = $this->postJson($uri, ['process_id' => $process->id, 'host_env_key' => 'SECOND_HOST', 'port_env_key' => 'SECOND_PORT'])
        ->assertOk()->json('data.id');
    $unrelated = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'other-mail',
        'runtime' => 'docker', 'runtime_config' => ['image' => 'axllent/mailpit', 'ports' => ['0.0.0.0:1026:1025/tcp']],
        'desired_state' => 'running', 'status' => 'active']);
    $unrelatedTarget = $this->postJson($uri, ['process_id' => $unrelated->id, 'host_env_key' => 'OTHER_HOST', 'port_env_key' => 'OTHER_PORT'])
        ->assertOk()->json('data.id');
    $unrelatedBefore = $unrelated->refresh()->getRawOriginal();
    app(InstanceEnvironmentStore::class)->seedForCreate($instance, ['MAIL_MAILER' => 'log', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '1025']);
    migrateNodeServiceThroughApi($instance);
    $runtimeBefore = $instance->runtime()->firstOrFail()->getRawOriginal();
    $processBefore = $process->refresh()->getRawOriginal();
    $environmentBefore = app(InstanceEnvironmentStore::class)->values($instance->refresh());
    Queue::fake();
    Illuminate\Support\Facades\Process::preventStrayProcesses();

    migrateDetachNodeServiceThroughApi($instance, $target, [1025, 1026]);

    $this->assertDatabaseMissing('instance_node_service_targets', ['id' => $target]);
    $this->assertDatabaseHas('instance_node_service_targets', ['id' => $shared, 'instance_id' => $sibling->id]);
    $this->assertDatabaseHas('instance_node_service_targets', ['id' => $other, 'instance_id' => $instance->id]);
    expect($instance->runtime()->firstOrFail()->getRawOriginal())->toEqual($runtimeBefore);
    expect($process->refresh()->getRawOriginal())->toEqual($processBefore);
    expect(app(InstanceEnvironmentStore::class)->values($instance))->toBe($environmentBefore);
    $this->deleteJson('/api/v1/processes/'.$process->id)->assertConflict()
        ->assertJsonPath('error.code', 'process.required_by_instance_node_service');
    migrateDetachNodeServiceThroughApi($instance, $other, [1026]);
    $this->deleteJson('/api/v1/processes/'.$process->id)->assertConflict()
        ->assertJsonPath('error.code', 'process.required_by_instance_node_service');
    $this->deleteJson("/api/v1/instances/{$sibling->id}/node-service-targets/{$shared}")->assertAccepted();
    Http::assertNothingSent();
    Illuminate\Support\Facades\Process::assertNothingRan();
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);

    if ($cleanup === 'ownership') {
        app(AiRouterState::class)->enable($node->id, 'cache', 'https://router.example.com', 'fixture-key', 'fixture-read', 'fixture-control');
        expect(app(AiRouterState::class)->nodeId())->toBe($node->id);
        expect($process->refresh()->getRawOriginal())->toEqual($processBefore);
    } else {
        $this->deleteJson('/api/v1/processes/'.$process->id)->assertAccepted()->assertJsonPath('data.kind', 'process.destroy');
        expect($process->refresh()->status)->toBe(LifecycleStatus::Removing);
    }
    $this->assertDatabaseHas('instance_node_service_targets', ['id' => $unrelatedTarget, 'process_id' => $unrelated->id]);
    expect($unrelated->refresh()->getRawOriginal())->toEqual($unrelatedBefore);
})->with(['destroy', 'ownership']);

it('retains default destruction boundaries after attaching and migrating a Node service record', function (bool $force): void {
    $node = containerDefaultNode();
    $node->accessibleNodes()->attach($node);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    [$instance, $process, $target] = migrateNodeServiceRecord($node, containerDefaultProject());
    migrateNodeServiceThroughApi($instance);
    $runtimeBefore = $instance->runtime()->firstOrFail()->getRawOriginal();
    $processBefore = $process->refresh()->getRawOriginal();
    $before = Operation::query()->count();

    $this->deleteJson("/api/v1/instances/{$instance->id}", ['force' => $force])->assertStatus(503)
        ->assertJsonPath('error.code', 'operation.kind_unavailable');

    expect(Operation::query()->count())->toBe($before);
    expect($instance->refresh()->status)->toBe(InstanceState::Active);
    expect($instance->runtime()->firstOrFail()->getRawOriginal())->toEqual($runtimeBefore);
    expect($process->refresh()->getRawOriginal())->toEqual($processBefore);
    $this->assertDatabaseHas('instance_node_service_targets', ['id' => $target]);
    Queue::fake();
    migrateDetachNodeServiceThroughApi($instance, $target, []);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
})->with([false, true]);

it('removes only the migrated non-default attachment on successful forced Instance destruction', function (): void {
    $node = containerDefaultNode();
    $node->accessibleNodes()->attach($node);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $project = containerDefaultProject();
    [$default, $process, $shared] = migrateNodeServiceRecord($node, $project);
    migrateNodeServiceThroughApi($default);
    [$instance, , $target] = migrateNodeServiceRecord($node, $project, 'feature', $process);
    migrateNodeServiceThroughApi($instance);
    $processBefore = $process->refresh()->getRawOriginal();
    $defaultRuntimeBefore = $default->runtime()->firstOrFail()->getRawOriginal();
    $this->deleteJson("/api/v1/instances/{$instance->id}")->assertConflict()->assertJsonPath('error.code', 'instance.container_destroy_force_required');
    $destroyId = $this->deleteJson("/api/v1/instances/{$instance->id}", ['force' => true])->assertAccepted()->json('data.id');
    $destroy = Operation::query()->findOrFail($destroyId);
    $this->assertDatabaseHas('instance_node_service_targets', ['id' => $target]);
    $this->deleteJson("/api/v1/instances/{$instance->id}/node-service-targets/{$target}")->assertConflict()
        ->assertJsonPath('error.code', 'instance.lifecycle_busy');
    $destroy->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'destroy-run'], 'finished_at' => null])->save();
    $frame = [];
    $socket = $this->mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->atLeast()->once()->withArgs(fn (array $message): bool => $message['type'] === 'vault.delete')
        ->andReturnUsing(function (array $message) use (&$frame): void {
            $frame = $message;
        });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frame): array {
        return [['id' => $frame['id'], 'type' => 'vault.delete', 'payload' => ['data' => []]]];
    });

    app(DevelopmentInstanceDestroyProjection::class)->apply($destroy, ['status' => 'succeeded', 'run_id' => 'destroy-run',
        'result' => [], 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String()]);

    $this->assertDatabaseMissing('instances', ['id' => $instance->id]);
    $this->assertDatabaseMissing('instance_runtimes', ['instance_id' => $instance->id]);
    $this->assertDatabaseMissing('instance_node_service_targets', ['id' => $target]);
    $this->assertDatabaseHas('instance_node_service_targets', ['id' => $shared, 'instance_id' => $default->id]);
    expect($process->refresh()->getRawOriginal())->toEqual($processBefore);
    expect($default->runtime()->firstOrFail()->getRawOriginal())->toEqual($defaultRuntimeBefore);
});

it('refuses an unreadable private source before submitting migrate and releases its runtime reservation', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $project->update(['repository_url' => 'https://github.com/Platform11/servauto-app.git']);
    $instance = migrateCheckout($project, $node);
    $before = $instance->fresh()->getAttributes();
    $migration = migrateStart($instance);
    $socket = migrateVault();
    Http::fake(['api.github.com/repos/Platform11/servauto-app' => Http::response([], 404)]);

    expect(containerRefusal(fn () => (new StartAdmittedSwampRun($migration->id))->handle(
        app(StartSwampRun::class), app(ExpireOperationAction::class),
    )))->toBe('source.github_app_denied');

    expect($socket->sentTypes)->toBe([])
        ->and($migration->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($migration->result['error']['message'])->toContain('Platform11/servauto-app')
        ->and($instance->runtime()->exists())->toBeFalse()
        ->and($instance->refresh()->getAttributes())->toBe($before);
});

it('keeps a never-pushed local branch while probing the Project default for migration', function (): void {
    $project = containerDefaultProject();
    $node = containerDefaultNode();
    migrateMovedDefault($project, $node);
    $instance = migrateCheckout($project, $node, 'never-pushed');
    $migration = migrateStart($instance);
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $pem);
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'orbit-nckrtl', 'Orbit', 'nckrtl', 'user',
        'https://github.com/apps/orbit-nckrtl', $pem));
    Http::fake(['api.github.com/repos/nckrtl/tool/installation' => Http::response([], 404)]);
    $run = app(InstanceMigrateProjection::class)->input($migration);
    expect($run['intent']['migrate']['source']['access_denied_message'])->toBe('The GitHub App orbit-nckrtl cannot read nckrtl/tool: install it for that organisation or add the repository.')
        ->and($run['intent']['migrate']['source'])->toMatchArray(['repository' => $project->repository_url, 'branch' => 'main'])
        ->and($run['intent']['migrate']['branch'])->toBe('never-pushed')
        ->and($run['intent']['container']['branch'])->toBe('never-pushed')
        ->and($run['intent']['container']['repository'])->toBe($instance->checkout_path)
        ->and($run['execution_secrets']['source_credential'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'instance-migrate-source-'.$migration->id])
        ->and($migration->input['migrate'])->not->toHaveKey('source');
});

it('compares the moved checkout with the branch that rename recorded for a linked worktree', function (): void {
    $project = containerDefaultProject();
    $node = containerDefaultNode();
    $node->update(['ssh_host_fingerprint' => 'fixture']);
    migrateMovedDefault($project, $node);
    $instance = migrateCheckout($project, $node, 'ps-server');
    $instance->update(['source_layout' => 'worktree', 'branch' => 'powersync/server', 'branch_override' => 'powersync/server']);
    $path = $instance->checkout_path;
    NodeFact::query()->create(['node_id' => $node->id, 'kind' => 'checkout', 'subject' => $path, 'digest' => str_repeat('a', 64),
        'observed_at' => now(), 'received_at' => now(), 'data' => ['application' => $path, 'top_level' => $path,
            'common_dir' => '/home/orbit/apps/tool/default/.git', 'linked' => true, 'worktrees' => [$path], 'origin' => $project->repository_url,
            'owner' => 'orbit', 'head' => str_repeat('c', 40), 'branch' => 'fix/on-disk', 'default_branch' => 'main',
            'artisan' => 'absent', 'composer' => null, 'env' => null]]);
    $view = Mockery::mock(AgentStateView::class);
    $clock = (float) now()->format('U.u');
    $view->shouldReceive('subscriber')->andReturn(new AgentViewSubscriberHealth($clock, true, true, 1));
    $view->shouldReceive('node')->andReturn(new AgentNodeView($node->id, AgentViewFreshness::Fresh, receivedAt: $clock));
    $view->shouldReceive('lastSeen')->andReturn(null);
    app()->instance(AgentStateView::class, $view);

    app(RenameInstanceAction::class)->execute($instance->fresh(), new RenameInstanceData('fix/on-disk'));
    $migration = migrateStart($instance->fresh());

    expect($migration->input['migrate']['branch'])->toBe('fix/on-disk')
        ->and(app(InstanceMigrateProjection::class)->input($migration)['intent']['container']['branch'])->toBe('fix/on-disk');
});

it('preserves gh_cli migration without an App preflight or source credential copy', function (): void {
    $project = containerDefaultProject();
    $project->update(['source_access' => ProjectSourceAccess::GhCli]);
    $instance = migrateCheckout($project, containerDefaultNode());
    $migration = migrateStart($instance);
    $socket = migrateVault();
    Http::fake();
    $run = app(InstanceMigrateProjection::class)->input($migration);

    expect($run['intent']['migrate'])->not->toHaveKey('source')
        ->and($run['intent']['migrate']['branch'])->toBe('main')
        ->and($run['intent']['container']['repository'])->toBe($instance->checkout_path)
        ->and($run['execution_secrets'])->not->toHaveKey('source_credential')
        ->and(array_column($socket->puts, 'key'))->not->toContain('instance-migrate-source-'.$migration->id);
    Http::assertNothingSent();
});

it('reports carried container timer state and latest execution without recording automatic Operations', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $schedule = migrationSchedule($instance);
    $operation = migrateStart($instance);
    migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40),
        'schedules' => [['id' => $schedule->id, 'name' => $schedule->name, 'outcome' => 'activated']]]);
    $instance->refresh()->update(['provisioning_step' => 'active']);
    migrationScheduleLogSocket();
    $count = Operation::query()->count();
    $data = ScheduleData::fromModel($schedule->refresh(), observe: true)->toArray();
    expect($data)->toMatchArray(['timer_state' => 'active', 'last_run_status' => 'success', 'last_run_at' => '2026-11-12T12:00:00+00:00']);
    expect(Operation::query()->count())->toBe($count);
    $instance->runtime()->update(['power_state' => 'stopped']);
    $data = ScheduleData::fromModel($schedule->refresh(), observe: true)->toArray();
    expect($data['timer_state'])->toBe('inactive');
});

it('refuses container Schedule logs without waking a stopped container', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $schedule = migrationSchedule($instance);
    migrateEnd(migrateStart($instance), 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]);
    $instance->refresh()->update(['provisioning_step' => 'active']);
    $socket = migrationScheduleLogSocket('stopped');
    expect(containerRefusal(fn () => app(ShowScheduleLogsAction::class)->execute($schedule->refresh())))->toBe('schedule.target_inactive');
    expect(array_column($socket->sent, 'type'))->not->toContain('workflow.trigger.set');
    $instance->runtime()->update(['power_state' => 'stopped']);
    $socket = migrationScheduleLogSocket();
    expect(containerRefusal(fn () => app(ShowScheduleLogsAction::class)->execute($schedule->refresh())))->toBe('schedule.target_inactive');
    expect($socket->sent)->toBe([]);
});

it('keeps a completed migration successful when a carried Schedule was not activated', function (): void {
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $schedule = migrationSchedule($instance);
    $operation = migrateStart($instance);
    $outcome = ['id' => $schedule->id, 'name' => $schedule->name, 'outcome' => 'not_activated', 'cause' => 'schedule.container_activation_failed'];
    migrateEnd($operation, 'succeeded', ['ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40), 'schedules' => [$outcome]]);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result['schedules'])->toBe([$outcome])
        ->and($schedule->refresh()->trigger->value)->toBe('container');
});

/**
 * The success history is DERIVED from Operation 18776's STORED run record, not a captured WebSocket reply.
 * Mapping: jobs.jobName -> name, steps.stepName -> name, native status/times unchanged.
 * Root identity and commit are scrubbed. Inputs bind to this admission. The finish stdout selects the five
 * public identity/outcome fields from stored im-finish/migrate v100; all other outputs, inputs, plans,
 * provenance, hosts, names and vault references are omitted. The three other files are the pinned captures.
 */
function unavailableMigrateTransport(Operation $operation, string $capture, bool $empty = false): object
{
    $file = $capture === 'success' ? 'stored-success' : 'pinned-'.$capture;
    $history = json_decode((string) file_get_contents(base_path('tests/Fixtures/instance-migrate/'.$file.'.json')), true, flags: JSON_THROW_ON_ERROR);
    $offset = now()->getTimestampMs() - CarbonImmutable::parse($history['completedAt'])->getTimestampMs();
    foreach (['startedAt', 'completedAt'] as $field) {
        $history[$field] = CarbonImmutable::parse($history[$field])->addMilliseconds($offset)->toISOString();
    }
    foreach ($history['jobs'][0]['steps'] as &$step) {
        foreach (['startedAt', 'completedAt'] as $field) {
            if (isset($step[$field])) {
                $step[$field] = CarbonImmutable::parse($step[$field])->addMilliseconds($offset)->toISOString();
            }
        }
        if ($step['name'] === 'finish') {
            $receipt = $step['outputs']['stdout'];
            $step['outputs'] = $empty ? ['stdout' => ''] : [];
        }
    }
    unset($step);
    $history['workflowName'] = 'operation-instance-migrate';
    $history['inputs'] = ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'instance.migrate'];
    $operation->update(['status' => 'running', 'attempts' => 1, 'started_at' => $history['startedAt'],
        'state' => ['swamp_run_id' => $history['id']]]);
    $transport = (object) ['history' => $history, 'receipt' => $receipt, 'frames' => []];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $frame = [];
    $socket->shouldReceive('send')->andReturnUsing(function (array $message) use (&$frame, $transport): void {
        $frame = $message;
        $transport->frames[] = $message;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frame, $transport): array {
        $value = $frame['type'] === 'workflow.history.get' ? $transport->history : ['results' => []];

        return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $value]]];
    });
    app(SwampConnection::class)->release();
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'fixture-read-token']);

    return $transport;
}

it('releases a proven rollback with lost finish output through only the job after the grace', function (string $capture, bool $empty): void {
    $this->freezeTime();
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = migrateStart($instance);
    $transport = unavailableMigrateTransport($operation, $capture, $empty);
    $before = $instance->refresh()->getAttributes();
    $this->travel(61)->seconds();

    app()->call([new ReconcileSwampOperations, 'handle']);

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('instance.migrate_result_unavailable')
        ->and($operation->result['restored'])->toBeTrue()->and($operation->result['moved'])->toBeFalse()
        ->and($operation->result['recovery']['runtime_reservation'])->toBe('released')
        ->and($operation->result['recovery']['operation_id'])->toBe($operation->id)
        ->and($instance->runtime()->exists())->toBeFalse()
        ->and($instance->refresh()->getAttributes())->toBe($before)
        ->and(array_column($transport->frames, 'type'))->toContain('workflow.history.get')
        ->and(migrateStart($instance)->status)->toBe(OperationStatus::Queued);
})->with([
    'failure absent' => ['failure', false], 'failure empty' => ['failure', true],
    'timeout absent' => ['timeout', false], 'timeout empty' => ['timeout', true],
    'cancel absent' => ['cancel', false], 'cancel empty' => ['cancel', true],
]);

it('keeps the normal receipt when it arrives inside the missing output grace', function (string $capture): void {
    $this->freezeTime();
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = migrateStart($instance);
    $transport = unavailableMigrateTransport($operation, $capture);
    if ($capture === 'cancel') {
        $operation->update(['cancel_requested_at' => now()->subSecond()]);
    }
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($instance->runtime()->exists())->toBeTrue();
    $this->travel(20)->seconds();
    foreach ($transport->history['jobs'][0]['steps'] as &$step) {
        if ($step['name'] === 'finish') {
            $step['outputs']['stdout'] = $transport->receipt;
        }
    }
    unset($step);
    app()->call([new ReconcileSwampOperations, 'handle']);
    $expectedStatus = match ($capture) {
        'success' => OperationStatus::Succeeded,
        'cancel' => OperationStatus::Cancelled,
        default => OperationStatus::Failed,
    };
    expect($operation->refresh()->status)->toBe($expectedStatus)
        ->and($operation->error_code)->toBe(in_array($capture, ['success', 'cancel'], true) ? null : 'instance.migrate_processes_failed')
        ->and($operation->result)->toBe(json_decode($transport->receipt, true, flags: JSON_THROW_ON_ERROR))
        ->and($instance->runtime()->exists())->toBe($capture === 'success');
})->with(['failure', 'timeout', 'cancel', 'success']);

it('keeps unproven rollback or completed cutover unchanged and names owner recovery at the deadline', function (string $case, string $evidence): void {
    $this->freezeTime();
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = migrateStart($instance);
    $transport = unavailableMigrateTransport($operation, $case === 'completed cutover' ? 'success' : 'failure');
    foreach ($transport->history['jobs'][0]['steps'] as &$step) {
        if ($case === 'failed restore' && $step['name'] === 'restore') {
            $step['status'] = 'failed';
        } elseif ($case === 'uncertain route' && $step['name'] === 'route') {
            $step['status'] = 'succeeded';
        } elseif ($case === 'retirement began' && $step['name'] === 'retire') {
            $step['status'] = 'failed';
            $step['startedAt'] = $transport->history['startedAt'];
        } elseif ($case === 'preflight no-op' && $step['name'] === 'container') {
            $step['status'] = 'failed';
        }
    }
    unset($step);
    $instanceBefore = $instance->refresh()->getAttributes();
    $runtime = $instance->runtime()->sole();
    $runtimeBefore = $runtime->getAttributes();
    $this->travel(61)->seconds();
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Running);
    $this->travelTo(OperationRunBudgets::deadline($operation)->addSecond());
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('instance.migrate_result_unavailable')
        ->and($operation->result['restored'])->toBeNull()
        ->and($operation->result['missing_evidence'])->toContain('finish_output', $evidence)
        ->and($operation->result['recovery']['runtime_reservation'])->toBe('retained')
        ->and($operation->result['error_message'])->toContain("Operation [{$operation->id}]", $evidence, 'owner', 'No Orbit command')
        ->and($instance->refresh()->getAttributes())->toBe($instanceBefore)
        ->and($runtime->refresh()->getAttributes())->toBe($runtimeBefore);
    try {
        migrateStart($instance);
        test()->fail('A missing rollback or cutover receipt must name owner recovery.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('instance.migrate_recovery_required')
            ->and($exception->status)->toBe(409)
            ->and($exception->details['previous_operation_id'])->toBe($operation->id)
            ->and($exception->getMessage())->toContain("Operation [{$operation->id}]", $evidence, 'No Orbit command');
    }
})->with([
    'failed restore' => ['failed restore', 'checkout_restored'],
    'uncertain Route' => ['uncertain route', 'route_restored'],
    'started retirement' => ['retirement began', 'retirement_not_started'],
    'preflight did not change resources' => ['preflight no-op', 'container_preparation'],
    'stored successful run' => ['completed cutover', 'retirement_not_started'],
]);

it('caps the output grace at the original migrate deadline', function (): void {
    $this->freezeTime();
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = migrateStart($instance);
    $transport = unavailableMigrateTransport($operation, 'failure');
    $operation->update(['started_at' => now()->subSeconds(5690)]);
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Running);
    $this->travel(11)->seconds();
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect($operation->refresh()->error_code)->toBe('instance.migrate_result_unavailable')
        ->and($operation->result['restored'])->toBeTrue()
        ->and($instance->runtime()->exists())->toBeFalse();
});

it('treats an empty JSON report as lost finish output instead of a generic failure', function (): void {
    $this->freezeTime();
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = migrateStart($instance);
    $transport = unavailableMigrateTransport($operation, 'failure');
    foreach ($transport->history['jobs'][0]['steps'] as &$step) {
        if ($step['name'] === 'finish') {
            $step['outputs']['stdout'] = '{}';
        }
    }
    unset($step);
    $this->travel(61)->seconds();
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect($operation->refresh()->error_code)->toBe('instance.migrate_result_unavailable')
        ->and($operation->result['restored'])->toBeTrue()
        ->and($instance->runtime()->exists())->toBeFalse();
});

it('keeps the terminal observer on the missing output path before settlement can finish', function (): void {
    $this->freezeTime();
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = migrateStart($instance);
    $transport = unavailableMigrateTransport($operation, 'failure');
    app()->call([new ReconcileSwampOperations, 'handle']);
    $observation = ProblemCollectorState::query()->sole()->observation;
    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($observation['operation_settlement_count'])->toBe(1)
        ->and($observation['operation_settlement_oldest_completed_at'])->toBe($transport->history['completedAt']);
    $this->travel(61)->seconds();
    app()->call([new ReconcileSwampOperations, 'handle']);
    $observation = ProblemCollectorState::query()->sole()->observation;
    expect($operation->refresh()->error_code)->toBe('instance.migrate_result_unavailable')
        ->and($observation['operation_settlement_count'])->toBe(0)
        ->and($observation['operation_settlement_oldest_completed_at'])->toBeNull();
});

it('keeps verified native status and known failure facts when a captured migrate receipt is lost', function (string $capture, string $nativeStatus, string $detail): void {
    $this->freezeTime();
    $instance = migrateCheckout(containerDefaultProject(), containerDefaultNode());
    $operation = migrateStart($instance);
    $transport = unavailableMigrateTransport($operation, $capture);
    $this->travel(61)->seconds();
    app()->call([new ReconcileSwampOperations, 'handle']);
    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('instance.migrate_result_unavailable')
        ->and($operation->state['native_failure']['failed_step'] ?? null)->toBe('container-processes')
        ->and($operation->state['native_failure']['detail'] ?? null)->toBe($detail)
        ->and($operation->state['native_failure']['stderr_recorded'] ?? null)->toBeFalse()
        ->and($operation->failed_step)->toBe('container-processes')
        ->and($operation->result['native_status'] ?? null)->toBe($nativeStatus)
        ->and($operation->result)->not->toHaveKey('commit');
    if ($capture === 'cancel') {
        // Other callers retain the existing cancelled-root policy unless they opt in.
        expect(app(NativeStepFailure::class)->fromRun($transport->history, $operation))->toBeNull();
    }
})->with([
    'native cancellation' => ['cancel', 'cancelled', 'Command exited with code -1'],
    'native phase timeout' => ['timeout', 'failed', 'Command exited with code 124'],
]);
