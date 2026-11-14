<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Operations\CancelOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\DirectInstanceDestroyIntent;
use App\Domain\Instances\InstanceCompleteProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Problems\GatewayLogSignals;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\NativeRouteMutationInput;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Routes\RoutePublicationSites;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\SharedPhpPoolInput;
use App\Domain\Schedules\ScheduleRuntimeAccount;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\NativeRunProgress;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseExecutionIntent;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Models\Schedule;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Orbit\Extensions\Conn\Models\ConnProfile;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../../Domain/Instances/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    app(ExtensionStore::class)->set('conn', true);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-root-certificate');
    $control = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $control->roles()->create(['role' => 'swamp', 'status' => 'active']);
    $this->socket = new class implements WebSocketClient
    {
        public array $messages = [];

        public bool $scheduleRemovalFails = false;

        public ?Closure $onTriggerRemoval = null;

        private array $last = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public ?Closure $onSend = null;

        public function send(array $message): void
        {
            $this->messages[] = $this->last = $message;
            if ($this->onSend !== null) {
                ($this->onSend)($message);
            }
            if ($message['type'] === 'workflow.trigger.remove' && $this->onTriggerRemoval !== null) {
                $callback = $this->onTriggerRemoval;
                $this->onTriggerRemoval = null;
                $callback();
            }
        }

        public function receive(float $timeoutSeconds): array
        {
            if ($this->scheduleRemovalFails && $this->last['type'] === 'workflow.trigger.remove') {
                return [['id' => $this->last['id'], 'type' => 'error', 'error' => ['code' => 'fixture_unavailable']]];
            }
            if ($this->last['type'] !== 'vault.put') {
                return [['id' => $this->last['id'], 'type' => 'error', 'error' => ['code' => 'not_found']]];
            }

            return [['id' => $this->last['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                'vaultName' => $this->last['payload']['vaultName'], 'secretKey' => $this->last['payload']['key'], 'vaultType' => 'local_encryption',
            ]]]];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $this->socket);
    // The workspace read is one SSH call to the Instance's Node; this stand-in records it and answers as the reader would.
    $this->workspace = new class implements SshExecutor
    {
        /** @var list<array{SshConnection, RemoteCommand}> */
        public array $calls = [];

        public ?string $answer = null;

        public int $exitCode = 0;

        public ?Throwable $throws = null;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls[] = [$connection, $command];
            if ($this->throws !== null) {
                throw $this->throws;
            }

            return new CommandResult($this->exitCode, $this->answer ?? '', '', 5, false);
        }
    };
    app()->instance(SshExecutor::class, $this->workspace);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/fixture/key');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/fixture/known_hosts');
    app()->instance(KnownHostsStore::class, $hosts);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/fixture-ca', 'swamp.run_token' => 'fixture-token']);
    $this->node = containerDefaultNode();
    $this->phone = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'phone', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.77', 'public_ssh_host' => '10.44.0.77', 'user' => 'orbit',
    ]));
    $this->phone->accessibleNodes()->attach($this->node->id);
    $create = containerCreate(containerDefaultProject(), $this->node);
    $create->forceFill(['status' => 'succeeded', 'finished_at' => now()])->save();
    $this->default = Instance::query()->findOrFail($create->target_id);
    $this->default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    $this->default->runtime()->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);
});

function connRemovalClone(bool $migrated = false): Instance
{
    $default = test()->default;
    $instance = Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $default->node_id, 'name' => 'feature',
        'status' => InstanceState::Active, 'branch' => 'feature', 'starting_commit' => str_repeat('c', 40),
        'checkout_path' => '/fixture/reservation', 'source_layout' => 'checkout', 'first_setup_pending' => false]);
    $instance->update(['checkout_path' => InstanceContainer::workspaceOnNode($instance)]);
    app(InstanceContainer::class)->reserve($instance, $default->node, $migrated ? null : $default);

    return $instance->refresh();
}

/** Every resource is an isolated test record; no Node, container, database server or T3 is contacted. */
function connRemovalResources(Instance $instance): void
{
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => 'feature.fixture.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active', 'sites_published' => true]);
    Schedule::query()->create(['target_type' => 'instance', 'target_id' => $instance->id, 'host_node_id' => $instance->node_id,
        'name' => 'nightly', 'calendar' => '*-*-* 03:00:00', 'command' => 'true', 'timeout_seconds' => 60,
        'desired_timer_state' => 'enabled', 'status' => 'active', 'trigger' => 'swamp',
        'swamp_workflow_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']);
    DatabaseConnection::query()->create(['slug' => 'feature-db', 'driver' => 'sqlite', 'node_id' => $instance->node_id,
        'owner_instance_id' => $instance->id, 'path' => $instance->checkout_path.'/database.sqlite']);
    $environment = ConnEnvironment::query()->forceCreate(['instance_id' => $instance->id, 'environment_id' => 'feature-env',
        'label' => 'feature', 'url' => 'http://10.44.0.7:3800', 'node_id' => $instance->node_id,
        'admin_session' => 'fixture-session', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    ConnPairing::query()->create(['node_id' => test()->phone->id, 'conn_environment_id' => $environment->id,
        'client_label' => 'phone', 'credential' => 'fixture-link']);
}

/** @return array<string,array<mixed>> */
function connRemovalStored(): array
{
    $state = [];
    foreach (['instances', 'instance_runtimes', 'operations', 'schedules', 'routes', 'route_targets', 'database_connections',
        'database_connection_targets', 'route_publication_holds', 'conn_environments', 'conn_pairings', 'instance_removals', 'instance_removal_members', (new DatabaseExecutionIntent)->getTable()] as $table) {
        $state[$table] = DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    return $state;
}

/** @param array<string,mixed> $body */
function connRemovalCall(int $id, array $body, string $key = 'fixture-remove'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => test()->phone->wireguard_ip])
        ->postJson('/api/v1/conn/instances/'.$id.'/destroy', $body, ['Idempotency-Key' => $key]);
}

function connRemovalRead(Operation $operation): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => test()->phone->wireguard_ip])
        ->getJson('/api/v1/conn/operations/'.$operation->id);
}

function connRemovalSucceed(Operation $operation): void
{
    $operation->update(['status' => 'running', 'state' => [...$operation->state, 'swamp_run_id' => 'fixture-destroy']]);
    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, [
        'status' => 'succeeded', 'run_id' => 'fixture-destroy', 'result' => [],
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
    ]);
}

/** @return array<string, mixed> What the fixed reader prints for a running workspace. */
function connWorkAnswer(int $modified = 0, int $untracked = 0, int $commits = 0, int $stashes = 0, string $baseline = 'recorded'): array
{
    $names = static fn (string $prefix, int $count): array => array_map(static fn (int $n): string => $prefix.$n, $count === 0 ? [] : range(1, min($count, 5)));

    return ['state' => $modified + $untracked + $commits + $stashes > 0 ? 'dirty' : 'clean', 'reason' => null, 'baseline' => $baseline, 'head' => 'feature-x',
        'modified' => ['count' => $modified, 'names' => $names('app/File', $modified)],
        'untracked' => ['count' => $untracked, 'names' => $names('new', $untracked)],
        'commits' => ['count' => $commits, 'branch_count' => $commits > 0 ? 1 : 0, 'branches' => $commits > 0 ? [['name' => 'feature-x', 'count' => $commits]] : []],
        'stashes' => ['count' => $stashes, 'names' => $names('stash@{', $stashes)]];
}

function connWorkRead(int $id): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => test()->phone->wireguard_ip])->getJson('/api/v1/conn/instances/'.$id.'/work');
}

it('refuses unknown work without touching Schedules Routes databases pairings or any Operation', function (): void {
    $instance = connRemovalClone();
    connRemovalResources($instance);
    $before = connRemovalStored();
    $messages = $this->socket->messages;
    $this->workspace->answer = json_encode(['state' => 'unknown', 'reason' => 'stopped']);
    connRemovalCall($instance->id, ['acknowledge_unsaved' => false])->assertConflict()
        ->assertJsonPath('error.code', 'instance.unsaved_work')
        ->assertJsonPath('error.details.state', 'unknown')->assertJsonPath('error.details.reason', 'stopped')
        ->assertJsonPath('error.details.uncommitted', null)->assertJsonPath('error.details.unpushed', null);
    expect(connRemovalStored())->toBe($before)->and($this->socket->messages)->toBe($messages);
});

it('reads the workspace for the remove sheet with the same answer the destroy admits by', function (): void {
    $instance = connRemovalClone();
    $this->workspace->answer = json_encode(connWorkAnswer(modified: 3, untracked: 1, commits: 2, stashes: 1));
    $read = connWorkRead($instance->id)->assertOk()
        ->assertJsonPath('data.instance_id', $instance->id)->assertJsonPath('data.state', 'dirty')->assertJsonPath('data.reason', null)
        ->assertJsonPath('data.baseline', 'recorded')->assertJsonPath('data.head', 'feature-x')
        ->assertJsonPath('data.uncommitted', 4)->assertJsonPath('data.unpushed', 2)
        ->assertJsonPath('data.modified', ['count' => 3, 'names' => ['app/File1', 'app/File2', 'app/File3']])
        ->assertJsonPath('data.commits.branches', [['name' => 'feature-x', 'count' => 2]])
        ->assertJsonPath('data.stashes.count', 1);
    expect($read->json('data.read_at'))->toBeString()->and($read->json('data.duration_ms'))->toBeInt();

    [$connection, $command] = $this->workspace->calls[0];
    expect($connection->host)->toBe($this->node->wireguard_ip)->and($connection->commandTimeout)->toEqual(4)
        ->and($command->timeout)->toEqual(4)
        ->and(array_slice($command->arguments, 0, 7))->toBe(['timeout', '3', 'python3', '-', 'read', 'orbit-instances', 'instance-'.$instance->id])
        // No baseline yet: the read compares with the Project default's container.
        ->and(array_slice($command->arguments, 8))->toBe(['orbit-instances', 'instance-'.$this->default->id])
        ->and($command->input)->toContain("BUDGET = 2\n");

    // The refusal carries the same object; the installed Conn's keys {uncommitted, unpushed, state} are a subset.
    $refused = connRemovalCall($instance->id, ['acknowledge_unsaved' => false])->assertConflict()
        ->assertJsonPath('error.code', 'instance.unsaved_work');
    $details = $refused->json('error.details');
    expect(Arr::only($details, ['uncommitted', 'unpushed', 'state']))->toEqual(['uncommitted' => 4, 'unpushed' => 2, 'state' => 'dirty'])
        ->and(Arr::except($details, ['read_at', 'duration_ms']))->toBe(Arr::except($read->json('data'), ['read_at', 'duration_ms']))
        ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse();
});

it('admits a clean workspace without consent', function (): void {
    $instance = connRemovalClone();
    $this->workspace->answer = json_encode(connWorkAnswer());
    connRemovalCall($instance->id, ['acknowledge_unsaved' => false])->assertAccepted()
        ->assertJsonPath('data.kind', 'instance.destroy')->assertJsonPath('data.input.force', true);
    expect($this->workspace->calls)->toHaveCount(1);
});

it('reads no workspace when consent is given', function (): void {
    connRemovalCall(connRemovalClone()->id, ['acknowledge_unsaved' => true])->assertAccepted();
    expect($this->workspace->calls)->toBe([]);
});

it('answers unknown for each reason the reader gives and unreachable for a failed or garbled read', function (?string $answer, int $exitCode, string $reason): void {
    $instance = connRemovalClone();
    $this->workspace->answer = $answer;
    $this->workspace->exitCode = $exitCode;
    connWorkRead($instance->id)->assertOk()->assertJsonPath('data.state', 'unknown')->assertJsonPath('data.reason', $reason)
        ->assertJsonPath('data.uncommitted', null)->assertJsonPath('data.modified', null)->assertJsonPath('data.baseline', null);
    connRemovalCall($instance->id, ['acknowledge_unsaved' => false])->assertConflict()
        ->assertJsonPath('error.code', 'instance.unsaved_work')->assertJsonPath('error.details.reason', $reason);
})->with([
    'stopped' => ['{"state":"unknown","reason":"stopped"}', 0, 'stopped'],
    'asleep' => ['{"state":"unknown","reason":"asleep"}', 0, 'asleep'],
    'timeout' => ['{"state":"unknown","reason":"timeout"}', 0, 'timeout'],
    'not a repository' => ['{"state":"unknown","reason":"not_a_repository"}', 0, 'not_a_repository'],
    'unlisted reason' => ['{"state":"unknown","reason":"other"}', 0, 'unreachable'],
    'ssh failure' => [null, 255, 'unreachable'],
    'garbled' => ['{"state":"clean"}', 0, 'unreachable'],
    'not json' => ['Traceback', 1, 'unreachable'],
    'outer bound' => ['', 124, 'timeout'],
]);

it('answers timeout when the SSH call itself runs out of time', function (): void {
    $instance = connRemovalClone();
    $this->workspace->throws = new ProcessTimedOutException(new Process(['true']), ProcessTimedOutException::TYPE_GENERAL);
    connWorkRead($instance->id)->assertOk()->assertJsonPath('data.state', 'unknown')->assertJsonPath('data.reason', 'timeout');
    // The API command deadline wraps the process timeout; it is the same timeout.
    $this->workspace->throws = new ResourceOperationException('command.deadline_exceeded', 'The command deadline was exceeded.', 504,
        new ProcessTimedOutException(new Process(['true']), ProcessTimedOutException::TYPE_GENERAL));
    connWorkRead($instance->id)->assertOk()->assertJsonPath('data.reason', 'timeout');
    $this->workspace->throws = new RuntimeException('connection refused');
    connWorkRead($instance->id)->assertOk()->assertJsonPath('data.reason', 'unreachable');
});

it('sends the recorded baseline to the reader and reads a default without one', function (): void {
    $instance = connRemovalClone();
    $baseline = ['heads' => [str_repeat('a', 40)], 'stashes' => [], 'untracked' => ['notes.txt']];
    $instance->runtime()->update(['workspace_baseline' => $baseline, 'workspace_baseline_at' => now()]);
    $this->workspace->answer = json_encode(connWorkAnswer());
    connWorkRead($instance->id)->assertOk()->assertJsonPath('data.state', 'clean');
    $command = $this->workspace->calls[0][1];
    expect(array_slice($command->arguments, 4))->toHaveCount(4)
        ->and($command->input)->toContain('BASELINE = json.loads(base64.b64decode("'.base64_encode(json_encode($baseline)).'"))');

    $this->default->runtime()->update(['workspace_baseline' => $baseline]);
    $this->workspace->answer = json_encode(connWorkAnswer(commits: 447, baseline: 'none'));
    connWorkRead($this->default->id)->assertOk()->assertJsonPath('data.unpushed', 447);
    $command = $this->workspace->calls[1][1];
    expect(array_slice($command->arguments, 4))->toHaveCount(4)
        ->and($command->input)->toContain('BASELINE = json.loads(base64.b64decode("'.base64_encode('null').'"))');
});

it('keeps the read behind the destroy access rule and to container Instances', function (): void {
    $instance = connRemovalClone();
    $host = Instance::query()->create(['project_id' => $this->default->project_id, 'node_id' => $this->node->id, 'name' => 'host-checkout',
        'status' => 'active', 'checkout_path' => '/fixture/host-checkout', 'source_layout' => 'checkout']);
    connWorkRead($host->id)->assertConflict()->assertJsonPath('error.code', 'instance.not_container');
    connWorkRead(999999)->assertNotFound();
    $this->phone->accessibleNodes()->detach();
    connWorkRead($instance->id)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    expect($this->workspace->calls)->toBe([]);
});

it('admits the forced engine and follows its Conn-owned Operation through successful deletion and replay', function (): void {
    $instance = connRemovalClone();
    connRemovalResources($instance);
    $response = connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted()
        ->assertJsonPath('data.kind', 'instance.destroy')->assertJsonPath('data.target_type', 'instance')
        ->assertJsonPath('data.target_id', $instance->id)->assertJsonPath('data.input.force', true);
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->triggered_by)->toBe('node:'.$this->phone->id);
    connRemovalRead($operation)->assertOk()->assertJsonPath('data.status', 'queued');
    $repeated = connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted();
    expect($repeated->json('data.id'))->toBe($operation->id);

    $operation->update(['status' => 'running', 'state' => [...$operation->state, 'swamp_run_id' => 'fixture-destroy']]);
    connRemovalRead($operation)->assertOk()->assertJsonPath('data.status', 'running');
    connRemovalSucceed($operation->refresh());
    connRemovalRead($operation)->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result.removed', true);
    connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted()->assertJsonPath('data.id', $operation->id);
    connRemovalCall($instance->id, ['acknowledge_unsaved' => false])->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    expect($instance->fresh())->toBeNull()->and($this->default->fresh())->not->toBeNull()
        ->and(ConnEnvironment::query()->where('instance_id', $instance->id)->exists())->toBeFalse()
        ->and(ConnPairing::query()->exists())->toBeFalse()
        ->and(Route::query()->where('domain', 'feature.fixture.test')->exists())->toBeFalse()
        ->and(DatabaseConnection::query()->where('owner_instance_id', $instance->id)->exists())->toBeFalse()
        ->and(Schedule::query()->where('target_id', $instance->id)->exists())->toBeFalse();
    $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])->getJson('/api/v1/conn/environments')
        ->assertOk()->assertJsonCount(0, 'data');
    connRemovalCall($instance->id, ['acknowledge_unsaved' => true], 'new-key')->assertNotFound();
});

it('drops the removed clone\'s attached threads from every profile when the destroy succeeds', function (): void {
    $instance = connRemovalClone();
    connRemovalResources($instance);
    ConnEnvironment::query()->create(['environment_id' => 'host-env', 'label' => 'host', 'url' => 'http://10.44.0.7:3773',
        'node_id' => $instance->node_id, 'admin_session' => 'fixture-session', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $profile = ConnProfile::query()->create(['name' => 'conn-lane-test', 'settings' => ['workspaces' => []], 'settings_version' => 1]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])->patchJson('/api/v1/conn/profiles/'.$profile->id.'/settings', ['sections' => [
        'attachedThreads' => ['version' => 0, 'value' => ['schema' => 1, 'threads' => [
            ['environmentId' => 'feature-env', 'threadId' => 'thread-1'], ['environmentId' => 'host-env', 'threadId' => 'thread-2'],
        ]]],
    ]])->assertOk();

    $operation = Operation::query()->findOrFail(connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted()->json('data.id'));
    expect($profile->fresh()?->settings_version)->toBe(2);
    connRemovalSucceed($operation->refresh());

    $profile = $profile->fresh();
    expect($instance->fresh())->toBeNull()
        ->and($profile?->settings['attachedThreads'])->toBe(['schema' => 1, 'threads' => [['environmentId' => 'host-env', 'threadId' => 'thread-2']]])
        ->and($profile?->section_meta['attachedThreads']['version'])->toBe(2)
        ->and($profile?->section_meta['attachedThreads']['updated_by_node_id'])->toBeNull()
        ->and($profile?->settings_version)->toBe(3);
});

it('finishes the destroy and drops the other profiles\' threads when a profile document is not JSON, and logs a Problem signal', function (): void {
    $directory = sys_get_temp_dir().'/conn-drop-'.Str::random(8);
    mkdir($directory);
    config(['logging.channels.conn-drop' => ['driver' => 'single', 'path' => $directory.'/laravel.log', 'level' => 'debug']]);
    app('log')->setDefaultDriver('conn-drop');
    try {
        $instance = connRemovalClone();
        connRemovalResources($instance);
        $broken = ConnProfile::query()->create(['name' => 'broken', 'settings' => ['workspaces' => []], 'settings_version' => 1]);
        DB::table('conn_profiles')->where('id', $broken->id)->update(['settings' => '{"attachedThreads": {"threads": [{"environmentId": "feature-env"']);
        $profile = ConnProfile::query()->create(['name' => 'conn-lane-test', 'settings' => ['workspaces' => []], 'settings_version' => 1]);
        $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])->patchJson('/api/v1/conn/profiles/'.$profile->id.'/settings', ['sections' => [
            'attachedThreads' => ['version' => 0, 'value' => ['schema' => 1, 'threads' => [['environmentId' => 'feature-env', 'threadId' => 'thread-1']]]],
        ]])->assertOk();

        $operation = Operation::query()->findOrFail(connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted()->json('data.id'));
        connRemovalSucceed($operation->refresh());

        connRemovalRead($operation)->assertOk()->assertJsonPath('data.status', 'succeeded');
        expect($instance->fresh())->toBeNull()
            ->and(ConnEnvironment::query()->where('environment_id', 'feature-env')->exists())->toBeFalse()
            ->and($profile->fresh()?->settings['attachedThreads']['threads'])->toBe([])
            ->and(DB::table('conn_profiles')->where('id', $broken->id)->value('settings'))->toStartWith('{"attachedThreads"');
        $log = (string) file_get_contents($directory.'/laravel.log');
        expect($log)->toContain('conn.attached_threads_drop_failed')->toContain("from profile {$broken->id}.")->toContain('feature-env');
        $signals = app(GatewayLogSignals::class)->collect($directory, base_path(), ['path' => $directory.'/laravel.log', 'inode' => (int) fileinode($directory.'/laravel.log'), 'offset' => 0])['signals'];
        expect($signals)->toHaveCount(1)
            ->and($signals[0]['exception_class'])->toBe('RuntimeException')
            ->and($signals[0]['frame'])->toStartWith('app/');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('keeps default and non-container refusals local to Conn', function (bool $container, string $code): void {
    $instance = $container ? $this->default : Instance::query()->create([
        'project_id' => $this->default->project_id, 'node_id' => $this->node->id, 'name' => 'host-checkout',
        'status' => 'active', 'checkout_path' => '/fixture/host-checkout', 'source_layout' => 'checkout',
    ]);
    $before = connRemovalStored();
    connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertConflict()->assertJsonPath('error.code', $code);
    expect(connRemovalStored())->toBe($before);
})->with(['default' => [true, 'instance.default_protected'], 'host' => [false, 'instance.not_container']]);

it('requires access to the Instance Node before removal or replay', function (): void {
    $instance = connRemovalClone();
    $this->phone->accessibleNodes()->detach();
    $before = connRemovalStored();
    connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    expect(connRemovalStored())->toBe($before);
});

it('runs stored engine blockers before any Schedule effect', function (string $blocker, string $code): void {
    $instance = connRemovalClone();
    connRemovalResources($instance);
    if ($blocker === 'database') {
        $database = DatabaseConnection::query()->where('owner_instance_id', $instance->id)->firstOrFail();
        DatabaseConnectionTarget::query()->create(['database_connection_id' => $database->id, 'instance_id' => $this->default->id, 'prefix' => 'DB']);
    } elseif ($blocker === 'clone') {
        Instance::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
            'name' => 'incomplete-clone', 'clone_candidate_id' => $instance->id, 'checkout_path' => '/fixture/clone']);
    } elseif ($blocker === 'account') {
        $this->node->update(['user' => 'root']);
    } else {
        Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => $instance->id,
            'node_id' => $instance->node_id, 'status' => 'running', 'input' => [], 'state' => []]);
    }
    $before = connRemovalStored();
    $messages = $this->socket->messages;
    connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertConflict()->assertJsonPath('error.code', $code);
    expect(connRemovalStored())->toBe($before)->and($this->socket->messages)->toBe($messages);

    // A refused pre-check must not have queued any work either.
    app(SwampDispatch::class)->copy(static function (): void {});
    expect(connRemovalStored())->toBe($before)->and($this->socket->messages)->toBe($messages);
})->with(['owned database used elsewhere' => ['database', 'database.connection_attached'],
    'existing destroy' => ['destroy', 'operation.target_busy'],
    'incomplete clone' => ['clone', 'instance.clone_in_progress'],
    'unsupported Node account' => ['account', 'instance.destroy_unsupported']]);

it('keeps a migrated Instances retired Git fact in the result after deletion', function (): void {
    $instance = connRemovalClone(true);
    $path = '/fixture/retired/feature.git';
    $at = '2026-11-12T23:00:00+00:00';
    $instance->update(['retained_git_path' => $path, 'retained_git_at' => $at]);
    $response = connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    connRemovalSucceed($operation);
    connRemovalRead($operation)->assertOk()->assertJsonPath('data.result.retained_git_path', $path)
        ->assertJsonPath('data.result.retained_git_at', $at);
    expect($instance->fresh())->toBeNull();
});

it('does not expose another callers destroy Operation', function (): void {
    $instance = connRemovalClone();
    $response = connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $operation->update(['triggered_by' => 'node:'.$this->node->id]);
    connRemovalRead($operation)->assertNotFound();
});

it('requires a key and a strict JSON acknowledgement', function (): void {
    $instance = connRemovalClone();
    $before = connRemovalStored();
    $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
        ->postJson('/api/v1/conn/instances/'.$instance->id.'/destroy', ['acknowledge_unsaved' => true])->assertUnprocessable();
    foreach ([[], ['acknowledge_unsaved' => 1], ['acknowledge_unsaved' => 'true'],
        ['acknowledge_unsaved' => true, 'force' => true]] as $body) {
        connRemovalCall($instance->id, $body)->assertUnprocessable();
    }
    expect(connRemovalStored())->toBe($before);
});

it('keeps the existing Schedule outage boundary for the CLI engine and Conn', function (bool $conn): void {
    $instance = connRemovalClone();
    connRemovalResources($instance);
    $before = connRemovalStored();
    $this->socket->scheduleRemovalFails = true;
    $response = $conn ? connRemovalCall($instance->id, ['acknowledge_unsaved' => true])
        : $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->deleteJson('/api/v1/instances/'.$instance->id, ['force' => true]);
    $response->assertStatus(503)->assertJsonPath('error.code', 'swamp.schedule_publish_unavailable');
    $after = connRemovalStored();
    // Main changes only Schedule failure evidence, with no removal intent or Operation.
    $before['schedules'] = $after['schedules'];
    expect($after)->toBe($before)
        ->and($instance->refresh()->status)->toBe(InstanceState::Active)
        ->and(Route::query()->where('domain', 'feature.fixture.test')->value('status'))->toBe(RouteStatus::Active)
        ->and(Route::query()->where('domain', 'feature.fixture.test')->value('sites_published'))->toBeTrue()
        ->and(Schedule::query()->where('target_id', $instance->id)->value('error_code'))->toBe('swamp.schedule_publish_unavailable');
})->with(['CLI' => [false], 'Conn' => [true]]);

it('refuses missing null and non-boolean consent in a JSON object without effects', function (string $content): void {
    $instance = connRemovalClone();
    connRemovalResources($instance);
    $before = connRemovalStored();
    $messages = $this->socket->messages;
    $this->call('POST', '/api/v1/conn/instances/'.$instance->id.'/destroy', [], [], [], [
        'REMOTE_ADDR' => $this->phone->wireguard_ip, 'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'fixture-invalid-consent',
    ], $content)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(connRemovalStored())->toBe($before)->and($this->socket->messages)->toBe($messages);
})->with(['omitted member' => ['{}'], 'empty body' => [''], 'null' => ['{"acknowledge_unsaved":null}'],
    'string' => ['{"acknowledge_unsaved":"true"}'], 'zero' => ['{"acknowledge_unsaved":0}'], 'one' => ['{"acknowledge_unsaved":1}']]);

it('keeps a Conn destroy readable at every terminal status when failure detail is absent', function (string $status): void {
    $instance = connRemovalClone();
    $response = connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $operation->update(['status' => $status, 'finished_at' => now(), 'error_code' => 'fixture.destroy_failed']);
    connRemovalRead($operation)->assertOk()->assertJsonPath('data.status', $status)
        ->assertJsonPath('data.error_code', 'fixture.destroy_failed')->assertJsonPath('data.error', null);
})->with(['failed', 'cancelled', 'interrupted']);

it('refuses an unpinned workload or publication Node before any Schedule effect for both callers', function (bool $conn, bool $publication): void {
    $instance = connRemovalClone();
    connRemovalResources($instance);
    if ($publication) {
        $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'fixture-unpinned-dns', 'status' => 'active',
            'platform' => 'linux', 'wireguard_ip' => '10.44.0.95', 'public_ssh_host' => '10.44.0.95', 'user' => 'orbit']));
        $node->roles()->create(['role' => 'vpn', 'status' => 'active']);
    } else {
        $node = $this->node;
    }
    $node->forceFill(['ssh_host_key_type' => null, 'ssh_host_key' => null])->save();
    $before = connRemovalStored();
    $messages = $this->socket->messages;
    $response = $conn ? connRemovalCall($instance->id, ['acknowledge_unsaved' => true])
        : $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->deleteJson('/api/v1/instances/'.$instance->id, ['force' => true]);
    $response->assertStatus(503)->assertJsonPath('error.code', 'swamp.ssh_target_unavailable');
    expect(connRemovalStored())->toBe($before)->and($this->socket->messages)->toBe($messages);
})->with(['CLI workload' => [false, false], 'CLI publication' => [false, true],
    'Conn workload' => [true, false], 'Conn publication' => [true, true]]);

it('refuses a Caddy render failure before any Schedule effect for both callers', function (bool $conn): void {
    $instance = connRemovalClone();
    connRemovalResources($instance);
    app()->instance(NodeCaddyfileRenderer::class, new NodeCaddyfileRenderer([new class implements NodeCaddySiteSource
    {
        public function order(): int
        {
            return 0;
        }

        public function sites(Node $node): array
        {
            throw new RuntimeException('fixture render problem');
        }
    }]));
    $before = connRemovalStored();
    $messages = $this->socket->messages;
    $response = $conn ? connRemovalCall($instance->id, ['acknowledge_unsaved' => true])
        : $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->deleteJson('/api/v1/instances/'.$instance->id, ['force' => true]);
    $response->assertConflict()->assertJsonPath('error.code', 'caddy.render_failed');
    expect(connRemovalStored())->toBe($before)->and($this->socket->messages)->toBe($messages);
})->with(['CLI' => [false], 'Conn' => [true]]);

it('prepares PHP and private DNS without changing any stored state or dispatch', function (): void {
    $this->mock(ScheduleRuntimeAccountResolver::class)->shouldReceive('resolve')
        ->andReturn(new ScheduleRuntimeAccount('orbit', 'orbit', '/home/orbit', '/bin/bash'));
    $project = $this->default->project;
    $project->update(['type' => ProjectType::LaravelApp, 'php_version' => '8.5', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'benchmark',
        'status' => InstanceState::Active, 'branch' => 'benchmark', 'starting_commit' => str_repeat('c', 40),
        'checkout_path' => '/home/orbit/apps/tool/benchmark', 'source_layout' => 'checkout', 'selected_php_version' => '8.5', 'first_setup_pending' => false]);
    connRemovalResources($instance);
    $dns = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'fixture-benchmark-dns', 'status' => 'active',
        'platform' => 'linux', 'wireguard_ip' => '10.44.0.95', 'public_ssh_host' => '10.44.0.95', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $dns->roles()->create(['role' => 'vpn', 'status' => 'active']);
    expect((array) app(SharedPhpPoolInput::class)->forNode($this->node)['configurations'])->toHaveKey('8.5');
    $snapshot = app(StartInstanceDestroyAction::class)->snapshot($instance, true);

    $tables = DB::getSchemaBuilder()->getTableListing();
    $stored = static fn (): array => array_combine($tables, array_map(static fn (string $table): array => DB::table($table)->get()->toArray(), $tables));
    $before = $stored();
    $messages = $this->socket->messages;
    $requests = Http::recorded()->count();
    [$prepared] = app(DirectInstanceDestroyIntent::class)->prepare($snapshot);
    expect($prepared['native_destroy']['route_mutations']['schema_version'])->toBe(2)
        ->and($prepared['native_destroy']['route_mutations']['artifacts']['php'])->not->toBeEmpty()
        ->and($prepared['native_destroy']['route_mutations']['artifacts']['dns'])->not->toBeEmpty()
        ->and($prepared['native_destroy']['execution_secrets'])->toBe([])
        ->and($prepared['direct_destroy']['database'])->not->toHaveKey('intent_id')
        ->and($stored())->toEqual($before)->and($this->socket->messages)->toBe($messages)
        ->and(Http::recorded()->count())->toBe($requests)
        ->and(app(RoutePublicationSites::class)->current())->toBeNull();
    app(SwampDispatch::class)->copy(static function (): void {});
    expect($stored())->toEqual($before)->and($this->socket->messages)->toBe($messages);
});

it('keeps the normal destroy effects and final state with a discarded dry run and fresh publication for both callers', function (bool $conn): void {
    $instance = connRemovalClone();
    connRemovalResources($instance);
    $publication = app(NativeRouteMutationInput::class);
    $spy = Mockery::mock($publication);
    $spy->shouldReceive('capture')->twice()->andReturnUsing(fn (...$args): array => $publication->capture(...$args));
    app()->instance(NativeRouteMutationInput::class, $spy);
    $start = count($this->socket->messages);
    $response = $conn ? connRemovalCall($instance->id, ['acknowledge_unsaved' => true])
        : $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->deleteJson('/api/v1/instances/'.$instance->id, ['force' => true]);
    $response->assertAccepted()->assertJsonPath('data.kind', 'instance.destroy')->assertJsonPath('data.input.force', true);
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $frames = array_slice($this->socket->messages, $start);
    expect(array_column(array_slice($frames, 0, 3), 'type'))->toBe(['workflow.trigger.remove', 'workflow.delete', 'vault.delete'])
        ->and($instance->refresh()->status)->toBe(InstanceState::Removing)
        ->and(Route::query()->where('domain', 'feature.fixture.test')->value('status'))->toBe(RouteStatus::Retiring)
        ->and($operation->input['native_destroy']['execution_secrets'])->toBe([])
        ->and($operation->input['direct_destroy']['database']['intent_id'])->toBeInt();
    connRemovalSucceed($operation);
    expect($instance->fresh())->toBeNull()->and($this->default->fresh())->not->toBeNull()
        ->and(Route::query()->where('domain', 'feature.fixture.test')->exists())->toBeFalse()
        ->and(DatabaseConnection::query()->where('owner_instance_id', $instance->id)->exists())->toBeFalse()
        ->and(Schedule::query()->where('target_id', $instance->id)->exists())->toBeFalse()
        ->and(ConnEnvironment::query()->where('instance_id', $instance->id)->exists())->toBeFalse()
        ->and(ConnPairing::query()->exists())->toBeFalse()
        ->and($operation->refresh()->status->value)->toBe('succeeded');
})->with(['CLI' => [false], 'Conn' => [true]]);

it('refuses an outside attachment made during Schedule removal for both callers', function (bool $conn): void {
    $instance = connRemovalClone();
    connRemovalResources($instance);
    $database = DatabaseConnection::query()->where('owner_instance_id', $instance->id)->sole();
    $before = connRemovalStored();
    $this->socket->onTriggerRemoval = function () use ($database): void {
        DatabaseConnectionTarget::query()->create(['database_connection_id' => $database->id,
            'instance_id' => $this->default->id, 'prefix' => 'DB', 'network' => 'private']);
    };
    $response = $conn ? connRemovalCall($instance->id, ['acknowledge_unsaved' => true])
        : $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->deleteJson('/api/v1/instances/'.$instance->id, ['force' => true]);
    $response->assertConflict()->assertJsonPath('error.code', 'database.connection_attached');
    $after = connRemovalStored();
    $before['schedules'] = $after['schedules'];
    $before['database_connection_targets'] = $after['database_connection_targets'];
    expect($after)->toBe($before)
        ->and($instance->refresh()->status)->toBe(InstanceState::Active)
        ->and(Route::query()->where('domain', 'feature.fixture.test')->value('status'))->toBe(RouteStatus::Active)
        ->and(DatabaseConnectionTarget::query()->where('database_connection_id', $database->id)->where('instance_id', $this->default->id)->exists())->toBeTrue()
        ->and(Schedule::query()->where('target_id', $instance->id)->value('status')->value)->toBe('removing')
        ->and(array_column(array_slice($this->socket->messages, -3), 'type'))->toBe(['workflow.trigger.remove', 'workflow.delete', 'vault.delete']);
})->with(['CLI' => [false], 'Conn' => [true]]);

it('includes a VPN Node activated during Schedule removal in fresh admission for both callers', function (bool $conn): void {
    $instance = connRemovalClone();
    connRemovalResources($instance);
    $dns = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'fixture-late-dns', 'status' => 'active',
        'platform' => 'linux', 'wireguard_ip' => '10.44.0.95', 'public_ssh_host' => '10.44.0.95', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $role = $dns->roles()->create(['role' => 'vpn', 'status' => 'provisioning']);
    $this->socket->onTriggerRemoval = static function () use ($role): void {
        $role->update(['status' => 'active']);
    };
    $response = $conn ? connRemovalCall($instance->id, ['acknowledge_unsaved' => true])
        : $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->deleteJson('/api/v1/instances/'.$instance->id, ['force' => true]);
    $response->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect(PrivateDnsPublicationNodes::query()->whereKey($dns->id)->exists())->toBeTrue()
        ->and($operation->input['participant_node_ids'])->toContain($dns->id)
        ->and(array_column($operation->input['native_destroy']['route_mutations']['forward']['publications'], 'node_id'))->toContain($dns->id);
})->with(['CLI' => [false], 'Conn' => [true]]);

/** A clone between its two Operations whose completion runs, as PR 3's create leaves it: `source_resolved`, its Route not yet active. */
function connCompletingClone(string $name = 'feature', string $status = 'running'): array
{
    $default = test()->default;
    $instance = Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $default->node_id, 'name' => $name,
        'status' => InstanceState::SourceResolved, 'branch' => $name, 'starting_commit' => str_repeat('c', 40),
        'checkout_path' => '/fixture/reservation', 'source_layout' => 'checkout', 'first_setup_pending' => true]);
    $instance->update(['checkout_path' => InstanceContainer::workspaceOnNode($instance)]);
    app(InstanceContainer::class)->reserve($instance, $default->node, $default);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => $name.'.fixture.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    DatabaseConnection::query()->create(['slug' => $name.'-db', 'driver' => 'sqlite', 'node_id' => $instance->node_id,
        'owner_instance_id' => $instance->id, 'path' => $instance->checkout_path.'/database.sqlite']);
    ConnEnvironment::query()->forceCreate(['instance_id' => $instance->id, 'environment_id' => $name.'-env',
        'label' => $name, 'url' => 'http://10.44.0.7:3800', 'node_id' => $instance->node_id,
        'admin_session' => 'fixture-session', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $terminal = in_array($status, ['failed', 'cancelled'], true);
    $completion = Operation::query()->create(['kind' => 'instance.complete', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'lock_key' => $terminal ? null : OperationStore::lockKey('instance', $instance->id),
        'input' => ['instance_id' => $instance->id, 'direct_provisioning' => ['container' => ['processes' => []]]],
        'state' => $status === 'running' ? ['swamp_run_id' => (string) Str::uuid()] : [], 'status' => $status, 'sequence' => 1,
        'attempts' => $status === 'queued' ? 0 : 1, 'started_at' => now()->subSeconds(2), 'finished_at' => $terminal ? now() : null]);
    if ($terminal) {
        $instance->update(['failed_step' => 'check', 'error_code' => 'instance.container_process_inactive']);
    }

    return [$instance->refresh(), $completion];
}

/** The completion's run reports its end, as Swamp reports a cancelled run; the removal behind it then promotes. */
function connCompletionEnds(Operation $completion, string $status = 'cancelled'): void
{
    app(InstanceCompleteProjection::class)->apply($completion->refresh(), ['status' => $status, 'run_id' => $completion->state['swamp_run_id'],
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['instance_id' => $completion->target_id, 'ready' => false]]);
    app(RoutePublicationHolds::class)->promoteAndStart();
}

describe('a removal while the clone\'s completion runs', function (): void {
    it('answers 202 at once with one waiting removal, records the cancel, and starts the removal when the completion has ended', function (): void {
        [$instance, $completion] = connCompletingClone();

        $response = connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted()
            ->assertJsonPath('data.kind', 'instance.destroy')->assertJsonPath('data.status', 'waiting');
        $removal = Operation::query()->findOrFail($response->json('data.id'));

        expect($removal->input['after_operation_id'])->toBe($completion->id)
            ->and($completion->refresh()->cancel_requested_at)->not->toBeNull()
            ->and($instance->refresh()->status)->toBe(InstanceState::Removing)
            ->and(Operation::query()->where('kind', 'instance.destroy')->count())->toBe(1);
        // Swamp has not ended the completion yet: the removal keeps waiting, and its budget clock has not started.
        app(RoutePublicationHolds::class)->promoteAndStart();
        expect($removal->refresh()->status)->toBe(OperationStatus::Waiting);

        connCompletionEnds($completion);

        expect($completion->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and($completion->result['recovery'] ?? null)->toBeNull()
            ->and($instance->refresh()->status)->toBe(InstanceState::Removing)
            ->and($removal->refresh()->status)->toBe(OperationStatus::Queued)
            ->and($removal->started_at)->not->toBeNull();
        connRemovalSucceed($removal->refresh());
        expect($instance->fresh())->toBeNull()
            ->and(Route::query()->where('domain', 'feature.fixture.test')->exists())->toBeFalse()
            ->and(DatabaseConnection::query()->where('owner_instance_id', $instance->id)->exists())->toBeFalse()
            ->and(ConnEnvironment::query()->where('instance_id', $instance->id)->exists())->toBeFalse();
    });

    it('lets three removals wait at once, each for its own clone\'s completion', function (): void {
        $pairs = array_map(fn (string $name): array => connCompletingClone($name), ['one', 'two', 'three']);
        $removals = array_map(fn (array $pair): Operation => Operation::query()->findOrFail(
            connRemovalCall($pair[0]->id, ['acknowledge_unsaved' => true], 'remove-'.$pair[0]->id)->assertAccepted()
                ->assertJsonPath('data.status', 'waiting')->json('data.id')), $pairs);

        connCompletionEnds($pairs[1][1]);
        expect(array_map(fn (Operation $removal): string => $removal->refresh()->status->value, $removals))->toBe(['waiting', 'queued', 'waiting']);
        connCompletionEnds($pairs[0][1]);
        connCompletionEnds($pairs[2][1], 'failed');
        // The three clones' Routes share the Node's publication: the started removal holds it, the others follow it oldest first.
        expect(array_map(fn (Operation $removal): string => $removal->refresh()->status->value, $removals))->toBe(['waiting', 'queued', 'waiting']);
        foreach ([1, 0, 2] as $index) {
            expect($removals[$index]->refresh()->status)->toBe(OperationStatus::Queued);
            connRemovalSucceed($removals[$index]);
            app(RoutePublicationHolds::class)->promoteAndStart();
        }
        foreach ($pairs as [$instance]) {
            expect($instance->fresh())->toBeNull();
        }
        expect(Operation::query()->where('kind', 'instance.destroy')->pluck('status')->map->value->all())->toBe(['succeeded', 'succeeded', 'succeeded']);
    });

    it('ends a completion that had not started at once, and the removal follows', function (): void {
        [$instance, $completion] = connCompletingClone(status: 'queued');

        $removal = Operation::query()->findOrFail(connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted()->json('data.id'));
        app(RoutePublicationHolds::class)->promoteAndStart();

        expect($completion->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and($completion->lock_key)->toBeNull()
            ->and($completion->result['recovery'] ?? null)->toBeNull()
            ->and($instance->refresh()->status)->toBe(InstanceState::Removing)
            ->and($removal->refresh()->status)->toBe(OperationStatus::Queued);
    });

    it('removes a clone whose completion failed at once, as a retained failed create', function (): void {
        [$instance] = connCompletingClone(status: 'failed');

        connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.input.after_operation_id', null);
    });

    it('withdraws an address the create published, whether the completion runs or failed at its check', function (string $status): void {
        // The create published the address beside the copy: the Route is active with its sites published before the clone is active
        // (docs/reference/development-containers.md#the-address-during-the-create).
        [$instance] = connCompletingClone(status: $status);
        $route = Route::query()->where('domain', 'feature.fixture.test')->firstOrFail();
        $route->update(['status' => 'active', 'sites_published' => true]);
        // A clone keeps the preparation id its create reserved it with.
        $instance->update(['source_prepare_id' => (string) Str::uuid()]);

        $removal = Operation::query()->findOrFail(connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted()->json('data.id'));
        if ($status === 'running') {
            connCompletionEnds(Operation::query()->where('kind', 'instance.complete')->where('target_id', $instance->id)->firstOrFail());
        }

        expect($removal->refresh()->status)->toBe(OperationStatus::Queued)
            ->and(array_column($removal->input['members'], 'route_id'))->toBe([$route->id])
            ->and($removal->input['native_destroy']['route_mutations']['forward']['publications'])->not->toBe([]);
        connRemovalSucceed($removal->refresh());
        expect($instance->fresh())->toBeNull()
            ->and(Route::query()->whereKey($route->id)->exists())->toBeFalse();
    })->with(['completion running' => ['running'], 'completion failed at its check' => ['failed']]);

    it('keeps the CLI and core API refusal while the completion runs, naming the completion, and changes nothing', function (): void {
        [$instance, $completion] = connCompletingClone();
        $before = connRemovalStored();

        try {
            app(StartInstanceDestroyAction::class)->execute($instance, true);
            $refusal = null;
        } catch (ResourceOperationException $exception) {
            $refusal = $exception;
        }

        expect($refusal?->errorCode)->toBe('operation.target_busy')
            ->and($refusal?->details)->toBe(['operation_id' => $completion->id])
            ->and(connRemovalStored())->toBe($before)
            ->and($completion->refresh()->cancel_requested_at)->toBeNull();
    });

    it('makes a retry of a cancelled waiting removal wait again from Conn and refuse from the CLI, never trusting the saved completion', function (): void {
        [$instance, $completion] = connCompletingClone();
        $first = Operation::query()->findOrFail(connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted()->json('data.id'));
        app(CancelOperationAction::class)->execute($first);
        expect($first->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and($completion->refresh()->status)->toBe(OperationStatus::Running);

        try {
            app(StartInstanceDestroyAction::class)->execute($instance->refresh(), true);
            $code = null;
        } catch (ResourceOperationException $exception) {
            $code = $exception->errorCode;
        }
        expect($code)->toBe('operation.target_busy');

        $retry = connRemovalCall($instance->id, ['acknowledge_unsaved' => true], 'remove-again')->assertAccepted()
            ->assertJsonPath('data.status', 'waiting')->assertJsonPath('data.input.after_operation_id', $completion->id);
        connCompletionEnds($completion);
        expect(Operation::query()->findOrFail($retry->json('data.id'))->status)->toBe(OperationStatus::Queued);
    });

    it('keeps a removing clone removing when its completion succeeds after the cancel was recorded', function (): void {
        [$instance, $completion] = connCompletingClone();
        $removal = Operation::query()->findOrFail(connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted()->json('data.id'));
        $route = Route::query()->where('domain', 'feature.fixture.test')->sole();

        connCompletionEnds($completion, 'succeeded');

        expect($completion->refresh()->status)->toBe(OperationStatus::Succeeded)
            ->and($instance->refresh()->status)->toBe(InstanceState::Removing)
            ->and($route->refresh()->status)->toBe(RouteStatus::Retiring)
            ->and($removal->refresh()->status)->toBe(OperationStatus::Queued);
    });

    it('lets a removal with no completion pass a removal on the same Node that still waits for its completion', function (): void {
        [$waiting] = connCompletingClone('slow');
        [$failed] = connCompletingClone('broken', 'failed');
        connRemovalCall($waiting->id, ['acknowledge_unsaved' => true], 'remove-slow')->assertAccepted()->assertJsonPath('data.status', 'waiting');

        connRemovalCall($failed->id, ['acknowledge_unsaved' => true], 'remove-broken')->assertAccepted()->assertJsonPath('data.status', 'queued');
    });

    it('asks Swamp once, after the answer, to stop a completion that no copy holds', function (): void {
        [$instance, $completion] = connCompletingClone();
        $run = $completion->state['swamp_run_id'];
        app(NativeRunProgress::class)->apply($completion->id, $run, ['kind' => 'step_started', 'stepId' => 'clone-services', 'jobId' => 'complete', 'seq' => 1]);
        $answered = false;
        $sentAnswered = [];
        Event::listen(RequestHandled::class, function () use (&$answered): void {
            $answered = true;
        });
        $this->socket->onSend = function (array $message) use (&$answered, &$sentAnswered): void {
            if ($message['type'] === 'workflow.cancel') {
                $sentAnswered[] = $answered;
            }
        };

        connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted()->assertJsonPath('data.status', 'waiting');

        expect($sentAnswered)->toBe([true])
            ->and($completion->refresh()->state['native_cancel_requested_at'] ?? null)->not->toBeNull();
    });

    it('holds the completion\'s cancel while its database fill runs, then cancels it and runs the removal', function (): void {
        [$instance, $completion] = connCompletingClone();
        $run = $completion->state['swamp_run_id'];
        app(NativeRunProgress::class)->apply($completion->id, $run, ['kind' => 'step_started', 'stepId' => 'server-database-fill', 'jobId' => 'complete', 'seq' => 1]);

        $removal = Operation::query()->findOrFail(connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted()
            ->assertJsonPath('data.status', 'waiting')->json('data.id'));

        $completion->refresh();
        expect($completion->status)->toBe(OperationStatus::Running)
            ->and($completion->cancel_requested_at)->not->toBeNull()
            ->and(collect($this->socket->messages)->pluck('type')->all())->not->toContain('workflow.cancel')
            ->and(app(NativeRunCancellation::class)->awaitsForwarding($completion))->toBeFalse();

        app(NativeRunProgress::class)->apply($completion->id, $run, ['kind' => 'step_completed', 'stepId' => 'server-database-fill', 'jobId' => 'complete', 'seq' => 2]);
        // The fill has ended: the cancel goes to Swamp now, through the reconciler that the step's end dispatched.
        expect(app(NativeRunCancellation::class)->awaitsForwarding($completion->refresh()))->toBeTrue();
        Queue::assertPushed(ReconcileSwampOperations::class);

        connCompletionEnds($completion);
        expect($removal->refresh()->status)->toBe(OperationStatus::Queued);
    });

    it('reads the workspace of a clone whose completion runs, as for any running container', function (): void {
        [$instance] = connCompletingClone();
        $this->workspace->answer = json_encode(connWorkAnswer(baseline: 'default_now'), JSON_THROW_ON_ERROR);

        connWorkRead($instance->id)->assertOk()->assertJsonPath('data.state', 'clean')->assertJsonPath('data.baseline', 'default_now');
    });
});

/**
 * A clone whose create still runs, as its admission leaves it: `reserved` with its preparation id, its Route pending, the create holding
 * the Instance lock. `free` took the Route Node (its publication runs), `published` is after `clone-route` succeeded (the Route active, the
 * Node freed), `deferred` found the Node held and carries no publication (docs/reference/development-containers.md#the-address-during-the-create).
 *
 * @return array{Instance, Operation, Route}
 */
function connCreatingClone(string $name, string $mode): array
{
    $default = test()->default;
    $instance = Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $default->node_id, 'name' => $name,
        'status' => InstanceState::Reserved, 'checkout_path' => '/fixture/reservation', 'source_layout' => 'checkout',
        'source_prepare_id' => (string) Str::uuid(), 'first_setup_pending' => true]);
    $instance->update(['checkout_path' => InstanceContainer::workspaceOnNode($instance)]);
    app(InstanceContainer::class)->reserve($instance, $default->node, $default);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => $name.'.fixture.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    DatabaseConnection::query()->create(['slug' => $name.'-db', 'driver' => 'sqlite', 'node_id' => $instance->node_id,
        'owner_instance_id' => $instance->id, 'path' => $instance->checkout_path.'/database.sqlite']);
    $create = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'lock_key' => OperationStore::lockKey('instance', $instance->id),
        'input' => ['instance_id' => $instance->id, 'route_id' => $route->id, 'direct_provisioning' => ['container' => ['clone' => ['source' => 'instance-'.$default->id], 'processes' => []]],
            ...($mode === 'deferred' ? [] : ['route_create' => ['route_id' => $route->id]])],
        'state' => ['swamp_run_id' => (string) Str::uuid()], 'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'started_at' => now()->subSeconds(3)]);
    if ($mode === 'free') {
        DB::table('route_publication_holds')->insert(['node_id' => $instance->node_id, 'operation_id' => $create->id, 'created_at' => now(), 'lease_until' => null]);
    }
    if ($mode === 'published') {
        $route->update(['status' => 'active', 'sites_published' => true]);
    }

    return [$instance->refresh(), $create, $route];
}

/** The create's run reports its cancelled end, as Swamp reports it; the removal behind it then promotes. */
function connCreateEnds(Operation $create): void
{
    app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), ['status' => 'cancelled', 'run_id' => $create->state['swamp_run_id'],
        'error_code' => 'operation.cancelled', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_id' => $create->target_id, 'ready' => false, 'failed_step' => 'clone', 'route_publication' => 'failed']]);
    app(RoutePublicationHolds::class)->promoteAndStart();
}

describe('a removal while the clone\'s create runs', function (): void {
    it('answers 202 at once with one removal waiting behind the create, cancels the create, and leaves nothing when both have ended', function (string $mode): void {
        [$instance, $create, $route] = connCreatingClone('feature', $mode);

        $response = connRemovalCall($instance->id, ['acknowledge_unsaved' => true])->assertAccepted()
            ->assertJsonPath('data.kind', 'instance.destroy')->assertJsonPath('data.status', 'waiting');
        $removal = Operation::query()->findOrFail($response->json('data.id'));

        expect($removal->input['after_operation_id'])->toBe($create->id)
            ->and($create->refresh()->cancel_requested_at)->not->toBeNull()
            ->and($instance->refresh()->status)->toBe(InstanceState::Removing)
            ->and([$route->refresh()->status->value, $route->sites_published])->toBe(['retiring', false])
            // The removal's withdrawal captures the clone's site whether or not the create has published it.
            ->and(array_column($removal->input['members'], 'route_id'))->toBe([$route->id]);
        // A clone-route that succeeds now leaves the retiring Route alone.
        if ($mode === 'free') {
            app(NativeRunProgress::class)->apply($create->id, $create->state['swamp_run_id'], ['kind' => 'step_completed', 'jobId' => 'publication',
                'stepId' => 'clone-route', 'seq' => 1, 'completedAt' => now()->toIso8601String()]);
            expect([$route->refresh()->status->value, $route->sites_published])->toBe(['retiring', false]);
        }
        app(RoutePublicationHolds::class)->promoteAndStart();
        expect($removal->refresh()->status)->toBe(OperationStatus::Waiting);

        connCreateEnds($create);

        // The removal owns the clone: the create's end records nothing of it and admits no completion.
        expect($create->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and($create->result['completion_operation_id'] ?? null)->toBeNull()
            ->and(Operation::query()->where('kind', 'instance.complete')->exists())->toBeFalse()
            ->and($instance->refresh()->status)->toBe(InstanceState::Removing)
            ->and(DB::table('route_publication_holds')->where('operation_id', $create->id)->exists())->toBeFalse()
            ->and($removal->refresh()->status)->toBe(OperationStatus::Queued);
        connRemovalSucceed($removal->refresh());
        expect($instance->fresh())->toBeNull()
            ->and(Route::query()->whereKey($route->id)->exists())->toBeFalse()
            ->and(InstanceRuntime::query()->where('instance_id', $instance->id)->exists())->toBeFalse()
            ->and(DatabaseConnection::query()->where('owner_instance_id', $instance->id)->exists())->toBeFalse();
    })->with(['hold free, before clone-route' => ['free'], 'after clone-route published' => ['published'], 'address deferred' => ['deferred']]);

    it('lets two removals wait at once, each behind its own clone\'s create', function (): void {
        [$one, $first] = connCreatingClone('one', 'free');
        [$two, $second] = connCreatingClone('two', 'deferred');
        $removals = array_map(fn (Instance $instance): Operation => Operation::query()->findOrFail(
            connRemovalCall($instance->id, ['acknowledge_unsaved' => true], 'remove-'.$instance->id)->assertAccepted()
                ->assertJsonPath('data.status', 'waiting')->json('data.id')), [$one, $two]);

        connCreateEnds($second);
        connCreateEnds($first);
        foreach ($removals as $removal) {
            if ($removal->refresh()->status === OperationStatus::Waiting) {
                app(RoutePublicationHolds::class)->promoteAndStart();
            }
            expect($removal->refresh()->status)->toBe(OperationStatus::Queued);
            connRemovalSucceed($removal);
            app(RoutePublicationHolds::class)->promoteAndStart();
        }
        expect([$one->fresh(), $two->fresh()])->toBe([null, null]);
    });

    it('admits the CLI and core API removal during a create the same way: accepted, waiting behind the cancelled create', function (): void {
        [$instance, $create] = connCreatingClone('feature', 'free');

        $removal = app(StartInstanceDestroyAction::class)->execute($instance, true);

        expect([$removal->kind, $removal->status, $removal->input['after_operation_id']])->toBe(['instance.destroy', OperationStatus::Waiting, $create->id])
            ->and($create->refresh()->cancel_requested_at)->not->toBeNull();
    });
});
