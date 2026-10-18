<?php

declare(strict_types=1);

use App\Actions\Doctor\RunDoctorAction;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Doctor\ProcessInspectionData;
use App\Domain\Doctor\ProcessInspectionStatus;
use App\Domain\Doctor\ProcessStateInspector;
use App\Domain\Problems\ProblemCollection;
use App\Domain\Problems\ProblemCollector;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Jobs\Problems\CollectDoctorResource;
use App\Jobs\Problems\CollectProblemSignals;
use App\Jobs\Problems\CompleteDoctorResource;
use App\Jobs\Problems\DispatchProblemBatch;
use App\Jobs\Problems\ObserveProblemNode;
use App\Models\Node;
use App\Models\ProblemFingerprint;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestOrbitHome;

it('queues separate Node observations and resource checks before collecting and filing signals', function (): void {
    config(['orbit.gateway_queue.connection' => 'services-valkey']);
    Node::query()->create(['name' => 'first', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.28', 'user' => 'orbit']);
    Node::query()->create(['name' => 'second', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.28', 'user' => 'orbit']);
    Bus::fake();

    app(ProblemCollection::class)->dispatch();

    Bus::assertChained([
        fn (DispatchProblemBatch $batch): bool => $batch->jobs->count() === 2 && $batch->jobs->every(fn ($job): bool => $job instanceof ObserveProblemNode && $job->timeout === 5 && $job->queue === 'tasks'),
        fn (DispatchProblemBatch $batch): bool => $batch->jobs->count() === 8 && $batch->jobs->every(fn ($job): bool => $job instanceof CollectDoctorResource && $job->timeout === 5 && $job->queue === 'tasks'),
        fn (DispatchProblemBatch $batch): bool => $batch->jobs->count() === 8 && $batch->jobs->every(fn ($job): bool => $job instanceof CompleteDoctorResource && $job->timeout === 5),
        CollectProblemSignals::class,
    ]);
});

it('keeps earlier resource drift and files a timed-out later resource through real batches and the file cache', function (): void {
    $this->freezeTime();
    config(['orbit.gateway_queue.connection' => 'services-valkey', 'cache.default' => 'file', 'cache.stores.file.path' => TestOrbitHome::scratch('problem-collection-cache')]);
    app(TaskExtensionState::class)->enable();
    Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://example.test/orbit.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'collector', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.28', 'user' => 'orbit']);
    $processes = [];
    foreach (['drift', 'slow', 'healthy'] as $name) {
        $processes[] = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => $name, 'runtime' => 'systemd', 'runtime_config' => ['command' => ['php']], 'desired_state' => 'running', 'status' => LifecycleStatus::Active]);
    }
    app()->instance(NodeStateInspector::class, new class implements NodeStateInspector
    {
        public function inspect(Node $node): NodeInspectionData
        {
            return new NodeInspectionData(true, 'linux', 'amd64', true);
        }
    });
    $inspector = new class implements ProcessStateInspector
    {
        public array $visited = [];

        public function inspect(Process $process): ProcessInspectionData
        {
            $this->visited[] = $process->name;

            return new ProcessInspectionData(true, $process->name === 'drift' ? ProcessInspectionStatus::Inactive : ProcessInspectionStatus::Active);
        }
    };
    app()->instance(ProcessStateInspector::class, $inspector);
    $queue = Queue::connection('gateway');

    foreach ([0, 10] as $minutes) {
        $this->travel($minutes)->minutes();
        app(ProblemCollection::class)->dispatch();
        while ($job = $queue->pop('tasks')) {
            $command = unserialize($job->payload()['data']['command']);
            expect($command->timeout)->toBe(5);
            if ($command instanceof CollectDoctorResource && $command->family === DoctorFamily::Process && $command->resourceId === $processes[1]->id) {
                $job->fail(new RuntimeException('The resource check exceeded its worker deadline.'));
            } else {
                $job->fire();
                $job->delete();
            }
        }
    }

    expect($inspector->visited)->toBe(['drift', 'healthy', 'drift', 'healthy']);
    expect(ProblemFingerprint::query()->where('fingerprint', 'doctor|process.state_mismatch|process|'.$processes[0]->id)->sole()->task_group_id)->not->toBeNull();
    expect(ProblemFingerprint::query()->where('fingerprint', 'doctor|process.inspection_failed|process|'.$processes[1]->id)->sole()->task_group_id)->not->toBeNull();
    expect(ProblemFingerprint::query()->where('fingerprint', 'like', 'doctor|%|process|'.$processes[2]->id)->exists())->toBeFalse();
});

it('keeps the UUID identity when a Schedule inspection times out', function (): void {
    $id = '123e4567-e89b-42d3-a456-426614174099';
    $job = new CollectDoctorResource('sweep', [], 1, DoctorFamily::Schedule, $id);

    $job->recordFailure(app(ProblemCollector::class));

    expect(ProblemFingerprint::query()->sole()->fingerprint)->toBe('doctor|schedule.inspection_failed|schedule|'.$id);
});

it('skips queued collection after the Tasks extension is disabled', function (): void {
    app(TaskExtensionState::class)->enable();
    $node = Node::query()->create(['name' => 'disabled-collector', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.28', 'user' => 'orbit']);
    $queue = Queue::connection('gateway');
    $queue->push(new ObserveProblemNode('disabled', [$node->id], $node->id));
    app(TaskExtensionState::class)->disable();

    $job = $queue->pop('tasks');
    $job->fire();
    $job->delete();

    expect(Cache::has(ProblemCollection::contextKey('disabled', $node->id)))->toBeFalse();
});

it('records an unverifiable Node when its observation job did not complete', function (): void {
    $node = Node::query()->create(['name' => 'unobserved', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.28', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.28', 'ssh_host_fingerprint' => 'SHA256:pinned']);
    $job = new CollectDoctorResource('missing-observation', [$node->id], $node->id, DoctorFamily::Node, $node->id);

    $job->handle(app(RunDoctorAction::class), app(ProblemCollector::class));

    expect(ProblemFingerprint::query()->sole()->fingerprint)->toBe('doctor|node.inspection_failed|node|'.$node->id);
});
