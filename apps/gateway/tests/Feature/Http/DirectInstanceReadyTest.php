<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\Instances\ContainerWorkspaceRead;
use App\Jobs\RecordWorkspaceBaseline;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../Domain/Instances/ContainerCloneHelpers.php';

const READY_RUN = '4f0c8a52-2d7e-4b8e-9c55-1f2b8e4d6a10';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $this->swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
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
    $this->node = containerDefaultNode();
    $this->default = seededDefault($this->node);
    $this->vault = vaultReadingSocket(['nckrtl-instance-t3-model-register-session-gateway-1-token' => 'gateway-session-value']);
    $this->reader = workspaceReaderNode(readyBaseline());
});

/** A clone's create whose run is bound and still works. */
function readyClone(): Operation
{
    $operation = containerCreate(test()->default->project, test()->node, 'feature');
    $operation->forceFill(['status' => OperationStatus::Running, 'started_at' => now(), 'state' => ['swamp_run_id' => READY_RUN]])->save();

    return $operation->refresh();
}

/** @param array<string, mixed>|null $body */
function readyCall(Node $caller, Operation $operation, ?array $body = null): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->postJson("/api/v1/direct-operations/{$operation->id}/instance-ready",
        $body ?? ['run_id' => READY_RUN, 't3' => cloneSucceeded($operation)['result']['t3']]);
}

/** The Node that started the create and follows it, with access to the clone's Node. */
function readyFollower(Operation $operation): Node
{
    $follower = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'laptop', 'status' => 'active',
        'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit']));
    $follower->accessibleNodes()->attach($operation->node_id);
    $operation->forceFill(['triggered_by' => 'node:'.$follower->id])->save();

    return $follower;
}

it('records the T3 server, the running runtime, the workspace baseline and ready_at of a clone whose run still works', function (): void {
    $operation = readyClone();
    $follower = readyFollower($operation);
    $read = fn (string $path): TestResponse => $this->withServerVariables(['REMOTE_ADDR' => $follower->wireguard_ip])->getJson($path)->assertOk();
    $read("/api/v1/operations/{$operation->id}")->assertJsonPath('data.ready_at', null);
    $read("/api/v1/conn/operations/{$operation->id}")->assertJsonPath('data.ready_at', null);
    InstanceRuntime::query()->where('instance_id', $operation->target_id)->update(['power_state' => InstanceRuntimePowerState::Stopped]);

    $readyAt = readyCall($this->swamp, $operation)->assertOk()->json('data.ready_at');

    $environment = ConnEnvironment::query()->where('environment_id', 'env-feature')->sole();
    $runtime = InstanceRuntime::query()->where('instance_id', $operation->target_id)->sole();
    expect($readyAt)->toBeString()
        ->and($environment->instance_id)->toBe($operation->target_id)
        ->and($environment->admin_session)->toBe('gateway-session-value')
        ->and($environment->cleanup_version)->toBe($environment->cleanup_completed_version)
        ->and($runtime->power_state)->toBe(InstanceRuntimePowerState::Running)
        ->and($runtime->workspace_baseline)->toBe(readyBaseline())
        ->and($runtime->workspace_baseline_at)->not->toBeNull()
        ->and($operation->refresh()->state['ready_at'])->toBe($readyAt)
        ->and($operation->status)->toBe(OperationStatus::Running)
        ->and($this->reader->calls)->toBe(1);
    $read("/api/v1/operations/{$operation->id}")->assertJsonPath('data.ready_at', $readyAt);
    $read("/api/v1/conn/operations/{$operation->id}")->assertJsonPath('data.ready_at', $readyAt);
    $read('/api/v1/operations?kind=instance.create')->assertJsonPath('data.0.ready_at', $readyAt);
});

it('changes nothing on a repeat call and answers the same time', function (): void {
    $operation = readyClone();
    $first = readyCall($this->swamp, $operation)->assertOk()->json('data.ready_at');
    $environment = ConnEnvironment::query()->sole()->getAttributes();
    $runtime = InstanceRuntime::query()->where('instance_id', $operation->target_id)->sole()->getAttributes();
    $sequence = $operation->refresh()->sequence;

    readyCall($this->node, $operation)->assertOk()->assertJsonPath('data.ready_at', $first);

    expect(ConnEnvironment::query()->sole()->getAttributes())->toBe($environment)
        ->and(InstanceRuntime::query()->where('instance_id', $operation->target_id)->sole()->getAttributes())->toBe($runtime)
        ->and($operation->refresh()->sequence)->toBe($sequence)
        ->and($this->reader->calls)->toBe(1);
});

it('keeps a recorded baseline, and the queued baseline job after the end records nothing over the mark', function (): void {
    $operation = readyClone();
    readyCall($this->swamp, $operation)->assertOk();
    $this->reader->answer = ['heads' => [str_repeat('b', 40)], 'stashes' => [], 'untracked' => []];

    new RecordWorkspaceBaseline($operation->target_id)->handle(app(ContainerWorkspaceRead::class));

    expect(InstanceRuntime::query()->where('instance_id', $operation->target_id)->sole()->workspace_baseline)->toBe(readyBaseline())
        ->and($this->reader->calls)->toBe(1);
});

it('records the mark without a baseline when the workspace cannot be read', function (): void {
    $operation = readyClone();
    $this->reader->answer = null;

    readyCall($this->swamp, $operation)->assertOk();

    expect(InstanceRuntime::query()->where('instance_id', $operation->target_id)->sole()->workspace_baseline)->toBeNull()
        ->and(ConnEnvironment::query()->count())->toBe(1)
        ->and($operation->refresh()->state['ready_at'])->toBeString();
});

it('refuses another Node, another or a missing run, other fields and a t3 that proves no server', function (): void {
    $operation = readyClone();
    $stranger = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'stranger', 'status' => 'active',
        'platform' => 'linux', 'wireguard_ip' => '10.44.0.99', 'public_ssh_host' => '10.44.0.99', 'user' => 'orbit']));
    $t3 = cloneSucceeded($operation)['result']['t3'];

    readyCall($stranger, $operation)->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
    readyCall($this->swamp, $operation, ['run_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 't3' => $t3])->assertStatus(409)->assertJsonPath('error.code', 'operation.run_mismatch');
    readyCall($this->swamp, $operation, ['t3' => $t3])->assertUnprocessable();
    readyCall($this->swamp, $operation, ['run_id' => READY_RUN])->assertUnprocessable();
    readyCall($this->swamp, $operation, ['run_id' => READY_RUN, 't3' => $t3, 'ready_at' => '2026-01-01T00:00:00Z'])->assertUnprocessable();
    readyCall($this->swamp, $operation, ['run_id' => READY_RUN, 't3' => [...$t3, 'environment_id' => null]])->assertJsonPath('error.code', 'instance.result_invalid');

    expect(ConnEnvironment::query()->count())->toBe(0)
        ->and($operation->refresh()->state['ready_at'] ?? null)->toBeNull()
        ->and($this->reader->calls)->toBe(0);
});

it('refuses an Operation that is no clone create, and one that has ended', function (): void {
    $default = Operation::query()->where('kind', 'instance.create')->where('target_id', $this->default->id)->sole();
    $default->forceFill(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => READY_RUN]])->save();
    $route = Operation::query()->create(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => 1, 'node_id' => $this->node->id,
        'input' => [], 'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => READY_RUN], 'sequence' => 1, 'attempts' => 1]);
    $ended = readyClone();
    $ended->forceFill(['status' => OperationStatus::Succeeded, 'finished_at' => now()])->save();

    readyCall($this->swamp, $default)->assertForbidden()->assertJsonPath('error.code', 'operation.ready_forbidden');
    readyCall($this->swamp, $route)->assertForbidden()->assertJsonPath('error.code', 'operation.ready_forbidden');
    readyCall($this->swamp, $ended)->assertStatus(409)->assertJsonPath('error.code', 'operation.finished');

    expect(ConnEnvironment::query()->count())->toBe(0)
        ->and($this->reader->calls)->toBe(0);
});
