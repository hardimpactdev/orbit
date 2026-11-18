<?php

declare(strict_types=1);

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\InstanceCompleteProjection;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationSecrets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProcessDefinition;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitting;
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
function callLaunchApi(?Node $caller, string $method, string $path, array $body = [], ?string $key = null): TestResponse
{
    $client = test()->withServerVariables(['REMOTE_ADDR' => $caller?->wireguard_ip ?? '192.0.2.1']);
    if ($method === 'POST' && $body === [] && str_ends_with($path, '/cancel')) {
        return $client->post('/api/v1/'.$path, [], ['Accept' => 'application/json']);
    }

    return $client->json($method, '/api/v1/'.$path, $body, $key === null ? [] : ['Idempotency-Key' => $key]);
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

function launchFixtureDispatch(int $id): Operation
{
    $state = app(ConnLaunchState::class);
    $state->workspaceReady($id);

    return $state->admitDispatch($id, 'fixture-environment', 'pinned-incarnation', 'fixture-t3-project',
        (object) ['providerInstanceId' => 'actual-provider', 'model' => 'actual-model', 'options' => (object) []],
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
