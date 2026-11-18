<?php

declare(strict_types=1);

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Operations\StartOperationAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\InstanceCompleteProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Power\AgentActivity;
use App\Domain\Instances\Power\ContainerIdleSweep;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Instances\Power\InstanceAgentActivity;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\InstanceStart;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Instances\Power\NodeFileTimes;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationSecrets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationVersionCleanup;
use App\Domain\Operations\OperationVersions;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Jobs\Operations\DispatchExecutorWake;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProcessDefinition;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Actions\BindConnNodeProfileAction;
use Orbit\Extensions\Conn\Actions\ControlConnLaunchAction;
use Orbit\Extensions\Conn\Actions\PublishConnLaunchAction;
use Orbit\Extensions\Conn\Domain\ConnLaunchChild;
use Orbit\Extensions\Conn\Domain\ConnLaunchDispatchSource;
use Orbit\Extensions\Conn\Domain\ConnLaunchInstances;
use Orbit\Extensions\Conn\Domain\ConnLaunchNativeWork;
use Orbit\Extensions\Conn\Domain\ConnLaunchSettlement;
use Orbit\Extensions\Conn\Domain\ConnLaunchStage;
use Orbit\Extensions\Conn\Domain\ConnLaunchState;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnLaunchIntent;
use Orbit\Extensions\Conn\Models\ConnProfile;
use Orbit\Extensions\Conn\Models\ConnThread;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../../Domain/Instances/ContainerInstanceHelpers.php';

function launchNode(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'user' => 'orbit',
    ]));
}

/** @param array<string, mixed> $body */
function callLaunchApi(?Node $caller, string $method, string $path, array $body = [], ?string $key = null, int $jsonFlags = 0): TestResponse
{
    $client = test()->withServerVariables(['REMOTE_ADDR' => $caller?->wireguard_ip ?? '192.0.2.1']);
    if ($method === 'POST' && $body === [] && str_ends_with($path, '/cancel')) {
        return $client->post('/api/v1/'.$path, [], ['Accept' => 'application/json']);
    }

    return $client->json($method, '/api/v1/'.$path, $body, $key === null ? [] : ['Idempotency-Key' => $key], $jsonFlags);
}

/** @return array<string, mixed> */
function launchBody(Node $caller, ConnProfile $profile): array
{
    return [
        'expected_profile_id' => $profile->id,
        'expected_node_id' => $caller->id,
        'project_id' => test()->project->id,
        'target' => ['kind' => 'existing', 'instance_id' => test()->instance->id],
        'prompt' => "  Fixture first message — π\n\n",
        'source' => 'web',
        'command_id' => (string) Str::uuid(),
        'thread_id' => (string) Str::uuid(),
        'message_id' => (string) Str::uuid(),
    ];
}

/** @return array{operations: int, intents: int, instances: int} */
function launchRecords(): array
{
    return ['operations' => Operation::query()->count(), 'intents' => ConnLaunchIntent::query()->count(), 'instances' => Instance::query()->count()];
}

/** @param array<string, mixed> $input */
function launchNativeReceipt(Operation $launch, string $kind, ?string $key = null, array $input = [], ?string $actor = null): Operation
{
    return Operation::query()->create(['kind' => $kind, 'target_type' => 'instance', 'target_id' => ConnLaunchChild::instanceId($launch),
        'node_id' => $launch->node_id, 'triggered_by' => $actor ?? $launch->triggered_by, 'input' => $input, 'state' => [],
        'status' => OperationStatus::Queued, 'sequence' => 1, 'attempts' => 0, 'idempotency_key' => $key]);
}

function launchFixtureDispatch(int $id, ?stdClass $model = null): Operation
{
    $state = app(ConnLaunchState::class);
    $state->workspaceReady($id);

    return $state->admitDispatch($id, 'fixture-environment', 'pinned-incarnation', 'fixture-t3-project',
        $model ?? (object) ['providerInstanceId' => 'actual-provider', 'model' => 'actual-model', 'options' => (object) []],
        function (Operation $launch, bool $recovery): Operation {
            expect($recovery)->toBeFalse()->and($launch->state['dispatch_committed_at'])->not->toBeNull();

            return launchNativeReceipt($launch, ConnLaunchDispatchSource::Kind, 'fixture-dispatch', ['launch_operation_id' => $launch->id]);
        });
}

function launchFixtureDefault(): Instance
{
    launchNode('fixture-swamp', '10.44.0.209')->roles()->create(['role' => 'swamp', 'status' => 'active']);
    app()->instance(ContainerModelRoute::class, new class implements ContainerModelRoute
    {
        public function assertReady(): void {}

        public function origin(): string
        {
            return 'http://10.44.0.3:8318';
        }

        public function key(): string
        {
            return 'fixture-model-route';
        }
    });
    $default = Instance::query()->findOrFail(containerCreate(test()->project, test()->host)->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
        ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);

    return $default;
}

/** @return array{Operation,Instance,Operation,Operation} */
function launchFixtureFailedApplication(): array
{
    $default = launchFixtureDefault();
    Operation::query()->where('kind', 'instance.create')->where('target_id', $default->id)->firstOrFail()
        ->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
    $body = test()->body;
    $name = 'fixture-application-retry-clone';
    $body['target'] = ['kind' => 'new', 'name' => $name];
    $id = callLaunchApi(test()->phone, 'POST', 'conn/launches', $body, test()->key)->assertAccepted()->json('data.id');
    $create = launchFixtureCreate($id, $name);
    $create->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now(),
        'result' => ['t3' => ['environment_id' => test()->environment->environment_id]]]);
    $instance = Instance::query()->findOrFail($create->target_id);
    $instance->update(['status' => InstanceState::SourceResolved, 'starting_commit' => str_repeat('c', 40)]);
    $instance->runtime()->update(['power_state' => InstanceRuntimePowerState::Running]);
    test()->environment->forceFill(['instance_id' => $instance->id])->save();

    $launch = Operation::query()->findOrFail($id);
    $failed = app(ConnLaunchInstances::class)->complete($launch, $instance);
    $failed->update(['status' => OperationStatus::Failed, 'lock_key' => null, 'finished_at' => now(),
        'error_code' => 'instance.container_process_inactive', 'result' => ['ready' => false]]);
    $dispatch = launchFixtureDispatch($id);
    $state = app(ConnLaunchState::class);
    $state->accepted($id, $dispatch->id, now());
    $state->published($id);
    $dispatch->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);

    return [$launch, $instance, $failed, $dispatch];
}

/** Switch to a later native declaration while preserving this launch's original children. */
function upgradeLaunchChildVersions(string ...$kinds): void
{
    $policies = [];
    foreach ($kinds as $kind) {
        $retained = app(DirectOperation::class)->definition($kind);
        $retained['workflow'] .= '-retained-v1';
        $policies[$kind] = ['current' => 2, 'retained' => [1 => $retained], 'deprecated' => [1]];
    }
    config(['operation_versions' => $policies]);
    app()->forgetInstance(DirectOperation::class);
    app(OperationVersionCleanup::class)->fenceOnly();
}

function launchFixtureCreate(int $id, string $name): Operation
{
    return app(ConnLaunchState::class)->admitChild($id, ConnLaunchChild::Create, 'fixture-create', static fn (Operation $launch): array => [
        'operation' => app(OperationIdempotency::class)->under('instance.create', 'fixture-create', hash('sha256', $name),
            fn (): Operation => app(StartDevelopmentInstanceCreateAction::class)->execute(
                new CreateInstanceData(test()->project->id, test()->host->id, $name, null, null, null), $launch->triggered_by, null,
                agentFiles: [])),
        'owned' => true,
    ]);
}

/** @return array{Operation, Instance} */
function launchCleanupFixture(OperationStatus $status): array
{
    $default = launchFixtureDefault();
    // The default's create no longer holds its target. The launch owns only its clone.
    Operation::query()->where('kind', 'instance.create')->where('target_id', $default->id)->firstOrFail()
        ->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
    $body = test()->body;
    $name = 'fixture-cleanup-clone';
    $body['target'] = ['kind' => 'new', 'name' => $name];
    $id = callLaunchApi(test()->phone, 'POST', 'conn/launches', $body, test()->key)->assertAccepted()->json('data.id');
    $create = launchFixtureCreate($id, $name);
    $unstarted = $status === OperationStatus::Cancelled;
    $create->update(['status' => $status, 'attempts' => $unstarted ? 0 : 1, 'lock_key' => null,
        'started_at' => $unstarted ? null : now(), 'finished_at' => now(),
        'error_code' => $status === OperationStatus::Failed ? DevelopmentInstanceCreateProjection::BaselineUnavailable : null]);
    $instance = Instance::query()->findOrFail($create->target_id);
    if (! $unstarted) {
        $instance->update(['status' => InstanceState::SourceResolved, 'starting_commit' => str_repeat('c', 40)]);
        $instance->runtime()->update(['power_state' => InstanceRuntimePowerState::Running]);
    }
    if ($status === OperationStatus::Succeeded) {
        test()->environment->forceFill(['instance_id' => $instance->id])->save();
    }
    callLaunchApi(test()->desktop, 'POST', "conn/launches/{$id}/cancel")->assertAccepted();

    return [Operation::query()->findOrFail($id), $instance];
}

function launchWorkspaceAnswer(int $modified = 0): string
{
    return json_encode(['state' => $modified === 0 ? 'clean' : 'dirty', 'baseline' => 'recorded',
        'head_commit' => str_repeat('c', 40), 'modified' => ['count' => $modified, 'names' => $modified === 0 ? [] : ['README.md']],
        'untracked' => ['count' => 0, 'names' => []], 'stashes' => ['count' => 0, 'names' => []],
        'commits' => ['count' => 0, 'branch_count' => 0, 'branches' => [], 'head' => 0]], JSON_THROW_ON_ERROR);
}

function copyLaunchFixtureToFile(string $path): void
{
    $copy = new PDO('sqlite:'.$path);
    $schema = DB::select("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END");
    foreach ($schema as $entry) {
        $copy->exec($entry->sql);
        if ($entry->type === 'table') {
            foreach (DB::table($entry->name)->get() as $row) {
                $columns = array_keys((array) $row);
                $columnList = implode(',', array_map(static fn (string $column): string => '"'.$column.'"', $columns));
                $placeholders = implode(',', array_fill(0, count($columns), '?'));
                $copy->prepare('INSERT INTO "'.$entry->name.'" ('.$columnList.') VALUES ('.$placeholders.')')->execute(array_values((array) $row));
            }
        }
    }
}

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    app(ExtensionStore::class)->set('conn', true);
    $this->host = containerDefaultNode('fixture-host');
    $this->phone = launchNode('fixture-phone', '10.44.0.77');
    $this->desktop = launchNode('fixture-desktop', '10.44.0.78');
    $this->otherHost = launchNode('fixture-other-host', '10.44.0.79');
    $this->phone->accessibleNodes()->attach([$this->host->id, $this->otherHost->id]);
    $this->desktop->accessibleNodes()->attach([$this->host->id, $this->otherHost->id]);
    $this->profile = ConnProfile::query()->create(['name' => 'fixture profile', 'settings' => ['workspaces' => [['id' => 'fixture-workspace']]]]);
    $this->otherProfile = ConnProfile::query()->create(['name' => 'other fixture profile', 'settings' => []]);
    app(BindConnNodeProfileAction::class)->execute($this->phone, $this->profile);
    app(BindConnNodeProfileAction::class)->execute($this->desktop, $this->profile);
    $this->project = containerDefaultProject();
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->host->id,
        'name' => 'existing-clone', 'checkout_path' => '/srv/workspace/existing-clone']);
    $this->environment = ConnEnvironment::query()->create([
        'environment_id' => 'fixture-environment', 'label' => 'Fixture', 'url' => 'http://10.44.0.30:3773', 'node_id' => $this->host->id,
        'admin_session' => 'fixture-session', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now(),
    ]);
    $this->environment->forceFill(['instance_id' => $this->instance->id])->save();
    $this->key = (string) Str::uuid();
    $this->body = launchBody($this->phone, $this->profile);
});

describe('launch admission', function (): void {
    it('records one encrypted intent and returns the same safe snapshot on replay', function (): void {
        $baseline = launchRecords();
        $activityCount = Activity::query()->count();
        $body = [...$this->body, 'model_selection' => ['providerInstanceId' => 'fixture-provider', 'model' => 'fixture-model', 'options' => (object) []]];

        $response = callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key)->assertAccepted()
            ->assertJsonPath('data.kind', 'conn.launch')->assertJsonPath('data.profile_id', $this->profile->id)
            ->assertJsonPath('data.target_node_id', $this->host->id)->assertJsonPath('data.initiator_node_id', $this->phone->id)
            ->assertJsonPath('data.instance_id', $this->instance->id)->assertJsonPath('data.launch_id', $this->key)
            ->assertJsonPath('data.phase', 'preparing')->assertJsonPath('data.workspace_id', null)->assertJsonPath('data.thread', null)
            ->assertJsonStructure(['data' => ['stages' => ['workspace_server', 'first_message', 'publication', 'application_preview', 'cleanup'],
                'readiness' => ['workspace_server', 'first_message', 'application_preview'], 'actions' => ['cancel', 'retry_launch', 'retry_application'],
                'children' => ['create_operation_id', 'wake_operation_id', 'completion_operation_id', 'destroy_operation_id']], 'meta' => ['request_id']]);
        $data = json_decode($response->getContent())->data;
        expect($data->stages)->toBeInstanceOf(stdClass::class)->and($data->actions)->toBeInstanceOf(stdClass::class)
            ->and($data->children)->toBeInstanceOf(stdClass::class)->and($data->children->create_operation_id)->toBeNull();

        $operation = Operation::query()->findOrFail($data->id);
        $intent = ConnLaunchIntent::query()->findOrFail($operation->id);
        $private = json_decode($intent->request);
        expect($operation->triggered_by)->toBe('node:'.$this->phone->id)
            ->and(json_encode($operation))->not->toContain('Fixture first message')
            ->and($intent->getRawOriginal('request'))->not->toContain('Fixture first message')
            ->and($intent->toArray())->not->toHaveKey('request')
            ->and($private->prompt)->toBe($body['prompt'])
            ->and($private->model_selection->options)->toBeInstanceOf(stdClass::class)
            ->and(Activity::query()->count())->toBe($activityCount);

        $reordered = array_reverse([...$body, 'workspace_id' => null], preserve_keys: true);
        callLaunchApi($this->phone, 'POST', 'conn/launches', $reordered, $this->key)->assertAccepted()->assertJsonPath('data', $response->json('data'));
        expect(launchRecords())->toBe(['operations' => $baseline['operations'] + 1, 'intents' => 1, 'instances' => $baseline['instances']]);
    });

    it('normalizes omitted and null clone branches and protects nondefault branch selectors', function (): void {
        launchFixtureDefault();
        $baseline = launchRecords();
        $body = [...$this->body, 'target' => ['kind' => 'new', 'name' => 'fixture-clone']];
        $first = callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key)->assertAccepted()->assertJsonPath('data.instance_id', null);

        $body['target']['branch'] = null;
        callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key)->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
        $body['target']['branch'] = ['mode' => 'new', 'from' => 'main'];
        callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key)->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
        expect(launchRecords())->toBe(['operations' => $baseline['operations'] + 1, 'intents' => 1, 'instances' => $baseline['instances']]);
    });

    it('conflicts on changes to accepted selectors or exact message bytes', function (string $field, mixed $change): void {
        callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted();
        $baseline = launchRecords();

        callLaunchApi($this->phone, 'POST', 'conn/launches', [...$this->body, $field => $change], $this->key)->assertConflict()
            ->assertJsonPath('error.code', 'operation.idempotency_conflict')->assertJsonMissingPath('data');
        expect(launchRecords())->toBe($baseline);
    })->with([
        'exact text' => ['prompt', 'Fixture first message — π'],
        'workspace' => ['workspace_id', 'fixture-workspace'],
        'source' => ['source', 'mobile'],
        'mode' => ['runtime_mode', 'full-access'],
        'message ID' => ['message_id', '22da92df-68f5-4a59-b138-bc96ff18755f'],
    ]);

    it('refuses a rebind between the identity read and the first POST', function (): void {
        callLaunchApi($this->phone, 'GET', 'conn/me')->assertOk()->assertJsonPath('data.id', $this->body['expected_node_id'])
            ->assertJsonPath('data.profile.id', $this->body['expected_profile_id']);
        $baseline = launchRecords();
        app(BindConnNodeProfileAction::class)->execute($this->phone, $this->otherProfile);

        callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertConflict()
            ->assertJsonPath('error.code', 'conn.launch_scope_changed')->assertJsonMissingPath('data');
        expect(launchRecords())->toBe($baseline);
    });

    it('never returns the old snapshot after rebinding before replay', function (): void {
        callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted();
        $baseline = launchRecords();
        app(BindConnNodeProfileAction::class)->execute($this->phone, $this->otherProfile);

        foreach ([$this->body, [...$this->body, 'expected_profile_id' => $this->otherProfile->id]] as $body) {
            callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key)->assertConflict()
                ->assertJsonPath('error.code', 'conn.launch_scope_changed')->assertJsonMissingPath('data');
        }
        expect(launchRecords())->toBe($baseline);
    });

    it('excludes a competing rebind from the binding read through the admission commit', function (bool $replay): void {
        if ($replay) {
            $original = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data');
        }
        $baseline = launchRecords();
        $database = tempnam(sys_get_temp_dir(), 'conn-launch-scope-');
        expect($database)->toBeString();
        $default = DB::getDefaultConnection();
        $race = 'conn_launch_race';
        try {
            copyLaunchFixtureToFile($database);
            config(['database.connections.'.$race => [...config('database.connections.sqlite'), 'database' => $database]]);
            DB::setDefaultConnection($race);
            $primary = DB::connection($race);
            $primary->getPdo();
            $competitor = new PDO('sqlite:'.$database);
            $competitor->exec('PRAGMA busy_timeout=0');
            $bindings = [$this->otherProfile->id, $this->phone->id];
            $contend = static function () use (&$competitor, $bindings): ?int {
                try {
                    $competitor->prepare('UPDATE conn_profile_bindings SET conn_profile_id = ? WHERE node_id = ?')->execute($bindings);

                    return null;
                } catch (PDOException $error) {
                    return $error->errorInfo[1] ?? null;
                }
            };
            $attempts = [];
            $observe = true;
            DB::listen(static function (QueryExecuted $query) use ($primary, $contend, &$attempts, &$observe): void {
                if ($observe && $query->connection === $primary && ! array_key_exists('binding', $attempts)
                    && str_starts_with($query->sql, 'select * from "conn_profile_bindings" where "node_id" = ')) {
                    $attempts['binding'] = ['level' => $primary->transactionLevel(), 'sqlite_code' => $contend()];
                }
            });
            Event::listen(TransactionCommitting::class, static function (TransactionCommitting $event) use ($primary, $contend, &$attempts, &$observe): void {
                if ($observe && $event->connection === $primary && array_key_exists('binding', $attempts)) {
                    $attempts['commit'] = ['level' => $primary->transactionLevel(), 'sqlite_code' => $contend()];
                }
            });

            $response = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key);
            $observe = false;
            expect($attempts)->toBe(['binding' => ['level' => 1, 'sqlite_code' => 5], 'commit' => ['level' => 1, 'sqlite_code' => 5]]);
            $response->assertAccepted()->assertJsonPath('data.profile_id', $this->profile->id)->assertJsonPath('data.initiator_node_id', $this->phone->id);
            if ($replay) {
                expect($response->json('data'))->toBe($original);
            }
            expect(DB::table('conn_profile_bindings')->where('node_id', $this->phone->id)->value('conn_profile_id'))->toBe($this->profile->id);
            expect(launchRecords())->toBe(['operations' => $baseline['operations'] + ($replay ? 0 : 1),
                'intents' => $baseline['intents'] + ($replay ? 0 : 1), 'instances' => $baseline['instances']]);

            expect($contend())->toBeNull();
            expect(DB::table('conn_profile_bindings')->where('node_id', $this->phone->id)->value('conn_profile_id'))->toBe($this->otherProfile->id);
            $beforeRefusal = launchRecords();
            callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertConflict()
                ->assertJsonPath('error.code', 'conn.launch_scope_changed')->assertJsonMissingPath('data');
            expect(launchRecords())->toBe($beforeRefusal);
        } finally {
            $observe = false;
            $competitor = null;
            DB::setDefaultConnection($default);
            DB::purge($race);
            foreach ([$database, $database.'-wal', $database.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    })->with(['first admission' => [false], 'same-key replay' => [true]]);

    it('refuses a valid but wrong expected Node and an absent binding before doing anything', function (): void {
        $baseline = launchRecords();
        callLaunchApi($this->phone, 'POST', 'conn/launches', [...$this->body, 'expected_node_id' => $this->desktop->id], $this->key)->assertConflict()
            ->assertJsonPath('error.code', 'conn.launch_scope_changed')->assertJsonMissingPath('data');
        DB::table('conn_profile_bindings')->where('node_id', $this->phone->id)->delete();
        callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertConflict()
            ->assertJsonPath('error.code', 'conn.launch_scope_changed')->assertJsonMissingPath('data');
        expect(launchRecords())->toBe($baseline);
    });

    it('validates both expected IDs before a replay can return an Operation', function (string $field, mixed $value): void {
        callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted();
        $baseline = launchRecords();
        $body = $this->body;
        if ($value === null) {
            unset($body[$field]);
        } else {
            $body[$field] = $value;
        }

        foreach ([$this->key, (string) Str::uuid()] as $key) {
            callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $key)->assertUnprocessable()
                ->assertJsonStructure(['error' => ['details' => [$field]]])->assertJsonMissingPath('data');
        }
        expect(launchRecords())->toBe($baseline);
    })->with([
        'missing profile' => ['expected_profile_id', null],
        'missing Node' => ['expected_node_id', null],
        'zero profile' => ['expected_profile_id', 0],
        'negative Node' => ['expected_node_id', -1],
        'string profile' => ['expected_profile_id', '1'],
        'fractional Node' => ['expected_node_id', 1.5],
    ]);

    it('requires collection access after checking scope and before looking up the target', function (): void {
        $baseline = launchRecords();
        $this->phone->accessibleNodes()->detach();
        $unknown = [...$this->body, 'project_id' => $this->project->id + 1000, 'workspace_id' => 'missing'];

        callLaunchApi($this->phone, 'POST', 'conn/launches', [...$unknown, 'expected_profile_id' => $this->otherProfile->id], $this->key)
            ->assertConflict()->assertJsonPath('error.code', 'conn.launch_scope_changed');
        callLaunchApi($this->phone, 'POST', 'conn/launches', $unknown, $this->key)
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        expect(launchRecords())->toBe($baseline);
    });

    it('refuses blank modes instead of freezing them', function (string $field, string $mode): void {
        $baseline = launchRecords();
        callLaunchApi($this->phone, 'POST', 'conn/launches', [...$this->body, $field => $mode], $this->key)
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => [$field]]]);
        expect(launchRecords())->toBe($baseline);
    })->with([
        ['runtime_mode', ''], ['runtime_mode', '  '], ['interaction_mode', ''], ['interaction_mode', '  '],
    ]);

    it('refuses an incomplete model selection before it can commit the first send', function (): void {
        $baseline = launchRecords();
        foreach ([['instanceId' => 'codex'], ['instanceId' => 'codex', 'model' => '  '], ['instanceId' => 'codex', 'model' => false]] as $selection) {
            callLaunchApi($this->phone, 'POST', 'conn/launches', [...$this->body, 'model_selection' => $selection], $this->key)
                ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['model_selection.model']]]);
        }
        foreach (['invalid', [['id' => 'thinking', 'value' => 5]]] as $options) {
            callLaunchApi($this->phone, 'POST', 'conn/launches', [...$this->body, 'model_selection' => [
                'instanceId' => 'codex', 'model' => 'valid-model', 'options' => $options,
            ]], $this->key)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['model_selection.options']]]);
        }
        expect(launchRecords())->toBe($baseline);
    });

    it('refuses a model selection above the encoded dispatch limit before creating any records', function (): void {
        launchFixtureDefault();
        $baseline = launchRecords();
        $selection = ['instanceId' => 'codex', 'model' => 'valid-model', 'options' => [['id' => 'fixture', 'value' => '']]];
        $remaining = 16_385 - strlen(json_encode($selection, JSON_THROW_ON_ERROR));
        $selection['options'][0]['value'] = str_repeat('/', intdiv($remaining, 2)).str_repeat('a', $remaining % 2);
        expect(strlen(json_encode($selection, JSON_THROW_ON_ERROR)))->toBe(16_385)
            ->and(strlen(json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))->toBeLessThan(16_384);
        $body = [...$this->body, 'target' => ['kind' => 'new', 'name' => 'fixture-model-bound'], 'model_selection' => $selection];

        callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key)
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['model_selection']]])
            ->assertJsonMissingPath('data');
        expect(launchRecords())->toBe($baseline);
    });

    it('refuses a legacy model selection when preserving an integral float puts its stored JSON over the limit', function (bool $newTarget, int $storedBytes): void {
        $body = $this->body;
        if ($newTarget) {
            launchFixtureDefault();
            $body['target'] = ['kind' => 'new', 'name' => 'fixture-float-bound'];
        }
        $baseline = launchRecords();
        $selection = ['instanceId' => 'codex', 'model' => 'valid-model', 'options' => (object) ['fixture' => '', 'integral' => 1.0, 'fractional' => 0.125]];
        $flags = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;
        $selection['options']->fixture = str_repeat('a', $storedBytes - strlen(json_encode($selection, $flags)));
        expect(strlen(json_encode($selection, $flags)))->toBe($storedBytes)
            ->and(strlen(json_encode($selection, JSON_THROW_ON_ERROR)))->toBe($storedBytes - 2);

        callLaunchApi($this->phone, 'POST', 'conn/launches', [...$body, 'model_selection' => $selection], $this->key, $flags)
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['model_selection']]])->assertJsonMissingPath('data');
        expect(launchRecords())->toBe($baseline);
    })->with(['existing target, one byte over' => [false, 16_385], 'new target, two bytes over' => [true, 16_386]]);

    it('admits the encoded model limit with a large first message and replays the same request', function (): void {
        $selection = ['instanceId' => 'codex', 'model' => 'valid-model', 'options' => (object) ['value' => '', 'integral' => 1.0, 'fractional' => 0.125]];
        $flags = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;
        $remaining = 16_384 - strlen(json_encode($selection, $flags));
        $selection['options']->value = str_repeat('π', intdiv($remaining, 6)).str_repeat('a', $remaining % 6);
        $encoded = json_encode($selection, $flags);
        expect(strlen($encoded))->toBe(16_384);
        $body = [...$this->body, 'model_selection' => $selection, 'prompt' => str_repeat('π', 70_001)];
        $baseline = launchRecords();

        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key, $flags)->assertAccepted()->json('data.id');
        $admitted = launchRecords();
        expect($admitted)->toBe(['operations' => $baseline['operations'] + 1, 'intents' => $baseline['intents'] + 1,
            'instances' => $baseline['instances']]);
        $request = json_decode(ConnLaunchIntent::query()->findOrFail($id)->request, flags: JSON_THROW_ON_ERROR);
        expect($request->prompt)->toBe($body['prompt'])
            ->and(json_encode($request->model_selection, $flags))->toBe($encoded)
            ->and($request->model_selection->options->integral)->toBe(1.0)
            ->and($request->model_selection->options->fractional)->toBe(0.125);

        callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key, $flags)->assertAccepted()->assertJsonPath('data.id', $id);
        $selection['options']->value .= 'a';
        callLaunchApi($this->phone, 'POST', 'conn/launches', [...$body, 'model_selection' => $selection], (string) Str::uuid(), $flags)
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['model_selection']]]);
        callLaunchApi($this->phone, 'POST', 'conn/launches', [...$body, 'model_selection' => $selection], $this->key, $flags)
            ->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict')->assertJsonMissingPath('data');
        expect(launchRecords())->toBe($admitted);

        $child = launchFixtureDispatch($id, $request->model_selection);
        $frozen = app(ConnLaunchDispatchSource::class)->resolve($child, $id, '', ConnLaunchDispatchSource::Field)->value;
        expect(json_encode($frozen->request->modelSelection, $flags))->toBe($encoded)
            ->and($frozen->request->initialMessage->text)->toBe($body['prompt']);
    });

    it('refuses unrepresentable model option numbers before admission or replay', function (): void {
        $body = [...$this->body, 'model_selection' => ['instanceId' => 'fixture', 'model' => 'fixture', 'options' => ['value' => 'fixture-number']]];
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $baseline = launchRecords();
        $post = fn (string $number): TestResponse => test()->call('POST', '/api/v1/conn/launches', [], [], [], [
            'REMOTE_ADDR' => $this->phone->wireguard_ip, 'HTTP_IDEMPOTENCY_KEY' => $this->key,
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], str_replace('"fixture-number"', $number, $json));

        $unrepresentable = ['9223372036854775808', '9223372036854775809', '1e400', '9007199254740991.1', '1e-400'];
        foreach ($unrepresentable as $number) {
            $post($number)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['body']]]);
        }
        expect(launchRecords())->toBe($baseline);
        $accepted = $post('9007199254740991.0')->assertAccepted();
        $post('90071992547409910e-1')->assertAccepted()->assertJsonPath('data.id', $accepted->json('data.id'));
        $admitted = launchRecords();
        foreach ($unrepresentable as $number) {
            $post($number)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['body']]]);
        }
        expect(launchRecords())->toBe($admitted);
    });

    it('requires active peer authentication and current target access even on replay', function (): void {
        callLaunchApi(null, 'POST', 'conn/launches', $this->body, $this->key)->assertForbidden();
        callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted();
        $baseline = launchRecords();
        $this->phone->accessibleNodes()->detach($this->host->id);

        foreach ([$this->key, (string) Str::uuid()] as $key) {
            callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $key)->assertForbidden()
                ->assertJsonPath('error.code', 'node_access.required')->assertJsonMissingPath('data');
        }
        expect(launchRecords())->toBe($baseline);
    });
});

describe('Conn launch scope', function (): void {
    it('scopes admission to the initiating Node and snapshots to the current shared profile and target', function (): void {
        $first = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data');
        $second = callLaunchApi($this->desktop, 'POST', 'conn/launches', [...$this->body, 'expected_node_id' => $this->desktop->id], $this->key)
            ->assertAccepted()->json('data');
        expect($second['id'])->not->toBe($first['id']);
        callLaunchApi($this->desktop, 'GET', 'conn/launches')->assertOk()->assertJsonPath('data', [$second, $first]);
        callLaunchApi($this->desktop, 'GET', 'conn/launches/'.$first['id'])->assertOk()->assertJsonPath('data', $first);

        $this->desktop->accessibleNodes()->detach($this->host->id);
        callLaunchApi($this->desktop, 'GET', 'conn/launches')->assertOk()->assertJsonPath('data', []);
        callLaunchApi($this->desktop, 'GET', 'conn/launches/'.$first['id'])->assertNotFound();
        $this->desktop->accessibleNodes()->attach($this->host->id);
        app(BindConnNodeProfileAction::class)->execute($this->desktop, $this->otherProfile);
        callLaunchApi($this->desktop, 'GET', 'conn/launches')->assertOk()->assertJsonPath('data', []);
        callLaunchApi($this->desktop, 'GET', 'conn/launches/'.$first['id'])->assertNotFound();
    });

    it('does not admit launch Operations to the generic or caller-owned Operation endpoints', function (): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        callLaunchApi($this->phone, 'GET', 'operations')->assertOk()->assertJsonPath('data', []);
        foreach (['operations/', 'conn/operations/'] as $path) {
            callLaunchApi($this->phone, 'GET', $path.$id)->assertNotFound();
            callLaunchApi($this->phone, 'POST', $path.$id.'/cancel')->assertNotFound();
        }
        expect(Operation::query()->where('kind', OperationKinds::ConnLaunch)->findOrFail($id)->cancel_requested_at)->toBeNull();
    });

    it('keeps ordinary Operations out of launch scope without changing their existing caller rule', function (): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $ordinary = launchNativeReceipt(Operation::query()->findOrFail($id), 'instance.create');
        callLaunchApi($this->phone, 'GET', 'operations/'.$ordinary->id)->assertOk();
        callLaunchApi($this->phone, 'GET', 'conn/operations/'.$ordinary->id)->assertOk();
        callLaunchApi($this->desktop, 'GET', 'conn/operations/'.$ordinary->id)->assertNotFound();
        callLaunchApi($this->desktop, 'POST', 'conn/operations/'.$ordinary->id.'/cancel')->assertNotFound();
        callLaunchApi($this->phone, 'GET', 'conn/launches/'.$ordinary->id)->assertNotFound();
        callLaunchApi($this->phone, 'POST', 'conn/launches/'.$ordinary->id.'/cancel')->assertNotFound();
        expect($ordinary->refresh()->cancel_requested_at)->toBeNull();
    });
});

describe('launch control and progress', function (): void {
    it('rechecks current target and profile access for both controls', function (): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $this->desktop->accessibleNodes()->detach($this->host->id);
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/cancel")->assertNotFound();
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/retry", ['target' => 'launch'])->assertNotFound();
        $this->desktop->accessibleNodes()->attach($this->host->id);
        app(BindConnNodeProfileAction::class)->execute($this->desktop, $this->otherProfile);
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/cancel")->assertNotFound();
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/retry", ['target' => 'application'])->assertNotFound();
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/retry", ['target' => 'unsupported'])->assertNotFound();
        expect(Operation::query()->findOrFail($id)->cancel_requested_at)->toBeNull();
        expect(Activity::query()->where('command', 'like', 'conn:launch:%')->exists())->toBeFalse();
    });

    it('refuses control when the authenticated peer was deactivated before taking the lock', function (): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        Node::query()->whereKey($this->desktop->id)->update(['status' => 'failed']);
        $control = app(ControlConnLaunchAction::class);

        expect(fn () => $control->cancel($this->desktop, $id, 'fixture-request'))->toThrow(ResourceOperationException::class, 'Active WireGuard peer identity required.')
            ->and(fn () => $control->retry($this->desktop, $id, 'launch', 'fixture-request'))->toThrow(ResourceOperationException::class, 'Active WireGuard peer identity required.');
        $launch = Operation::query()->findOrFail($id);
        expect($launch->cancel_requested_at)->toBeNull()->and($launch->state['launch_retry_requests'] ?? null)->toBeNull();
    });

    it('lets Cancel win the fence and keeps a joined wake and pre-existing Instance', function (): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $state = app(ConnLaunchState::class);
        $wake = $state->admitChild($id, ConnLaunchChild::Wake, 'fixture-join-wake', static fn (Operation $launch): array => [
            'operation' => launchNativeReceipt($launch, 'instance.wake', actor: 'node:77'), 'owned' => false,
        ]);
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/cancel")->assertAccepted()
            ->assertJsonPath('data.cancel_requested', true)->assertJsonPath('data.phase', 'cancelling')->assertJsonPath('data.finished_at', null)
            ->assertJsonPath('data.children.wake_operation_id', $wake->id)->assertJsonPath('data.initiator_node_id', $this->phone->id);
        $launch = Operation::query()->findOrFail($id);
        expect($launch->state['launch_cancellation']['node_id'])->toBe($this->desktop->id)->and($launch->status->terminal())->toBeFalse();
        expect(Activity::query()->where('command', 'like', 'conn:launch:%')->exists())->toBeFalse();

        $never = static fn (): never => throw new LogicException('No more work may start once Cancel wins.');
        expect($state->admitDispatch($id, $this->environment->environment_id, 'pinned', 'project', (object) [], $never))->toBeNull()
            ->and($state->admitChild($id, ConnLaunchChild::Completion, 'fixture-complete', $never))->toBeNull()
            ->and($state->admitChild($id, ConnLaunchChild::Destroy, 'fixture-destroy', $never))->toBeNull();
        $state->blocked($id, ConnLaunchStage::Cleanup, 'fixture-private-diagnostic');
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'cancelling')
            ->assertJsonPath('data.stages.cleanup.error.code', 'conn.launch_cleanup_pending')->assertJsonPath('data.finished_at', null)
            ->assertDontSee('fixture-private-diagnostic');
        expect(ConnLaunchIntent::query()->whereKey($id)->exists())->toBeTrue();

        $state->cleanupVerified($id);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'cancelled')
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.stages.cleanup.status', 'succeeded');
        expect(ConnLaunchIntent::query()->whereKey($id)->exists())->toBeFalse()->and($wake->refresh()->cancel_requested_at)->toBeNull()
            ->and(Instance::query()->whereKey($this->instance->id)->exists())->toBeTrue();
    });

    it('fences the signed publisher when Cancel commits before cleanup', function (bool $shared): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $wake = app(ConnLaunchState::class)->admitChild($id, ConnLaunchChild::Wake, 'fixture-signed-wake', static function (Operation $launch) use ($shared): array {
            $wake = launchNativeReceipt($launch, InstanceWake::Kind, 'fixture-signed-wake');
            $wake->update(['state' => [ExecutorWake::State => true, ...($shared ? ['wake_shared' => true] : [])]]);

            return ['operation' => $wake, 'owned' => true];
        });

        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/cancel")->assertAccepted();
        expect($wake->refresh())->status->toBe($shared ? OperationStatus::Queued : OperationStatus::Cancelled)->attempts->toBe(0);
        if (! $shared) {
            app()->call([new DispatchExecutorWake($wake->id), 'handle']);
            expect($wake->refresh())->status->toBe(OperationStatus::Cancelled)->attempts->toBe(0);
        }
    })->with(['unshared' => [false], 'shared' => [true]]);

    it('does not clear a cancelling launch while its owned wake remains open', function (): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $state = app(ConnLaunchState::class);
        $state->admitChild($id, ConnLaunchChild::Wake, 'fixture-owned-wake', static fn (Operation $launch): array => [
            'operation' => launchNativeReceipt($launch, 'instance.wake', 'fixture-owned-wake'), 'owned' => true,
        ]);
        callLaunchApi($this->phone, 'POST', "conn/launches/{$id}/cancel")->assertAccepted();

        expect(fn () => $state->cleanupVerified($id))->toThrow(LogicException::class);
        expect(ConnLaunchIntent::query()->whereKey($id)->exists())->toBeTrue();
        callLaunchApi($this->phone, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'cancelling');
    });

    it('gives a joined wake to the other caller even if its launch is cancelled', function (bool $nested, bool $joinFirst, bool $duringCancelRead = false): void {
        $this->project->forceFill(['nested_fleet' => $nested])->save();
        $this->host->update(['settings' => [...$this->host->settings, 'nested_fleet' => $nested]]);
        $default = Instance::query()->create(['node_id' => $this->host->id, 'project_id' => $this->project->id,
            'name' => 'default', 'checkout_path' => '/workspace/default', 'root' => '.', 'status' => InstanceState::Active]);
        $container = app(InstanceContainer::class);
        $container->reserve($default, $this->host)->update(['seed_commit' => str_repeat('a', 40)]);
        $this->instance->update(['status' => InstanceState::Active]);
        $container->reserve($this->instance, $this->host, $default);
        $kind = $nested ? InstanceStart::Kind : InstanceWake::Kind;
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $launch = Operation::query()->findOrFail($id);
        upgradeLaunchChildVersions($kind);
        $settlement = app(ConnLaunchSettlement::class);
        $settlement->advance($launch);
        $wake = ConnLaunchChild::Wake->operation($launch->refresh());
        expect($wake->kind)->toBe($kind)->and($wake->parent_operation_id)->toBe($id)->and($wake->operation_version)->toBe(1)->and($wake->status)->toBe(OperationStatus::Queued)
            ->and($wake->state['conn_launch_owner'])->toBe($launch->id);

        $key = (string) Str::uuid();
        $join = fn (): int => callLaunchApi($this->desktop, 'POST', "instances/{$this->instance->id}/wake", key: $key)
            ->assertAccepted()->assertJsonPath('data.kind', $kind)->json('data.id');
        if ($joinFirst && ! $duringCancelRead) {
            expect($join())->toBe($wake->id);
        }
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/cancel")->assertAccepted();
        $joinedDuringRead = null;
        if ($duringCancelRead) {
            $instance = $this->instance;
            $caller = 'node:'.$this->desktop->id;
            DB::listen(static function (QueryExecuted $query) use ($wake, $instance, $caller, &$joinedDuringRead): void {
                if ($joinedDuringRead === null && $query->bindings === [$wake->id] && str_starts_with($query->sql, 'select * from "operations"')) {
                    $joinedDuringRead = 0;
                    $joinedDuringRead = app(InstanceWake::class)->admitDeferred($instance, $caller, null)->id;
                }
            });
        }
        $settlement->advance($launch->refresh());
        if ($duringCancelRead) {
            expect($joinedDuringRead)->toBe($wake->id);
        }

        if ($joinFirst) {
            expect($wake->refresh()->status)->toBe(OperationStatus::Queued)
                ->and($wake->cancel_requested_at)->toBeNull()
                ->and($wake->state['wake_shared'])->toBeTrue();
            $wake->update(['status' => OperationStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);
            $this->instance->runtime()->update(['power_state' => InstanceRuntimePowerState::Running]);
            expect(Operation::query()->where('kind', $kind)->count())->toBe(1);
        } else {
            expect($wake->refresh()->status)->toBe(OperationStatus::Cancelled)
                ->and($wake->state['wake_shared'] ?? null)->toBeNull();
            $replacement = $join();
            expect($replacement)->not->toBe($wake->id)->and($join())->toBe($replacement)
                ->and(Operation::query()->where('kind', $kind)->count())->toBe(2);
        }
        $settlement->advance($launch->refresh());
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()
            ->assertJsonPath('data.phase', 'cancelled')->assertJsonPath('data.readiness.first_message', false)
            ->assertJsonPath('data.thread', null);
        expect(Operation::query()->whereIn('kind', [InstanceSleep::Kind, ConnLaunchDispatchSource::Kind])->exists())->toBeFalse();
    })->with([
        'ordinary join first' => [false, true],
        'nested start join first' => [true, true],
        'ordinary join during cancellation' => [false, true, true],
        'nested start join during cancellation' => [true, true, true],
        'ordinary cancel first' => [false, false],
        'nested start cancel first' => [true, false],
    ]);

    it('does not claim a clone that was reserved independently after launch admission', function (): void {
        launchFixtureDefault();
        $body = [...$this->body, 'target' => ['kind' => 'new', 'name' => 'fixture-raced-clone']];
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key)->assertAccepted()->json('data.id');
        $independent = containerCreate($this->project, $this->host, 'fixture-raced-clone');
        $baseline = launchRecords();

        expect(fn () => app(ConnLaunchState::class)->admitChild($id, ConnLaunchChild::Create, 'fixture-create', static fn (Operation $launch): array => [
            'operation' => Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $independent->target_id,
                'node_id' => $launch->node_id, 'triggered_by' => $launch->triggered_by, 'input' => ['created' => false],
                'state' => [], 'status' => OperationStatus::Queued, 'sequence' => 1, 'attempts' => 0, 'idempotency_key' => 'fixture-create']),
            'owned' => true,
        ]))->toThrow(LogicException::class);
        expect(launchRecords())->toBe($baseline);
        callLaunchApi($this->phone, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.children.create_operation_id', null)
            ->assertJsonPath('data.instance_id', null);
    });

    it('tracks the owned create before observing readiness and waits for it to settle before teardown', function (): void {
        launchFixtureDefault();
        $name = 'fixture-owned-clone';
        $body = [...$this->body, 'target' => ['kind' => 'new', 'name' => $name]];
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key)->assertAccepted()->json('data.id');
        $state = app(ConnLaunchState::class);
        $create = launchFixtureCreate($id, $name);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()
            ->assertJsonPath('data.children.create_operation_id', $create->id)->assertJsonPath('data.instance_id', $create->target_id)
            ->assertJsonPath('data.readiness.workspace_server', null);
        $this->environment->forceFill(['instance_id' => $create->target_id])->save();
        expect(fn () => $state->workspaceReady($id))->toThrow(LogicException::class);
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/cancel")->assertAccepted();
        $destroy = static fn (Operation $launch): array => [
            'operation' => launchNativeReceipt($launch, 'instance.destroy', 'fixture-destroy'), 'owned' => true,
        ];
        expect($state->admitChild($id, ConnLaunchChild::Destroy, 'fixture-destroy', $destroy))->toBeNull();

        $started = now()->subMinutes(2);
        $finished = now()->subMinute();
        $create->update(['status' => OperationStatus::Succeeded, 'started_at' => $started, 'finished_at' => $finished,
            'result' => ['t3' => ['environment_id' => 'different-registration']]]);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.readiness.workspace_server', false);
        $create->update(['result' => ['t3' => ['environment_id' => $this->environment->environment_id]]]);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.readiness.workspace_server', true);

        $teardown = $state->admitChild($id, ConnLaunchChild::Destroy, 'fixture-destroy', $destroy);
        expect($teardown?->target_id)->toBe($create->target_id);
        expect(fn () => $state->cleanupVerified($id))->toThrow(LogicException::class);
        $this->environment->delete();
        InstanceRuntime::query()->where('instance_id', $create->target_id)->delete();
        Instance::query()->whereKey($create->target_id)->delete();
        $teardown->update(['status' => OperationStatus::Succeeded, 'finished_at' => now()]);
        $state->cleanupVerified($id);

        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'cancelled')
            ->assertJsonPath('data.stages.workspace_server.status', 'succeeded')->assertJsonPath('data.readiness.workspace_server', true)
            ->assertJsonPath('data.stages.workspace_server.started_at', $started->toAtomString())
            ->assertJsonPath('data.stages.workspace_server.finished_at', $finished->toAtomString())
            ->assertJsonPath('data.stages.first_message.status', 'cancelled')->assertJsonPath('data.stages.cleanup.status', 'succeeded');
    });

    it('preserves a completed wake through cancellation without tearing down the existing Instance', function (): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $state = app(ConnLaunchState::class);
        $wake = $state->admitChild($id, ConnLaunchChild::Wake, 'fixture-wake', static fn (Operation $launch): array => [
            'operation' => launchNativeReceipt($launch, 'instance.wake', 'fixture-wake'), 'owned' => true,
        ]);
        $started = now()->subMinutes(2);
        $finished = now()->subMinute();
        $wake->update(['status' => OperationStatus::Succeeded, 'started_at' => $started, 'finished_at' => $finished]);
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/cancel")->assertAccepted();
        $this->environment->delete();
        $state->cleanupVerified($id);

        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'cancelled')
            ->assertJsonPath('data.stages.workspace_server.status', 'succeeded')->assertJsonPath('data.readiness.workspace_server', true)
            ->assertJsonPath('data.stages.workspace_server.started_at', $started->toAtomString())
            ->assertJsonPath('data.stages.workspace_server.finished_at', $finished->toAtomString())
            ->assertJsonPath('data.children.destroy_operation_id', null);
        expect(Instance::query()->whereKey($this->instance->id)->exists())->toBeTrue();
    });

    it('preserves work on a usable clone whose create failed', function (): void {
        [$launch, $instance] = launchCleanupFixture(OperationStatus::Failed);
        $reader = workspaceReader(launchWorkspaceAnswer(modified: 1));

        app(ConnLaunchSettlement::class)->sweep();

        callLaunchApi($this->desktop, 'GET', "conn/launches/{$launch->id}")->assertOk()
            ->assertJsonPath('data.phase', 'cancelling')
            ->assertJsonPath('data.stages.cleanup.error.code', 'instance.unsaved_work')
            ->assertJsonPath('data.children.destroy_operation_id', null);
        expect($reader->calls)->toHaveCount(1)
            ->and($instance->refresh()->status)->toBe(InstanceState::SourceResolved)
            ->and(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $instance->id)->exists())->toBeFalse();
    });

    it('preserves a clean clone when its failed create left T3 activity unreadable', function (): void {
        [$launch, $instance] = launchCleanupFixture(OperationStatus::Failed);
        $reader = workspaceReader(launchWorkspaceAnswer());

        app(ConnLaunchSettlement::class)->sweep();

        callLaunchApi($this->desktop, 'GET', "conn/launches/{$launch->id}")->assertOk()
            ->assertJsonPath('data.phase', 'cancelling')
            ->assertJsonPath('data.stages.cleanup.error.code', 'instance.unsaved_work')
            ->assertJsonPath('data.children.destroy_operation_id', null);
        expect($reader->calls)->toHaveCount(1)
            ->and($instance->refresh()->status)->toBe(InstanceState::SourceResolved);
    });

    it('removes an unstarted launch reservation without a workspace read', function (): void {
        [$launch, $instance] = launchCleanupFixture(OperationStatus::Cancelled);
        $reader = workspaceReader();

        app(ConnLaunchSettlement::class)->advance($launch);

        expect($reader->calls)->toBeEmpty()
            ->and(ConnLaunchChild::Destroy->operation($launch->refresh())?->target_id)->toBe($instance->id);
    });

    it('preserves the launch that claims a clone during the cleanup preflight', function (): void {
        [$launch, $instance] = launchCleanupFixture(OperationStatus::Succeeded);
        workspaceReader(launchWorkspaceAnswer());
        $body = launchBody($this->desktop, $this->profile);
        $body['target']['instance_id'] = $instance->id;
        $consumer = null;
        Http::fake(['http://10.44.0.30:3773/*' => function () use ($body, &$consumer) {
            $consumer = callLaunchApi($this->desktop, 'POST', 'conn/launches', $body, (string) Str::uuid())
                ->assertAccepted()->json('data.id');

            return Http::response(['threads' => []]);
        }]);

        expect(fn () => app(ConnLaunchSettlement::class)->advance($launch))
            ->toThrow(ResourceOperationException::class, 'Another Conn launch uses this Instance.');

        expect($consumer)->toBeInt()
            ->and($instance->refresh()->status)->toBe(InstanceState::SourceResolved)
            ->and(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $instance->id)->exists())->toBeFalse();
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$consumer}")->assertOk()
            ->assertJsonPath('data.instance_id', $instance->id)
            ->assertJsonPath('data.phase', 'preparing');
    });

    it('admits cleanup for an exclusively owned clean clone and refuses a later claim', function (): void {
        [$launch, $instance] = launchCleanupFixture(OperationStatus::Succeeded);
        workspaceReader(launchWorkspaceAnswer());
        Http::fake(['http://10.44.0.30:3773/*' => Http::response(['threads' => []])]);

        app(ConnLaunchSettlement::class)->advance($launch);

        $destroy = ConnLaunchChild::Destroy->operation($launch->refresh());
        expect($destroy?->target_id)->toBe($instance->id)
            ->and($instance->refresh()->status)->toBe(InstanceState::Removing);
        $body = launchBody($this->desktop, $this->profile);
        $body['target']['instance_id'] = $instance->id;
        callLaunchApi($this->desktop, 'POST', 'conn/launches', $body, (string) Str::uuid())->assertConflict()
            ->assertJsonPath('error.code', 'conn.launch_target_unavailable');
    });

    it('retries only an owned container removal with a recorded stop', function (string $failedStep, string $stop, bool $owned, string $reason, bool $retries): void {
        [$launch, $instance] = launchCleanupFixture(OperationStatus::Succeeded);
        $reader = workspaceReader(launchWorkspaceAnswer());
        Http::fake(['http://10.44.0.30:3773/*' => Http::response(['threads' => []])]);
        $settlement = app(ConnLaunchSettlement::class);
        $settlement->advance($launch);
        $destroy = ConnLaunchChild::Destroy->operation($launch->refresh());
        $nativeState = [...$destroy->state, 'swamp_run_id' => 'failed-destroy-run'];
        if (! $owned) {
            unset($nativeState['conn_launch_owner']);
        }
        $destroy->update(['status' => OperationStatus::Running, 'state' => $nativeState]);
        app(DevelopmentInstanceDestroyProjection::class)->apply($destroy, [
            'status' => 'failed', 'run_id' => 'failed-destroy-run', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(),
            'result' => ['summary' => ['failed_steps' => [$failedStep]], 'steps' => [
                'Stop the container' => ['status' => $stop],
                $failedStep => ['status' => 'failed'],
            ]],
        ]);
        $reader->answer = json_encode(['state' => 'unknown', 'reason' => $reason], JSON_THROW_ON_ERROR);

        $settlement->sweep();

        $current = ConnLaunchChild::Destroy->operation($launch->refresh());
        expect($instance->refresh()->status)->toBe(InstanceState::Removing);
        if ($retries) {
            expect($current?->id)->not->toBe($destroy->id)
                ->and($current?->target_id)->toBe($instance->id)
                ->and($current?->state['conn_launch_owner'])->toBe($launch->id)
                ->and($current?->idempotency_key)->toBe("conn-launch:{$launch->id}:destroy:{$destroy->id}")
                ->and($current?->input['removal_id'])->toBe($destroy->input['removal_id']);
        } else {
            expect($current?->id)->toBe($destroy->id)->and($launch->error_code)->toBe('instance.unsaved_work');
        }
    })->with([
        'owned database drop failure' => ['Drop owned databases-0', 'succeeded', true, 'stopped', true],
        'owned container delete failure' => ['Delete the container', 'succeeded', true, 'stopped', true],
        'stop failure' => ['Stop the container', 'failed', true, 'stopped', false],
        'unowned destroy' => ['Delete the container', 'succeeded', false, 'stopped', false],
        'workspace read timeout' => ['Drop owned databases-0', 'succeeded', true, 'timeout', false],
    ]);

    it('keeps verified Stop evidence only in its owned removal chain', function (string $case): void {
        [$launch, $instance] = launchCleanupFixture(OperationStatus::Succeeded);
        $reader = workspaceReader(launchWorkspaceAnswer());
        Http::fake(['http://10.44.0.30:3773/*' => Http::response(['threads' => []])]);
        $settlement = app(ConnLaunchSettlement::class);
        $settlement->advance($launch);
        $first = ConnLaunchChild::Destroy->operation($launch->refresh());
        $first->update(['status' => OperationStatus::Running, 'state' => [...$first->state, 'swamp_run_id' => 'database-drop-run']]);
        app(DevelopmentInstanceDestroyProjection::class)->apply($first, [
            'status' => 'failed', 'run_id' => 'database-drop-run', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(),
            'result' => ['summary' => ['failed_steps' => ['Drop owned databases-0']], 'steps' => [
                'Stop the container' => ['status' => 'succeeded'],
                'Drop owned databases-0' => ['status' => 'failed'],
            ]],
        ]);
        $reader->answer = json_encode(['state' => 'unknown', 'reason' => 'stopped'], JSON_THROW_ON_ERROR);
        $settlement->advance($launch->refresh());
        $retry = ConnLaunchChild::Destroy->operation($launch->refresh());
        $removal = $first->input['removal_id'];
        expect($retry?->idempotency_key)->toBe("conn-launch:{$launch->id}:destroy:{$first->id}")
            ->and($retry?->input['removal_id'])->toBe($removal);

        $refuse = static fn (Operation $operation) => app(StartSwampRun::class)->refuse(
            $operation, new ResourceOperationException('swamp.start_job_failed', 'The native start did not run.', 503));
        $refuse($retry);
        expect($retry->refresh()->status)->toBe(OperationStatus::Failed)
            ->and($retry->state['swamp_run_id'] ?? null)->toBeNull()
            ->and($retry->state['container_stop_succeeded'] ?? null)->toBeNull();
        if ($case === 'different removal') {
            $first->update(['input' => [...$first->input, 'removal_id' => (string) Str::uuid()]]);
        } elseif ($case === 'different live removal') {
            $other = (string) Str::uuid();
            $first->update(['input' => [...$first->input, 'removal_id' => $other]]);
            $retry->update(['input' => [...$retry->input, 'removal_id' => $other]]);
        } elseif ($case === 'different Instance') {
            $first->update(['target_id' => $this->instance->id]);
        } elseif ($case === 'different owner') {
            $first->update(['state' => [...$first->state, 'conn_launch_owner' => $launch->id + 1]]);
        } elseif ($case === 'dirty workspace') {
            $reader->answer = launchWorkspaceAnswer(modified: 1);
        } elseif ($case === 'unreadable workspace') {
            $reader->answer = json_encode(['state' => 'unknown', 'reason' => 'timeout'], JSON_THROW_ON_ERROR);
        }

        $settlement->sweep();

        $next = ConnLaunchChild::Destroy->operation($launch->refresh());
        if ($case !== 'same removal') {
            expect($next?->id)->toBe($retry->id)->and($launch->error_code)->toBe('instance.unsaved_work');

            return;
        }

        expect($next?->idempotency_key)->toBe("conn-launch:{$launch->id}:destroy:{$retry->id}")
            ->and($next?->input['removal_id'])->toBe($removal);
        $refuse($next);
        $settlement->advance($launch->refresh());
        $last = ConnLaunchChild::Destroy->operation($launch->refresh());
        expect($last?->idempotency_key)->toBe("conn-launch:{$launch->id}:destroy:{$next->id}")
            ->and($last?->input['removal_id'])->toBe($removal)
            ->and($last?->target_id)->toBe($instance->id);
        $settlement->advance($launch->refresh());
        expect(ConnLaunchChild::Destroy->operationId($launch->refresh()))->toBe($last->id);
    })->with(['same removal', 'different removal', 'different live removal', 'different Instance', 'different owner', 'dirty workspace', 'unreadable workspace']);

    it('restores only a launch-owned wake with verified idle evidence', function (): void {
        $this->instance->update(['status' => InstanceState::Active]);
        $runtime = InstanceRuntime::query()->create(['instance_id' => $this->instance->id, 'node_id' => $this->host->id,
            'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$this->instance->id, 'bridge_address' => '10.253.0.2',
            'runtime_version' => 'fixture-runtime', 'agent_version' => 'fixture-agent', 'power_state' => InstanceRuntimePowerState::Running]);
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $wake = app(ConnLaunchState::class)->admitChild($id, ConnLaunchChild::Wake, 'fixture-wake', static fn (Operation $launch): array => [
            'operation' => launchNativeReceipt($launch, 'instance.wake', 'fixture-wake'), 'owned' => true,
        ]);
        $wake->update(['status' => OperationStatus::Succeeded, 'started_at' => now()->subMinute(), 'finished_at' => now()]);
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/cancel")->assertAccepted();
        $times = new class implements NodeFileTimes
        {
            public bool $readable = false;

            public function read(Node $node, array $paths): ?array
            {
                return $this->readable ? array_fill_keys($paths, null) : null;
            }
        };
        app()->instance(ContainerIdleSweep::class, new ContainerIdleSweep($times, app(InstanceSleep::class), []));
        Http::fake(['http://10.44.0.30:3773/*' => Http::response(['threads' => []])]);

        $settlement = app(ConnLaunchSettlement::class);
        $launch = Operation::query()->findOrFail($id);
        $settlement->advance($launch);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()
            ->assertJsonPath('data.phase', 'cancelling')->assertJsonPath('data.stages.cleanup.error.code', 'conn.launch_wake_restore_unproven');
        expect(Operation::query()->where('kind', InstanceSleep::Kind)->count())->toBe(0);

        $times->readable = true;
        $settlement->advance($launch->refresh());
        $sleep = Operation::query()->where('kind', InstanceSleep::Kind)->sole();
        expect($sleep->target_id)->toBe($this->instance->id)->and($sleep->triggered_by)->toBe('node:'.$this->phone->id);
        $settlement->advance($launch->refresh());
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'cancelling');

        $sleep->update(['status' => OperationStatus::Succeeded, 'finished_at' => now()]);
        $runtime->update(['power_state' => InstanceRuntimePowerState::Stopped]);
        $settlement->advance($launch->refresh());
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()
            ->assertJsonPath('data.phase', 'cancelled')->assertJsonPath('data.children.destroy_operation_id', null);
        expect(Instance::query()->whereKey($this->instance->id)->exists())->toBeTrue();
    });

    it('orders wake restoration and existing-target admission on the same Instance', function (string $kind, string $order): void {
        $nested = $kind === InstanceStart::Kind;
        $this->project->forceFill(['nested_fleet' => $nested])->save();
        $this->host->update(['settings' => [...$this->host->settings, 'nested_fleet' => $nested]]);
        $default = Instance::query()->create(['node_id' => $this->host->id, 'project_id' => $this->project->id,
            'name' => 'default', 'checkout_path' => '/workspace/default', 'root' => '.', 'status' => InstanceState::Active]);
        $container = app(InstanceContainer::class);
        $container->reserve($default, $this->host)->update(['seed_commit' => str_repeat('a', 40)]);
        $this->instance->update(['status' => InstanceState::Active]);
        $runtime = $container->reserve($this->instance, $this->host, $default);
        $read = new class implements NodeFileTimes
        {
            public ?Closure $duringRead = null;

            public function read(Node $node, array $paths): ?array
            {
                $admit = $this->duringRead;
                $this->duringRead = null;
                $admit?->__invoke();

                return array_fill_keys($paths, null);
            }
        };
        $idleAgent = new class implements InstanceAgentActivity
        {
            public function read(Instance $instance): AgentActivity
            {
                return AgentActivity::read(false, null);
            }
        };
        app()->instance(ContainerIdleSweep::class, new ContainerIdleSweep($read, app(InstanceSleep::class), [$idleAgent]));
        Http::fake(['http://10.44.0.30:3773/*' => Http::response(['threads' => []])]);

        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $owner = Operation::query()->findOrFail($id);
        $settlement = app(ConnLaunchSettlement::class);
        $settlement->advance($owner);
        $wake = ConnLaunchChild::Wake->operation($owner->refresh());
        expect($wake->kind)->toBe($kind)->and($wake->state['conn_launch_owner'])->toBe($owner->id);
        $wake->update(['lock_key' => null, 'status' => OperationStatus::Succeeded,
            'started_at' => now()->subMinute(), 'finished_at' => now()]);
        $runtime->update(['power_state' => InstanceRuntimePowerState::Running]);
        callLaunchApi($this->phone, 'POST', "conn/launches/{$id}/cancel")->assertAccepted();

        $consumerKey = (string) Str::uuid();
        $body = launchBody($this->desktop, $this->profile);
        $consumerId = null;
        $admit = function () use ($body, $consumerKey, &$consumerId): void {
            $consumerId = callLaunchApi($this->desktop, 'POST', 'conn/launches', $body, $consumerKey)->assertAccepted()
                ->assertJsonPath('data.instance_id', $this->instance->id)->json('data.id');
        };
        $read->duringRead = match ($order) {
            'consumer first' => $admit,
            'newer wake' => static function () use ($owner, $kind): void {
                launchNativeReceipt($owner, $kind)->update(['status' => OperationStatus::Succeeded,
                    'started_at' => now(), 'finished_at' => now()]);
            },
            'changed owner' => static function () use ($wake): void {
                $wake->update(['state' => [...$wake->state, 'conn_launch_owner' => $wake->id]]);
            },
            default => null,
        };

        $settlement->advance($owner->refresh());
        expect($read->duringRead)->toBeNull();

        $sleeps = Operation::query()->where('kind', InstanceSleep::Kind)->where('target_id', $this->instance->id);
        if ($order === 'sleep first') {
            $sleep = $sleeps->sole();
            $admit();
            $consumer = Operation::query()->findOrFail($consumerId);
            $settlement->advance($consumer);
            expect(Operation::query()->where('kind', OperationKinds::ConnLaunchInspect)->exists())->toBeFalse()
                ->and(ConnLaunchChild::Wake->operation($consumer->refresh()))->toBeNull();
            $settlement->advance($owner->refresh());
            expect($sleeps->sole()->id)->toBe($sleep->id)
                ->and($owner->refresh()->status->terminal())->toBeFalse()
                ->and($runtime->refresh()->power_state)->toBe(InstanceRuntimePowerState::Running);

            $sleep->update(['lock_key' => null, 'status' => OperationStatus::Succeeded, 'finished_at' => now(), 'result' => ['sleep' => 'frozen']]);
            $runtime->update(['power_state' => InstanceRuntimePowerState::Stopped]);
            $settlement->advance($consumer->refresh());
            $newWake = ConnLaunchChild::Wake->operation($consumer->refresh());
            expect($newWake?->id)->not->toBeNull()->not->toBe($wake->id)
                ->and($newWake?->kind)->toBe(InstanceWake::Kind)
                ->and($newWake?->triggered_by)->toBe('node:'.$this->desktop->id)
                ->and($newWake?->status)->toBe(OperationStatus::Queued);
        } else {
            expect($sleeps->exists())->toBeFalse()
                ->and($runtime->refresh()->power_state)->toBe(InstanceRuntimePowerState::Running);
            if ($order === 'consumer first') {
                expect($consumerId)->not->toBeNull();
                $settlement->advance(Operation::query()->findOrFail($consumerId));
                expect(Operation::query()->where('kind', OperationKinds::ConnLaunchInspect)->sole()->input['launch_operation_id'])
                    ->toBe($consumerId);
            }
        }

        $settlement->advance($owner->refresh());
        callLaunchApi($this->phone, 'POST', "conn/launches/{$id}/cancel")->assertAccepted()->assertJsonPath('data.phase', 'cancelled');
        expect($sleeps->count())->toBe($order === 'sleep first' ? 1 : 0)
            ->and($wake->refresh()->status)->toBe(OperationStatus::Succeeded);
        if ($consumerId !== null) {
            callLaunchApi($this->desktop, 'POST', 'conn/launches', $body, $consumerKey)->assertAccepted()
                ->assertJsonPath('data.id', $consumerId)->assertJsonPath('data.phase', 'preparing');
        }
    })->with([InstanceWake::Kind, InstanceStart::Kind])->with(['consumer first', 'sleep first', 'newer wake', 'changed owner']);

    it('resumes a transient preparation failure using the exact successful workspace child', function (ConnLaunchChild $role): void {
        $body = $this->body;
        $name = 'fixture-retry-clone';
        if ($role === ConnLaunchChild::Create) {
            launchFixtureDefault();
            $body['target'] = ['kind' => 'new', 'name' => $name];
        }
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key)->assertAccepted()->json('data.id');
        $state = app(ConnLaunchState::class);
        $child = $role === ConnLaunchChild::Create ? launchFixtureCreate($id, $name)
            : $state->admitChild($id, $role, 'fixture-wake', static fn (Operation $launch): array => [
                'operation' => launchNativeReceipt($launch, 'instance.wake', 'fixture-wake'), 'owned' => true,
            ]);
        $this->environment->forceFill(['instance_id' => $child->target_id])->save();
        $started = now()->subMinutes(2);
        $finished = now()->subMinute();
        $child->update(['status' => OperationStatus::Succeeded, 'started_at' => $started, 'finished_at' => $finished,
            'result' => $role === ConnLaunchChild::Create ? ['t3' => ['environment_id' => $this->environment->environment_id]] : []]);

        $state->blocked($id, ConnLaunchStage::Workspace);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'needs_retry')
            ->assertJsonPath('data.error_code', 'conn.launch_workspace_failed')->assertJsonPath('data.readiness.workspace_server', true);
        expect($state->admitDispatch($id, $this->environment->environment_id, 'pinned', 'project', (object) [],
            static fn (): never => throw new LogicException('Retry must be requested before dispatch.')))->toBeNull();

        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/retry", ['target' => 'launch'])->assertAccepted()
            ->assertJsonPath('data.phase', 'preparing')->assertJsonPath('data.children.'.$role->value, $child->id);
        $dispatch = launchFixtureDispatch($id);
        expect($dispatch->target_id)->toBe($child->target_id)->and($dispatch->triggered_by)->toBe('node:'.$this->phone->id);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'dispatching')
            ->assertJsonPath('data.children.'.$role->value, $child->id)->assertJsonPath('data.stages.workspace_server.status', 'succeeded')
            ->assertJsonPath('data.stages.workspace_server.started_at', $started->toAtomString())
            ->assertJsonPath('data.stages.workspace_server.finished_at', $finished->toAtomString());
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/cancel")->assertConflict()
            ->assertJsonPath('error.code', 'conn.launch_handoff_committed');
    })->with(['create' => [ConnLaunchChild::Create], 'wake' => [ConnLaunchChild::Wake]]);

    it('wakes an existing Instance again on launch Retry after a failed probe and later sleep', function (string $outcome): void {
        $default = Instance::query()->create(['node_id' => $this->host->id, 'project_id' => $this->project->id,
            'name' => 'default', 'checkout_path' => '/workspace/default', 'root' => '.', 'status' => InstanceState::Active]);
        $container = app(InstanceContainer::class);
        $container->reserve($default, $this->host)->update(['seed_commit' => str_repeat('a', 40)]);
        $this->instance->update(['status' => InstanceState::Active]);
        $runtime = $container->reserve($this->instance, $this->host, $default);
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $launch = Operation::query()->findOrFail($id);
        $settlement = app(ConnLaunchSettlement::class);

        $settlement->advance($launch);
        $wake = ConnLaunchChild::Wake->operation($launch->refresh());
        $wake->update(['lock_key' => null, 'status' => OperationStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);
        $runtime->update(['power_state' => InstanceRuntimePowerState::Running]);
        $settlement->advance($launch->refresh());
        $probe = Operation::query()->where('kind', OperationKinds::ConnLaunchInspect)->sole();
        $probe->update(['lock_key' => null, 'status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'conn.launch_workspace_failed']);
        $settlement->advance($launch->refresh());
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()
            ->assertJsonPath('data.phase', 'needs_retry')->assertJsonPath('data.thread', null);

        $runtime->update(['power_state' => InstanceRuntimePowerState::Stopped]);
        foreach ([$this->desktop, $this->phone] as $caller) {
            callLaunchApi($caller, 'POST', "conn/launches/{$id}/retry", ['target' => 'launch'])->assertAccepted()
                ->assertJsonPath('data.id', $id)->assertJsonPath('data.phase', 'preparing');
        }
        $settlement->advance($launch->refresh());
        $freshWake = ConnLaunchChild::Wake->operation($launch->refresh());
        expect($freshWake->id)->not->toBe($wake->id);
        expect($freshWake->status)->toBe(OperationStatus::Queued)
            ->and($freshWake->triggered_by)->toBe($wake->triggered_by)
            ->and($wake->refresh()->status)->toBe(OperationStatus::Succeeded);

        $joinKey = (string) Str::uuid();
        $join = fn () => callLaunchApi($this->desktop, 'POST', "instances/{$this->instance->id}/wake", key: $joinKey)
            ->assertAccepted()->assertJsonPath('data.id', $freshWake->id);
        $join();
        $settlement->advance($launch->refresh());
        $join();
        expect($freshWake->refresh()->state['wake_shared'])->toBeTrue()
            ->and(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(2)
            ->and(Operation::query()->where('kind', OperationKinds::ConnLaunchInspect)->sole()->id)->toBe($probe->id)
            ->and(Operation::query()->where('kind', OperationKinds::ConnLaunchDispatch)->exists())->toBeFalse();

        if ($outcome === 'cancel') {
            $probe->update(['finished_at' => now()->subMinutes(3)]);
            callLaunchApi($this->phone, 'POST', "conn/launches/{$id}/cancel")->assertAccepted();
            $settlement->advance($launch->refresh());
            expect($freshWake->refresh()->status)->toBe(OperationStatus::Queued)
                ->and($freshWake->cancel_requested_at)->toBeNull();
        }

        $freshWake->update(['lock_key' => null, 'status' => OperationStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);
        $runtime->update(['power_state' => $outcome === 'sleep'
            ? InstanceRuntimePowerState::Stopped : InstanceRuntimePowerState::Running]);
        $settlement->advance($launch->refresh());
        if ($outcome !== 'dispatch') {
            callLaunchApi($this->phone, 'GET', "conn/launches/{$id}")->assertOk()
                ->assertJsonPath('data.phase', $outcome === 'cancel' ? 'cancelled' : 'needs_retry')
                ->assertJsonPath('data.thread', null);
            expect(Operation::query()->where('kind', OperationKinds::ConnLaunchInspect)->count())->toBe(1)
                ->and(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(2)
                ->and(Operation::query()->whereIn('kind', ['instance.sleep', OperationKinds::ConnLaunchDispatch])->exists())->toBeFalse();

            return;
        }

        $freshProbe = Operation::query()->where('kind', OperationKinds::ConnLaunchInspect)->whereKeyNot($probe->id)->sole();
        $freshProbe->update(['lock_key' => null, 'status' => OperationStatus::Succeeded, 'finished_at' => now(), 'result' => [
            'environment_id' => $this->environment->environment_id, 'incarnation' => 'fixture-incarnation',
            'project_id' => 'fixture-t3-project', 'model_selection' => ['instanceId' => 'fixture-provider', 'model' => 'fixture-model'],
        ]]);
        $settlement->advance($launch->refresh());
        $settlement->advance($launch->refresh());
        expect(Operation::query()->where('kind', OperationKinds::ConnLaunchDispatch)->sole()->triggered_by)->toBe($wake->triggered_by);
        expect(Operation::query()->where('kind', OperationKinds::ConnLaunchInspect)->count())->toBe(2);
        callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()
            ->assertJsonPath('data.id', $id)->assertJsonPath('data.children.wake_operation_id', $freshWake->id)
            ->assertJsonPath('data.phase', 'dispatching');
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/cancel")->assertConflict()
            ->assertJsonPath('error.code', 'conn.launch_handoff_committed');
    })->with(['dispatch', 'cancel', 'sleep']);

    it('rolls back the dispatch fence and frozen request if child admission fails', function (): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $state = app(ConnLaunchState::class);
        $state->workspaceReady($id);
        $before = launchRecords();

        expect(fn () => $state->admitDispatch($id, $this->environment->environment_id, 'pinned', 'project', (object) [],
            static fn (Operation $launch): Operation => launchNativeReceipt($launch, 'instance.wake', 'fixture-wrong-child')))->toThrow(LogicException::class);
        expect(launchRecords())->toBe($before)->and(ConnLaunchIntent::query()->findOrFail($id)->dispatch)->toBeNull();
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.actions.cancel.available', true);
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/cancel")->assertAccepted()->assertJsonPath('data.phase', 'cancelling');
    });

    it('publishes the accepted thread once and adds the project reference only to a surviving workspace', function (bool $workspaceDeleted): void {
        $body = [...$this->body, 'workspace_id' => 'fixture-workspace'];
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key)->assertAccepted()->json('data.id');
        $dispatch = launchFixtureDispatch($id);
        app(ConnLaunchState::class)->accepted($id, $dispatch->id, now());
        $this->profile->update(['settings' => ['workspaces' => $workspaceDeleted ? [['id' => 'another-workspace']]
            : [['id' => 'fixture-workspace', 'projectRefs' => ['another-environment:another-project']]]]]);
        $this->phone->accessibleNodes()->detach($this->host->id);
        ConnThread::query()->create(['conn_environment_id' => $this->environment->id, 'instance_id' => $this->instance->id,
            'thread_id' => $body['thread_id'], 'title' => 'Generated by T3', 'seen_at' => now()]);

        $publish = app(PublishConnLaunchAction::class);
        $publish->execute($id);
        $published = $this->profile->fresh();
        $publish->execute($id);

        expect($this->profile->fresh()->settings_version)->toBe($published->settings_version)
            ->and($published->settings['attachedThreads']['threads'])->toBe([
                ['environmentId' => $this->environment->environment_id, 'threadId' => $body['thread_id']],
            ])
            ->and($published->settings['workspaces'])->toBe($workspaceDeleted ? [['id' => 'another-workspace']]
                : [['id' => 'fixture-workspace', 'projectRefs' => ['another-environment:another-project', 'fixture-environment:fixture-t3-project']]])
            ->and(ConnThread::query()->where('thread_id', $body['thread_id'])->pluck('title')->all())->toBe(['Generated by T3']);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'ready')
            ->assertJsonPath('data.thread.thread_id', $body['thread_id']);
    })->with(['workspace still present' => false, 'workspace removed' => true]);

    it('finishes a cancel before any work through the recorded reconciliation pass', function (): void {
        launchFixtureDefault();
        $body = [...$this->body, 'target' => ['kind' => 'new', 'name' => 'cancel-before-preparation']];
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key)->assertAccepted()->json('data.id');
        $before = Instance::query()->count();
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/cancel")->assertAccepted();
        app(ConnLaunchSettlement::class)->sweep();
        expect(Instance::query()->count())->toBe($before)->and(ConnLaunchIntent::query()->find($id))->toBeNull();
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'cancelled')
            ->assertJsonPath('data.children.create_operation_id', null);
    });

    it('keeps cancelling until the exact timed-out probe settles and its session cannot survive', function (): void {
        InstanceRuntime::query()->create(['instance_id' => $this->instance->id, 'node_id' => $this->host->id,
            'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$this->instance->id, 'bridge_address' => '10.253.0.2',
            'runtime_version' => 'fixture-runtime', 'agent_version' => 'fixture-agent', 'power_state' => InstanceRuntimePowerState::Running]);
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $settlement = app(ConnLaunchSettlement::class);
        $settlement->advance(Operation::query()->findOrFail($id));
        $parent = Operation::query()->findOrFail($id);
        $probe = Operation::query()->findOrFail($parent->state['launch_inspect_operation_id']);
        expect($probe->kind)->toBe(OperationKinds::ConnLaunchInspect)->and($probe->triggered_by)->toBe($parent->triggered_by);
        $probe->update(['status' => OperationStatus::Failed, 'finished_at' => now()->subMinutes(3), 'error_code' => 'operation.timed_out',
            'state' => ['native_timeout' => ['native_terminal_status' => 'unproven']]]);
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/cancel")->assertAccepted();
        $settlement->advance($parent->refresh());
        $blocked = callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'cancelling')
            ->assertJsonPath('data.stages.cleanup.error.code', 'operation.timed_out')->json('data.stages.cleanup.finished_at');
        $this->travel(1)->seconds();
        $settlement->advance($parent->refresh());
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.stages.cleanup.finished_at', $blocked);

        $probe->update(['state' => ['native_timeout' => ['native_terminal_status' => 'failed', 'native_terminal_observed_at' => now()->toISOString()]]]);
        $settlement->advance($parent->refresh());
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'cancelling');
        $this->travel(121)->seconds();
        $settlement->advance($parent->refresh());
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'cancelled')
            ->assertJsonPath('data.children.destroy_operation_id', null);
        expect(Instance::query()->find($this->instance->id))->not->toBeNull();
    });

    it('automatically reconciles the first ambiguous handoff with read-only work', function (): void {
        InstanceRuntime::query()->create(['instance_id' => $this->instance->id, 'node_id' => $this->host->id,
            'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$this->instance->id, 'bridge_address' => '10.253.0.2',
            'runtime_version' => 'fixture-runtime', 'agent_version' => 'fixture-agent', 'power_state' => InstanceRuntimePowerState::Running]);
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $dispatch = launchFixtureDispatch($id);
        $dispatch->update(['status' => OperationStatus::Failed, 'input' => [...$dispatch->input, 'intent' => ['action' => 'launch']],
            'finished_at' => now(), 'error_code' => 'conn.launch_first_message_unconfirmed']);
        $launch = Operation::query()->findOrFail($id);
        $launch->update(['state' => [...$launch->state, 'launch_dispatch_copy_removed' => $dispatch->id]]);

        $settlement = app(ConnLaunchSettlement::class);
        $settlement->advance($launch);
        $recovery = Operation::query()->findOrFail($launch->refresh()->state['launch_dispatch_operation_id']);
        expect($recovery->id)->not->toBe($dispatch->id)->and($recovery->input['intent']['action'])->toBe('reconcile-launch')
            ->and($recovery->triggered_by)->toBe($dispatch->triggered_by)
            ->and(Operation::query()->where('kind', OperationKinds::ConnLaunchDispatch)->count())->toBe(2);

        $recovery->update(['status' => OperationStatus::Succeeded, 'result' => ['accepted_at' => now()->toISOString()],
            'finished_at' => now(), 'error_code' => null, 'lock_key' => null]);
        $launch->update(['state' => [...$launch->state, 'launch_dispatch_copy_removed' => $recovery->id]]);
        $settlement->advance($launch);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'ready')
            ->assertJsonPath('data.thread.message_id', $this->body['message_id']);
        expect(ConnLaunchIntent::query()->find($id))->toBeNull()->and(Operation::query()->where('kind', OperationKinds::ConnLaunchDispatch)->count())->toBe(2);
    });

    it('stops polling a ready launch whose preparation found no application work', function (): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $dispatch = launchFixtureDispatch($id);
        $state = app(ConnLaunchState::class);
        $state->accepted($id, $dispatch->id, now());
        $state->published($id);
        $state->applicationNotRequired($id);

        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'ready')
            ->assertJsonPath('data.stages.application_preview.status', 'skipped')->assertJsonPath('data.readiness.application_preview', null)
            ->assertJsonPath('data.poll_after_seconds', null)->assertJsonPath('data.actions.retry_application.available', false);
    });

    it('rejects late Cancel and binds recovery to the exact frozen private dispatch', function (): void {
        $body = [...$this->body, 'interaction_mode' => 'plan', 'runtime_mode' => 'approval-required'];
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key)->assertAccepted()->json('data.id');
        $dispatch = launchFixtureDispatch($id);
        $state = app(ConnLaunchState::class);
        $field = ConnLaunchDispatchSource::Field;
        $sourceName = ConnLaunchDispatchSource::Name;
        $private = [$field => ['$secret' => $sourceName, 'id' => $id]];
        $secrets = app(OperationSecrets::class);
        $frozen = $secrets->hydrate($dispatch, '', $private, [$field => $sourceName])['input'][$field];
        expect($frozen->request->initialMessage->text)->toBe($body['prompt'])->and($frozen->request->initialMessage->messageId)->toBe($body['message_id'])
            ->and($frozen->request->commandId)->toBe($body['command_id'])->and($frozen->request->threadId)->toBe($body['thread_id'])
            ->and($frozen->request->title)->not->toContain('Fixture first message')->and($frozen->request->generateTitle)->toBeTrue()
            ->and($frozen->request->creationSource)->toBe($body['source'])
            ->and($frozen->request->interactionMode)->toBe('plan')->and($frozen->request->runtimeMode)->toBe('approval-required');
        $dispatch->node_id = $this->otherHost->id;
        expect(fn () => $secrets->hydrate($dispatch, '', $private, [$field => $sourceName]))->toThrow(ResourceOperationException::class);
        $dispatch->refresh()->update(['status' => OperationStatus::Failed, 'finished_at' => now()]);
        $state->blocked($id, ConnLaunchStage::FirstMessage);
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/cancel")->assertConflict()->assertJsonPath('error.code', 'conn.launch_handoff_committed');
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'needs_retry')
            ->assertJsonPath('data.readiness.first_message', null)->assertJsonPath('data.actions.retry_launch.available', true);
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/retry", ['target' => 'launch'])->assertAccepted()
            ->assertJsonPath('data.phase', 'dispatching')->assertJsonPath('data.initiator_node_id', $this->phone->id);
        $state->blocked($id, ConnLaunchStage::FirstMessage);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'dispatching');
        $retry = $state->admitDispatch($id, 'replacement-environment', 'replacement-incarnation', 'replacement-project', (object) [],
            function (Operation $launch, bool $recovery): Operation {
                expect($recovery)->toBeTrue()->and($launch->state['launch_last_retry']['launch']['node_id'])->toBe($this->desktop->id);

                return launchNativeReceipt($launch, ConnLaunchDispatchSource::Kind, 'fixture-recovery', ['launch_operation_id' => $launch->id]);
            });
        expect(json_encode($secrets->hydrate($retry, '', $private, [$field => $sourceName])['input'][$field]))->toBe(json_encode($frozen));
        callLaunchApi($this->phone, 'GET', 'operations/'.$retry->id)->assertNotFound();
        callLaunchApi($this->phone, 'GET', 'operations')->assertOk()->assertJsonPath('data', []);

        $acceptedAt = new DateTimeImmutable('2026-11-17T20:00:00.123+00:00');
        $state->accepted($id, $retry->id, $acceptedAt);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'publishing')
            ->assertJsonPath('data.thread.thread_id', $body['thread_id'])->assertJsonPath('data.thread.message_id', $body['message_id'])
            ->assertJsonPath('data.thread.environment_id', 'fixture-environment')->assertJsonPath('data.thread.project_id', 'fixture-t3-project')
            ->assertJsonPath('data.thread.accepted_at', '2026-11-17T20:00:00.123+00:00')->assertJsonPath('data.readiness.first_message', true)
            ->assertJsonPath('data.thread.model_selection.model', 'actual-model')->assertDontSee('Fixture first message');
        expect(ConnLaunchIntent::query()->whereKey($id)->exists())->toBeFalse()
            ->and(fn () => $secrets->hydrate($retry, '', $private, [$field => $sourceName]))->toThrow(ResourceOperationException::class);
    });

    it('waits for T3 inspection before marking an existing workspace ready', function (): void {
        InstanceRuntime::query()->create(['instance_id' => $this->instance->id, 'node_id' => $this->host->id,
            'incus_project' => 'orbit-instances', 'container_name' => 'fixture-clone', 'bridge_address' => '10.253.0.2',
            'runtime_version' => 'fixture-runtime', 'agent_version' => 'fixture-agent', 'power_state' => InstanceRuntimePowerState::Running]);
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()
            ->assertJsonPath('data.readiness.workspace_server', null)->json('data.id');
        $launch = Operation::query()->findOrFail($id);
        $probe = launchNativeReceipt($launch, OperationKinds::ConnLaunchInspect, 'conn-launch:'.$id.':inspect:initial',
            ['launch_operation_id' => $id]);
        $probe->update(['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'conn.launch_workspace_failed']);
        $launch->update(['state' => [...($launch->state ?? []), 'launch_inspect_operation_id' => $probe->id]]);

        app(ConnLaunchSettlement::class)->advance($launch);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()
            ->assertJsonPath('data.phase', 'needs_retry')->assertJsonPath('data.readiness.workspace_server', false)
            ->assertJsonPath('data.stages.workspace_server.status', 'failed');
    });

    it('observes existing completion without ownership and consumes a racing application retry', function (): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $this->instance->update(['status' => InstanceState::SourceResolved]);
        $dispatch = launchFixtureDispatch($id);
        $state = app(ConnLaunchState::class);
        $state->accepted($id, $dispatch->id, now());
        $state->published($id);
        $launch = Operation::query()->findOrFail($id);
        $actor = 'node:'.$this->desktop->id;
        $failed = launchNativeReceipt($launch, 'instance.complete', actor: $actor);
        $failed->update(['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'instance.container_process_inactive',
            'result' => ['ready' => false, 'recovery' => ['retry' => ['requires' => 'project_process_definition']]]]);
        $settlement = app(ConnLaunchSettlement::class);
        $settlement->advance($launch);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()
            ->assertJsonPath('data.children.completion_operation_id', $failed->id)
            ->assertJsonPath('data.actions.retry_application.requires', 'project_process_definition');
        expect($launch->refresh()->state['launch_children']['completion_operation_id']['owned'])->toBeFalse();

        $failed->update(['result' => ['ready' => false]]);
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/retry", ['target' => 'application'])->assertAccepted();
        $completed = launchNativeReceipt($launch, 'instance.complete', actor: $actor);
        $completed->update(['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'result' => ['ready' => true]]);
        $this->instance->update(['status' => InstanceState::Active]);
        $settlement->advance($launch->refresh());

        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()
            ->assertJsonPath('data.children.completion_operation_id', $completed->id)
            ->assertJsonPath('data.readiness.application_preview', true)->assertJsonPath('data.poll_after_seconds', null);
        expect($launch->refresh()->state['launch_children']['completion_operation_id']['owned'])->toBeFalse()
            ->and($launch->state)->not->toHaveKey('launch_retry_requests.application')
            ->and(Operation::query()->where('kind', 'instance.complete')->count())->toBe(2);
    });

    it('settles a refused application retry on an existing Instance without admitting a child', function (): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $path = "conn/launches/{$id}";
        $this->instance->update(['status' => InstanceState::SourceResolved]);
        $dispatch = launchFixtureDispatch($id);
        $state = app(ConnLaunchState::class);
        $state->accepted($id, $dispatch->id, now());
        $state->published($id);

        $launch = Operation::query()->findOrFail($id);
        $failed = launchNativeReceipt($launch, 'instance.complete', actor: 'node:'.$this->desktop->id);
        $failed->update(['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'instance.container_process_inactive',
            'result' => ['ready' => false]]);
        $settlement = app(ConnLaunchSettlement::class);
        $settlement->advance($launch);
        $before = callLaunchApi($this->phone, 'GET', $path)->assertOk()->json('data');

        $request = ['target' => 'application'];
        $pending = callLaunchApi($this->desktop, 'POST', "{$path}/retry", $request)->assertAccepted()
            ->assertJsonPath('data.poll_after_seconds', 2)->json('data');
        $retry = $launch->refresh()->state['launch_retry_requests']['application'];
        expect(callLaunchApi($this->phone, 'POST', "{$path}/retry", $request)->assertAccepted()->json('data'))->toBe($pending)
            ->and($launch->refresh()->state['launch_retry_requests']['application'])->toBe($retry);

        $stale = clone $launch;
        $this->travel(3)->seconds();
        $settlement->sweep();
        $after = callLaunchApi($this->desktop, 'GET', $path)->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.phase', 'ready')
            ->assertJsonPath('data.finished_at', $before['finished_at'])->assertJsonPath('data.error_code', null)
            ->assertJsonPath('data.children.completion_operation_id', $failed->id)
            ->assertJsonPath('data.thread', $before['thread'])->assertJsonPath('data.stages.first_message', $before['stages']['first_message'])
            ->assertJsonPath('data.stages.publication', $before['stages']['publication'])
            ->assertJsonPath('data.readiness.first_message', true)->assertJsonPath('data.readiness.application_preview', false)
            ->assertJsonPath('data.stages.application_preview', ['status' => 'failed', 'started_at' => $retry['requested_at'],
                'finished_at' => now()->toAtomString(), 'error' => ['code' => 'instance.complete_unavailable',
                    'detail' => 'The Instance cannot currently be completed.']])
            ->assertJsonPath('data.actions.retry_application.available', true)->assertJsonPath('data.poll_after_seconds', null)
            ->assertDontSee('Only a container clone can be completed.')
            ->json('data');

        $this->travel(3)->seconds();
        $settlement->sweep();
        expect(callLaunchApi($this->phone, 'GET', 'conn/launches')->assertOk()->json('data'))->toBe([$after])
            ->and($launch->refresh()->state['launch_last_retry']['application'])->toBe($retry)
            ->and($launch->state)->not->toHaveKey('launch_retry_requests.application')
            ->and(Operation::query()->where('kind', 'instance.complete')->pluck('id')->all())->toBe([$failed->id])
            ->and(Operation::query()->where('kind', ConnLaunchDispatchSource::Kind)->count())->toBe(1);

        $withoutRetry = clone $launch;
        callLaunchApi($this->phone, 'POST', "{$path}/retry", $request)->assertAccepted();
        $current = $launch->refresh()->state['launch_retry_requests']['application'];
        expect($current['id'])->not->toBe($retry['id']);
        $settlement->advance($stale);
        expect($launch->refresh()->state['launch_retry_requests']['application'])->toBe($current);
        $state->admitApplicationRetry($id, $retry['id'], 'stale-key', static function (): never {
            throw new LogicException('A stale settlement must not enter completion admission.');
        });
        expect($launch->refresh()->state['launch_retry_requests']['application'])->toBe($current);

        $completed = launchNativeReceipt($launch, 'instance.complete', actor: 'node:'.$this->desktop->id);
        $completed->update(['status' => OperationStatus::Succeeded, 'started_at' => now(), 'finished_at' => now(), 'result' => ['ready' => true]]);
        $this->instance->update(['status' => InstanceState::Active]);
        $settlement->advance($stale);
        $settlement->advance($withoutRetry);
        expect($launch->refresh()->state['launch_retry_requests']['application'])->toBe($current)
            ->and(ConnLaunchChild::Completion->operationId($launch))->toBe($failed->id);
        $settlement->sweep();
        callLaunchApi($this->desktop, 'GET', $path)->assertOk()
            ->assertJsonPath('data.children.completion_operation_id', $completed->id)
            ->assertJsonPath('data.stages.application_preview.error', null)
            ->assertJsonPath('data.readiness.application_preview', true)
            ->assertJsonPath('data.thread', $before['thread']);
    });

    it('settles only the pending application Retry when its exact Instance is gone', function (bool $newTarget): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $path = "conn/launches/{$id}";
        $this->instance->update(['status' => InstanceState::SourceResolved]);
        $dispatch = launchFixtureDispatch($id);
        $state = app(ConnLaunchState::class);
        $state->accepted($id, $dispatch->id, now());
        $state->published($id);
        $launch = Operation::query()->findOrFail($id);
        $prior = launchNativeReceipt($launch, 'instance.complete');
        $prior->update(['status' => OperationStatus::Failed, 'finished_at' => now()]);
        $state->observeCompletion($id, $prior);
        $failed = launchNativeReceipt($launch, 'instance.complete');
        $failed->update(['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'instance.container_process_inactive']);
        $state->observeCompletion($id, $failed);
        $launch->refresh();
        if ($newTarget) {
            $input = $launch->input;
            unset($input['instance_id']);
            $launch->update(['input' => [...$input, 'target_kind' => 'new']]);
        }

        $before = callLaunchApi($this->phone, 'GET', $path)->assertOk()->json('data');
        $request = ['target' => 'application'];
        $pending = callLaunchApi($this->desktop, 'POST', "{$path}/retry", $request)->assertAccepted()
            ->assertJsonPath('data.poll_after_seconds', 2)->json('data');
        $launch->refresh();
        $retry = $launch->state['launch_retry_requests']['application'];
        $children = $launch->state['launch_children'];
        $history = $launch->state['launch_child_history'];
        expect(callLaunchApi($this->phone, 'POST', "{$path}/retry", $request)->assertAccepted()->json('data'))->toBe($pending);

        $this->environment->delete();
        $this->instance->delete();
        $settlement = app(ConnLaunchSettlement::class);
        $this->travel(3)->seconds();
        $settlement->sweep();
        $after = callLaunchApi($this->desktop, 'GET', $path)->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.phase', 'ready')
            ->assertJsonPath('data.finished_at', $before['finished_at'])->assertJsonPath('data.error_code', null)
            ->assertJsonPath('data.children', $before['children'])->assertJsonPath('data.thread', $before['thread'])
            ->assertJsonPath('data.stages.first_message', $before['stages']['first_message'])
            ->assertJsonPath('data.stages.publication', $before['stages']['publication'])
            ->assertJsonPath('data.readiness.first_message', true)
            ->assertJsonPath('data.readiness.application_preview', false)->assertJsonPath('data.poll_after_seconds', null)
            ->assertJsonPath('data.stages.application_preview', ['status' => 'failed', 'started_at' => $retry['requested_at'],
                'finished_at' => now()->toAtomString(), 'error' => ['code' => 'instance.complete_unavailable',
                    'detail' => 'The Instance cannot currently be completed.']])
            ->assertJsonPath('data.actions.retry_application.available', false)->json('data');

        $this->travel(3)->seconds();
        $settlement->advance($launch);
        $settlement->sweep();
        callLaunchApi($this->phone, 'POST', "{$path}/retry", $request)->assertConflict()
            ->assertJsonPath('error.code', 'conn.launch_retry_unavailable');
        expect(callLaunchApi($this->phone, 'GET', 'conn/launches')->assertOk()->json('data'))->toBe([$after])
            ->and($launch->refresh()->state['launch_last_retry']['application'])->toBe($retry)
            ->and($launch->state)->not->toHaveKey('launch_retry_requests.application')
            ->and($launch->state['launch_children'])->toBe($children)
            ->and($launch->state['launch_child_history'])->toBe($history)
            ->and(Operation::query()->where('kind', 'instance.complete')->orderBy('id')->pluck('id')->all())->toBe([$prior->id, $failed->id])
            ->and(Operation::query()->where('kind', ConnLaunchDispatchSource::Kind)->count())->toBe(1);
    })->with(['existing target' => [false], 'new target' => [true]]);

    it('admits the exact v1 completion for an already-requested retry on a published launch', function (): void {
        [$launch] = launchFixtureFailedApplication();
        $path = 'conn/launches/'.$launch->id.'/retry';
        callLaunchApi($this->phone, 'POST', $path, ['target' => 'application'])->assertAccepted();
        $launch->refresh();
        expect($launch->status)->toBe(OperationStatus::Succeeded);
        upgradeLaunchChildVersions('instance.complete', OperationKinds::ConnLaunchInspect);
        $versions = array_column(app(OperationVersionCleanup::class)->check()['versions'], 'eligible', 'kind');
        expect($versions)->toBe([OperationKinds::ConnLaunchInspect => true, 'instance.complete' => false]);
        app(ConnLaunchSettlement::class)->advance($launch);
        $retry = ConnLaunchChild::Completion->operation($launch->refresh());
        expect($retry->parent_operation_id)->toBe($launch->id)->and($retry->operation_version)->toBe(1)
            ->and($retry->state['swamp_start']['workflow'])->toBe('operation-instance-complete-retained-v1')
            ->and($launch->state['launch_retry_requests']['application'] ?? null)->toBeNull()
            ->and(app(OperationVersionCleanup::class)->check()['eligible'])->toBeFalse();
        $retry->update(['status' => OperationStatus::Failed, 'lock_key' => null, 'finished_at' => now(),
            'error_code' => 'instance.container_process_inactive', 'result' => ['ready' => false]]);
        expect(app(OperationVersionCleanup::class)->check()['eligible'])->toBeTrue();
        callLaunchApi($this->phone, 'POST', $path, ['target' => 'application'])->assertStatus(409);
    });

    it('upgrades the existing v1 launch ledger without losing admitted identity or permitting edits to the new pins', function (): void {
        $migration = require database_path('migrations/2027_03_08_000005_fence_conn_launch_retries.php');
        $migration->down();
        $legacy = Operation::query()->create(['kind' => OperationKinds::ConnLaunch, 'operation_version' => 1, 'child_versions' => [],
            'target_type' => 'conn_profile', 'target_id' => $this->profile->id, 'node_id' => $this->host->id,
            'input' => ['launch_id' => $this->key], 'state' => [], 'status' => OperationStatus::Waiting]);
        $previous = DB::table('sqlite_schema')->whereIn('name', ['operations_version_immutable', 'operations_version_admission'])->orderBy('name')->pluck('sql')->all();
        DB::statement("CREATE TRIGGER fixture_abort_launch_backfill BEFORE UPDATE OF child_versions ON operations
            BEGIN SELECT RAISE(ABORT, 'fixture.launch_backfill_interrupted'); END");
        expect(fn () => $migration->up())->toThrow(QueryException::class, 'fixture.launch_backfill_interrupted');
        expect($legacy->refresh()->child_versions)->toBe([])->and(DB::table('sqlite_schema')
            ->whereIn('name', ['operations_version_immutable', 'operations_version_admission'])->orderBy('name')->pluck('sql')->all())->toBe($previous)
            ->and(DB::table('sqlite_schema')->where('name', 'operations_version_launch_retry')->exists())->toBeFalse();
        DB::statement('DROP TRIGGER fixture_abort_launch_backfill');
        $migration->up();
        $migration->up();
        expect($legacy->refresh()->child_versions)->toBe(OperationKinds::CoordinatorChildren[OperationKinds::ConnLaunch])
            ->and($legacy->input['launch_id'])->toBe($this->key)->and($legacy->status)->toBe(OperationStatus::Waiting);
        expect(fn () => $legacy->update(['child_versions' => []]))->toThrow(QueryException::class, 'operation.version_immutable');
    });

    it('retains the eight fixed v1 child pins for an earlier Gateway that admits a launch after backfill', function (): void {
        $row = ['kind' => OperationKinds::ConnLaunch, 'operation_version' => 1, 'child_versions' => [],
            'target_type' => 'conn_profile', 'target_id' => $this->profile->id, 'node_id' => $this->host->id,
            'input' => [], 'state' => [], 'status' => OperationStatus::Waiting];
        $launch = Operation::query()->create($row);
        upgradeLaunchChildVersions(OperationKinds::ConnLaunchInspect);
        expect(fn () => Operation::query()->create($row))->toThrow(QueryException::class, 'operation.version_deprecated');
        expect(app(OperationVersionCleanup::class)->check()['eligible'])->toBeFalse();
        expect(app(DirectOperation::class)->pinnedChildVersions($launch))->toBe(OperationKinds::CoordinatorChildren[OperationKinds::ConnLaunch]);
        $child = app(StartOperationAction::class)->execute(OperationKinds::ConnLaunchInspect, 'instance', $this->instance->id,
            $this->host->id, ['launch_operation_id' => $launch->id], 'test', null, start: false, parent: $launch);
        expect($child->operation_version)->toBe(1)->and($child->parent_operation_id)->toBe($launch->id);
        $launch->update(['status' => OperationStatus::Succeeded]);
        expect(app(OperationVersionCleanup::class)->check()['eligible'])->toBeFalse();
        $child->update(['status' => OperationStatus::Succeeded]);
        expect(app(OperationVersionCleanup::class)->check()['eligible'])->toBeTrue();
    });

    it('runs the old launch inspect and dispatch handlers after their versions close', function (): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $launch = Operation::query()->findOrFail($id);
        expect($launch->child_versions)->toBe(OperationKinds::CoordinatorChildren[OperationKinds::ConnLaunch]);
        InstanceRuntime::query()->create(['instance_id' => $this->instance->id, 'node_id' => $this->host->id,
            'incus_project' => 'orbit-instances', 'container_name' => 'fixture-clone', 'bridge_address' => '10.253.0.2',
            'runtime_version' => 'fixture-runtime', 'agent_version' => 'fixture-agent', 'power_state' => InstanceRuntimePowerState::Running]);
        upgradeLaunchChildVersions(OperationKinds::ConnLaunchInspect, OperationKinds::ConnLaunchDispatch);
        $native = app(ConnLaunchNativeWork::class);
        $inspect = $native->inspect($launch, $this->instance, $this->environment, null);
        expect($inspect->parent_operation_id)->toBe($id)->and($inspect->operation_version)->toBe(1);
        $inspect->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
        app(ConnLaunchState::class)->workspaceReady($id);
        $dispatch = $native->dispatch($launch, $this->instance, 'fixture-environment', 'pinned-incarnation', 'fixture-project',
            (object) ['providerInstanceId' => 'actual-provider', 'model' => 'actual-model', 'options' => (object) []]);
        expect($dispatch->parent_operation_id)->toBe($id)->and($dispatch->operation_version)->toBe(1)
            ->and($dispatch->state['swamp_start']['workflow'])->toBe('operation-conn-launch-retained-v1');
    });

    it('adopts an ordinary completion admitted before an application Retry takes the Instance lock', function (bool $alreadyFinished): void {
        [$launch, $instance, $failed, $dispatch] = launchFixtureFailedApplication();
        $id = $launch->id;
        $state = app(ConnLaunchState::class);
        $path = "conn/launches/{$id}";
        $before = callLaunchApi($this->phone, 'GET', $path)->assertOk()->assertJsonPath('data.phase', 'ready')
            ->assertJsonPath('data.children.completion_operation_id', $failed->id)->json('data');
        $retry = ['target' => 'application'];
        $pending = callLaunchApi($this->desktop, 'POST', "{$path}/retry", $retry)->assertAccepted()
            ->assertJsonPath('data.poll_after_seconds', 2)->json('data');
        expect(callLaunchApi($this->phone, 'POST', "{$path}/retry", $retry)->assertAccepted()->json('data'))->toBe($pending);
        $launch->refresh();
        upgradeLaunchChildVersions('instance.complete');
        expect(app(OperationVersionCleanup::class)->check()['eligible'])->toBeFalse();
        $request = $launch->state['launch_retry_requests']['application'];
        $prior = $launch->state['launch_children']['completion_operation_id'];
        $started = now()->subSecond();
        $finished = now();
        $ordinary = null;
        $finishOrdinary = static function (Operation $operation) use ($instance, $started, $finished): void {
            $operation->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'started_at' => $started, 'finished_at' => $finished,
                'result' => ['ready' => true, 'instance_id' => $instance->id]]);
            $instance->update(['status' => InstanceState::Active]);
        };

        $admitting = false;
        DB::connection()->beforeStartingTransaction(function () use (&$admitting, &$ordinary, $instance, $launch, $alreadyFinished, $finishOrdinary): void {
            if ($admitting) {
                return;
            }
            // Before beginning the Retry transaction, admit ordinary completion along its normal HTTP path.
            $admitting = true;
            $key = (string) Str::uuid();
            $path = "instances/{$instance->id}/complete";
            $id = callLaunchApi($this->desktop, 'POST', $path, key: $key)->assertAccepted()->json('data.id');
            callLaunchApi($this->desktop, 'POST', $path, key: $key)->assertAccepted()->assertJsonPath('data.id', $id);
            $ordinary = Operation::query()->findOrFail($id);
            if ($alreadyFinished) {
                $finishOrdinary($ordinary);
            }
            foreach ([
                ['target_id' => $instance->id + 1],
                ['node_id' => $this->otherHost->id],
                ['target_type' => 'project'],
                ['kind' => 'instance.create'],
            ] as $unrelated) {
                $kind = $unrelated['kind'] ?? 'instance.complete';
                Operation::query()->create(['kind' => $kind, 'operation_version' => OperationVersions::admit($kind), 'node_id' => $launch->node_id,
                    'input' => [], 'target_type' => 'instance', 'target_id' => $instance->id, ...$unrelated, 'status' => OperationStatus::Succeeded, 'finished_at' => now()]);
            }
        });
        $settlement = app(ConnLaunchSettlement::class);
        $settlement->advance($launch);
        expect($ordinary)->toBeInstanceOf(Operation::class);
        $observed = callLaunchApi($this->desktop, 'GET', $path)->assertOk()
            ->assertJsonPath('data.children.completion_operation_id', $ordinary->id)
            ->assertJsonPath('data.stages.application_preview.status', $alreadyFinished ? 'succeeded' : 'pending')
            ->assertJsonPath('data.poll_after_seconds', $alreadyFinished ? null : 2)->json('data');
        expect($launch->refresh()->state['launch_children']['completion_operation_id']['owned'])->toBeFalse()
            ->and($launch->state['launch_child_history']['completion_operation_id'])->toBe([$prior])
            ->and($launch->state['launch_last_retry']['application'])->toBe($request)
            ->and($launch->state)->not->toHaveKey('launch_retry_requests.application')
            ->and($launch->state)->not->toHaveKey('launch_application_refusal')
            ->and($ordinary->refresh()->state)->not->toHaveKey('conn_launch_owner')
            ->and(callLaunchApi($this->phone, 'GET', 'conn/launches')->assertOk()->json('data'))->toBe([$observed]);

        if (! $alreadyFinished) {
            $finishOrdinary($ordinary);
        }
        $settlement->sweep();
        $ready = callLaunchApi($this->phone, 'GET', $path)->assertOk()
            ->assertJsonPath('data.children.completion_operation_id', $ordinary->id)
            ->assertJsonPath('data.stages.application_preview', ['status' => 'succeeded', 'started_at' => $started->toAtomString(),
                'finished_at' => $finished->toAtomString(), 'error' => null])
            ->assertJsonPath('data.readiness.application_preview', true)->assertJsonPath('data.poll_after_seconds', null)
            ->assertJsonPath('data.thread', $before['thread'])->assertJsonPath('data.finished_at', $before['finished_at'])
            ->assertJsonPath('data.stages.first_message', $before['stages']['first_message'])
            ->assertJsonPath('data.stages.publication', $before['stages']['publication'])->json('data');
        callLaunchApi($this->desktop, 'POST', "{$path}/retry", $retry)->assertConflict()
            ->assertJsonPath('error.code', 'conn.launch_retry_unavailable');
        expect(callLaunchApi($this->desktop, 'GET', 'conn/launches')->assertOk()->json('data'))->toBe([$ready])
            ->and(ConnLaunchChild::completions()->where('target_id', $instance->id)->where('node_id', $instance->node_id)
                ->orderBy('id')->pluck('id')->all())->toBe([$failed->id, $ordinary->id])
            ->and(Operation::query()->where('kind', ConnLaunchDispatchSource::Kind)->pluck('id')->all())->toBe([$dispatch->id]);
        expect($ordinary->operation_version)->toBe(2)->and($ordinary->parent_operation_id)->toBeNull();
    })->with(['still open' => false, 'already terminal' => true]);

    it('settles the first ordinary completion on a ready existing launch', function (bool $recordedInstance): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $path = "conn/launches/{$id}";
        $this->instance->update(['status' => InstanceState::SourceResolved]);
        $state = app(ConnLaunchState::class);
        $state->applicationNotRequired($id);
        $dispatch = launchFixtureDispatch($id);
        $state->accepted($id, $dispatch->id, now());
        $state->published($id);

        $launch = Operation::query()->findOrFail($id);
        expect($launch->input['instance_id'])->toBe($this->instance->id)
            ->and($launch->state)->not->toHaveKey('launch_instance_id');
        if ($recordedInstance) {
            $launch->update(['state' => [...$launch->state, 'launch_instance_id' => $this->instance->id]]);
        }
        $before = callLaunchApi($this->phone, 'GET', $path)->assertOk()
            ->assertJsonPath('data.phase', 'ready')->assertJsonPath('data.children.completion_operation_id', null)
            ->assertJsonPath('data.stages.application_preview.status', 'skipped')->json('data');

        $actor = 'node:'.$this->desktop->id;
        $success = ['status' => OperationStatus::Succeeded, 'started_at' => now()->subSeconds(3), 'finished_at' => now(),
            'result' => ['ready' => true, 'instance_id' => $this->instance->id]];
        foreach ([
            ['target_id' => $this->instance->id + 1],
            ['node_id' => $this->otherHost->id],
            ['target_type' => 'project'],
            ['kind' => 'instance.create'],
        ] as $outsideScope) {
            launchNativeReceipt($launch, 'instance.complete', actor: $actor)->update([...$success, ...$outsideScope]);
        }
        $settlement = app(ConnLaunchSettlement::class);
        $settlement->sweep();
        expect(callLaunchApi($this->desktop, 'GET', 'conn/launches')->assertOk()->json('data'))->toBe([$before]);

        $completed = launchNativeReceipt($launch, 'instance.complete', actor: $actor);
        $completed->update($success);
        $this->instance->update(['status' => InstanceState::Active]);
        $settlement->sweep();
        $after = callLaunchApi($this->desktop, 'GET', $path)->assertOk()
            ->assertJsonPath('data.instance_id', $this->instance->id)->assertJsonPath('data.phase', 'ready')
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.finished_at', $before['finished_at'])
            ->assertJsonPath('data.children.completion_operation_id', $completed->id)
            ->assertJsonPath('data.stages.application_preview', ['status' => 'succeeded', 'started_at' => $completed->started_at->toAtomString(),
                'finished_at' => $completed->finished_at->toAtomString(), 'error' => null])
            ->assertJsonPath('data.readiness.application_preview', true)->assertJsonPath('data.poll_after_seconds', null)
            ->assertJsonPath('data.thread', $before['thread'])->assertJsonPath('data.stages.first_message', $before['stages']['first_message'])
            ->assertJsonPath('data.stages.publication', $before['stages']['publication'])->json('data');

        $settlement->sweep();
        expect(callLaunchApi($this->phone, 'GET', 'conn/launches')->assertOk()->json('data'))->toBe([$after])
            ->and($launch->refresh()->state['launch_children']['completion_operation_id']['owned'])->toBeFalse()
            ->and($launch->state)->not->toHaveKey('launch_child_history')
            ->and($launch->state)->not->toHaveKey('launch_last_retry')
            ->and($completed->refresh()->state)->not->toHaveKey('conn_launch_owner')
            ->and(Operation::query()->where('kind', ConnLaunchDispatchSource::Kind)->pluck('id')->all())->toBe([$dispatch->id]);
    })->with(['from admission' => false, 'with a recorded Instance' => true]);

    it('settles an external completion on the same ready launch without a Conn retry', function (): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $path = "conn/launches/{$id}";
        $this->instance->update(['status' => InstanceState::SourceResolved]);
        $dispatch = launchFixtureDispatch($id);
        $state = app(ConnLaunchState::class);
        $state->accepted($id, $dispatch->id, now());
        $state->published($id);
        $launch = Operation::query()->findOrFail($id);
        $actor = 'node:'.$this->desktop->id;
        $failed = launchNativeReceipt($launch, 'instance.complete', actor: $actor);
        $failed->update(['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'instance.container_process_inactive',
            'result' => ['ready' => false]]);
        $settlement = app(ConnLaunchSettlement::class);
        $settlement->advance($launch);
        $before = callLaunchApi($this->desktop, 'GET', $path)->assertOk()
            ->assertJsonPath('data.phase', 'ready')->assertJsonPath('data.children.completion_operation_id', $failed->id)
            ->assertJsonPath('data.readiness.application_preview', false)->json('data');

        $started = now()->subSeconds(3);
        $completed = launchNativeReceipt($launch, 'instance.complete', actor: $actor);
        $success = ['status' => OperationStatus::Succeeded, 'started_at' => $started, 'finished_at' => now(),
            'result' => ['ready' => true, 'instance_id' => $this->instance->id]];
        $completed->update($success);
        $this->instance->update(['status' => InstanceState::Active]);
        foreach ([
            ['kind' => 'instance.create'],
            ['target_type' => 'project'],
            ['target_id' => $this->instance->id + 1],
            ['node_id' => $this->otherHost->id],
        ] as $otherTarget) {
            launchNativeReceipt($launch, 'instance.complete', actor: $actor)->update([...$success, ...$otherTarget]);
        }

        $settlement->sweep();
        $after = callLaunchApi($this->desktop, 'GET', $path)->assertOk()
            ->assertJsonPath('data.id', $id)->assertJsonPath('data.instance_id', $this->instance->id)
            ->assertJsonPath('data.phase', 'ready')->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.children.completion_operation_id', $completed->id)
            ->assertJsonPath('data.stages.application_preview.status', 'succeeded')
            ->assertJsonPath('data.stages.application_preview.started_at', $started->toAtomString())
            ->assertJsonPath('data.stages.application_preview.finished_at', $completed->finished_at->toAtomString())
            ->assertJsonPath('data.stages.application_preview.error', null)->assertJsonPath('data.readiness.application_preview', true)
            ->assertJsonPath('data.actions.retry_application.available', false)->assertJsonPath('data.poll_after_seconds', null)
            ->assertJsonPath('data.thread', $before['thread'])->assertJsonPath('data.finished_at', $before['finished_at'])
            ->json('data');
        $settlement->sweep();
        expect(callLaunchApi($this->phone, 'GET', $path)->assertOk()->json('data'))->toBe($after)
            ->and(callLaunchApi($this->desktop, 'GET', 'conn/launches')->assertOk()->json('data'))->toBe([$after])
            ->and($launch->refresh()->state['launch_children']['completion_operation_id']['owned'])->toBeFalse()
            ->and(array_column($launch->state['launch_child_history']['completion_operation_id'], 'id'))->toBe([$failed->id])
            ->and($launch->state)->not->toHaveKey('launch_last_retry')
            ->and($completed->refresh()->state)->not->toHaveKey('conn_launch_owner')
            ->and(Operation::query()->where('kind', ConnLaunchDispatchSource::Kind)->count())->toBe(1);
    });

    it('keeps application progress and retries independent of a published thread', function (): void {
        $id = callLaunchApi($this->phone, 'POST', 'conn/launches', $this->body, $this->key)->assertAccepted()->json('data.id');
        $this->instance->update(['status' => InstanceState::SourceResolved]);
        InstanceRuntime::query()->create(['instance_id' => $this->instance->id, 'node_id' => $this->host->id,
            'incus_project' => 'orbit-instances', 'container_name' => 'fixture-clone', 'bridge_address' => '10.253.0.2',
            'runtime_version' => 'fixture-runtime', 'agent_version' => 'fixture-agent', 'power_state' => InstanceRuntimePowerState::Running]);
        $definition = ProcessDefinition::query()->create(['project_id' => $this->project->id, 'name' => 'fixture-worker',
            'environments' => ['development'], 'spec' => ['runtime' => 'systemd', 'command' => ['/bin/false']]]);
        app(InstantiateProjectRuntimeDefinitionsAction::class)->captureForDevelopment($this->instance);
        $captured = app(ContainerProcesses::class)->units($this->instance);
        $dispatch = launchFixtureDispatch($id);
        $state = app(ConnLaunchState::class);
        $state->accepted($id, $dispatch->id, now());
        $state->published($id);
        $completion = $state->admitChild($id, ConnLaunchChild::Completion, 'fixture-complete', static fn (Operation $launch): array => [
            'operation' => launchNativeReceipt($launch, 'instance.complete', 'fixture-complete', ['direct_provisioning' => ['container' => ['processes' => $captured]]]),
            'owned' => true,
        ]);
        $started = now()->subSeconds(3);
        $finished = now();
        $completion->update(['status' => OperationStatus::Running, 'started_at' => $started]);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'ready')
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.children.completion_operation_id', $completion->id)
            ->assertJsonPath('data.stages.application_preview.status', 'running')->assertJsonPath('data.poll_after_seconds', 2);

        $completion->update(['status' => OperationStatus::Failed, 'finished_at' => $finished, 'error_code' => 'instance.container_process_inactive',
            'result' => ['ready' => false, 'recovery' => InstanceCompleteProjection::recovery($this->instance, true), 'detail' => 'fixture-private-diagnostic',
                'process_failure' => ['process_id' => $captured[0]['id']]]]);
        launchNativeReceipt(Operation::query()->findOrFail($id), 'instance.complete')->update(['status' => OperationStatus::Succeeded, 'result' => ['ready' => true]]);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'ready')
            ->assertJsonPath('data.readiness.first_message', true)->assertJsonPath('data.readiness.application_preview', false)
            ->assertJsonPath('data.stages.application_preview.started_at', $started->toAtomString())
            ->assertJsonPath('data.stages.application_preview.finished_at', $finished->toAtomString())
            ->assertJsonPath('data.actions.retry_application.requires', 'project_process_definition')->assertDontSee('fixture-private-diagnostic');
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/retry", ['target' => 'application'])->assertConflict()
            ->assertJsonPath('error.details.requires', 'project_process_definition');

        $definition->update(['spec' => ['runtime' => 'systemd', 'command' => ['/bin/sleep', '3600']]]);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.actions.retry_application.available', true)
            ->assertJsonPath('data.actions.retry_application.requires', null);
        expect(app(ContainerProcesses::class)->units($this->instance))->toBe($captured);
        app(InstantiateProjectRuntimeDefinitionsAction::class)->refreshForContainer($this->instance);
        expect(app(ContainerProcesses::class)->units($this->instance))->not->toBe($captured);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.actions.retry_application.available', true)
            ->assertJsonPath('data.actions.retry_application.requires', null);
        callLaunchApi($this->desktop, 'POST', "conn/launches/{$id}/retry", ['target' => 'application'])->assertAccepted()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.phase', 'ready')->assertJsonPath('data.poll_after_seconds', 2);
        $replacement = $state->admitChild($id, ConnLaunchChild::Completion, 'fixture-complete-retry', function (Operation $launch): array {
            expect($launch->state['launch_last_retry']['application']['node_id'])->toBe($this->desktop->id);

            return ['operation' => launchNativeReceipt($launch, 'instance.complete', 'fixture-complete-retry'), 'owned' => true];
        });
        $replacement->update(['status' => OperationStatus::Succeeded, 'started_at' => $started, 'finished_at' => now(), 'result' => ['ready' => true]]);
        callLaunchApi($this->desktop, 'GET', "conn/launches/{$id}")->assertOk()->assertJsonPath('data.phase', 'ready')
            ->assertJsonPath('data.children.completion_operation_id', $replacement->id)->assertJsonPath('data.readiness.application_preview', true)
            ->assertJsonPath('data.stages.application_preview.status', 'succeeded')->assertJsonPath('data.poll_after_seconds', null);
        expect($replacement->triggered_by)->toBe('node:'.$this->phone->id)->and(Operation::query()->where('kind', ConnLaunchDispatchSource::Kind)->count())->toBe(1);
    });
});
