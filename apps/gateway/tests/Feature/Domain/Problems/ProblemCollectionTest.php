<?php

declare(strict_types=1);

use App\Actions\Doctor\RunDoctorAction;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorResourceKind;
use App\Domain\Doctor\DoctorResources;
use App\Domain\Doctor\InstalledPackageInventory;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Doctor\ProcessInspectionData;
use App\Domain\Doctor\ProcessInspectionStatus;
use App\Domain\Doctor\ProcessStateInspector;
use App\Domain\Problems\ProblemCollection;
use App\Domain\Problems\ProblemCollector;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tools\ToolInventoryScan;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\Processes\CommandDeadline;
use App\Jobs\Problems\CollectDoctorResource;
use App\Jobs\Problems\CollectProblemSignals;
use App\Jobs\Problems\DispatchProblemBatch;
use App\Jobs\Problems\ObserveProblemNode;
use App\Models\Node;
use App\Models\ProblemFingerprint;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestOrbitHome;

it('queues only Node observations and grouped checks before collecting signals on the lower priority queue', function (): void {
    config(['orbit.gateway_queue.connection' => 'services-valkey']);
    $first = Node::query()->create(['name' => 'first', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.28']);
    Node::query()->create(['name' => 'second', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.28']);
    foreach (range(1, 40) as $index) {
        Process::query()->create(['owner_type' => Node::class, 'owner_id' => $first->id, 'name' => 'worker-'.$index, 'runtime' => 'systemd', 'runtime_config' => ['command' => ['php']], 'desired_state' => 'running', 'status' => LifecycleStatus::Active]);
    }
    Bus::fake();

    app(ProblemCollection::class)->dispatch();

    Bus::assertChained([
        fn (DispatchProblemBatch $batch): bool => $batch->jobs->count() === 2 && $batch->jobs->every(fn ($job): bool => $job instanceof ObserveProblemNode && $job->timeout === 13 && $job->queue === 'problems'),
        fn (DispatchProblemBatch $batch): bool => $batch->jobs->count() === 22
            && $batch->jobs->every(fn ($job): bool => $job instanceof CollectDoctorResource && $job->timeout === 13 && $job->queue === 'problems')
            && $batch->jobs->where('family', DoctorFamily::Process)->every(fn ($job): bool => count($job->resources->ids) <= 3),
        CollectProblemSignals::class,
    ]);
});

it('reduces a 390 resource Node to 28 collection jobs including orchestration', function (): void {
    config(['orbit.gateway_queue.connection' => 'services-valkey']);
    $node = Node::query()->create(['name' => 'many-routes', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.28']);
    foreach (range(1, 390) as $index) {
        $route = Route::query()->create(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => 'route-'.$index.'.test', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
        $route->customProxy()->create(['node_id' => $node->id, 'upstream' => 'http://127.0.0.1:4788']);
        $route->update(['status' => RouteStatus::Active]);
    }
    Bus::fake();

    app(ProblemCollection::class)->dispatch();

    Bus::assertChained([
        fn (DispatchProblemBatch $batch): bool => $batch->jobs->count() === 1,
        fn (DispatchProblemBatch $batch): bool => $batch->jobs->count() === 24
            && $batch->jobs->where('family', DoctorFamily::Route)->count() === 20,
        CollectProblemSignals::class,
    ]);
});

it('records each grouped Process result without filing and bounds all reads by one probe deadline', function (): void {
    config(['orbit.gateway_queue.connection' => 'services-valkey', 'cache.default' => 'file', 'cache.stores.file.path' => TestOrbitHome::scratch('problem-collection-cache')]);
    app(TaskExtensionState::class)->enable();
    $node = Node::query()->create(['name' => 'collector', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.28']);
    $processes = [];
    foreach (['drift', 'slow', 'healthy'] as $name) {
        $processes[] = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => $name, 'runtime' => 'systemd', 'runtime_config' => ['command' => ['php']], 'desired_state' => 'running', 'status' => LifecycleStatus::Active]);
    }
    $clock = (object) ['now' => 0.0];
    $deadline = new CommandDeadline(static fn (): float => $clock->now);
    app()->instance(CommandDeadline::class, $deadline);
    app()->instance(NodeStateInspector::class, new class implements NodeStateInspector
    {
        public function inspect(Node $node): NodeInspectionData
        {
            return new NodeInspectionData(true, 'linux', 'amd64', true);
        }
    });
    $inspector = new class($clock, $deadline) implements ProcessStateInspector
    {
        public array $budgets = [];

        public function __construct(private stdClass $clock, private CommandDeadline $deadline) {}

        public function inspect(Process $process): ProcessInspectionData
        {
            $this->budgets[] = $this->deadline->cap(30.0);
            $this->clock->now += 2.5;

            return new ProcessInspectionData(true, $process->name === 'drift' ? ProcessInspectionStatus::Inactive : ProcessInspectionStatus::Active);
        }
    };
    app()->instance(ProcessStateInspector::class, $inspector);
    $job = new CollectDoctorResource('grouped', [$node->id], $node->id, DoctorFamily::Process, new DoctorResources(DoctorResourceKind::Rows, array_map(fn (Process $process): int => $process->id, $processes)));
    (new ObserveProblemNode('grouped', [$node->id], $node->id))->handle(app(RunDoctorAction::class), $deadline);

    $job->handle(app(RunDoctorAction::class), app(ProblemCollector::class), $deadline);

    expect($inspector->budgets)->toBe([12.0, 9.5, 7.0]);
    expect(ProblemFingerprint::query()->sole()->fingerprint)->toBe('doctor|process.state_mismatch|process|'.$processes[0]->id);
    expect(ProblemFingerprint::query()->sole()->task_group_id)->toBeNull();
    expect($job->timeout)->toBe(13);
    expect($deadline->cap(30))->toBe(30.0);
});

it('lets a 10.2 second Node observation return its own reachability result', function (bool $reachable): void {
    $node = Node::query()->create(['name' => 'slow-observation', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.28', 'wireguard_ip' => '10.44.0.28', 'ssh_host_fingerprint' => 'SHA256:pinned']);
    $clock = (object) ['now' => 0.0];
    $deadline = new CommandDeadline(static fn (): float => $clock->now);
    app()->instance(CommandDeadline::class, $deadline);
    app()->instance(NodeStateInspector::class, new class($clock, $deadline, $reachable) implements NodeStateInspector
    {
        public function __construct(private stdClass $clock, private CommandDeadline $deadline, private bool $reachable) {}

        public function inspect(Node $node): NodeInspectionData
        {
            expect($this->deadline->cap(30))->toBe(12.0);
            $this->clock->now += 10.2;
            expect($this->deadline->cap(30))->toBeGreaterThan(1.7);

            return new NodeInspectionData($this->reachable, 'linux', 'amd64', true);
        }
    });
    $job = new ObserveProblemNode('slow-node', [$node->id], $node->id);

    $job->handle(app(RunDoctorAction::class), $deadline);
    (new CollectDoctorResource('slow-node', [$node->id], $node->id, DoctorFamily::Node, new DoctorResources(DoctorResourceKind::Rows, [$node->id])))
        ->handle(app(RunDoctorAction::class), app(ProblemCollector::class), $deadline);

    expect(Cache::get(ProblemCollection::contextKey('slow-node', $node->id)))->toMatchArray(['failed' => false]);
    expect(Cache::get(ProblemCollection::contextKey('slow-node', $node->id))['inspection']['reachable'])->toBe($reachable);
    expect(ProblemFingerprint::query()->where('fingerprint', 'doctor|node.inspection_failed|node|'.$node->id)->exists())->toBeFalse();
    expect(ProblemFingerprint::query()->where('fingerprint', 'doctor|node.ssh_unreachable|node|'.$node->id)->exists())->toBe(! $reachable);
})->with(['slow reachable Node' => true, 'down Node' => false]);

it('lets the measured 6.8 second inventory complete without a worker failure', function (): void {
    $node = Node::query()->create(['name' => 'slow-inventory', 'status' => LifecycleStatus::Active, 'platform' => 'macos', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.28']);
    $clock = (object) ['now' => 0.0];
    $deadline = new CommandDeadline(static fn (): float => $clock->now);
    app()->instance(CommandDeadline::class, $deadline);
    Cache::put(ProblemCollection::contextKey('inventory', $node->id), ['inspection' => (new NodeInspectionData(true, 'macos', 'arm64', true))->toArray(), 'failed' => false]);
    app()->instance(InstalledPackageInventory::class, new class($clock, $deadline) implements InstalledPackageInventory
    {
        public function __construct(private stdClass $clock, private CommandDeadline $deadline) {}

        public function inspect(Node $node): array
        {
            expect($this->deadline->cap(30))->toBe(12.0);
            $this->clock->now += 6.8;
            expect($this->deadline->cap(30))->toBeGreaterThan(5.1);

            return array_map(static fn (ToolManagerName $manager): ToolInventoryScan => new ToolInventoryScan($manager, ToolInventoryScanState::Complete, []), [ToolManagerName::Brew, ToolManagerName::BrewCask, ToolManagerName::Vp]);
        }
    });
    $job = new CollectDoctorResource('inventory', [$node->id], $node->id, DoctorFamily::Tool, new DoctorResources(DoctorResourceKind::Inventory));

    $job->handle(app(RunDoctorAction::class), app(ProblemCollector::class), $deadline);

    expect(ProblemFingerprint::query()->exists())->toBeFalse();
    expect($job->timeout)->toBeGreaterThan($clock->now);
});

it('uses the Laravel failure hook to record every selected UUID without completion jobs', function (): void {
    app(TaskExtensionState::class)->enable();
    $ids = ['123e4567-e89b-42d3-a456-426614174099', '123e4567-e89b-42d3-a456-426614174098'];
    $queue = Queue::connection('gateway');
    $queue->pushOn('problems', new CollectDoctorResource('failed-group', [], 1, DoctorFamily::Schedule, new DoctorResources(DoctorResourceKind::Rows, $ids)));
    $job = $queue->pop('problems');

    $job->fail(new RuntimeException('The worker failed.'));

    expect(ProblemFingerprint::query()->orderBy('fingerprint')->pluck('fingerprint')->all())->toBe([
        'doctor|schedule.inspection_failed|schedule|'.$ids[1],
        'doctor|schedule.inspection_failed|schedule|'.$ids[0],
    ]);
    expect($queue->pop('problems'))->toBeNull();
});

it('collects signals without filing an eligible problem until the hourly command runs', function (): void {
    $this->freezeTime();
    app(TaskExtensionState::class)->enable();
    Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://example.test/orbit.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'filing', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.28']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'failed', 'runtime' => 'systemd', 'runtime_config' => ['command' => ['php']], 'desired_state' => 'running', 'status' => LifecycleStatus::Active]);
    $job = new CollectDoctorResource('failed', [$node->id], $node->id, DoctorFamily::Process, new DoctorResources(DoctorResourceKind::Rows, [$process->id]));
    $job->failed(new RuntimeException('worker lost'));
    $this->travel(10)->minutes();
    $job->failed(new RuntimeException('worker lost again'));
    Cache::put(ProblemCollection::contextKey('signals', 1), ['observation']);

    app()->call([new CollectProblemSignals('signals', [1]), 'handle']);

    expect(Cache::has(ProblemCollection::contextKey('signals', 1)))->toBeFalse();
    expect(ProblemFingerprint::query()->sole()->task_group_id)->toBeNull();
    expect(Artisan::call('problems:file'))->toBe(0);
    expect(ProblemFingerprint::query()->sole()->task_group_id)->not->toBeNull();
});

it('skips queued collection after the Tasks extension is disabled', function (): void {
    app(TaskExtensionState::class)->enable();
    $node = Node::query()->create(['name' => 'disabled-collector', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.28']);
    $queue = Queue::connection('gateway');
    $queue->pushOn('problems', new ObserveProblemNode('disabled', [$node->id], $node->id));
    app(TaskExtensionState::class)->disable();

    $job = $queue->pop('problems');
    $job->fire();
    $job->delete();

    expect(Cache::has(ProblemCollection::contextKey('disabled', $node->id)))->toBeFalse();
});

it('records an unverifiable Node when its observation job did not complete', function (): void {
    $node = Node::query()->create(['name' => 'unobserved', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.28', 'wireguard_ip' => '10.44.0.28', 'ssh_host_fingerprint' => 'SHA256:pinned']);
    $job = new CollectDoctorResource('missing-observation', [$node->id], $node->id, DoctorFamily::Node, new DoctorResources(DoctorResourceKind::Rows, [$node->id]));

    $job->handle(app(RunDoctorAction::class), app(ProblemCollector::class), app(CommandDeadline::class));

    expect(ProblemFingerprint::query()->sole()->fingerprint)->toBe('doctor|node.inspection_failed|node|'.$node->id);
});

it('removes the retired Doctor resume cursor without removing signal collector state', function (): void {
    expect(Schema::hasColumn('problem_collector_state', 'doctor_resume_key'))->toBeFalse();
    expect(Schema::hasColumns('problem_collector_state', ['activity_cursor', 'log_path', 'log_inode', 'log_offset']))->toBeTrue();
});

it('returns after one lookup when the observed Node was deleted', function (): void {
    config(['cache.default' => 'array']);
    $job = new CollectDoctorResource('deleted-node', [999999], 999999, DoctorFamily::Tool, new DoctorResources(DoctorResourceKind::Rows, [1]));
    DB::enableQueryLog();

    app()->call([$job, 'handle']);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(collect($queries)->filter(static fn (array $query): bool => str_contains($query['query'], '"nodes"'))->count())->toBe(1);
});
