<?php

declare(strict_types=1);

use App\Domain\Instances\ContainerServer;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceServerSwitch;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Power\AgentActivity;
use App\Domain\Instances\Power\InstanceAgentActivity;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

/**
 * Sets up a gateway peer, an app-dev Node, a Project and a container Instance with its runtime and optional Conn environment.
 *
 * @return array{node: Node, project: Project, instance: Instance, runtime: InstanceRuntime, environment: ConnEnvironment|null}
 */
function serverSwitchFixture(
    ?ContainerServer $server = ContainerServer::T3,
    InstanceRuntimePowerState $powerState = InstanceRuntimePowerState::Running,
    bool $withEnvironment = true,
): array {
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);

    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'feature',
        'checkout_path' => '/test/feature', 'status' => InstanceState::Active, 'first_setup_pending' => false,
    ]);
    $runtime = InstanceRuntime::query()->create([
        'instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
        'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.3',
        't3_port' => $server === null ? null : 3800,
        'container_server' => $server,
        'runtime_version' => 'r1', 'agent_version' => 'a1',
        'power_state' => $powerState,
    ]);
    $environment = null;
    if ($withEnvironment && $server !== null) {
        $environment = ConnEnvironment::query()->forceCreate([
            'environment_id' => 'env-'.$instance->id,
            'instance_id' => $instance->id,
            'node_id' => $node->id,
            'label' => $project->slug.' / '.$instance->name,
            'url' => 'http://'.$node->wireguard_ip.':3800',
            'server_version' => $server === ContainerServer::T3 ? '0.1.7' : '0.1.10',
            'admin_session' => 'secret-admin-token',
            'admin_session_expires_at' => now()->addDays(20),
            'registered_at' => now(),
            'cleanup_version' => 1,
            'cleanup_completed_version' => 1,
        ]);
    }

    return compact('node', 'project', 'instance', 'runtime', 'environment');
}

function bindAgentActivity(AgentActivity $activity): void
{
    $fake = new class($activity) implements InstanceAgentActivity
    {
        public function __construct(private readonly AgentActivity $activity) {}

        public function read(Instance $instance): AgentActivity
        {
            return $this->activity;
        }
    };
    app()->tag([$fake::class], InstanceAgentActivity::class);
    app()->instance($fake::class, $fake);
}

/** @param array<string, mixed> $result */
function switchRun(Operation $operation, string $status, array $result = []): Operation
{
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'switch-proof']])->save();

    return app(DirectOperation::class)->projection($operation)->apply($operation->refresh(), [
        'status' => $status, 'run_id' => 'switch-proof',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => $result,
    ]);
}

describe('switching a container server to conn', function (): void {
    it('switches a running t3 runtime to conn, updates its Conn environment version, and keeps its address', function (): void {
        ['instance' => $instance, 'runtime' => $runtime, 'environment' => $environment] = serverSwitchFixture(ContainerServer::T3, InstanceRuntimePowerState::Running);
        bindAgentActivity(AgentActivity::read(false, now()->subMinutes(5)->getTimestamp()));
        Queue::fake([StartAdmittedSwampRun::class]);

        $response = $this->postJson('/api/v1/instances/'.$instance->id.'/server-switch')
            ->assertAccepted()
            ->assertJsonPath('data.kind', InstanceServerSwitch::Kind)
            ->assertJsonPath('data.status', 'queued');

        $operation = Operation::query()->findOrFail($response->json('data.id'));
        Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->operationId === $operation->id);
        expect($operation->input['program'])->toBe('/opt/orbit-agent/current/agent/bin/conn-server')
            ->and($operation->input['drop_in']['path'])->toBe(InstanceContainer::ServerDropIn)
            ->and($operation->input['drop_in']['content'])->toContain('ExecStart=/opt/orbit-agent/current/agent/bin/conn-server serve')
            ->and($runtime->refresh()->container_server)->toBe(ContainerServer::T3);

        $done = switchRun($operation, 'succeeded', ['switch' => 'switched', 'container_server' => 'conn', 'server_version' => '0.1.10']);

        expect($done->status)->toBe(OperationStatus::Succeeded)
            ->and($done->result)->toBe(['container_server' => 'conn', 'switch' => 'switched', 'server_version' => '0.1.10'])
            ->and($runtime->refresh()->container_server)->toBe(ContainerServer::Conn)
            ->and($environment->refresh()->server_version)->toBe('0.1.10')
            ->and($environment->url)->toBe('http://10.44.0.7:3800');
    });

    it('switches a stopped t3 runtime at its next wake, carrying the conn drop-in and updating server_version on wake', function (): void {
        ['instance' => $instance, 'runtime' => $runtime, 'environment' => $environment] = serverSwitchFixture(ContainerServer::T3, InstanceRuntimePowerState::Stopped);
        Queue::fake([StartAdmittedSwampRun::class]);

        $response = $this->postJson('/api/v1/instances/'.$instance->id.'/server-switch')
            ->assertAccepted()
            ->assertJsonPath('data.kind', InstanceServerSwitch::Kind)
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.result.container_server', 'conn')
            ->assertJsonPath('data.result.switch', 'deferred');

        Queue::assertNotPushed(StartAdmittedSwampRun::class);
        expect($runtime->refresh()->container_server)->toBe(ContainerServer::Conn)
            ->and($environment->refresh()->server_version)->toBe('0.1.7')
            ->and(InstanceServerSwitch::pendingForWake($instance))->toBeTrue();

        $wakeResponse = $this->postJson('/api/v1/instances/'.$instance->id.'/wake')
            ->assertAccepted()
            ->assertJsonPath('data.kind', InstanceWake::Kind);

        $wake = Operation::query()->findOrFail($wakeResponse->json('data.id'));
        Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->operationId === $wake->id);
        $dropIn = collect($wake->input['container']['clone']['files'])->firstWhere('path', InstanceContainer::ServerDropIn);
        expect($dropIn['content'])->toContain('ExecStart=/opt/orbit-agent/current/agent/bin/conn-server serve');

        switchRun($wake, 'succeeded', ['wake' => 'started', 'marker_found' => false, 'server_version' => '0.1.10']);

        expect($environment->refresh()->server_version)->toBe('0.1.10')
            ->and(InstanceServerSwitch::pendingForWake($instance))->toBeFalse();
    });

    it('refuses a conn runtime and a serverless runtime, and keeps conn to t3 aborted at the database trigger', function (): void {
        ['instance' => $connInstance, 'runtime' => $connRuntime] = serverSwitchFixture(ContainerServer::Conn, InstanceRuntimePowerState::Running);

        $this->postJson('/api/v1/instances/'.$connInstance->id.'/server-switch')
            ->assertConflict()
            ->assertJsonPath('error.code', 'instance.container_server_already_conn');

        expect(fn () => DB::table('instance_runtimes')->where('id', $connRuntime->id)->update(['container_server' => 't3']))
            ->toThrow(QueryException::class);

        $serverless = Instance::query()->create([
            'project_id' => $connInstance->project_id, 'node_id' => $connInstance->node_id, 'name' => 'default',
            'checkout_path' => '/test/default', 'status' => InstanceState::Active, 'first_setup_pending' => false,
        ]);
        $defaultRuntime = InstanceRuntime::query()->create([
            'instance_id' => $serverless->id, 'node_id' => $connInstance->node_id, 'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$serverless->id, 'bridge_address' => '10.253.0.4',
            't3_port' => null, 'container_server' => null,
            'runtime_version' => 'r1', 'agent_version' => 'a1',
            'power_state' => InstanceRuntimePowerState::Running,
        ]);

        $this->postJson('/api/v1/instances/'.$serverless->id.'/server-switch')
            ->assertConflict()
            ->assertJsonPath('error.code', 'instance.container_server_none');

        expect(fn () => DB::table('instance_runtimes')->where('id', $defaultRuntime->id)->update(['container_server' => 'conn']))
            ->toThrow(QueryException::class);
    });

    it('refuses a busy running container with a typed error and leaves it on t3', function (AgentActivity $activity, string $expectedCode): void {
        ['instance' => $instance, 'runtime' => $runtime] = serverSwitchFixture(ContainerServer::T3, InstanceRuntimePowerState::Running);
        bindAgentActivity($activity);

        $this->postJson('/api/v1/instances/'.$instance->id.'/server-switch')
            ->assertConflict()
            ->assertJsonPath('error.code', $expectedCode);

        expect($runtime->refresh()->container_server)->toBe(ContainerServer::T3)
            ->and(Operation::query()->where('kind', InstanceServerSwitch::Kind)->count())->toBe(0);
    })->with([
        'running turn' => [AgentActivity::read(true, now()->getTimestamp()), 'instance.container_server_busy'],
        'pending runtime request' => [AgentActivity::read(false, now()->getTimestamp(), pendingRequest: true), 'instance.container_server_busy'],
        'unreadable T3 server' => [AgentActivity::unreadable(), 'instance.t3_unreadable'],
    ]);

    it('keeps a t3 runtime on t3 when the switch run fails as busy and lets a later switch succeed', function (): void {
        ['instance' => $instance, 'runtime' => $runtime] = serverSwitchFixture(ContainerServer::T3, InstanceRuntimePowerState::Running);
        bindAgentActivity(AgentActivity::read(false, now()->subMinutes(5)->getTimestamp()));
        Queue::fake([StartAdmittedSwampRun::class]);

        $operation = Operation::query()->findOrFail(
            $this->postJson('/api/v1/instances/'.$instance->id.'/server-switch')->assertAccepted()->json('data.id'),
        );

        $failed = switchRun($operation, 'failed', ['error_code' => 'instance.container_server_busy']);

        expect($failed->status)->toBe(OperationStatus::Failed)
            ->and($failed->error_code)->toBe('instance.container_server_busy')
            ->and($runtime->refresh()->container_server)->toBe(ContainerServer::T3);
    });

    it('sets the projects.container_server column default to conn while preserving existing t3 projects', function (): void {
        $project = Project::query()->create([
            'name' => 'legacy', 'slug' => 'legacy', 'repository_url' => 'https://github.com/example/legacy.git',
            'default_branch' => 'main', 'root' => 'public', 'container_server' => ContainerServer::T3,
        ]);

        $column = collect(DB::select('PRAGMA table_info(projects)'))->firstWhere('name', 'container_server');
        expect($column)->not->toBeNull()
            ->and((string) $column->dflt_value)->toContain('conn')
            ->and($project->refresh()->container_server)->toBe(ContainerServer::T3);
    });
});
