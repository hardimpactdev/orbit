<?php

declare(strict_types=1);

use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\Power\AgentActivity;
use App\Domain\Instances\Power\ContainerIdleDecision;
use App\Domain\Instances\Power\ContainerIdleSweep;
use App\Domain\Instances\Power\InstanceAgentActivity;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\NodeFileTimes;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectType;
use App\Infrastructure\Instances\AgentNodeFileTimes;
use App\Models\Instance;
use App\Models\InstanceNodeServiceTarget;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Domain\ConnAgentActivity;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

const IdleWindow = 3_600;

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->now = CarbonImmutable::parse('2026-11-14 12:00:00');
    CarbonImmutable::setTestNow($this->now);
    $node = containerDefaultNode();
    $this->project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $this->default = Instance::query()->create(['node_id' => $node->id, 'project_id' => $this->project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($this->default, $node)->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
    $this->node = $node;
    // The fakes: each Instance's access-log time, and each Instance's T3 answer.
    $this->logs = [];
    $this->reads = 0;
    $this->files = new class($this) implements NodeFileTimes
    {
        public function __construct(private object $test) {}

        public function read(Node $node, array $paths): ?array
        {
            $this->test->reads++;
            if ($this->test->logs === null) {
                return null;
            }

            return array_combine($paths, array_map(fn (string $path): ?int => $this->test->logs[$path] ?? null, $paths));
        }
    };
    $this->agent = [];
    $this->agentReads = [];
    $this->agents = new class($this) implements InstanceAgentActivity
    {
        public function __construct(private object $test) {}

        public function read(Instance $instance): ?AgentActivity
        {
            $this->test->agentReads[] = $instance->id;

            return $this->test->agent[$instance->id] ?? AgentActivity::read(false, null);
        }
    };
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A running development clone whose access log is `$logAge` seconds old (null: no log) and whose watch began `$watchAge` seconds ago (null: never). */
function idleClone(object $test, string $name, ?int $logAge = 7_200, bool $taskOwned = false, ?int $watchAge = 7_200): Instance
{
    $owner = $taskOwned ? Task::query()->forceCreate(['project_id' => $test->project->id, 'title' => 'task', 'brief' => 'Fixture task.', 'status' => 'running'])->id : null;
    $clone = Instance::query()->forceCreate(['node_id' => $test->node->id, 'project_id' => $test->project->id, 'name' => $name, 'task_workspace_owner_id' => $owner,
        'checkout_path' => '/workspace/'.$name, 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($clone, $test->node, $test->default)->update(['power_state' => 'running',
        'idle_watch_started_at' => $watchAge === null ? null : $test->now->subSeconds($watchAge)]);
    if ($logAge !== null) {
        $test->logs[RuntimeHibernation::accessLogPath(RuntimeHibernation::key($clone->id))] = $test->now->getTimestamp() - $logAge;
    }

    return $clone->refresh();
}

/** The Instance uses a Node service, as 606 and 608 use PowerSync on another Node. */
function attachNodeService(Instance $clone): void
{
    $process = Process::query()->forceCreate(['owner_type' => 'node', 'owner_id' => $clone->node_id, 'name' => 'powersync', 'runtime' => 'docker',
        'working_directory' => '/srv', 'status' => 'active', 'runtime_config' => ['image' => 'journeyapps/powersync-service']]);
    InstanceNodeServiceTarget::query()->create(['instance_id' => $clone->id, 'process_id' => $process->id, 'node_id' => $clone->node_id,
        'protocol' => 'tcp', 'port' => 8096, 'host_env_key' => 'POWERSYNC_HOST', 'port_env_key' => 'POWERSYNC_PORT']);
}

/** @param list<int> $neverSleep */
function idleSweep(object $test, array $neverSleep = [], ?array $agents = null): ContainerIdleSweep
{
    return new ContainerIdleSweep($test->files, app(InstanceSleep::class), $agents ?? [$test->agents], IdleWindow, 5, $neverSleep);
}

/** @param list<ContainerIdleDecision> $decisions */
function reasonOf(array $decisions, Instance $instance): string
{
    $decision = collect($decisions)->firstWhere('instanceId', $instance->id);

    return $decision->decision === 'sleep' ? 'sleep' : $decision->reason;
}

it('puts an idle clone to sleep as an Operation that names the sweep and says T3 was idle', function (): void {
    $clone = idleClone($this, 'idle');

    $decisions = idleSweep($this)->run(false);

    $sleep = Operation::query()->where('kind', InstanceSleep::Kind)->sole();
    expect(reasonOf($decisions, $clone))->toBe('sleep')
        ->and(reasonOf($decisions, $this->default))->toBe('default')
        ->and($sleep->target_id)->toBe($clone->id)
        ->and($sleep->triggered_by)->toBe(ContainerIdleSweep::TriggeredBy)
        ->and(collect($decisions)->firstWhere('instanceId', $clone->id)->operationId)->toBe($sleep->id);

    $sleep->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'idle-proof']])->save();
    $done = app(DirectOperation::class)->projection($sleep)->apply($sleep->refresh(), ['status' => 'succeeded', 'run_id' => 'idle-proof',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['sleep' => 'frozen']]);
    expect($done->result)->toBe(['power_state' => 'stopped', 't3_turn' => 'idle', 'sleep' => 'frozen']);
});

it('changes nothing in a dry run, and lists every container Instance with its reason', function (): void {
    $idle = idleClone($this, 'idle');
    $fresh = idleClone($this, 'fresh', logAge: 60);

    $decisions = idleSweep($this)->run(true);

    expect(Operation::query()->count())->toBe(0)
        ->and(collect($decisions)->pluck('instanceId')->all())->toBe([$this->default->id, $idle->id, $fresh->id])
        ->and(reasonOf($decisions, $idle))->toBe('sleep')
        ->and(collect($decisions)->firstWhere('instanceId', $idle->id)->operationId)->toBeNull()
        ->and(reasonOf($decisions, $fresh))->toBe('http_active');
});

it('keeps awake an Instance that must never sleep, before any read of the Node or T3', function (string $case, string $reason): void {
    $clone = idleClone($this, 'clone', taskOwned: $case === 'task');
    $never = [];
    match ($case) {
        'nested' => $this->project->forceFill(['nested_fleet' => true])->save(),
        'never' => $never = [$clone->id],
        'asleep' => $clone->runtime()->update(['power_state' => 'stopped']),
        'operation' => Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $clone->id,
            'node_id' => $clone->node_id, 'lock_key' => OperationStore::lockKey('instance', $clone->id), 'status' => 'running', 'input' => [], 'state' => []]),
        'process_operation' => Operation::query()->create(['kind' => 'process.restart', 'target_type' => 'process', 'node_id' => $clone->node_id,
            'target_id' => Process::query()->forceCreate(['owner_type' => 'instance', 'owner_id' => $clone->id, 'name' => 'worker', 'runtime' => 'systemd',
                'working_directory' => '/srv/workspace', 'status' => 'active', 'runtime_config' => ['command' => ['/bin/true']]])->id,
            'status' => 'running', 'input' => [], 'state' => []]),
        'lifecycle' => $clone->update(['status' => 'checkout_prepared']),
        'production' => $this->node->roles()->update(['role' => RoleName::AppProd]),
        'task' => null,
        'keep_alive' => Process::query()->forceCreate(['owner_type' => 'instance', 'owner_id' => $clone->id, 'name' => 'queue', 'runtime' => 'systemd',
            'working_directory' => '/srv/workspace', 'status' => 'active', 'keep_alive' => true, 'desired_state' => 'running',
            'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'queue:work']]]),
        'node_service' => attachNodeService($clone),
    };

    $decisions = idleSweep($this, $never)->run(false);

    expect(reasonOf($decisions, $clone))->toBe($reason)
        ->and(Operation::query()->where('kind', InstanceSleep::Kind)->count())->toBe(0)
        ->and($this->agentReads)->toBe([])
        ->and($this->reads)->toBe(0);
})->with([
    ['production', 'not_development'], ['nested', 'nested_fleet'], ['never', 'never_sleep'], ['asleep', 'asleep'], ['operation', 'busy'], ['process_operation', 'busy'],
    ['lifecycle', 'busy'], ['task', 'task'], ['keep_alive', 'keep_alive'], ['node_service', 'node_service'],
]);

it('lets a stopped keep-alive Process not hold the container awake', function (): void {
    $clone = idleClone($this, 'clone');
    Process::query()->forceCreate(['owner_type' => 'instance', 'owner_id' => $clone->id, 'name' => 'queue', 'runtime' => 'systemd',
        'working_directory' => '/srv/workspace', 'status' => 'active', 'keep_alive' => true, 'desired_state' => 'stopped',
        'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'queue:work']]]);

    expect(reasonOf(idleSweep($this)->run(true), $clone))->toBe('sleep');
});

it('keeps awake an Instance without a full window of HTTP evidence', function (?int $logAge, ?int $watchAge, bool $nodeAnswers, string $reason): void {
    $clone = idleClone($this, 'clone', $logAge, watchAge: $watchAge);
    if (! $nodeAnswers) {
        $this->logs = null;
    }

    $decisions = idleSweep($this)->run(false);

    expect(reasonOf($decisions, $clone))->toBe($reason)
        ->and(Operation::query()->count())->toBe(0);
})->with([
    // A log written before the watch began says nothing about the time since: the old file of a migrated Instance.
    'an old log, never watched' => [86_400, null, true, 'watch_starting'],
    'an old log, watched for less than the window' => [86_400, IdleWindow - 1, true, 'watching'],
    'no log, watched for less than the window' => [null, IdleWindow - 1, true, 'watching'],
    'a log younger than the window' => [IdleWindow - 1, 7_200, true, 'http_active'],
    'the Node does not answer' => [7_200, 7_200, false, 'http_unreadable'],
]);

it('starts the watch in an acting run only, and lets a clone without a Route sleep after one watched window', function (): void {
    $clone = idleClone($this, 'routeless', logAge: null, watchAge: null);

    expect(reasonOf(idleSweep($this)->run(true), $clone))->toBe('watch_starting')
        ->and($clone->runtime()->firstOrFail()->idle_watch_started_at)->toBeNull();

    expect(reasonOf(idleSweep($this)->run(false), $clone))->toBe('watch_starting')
        ->and($clone->runtime()->firstOrFail()->idle_watch_started_at?->getTimestamp())->toBe($this->now->getTimestamp());

    // A second acting run keeps the first start.
    CarbonImmutable::setTestNow($this->now->addSeconds(IdleWindow - 1));
    expect(reasonOf(idleSweep($this)->run(false, now: CarbonImmutable::now()), $clone))->toBe('watching')
        ->and($clone->runtime()->firstOrFail()->idle_watch_started_at?->getTimestamp())->toBe($this->now->getTimestamp());

    expect(reasonOf(idleSweep($this)->run(false, now: $this->now->addSeconds(IdleWindow)), $clone))->toBe('sleep');
});

it('starts no watch for an Instance it could not read or that must never sleep', function (string $case, string $reason): void {
    $clone = idleClone($this, 'clone', watchAge: null);
    $case === 'node silent' ? $this->logs = null : $clone->runtime()->update(['power_state' => 'stopped']);

    expect(reasonOf(idleSweep($this)->run(false), $clone))->toBe($reason)
        ->and($clone->runtime()->firstOrFail()->idle_watch_started_at)->toBeNull();
})->with([['node silent', 'http_unreadable'], ['asleep', 'asleep']]);

it('keeps the watch and the wake rules in a scoped run with a short window', function (?int $wokeAgo, int $watchAge, string $reason): void {
    $clone = idleClone($this, 'clone', logAge: 7_200, watchAge: $watchAge);
    if ($wokeAgo !== null) {
        Operation::query()->create(['kind' => 'instance.wake', 'target_type' => 'instance', 'target_id' => $clone->id, 'node_id' => $clone->node_id,
            'status' => 'succeeded', 'input' => [], 'state' => [], 'finished_at' => $this->now->subSeconds($wokeAgo)]);
    }

    expect(reasonOf(idleSweep($this)->run(false, [$clone->id], 120), $clone))->toBe($reason);
})->with([
    'watched for less than the short window' => [null, 60, 'watching'],
    'woken within the short window' => [60, 7_200, 'woke_recently'],
    'neither' => [7_200, 7_200, 'sleep'],
]);

it('gives each decision the evidence it used', function (): void {
    $clone = idleClone($this, 'clone', logAge: 7_000, watchAge: 9_000);
    $this->agent[$clone->id] = AgentActivity::read(false, $this->now->subSeconds(5_000)->getTimestamp());
    Operation::query()->create(['kind' => 'instance.wake', 'target_type' => 'instance', 'target_id' => $clone->id, 'node_id' => $clone->node_id,
        'status' => 'succeeded', 'input' => [], 'state' => [], 'finished_at' => $this->now->subSeconds(8_000)]);

    $decision = collect(idleSweep($this)->run(true))->firstWhere('instanceId', $clone->id);

    expect($decision->decision)->toBe('sleep')
        ->and($decision->watchStartedAt)->toBe($this->now->getTimestamp() - 9_000)
        ->and($decision->lastHttpAt)->toBe($this->now->getTimestamp() - 7_000)
        ->and($decision->t3?->lastActivityUnix)->toBe($this->now->getTimestamp() - 5_000)
        ->and($decision->lastWakeAt)->toBe($this->now->getTimestamp() - 8_000);
});

it('reads the access logs of a Node in one call', function (): void {
    idleClone($this, 'one');
    idleClone($this, 'two');
    idleClone($this, 'three', logAge: 10);

    idleSweep($this)->run(true);

    expect($this->reads)->toBe(1);
});

it('gives a woken Instance a full window, then lets it sleep', function (int $wokeAgo, string $reason): void {
    $clone = idleClone($this, 'clone');
    Operation::query()->create(['kind' => 'instance.wake', 'target_type' => 'instance', 'target_id' => $clone->id, 'node_id' => $clone->node_id,
        'status' => 'succeeded', 'input' => [], 'state' => [], 'finished_at' => $this->now->subSeconds($wokeAgo)]);

    expect(reasonOf(idleSweep($this)->run(true), $clone))->toBe($reason);
})->with([[IdleWindow - 60, 'woke_recently'], [IdleWindow + 60, 'sleep']]);

it('never puts to sleep an Instance whose turn runs, whatever its age, or whose T3 cannot be read', function (AgentActivity $activity, string $reason): void {
    $clone = idleClone($this, 'clone');
    $this->agent[$clone->id] = $activity;

    expect(reasonOf(idleSweep($this)->run(false), $clone))->toBe($reason)
        ->and(Operation::query()->count())->toBe(0);
})->with([
    'a turn runs, started long ago' => [fn () => AgentActivity::read(true, CarbonImmutable::parse('2026-11-14 01:00:00')->getTimestamp()), 't3_turn_running'],
    'unreadable' => [fn () => AgentActivity::unreadable(), 't3_unreadable'],
    'a user message inside the window' => [fn () => AgentActivity::read(false, CarbonImmutable::parse('2026-11-14 11:30:00')->getTimestamp()), 't3_active'],
]);

it('lets an Instance sleep whose last T3 activity is older than the window', function (): void {
    $clone = idleClone($this, 'clone');
    $this->agent[$clone->id] = AgentActivity::read(false, $this->now->subSeconds(IdleWindow + 1)->getTimestamp());

    expect(reasonOf(idleSweep($this)->run(true), $clone))->toBe('sleep');
});

it('puts no Instance to sleep without an agent activity contribution, as while Conn is off', function (): void {
    $clone = idleClone($this, 'clone');

    expect(reasonOf(idleSweep($this, agents: [])->run(false), $clone))->toBe('t3_unreadable')
        ->and(Operation::query()->count())->toBe(0);
});

it('admits at most five sleeps per run and leaves the rest for the next run', function (): void {
    $clones = collect(range(1, 7))->map(fn (int $index): Instance => idleClone($this, 'idle-'.$index));

    $decisions = idleSweep($this)->run(false);

    expect(Operation::query()->where('kind', InstanceSleep::Kind)->count())->toBe(5)
        ->and($clones->map(fn (Instance $clone): string => reasonOf($decisions, $clone))->all())
        ->toBe(['sleep', 'sleep', 'sleep', 'sleep', 'sleep', 'next_run', 'next_run']);
});

it('considers only the named Instances, with the window of the run', function (): void {
    $named = idleClone($this, 'named', logAge: 200);
    $other = idleClone($this, 'other');

    $decisions = idleSweep($this)->run(false, [$named->id], 120);

    expect(collect($decisions)->pluck('instanceId')->all())->toBe([$named->id])
        ->and(Operation::query()->where('kind', InstanceSleep::Kind)->sole()->target_id)->toBe($named->id)
        ->and($other->refresh()->status->value)->toBe('active');
});

/** The proof path: a named run with a short window may only pick candidates and shorten the window; it skips no rule. */
it('applies every never-sleep rule in a scoped run with a short window', function (string $case, string $reason): void {
    $clone = idleClone($this, 'clone', logAge: 200, taskOwned: $case === 'task', watchAge: $case === 'never_watched' ? null : 7_200);
    $target = $case === 'default' ? $this->default : $clone;
    $this->logs[RuntimeHibernation::accessLogPath(RuntimeHibernation::key($this->default->id))] = $this->now->getTimestamp() - 200;
    $never = [];
    match ($case) {
        'default', 'task', 'never_watched' => null,
        'node_service' => attachNodeService($clone),
        'nested' => $this->project->forceFill(['nested_fleet' => true])->save(),
        'never' => $never = [$clone->id],
        'operation' => Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $clone->id,
            'node_id' => $clone->node_id, 'lock_key' => OperationStore::lockKey('instance', $clone->id), 'status' => 'running', 'input' => [], 'state' => []]),
        'keep_alive' => Process::query()->forceCreate(['owner_type' => 'instance', 'owner_id' => $clone->id, 'name' => 'queue', 'runtime' => 'systemd',
            'working_directory' => '/srv/workspace', 'status' => 'active', 'keep_alive' => true, 'desired_state' => 'running',
            'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'queue:work']]]),
        'turn_runs' => $this->agent[$clone->id] = AgentActivity::read(true, null),
        'user_waits' => $this->agent[$clone->id] = AgentActivity::read(false, $this->now->subSeconds(60)->getTimestamp()),
        'unreadable' => $this->agent[$clone->id] = AgentActivity::unreadable(),
    };

    $decisions = idleSweep($this, $never)->run(false, [$target->id], 120);

    expect(collect($decisions)->pluck('instanceId')->all())->toBe([$target->id])
        ->and(reasonOf($decisions, $target))->toBe($reason)
        ->and(Operation::query()->where('kind', InstanceSleep::Kind)->count())->toBe(0);
})->with([
    ['default', 'default'], ['nested', 'nested_fleet'], ['never', 'never_sleep'], ['operation', 'busy'], ['task', 'task'],
    ['keep_alive', 'keep_alive'], ['turn_runs', 't3_turn_running'], ['user_waits', 't3_active'], ['unreadable', 't3_unreadable'],
    ['never_watched', 'watch_starting'], ['node_service', 'node_service'],
]);

it('wires the Conn read of T3 and the Node agent read of the logs into the sweep', function (): void {
    expect(app(NodeFileTimes::class))->toBeInstanceOf(AgentNodeFileTimes::class)
        ->and(collect(app()->tagged(InstanceAgentActivity::class))->map(fn (object $agent): string => $agent::class)->all())
        ->toBe([ConnAgentActivity::class])
        ->and(app(ContainerIdleSweep::class))->toBeInstanceOf(ContainerIdleSweep::class);
});
