<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\Power\ContainerIdleSweep;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\NodeFileTimes;
use App\Domain\Projects\ProjectType;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Domain\ConnAgentActivity;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/../../Domain/Instances/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    app(ExtensionStore::class)->set('conn', true);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-14 12:00:00'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @param array<string, mixed> $thread */
function shellThread(array $thread): array
{
    return ['id' => 't1', 'status' => 'completed', 'activeRunId' => null, 'pendingRuntimeRequest' => null,
        'latestUserMessageAt' => null, 'latestRunCompletedAt' => null, ...$thread];
}

it('reads a turn as running in every working state, at any age', function (array $thread, bool $archived = false): void {
    $activity = ConnAgentActivity::fromShell($archived ? ['threads' => [], 'archivedThreads' => [shellThread($thread)]]
        : ['threads' => [shellThread($thread)], 'archivedThreads' => []]);

    expect($activity->readable)->toBeTrue()->and($activity->turnRuns)->toBeTrue();
})->with([
    'preparing' => [['status' => 'preparing']], 'queued' => [['status' => 'queued']], 'starting' => [['status' => 'starting']],
    'running' => [['status' => 'running', 'latestUserMessageAt' => '2026-11-10T08:00:00.000Z']],
    'activity running on an idle shell status' => [['status' => 'idle', 'activityRunStatus' => 'running']],
    'in an archived thread' => [['status' => 'running'], true],
    // A run that waits for its own work is not waiting for the user: it may wait for hours and is still working.
    'waits for a delegated thread' => [['status' => 'waiting', 'blockedOnThreadWait' => true, 'latestRunStartedAt' => '2026-11-14T08:00:00.000Z']],
    'waits without a request' => [['status' => 'waiting', 'latestRunStartedAt' => '2026-11-14T08:00:00.000Z']],
    'waits past an async question' => [['status' => 'waiting', 'pendingRuntimeRequest' => ['id' => 'r', 'kind' => 'question',
        'createdAt' => '2026-11-14T08:00:00.000Z', 'blocking' => false]]],
    'a background task runs' => [['status' => 'completed', 'pendingBackgroundTasks' => [['id' => 'b1']]]],
]);

it('takes the newest user message, turn end or waiting request as the last activity', function (): void {
    $activity = ConnAgentActivity::fromShell(['threads' => [
        shellThread(['latestUserAuthoredMessageAt' => '2026-11-14T09:00:00.000Z', 'latestUserMessageAt' => '2026-11-14T11:59:00.000Z',
            'latestRunCompletedAt' => '2026-11-14T09:05:00.000Z']),
        shellThread(['status' => 'waiting', 'pendingRuntimeRequest' => ['id' => 'r', 'kind' => 'approval', 'createdAt' => '2026-11-14T10:00:00.000Z']]),
    ], 'archivedThreads' => []]);

    // A wake message moves latestUserMessageAt; the user-authored time wins when T3 gives it.
    expect($activity->turnRuns)->toBeFalse()
        ->and($activity->lastActivityUnix)->toBe(CarbonImmutable::parse('2026-11-14T10:00:00Z')->getTimestamp());
});

it('reads a blocking request that waits for the user as no running turn, dated by its request', function (): void {
    $activity = ConnAgentActivity::fromShell(['threads' => [shellThread(['status' => 'waiting', 'activityRunStatus' => 'waiting',
        'pendingBackgroundTasks' => [], 'blockedOnThreadWait' => false,
        'pendingRuntimeRequest' => ['id' => 'r', 'kind' => 'approval', 'createdAt' => '2026-11-14T08:00:00.000Z', 'blocking' => true]])]]);

    expect($activity->turnRuns)->toBeFalse()
        ->and($activity->lastActivityUnix)->toBe(CarbonImmutable::parse('2026-11-14T08:00:00Z')->getTimestamp());
});

it('reads a server with no threads as idle with no activity', function (): void {
    $activity = ConnAgentActivity::fromShell(['schemaVersion' => 2, 'threads' => [], 'archivedThreads' => [], 'projects' => []]);

    expect($activity->readable)->toBeTrue()->and($activity->turnRuns)->toBeFalse()->and($activity->lastActivityUnix)->toBeNull();
});

it('reads an answer it does not understand as unreadable', function (array $shell): void {
    expect(ConnAgentActivity::fromShell($shell)->readable)->toBeFalse();
})->with([
    'no threads' => [['projects' => []]],
    'a thread that is not an object' => [['threads' => ['x']]],
    'a time that is not a string' => [['threads' => [shellThread(['latestRunCompletedAt' => 12])]]],
    'a time that does not parse' => [['threads' => [shellThread(['latestRunCompletedAt' => 'yesterday-ish'])]]],
]);

/** A clone with its T3 server recorded in the Conn layer, whose access log is two hours old. */
function connIdleClone(): array
{
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $default = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($default, $node)->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
    $clone = Instance::query()->forceCreate(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'clone',
        'checkout_path' => '/workspace/clone', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($clone, $node, $default)->update(['power_state' => 'running']);
    $environment = ConnEnvironment::query()->create(['environment_id' => 'env-clone', 'label' => 'tool / clone', 'url' => 'http://10.44.0.7:3840',
        'node_id' => $node->id, 'admin_session' => 'gateway-session', 'admin_session_expires_at' => now()->addDays(20), 'registered_at' => now()->subDays(1)]);
    $environment->forceFill(['instance_id' => $clone->id])->save();
    $log = RuntimeHibernation::accessLogPath(RuntimeHibernation::key($clone->id));
    app()->instance(NodeFileTimes::class, new class($log) implements NodeFileTimes
    {
        public function __construct(private string $log) {}

        public function read(Node $node, array $paths): ?array
        {
            return array_combine($paths, array_map(fn (string $path): ?int => $path === $this->log ? now()->subHours(2)->getTimestamp() : null, $paths));
        }
    });

    return [$clone, $default];
}

it('reads T3 once with the Gateway admin session and the protocol header, and keeps a young waiting request awake', function (int $minutesAgo, string $decision): void {
    [$clone] = connIdleClone();
    Http::fake(['http://10.44.0.7:3840/api/orchestration/shell' => Http::response(['schemaVersion' => 2, 'threads' => [
        shellThread(['status' => 'waiting', 'pendingRuntimeRequest' => ['id' => 'r', 'kind' => 'user-input',
            'createdAt' => now()->subMinutes($minutesAgo)->toIso8601ZuluString()]]),
    ], 'archivedThreads' => [], 'projects' => []])]);

    $decisions = app(ContainerIdleSweep::class)->run(false);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer gateway-session')
        && $request->hasHeader('x-t3-orchestration-protocol', '2') && $request->method() === 'GET');
    $mine = collect($decisions)->firstWhere('instanceId', $clone->id);
    expect($mine->decision === 'sleep' ? 'sleep' : $mine->reason)->toBe($decision)
        ->and(Operation::query()->where('kind', InstanceSleep::Kind)->count())->toBe($decision === 'sleep' ? 1 : 0);
})->with(['younger than the window' => [59, 't3_active'], 'older than the window' => [61, 'sleep']]);

it('keeps the Instance awake when T3 refuses or does not answer, or Conn is off', function (string $case): void {
    [$clone] = connIdleClone();
    match ($case) {
        'refused' => Http::fake(['*' => Http::response(['reason' => 'invalid_credential'], 401)]),
        'unreachable' => Http::fake(['*' => fn () => throw new ConnectionException('down')]),
        'conn off' => app(ExtensionStore::class)->set('conn', false),
    };

    $mine = collect(app(ContainerIdleSweep::class)->run(false))->firstWhere('instanceId', $clone->id);

    expect($mine->reason)->toBe('t3_unreadable')->and(Operation::query()->where('kind', InstanceSleep::Kind)->count())->toBe(0);
    if ($case === 'conn off') {
        Http::assertNothingSent();
    }
})->with(['refused', 'unreachable', 'conn off']);
