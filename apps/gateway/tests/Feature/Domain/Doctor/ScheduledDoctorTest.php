<?php

declare(strict_types=1);

use App\Actions\Doctor\RunDoctorAction;
use App\Domain\AgentView\AgentStateView;
use App\Domain\Firewall\FirewallInspectionInput;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Problems\ProblemCollector;
use App\Domain\Problems\ProblemGroupSnapshot;
use App\Domain\Problems\ProblemSource;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\ProblemCollectorState;
use App\Models\ProblemFingerprint;
use App\Models\Process;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-12T12:00:00Z'));
    $this->view = new CacheAgentStateView(new Repository(new ArrayStore));
    $this->view->putSubscriber(true, true, 15);
    app()->instance(AgentStateView::class, $this->view);
    Http::preventStrayRequests();
    $this->mock(SshExecutor::class)->shouldNotReceive('execute');
});

afterEach(function (): void {
    ProblemCollectorState::flushEventListeners();
});

function scheduledDoctorNode(string $name = 'beast', int $address = 7): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.'.$address, 'public_ssh_host' => '192.0.2.'.$address,
        'ssh_host_fingerprint' => 'SHA256:fixture', 'user' => 'orbit',
    ]));
}

function scheduledDoctorFacts(Node $node, ?string $bridgeError = null): void
{
    test()->view->putNode($node->id, [], null, 1, CacheAgentStateView::now(), null);
    foreach (['host' => ['os' => 'linux', 'arch' => 'x86_64',
        'filesystems' => [['mount' => '/', 'size' => 100_000_000_000, 'free' => 50_000_000_000]],
        'instance_bridge' => ['error_code' => $bridgeError]],
        'units' => ['units' => [], 'awake' => []],
        'ufw' => ['installed' => true, 'enabled' => true, 'rules' => array_column(app(FirewallInspectionInput::class)->expected($node), 'shape')]] as $kind => $data) {
        NodeFact::query()->updateOrCreate(['node_id' => $node->id, 'kind' => $kind], ['digest' => hash('sha256', json_encode($data)), 'data' => $data, 'observed_at' => now(), 'received_at' => now()]);
    }
}

function scheduledDoctorEvent(): CallbackEvent
{
    Artisan::call('schedule:list');
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'doctor:collect');
    expect($event)->toBeInstanceOf(CallbackEvent::class);

    return $event;
}

function scheduledDoctorProblem(Node $node, string $code, string $resource, string $since, array $extra = []): ProblemFingerprint
{
    return ProblemFingerprint::query()->create([
        'source' => ProblemSource::Doctor, 'fingerprint' => "doctor|{$code}|process|{$resource}",
        'first_seen' => $since, 'last_seen' => now(), 'occurrences' => 100,
        'evidence' => ['doctor_node' => (string) $node->id, 'doctor_code' => $code, 'doctor_family' => 'process',
            'doctor_resource' => $resource, 'doctor_observed_at' => '2026-11-12T11:59:00.000000Z',
            'observation_times' => ['2026-11-01T00:00:00Z', '2026-11-02T00:00:00Z'], ...$extra],
    ]);
}

it('collects every five minutes with Tasks disabled, records recovery and leaves other signals untouched', function (): void {
    $node = scheduledDoctorNode();
    scheduledDoctorFacts($node, 'instance.container_bridge_write_failed');
    app(TaskExtensionState::class)->disable();
    config(['orbit.gateway_queue.connection' => '']);
    $state = ProblemCollectorState::query()->create(['activity_cursor' => 42, 'log_path' => '/unchanged', 'log_inode' => 17, 'log_offset' => 99,
        'observation' => ['other' => 'preserved']]);
    $assist = ProblemFingerprint::query()->create(['source' => ProblemSource::Assist, 'fingerprint' => 'assist|waiting', 'occurrences' => 1,
        'evidence' => ['assistance_task_ids' => [999]]])->refresh();
    $event = scheduledDoctorEvent();
    expect($event->expression)->toBe('*/5 * * * *')->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->filtersPass(app()))->toBeTrue();
    $event->run(app());
    $problem = ProblemFingerprint::query()->where('evidence->doctor_code', 'node.instance_bridge_unhealthy')->sole();
    expect($problem->occurrences)->toBe(1)->and($problem->evidence)->not->toHaveKey('resolved_at')
        ->and($state->fresh()->only(['activity_cursor', 'log_path', 'log_inode', 'log_offset']))
        ->toBe(['activity_cursor' => 42, 'log_path' => '/unchanged', 'log_inode' => 17, 'log_offset' => 99])
        ->and($state->fresh()->observation)->toMatchArray(['other' => 'preserved', 'doctor_collected_at' => '2026-11-12T12:00:00.000000Z'])
        ->and($assist->fresh()->getAttributes())->toBe($assist->getAttributes())
        ->and(ProblemFingerprint::query()->whereIn('source', ['log', 'activity'])->count())->toBe(0);
    $this->travel(5)->minutes();
    $this->view->putSubscriber(true, true, 1);
    scheduledDoctorFacts($node);
    $event->run(app());
    expect($problem->fresh()->evidence['resolution'])->toBe('fresh_doctor_success')
        ->and($state->fresh()->observation['doctor_collected_at'])->toBe('2026-11-12T12:05:00.000000Z');
});

it('skips an overlapping scheduled collection and releases its lock after completion', function (): void {
    scheduledDoctorNode();
    $event = scheduledDoctorEvent();
    expect($event->mutex->create($event))->toBeTrue();
    try {
        $event->run(app());
        expect(ProblemFingerprint::query()->count())->toBe(0)->and(ProblemCollectorState::query()->count())->toBe(0);
    } finally {
        $event->mutex->forget($event);
    }
    $event->run(app());
    expect(ProblemFingerprint::query()->count())->toBeGreaterThan(0)->and($event->mutex->exists($event))->toBeFalse();
});

it('rolls back failed writes and reports a failing collection once until it succeeds', function (): void {
    Exceptions::fake();
    Log::spy();
    $node = scheduledDoctorNode();
    scheduledDoctorFacts($node, 'instance.container_bridge_write_failed');
    $state = ProblemCollectorState::query()->create(['observation' => ['doctor_collected_at' => '2026-11-11T12:00:00Z']]);
    $fail = true;
    ProblemCollectorState::saving(function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('collection state write failed');
        }
    });
    $event = scheduledDoctorEvent();
    $event->run(app());
    $this->travel(5)->minutes();
    $event->run(app());
    Exceptions::assertReportedCount(1);
    expect(ProblemFingerprint::query()->count())->toBe(0)
        ->and($state->fresh()->observation['doctor_collected_at'])->toBe('2026-11-11T12:00:00Z')
        ->and($event->mutex->exists($event))->toBeFalse();
    $fail = false;
    $event->run(app());
    expect(ProblemFingerprint::query()->count())->toBeGreaterThan(0);
    $fail = true;
    $this->travel(5)->minutes();
    $event->run(app());
    Exceptions::assertReportedCount(2);
    Log::shouldHaveReceived('info')->with('Doctor collection ran.', Mockery::type('array'))->times(4);
});

it('uses the same Doctor step from observation collection without treating Horizon-only reads as a full collection', function (): void {
    $node = scheduledDoctorNode();
    scheduledDoctorFacts($node, 'instance.container_bridge_write_failed');
    app(RunDoctorAction::class)->collect();
    expect(ProblemCollectorState::query()->sole()->observation['doctor_collected_at'])->toBe('2026-11-12T12:00:00.000000Z');
    $this->travel(5)->minutes();
    app(RunDoctorAction::class)->collect(true);
    expect(ProblemCollectorState::query()->sole()->observation['doctor_collected_at'])->toBe('2026-11-12T12:00:00.000000Z');
});

it('never files recurring Doctor rows but still files an eligible non-Doctor row in the same run', function (): void {
    app(TaskExtensionState::class)->enable();
    Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git']);
    $doctor = scheduledDoctorProblem(scheduledDoctorNode(), 'process.state_mismatch', '1', '2026-11-01T00:00:00Z');
    $this->artisan('problems:file')->assertSuccessful();
    expect(Task::topLevel()->count())->toBe(0)->and($doctor->fresh()->filed_at)->toBeNull()->and($doctor->fresh()->task_group_id)->toBeNull();
    $activity = ProblemFingerprint::query()->create(['source' => ProblemSource::Activity, 'fingerprint' => 'activity|node:converge|gateway.unhandled',
        'first_seen' => now()->subDays(2), 'last_seen' => now(), 'occurrences' => 100,
        'evidence' => ['observation_counts' => [1, 1], 'observation_times' => ['2026-11-10T12:00:00Z', '2026-11-11T12:00:00Z']]]);
    $this->artisan('problems:file')->assertSuccessful();
    expect(Task::topLevel()->count())->toBe(1)->and($activity->fresh()->task_group_id)->not->toBeNull()
        ->and($activity->fresh()->filed_at)->not->toBeNull()->and($doctor->fresh()->filed_at)->toBeNull()->and($doctor->fresh()->task_group_id)->toBeNull();
});

it('leaves an already linked Doctor group and its subtasks unchanged', function (bool $resolved): void {
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Original', 'brief' => 'Original brief', 'status' => TaskGroupStatus::Backlog]);
    $subtask = $group->tasks()->create(['title' => 'Original step', 'brief' => 'Original step brief', 'position' => 1]);
    $doctor = scheduledDoctorProblem(scheduledDoctorNode(), 'process.state_mismatch', '1', '2026-11-01T00:00:00Z',
        ['generated_group_hash' => app(ProblemGroupSnapshot::class)->hash($group), ...($resolved ? ['resolved_at' => now()->toISOString()] : [])]);
    $doctor->update(['task_group_id' => $group->id]);
    $group->refresh();
    $subtask->refresh();
    $this->artisan('problems:file')->assertSuccessful();
    expect($group->fresh()->getAttributes())->toBe($group->getAttributes())
        ->and($subtask->fresh()->getAttributes())->toBe($subtask->getAttributes())->and($doctor->fresh()->task_group_id)->toBe($group->id);
})->with([false, true]);

it('leaves a Doctor row linked to an ended group unmuted with its history', function (TaskGroupStatus $status): void {
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Ended', 'brief' => 'Ended brief', 'status' => $status]);
    // A row as the collection writes it today: its windows are counted, so no legacy settlement applies.
    $doctor = scheduledDoctorProblem(scheduledDoctorNode(), 'process.state_mismatch', '1', '2026-11-01T00:00:00Z',
        ['observation_counts' => [1, 1], 'counted_blocks' => [5926176, 5926464]]);
    $doctor->update(['task_group_id' => $group->id]);
    $before = $doctor->fresh()->getAttributes();
    $this->artisan('problems:file')->assertSuccessful();
    expect($doctor->fresh()->getAttributes())->toBe($before);
})->with([TaskGroupStatus::Cancelled, TaskGroupStatus::Completed]);

it('keeps the group link of an auto-closed Doctor finding that recurs', function (): void {
    $node = scheduledDoctorNode();
    scheduledDoctorFacts($node, 'instance.container_endpoint_unresolved');
    app(RunDoctorAction::class)->scheduled();
    $row = ProblemFingerprint::query()->where('source', ProblemSource::Doctor)->where('evidence->doctor_code', 'node.instance_bridge_unhealthy')->sole();
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Closed', 'brief' => 'Closed brief', 'status' => TaskGroupStatus::Cancelled]);
    $row->update(['task_group_id' => $group->id, 'evidence' => [...$row->evidence, 'auto_closed' => true]]);
    $this->travel(5)->minutes();
    scheduledDoctorFacts($node, 'instance.container_endpoint_unresolved');
    app(RunDoctorAction::class)->scheduled();
    expect($row->fresh()->task_group_id)->toBe($group->id);
});

it('evicts the oldest counted arrival while retaining 288 windows', function (): void {
    $node = scheduledDoctorNode();
    $start = new DateTimeImmutable('2026-11-12T12:00:00Z');
    $this->travelTo($start);
    scheduledDoctorFacts($node, 'instance.container_endpoint_unresolved');
    app(RunDoctorAction::class)->scheduled();
    $row = ProblemFingerprint::query()->where('source', ProblemSource::Doctor)->where('evidence->doctor_code', 'node.instance_bridge_unhealthy')->sole();
    $current = intdiv($start->getTimestamp(), 300);
    $blocks = range($current - 287, $current);
    $row->update(['occurrences' => 288, 'evidence' => [...$row->evidence, 'counted_blocks' => $blocks]]);

    $this->travelTo($start->modify('+5 minutes'));
    scheduledDoctorFacts($node, 'instance.container_endpoint_unresolved');
    app(RunDoctorAction::class)->scheduled();
    $row->refresh();
    expect($row->occurrences)->toBe(289)->and($row->evidence['counted_blocks'])->toBe(range($current - 286, $current + 1))
        ->toHaveCount(288)->not->toContain($blocks[0])->toContain($current + 1)
        ->and(count($row->evidence['observation_times']))->toBeLessThanOrEqual(20);
});

it('shows three open groups with both first-seen bounds, a single resource and newest-first sorting only to peers', function (): void {
    $beast = scheduledDoctorNode();
    $shark = scheduledDoctorNode('shark', 8);
    $beast->accessibleNodes()->attach($shark);
    scheduledDoctorProblem($beast, 'process.state_mismatch', '21', '2026-11-01T00:00:00Z');
    scheduledDoctorProblem($beast, 'process.state_mismatch', '22', '2026-11-12T11:00:00Z');
    scheduledDoctorProblem($beast, 'process.crash_loop', '23', '2026-11-09T00:00:00Z');
    scheduledDoctorProblem($shark, 'process.state_mismatch', '24', '2026-11-10T00:00:00Z');
    scheduledDoctorProblem($shark, 'process.crash_loop', '25', '2026-11-12T11:59:00Z', ['resolved_at' => now()->toISOString()]);
    ProblemFingerprint::query()->create(['source' => ProblemSource::Log, 'fingerprint' => 'log|exception|frame', 'evidence' => []]);
    ProblemCollectorState::query()->create(['observation' => ['doctor_collected_at' => '2026-11-12T12:00:00.000000Z', 'doctor_duration_ms' => 650, 'doctor_started_at' => '2026-11-12T11:59:00.000000Z']]);
    $doctor = ['collected_at' => '2026-11-12T12:00:00.000000Z', 'last_run_ms' => 650, 'hidden_nodes' => 0, 'issues' => [
        ['node_id' => $beast->id, 'node_name' => 'beast', 'code' => 'process.state_mismatch', 'count' => 2,
            'since' => '2026-11-01T00:00:00.000000Z', 'newest_since' => '2026-11-12T11:00:00.000000Z', 'resource' => null],
        ['node_id' => $shark->id, 'node_name' => 'shark', 'code' => 'process.state_mismatch', 'count' => 1,
            'since' => '2026-11-10T00:00:00.000000Z', 'newest_since' => '2026-11-10T00:00:00.000000Z', 'resource' => ['type' => 'process', 'id' => '24']],
        ['node_id' => $beast->id, 'node_name' => 'beast', 'code' => 'process.crash_loop', 'count' => 1,
            'since' => '2026-11-09T00:00:00.000000Z', 'newest_since' => '2026-11-09T00:00:00.000000Z', 'resource' => ['type' => 'process', 'id' => '23']],
    ]];
    $response = $this->withServerVariables(['REMOTE_ADDR' => $beast->wireguard_ip])->getJson('/api/v1/gateway/status')->assertOk()->assertJsonPath('data.doctor', $doctor);
    $body = $response->json();
    $body['meta']['request_id'] = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $fixture = ['schema' => 1, 'request' => ShowGatewayStatusRequest::class, 'route' => 'GET /api/v1/gateway/status', 'status' => 200, 'body' => $body];
    $path = base_path('../../packages/php-sdk/fixtures/gateway/gateway-status/doctor.json');
    if (getenv('ORBIT_FIXTURES') === 'record') {
        file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
    expect(is_file($path))->toBeTrue();
    expect(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['body']['data']['doctor'])->toBe($doctor);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->getJson('/api/v1/gateway/status')->assertOk()->assertJsonPath('data.doctor', null);
});

it('shows only what the latest full collection saw and keeps older open rows as history', function (): void {
    $node = scheduledDoctorNode();
    $stale = scheduledDoctorProblem($node, 'process.node_unreachable', '7', '2026-10-26T12:20:00Z', ['doctor_observed_at' => '2026-10-31T07:34:00.000000Z']);
    $legacy = scheduledDoctorProblem($node, 'node.ssh_unreachable', '8', '2026-10-01T19:51:00Z');
    $legacy->update(['evidence' => array_diff_key($legacy->evidence, ['doctor_observed_at' => true])]);
    scheduledDoctorFacts($node, 'instance.container_endpoint_unresolved');
    app(RunDoctorAction::class)->scheduled();
    $codes = fn (): array => array_column($this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->getJson('/api/v1/gateway/status')->assertOk()->json('data.doctor.issues'), 'code');
    expect($codes())->toContain('node.instance_bridge_unhealthy')->not->toContain('process.node_unreachable')->not->toContain('node.ssh_unreachable')
        ->and($stale->fresh())->not->toBeNull()->and($stale->fresh()->evidence)->not->toHaveKey('resolved_at')->and($legacy->fresh())->not->toBeNull();
    // The finding recovers: the next collection no longer sees it, so it leaves the list at once.
    $this->travelTo(new DateTimeImmutable('2026-11-12T12:05:00Z'));
    scheduledDoctorFacts($node);
    app(RunDoctorAction::class)->scheduled();
    expect($codes())->not->toContain('node.instance_bridge_unhealthy');
});

it('distinguishes a healthy full collection from one that has never run', function (): void {
    $node = scheduledDoctorNode();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->getJson('/api/v1/gateway/status')->assertOk()
        ->assertJsonPath('data.doctor', ['collected_at' => null, 'last_run_ms' => null, 'hidden_nodes' => 0, 'issues' => []]);
    ProblemCollectorState::query()->create(['observation' => ['doctor_collected_at' => '2026-11-12T12:00:00Z']]);
    $this->getJson('/api/v1/gateway/status')->assertOk()->assertJsonPath('data.doctor', ['collected_at' => '2026-11-12T12:00:00Z', 'last_run_ms' => null, 'hidden_nodes' => 0, 'issues' => []]);
});

it('succeeds and lists a Node whose agent is silent or has never answered', function (bool $seen): void {
    $node = scheduledDoctorNode();
    if ($seen) {
        $this->view->putNode($node->id, [], null, 1, CacheAgentStateView::now() - 60, null);
    }
    scheduledDoctorEvent()->run(app());
    $response = $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->getJson('/api/v1/gateway/status')->assertOk();
    expect(collect($response->json('data.doctor.issues'))->firstWhere('code', $seen ? 'node.agent_view_stale' : 'node.agent_missing'))
        ->toMatchArray(['node_id' => $node->id, 'node_name' => 'beast', 'count' => 1])
        ->and($response->json('data.doctor.collected_at'))->toBe('2026-11-12T12:00:00.000000Z');
})->with([false, true]);

it('counts the database queries for one real collection over 15 Nodes and 80 failing Processes', function (): void {
    for ($i = 1; $i <= 15; $i++) {
        $node = scheduledDoctorNode('node-'.$i, $i);
        scheduledDoctorFacts($node);
        if ($i === 1) {
            for ($j = 1; $j <= 80; $j++) {
                Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'process-'.$j,
                    'runtime' => 'systemd', 'desired_state' => 'running', 'status' => 'active', 'runtime_config' => []]);
            }
        }
    }
    $event = scheduledDoctorEvent();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $event->run(app());
    $count = count($queries);
    $types = array_count_values(array_map(fn (string $sql): string => strtolower(strtok($sql, ' ')), $queries));
    expect(ProblemFingerprint::query()->count())->toBe(80)
        ->and(ProblemFingerprint::query()->where('evidence->doctor_code', 'process.runtime_missing')->count())->toBe(80)
        ->and(ProblemCollectorState::query()->sole()->observation['doctor_collected_at'])->toBe('2026-11-12T12:00:00.000000Z');
    fwrite(STDOUT, "\nDoctor collection queries (15 Nodes, 80 Process findings): {$count}\n");
    fwrite(STDOUT, json_encode($types, JSON_THROW_ON_ERROR)."\n");
    expect($count)->toBe(441);
});

it('records the peer status fixture from the real API with its cached desired fleet state', function (): void {
    $path = base_path('../../packages/php-sdk/fixtures/gateway/gateway-status/peer.json');
    $existing = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $seed = $existing['body']['data'];
    config(['app.version' => $seed['version']]);
    Cache::put('orbit:desired-fleet-state:v1:'.hash('sha256', $seed['version']), $seed['desired_fleet_state']);
    $node = scheduledDoctorNode();
    $body = $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->getJson('/api/v1/gateway/status')->assertOk()->json();
    $body['meta']['request_id'] = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $fixture = ['schema' => 1, 'request' => ShowGatewayStatusRequest::class, 'route' => 'GET /api/v1/gateway/status', 'status' => 200, 'body' => $body];
    if (getenv('ORBIT_FIXTURES') === 'record') {
        file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
    $recorded = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($body['data']['doctor'])->toBe(['collected_at' => null, 'last_run_ms' => null, 'hidden_nodes' => 0, 'issues' => []])
        ->and($recorded['body']['data']['doctor'])->toBe($body['data']['doctor']);
});

it('reports one monotonic run cost and commits its stored timing with the findings', function (): void {
    Log::spy();
    $node = scheduledDoctorNode();
    scheduledDoctorFacts($node, 'instance.container_endpoint_unresolved');
    ProblemCollectorState::saving(function (): void {
        // A wall-clock jump during the run must not become a multi-year duration.
        test()->travelTo(new DateTimeImmutable('2030-11-12T12:00:00Z'));
    });
    app(RunDoctorAction::class)->scheduled();
    $stored = ProblemCollectorState::query()->sole()->observation;
    expect($stored)->toHaveKeys(['doctor_collected_at', 'doctor_duration_ms', 'doctor_write_ms'])
        ->and($stored['doctor_duration_ms'])->toBeFloat()->toBeGreaterThanOrEqual(0)->toBeLessThan(1000)
        ->and($stored['doctor_write_ms'])->toBeFloat()->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($stored['doctor_duration_ms']);
    $logged = null;
    Log::shouldHaveReceived('info')->once()->with('Doctor collection ran.', Mockery::on(function (array $cost) use (&$logged, $stored): bool {
        $logged = $cost;

        return array_keys($cost) === ['duration_ms', 'write_ms', 'nodes', 'issues']
            && $cost['duration_ms'] >= $stored['doctor_duration_ms'] && $cost['duration_ms'] < 1000
            && $cost['write_ms'] >= $stored['doctor_write_ms'] && $cost['write_ms'] <= $cost['duration_ms']
            && $cost['nodes'] === 1 && $cost['issues'] === 1;
    }));
    fwrite(STDOUT, "\n".json_encode(['message' => 'Doctor collection ran.', 'context' => $logged], JSON_THROW_ON_ERROR)."\n");
});

it('reveals only the number of hidden affected Nodes to a peer without an edge', function (): void {
    $peer = scheduledDoctorNode('peer', 9);
    $beast = scheduledDoctorNode('beast', 7);
    $shark = scheduledDoctorNode('shark', 8);
    scheduledDoctorProblem($beast, 'process.state_mismatch', '21', '2026-11-01T00:00:00Z');
    scheduledDoctorProblem($beast, 'process.crash_loop', '22', '2026-11-02T00:00:00Z');
    scheduledDoctorProblem($shark, 'process.state_mismatch', '23', '2026-11-03T00:00:00Z');
    ProblemCollectorState::query()->create(['observation' => ['doctor_collected_at' => '2026-11-12T12:00:00Z', 'doctor_duration_ms' => 650, 'doctor_started_at' => '2026-11-12T11:59:00.000000Z']]);
    $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip])->getJson('/api/v1/gateway/status')->assertOk()
        ->assertJsonPath('data.doctor', ['collected_at' => '2026-11-12T12:00:00Z', 'last_run_ms' => 650, 'hidden_nodes' => 2, 'issues' => []]);

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->getJson('/api/v1/gateway/status')->assertOk()->assertJsonPath('data.doctor', null);
    expect(array_filter($queries, fn (string $query): bool => str_contains($query, 'problem_')))->toBe([]);
});

it('includes the caller own Node only as a status exception to the shared grant rule', function (): void {
    $peer = scheduledDoctorNode();
    scheduledDoctorProblem($peer, 'process.state_mismatch', '21', '2026-11-01T00:00:00Z');
    ProblemCollectorState::query()->create(['observation' => ['doctor_collected_at' => '2026-11-12T12:00:00Z', 'doctor_started_at' => '2026-11-12T11:59:00.000000Z']]);
    $access = app(NodeAccessAuthorizer::class);
    expect($access->allows($peer, $peer))->toBeFalse()->and($access->accessibleNodeIds($peer))->toBe([]);
    $response = $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip])->getJson('/api/v1/gateway/status')->assertOk();
    expect(array_column($response->json('data.doctor.issues'), 'node_id'))->toBe([$peer->id])
        ->and($response->json('data.doctor.hidden_nodes'))->toBe(0);
});

it('shows every Node and the synthetic slow group with Gateway or control-plane authority', function (): void {
    $gateway = scheduledDoctorNode('gateway', 1);
    $gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
    $beast = scheduledDoctorNode('beast', 7);
    $shark = scheduledDoctorNode('shark', 8);
    scheduledDoctorProblem($beast, 'process.state_mismatch', '21', '2026-11-11T00:00:00Z');
    scheduledDoctorProblem($shark, 'process.crash_loop', '22', '2026-11-10T00:00:00Z');
    ProblemCollectorState::query()->create(['observation' => ['doctor_collected_at' => '2026-11-12T12:00:00Z', 'doctor_duration_ms' => 1500, 'doctor_started_at' => '2026-11-12T11:59:00.000000Z']]);
    $expected = ['collected_at' => '2026-11-12T12:00:00Z', 'last_run_ms' => 1500, 'hidden_nodes' => 0, 'issues' => [
        ['node_id' => $gateway->id, 'node_name' => 'gateway', 'code' => 'gateway.doctor_collection_slow', 'count' => 1,
            'since' => '2026-11-12T12:00:00Z', 'newest_since' => '2026-11-12T12:00:00Z', 'resource' => null],
        ['node_id' => $beast->id, 'node_name' => 'beast', 'code' => 'process.state_mismatch', 'count' => 1,
            'since' => '2026-11-11T00:00:00.000000Z', 'newest_since' => '2026-11-11T00:00:00.000000Z', 'resource' => ['type' => 'process', 'id' => '21']],
        ['node_id' => $shark->id, 'node_name' => 'shark', 'code' => 'process.crash_loop', 'count' => 1,
            'since' => '2026-11-10T00:00:00.000000Z', 'newest_since' => '2026-11-10T00:00:00.000000Z', 'resource' => ['type' => 'process', 'id' => '22']],
    ]];
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])->getJson('/api/v1/gateway/status')->assertOk()->assertJsonPath('data.doctor', $expected);
    $peer = scheduledDoctorNode('operator', 9);
    $peer->accessibleNodes()->attach($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip])->getJson('/api/v1/gateway/status')->assertOk()->assertJsonPath('data.doctor', $expected);
    $peer->accessibleNodes()->detach();
    app(GatewayServingHost::class)->remember($peer);
    $this->getJson('/api/v1/gateway/status')->assertOk()->assertJsonPath('data.doctor', $expected);
    expect(ProblemFingerprint::query()->count())->toBe(2);
});

it('applies Node access and the strict duration threshold to the slow group without storing a fingerprint', function (int $duration): void {
    $gateway = scheduledDoctorNode('gateway', 1);
    $gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
    $peer = scheduledDoctorNode('peer', 9);
    $state = ProblemCollectorState::query()->create(['observation' => ['doctor_collected_at' => '2026-11-12T12:00:00Z', 'doctor_duration_ms' => $duration, 'doctor_started_at' => '2026-11-12T11:59:00.000000Z']]);
    $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip])->getJson('/api/v1/gateway/status')->assertOk()
        ->assertJsonPath('data.doctor', ['collected_at' => '2026-11-12T12:00:00Z', 'last_run_ms' => $duration, 'hidden_nodes' => $duration > 1000 ? 1 : 0, 'issues' => []]);
    $response = $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])->getJson('/api/v1/gateway/status')->assertOk();
    expect($response->json('data.doctor.issues'))->toHaveCount($duration > 1000 ? 1 : 0);
    $state->update(['observation' => ['doctor_collected_at' => '2026-11-12T12:05:00Z', 'doctor_duration_ms' => 900, 'doctor_started_at' => '2026-11-12T11:59:00.000000Z']]);
    $this->getJson('/api/v1/gateway/status')->assertOk()->assertJsonPath('data.doctor.issues', []);
    expect(ProblemFingerprint::query()->count())->toBe(0);
})->with([999, 1000, 1001]);

it('counts a late signal again after its window was evicted, retaining arrival order', function (): void {
    $collector = app(ProblemCollector::class);
    $signals = ['activity_cursor_before' => null, 'activity_cursor_after' => 0, 'open_assistance_ids' => [], 'assistance' => [],
        'activities' => [['key' => 'node:converge|gateway.unhandled', 'seen_at' => now()->toISOString(), 'observation' => []]]];
    $collector->recordSignals($signals);
    $row = ProblemFingerprint::query()->where('source', ProblemSource::Activity)->sole();
    $current = intdiv(now()->getTimestamp(), 300);
    $blocks = range($current - 287, $current);
    $row->update(['occurrences' => 288, 'evidence' => [...$row->evidence, 'counted_blocks' => $blocks]]);
    $signals['activity_cursor_before'] = 0;
    $signals['activities'][0]['seen_at'] = now()->subSeconds(500 * 300)->toISOString();
    $collector->recordSignals($signals);
    $row->refresh();
    expect($row->occurrences)->toBe(289)->and($row->evidence['counted_blocks'])->toBe([...array_slice($blocks, 1), $current - 500]);
});
