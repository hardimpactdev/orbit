<?php

declare(strict_types=1);

use App\Actions\Tasks\ReadTaskSwampRunsAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\SwampRunReader;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Infrastructure\Swamp\SwampHandshakes;
use App\Infrastructure\Tasks\WebSocketSwampRunReader;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\HandshakeCountingServe;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../../Domain/Instances/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    app(ExtensionStore::class)->set('conn', true);
    app()->instance(ContainerModelRoute::class, new class implements ContainerModelRoute
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
    workspaceReader();
    config(['swamp.run_token' => 'run-token', 'swamp.read_token' => 'read-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
});

/** The Project's seeded container default on a Node, as its finished create leaves it. */
function handshakeDefault(Node $node, string $slug): Instance
{
    Http::fake(['api.github.com/repos/nckrtl/'.$slug => Http::response(['full_name' => 'nckrtl/'.$slug, 'private' => false])]);
    $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => ProjectType::NodePackage,
        'repository_url' => 'https://github.com/nckrtl/'.$slug.'.git', 'default_branch' => 'main', 'root' => '.', 'is_laravel' => false, 'source_facts_captured_at' => now()]);
    $default = Instance::query()->findOrFail(containerCreate($project, $node)->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
        ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);

    return $default->refresh();
}

/** Runs every start job queued so far that is due now, once; returns the Operations it ran. */
function runQueuedStarts(array &$ran): void
{
    foreach (Queue::pushedJobs()[StartAdmittedSwampRun::class] ?? [] as $index => $pushed) {
        $delay = $pushed['job']->delay;
        if (isset($ran[$index]) || ($delay instanceof DateTimeInterface && $delay->getTimestamp() > now()->getTimestamp())) {
            continue;
        }
        $ran[$index] = true;
        try {
            app()->call([$pushed['job'], 'handle']);
        } catch (ResourceOperationException) {
            // A start that fails for good throws after recording its failure, as the queue worker sees it.
        }
    }
}

it('starts a clone create and its completion on one serve handshake each', function (): void {
    $node = containerDefaultNode();
    $default = handshakeDefault($node, 'one');
    $serve = new HandshakeCountingServe;
    app()->instance(WebSocketClient::class, $serve);
    $ran = [];
    runQueuedStarts($ran);
    $serve->connects = [];
    $create = containerCreate($default->project, $node, 'feature');

    $admitted = $serve->total();
    runQueuedStarts($ran);
    $createStart = $serve->total() - $admitted;

    $create->refresh()->forceFill(['status' => 'running', 'finished_at' => null, 'state' => [...$create->state, 'swamp_run_id' => 'run-1']])->save();
    $before = $serve->total();
    app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), [
        'status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['ready' => true, 'instance_id' => $create->target_id, 'commit' => str_repeat('c', 40), 't3' => [
            'environment_id' => 'env-feature', 'server_version' => '0.1.7', 'expires_at' => '2026-12-10T10:00:00.000Z',
            'gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-gateway-1-token'],
            'swamp_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-swamp-1-token']]]]);
    $completionAdmitted = $serve->total() - $before;
    $completion = Operation::query()->findOrFail($create->refresh()->result['completion_operation_id']);
    $before = $serve->total();
    runQueuedStarts($ran);
    $completionStart = $serve->total() - $before;

    // Before one start shared one connection: 6 for the create's start (five copies and the run), 1 and 2 for its completion.
    expect($completion->refresh()->status)->toBe(OperationStatus::Running)
        ->and([$admitted, $createStart, $completionAdmitted, $completionStart])->toBe([0, 1, 1, 1])
        ->and($serve->connects)->toBe(['run-token' => 3]);
});

it('starts eight clone creates and their completions inside one serve window with no 429 and no deferral', function (): void {
    $node = containerDefaultNode();
    $defaults = array_map(fn (int $index): Instance => handshakeDefault($node, 'tool-'.$index), range(1, 8));
    $serve = new HandshakeCountingServe;
    app()->instance(WebSocketClient::class, $serve);
    $ran = [];
    runQueuedStarts($ran);
    $this->travel(HandshakeCountingServe::WindowSeconds + 1)->seconds();
    $serve->connects = [];

    // Eight clones admitted together, as Conn or a proof starts them: before one start shared one connection, each create cost six
    // handshakes and each completion three, so the eight passed serve's 50 within the minute.
    $creates = array_map(fn (Instance $default): Operation => containerCreate($default->project, $node, 'feature'), $defaults);
    runQueuedStarts($ran);
    foreach ($creates as $create) {
        $create->refresh()->forceFill(['state' => [...$create->state, 'swamp_run_id' => 'run-'.$create->id]])->save();
        app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), [
            'status' => 'succeeded', 'run_id' => 'run-'.$create->id, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
            'result' => ['ready' => true, 'instance_id' => $create->target_id, 'commit' => str_repeat('c', 40), 't3' => [
                'environment_id' => 'env-'.$create->id, 'server_version' => '0.1.7', 'expires_at' => '2026-12-10T10:00:00.000Z',
                'gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-gateway-1-token'],
                'swamp_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-swamp-1-token']]]]);
    }
    runQueuedStarts($ran);

    $completions = Operation::query()->where('kind', 'instance.complete')->get();
    expect($serve->refused)->toBe(0)
        ->and($serve->total())->toBe(3 * count($creates))
        ->and($completions)->toHaveCount(count($creates));
    foreach ([...$creates, ...$completions] as $operation) {
        // Each create started at once and has ended; each completion started at once and runs.
        expect($operation->refresh()->status)->toBe($operation->kind === 'instance.complete' ? OperationStatus::Running : OperationStatus::Succeeded)
            ->and($operation->state['swamp_run_id'])->toBeString()
            ->and($operation->state['start_deferrals'] ?? null)->toBeNull();
    }
});

it('pushes an admission copy again after serve\'s Retry-After instead of failing the admitted vault.sync', function (): void {
    Queue::fake([StartAdmittedSwampRun::class]);
    Sleep::fake(syncWithCarbon: true);
    $node = SwampNodeFixture::ensure();
    $serve = new HandshakeCountingServe;
    app()->instance(WebSocketClient::class, $serve);
    // Serve's window is already spent by other Gateway work: the next upgrade is refused for 37 more seconds.
    $burst = new WebSocketEndpoint('swamp.test', 443, 'swamp.orbit', '/', '/test/ca.pem', 'run-token');
    for ($index = 0; $index < HandshakeCountingServe::Limit; $index++) {
        $serve->connect($burst, 1);
    }
    $this->travel(23)->seconds();
    $sync = Operation::query()->create(['kind' => 'vault.sync', 'target_type' => 'vault', 'target_id' => $node->id,
        'node_id' => $node->id, 'input' => [], 'status' => OperationStatus::Queued]);

    // StartVaultOperationAction admits a sync like this: the start and its configuration copy in one transaction.
    DB::transaction(function () use ($sync): void {
        app(SwampDispatch::class)->start($sync);
        app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'vault-sync-'.$sync->id, '{"url":"https://vault.test"}');
    });

    Sleep::assertSleptTimes(1);
    expect($serve->refused)->toBe(1)
        ->and($sync->refresh()->status)->toBe(OperationStatus::Queued)
        ->and($sync->error_code)->toBeNull()
        ->and(collect($serve->frames)->where('type', 'vault.put')->pluck('payload.key')->all())->toBe(['vault-sync-'.$sync->id]);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
});

it('fails an admitted vault.sync when serve still refuses its copy, and never waits in a web request', function (bool $console, int $slept): void {
    Queue::fake([StartAdmittedSwampRun::class]);
    Sleep::fake(syncWithCarbon: true);
    $application = app();
    (fn () => $this->isRunningInConsole = $console)->call($application);
    $node = SwampNodeFixture::ensure();
    // Other Gateway work keeps spending every window, so the copy is refused again after its one wait.
    $serve = new HandshakeCountingServe(limit: 0, windowSeconds: 30);
    app()->instance(WebSocketClient::class, $serve);
    $sync = Operation::query()->create(['kind' => 'vault.sync', 'target_type' => 'vault', 'target_id' => $node->id,
        'node_id' => $node->id, 'input' => [], 'status' => OperationStatus::Queued]);

    try {
        expect(fn () => DB::transaction(function () use ($sync): void {
            app(SwampDispatch::class)->start($sync);
            app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'vault-sync-'.$sync->id, '{"url":"https://vault.test"}');
        }))->toThrow(ResourceOperationException::class);
    } finally {
        (fn () => $this->isRunningInConsole = null)->call($application);
    }

    Sleep::assertSleptTimes($slept);
    expect($sync->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($sync->error_code)->toBe('swamp.vault_sync_unavailable')
        ->and($serve->refused)->toBe(1 + $slept);
    Queue::assertNothingPushed();
})->with([
    'a queue worker waits once' => [true, 1],
    'a web request never waits' => [false, 0],
]);

/** Completes a clone create as its run's end does, so its completion is admitted. */
function handshakeFinishCreate(Operation $create): void
{
    $create->refresh()->forceFill(['state' => [...$create->state, 'swamp_run_id' => 'run-'.$create->id]])->save();
    app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), [
        'status' => 'succeeded', 'run_id' => 'run-'.$create->id, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['ready' => true, 'instance_id' => $create->target_id, 'commit' => str_repeat('c', 40), 't3' => [
            'environment_id' => 'env-'.$create->id, 'server_version' => '0.1.7', 'expires_at' => '2026-12-10T10:00:00.000Z',
            'gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-gateway-1-token'],
            'swamp_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-swamp-1-token']]]]);
}

it('starts clone creates and their completions at once after 46 screen reads in ten seconds', function (): void {
    $node = containerDefaultNode();
    $defaults = [handshakeDefault($node, 'reads-one'), handshakeDefault($node, 'reads-two')];
    $serve = new HandshakeCountingServe;
    $serve->answer = static fn (array $frame): ?array => $frame['type'] === 'workflow.run.search'
        ? ['type' => 'workflow.run.search', 'id' => $frame['id'], 'payload' => ['data' => ['results' => []], 'total' => 0]] : null;
    app()->instance(WebSocketClient::class, $serve);
    app()->instance(SwampRunReader::class, new WebSocketSwampRunReader($serve, 'swamp.test', 'read-token', '/test/ca.pem'));
    $ran = [];
    runQueuedStarts($ran);
    $this->travel(HandshakeCountingServe::WindowSeconds + 1)->seconds();
    $serve->connects = [];

    // A screen or a loop reads 46 task groups inside ten seconds, as the dependency loop of 15 November read 46 Projects.
    $answers = [];
    foreach (range(1, 46) as $index) {
        $group = Task::query()->create(['title' => 'Group '.$index, 'brief' => 'Read it', 'status' => TaskGroupStatus::Running]);
        $answers[] = app(ReadTaskSwampRunsAction::class)->execute($group)->availability;
        if ($index % 5 === 0) {
            $this->travel(1)->seconds();
        }
    }
    $reads = $serve->connects['read-token'] ?? 0;

    $creates = array_map(fn (Instance $default): Operation => containerCreate($default->project, $node, 'feature'), $defaults);
    runQueuedStarts($ran);
    array_map(handshakeFinishCreate(...), $creates);
    runQueuedStarts($ran);

    // Reads stop at the view share; the rest answer today's `unavailable` without touching serve. Before, all 46 connected and
    // the second create's completion met serve's 429 and was deferred.
    expect($reads)->toBe(SwampHandshakes::ViewShare)
        ->and(array_count_values($answers))->toBe(['available' => SwampHandshakes::ViewShare, 'unavailable' => 46 - SwampHandshakes::ViewShare])
        ->and($serve->refused)->toBe(0)
        ->and($serve->connects['run-token'])->toBe(6);
    foreach ([...$creates, ...Operation::query()->where('kind', 'instance.complete')->get()] as $operation) {
        expect($operation->refresh()->state['swamp_run_id'])->toBeString()
            ->and($operation->state['start_deferrals'] ?? null)->toBeNull();
    }
});
