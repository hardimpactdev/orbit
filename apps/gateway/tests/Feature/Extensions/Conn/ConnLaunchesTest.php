<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationKinds;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Actions\BindConnNodeProfileAction;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnLaunchIntent;
use Orbit\Extensions\Conn\Models\ConnProfile;

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
    return test()->withServerVariables(['REMOTE_ADDR' => $caller?->wireguard_ip ?? '192.0.2.1'])->json($method, '/api/v1/'.$path,
        $body, $key === null ? [] : ['Idempotency-Key' => $key]);
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
        $default = Instance::query()->findOrFail(containerCreate($this->project, $this->host)->target_id);
        $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
        InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
            ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);
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

        callLaunchApi($this->phone, 'POST', 'conn/launches', $body, $this->key)->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => [$field]]])->assertJsonMissingPath('data');
        expect(launchRecords())->toBe($baseline);
    })->with([
        'missing profile' => ['expected_profile_id', null],
        'missing Node' => ['expected_node_id', null],
        'zero profile' => ['expected_profile_id', 0],
        'negative Node' => ['expected_node_id', -1],
        'string profile' => ['expected_profile_id', '1'],
        'fractional Node' => ['expected_node_id', 1.5],
    ]);

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
});
