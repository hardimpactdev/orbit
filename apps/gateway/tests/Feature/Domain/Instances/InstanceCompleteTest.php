<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\WaitForGatewayReleaseIdleAction;
use App\Actions\Instances\StartInstanceCompleteAction;
use App\Actions\Operations\CancelOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerDestroyExecutionCopies;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceCompleteProjection;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationBudgetTable;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
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
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('root-certificate');
    app()->instance(WebSocketClient::class, $this->completionVault = completionVault());
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);
});

/**
 * A routed clone between its two Operations, as a create that ends at registration leaves it: `source_resolved` with its starting
 * commit, its container running and its T3 server registered; its create succeeded.
 *
 * @return array{0: Instance, 1: Operation}
 */
function clonePendingCompletion(bool $registered = true): array
{
    $node = containerDefaultNode();
    $node->update(['tld' => 'beast.test']);
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $seed = containerCreate($project, $node);
    $seed->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'seed-run']])->save();
    app(DevelopmentInstanceCreateProjection::class)->apply($seed, ['status' => 'succeeded', 'run_id' => 'seed-run', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['instance_id' => $seed->target_id, 'ready' => true, 'commit' => str_repeat('c', 40)]]);
    $create = containerCreate($project, $node, 'feature');
    $create->forceFill(['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null])->save();
    $instance = Instance::query()->findOrFail($create->target_id);
    $instance->update(['status' => InstanceState::SourceResolved, 'starting_commit' => str_repeat('a', 40), 'provisioning_step' => 'php-selected']);
    InstanceRuntime::query()->where('instance_id', $instance->id)->firstOrFail()->update(['power_state' => InstanceRuntimePowerState::Running]);
    if ($registered) {
        ConnEnvironment::query()->forceCreate(['environment_id' => 'env-feature', 'label' => 'tool / feature', 'url' => 'http://10.44.0.7:3800',
            'node_id' => $node->id, 'instance_id' => $instance->id, 'admin_session' => 'session', 'admin_session_expires_at' => now()->addDays(20),
            'registered_at' => now()]);
    }

    return [$instance->refresh(), $create];
}

/** Admits the completion and binds a run to it, as the native start would. */
function runningCompletion(Instance $instance): Operation
{
    $operation = app(StartInstanceCompleteAction::class)->execute($instance);
    $operation->forceFill(['status' => 'running', 'state' => [...($operation->state ?? []), 'swamp_run_id' => 'complete-run']])->save();

    return $operation->refresh();
}

/** @param array<string, mixed> $result */
function completionEnds(Operation $operation, string $status, array $result, ?string $code = null): Operation
{
    return app(InstanceCompleteProjection::class)->apply($operation, ['status' => $status, 'run_id' => 'complete-run', 'error_code' => $code,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['instance_id' => $operation->target_id, ...$result]]);
}

/** An active routed clone needs its active Route first (`instances_active_route_update`). */
function completedClone(Instance $instance): void
{
    Route::query()->whereHas('targets', fn ($query) => $query->where('instance_id', $instance->id))->update(['status' => 'active']);
    $instance->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
}

function completionRefusal(Instance $instance): string
{
    try {
        app(StartInstanceCompleteAction::class)->execute($instance);
    } catch (ResourceOperationException $exception) {
        return $exception->errorCode;
    }

    return 'admitted';
}

/** A vault socket that answers every put and records each key and value. */
function completionVault(): object
{
    return new class implements WebSocketClient
    {
        /** @var list<array{type: string, key: string, value: string}> */
        public array $puts = [];

        /** @var list<array<string, mixed>> */
        private array $frames = [];

        private bool $open = false;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);
            $this->puts[] = ['type' => $frame['type'], 'key' => $frame['payload']['key'], 'value' => (string) ($frame['payload']['value'] ?? '')];

            return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
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
}

describe('admission', function (): void {
    it('admits a registered source_resolved clone with the Instance lock, its Processes and a Route capture', function (): void {
        [$instance, $create] = clonePendingCompletion();

        $operation = app(StartInstanceCompleteAction::class)->execute($instance);

        expect($operation->kind)->toBe('instance.complete')
            ->and($operation->lock_key)->toBe(OperationStore::lockKey('instance', $instance->id))
            ->and($operation->input['create_operation_id'])->toBe($create->id)
            ->and($operation->input['route_id'])->toBe($create->input['route_id'])
            ->and($operation->input)->toHaveKeys(['route_create', 'route_lifecycle', 'execution_secrets'])
            ->and($operation->input['direct_provisioning']['container']['clone'])->toBe($create->input['direct_provisioning']['container']['clone'])
            ->and(RoutePublicationHolds::nodes($operation))->not->toBe([]);
    });

    it('refuses an active clone, a default, a clone with no registered T3 server, and a held Instance lock', function (): void {
        [$instance] = clonePendingCompletion();
        completedClone($instance);
        expect(completionRefusal($instance))->toBe('instance.complete_unavailable');
        $instance->update(['status' => InstanceState::SourceResolved]);
        Route::query()->whereHas('targets', fn ($query) => $query->where('instance_id', $instance->id))->update(['status' => 'pending']);

        $default = Instance::query()->where('project_id', $instance->project_id)->where('name', 'default')->firstOrFail();
        expect(completionRefusal($default))->toBe('instance.complete_unavailable');

        ConnEnvironment::query()->where('instance_id', $instance->id)->delete();
        expect(completionRefusal($instance))->toBe('instance.complete_unavailable');

        ConnEnvironment::query()->forceCreate(['environment_id' => 'env-again', 'label' => 'x', 'url' => 'http://10.44.0.7:3800',
            'node_id' => $instance->node_id, 'instance_id' => $instance->id, 'admin_session' => 's', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
        $holder = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
            'lock_key' => OperationStore::lockKey('instance', $instance->id), 'input' => [], 'state' => [], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 0]);
        expect(completionRefusal($instance))->toBe('operation.target_busy');
        $holder->forceFill(['status' => OperationStatus::Succeeded, 'lock_key' => null])->save();
        expect(completionRefusal($instance))->toBe('admitted');
    });

    it('refuses a retained failed create today: its T3 server is recorded only by a successful create', function (): void {
        [$instance] = clonePendingCompletion(registered: false);

        expect($instance->status)->toBe(InstanceState::SourceResolved)
            ->and($instance->starting_commit)->not->toBeNull()
            ->and(completionRefusal($instance))->toBe('instance.complete_unavailable');
    });

    it('answers 409 instance.complete_unavailable over the API for an active clone and starts nothing', function (): void {
        [$instance] = clonePendingCompletion();
        completedClone($instance);
        $caller = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'reader', 'status' => 'active',
            'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit']));
        $caller->accessibleNodes()->attach($instance->node_id);
        $before = Operation::query()->count();

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->postJson('/api/v1/instances/'.$instance->id.'/complete')
            ->assertStatus(409)->assertJsonPath('error.code', 'instance.complete_unavailable');

        expect(Operation::query()->count())->toBe($before);
    });
});

describe('the run input', function (): void {
    it('stages only the Process files in its own vault copy and names the Route it publishes', function (): void {
        [$instance] = clonePendingCompletion();
        $operation = runningCompletion($instance);

        $run = app(InstanceCompleteProjection::class)->input($operation);

        $put = collect($this->completionVault->puts)->firstWhere('key', 'instance-complete-processes-'.$operation->id);
        expect($run['execution_secrets']['process_environments'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'instance-complete-processes-'.$operation->id])
            ->and(array_keys(json_decode($put['value'], true)))->toBe(['processes'])
            ->and($run['intent']['route'])->toBe($operation->input['route_create'])
            ->and($run['intent']['route_mutations'])->toHaveKeys(['artifacts', 'restore'])
            ->and($run['intent'])->toHaveKeys(['database', 'server_database', 'server_valkey', 'container', 'ssh_execution'])
            ->and($run['intent'])->not->toHaveKey('fleet');
    });
});

describe('the terminal projection', function (): void {
    it('makes the Route and then the clone active on success', function (): void {
        [$instance] = clonePendingCompletion();
        $operation = runningCompletion($instance);

        completionEnds($operation, 'succeeded', ['ready' => true]);

        $instance->refresh();
        expect($instance->status)->toBe(InstanceState::Active)
            ->and($instance->provisioning_step)->toBe('active')
            ->and($instance->first_setup_pending)->toBeFalse()
            ->and(Route::query()->findOrFail($operation->input['route_id'])->status->value)->toBe('active')
            ->and($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
            ->and($operation->lock_key)->toBeNull();
    });

    it('keeps the clone, its running container and its T3 registration after a failure, and names instance:complete as the retry', function (): void {
        [$instance] = clonePendingCompletion();
        $operation = runningCompletion($instance);
        $failure = ['process_id' => 41, 'unit' => 'orbit-process-41-worker.service', 'state' => 'failed'];

        completionEnds($operation, 'failed', ['ready' => false, 'failed_step' => 'check', 'error_code' => 'instance.container_process_inactive',
            'process_failure' => $failure], 'instance.container_process_inactive');

        $instance->refresh();
        $result = $operation->refresh()->result;
        expect($instance->status)->toBe(InstanceState::SourceResolved)
            ->and($instance->failed_step)->toBe('check')
            ->and($instance->error_code)->toBe('instance.container_process_inactive')
            ->and($instance->starting_commit)->toBe(str_repeat('a', 40))
            ->and(InstanceRuntime::query()->where('instance_id', $instance->id)->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running)
            ->and(ConnEnvironment::query()->where('instance_id', $instance->id)->exists())->toBeTrue()
            ->and($result['process_failure'])->toBe($failure)
            ->and($result['recovery']['state'])->toBe('retained_for_retry')
            ->and($result['recovery']['retry'])->toBe(['command' => 'instance:complete', 'instance_id' => $instance->id, 'new_idempotency_key' => true])
            ->and($result['recovery']['destroy']['command'])->toBe('instance:destroy');
        // The retry is the same admission again.
        expect(completionRefusal($instance))->toBe('admitted');
    });

    it('records why the fleet did not start as the failure detail, redacted, and changes no other failure', function (): void {
        [$instance] = clonePendingCompletion();
        $native = ['failed_step' => 'clone-fleet', 'detail' => 'Command exited with code 2', 'stderr_recorded' => false];
        $runs = [['failed_step' => 'fleet', 'error_code' => 'instance.container_fleet_resume_failed',
            'detail' => "Check Doctor: Readiness check 'doctor' failed after 300 s: HTTP 200: {} password=hunter2-secret-value"],
            ['failed_step' => 'workspace', 'error_code' => 'instance.create_workspace_failed']];
        $ended = [];
        foreach ($runs as $result) {
            $operation = runningCompletion($instance->refresh());
            $ended[] = app(InstanceCompleteProjection::class)->apply($operation, ['status' => 'failed', 'run_id' => 'complete-run', 'error_code' => $result['error_code'],
                'failure' => [...$native, 'failed_step' => $result['failed_step'] === 'fleet' ? 'clone-fleet' : 'clone-services'],
                'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
                'result' => ['ready' => false, 'instance_id' => $instance->id, ...$result]]);
        }
        [$fleet, $other] = $ended;
        $failure = $fleet->refresh()->state['native_failure'];
        expect($fleet->error_code)->toBe('instance.container_fleet_resume_failed')
            ->and($fleet->result)->not->toHaveKey('detail')
            ->and([$failure['failed_step'], $failure['stderr_recorded']])->toBe(['clone-fleet', true])
            ->and($failure['detail'])->toStartWith("Check Doctor: Readiness check 'doctor' failed after 300 s")
            ->and($failure['detail'])->not->toContain('hunter2-secret-value')
            // Any other failed completion keeps the native step's failure as it was.
            ->and($other->refresh()->state['native_failure'])->toBe([...$native, 'failed_step' => 'clone-services']);
    });

    it('asks for a definition change after a dead Project Process, and the retry installs the definitions as they are now', function (): void {
        [$instance] = clonePendingCompletion();
        $definition = ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => 'worker', 'environments' => ['development'],
            'spec' => ['runtime' => 'systemd', 'command' => ['/bin/false']]]);
        $operation = runningCompletion($instance);
        $process = Process::query()->where('owner_id', $instance->id)->where('source_definition_id', $definition->id)->sole();
        expect(array_column($operation->input['direct_provisioning']['container']['processes'], 'id'))->toContain($process->id);
        $dead = ['ready' => false, 'failed_step' => 'check', 'error_code' => 'instance.container_process_inactive'];

        completionEnds($operation, 'failed', [...$dead, 'process_failure' => ['process_id' => $process->id, 'unit' => 'u', 'state' => 'failed']], 'instance.container_process_inactive');
        expect($operation->refresh()->result['recovery']['retry']['requires'] ?? null)->toBe('project_process_definition');
        // A failure the activity log and the alert webhook report, as for a failed create.
        expect(Activity::query()->where('description', 'instance.operation_failed')->get()->pluck('properties.kind')->all())->toContain('instance.complete');

        $definition->update(['spec' => ['runtime' => 'systemd', 'command' => ['/bin/sleep', '3600']]]);
        $retry = runningCompletion($instance->refresh());
        expect($process->refresh()->runtime_config['command'][0])->toBe('/bin/sleep');
        // A built-in unit's failure keeps the plain retry.
        completionEnds($retry, 'failed', [...$dead, 'process_failure' => ['process_id' => $process->id + 1000, 'unit' => 'orbit-frankenphp.service', 'state' => 'failed']],
            'instance.container_process_inactive');
        expect($retry->refresh()->result['recovery']['retry'])->not->toHaveKey('requires');
    });

    it('keeps, in the first completion, the Processes its create admitted: a definition edited before the hand-over is not installed', function (): void {
        $node = containerDefaultNode();
        $node->update(['tld' => 'beast.test']);
        $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
        $definition = ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'worker', 'environments' => ['development'],
            'spec' => ['runtime' => 'systemd', 'command' => ['/bin/false']]]);
        $seed = containerCreate($project, $node);
        $seed->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'seed-run']])->save();
        app(DevelopmentInstanceCreateProjection::class)->apply($seed, ['status' => 'succeeded', 'run_id' => 'seed-run', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'result' => ['instance_id' => $seed->target_id, 'ready' => true, 'commit' => str_repeat('c', 40)]]);
        $create = containerCreate($project, $node, 'feature');
        $create->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-1']])->save();
        $process = Process::query()->where('owner_id', $create->target_id)->where('source_definition_id', $definition->id)->sole();
        // The definition changes while the create runs.
        $definition->update(['spec' => ['runtime' => 'systemd', 'command' => ['/bin/sleep', '3600']]]);
        swampVaultStandIn();
        workspaceReader();

        app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), ['status' => 'succeeded', 'run_id' => 'run-1',
            'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
            'result' => ['ready' => true, 'instance_id' => $create->target_id, 'commit' => str_repeat('c', 40), 't3' => [
                'environment_id' => 'env-feature', 'server_version' => '0.1.7', 'expires_at' => '2026-12-10T10:00:00.000Z',
                'gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-gateway-1-token'],
                'swamp_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-swamp-1-token']]]]);

        $first = Operation::query()->findOrFail($create->refresh()->result['completion_operation_id']);
        $unit = collect($first->input['direct_provisioning']['container']['processes'])->firstWhere('id', $process->id);
        expect($process->refresh()->runtime_config['command'][0])->toBe('/bin/false')
            ->and($unit['content'])->toContain('/bin/false')->not->toContain('/bin/sleep');
    });

    it('marks the Route of a failed publication, and keeps the clone after a cancel', function (): void {
        [$instance] = clonePendingCompletion();
        $operation = runningCompletion($instance);
        completionEnds($operation, 'failed', ['ready' => false, 'failed_step' => 'route', 'error_code' => 'instance.create_route_failed'], 'instance.create_route_failed');
        expect(Route::query()->findOrFail($operation->input['route_id'])->only(['failed_step', 'error_code']))
            ->toBe(['failed_step' => 'native-create', 'error_code' => 'instance.create_route_failed']);

        $cancelled = runningCompletion($instance->refresh());
        completionEnds($cancelled, 'cancelled', ['ready' => false]);
        $instance->refresh();
        expect($cancelled->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and($instance->status)->toBe(InstanceState::SourceResolved)
            ->and($instance->error_code)->toBe('operation.cancelled')
            ->and($cancelled->result['recovery']['retry']['command'])->toBe('instance:complete')
            ->and(InstanceRuntime::query()->where('instance_id', $instance->id)->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running);
    });
});

it('holds the Route Nodes, blocks a Gateway release and has its own fixed budget', function (): void {
    expect(RoutePublicationHolds::applies('instance.complete'))->toBeTrue()
        ->and(RoutePublicationHolds::applies('instance.create'))->toBeTrue()
        ->and(WaitForGatewayReleaseIdleAction::BlockingKindPatterns)->toContain('instance.complete')
        ->and(OperationBudgetTable::SecondsByKind['instance.complete'])->toBe(1800);
});

it('keeps the clone for a retry when the completion is cancelled before its run starts', function (): void {
    [$instance] = clonePendingCompletion();
    $operation = app(StartInstanceCompleteAction::class)->execute($instance);

    app(CancelOperationAction::class)->execute($operation);

    $operation->refresh();
    $instance->refresh();
    expect($operation->status)->toBe(OperationStatus::Cancelled)
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->result['recovery']['retry']['command'])->toBe('instance:complete')
        ->and($instance->status)->toBe(InstanceState::SourceResolved)
        ->and([$instance->failed_step, $instance->error_code])->toBe(['complete', 'operation.cancelled'])
        ->and(completionRefusal($instance))->toBe('admitted');
});

it('deletes the completion\'s own vault copy and every copy its input names when the clone is destroyed', function (): void {
    [$instance] = clonePendingCompletion();
    $operation = runningCompletion($instance);
    app(InstanceCompleteProjection::class)->input($operation);
    $routeKeys = collect($operation->input['execution_secrets'])->pluck('key')->all();
    $destroy = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'input' => [], 'state' => [], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 0]);

    app(ContainerDestroyExecutionCopies::class)->remove($destroy);

    $deleted = collect($this->completionVault->puts)->where('type', 'vault.delete')->pluck('key')->all();
    expect($deleted)->toContain('instance-complete-processes-'.$operation->id, ...$routeKeys);
});

it('counts a clone as registered from its Conn row, also while Conn is off', function (): void {
    [$instance] = clonePendingCompletion();
    app(ExtensionStore::class)->set('conn', false);

    expect(app(InstanceLifecycleContributions::class)->registered($instance))->toBeTrue();
    ConnEnvironment::query()->where('instance_id', $instance->id)->delete();
    expect(app(InstanceLifecycleContributions::class)->registered($instance))->toBeFalse();
});

describe('Conn\'s Retry', function (): void {
    it('admits the same completion over the Conn endpoint, replays its key, and refuses what the core admission refuses', function (): void {
        [$instance] = clonePendingCompletion();
        $phone = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'phone', 'status' => 'active',
            'platform' => 'linux', 'wireguard_ip' => '10.44.0.77', 'public_ssh_host' => '10.44.0.77', 'user' => 'orbit']));
        $phone->accessibleNodes()->attach($instance->node_id);
        $call = fn (string $key, array $body = []) => $this->withServerVariables(['REMOTE_ADDR' => $phone->wireguard_ip])
            ->postJson('/api/v1/conn/instances/'.$instance->id.'/complete', $body, ['Idempotency-Key' => $key]);

        $first = $call('retry-1')->assertAccepted()->assertJsonPath('data.kind', 'instance.complete')->assertJsonPath('data.target_id', $instance->id);
        $call('retry-1')->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
        $call('retry-2')->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
        $call('retry-3', ['force' => true])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
        $this->withServerVariables(['REMOTE_ADDR' => $phone->wireguard_ip])->postJson('/api/v1/conn/instances/'.$instance->id.'/complete')
            ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
        expect(Operation::query()->where('kind', 'instance.complete')->count())->toBe(1)
            ->and(Operation::query()->findOrFail($first->json('data.id'))->triggered_by)->toBe('node:'.$phone->id);

        Operation::query()->whereKey($first->json('data.id'))->update(['status' => 'failed', 'lock_key' => null, 'finished_at' => now()]);
        completedClone($instance);
        $call('retry-4')->assertConflict()->assertJsonPath('error.code', 'instance.complete_unavailable');
    });
});
