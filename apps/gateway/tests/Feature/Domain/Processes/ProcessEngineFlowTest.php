<?php

declare(strict_types=1);

use App\Actions\Processes\DestroyProcessAction;
use App\Actions\Processes\StartProcessAction;
use App\Actions\Schedules\CreateScheduleAction;
use App\Data\Schedules\AddScheduleData;
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
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Models\Process;
use App\Models\Project;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/** A machine bus that keeps what the engine publishes and reports the actions a test gives the Node. */
final class ProcessEngineBus implements MachineBus
{
    /** @var list<SignedExecutorOrder> */
    public array $orders = [];

    /** @var list<int> */
    public array $starts = [];

    /** @var list<string> */
    public array $actions = ['process.ensure_state', 'schedule.ensure_timer'];

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
        'orbit.engine.kinds' => ['process.start', 'process.stop', 'schedule.enable']]);
    $this->bus = new ProcessEngineBus;
    $this->app->instance(MachineBus::class, $this->bus);
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'app-dev', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
    ]));
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

it('gates the process family on the agent version this tree builds', function (): void {
    preg_match('/^\[package\]\nname = "orbit-agent"\nversion = "([0-9.]+)"/m', (string) file_get_contents(base_path('../agent/Cargo.toml')), $built);

    expect(NodeExecutorInput::FamilySince['process'])->toBe($built[1] ?? null);
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
