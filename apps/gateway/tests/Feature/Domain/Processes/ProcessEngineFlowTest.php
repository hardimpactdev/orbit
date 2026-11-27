<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\DestroyDatabaseServerAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Nodes\AdmitNodeRemovalOperationAction;
use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Actions\Processes\DestroyProcessAction;
use App\Actions\Processes\StartProcessAction;
use App\Actions\Schedules\CreateScheduleAction;
use App\Data\Schedules\AddScheduleData;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineKinds;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineTargetFence;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\MachineBus;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Nodes\NodeExecutorInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessControlEngineFlow;
use App\Domain\Processes\ProcessControlProjection;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\VpDevPreset;
use App\Domain\Projects\ProjectType;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Cluster;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Models\Process;
use App\Models\Project;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;
use Orbit\Extensions\AiRouter\Infrastructure\NativeAiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Models\AiRouterProcessSource;

uses(LazilyRefreshDatabase::class);

/** A machine bus that keeps what the engine publishes and reports the actions a test gives the Node. */
final class ProcessEngineBus implements MachineBus
{
    /** @var list<SignedExecutorOrder> */
    public array $orders = [];

    /** @var list<int> */
    public array $starts = [];

    /** @var list<string> */
    public array $actions = ['process.ensure_state', 'process.read_instance', 'process.restart', 'schedule.ensure_timer'];

    /** @var array<string, string> */
    public array $contents = [];

    public function publishOrder(int $node, SignedExecutorOrder $order, ?Closure $claim = null): void
    {
        if ($claim === null || $claim()) {
            $this->orders[] = $order;
        }
    }

    public function publishStart(int $operation): void
    {
        $this->starts[] = $operation;
    }

    public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void {}

    public function nudge(int $operation, string $id): void {}

    public function putContent(int $node, string $content): array
    {
        $this->contents[$sha = hash('sha256', $content)] = $content;

        return ['sha256' => $sha];
    }

    public function nodeActions(int $node): array
    {
        return $this->actions;
    }

    public function nodeFeatures(int $node): array
    {
        return [];
    }

    public function nodeTargetActions(int $node): array
    {
        return $this->actions;
    }

    public function nodeClock(int $node): ?bool
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function payload(int $index): array
    {
        return json_decode((string) base64_decode(explode('.', $this->orders[$index]->wire)[0], true), true, flags: JSON_THROW_ON_ERROR);
    }
}

beforeEach(function (): void {
    Queue::fake();
    $pair = sodium_crypto_sign_keypair();
    config(['orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        'orbit.engine.kinds' => ['process.start', 'process.stop', 'process.restart', 'schedule.enable']]);
    $this->bus = new ProcessEngineBus;
    $this->app->instance(MachineBus::class, $this->bus);
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'app-dev', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->process = fn (array $command = ['php', 'artisan', 'queue:work'], string $runtime = 'systemd'): Process => Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'web'.(Process::query()->count() ?: ''), 'runtime' => $runtime,
        'runtime_config' => $runtime === 'docker' ? ['image' => 'redis:7'] : ['command' => $command],
        'restart_policy' => 'always', 'desired_state' => 'stopped', 'status' => 'active']);
    $this->operation = function (Process $process, string $kind, array $input = []) {
        $name = $process->runtime->value === 'docker' ? "orbit-process-{$process->id}-web" : "orbit-process-{$process->id}-web.service";

        return Operation::query()->create(['kind' => $kind, 'target_type' => 'process', 'target_id' => $process->id, 'node_id' => $this->node->id,
            'status' => OperationStatus::Queued,
            'input' => [...['process_id' => $process->id, 'node_id' => $this->node->id, 'name' => $name, 'runtime' => $process->runtime->value,
                'variant' => $process->runtime->value, 'desired' => 'stopped', 'on_demand' => false, 'created' => false, 'preserve_record' => false,
                'caller_node_id' => null, 'vite' => null], ...($process->runtime->value === 'docker' ? ['image' => 'redis:7'] : []), ...$input]]);
    };
    // A receipt names the Node, the step, its action and its target, as the executor's receipts do; a fenced kind needs all four.
    $this->receipt = function (Operation $operation, string $step, array $line): void {
        $row = OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', $step)->firstOrFail();
        app(Engine::class)->onReceipt(['node' => $row->node_id, 'line' => ['action' => $row->action, 'target' => $row->target,
            'operation' => "{$operation->id}:{$step}", ...$line]]);
    };
    $this->input = fn (Operation $operation): array => app(ProcessControlEngineFlow::class)->input($operation, 'converge', new EngineRun($operation->id, [], 0, null));
});

it('starts a host unit on the engine with one ensure_state step and projects what a Swamp run projects', function (): void {
    $process = ($this->process)();
    $operation = ($this->operation)($process, 'process.start');
    app(StartSwampRun::class)->start($operation);

    expect($operation->refresh()->state['swamp_run_id'])->toBe('engine-'.$operation->id)
        ->and($this->bus->starts)->toBe([$operation->id])
        ->and(OperationEngineStep::query()->where('operation_id', $operation->id)->pluck('name')->all())->toBe(['converge']);

    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    $payload = $this->bus->payload(0);
    expect($payload['action'])->toBe('process.ensure_state')
        ->and($payload['target'])->toBe("{$this->node->id}:process-{$process->id}")
        ->and($payload['operation'])->toBe("{$operation->id}:converge")
        ->and($payload['input'])->toBe(['process_id' => $process->id, 'runtime' => 'systemd', 'name' => "orbit-process-{$process->id}-web.service",
            'state' => 'running', 'boot' => 'enabled']);

    // A lost receipt: at its resend deadline the step goes again with a fresh nonce and the same step ID; the executor's journal
    // answers the stored result.
    $this->travel(16)->seconds();
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id, 'step' => 'converge']);
    expect($this->bus->orders)->toHaveCount(2)
        ->and($this->bus->payload(1)['operation'])->toBe($payload['operation'])
        ->and($this->bus->payload(1)['nonce'])->not->toBe($payload['nonce'])
        ->and($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->lock_key)->toBe('process:'.$process->id);
    ($this->receipt)($operation, 'converge', ['status' => 'applied', 'changes' => 2, 'repeat' => true]);

    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result)->toBe(['ready' => true, 'state' => 'active'])
        ->and($operation->lock_key)->toBeNull()
        ->and($process->refresh()->desired_state->value)->toBe('running')
        ->and($process->status)->toBe(LifecycleStatus::Active)
        ->and($this->bus->orders)->toHaveCount(2);
});

it('stops a unit disabled at boot and fails the Process with the read-back code', function (): void {
    $process = ($this->process)();
    $operation = ($this->operation)($process, 'process.stop');
    expect(($this->input)($operation))->toMatchArray(['state' => 'stopped', 'boot' => 'disabled']);

    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    ($this->receipt)($operation, 'converge', ['status' => 'failed', 'code' => 'process.state_mismatch']);

    // The same failure through the Swamp result reader: a failed native check is `operation.native_failed`.
    $swamp = ($this->operation)($other = ($this->process)(), 'process.stop');
    $swamp->forceFill(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => 'run-1']])->save();
    app(ProcessControlProjection::class)->apply($swamp, ['run_id' => 'run-1', 'started_at' => '2026-11-26T13:00:00.000Z', 'finished_at' => '2026-11-26T13:00:01.000Z', 'status' => 'failed', 'error_code' => 'operation.native_failed',
        'result' => ['error_code' => 'operation.native_failed'], 'failure' => ['failed_step' => 'Verify the state', 'detail' => 'assert', 'stderr_recorded' => false]]);

    $operation->refresh();
    $swamp->refresh();
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('operation.native_failed')->toBe($swamp->error_code)
        ->and($operation->result)->toBe(['error_code' => 'operation.native_failed'])->toBe($swamp->result)
        ->and($operation->failed_step)->toBe('Verify the state')->toBe($swamp->failed_step)
        ->and(OperationEngineStep::query()->where('operation_id', $operation->id)->sole()->error_code)->toBe('process.state_mismatch')
        ->and($process->refresh()->status)->toBe(LifecycleStatus::Failed)
        ->and($process->error_code)->toBe($other->refresh()->error_code)
        ->and($process->desired_state->value)->toBe('stopped');
});

it('keeps an on-demand unit disabled at boot when it starts', function (): void {
    expect(($this->input)(($this->operation)(($this->process)(), 'process.start', ['on_demand' => true])))->toMatchArray(['state' => 'running', 'boot' => 'disabled']);
});

it('converges a Docker container by its name and admitted image', function (): void {
    $process = ($this->process)(runtime: 'docker');
    $operation = ($this->operation)($process, 'process.stop');

    expect(($this->input)($operation))->toBe(['process_id' => $process->id, 'runtime' => 'docker', 'name' => "orbit-process-{$process->id}-web", 'state' => 'stopped', 'image' => 'redis:7']);

    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    ($this->receipt)($operation, 'converge', ['status' => 'applied', 'changes' => 0]);
    expect($operation->refresh()->result)->toBe(['ready' => true, 'state' => 'exited']);
});

it('leaves every shape the primitive does not reproduce on Swamp', function (array $input, ?array $command = null): void {
    $operation = ($this->operation)(($this->process)($command ?? ['php', 'artisan', 'queue:work']), $input['kind'] ?? 'process.start', array_diff_key($input, ['kind' => 1]));

    expect(fn () => app(ProcessControlEngineFlow::class)->steps($operation))->toThrow(EngineUnsupported::class)
        ->and(app(EngineKinds::class)->routes($operation))->toBeFalse();
})->with([
    'host Horizon stop (drain wait)' => [['kind' => 'process.stop'], ['php', 'artisan', 'horizon']],
    'host Horizon start' => [[], ['php', 'artisan', 'horizon']],
    'Vite dev Process' => [['variant' => 'vite', 'vite' => ['path' => '/x', 'instance_id' => 3], 'vite_plan' => ['listener' => ['port' => 5173]]]],
    'production release layout' => [['directory' => '/home/orbit/apps/shop/current']],
    'Gateway release container' => [['container' => ['release' => str_repeat('a', 40), 'project' => 'orbit-releases', 'name' => 'gateway-'.str_repeat('a', 12)]]],
    'unit in an Instance container (its app-account move is a script)' => [['container' => ['instance_id' => 12, 'project' => 'orbit-instances', 'name' => 'instance-12',
        'app_account' => ['path' => '/usr/local/libexec/orbit-app-user', 'content' => "#!/bin/sh\n"]]]],
]);

it('takes the whole Swamp path when the Node does not report the action or the kind is off', function (): void {
    $operation = ($this->operation)(($this->process)(), 'process.start');
    $this->bus->actions = ['ufw.ensure_rule'];
    expect(app(EngineKinds::class)->routes($operation))->toBeFalse();

    $this->bus->actions = ['process.ensure_state'];
    expect(app(EngineKinds::class)->routes($operation))->toBeTrue();
    config(['orbit.engine.kinds' => ['process.stop']]);
    expect(app(EngineKinds::class)->routes($operation))->toBeFalse();
});

it('enables a host Node timer with one ensure_timer step and settles it only on its read-back', function (): void {
    $schedule = Schedule::query()->create(['target_type' => Node::class, 'target_id' => $this->node->id, 'host_node_id' => $this->node->id,
        'name' => 'nightly', 'calendar' => '*-*-* 03:00:00', 'command' => 'true', 'timeout_seconds' => 60,
        'desired_timer_state' => 'enabled', 'status' => 'active', 'trigger' => 'node']);
    $operation = Operation::query()->create(['kind' => 'schedule.enable', 'target_type' => 'node_schedules', 'target_id' => $this->node->id, 'node_id' => $this->node->id,
        'status' => OperationStatus::Queued, 'input' => ['id' => $schedule->id, 'node_id' => $this->node->id]]);

    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    expect($operation->refresh()->lock_key)->toBe('schedule:'.$schedule->id);
    $payload = $this->bus->payload(0);
    expect($payload['action'])->toBe('schedule.ensure_timer')
        ->and($payload['target'])->toBe("{$this->node->id}:schedule-{$schedule->id}")
        ->and($payload['input'])->toBe(['schedule_id' => $schedule->id, 'unit' => "orbit-schedule-{$schedule->id}.timer"]);

    // A success that is not the timer's read-back settles nothing.
    ($this->receipt)($operation, 'timer', ['status' => 'output', 'stdout' => '{"changes":0,"unit_file_state":"disabled","active_state":"active","next_elapse":""}']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and(OperationEngineStep::query()->where('operation_id', $operation->id)->sole()->error_code)->toBe('engine.receipt_invalid');
});

it('records the Schedule result Swamp records from the timer read-back', function (): void {
    $schedule = Schedule::query()->create(['target_type' => Node::class, 'target_id' => $this->node->id, 'host_node_id' => $this->node->id,
        'name' => 'nightly', 'calendar' => '*-*-* 03:00:00', 'command' => 'true', 'timeout_seconds' => 60,
        'desired_timer_state' => 'enabled', 'status' => 'active', 'trigger' => 'node']);
    $operation = Operation::query()->create(['kind' => 'schedule.enable', 'target_type' => 'node_schedules', 'target_id' => $this->node->id, 'node_id' => $this->node->id,
        'status' => OperationStatus::Queued, 'input' => ['id' => $schedule->id, 'node_id' => $this->node->id]]);
    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    ($this->receipt)($operation, 'timer', ['status' => 'output',
        'stdout' => '{"changes":0,"unit_file_state":"enabled","active_state":"active","next_elapse":"Thu 2026-11-26 03:00:00 UTC"}']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result)->toBe(['ready' => true]);

    foreach ([['production_control' => ['instance_id' => 3, 'release' => 'current']], ['container' => ['instance_id' => 3]]] as $extra) {
        $other = $operation->replicate()->forceFill(['input' => [...$operation->input, ...$extra]]);
        expect(app(EngineKinds::class)->routes($other))->toBeFalse();
    }
    $schedule->update(['trigger' => 'swamp']);
    expect(app(EngineKinds::class)->routes($operation->replicate()))->toBeFalse();
});

it('gates the process family on agent 0.31.0, which this tree builds or follows', function (): void {
    preg_match('/^\[package\]\nname = "orbit-agent"\nversion = "([0-9.]+)"/m', (string) file_get_contents(base_path('../agent/Cargo.toml')), $built);

    // The family shipped in 0.31.0; a later agent with more actions keeps it, so the gate never moves past the released agent.
    expect(NodeExecutorInput::FamilySince['process'])->toBe('0.31.0')
        ->and(version_compare($built[1] ?? '0', NodeExecutorInput::FamilySince['process'], '>='))->toBeTrue();
});

it('enables the process family on app-dev only, from the agent that knows it', function (): void {
    $intent = fn (string $version, RoleName $role): ?array => NodeExecutorInput::intent('7', 'orbit', 'executor', [$role],
        ['executor' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/x']], ['key'], $version);

    expect($intent('0.31.0', RoleName::AppDev)['enabled'])->toBe("firewall\ninstance\npower\nprocess\nproof\nroute\n")
        ->and($intent('0.30.0', RoleName::AppDev)['enabled'])->toBe("firewall\ninstance\npower\nproof\nroute\n")
        ->and($intent('0.31.0', RoleName::AppProd))->toBeNull();
});

it('binds the run with its Process lock and fences competing Operations on both engines until a terminal receipt', function (): void {
    $process = ($this->process)();
    $start = ($this->operation)($process, 'process.start');
    app(StartSwampRun::class)->start($start);
    expect($start->refresh()->lock_key)->toBe('process:'.$process->id);

    // Admission of any other Process verb, on either engine, is refused while the run holds the lock.
    expect(fn () => app(ProcessOperationAdmission::class)->execute($process, 'stop'))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('operation.target_busy'));

    // An engine run whose Process already has another open Operation, here a Swamp one, does not bind.
    $other = ($this->process)();
    $swamp = ($this->operation)($other, 'process.restart');
    $swamp->forceFill(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => 'run-2']])->save();
    $engine = ($this->operation)($other, 'process.stop');
    expect(fn () => app(StartSwampRun::class)->start($engine))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('operation.target_busy'));
    expect($engine->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($engine->lock_key)->toBeNull()
        ->and(OperationEngineStep::query()->where('operation_id', $engine->id)->exists())->toBeFalse();
});

it('keeps the run fenced and unsettled when the receipt is lost past the step budget', function (): void {
    $process = ($this->process)();
    $operation = ($this->operation)($process, 'process.start');
    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    $this->travel(121)->seconds();
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id, 'step' => 'converge']);

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->lock_key)->toBe('process:'.$process->id)
        ->and(count($this->bus->orders))->toBe(2);
    ($this->receipt)($operation, 'converge', ['status' => 'applied', 'changes' => 1, 'repeat' => true]);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
});

it('decides eligibility from the Process and its placement, on the intents admission builds', function (): void {
    $dev = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'dev', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '10.44.0.3', 'user' => 'orbit']));
    $dev->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $prod = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'prod', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.4', 'public_ssh_host' => '10.44.0.4', 'user' => 'orbit']));
    $prod->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'tool', 'slug' => 'tool', 'type' => ProjectType::NodePackage, 'repository_url' => 'https://github.com/nckrtl/tool.git',
        'default_branch' => 'main', 'root' => '.', 'is_laravel' => false]);
    $instance = fn (Node $node, string $name): Instance => Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name,
        'checkout_path' => "/home/orbit/apps/{$name}", 'branch' => 'main', 'vite_port' => 5173, 'status' => 'active',
        'provisioning_step' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => false]);
    $owned = fn (Instance $owner, array $config, string $name = 'worker'): Process => Process::query()->create(['owner_type' => 'instance', 'owner_id' => $owner->id, 'name' => $name,
        'runtime' => 'systemd', 'runtime_config' => $config, 'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active']);
    $admitted = function (Process $process, string $verb, ProcessTarget $target): Operation {
        $input = app(ProcessOperationAdmission::class)->inputForTarget($process, $verb, $target);

        return (new Operation)->forceFill(['id' => 900 + $process->id, 'kind' => "process.{$verb}", 'target_type' => 'process', 'target_id' => $process->id,
            'node_id' => $target->node->id, 'input' => $input]);
    };
    $routes = fn (Operation $operation): bool => app(EngineKinds::class)->routes($operation);

    $shop = $instance($dev, 'shop');
    $devTarget = new ProcessTarget($dev, 'orbit', '/home/orbit/apps/shop', 'app-instance-'.$shop->id, $shop, '/home/orbit/apps/shop/.env');
    $worker = $owned($shop, ['command' => ['php', 'artisan', 'queue:work']]);
    $vp = $owned($shop, ['preset' => VpDevPreset::NAME, 'command' => VpDevPreset::command(null)], 'dev');
    $live = $instance($prod, 'live');
    $prodWorker = $owned($live, ['command' => ['php', 'artisan', 'queue:work']]);
    $prodTarget = new ProcessTarget($prod, 'shop', '/home/shop/current', null, $live, '/home/shop/.env', productionReleaseLayout: true);
    $guest = new ProcessTarget($dev, 'www-data', '/srv/workspace', null, $shop, '/srv/workspace/.env',
        container: ['instance_id' => $shop->id, 'project' => 'orbit-instances', 'name' => 'instance-'.$shop->id]);

    expect($routes($admitted($worker, 'stop', $devTarget)))->toBeTrue()
        ->and($routes($admitted($worker, 'start', $devTarget)))->toBeTrue()
        ->and($routes($admitted($vp, 'stop', $devTarget)))->toBeFalse()
        ->and($routes($admitted($prodWorker, 'stop', $prodTarget)))->toBeFalse()
        ->and($routes($admitted($prodWorker, 'start', $prodTarget)))->toBeFalse()
        ->and($routes($admitted($vp, 'start', $guest)))->toBeFalse()
        ->and($routes($admitted($worker, 'start', $guest)))->toBeFalse();

    // Only the renderer's drain condition refuses: a Node's own systemd Horizon unit. A name alone, an Instance's Horizon unit
    // (no long-drain directives) and a Docker Process running Horizon stay eligible.
    $nodeTarget = new ProcessTarget($dev, 'orbit', '/home/orbit');
    $onNode = fn (string $name, array $config, string $runtime = 'systemd'): Process => Process::query()->create(['owner_type' => Node::class,
        'owner_id' => $dev->id, 'name' => $name, 'runtime' => $runtime, 'runtime_config' => $config, 'restart_policy' => 'always',
        'desired_state' => 'running', 'status' => 'active']);
    $horizon = ['command' => ['php', 'artisan', 'horizon']];
    foreach (['start', 'stop'] as $verb) {
        expect($routes($admitted($onNode("horizon-{$verb}", ['command' => ['php', 'artisan', 'queue:work']]), $verb, $nodeTarget)))->toBeTrue()
            ->and($routes($admitted($onNode("schedule-work-{$verb}", ['command' => ['php', 'artisan', 'schedule:work']]), $verb, $nodeTarget)))->toBeTrue()
            ->and($routes($admitted($owned($shop, $horizon, "horizon-{$verb}"), $verb, $devTarget)))->toBeTrue()
            ->and($routes($admitted($onNode("docker-horizon-{$verb}", [...$horizon, 'image' => 'php:8.4'], 'docker'), $verb, $nodeTarget)))->toBeTrue()
            ->and($routes($admitted($onNode("drain-{$verb}", $horizon), $verb, $nodeTarget)))->toBeFalse();
    }
});

it('runs a Process on the engine only on a Node the Gateway places in development, whatever the Node reports', function (string $kind): void {
    // An ingress role belongs to the Node's Cluster.
    $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => 'active']);
    $node = fn (string $name, array $roles, array $settings = []): Node => tap(Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active',
        'platform' => 'linux', 'wireguard_ip' => '10.44.1.'.Node::query()->count(), 'public_ssh_host' => $name, 'user' => 'orbit', 'settings' => $settings,
        'cluster_id' => in_array(RoleName::Ingress, $roles, true) ? $cluster->id : null])),
        fn (Node $node) => array_map(fn (RoleName $role) => $node->roles()->create(['role' => $role, 'status' => 'active',
            'cluster_id' => $role === RoleName::Ingress ? $cluster->id : null]), $roles));
    $project = Project::query()->create(['name' => 'tool', 'slug' => 'tool', 'type' => ProjectType::NodePackage, 'repository_url' => 'https://github.com/nckrtl/tool.git',
        'default_branch' => 'main', 'root' => '.', 'is_laravel' => false]);
    $operation = function (Node $node, ?Instance $owner = null, array $input = [], string $runtime = 'systemd') use ($kind): Operation {
        $process = Process::query()->create(['owner_type' => $owner === null ? Node::class : 'instance', 'owner_id' => $owner->id ?? $node->id,
            'name' => 'worker'.Process::query()->count(), 'runtime' => $runtime,
            'runtime_config' => $runtime === 'docker' ? ['image' => 'redis:7'] : ['command' => ['php', 'artisan', 'queue:work']],
            'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active']);

        return Operation::query()->create(['kind' => $kind, 'target_type' => 'process', 'target_id' => $process->id, 'node_id' => $node->id,
            'status' => OperationStatus::Queued, 'input' => ['process_id' => $process->id, 'node_id' => $node->id, 'name' => "orbit-process-{$process->id}-web.service",
                'runtime' => $runtime, 'on_demand' => false, 'vite' => null, ...($runtime === 'docker' ? ['image' => 'redis:7'] : []), ...$input]]);
    };
    $instance = fn (Node $node): Instance => Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'i'.Instance::query()->count(),
        'checkout_path' => '/home/orbit/apps/i'.Instance::query()->count(), 'branch' => 'main', 'vite_port' => 5173, 'status' => 'active',
        'provisioning_step' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => false]);
    $routes = fn (Operation $operation): bool => app(EngineKinds::class)->routes($operation);
    $prod = $node('app-prod', [RoleName::AppProd]);
    $both = $node('both', [RoleName::AppDev, RoleName::AppProd]);
    $plain = $node('database', [RoleName::Database]);
    $placed = $instance($prod);

    // The Node reports every process action throughout: only the Gateway's own roles and placement decide.
    expect($routes($operation($this->node)))->toBeTrue()
        ->and($routes($operation($this->node, $instance($this->node))))->toBeTrue()
        ->and($routes($operation($prod)))->toBeFalse()
        ->and($routes($operation($prod, $placed)))->toBeFalse()
        ->and($routes($operation($this->node, $instance($this->node), ['production_control' => ['instance_id' => 1, 'release' => 'current']])))->toBeFalse()
        ->and($routes($operation($both)))->toBeFalse()
        ->and($routes($operation($both, $instance($both))))->toBeFalse()
        ->and($routes($operation($plain)))->toBeFalse();

    // A CI place places only development proof copies: a Node with `ci-runner` and no app role keeps its own Processes on Swamp,
    // alone or beside a shared role, for a unit and a container, while a proof copy on it still reads as development.
    $ci = ['ci' => ['places' => 2, 'proof_only' => false]];
    foreach ([[RoleName::CiRunner], [RoleName::CiRunner, RoleName::Database], [RoleName::CiRunner, RoleName::Ingress]] as $index => $roles) {
        $runner = $node("ci-{$index}", $roles, $ci);
        expect($runner->placementEnvironment())->toBe('development')
            ->and($routes($operation($runner)))->toBeFalse()
            ->and($routes($operation($runner, runtime: 'docker')))->toBeFalse();
    }

    // A production-placed Instance whose Process names a development Node, an app-prod Node's Process whose intent names the
    // development Node, and a Node the Gateway has no row for stay on Swamp too.
    $live = $operation($this->node, $placed);
    $other = $operation($prod);
    $other->forceFill(['node_id' => $this->node->id, 'input' => [...$other->input, 'node_id' => $this->node->id]]);
    $gone = $operation($this->node);
    $gone->forceFill(['node_id' => 9_999, 'input' => [...$gone->input, 'node_id' => 9_999]]);
    expect($routes($live))->toBeFalse()
        ->and($routes($other))->toBeFalse()
        ->and($routes($gone))->toBeFalse()
        ->and(fn () => app(ProcessControlEngineFlow::class)->steps($gone))->toThrow(EngineUnsupported::class);
})->with(['process.start', 'process.stop', 'process.restart']);

it('names the Swamp step each failed phase stands for, and records a cancel as Swamp does', function (string $runtime, string $kind, string $code, string $step): void {
    $operation = ($this->operation)(($this->process)(runtime: $runtime), $kind);
    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    ($this->receipt)($operation, 'converge', ['status' => 'failed', 'code' => $code]);

    expect($operation->refresh()->failed_step)->toBe($step)
        ->and($operation->error_code)->toBe('operation.native_failed');
})->with([
    'unit start change' => ['systemd', 'process.start', 'process.apply_failed', 'Start the Process'],
    'unit stop change' => ['systemd', 'process.stop', 'process.apply_failed', 'Stop the Process'],
    'unit plain stop' => ['systemd', 'process.stop', 'process.unit_stop_failed', 'Stop the unit'],
    'unit read' => ['systemd', 'process.start', 'process.read_failed', 'Check the unit'],
    'unit assert' => ['systemd', 'process.stop', 'process.state_mismatch', 'Verify the state'],
    'container start' => ['docker', 'process.start', 'process.container_missing', 'Start the container'],
    'container stop' => ['docker', 'process.stop', 'process.apply_failed', 'Stop the container'],
    'container read' => ['docker', 'process.stop', 'process.read_failed', 'Check the container'],
    'container assert' => ['docker', 'process.start', 'process.state_mismatch', 'Verify the state'],
]);

it('records a cancelled engine run with the result a cancelled Swamp run records', function (): void {
    $operation = ($this->operation)(($this->process)(), 'process.start');
    app(StartSwampRun::class)->start($operation);
    $steps = OperationEngineStep::query()->where('operation_id', $operation->id)->get()->keyBy('name')->all();
    app(ProcessControlEngineFlow::class)->finish($operation->refresh(), new EngineRun($operation->id, $steps, 1_800_000_000_000, 1_800_000_001_000));

    $swamp = ($this->operation)(($this->process)(), 'process.start');
    $swamp->forceFill(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => 'run-3']])->save();
    app(ProcessControlProjection::class)->apply($swamp, ['run_id' => 'run-3', 'started_at' => '2026-11-26T13:00:00.000Z', 'finished_at' => '2026-11-26T13:00:01.000Z',
        'status' => 'cancelled', 'error_code' => null, 'result' => [], 'failure' => null]);

    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($operation->error_code)->toBeNull()->toBe($swamp->refresh()->error_code)
        ->and($operation->result)->toBe($swamp->result);
});

it('fences one Schedule by its UUID and leaves the owner\'s other Schedules free', function (): void {
    $make = fn (string $name, string $trigger = 'node'): Schedule => Schedule::query()->create(['target_type' => Node::class, 'target_id' => $this->node->id,
        'host_node_id' => $this->node->id, 'name' => $name, 'calendar' => '*-*-* 03:00:00', 'command' => 'true', 'timeout_seconds' => 60,
        'desired_timer_state' => 'enabled', 'status' => 'active', 'trigger' => $trigger]);
    $enable = fn (Schedule $schedule): Operation => Operation::query()->create(['kind' => 'schedule.enable', 'target_type' => 'node_schedules',
        'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'input' => ['id' => $schedule->id, 'node_id' => $this->node->id]]);
    [$nightly, $hourly, $cron] = [$make('nightly'), $make('hourly'), $make('cron', 'swamp')];

    // An open run of another Schedule of the owner, here a cron run, does not stop this timer's enable.
    Operation::query()->create(['kind' => 'schedule.run', 'target_type' => 'node_schedules', 'target_id' => $this->node->id, 'node_id' => $this->node->id,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => 'run-4'], 'input' => ['id' => $cron->id, 'node_id' => $this->node->id]]);
    $first = $enable($nightly);
    app(StartSwampRun::class)->start($first);
    expect($first->refresh()->lock_key)->toBe('schedule:'.$nightly->id);

    // While it holds the lock, the same Schedule is fenced and a sibling is not.
    expect(fn () => EngineTargetFence::free('schedule:'.$nightly->id))->toThrow(ResourceOperationException::class);
    EngineTargetFence::free('schedule:'.$hourly->id);
    $sibling = $enable($hourly);
    app(StartSwampRun::class)->start($sibling);
    expect($sibling->refresh()->lock_key)->toBe('schedule:'.$hourly->id);

    // A second engine enable of the fenced Schedule does not bind.
    $again = $enable($nightly);
    expect(fn () => app(StartSwampRun::class)->start($again))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('operation.target_busy'));
});

it('refuses a create of a fenced Schedule before its reservation changes the row', function (): void {
    $schedule = Schedule::query()->create(['target_type' => Node::class, 'target_id' => $this->node->id, 'host_node_id' => $this->node->id,
        'name' => 'nightly', 'calendar' => '*-*-* 03:00:00', 'command' => 'true', 'timeout_seconds' => 60,
        'desired_timer_state' => 'enabled', 'status' => 'active', 'trigger' => 'node']);
    Operation::query()->create(['kind' => 'schedule.enable', 'target_type' => 'node_schedules', 'target_id' => $this->node->id, 'node_id' => $this->node->id,
        'status' => OperationStatus::Running, 'lock_key' => 'schedule:'.$schedule->id, 'input' => ['id' => $schedule->id, 'node_id' => $this->node->id]]);
    $before = $schedule->refresh()->getAttributes();

    expect(fn () => app(CreateScheduleAction::class)->admit(new AddScheduleData(ScheduleTargetType::Node, $this->node->id, 'nightly', '*-*-* 03:00:00', 'true', 60)))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('operation.target_busy'));
    expect($schedule->refresh()->getAttributes())->toBe($before)
        ->and(Operation::query()->where('kind', 'schedule.create')->exists())->toBeFalse();
});

it('refuses synchronous Process writers while an engine stop is outstanding, and the stop then ends as admitted', function (string $writer): void {
    $process = ($this->process)();
    $operation = ($this->operation)($process, 'process.stop');
    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    expect($this->bus->orders)->toHaveCount(1);

    $direct = match ($writer) {
        'removal' => fn () => app(DestroyProcessAction::class)->execute($process),
        'start' => fn () => app(StartProcessAction::class)->execute($process),
    };
    expect($direct)->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('operation.target_busy'));
    expect(Process::query()->whereKey($process->id)->exists())->toBeTrue()
        ->and($process->refresh()->desired_state->value)->toBe('stopped');

    ($this->receipt)($operation, 'converge', ['status' => 'applied', 'changes' => 1]);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->result)->toBe(['ready' => true, 'state' => 'inactive'])
        ->and($process->refresh()->desired_state->value)->toBe('stopped');
})->with(['removal', 'start']);

/** An engine stop of the Process with its order sent and no receipt yet: the Process is fenced. */
function outstandingEngineStop(object $test, Process $process): Operation
{
    $operation = ($test->operation)($process, 'process.stop');
    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    expect($operation->refresh()->lock_key)->toBe('process:'.$process->id);

    return $operation;
}

it('refuses a Node removal that would delete a fenced Process before teardown starts', function (): void {
    $process = ($this->process)();
    outstandingEngineStop($this, $process);
    // The fence alone refuses: without an app role the removal plans no role teardown that would need an agent build first.
    $this->node->roles()->delete();
    $caller = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'operator', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit']));

    expect(fn () => app(AdmitNodeRemovalOperationAction::class)->execute($this->node, $caller, offline: true, force: true))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('operation.target_busy'));
    expect(Operation::query()->where('kind', 'node.remove')->exists())->toBeFalse()
        ->and($this->node->refresh()->status->value)->toBe('active');
});

it('refuses an analytics role removal that would delete its fenced Plausible Process', function (): void {
    $this->node->roles()->create(['role' => RoleName::Analytics, 'status' => 'active']);
    $plausible = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => PlausibleProcess::NAME,
        'runtime' => 'docker', 'runtime_config' => ['image' => 'redis:7'], 'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active']);
    outstandingEngineStop($this, $plausible);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->remove($this->node, RoleName::Analytics, force: true))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('operation.target_busy'));
    expect(Operation::query()->where('kind', 'node.role_remove')->exists())->toBeFalse();
});

it('refuses a database server removal whose Docker Process is fenced', function (): void {
    $process = ($this->process)(runtime: 'docker');
    outstandingEngineStop($this, $process);
    $server = DatabaseServer::query()->create(['slug' => 'fixture-postgres', 'driver' => 'pgsql', 'node_id' => $this->node->id, 'process_id' => $process->id, 'port' => 5432, 'tag' => '16', 'root_password' => 'fixture-only', 'status' => 'active']);

    expect(fn () => app(DestroyDatabaseServerAction::class)->execute($server))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('operation.target_busy'));
    expect(Operation::query()->where('kind', 'database_server.destroy')->exists())->toBeFalse()
        ->and(DatabaseServer::query()->whereKey($server->id)->exists())->toBeTrue();
});

it('refuses an Instance removal that would delete its fenced Process, and a Process order never binds beside an open removal', function (): void {
    $project = Project::query()->create(['name' => 'tool', 'slug' => 'tool', 'type' => ProjectType::NodePackage, 'repository_url' => 'https://github.com/nckrtl/tool.git',
        'default_branch' => 'main', 'root' => '.', 'is_laravel' => false]);
    $shop = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'shop', 'checkout_path' => '/home/orbit/apps/shop',
        'branch' => 'main', 'vite_port' => 5173, 'status' => 'active', 'provisioning_step' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => false]);
    $worker = Process::query()->create(['owner_type' => 'instance', 'owner_id' => $shop->id, 'name' => 'worker', 'runtime' => 'systemd',
        'runtime_config' => ['command' => ['php', 'artisan', 'queue:work']], 'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active']);
    $stop = outstandingEngineStop($this, $worker);

    expect(fn () => app(StartInstanceDestroyAction::class)->execute($shop, force: true))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('operation.target_busy'));
    expect(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse()
        ->and(Process::query()->whereKey($worker->id)->exists())->toBeTrue();

    // The removal admitted first owns the Process: a new engine stop of it does not bind.
    ($this->receipt)($stop, 'converge', ['status' => 'applied', 'changes' => 1]);
    Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => $shop->id, 'node_id' => $this->node->id,
        'status' => OperationStatus::Running, 'lock_key' => 'instance:'.$shop->id, 'input' => ['instance_id' => $shop->id]]);
    $again = ($this->operation)($worker, 'process.stop');
    expect(fn () => app(StartSwampRun::class)->start($again))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('operation.target_busy'));
});

it('ends an engine stop and frees its fence when a removal already deleted the Process row', function (): void {
    $process = ($this->process)();
    $operation = outstandingEngineStop($this, $process);
    Process::query()->whereKey($process->id)->delete();

    ($this->receipt)($operation, 'converge', ['status' => 'applied', 'changes' => 1]);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->lock_key)->toBeNull();
});

it('refuses AI-router collector reconvergence before any collector write while its Process is fenced', function (): void {
    $collector = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => AiRouterProcess::NAME,
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/local/bin/orbit-agent', 'ai-router']], 'restart_policy' => 'always',
        'desired_state' => 'running', 'status' => 'active']);
    AiRouterProcessSource::query()->create(['process_id' => $collector->id, 'source_revision' => 'old-revision', 'activation_pending' => false]);
    outstandingEngineStop($this, $collector);

    expect(fn () => app(NativeAiRouterRuntimeLifecycle::class)->converge($this->node, []))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('operation.target_busy'));
    $source = AiRouterProcessSource::query()->findOrFail($collector->id);
    expect($source->source_revision)->toBe('old-revision')
        ->and($source->activation_pending)->toBeFalse()
        ->and($collector->refresh()->systemd_activation_pending)->toBeFalse();
});

it('restarts a host unit with a read and a restart bound to the read run, and projects what a Swamp restart projects', function (): void {
    $process = ($this->process)();
    $operation = ($this->operation)($process, 'process.restart');
    app(StartSwampRun::class)->start($operation);
    expect(OperationEngineStep::query()->where('operation_id', $operation->id)->orderBy('id')->pluck('action', 'name')->all())
        ->toBe(['read' => 'process.read_instance', 'restart' => 'process.restart'])
        ->and($operation->refresh()->lock_key)->toBe('process:'.$process->id);

    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    $name = "orbit-process-{$process->id}-web.service";
    expect($this->bus->orders)->toHaveCount(1)
        ->and($this->bus->payload(0))->toMatchArray(['action' => 'process.read_instance', 'target' => "{$this->node->id}:process-{$process->id}",
            'operation' => "{$operation->id}:read", 'input' => ['process_id' => $process->id, 'runtime' => 'systemd', 'name' => $name]]);
    ($this->receipt)($operation, 'read', ['status' => 'output', 'stdout' => '{"instance":"aaa"}']);

    $restart = $this->bus->payload(1);
    expect($restart['action'])->toBe('process.restart')
        ->and($restart['operation'])->toBe("{$operation->id}:restart")
        ->and($restart['input'])->toBe(['process_id' => $process->id, 'runtime' => 'systemd', 'name' => $name, 'instance' => 'aaa', 'boot' => 'enabled']);

    // A lost receipt: the same step goes again with the same pinned run under a fresh nonce; the journal answers the first result.
    $this->travel(121)->seconds();
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id, 'step' => 'restart']);
    expect($this->bus->orders)->toHaveCount(3)
        ->and($this->bus->payload(2)['input'])->toBe($restart['input'])
        ->and($this->bus->payload(2)['operation'])->toBe($restart['operation'])
        ->and($this->bus->payload(2)['nonce'])->not->toBe($restart['nonce'])
        ->and($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->lock_key)->toBe('process:'.$process->id);
    ($this->receipt)($operation, 'restart', ['status' => 'output', 'stdout' => '{"restarted":"now","instance":"bbb"}', 'repeat' => true]);

    $swamp = ($this->operation)($other = ($this->process)(), 'process.restart');
    $swamp->forceFill(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => 'run-4']])->save();
    app(ProcessControlProjection::class)->apply($swamp, ['run_id' => 'run-4', 'started_at' => '2026-11-26T13:00:00.000Z', 'finished_at' => '2026-11-26T13:00:01.000Z',
        'status' => 'succeeded', 'error_code' => null]);
    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result)->toBe(['ready' => true, 'state' => 'active'])->toBe($swamp->refresh()->result)
        ->and($operation->lock_key)->toBeNull()
        ->and($process->refresh()->desired_state->value)->toBe('running')->toBe($other->refresh()->desired_state->value)
        ->and($process->status)->toBe(LifecycleStatus::Active)->toBe($other->status)
        ->and($this->bus->orders)->toHaveCount(3);
});

it('pins the container run and keeps an on-demand unit disabled at boot on a restart', function (): void {
    $run = fn (Operation $operation): EngineRun => new EngineRun($operation->id, ['read' => new OperationEngineStep(['name' => 'read', 'output' => '{"instance":"c1@T1"}'])], 0, null);
    $container = ($this->operation)($docker = ($this->process)(runtime: 'docker'), 'process.restart');
    $unit = ($this->operation)(($this->process)(), 'process.restart', ['on_demand' => true]);

    expect(app(ProcessControlEngineFlow::class)->input($container, 'restart', $run($container)))
        ->toBe(['process_id' => $docker->id, 'runtime' => 'docker', 'name' => "orbit-process-{$docker->id}-web", 'instance' => 'c1@T1', 'image' => 'redis:7'])
        ->and(app(ProcessControlEngineFlow::class)->input($unit, 'restart', $run($unit)))->toMatchArray(['instance' => 'c1@T1', 'boot' => 'disabled'])
        ->and(fn () => app(ProcessControlEngineFlow::class)->input($unit, 'converge', $run($unit)))->toThrow(LogicException::class);
});

it('settles a restart step only on its evidence', function (string $step, string $stdout, bool $valid): void {
    expect(app(ProcessControlEngineFlow::class)->evidence(new Operation, $step, $stdout))->toBe($valid);
})->with([
    'read' => ['read', '{"instance":"aaa"}', true],
    'read of a stopped unit' => ['read', '{"instance":""}', true],
    'read without a run' => ['read', '{}', false],
    'restart now' => ['restart', '{"restarted":"now","instance":"bbb"}', true],
    'restart recovered after a lost result' => ['restart', '{"restarted":"recovered","instance":"bbb"}', true],
    'restart already' => ['restart', '{"restarted":"already","instance":"bbb"}', true],
    'restart to no run' => ['restart', '{"restarted":"now","instance":""}', false],
    'restart that says nothing' => ['restart', '{"instance":"bbb"}', false],
    'not JSON' => ['restart', 'ok', false],
]);

it('fails a restart whose receipt is not its evidence and keeps the Process failed as Swamp does', function (): void {
    $operation = ($this->operation)(($this->process)(), 'process.restart');
    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    ($this->receipt)($operation, 'read', ['status' => 'applied', 'changes' => 0]);

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and(OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', 'read')->sole()->error_code)->toBe('engine.receipt_invalid')
        ->and($this->bus->orders)->toHaveCount(1);
});

it('names the Swamp step each failed restart phase stands for', function (string $runtime, string $step, string $code, string $swampStep): void {
    $operation = ($this->operation)(($this->process)(runtime: $runtime), 'process.restart');
    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    if ($step === 'restart') {
        ($this->receipt)($operation, 'read', ['status' => 'output', 'stdout' => '{"instance":"aaa"}']);
    }
    ($this->receipt)($operation, $step, ['status' => 'failed', 'code' => $code]);

    expect($operation->refresh()->failed_step)->toBe($swampStep)
        ->and($operation->error_code)->toBe('operation.native_failed')
        ->and($operation->lock_key)->toBeNull();
})->with([
    'unit missing at the read' => ['systemd', 'read', 'process.unit_missing', 'Set boot policy'],
    'container missing at the read' => ['docker', 'read', 'process.container_missing', 'Restart the container'],
    'unit boot policy' => ['systemd', 'restart', 'process.boot_failed', 'Set boot policy'],
    'unit restart or its read before it' => ['systemd', 'restart', 'process.apply_failed', 'Restart the Process'],
    'interrupted attempt never resolved' => ['systemd', 'restart', 'process.restart_unresolved', 'Restart the Process'],
    'unit read-back' => ['systemd', 'restart', 'process.read_failed', 'Check the unit'],
    'unit still on the pinned run' => ['systemd', 'restart', 'process.restart_unverified', 'Verify the state'],
    'container restart' => ['docker', 'restart', 'process.apply_failed', 'Restart the container'],
    'container assert' => ['docker', 'restart', 'process.state_mismatch', 'Verify the state'],
]);

it('takes the whole Swamp path for a restart the engine does not cover or a Node without the restart actions', function (array $input, ?array $command = null): void {
    $operation = ($this->operation)(($this->process)($command ?? ['php', 'artisan', 'queue:work']), 'process.restart', $input);

    expect(fn () => app(ProcessControlEngineFlow::class)->steps($operation))->toThrow(EngineUnsupported::class)
        ->and(app(EngineKinds::class)->routes($operation))->toBeFalse();
})->with([
    'host Horizon (its stop waits for the drain)' => [[], ['php', 'artisan', 'horizon']],
    'Vite dev Process' => [['variant' => 'vite', 'vite' => ['path' => '/x', 'instance_id' => 3], 'vite_plan' => ['listener' => ['port' => 5173]]]],
    'production release layout' => [['directory' => '/home/orbit/apps/shop/current']],
    'unit in an Instance container' => [['container' => ['instance_id' => 12, 'project' => 'orbit-instances', 'name' => 'instance-12']]],
]);

it('routes a restart only when the kind is on and the Node reports both restart actions', function (): void {
    $operation = ($this->operation)(($this->process)(), 'process.restart');
    expect(app(EngineKinds::class)->routes($operation))->toBeTrue();

    // An agent from before the restart reports the process family's start and stop action only.
    $this->bus->actions = ['process.ensure_state', 'schedule.ensure_timer'];
    expect(app(EngineKinds::class)->routes($operation))->toBeFalse();
    $this->bus->actions = ['process.ensure_state', 'process.read_instance'];
    expect(app(EngineKinds::class)->routes($operation))->toBeFalse();
    $this->bus->actions = ['process.read_instance', 'process.restart'];
    config(['orbit.engine.kinds' => ['process.start', 'process.stop']]);
    expect(app(EngineKinds::class)->routes($operation))->toBeFalse();
});

it('fences a restarting Process against every other verb until its terminal receipt', function (): void {
    $process = ($this->process)();
    $operation = ($this->operation)($process, 'process.restart');
    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);

    expect(Engine::receiptBound('process.restart'))->toBeTrue()
        ->and(fn () => app(ProcessOperationAdmission::class)->execute($process, 'stop'))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('operation.target_busy'));
    ($this->receipt)($operation, 'read', ['status' => 'output', 'stdout' => '{"instance":"aaa"}']);
    ($this->receipt)($operation, 'restart', ['status' => 'output', 'stdout' => '{"restarted":"now","instance":"bbb"}']);
    expect($operation->refresh()->lock_key)->toBeNull();
    EngineTargetFence::free('process:'.$process->id);
});

it('settles restarts on the outputs the executor serializes, in any member order', function (string $runtime, string $answer): void {
    $outputs = json_decode((string) file_get_contents(base_path('../agent/contract/fixtures/executor-process-restart-output.json')), true, flags: JSON_THROW_ON_ERROR);
    $process = ($this->process)(runtime: $runtime);
    $operation = ($this->operation)($process, 'process.restart');
    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    ($this->receipt)($operation, 'read', ['status' => 'output', 'stdout' => $outputs['read']]);
    expect($this->bus->payload(1)['input']['instance'])->toBe('aaa');
    ($this->receipt)($operation, 'restart', ['status' => 'output', 'stdout' => $outputs[$answer]]);

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result)->toBe(['ready' => true, 'state' => $runtime === 'docker' ? 'running' : 'active'])
        ->and(app(ProcessControlEngineFlow::class)->evidence($operation, 'restart', json_encode(array_reverse(json_decode($outputs[$answer], true)), JSON_THROW_ON_ERROR)))->toBeTrue();
})->with([
    'unit now' => ['systemd', 'now'], 'unit already' => ['systemd', 'already'], 'unit recovered' => ['systemd', 'recovered'],
    'container now' => ['docker', 'now'], 'container already' => ['docker', 'already'], 'container recovered' => ['docker', 'recovered'],
]);
