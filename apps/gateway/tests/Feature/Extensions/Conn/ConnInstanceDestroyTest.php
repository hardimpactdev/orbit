<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

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

        private array $last = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->messages[] = $this->last = $message;
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
        'database_connection_targets', 'conn_environments', 'conn_pairings', 'instance_removals', 'instance_removal_members'] as $table) {
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

it('refuses unknown work without touching Schedules Routes databases pairings or any Operation', function (): void {
    $instance = connRemovalClone();
    connRemovalResources($instance);
    $before = connRemovalStored();
    $messages = $this->socket->messages;
    connRemovalCall($instance->id, ['acknowledge_unsaved' => false])->assertConflict()
        ->assertJsonPath('error.code', 'instance.unsaved_work')
        ->assertJsonPath('error.details', ['uncommitted' => null, 'unpushed' => null, 'state' => 'unknown']);
    expect(connRemovalStored())->toBe($before)->and($this->socket->messages)->toBe($messages);
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
