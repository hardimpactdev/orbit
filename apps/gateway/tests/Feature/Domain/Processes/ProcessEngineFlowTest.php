<?php

declare(strict_types=1);

use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineKinds;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\MachineBus;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Nodes\NodeExecutorInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessControlEngineFlow;
use App\Domain\Schedules\ScheduleEnableEngineFlow;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Models\Process;
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
        'owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'web', 'runtime' => $runtime,
        'runtime_config' => $runtime === 'docker' ? ['image' => 'redis:7'] : ['command' => $command],
        'restart_policy' => 'always', 'desired_state' => 'stopped', 'status' => 'active']);
    $this->operation = function (Process $process, string $kind, array $input = []) {
        $name = $process->runtime->value === 'docker' ? "orbit-process-{$process->id}-web" : "orbit-process-{$process->id}-web.service";

        return Operation::query()->create(['kind' => $kind, 'target_type' => 'process', 'target_id' => $process->id, 'node_id' => $this->node->id,
            'status' => OperationStatus::Queued, 'lock_key' => 'process:'.$process->id,
            'input' => [...['process_id' => $process->id, 'node_id' => $this->node->id, 'name' => $name, 'runtime' => $process->runtime->value,
                'variant' => $process->runtime->value, 'desired' => 'stopped', 'on_demand' => false, 'created' => false, 'preserve_record' => false,
                'caller_node_id' => null, 'vite' => null], ...($process->runtime->value === 'docker' ? ['image' => 'redis:7'] : []), ...$input]]);
    };
    $this->receipt = fn (Operation $operation, string $step, array $line) => app(Engine::class)->onReceipt([
        'node' => $this->node->id, 'line' => ['operation' => "{$operation->id}:{$step}", ...$line]]);
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

    // A lost receipt: the step goes again with a fresh nonce and the same step ID; the executor's journal answers the stored result.
    ($this->receipt)($operation, 'converge', ['status' => 'refused', 'reason' => 'expired']);
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

    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('process.state_mismatch')
        ->and($operation->result)->toBe(['error_code' => 'process.state_mismatch'])
        ->and($process->refresh()->status)->toBe(LifecycleStatus::Failed)
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

it('reaches a unit in an Instance container by its saved Incus identity and sends the app account as content', function (): void {
    $process = ($this->process)();
    $operation = ($this->operation)($process, 'process.start', ['on_demand' => true, 'container' => ['instance_id' => 12, 'project' => 'orbit-instances', 'name' => 'instance-12',
        'app_account' => ['path' => '/usr/local/libexec/orbit-app-user', 'content' => "#!/bin/sh\n"]]]);

    expect(($this->input)($operation))->toMatchArray(['boot' => 'enabled', 'container' => ['project' => 'orbit-instances', 'name' => 'instance-12',
        'account_path' => '/usr/local/libexec/orbit-app-user', 'account' => ['blob' => ['content' => "#!/bin/sh\n"]]]]);

    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    expect($this->bus->payload(0)['input']['container']['account'])->toBe(['blob' => ['sha256' => hash('sha256', "#!/bin/sh\n")]]);
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
    'container without the app account' => [['container' => ['instance_id' => 12, 'project' => 'orbit-instances', 'name' => 'instance-12']]],
    'container named for another Instance' => [['container' => ['instance_id' => 12, 'project' => 'orbit-instances', 'name' => 'instance-13',
        'app_account' => ['path' => '/p', 'content' => 'x']]]],
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

it('enables a host Node timer with one ensure_timer step and records the Schedule result Swamp records', function (): void {
    $schedule = Schedule::query()->create(['target_type' => Node::class, 'target_id' => $this->node->id, 'host_node_id' => $this->node->id,
        'name' => 'nightly', 'calendar' => '*-*-* 03:00:00', 'command' => 'true', 'timeout_seconds' => 60,
        'desired_timer_state' => 'enabled', 'status' => 'active', 'trigger' => 'node']);
    $operation = Operation::query()->create(['kind' => 'schedule.enable', 'target_type' => 'node_schedules', 'target_id' => $this->node->id, 'node_id' => $this->node->id,
        'status' => OperationStatus::Queued, 'lock_key' => 'node_schedules:'.$this->node->id, 'input' => ['id' => $schedule->id, 'node_id' => $this->node->id]]);

    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    $payload = $this->bus->payload(0);
    expect($payload['action'])->toBe('schedule.ensure_timer')
        ->and($payload['target'])->toBe("{$this->node->id}:schedule-{$schedule->id}")
        ->and($payload['input'])->toBe(['schedule_id' => $schedule->id, 'unit' => "orbit-schedule-{$schedule->id}.timer"]);

    app(Engine::class)->onReceipt(['node' => $this->node->id, 'line' => ['operation' => "{$operation->id}:timer", 'status' => 'output',
        'stdout' => '{"changes":0,"unit_file_state":"enabled","active_state":"active","next_elapse":"Thu 2026-11-26 03:00:00 UTC"}']]);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result)->toBe(['ready' => true]);

    foreach ([['production_control' => ['instance_id' => 3, 'release' => 'current']], ['container' => ['instance_id' => 3]]] as $extra) {
        $other = $operation->replicate()->forceFill(['input' => [...$operation->input, ...$extra]]);
        expect(app(EngineKinds::class)->routes($other))->toBeFalse();
    }
    $schedule->update(['trigger' => 'swamp']);
    expect(app(EngineKinds::class)->routes($operation->replicate()))->toBeFalse();
});

it('enables the process family on app-dev only, from the agent that knows it', function (): void {
    $intent = fn (string $version, RoleName $role): ?array => NodeExecutorInput::intent('7', 'orbit', 'executor', [$role],
        ['executor' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/x']], ['key'], $version);

    expect($intent('0.31.0', RoleName::AppDev)['enabled'])->toBe("firewall\ninstance\npower\nprocess\nproof\nroute\n")
        ->and($intent('0.30.0', RoleName::AppDev)['enabled'])->toBe("firewall\ninstance\npower\nproof\nroute\n")
        ->and($intent('0.31.0', RoleName::AppProd))->toBeNull();
});
