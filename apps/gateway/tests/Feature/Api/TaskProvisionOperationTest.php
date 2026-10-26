<?php

declare(strict_types=1);

use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\TaskVms\DeleteTaskVmAction;
use App\Actions\TaskVms\DestroyEndedTaskVmsAction;
use App\Actions\TaskVms\ParkIdleTaskVmsAction;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\OperationSweeper;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskAnalysisRunner;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskReviewedCommitSource;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Tasks\NativeTaskExecutionLock;
use App\Infrastructure\TaskVms\TaskVmWorker;
use App\Infrastructure\TaskVms\TaskWorkerEnrollment;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Jobs\TaskVms\ParkTaskVm;
use App\Jobs\TaskVms\ResumeTaskVm;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskReviewedCommit;
use App\Models\TaskVm;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Tasks\ProvisionTaskGroupRequest;
use Symfony\Component\Process\Process;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeTaskAnalysisRunner;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\RecordingTaskVmProvider;
use Tests\Support\TaskAnalysisAdapterFixture;

require_once __DIR__.'/../../Support/TaskCheckRunnerFixtures.php';
use App\Actions\Operations\CancelOperationAction;
use App\Domain\Tasks\TaskProvisionOperation;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Facades\Cache;
use Tests\Support\TaskVmSettingsBuilder;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    enable_operation_queue();
    app()->instance(TaskAnalysisRunner::class, new FakeTaskAnalysisRunner);
    $this->travelTo(now()->startOfSecond());
});

function provision_operation_group(TaskGroupStatus $status = TaskGroupStatus::Todo, TaskOrchestration $orchestration = TaskOrchestration::External): Task
{
    $node = test()->markAsGateway(Node::query()->create([
        'name' => 'provision-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1',
        'user' => 'orbit', 'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]));
    test()->gatewayNode = $node;
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create([
        'name' => 'Provision', 'slug' => 'provision', 'repository_url' => 'git@github.com:acme/provision.git',
        'default_branch' => 'main', 'root' => 'public', 'task_workspace_routed' => false,
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Provision', 'brief' => 'Provision.',
        'status' => $status, 'orchestration' => $orchestration,
    ]);
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'One', 'brief' => 'One.', 'status' => TaskStatus::Todo]);

    return $group;
}

function provision_operation_fakes(bool $routeFails = false, ?string $startingCommit = null, ?Closure $afterSetup = null): void
{
    mock(ManagedUserAccountResolver::class)->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    mock(InstanceDestinationGuard::class)->shouldReceive('assertUnoccupied');
    $source = mock(DevelopmentInstanceSourceLifecycle::class);
    $source->shouldReceive('prepare')->once()->withArgs(fn (Instance $instance, bool $allowExisting): bool => $instance->project->default_branch === 'main' && ! $allowExisting);
    $source->shouldReceive('inspectPrepared');
    $source->shouldReceive('inspectResolved')->andReturnUsing(fn (Instance $instance): DevelopmentSourceResolution => new DevelopmentSourceResolution((string) $instance->branch, (string) $instance->starting_commit));
    $source->shouldReceive('resolve')->once()->andReturnUsing(fn (Instance $instance): DevelopmentSourceResolution => new DevelopmentSourceResolution($instance->name, $startingCommit ?? str_repeat('a', 40)));
    $development = mock(DevelopmentInstanceProvisioner::class);
    $development->shouldReceive('reserve');
    $development->shouldReceive('prepare')->andReturnUsing(fn (Instance $instance): Instance => $instance);
    $development->shouldReceive('complete')->andReturnUsing(function (Instance $instance) use ($routeFails, $afterSetup): Instance {
        if ($routeFails) {
            throw new ResourceOperationException('route.projection_failed', 'Route projection failed.');
        }
        $route = Route::query()->create(['project_id' => $instance->project_id, 'provenance' => 'explicit', 'publication' => 'private', 'node_id' => $instance->node_id, 'domain' => 'task.provision.test', 'status' => RouteStatus::Pending]);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 1, 'weight' => 1]);
        $route->update(['status' => RouteStatus::Active]);
        $instance->update(['status' => InstanceState::Active]);
        $afterSetup?->__invoke();

        return $instance;
    });
}

function provision_operation_start(Task $group, ?string $key = null): Operation
{
    $response = test()->postJson('/api/v1/task-groups/'.$group->id.'/provision', [], $key === null ? [] : ['Idempotency-Key' => $key])
        ->assertAccepted()->assertJsonPath('data.kind', 'tasks.provision')->assertJsonPath('data.target_type', 'task_group')
        ->assertJsonPath('data.target_id', $group->id);

    return Operation::query()->findOrFail($response->json('data.id'));
}

describe('tasks.provision', function (): void {
    it('records the refusal contract for a tick group', function (): void {
        $this->travelTo('2026-10-22 12:00:00');
        $group = provision_operation_group(orchestration: TaskOrchestration::Tick);

        record_fixture($this->postJson('/api/v1/task-groups/'.$group->id.'/provision')->assertConflict(),
            'tasks/tasks-provision/not-orchestrated',
            ProvisionTaskGroupRequest::class,
            'POST /api/v1/task-groups/{group}/provision');
    });

    it('refuses an unsuitable group with 409 before creating an operation', function (TaskGroupStatus $status, TaskOrchestration $mode, string $code): void {
        $group = provision_operation_group($status, $mode);

        $this->postJson('/api/v1/task-groups/'.$group->id.'/provision')->assertConflict()->assertJsonPath('error.code', $code);

        expect(Operation::query()->count())->toBe(0);
        expect($group->fresh()->status)->toBe($status);
    })->with([
        [TaskGroupStatus::Todo, TaskOrchestration::Tick, 'tasks.not_orchestrated'],
        [TaskGroupStatus::Backlog, TaskOrchestration::External, 'tasks.not_todo'],
        [TaskGroupStatus::Reserved, TaskOrchestration::External, 'tasks.already_claimed'],
        [TaskGroupStatus::Running, TaskOrchestration::External, 'tasks.already_claimed'],
        [TaskGroupStatus::Completed, TaskOrchestration::External, 'tasks.group_closed'],
        [TaskGroupStatus::Failed, TaskOrchestration::External, 'tasks.group_closed'],
        [TaskGroupStatus::Cancelled, TaskOrchestration::External, 'tasks.group_closed'],
    ]);

    it('requires Gateway access and enabled tasks before recording work', function (): void {
        $group = provision_operation_group();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.254']);
        $this->postJson('/api/v1/task-groups/'.$group->id.'/provision')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => $this->gatewayNode->wireguard_ip]);
        app(TaskExtensionState::class)->disable();
        $this->postJson('/api/v1/task-groups/'.$group->id.'/provision')->assertConflict()->assertJsonPath('error.code', 'extension.disabled');
        expect(Operation::query()->count())->toBe(0);
    });

    it('holds one group across kinds with 409 target_busy', function (): void {
        $group = provision_operation_group();
        $other = app(OperationStore::class)->create('tasks.check', OperationKinds::TaskGroupTarget, $group->id, null, [], 'test', null);

        $this->postJson('/api/v1/task-groups/'.$group->id.'/provision')->assertConflict()
            ->assertJsonPath('error.code', 'operation.target_busy')->assertJsonPath('error.details.operation_id', $other->id);
        expect(Operation::query()->count())->toBe(1);
    });

    it('ends the group when its provision cannot be queued and replays that failure', function (): void {
        $group = provision_operation_group();
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('Connection refused'));

        $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', [], ['Idempotency-Key' => 'provision.dispatch'])
            ->assertStatus(503)->assertJsonPath('error.code', 'operation.dispatch_failed');

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Failed);
        expect($group->reserved_at)->toBeNull();
        $operation = Operation::query()->sole();
        expect($operation->status)->toBe(OperationStatus::Failed);
        $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', [], ['Idempotency-Key' => 'provision.dispatch'])
            ->assertAccepted()->assertJsonPath('data.id', $operation->id)->assertJsonPath('data.status', 'failed');
        expect(Operation::query()->count())->toBe(1);
    });

    it('replays a matching start that wins while this request waits for admission', function (): void {
        $group = provision_operation_group();
        $winner = null;
        $waiting = false;
        mock(TaskExecutionLock::class)->shouldReceive('synchronized')->twice()
            ->andReturnUsing(function (int $groupId, Closure $work) use ($group, &$winner, &$waiting): mixed {
                if (! $waiting) {
                    $waiting = true;
                    $winner = $this->postJson('/api/v1/task-groups/'.$groupId.'/provision', [], ['Idempotency-Key' => 'provision.race'])
                        ->assertAccepted()->json('data.id');
                    $group->update(['status' => TaskGroupStatus::Running]);
                }

                return $work();
            });

        $response = $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', [], ['Idempotency-Key' => 'provision.race']);

        $response->assertAccepted()->assertJsonPath('data.id', $winner);
        expect(Operation::query()->count())->toBe(1);
        $this->assertDatabaseCount('jobs', 1);
    });

    it('refuses a busy target without entering its held execution lock', function (): void {
        $group = provision_operation_group();
        $operation = provision_operation_start($group);
        $directory = sys_get_temp_dir().'/orbit-provision-busy-'.bin2hex(random_bytes(8));
        $holder = new NativeTaskExecutionLock($directory);
        // The independent request must answer without acquiring the lock held over remote work.
        mock(TaskExecutionLock::class)->shouldNotReceive('synchronized');
        try {
            $holder->synchronized($group->id, function () use ($group, $operation): void {
                $this->postJson('/api/v1/task-groups/'.$group->id.'/provision')->assertConflict()
                    ->assertJsonPath('error.code', 'operation.target_busy')
                    ->assertJsonPath('error.details.operation_id', $operation->id);
            });
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
        expect(Operation::query()->count())->toBe(1);
    });

    it('waits for capacity without releasing the reservation then succeeds without starting work', function (): void {
        $group = provision_operation_group();
        TaskVmSettingsBuilder::bind();
        $operation = provision_operation_start($group, 'provision.capacity');
        app(OperationRunner::class)->run($operation->id);
        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Reserved);
        expect($group->reserved_at)->not->toBeNull();

        provision_operation_fakes();
        $node = Node::query()->create([
            'name' => 'vm-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.130', 'wireguard_ip' => '10.44.0.130', 'user' => 'orbit',
            'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
        ]);
        $vm = test_task_vm($group, $node);
        $vm->update(['state' => TaskVmState::Provisioning]);
        $this->travel(10)->seconds();
        app(OperationRunner::class)->read($operation->id);
        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
        $vm->update(['state' => TaskVmState::Ready]);
        $this->travel(10)->seconds();
        app(OperationRunner::class)->read($operation->id);

        expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
        expect($operation->result)->toBe(['instance_id' => $group->fresh()->taskable_id, 'node_id' => $node->id, 'starting_commit' => str_repeat('a', 40), 'analysis_base' => $operation->state['analysis_base']]);
        expect($operation->attempts)->toBe(1);
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Running);
        expect($group->reserved_at)->toBeNull();
        expect($group->taskable->status)->toBe(InstanceState::SourceResolved);
        expect($group->tasks()->sole()->status)->toBe(TaskStatus::Todo);
        expect(TaskCheck::query()->count())->toBe(0);
        expect(AgentThread::query()->count())->toBe(0);
        expect(app(TaskCheckRunner::class)->starts)->toBe(0);
        $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', [], ['Idempotency-Key' => 'provision.capacity'])
            ->assertAccepted()->assertJsonPath('data.id', $operation->id)->assertJsonPath('data.status', 'succeeded');
        expect(Instance::query()->count())->toBe(1);
        expect(TaskVm::query()->where('group_id', $group->id)->count())->toBe(1);
    });

    it('finishes the Route before reporting a routed workspace ready', function (): void {
        $group = provision_operation_group();
        $group->project->update(['task_workspace_routed' => true]);
        test_task_vm($group, $this->gatewayNode);
        provision_operation_fakes();
        $operation = provision_operation_start($group);
        app(OperationRunner::class)->run($operation->id);

        expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
        expect($group->refresh()->taskable->status)->toBe(InstanceState::Active);
        expect($group->taskable->routes()->sole()->domain)->toBe('task.provision.test');
        expect(TaskCheck::query()->count())->toBe(0);
    });

    it('records the initial analysis base once at provision and retains unavailable bases without guessing later', function (bool $available): void {
        $this->travelTo('2026-11-01T10:00:00+00:00');
        $group = provision_operation_group();
        test_task_vm($group, $this->gatewayNode);
        provision_operation_fakes();
        $analysis = app(TaskAnalysisRunner::class);
        $analysis->initialBase = $available ? str_repeat('b', 40) : null;
        $operation = provision_operation_start($group, 'provision.fixed-base');
        app(OperationRunner::class)->run($operation->id);
        $operation->refresh();
        $instance = $group->fresh()->taskable;
        $recorded = ['base' => $analysis->initialBase, 'instance_id' => $instance->id,
            'repository' => $group->project->repository_url, 'branch' => $instance->branch];
        expect($operation->status)->toBe(OperationStatus::Succeeded)->and($operation->state['analysis_base'])->toBe($recorded)
            ->and($analysis->baseReads)->toBe(1)->and($analysis->starts)->toBe(0);
        $shown = $this->getJson('/api/v1/operations/'.$operation->id)->assertOk()
            ->assertJsonPath('data.result.starting_commit', str_repeat('a', 40))
            ->assertJsonPath('data.result.analysis_base', $available ? $recorded : null);
        record_fixture($shown, 'tasks/tasks-provision/'.($available ? 'pinned' : 'unavailable'), ShowOperationRequest::class, 'GET /api/v1/operations/{operation}');
        $this->getJson('/api/v1/operations?target_type=task_group&target_id='.$group->id)->assertOk()
            ->assertJsonPath('data.0.result.analysis_base', $available ? $recorded : null);
        $analysis->initialBase = str_repeat('c', 40);
        app(OperationRunner::class)->run($operation->id);
        $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', [], ['Idempotency-Key' => 'provision.fixed-base'])
            ->assertAccepted()->assertJsonPath('data.id', $operation->id)->assertJsonPath('data.result.analysis_base', $available ? $recorded : null);
        expect($operation->fresh()->state['analysis_base'])->toBe($recorded)->and($analysis->baseReads)->toBe(1);
    })->with([false, true]);

    it('publishes the real divergent merge base accepted by analyze and never follows moving origin main on replay', function (): void {
        $fixture = new TaskAnalysisAdapterFixture('normal');
        $this->directory = $fixture->checkout;
        try {
            $git = fn (array $arguments): string => trim(new Process(['git', ...$arguments], $fixture->checkout)->mustRun()->getOutput());
            $base = $git(['rev-parse', 'HEAD']);
            $git(['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'starting workspace']);
            $starting = $git(['rev-parse', 'HEAD']);
            $git(['update-ref', 'refs/remotes/origin/main', $base]);
            $group = provision_operation_group();
            test_task_vm($group, $this->gatewayNode);
            provision_operation_fakes(startingCommit: $starting);
            $native = check_runner(new LocalShellSshExecutor);
            $execution = new FakeTaskAnalysisRunner;
            $analysis = mock(TaskAnalysisRunner::class);
            $analysis->shouldReceive('initialAnalysisBase')->once()->andReturnUsing(function (Instance $instance) use ($native, $fixture): ?string {
                // Only the source/VM lifecycle is faked: base and entrypoint inspection run on this real checkout.
                $instance->update(['checkout_path' => $fixture->checkout]);

                return $native->initialAnalysisBase($instance);
            });
            $analysis->shouldReceive('inspectAnalysis')->andReturnUsing(fn (Instance $instance, string $pin): array => $native->inspectAnalysis($instance, $pin));
            $analysis->shouldReceive('startAnalysis')->once()->andReturnUsing(fn (Instance $instance, array $input, string $id) => $execution->startAnalysis($instance, $input, $id));
            $analysis->shouldReceive('recoverStart')->andReturnUsing(fn (Instance $instance, string $id) => $execution->recoverStart($instance, $id));
            $analysis->shouldReceive('readAnalysis')->andReturnUsing(fn (Instance $instance, $process): array => $execution->readAnalysis($instance, $process));
            $provision = provision_operation_start($group, 'provision.divergent');
            app(OperationRunner::class)->run($provision->id);
            $identity = $this->getJson('/api/v1/operations/'.$provision->id)->assertOk()->assertJsonPath('data.status', 'succeeded')
                ->assertJsonPath('data.result.starting_commit', $starting)->assertJsonPath('data.result.analysis_base.base', $base)->json('data.result');
            expect($starting)->not->toBe($base);
            $instance = $group->fresh()->taskable;
            $git(['checkout', '--quiet', '-b', $instance->branch]);
            $git(['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'later candidate']);
            $candidate = $git(['rev-parse', 'HEAD']);
            $git(['update-ref', 'refs/remotes/origin/main', $candidate]);
            expect($native->initialAnalysisBase($instance))->toBe($starting)->not->toBe($base);
            $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', [], ['Idempotency-Key' => 'provision.divergent'])
                ->assertAccepted()->assertJsonPath('data.result', $identity);
            $inspection = $native->inspectAnalysis($instance, $base);
            $execution->head = $inspection['head'];
            $execution->tree = $inspection['tree'];
            $checks = new FakeTaskCheckRunner;
            $checks->head = $execution->head;
            $checks->tree = $execution->tree;
            app()->instance(TaskCheckRunner::class, $checks);
            $plan = ['asserted_by' => 'orchestrator', 'complete' => true, 'id' => 'public-pin', 'paths' => [['change' => 'add', 'path' => 'new.php']],
                'revision' => 1, 'source' => ['brief_id' => 'brief:1', 'revision_sha256' => str_repeat('d', 64)]];
            $input = ['subtask' => $group->tasks()->sole()->id, 'plan' => $plan, 'plan_sha256' => hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)),
                'base' => $identity['analysis_base']['base'], 'head' => $inspection['head'], 'tree' => $inspection['tree']];
            $id = $this->postJson('/api/v1/task-groups/'.$group->id.'/analyze', $input, ['Idempotency-Key' => 'analyze.public-pin'])->assertAccepted()->json('data.id');
            app(OperationRunner::class)->run($id);
            $this->travel(10)->seconds();
            app(OperationRunner::class)->read($id);
            $this->getJson('/api/v1/operations/'.$id)->assertOk()->assertJsonPath('data.status', 'succeeded')
                ->assertJsonPath('data.result.provenance.base', $base);
            // The tempting starting_commit substitute is rejected before any second dispatch.
            $wrong = $this->postJson('/api/v1/task-groups/'.$group->id.'/analyze', [...$input, 'base' => $starting], ['Idempotency-Key' => 'analyze.wrong-pin'])->assertAccepted()->json('data.id');
            app(OperationRunner::class)->run($wrong);
            $this->getJson('/api/v1/operations/'.$wrong)->assertOk()->assertJsonPath('data.error_code', 'tasks.analysis_stale')->assertJsonPath('data.result', null);
            expect($execution->starts)->toBe(1);
        } finally {
            $fixture->close();
        }
    });

    it('recovers the public provision identity after release before terminal persistence without resolving again', function (): void {
        $group = provision_operation_group();
        test_task_vm($group, $this->gatewayNode);
        provision_operation_fakes();
        $analysis = app(TaskAnalysisRunner::class);
        $analysis->initialBase = str_repeat('b', 64);
        $operation = provision_operation_start($group, 'provision.recovery');
        // Stop the worker after the kind has durably completed its steps, before
        // OperationRunner persists its returned outcome. No exception cleanup runs.
        $store = app(OperationStore::class);
        $store->claim($operation, 'lost-provision-worker:123:1');
        app(TaskProvisionOperation::class)->run($operation, new OperationRun($operation, $store));
        $operation->refresh();
        expect($operation->result)->toBeNull()->and($operation->status)->toBe(OperationStatus::Running);
        $identity = $operation->state['steps']['route'];
        expect($identity['analysis_base']['base'])->toBe(str_repeat('b', 64))
            ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'provision')->sole()->status)->toBe('released');
        $analysis->initialBase = str_repeat('c', 40);
        $instance = $group->fresh()->taskable;
        $instance->update(['branch' => 'moved-branch', 'starting_commit' => str_repeat('d', 40)]);
        $group->project->update(['repository_url' => 'git@github.com:other/repository.git', 'default_branch' => 'changed']);
        $operation->update(['status' => 'queued', 'worker' => null]);
        app(OperationRunner::class)->run($operation->id);
        $this->getJson('/api/v1/operations/'.$operation->id)->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result', $identity);
        $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', [], ['Idempotency-Key' => 'provision.recovery'])
            ->assertAccepted()->assertJsonPath('data.result', $identity);
        expect($analysis->baseReads)->toBe(1);
    });

    it('publishes null for an invalid retained provision pin instead of substituting workspace identity', function (array $changes): void {
        $group = provision_operation_group();
        test_task_vm($group, $this->gatewayNode);
        provision_operation_fakes();
        $operation = provision_operation_start($group);
        // The retained checkpoint must be validated even when it survived a previous worker.
        $record = ['base' => str_repeat('b', 40), 'instance_id' => 1, 'repository' => $group->project->repository_url, 'branch' => 'main'];
        $operation->update(['state' => ['analysis_base' => [...$record, ...$changes]]]);
        app(OperationRunner::class)->run($operation->id);
        $this->getJson('/api/v1/operations/'.$operation->id)->assertOk()->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.result.analysis_base', null)->assertJsonPath('data.result.starting_commit', str_repeat('a', 40));
        expect(app(TaskAnalysisRunner::class)->baseReads)->toBe(0);
    })->with([
        [['base' => null]], [['base' => 'short']], [['base' => str_repeat('B', 40)]],
        [['instance_id' => 999]], [['instance_id' => '1']],
        [['repository' => 'https://user:secret-sentinel@example.com/private.git']],
        [['repository' => "git@github.com:acme/repo.git\n"]], [['branch' => 'main..secret']], [['branch' => "main\n"]],
    ]);

    it('ends the group after a Route effect fails and never retries it', function (): void {
        $group = provision_operation_group();
        $group->project->update(['task_workspace_routed' => true]);
        test_task_vm($group, $this->gatewayNode);
        provision_operation_fakes(routeFails: true);
        $operation = provision_operation_start($group);
        app(OperationRunner::class)->run($operation->id);

        expect($operation->refresh()->error_code)->toBe('route.projection_failed');
        expect($operation->failed_step)->toBe('route');
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Failed);
        expect($group->reserved_at)->toBeNull();
        app(OperationRunner::class)->run($operation->id);
        expect($operation->refresh()->attempts)->toBe(1);
        expect(Instance::query()->count())->toBe(1);
    });

    it('never repeats an effect after losing its worker', function (string $step): void {
        $group = provision_operation_group();
        $group->update(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now()]);
        $store = app(OperationStore::class);
        $operation = $store->create(OperationKinds::TasksProvision, OperationKinds::TaskGroupTarget, $group->id, null, [], 'test', null);
        $store->claim($operation, 'gone-host:4242:1');
        $run = new OperationRun($operation, $store);
        $names = ['provision', 'workspace', 'workspace_lease', 'route'];
        $run->plan(array_map(fn (string $name): OperationStepPlan => new OperationStepPlan($name, $name === 'workspace_lease'), $names));
        foreach ($operation->steps()->get() as $planned) {
            $planned->update(['status' => $planned->name === $step ? 'running' : 'succeeded']);
            if ($planned->name === $step) {
                break;
            }
        }
        $operation->update(['current_step' => $step]);
        mock(DevelopmentInstanceSourceLifecycle::class)->shouldNotReceive('prepare');
        mock(DevelopmentInstanceProvisioner::class)->shouldNotReceive('complete');

        expect(app(OperationSweeper::class)->sweep())->toBe(1);
        run_operation_jobs();

        expect($operation->refresh()->error_code)->toBe('operation.worker_lost');
        expect($operation->failed_step)->toBe($step);
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Failed);
        expect($group->reserved_at)->toBeNull();
    })->with(['workspace', 'route']);

    it('fails and ends the group for disabled or invalid task VM configuration', function (bool $invalid): void {
        $group = provision_operation_group();
        if ($invalid) {
            config(['orbit.task_vms.enabled' => true, 'orbit.task_vms.wireguard_range' => 'invalid']);
            app()->forgetInstance(TaskVmSettings::class);
        } else {
            app()->instance(TaskVmSettings::class, TaskVmSettingsBuilder::off());
        }
        $operation = provision_operation_start($group);
        app(OperationRunner::class)->run($operation->id);

        expect($operation->refresh()->status)->toBe(OperationStatus::Failed);
        expect($operation->error_code)->toBe('task_vm.invalid_config');
        expect($operation->failed_step)->toBe('provision');
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Failed);
        expect($group->reserved_at)->toBeNull();
    })->with([false, true]);

    it('ends a failed VM provision and releases it for existing cleanup without retrying', function (): void {
        $group = provision_operation_group();
        $vm = test_task_vm($group, $this->gatewayNode);
        $vm->update(['state' => TaskVmState::Provisioning]);
        $operation = provision_operation_start($group);
        app(OperationRunner::class)->run($operation->id);
        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
        $vm->update(['state' => TaskVmState::Failed, 'error_code' => 'task_vm.bootstrap_failed', 'error_message' => 'Failed.']);
        $this->travel(10)->seconds();
        app(OperationRunner::class)->read($operation->id);
        expect($operation->refresh()->error_code)->toBe('task_vm.bootstrap_failed');
        expect($operation->status)->toBe(OperationStatus::Failed);
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Failed);
        expect($group->reserved_at)->toBeNull();
        Queue::fake([DestroyTaskVm::class]);
        app(DestroyEndedTaskVmsAction::class)->execute();
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $vm->id);
    });

    it('cancels a waiting provision before group cancellation and clears its reservation for cleanup', function (): void {
        $group = provision_operation_group();
        $vm = test_task_vm($group, $this->gatewayNode);
        $vm->update(['state' => TaskVmState::Provisioning]);
        $operation = provision_operation_start($group);
        app(OperationRunner::class)->run($operation->id);

        $this->postJson('/api/v1/task-groups/'.$group->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');

        expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled);
        expect($group->refresh()->reserved_at)->toBeNull();
        Queue::fake([DestroyTaskVm::class]);
        app(DestroyEndedTaskVmsAction::class)->execute();
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $vm->id);
        $this->travel(10)->seconds();
        app(OperationRunner::class)->read($operation->id);
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled);
        expect(Instance::query()->count())->toBe(0);
    });

    it('completes a group only after cancelling its open operation', function (): void {
        $group = provision_operation_group(TaskGroupStatus::WaitingForReview);
        $operation = app(OperationStore::class)->create(OperationKinds::TasksProvision, OperationKinds::TaskGroupTarget, $group->id, null, [], 'test', null);

        $this->postJson('/api/v1/task-groups/'.$group->id.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');

        expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled);
    });

    it('keeps provisioning after a premature completion is refused', function (): void {
        $group = provision_operation_group();
        TaskVmSettingsBuilder::bind();
        $operation = provision_operation_start($group);
        app(OperationRunner::class)->run($operation->id);
        $reservedAt = $group->refresh()->reserved_at;

        $this->postJson('/api/v1/task-groups/'.$group->id.'/complete')->assertConflict()->assertJsonPath('error.code', 'tasks.not_settling');

        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
        expect($operation->cancel_requested_at)->toBeNull();
        expect($operation->lock_key)->toBe('task_group:'.$group->id);
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Reserved);
        expect($group->reserved_at)->toEqual($reservedAt);
        $this->travel(10)->seconds();
        app(OperationRunner::class)->read($operation->id);
        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
        expect($operation->next_read_at)->toEqual(now()->addSeconds(10));
    });

    it('leaves an open provision untouched when group cancellation is disabled', function (): void {
        $group = provision_operation_group();
        TaskVmSettingsBuilder::bind();
        $operation = provision_operation_start($group);
        app(OperationRunner::class)->run($operation->id);
        app(TaskExtensionState::class)->disable();

        $this->postJson('/api/v1/task-groups/'.$group->id.'/cancel')->assertConflict()->assertJsonPath('error.code', 'extension.disabled');

        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
        expect($operation->cancel_requested_at)->toBeNull();
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Reserved);
        expect($group->reserved_at)->not->toBeNull();
    });

    it('keeps disabled task VMs as a tick claim wait', function (): void {
        $group = provision_operation_group(orchestration: TaskOrchestration::Tick);
        app()->instance(TaskVmSettings::class, TaskVmSettingsBuilder::off());

        expect(app(TaskScheduler::class)->claimNext())->toBeNull();
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Todo);
        expect($group->capacity_wait_reason)->toBe('Task VM: task VMs are not enabled on this Gateway.');
        expect(Operation::query()->count())->toBe(0);
    });
});

describe('optional provision worker', function (): void {
    it('starts after workspace readiness without retaining the token in records or output', function (): void {
        $group = provision_operation_group();
        test_task_vm($group, $this->gatewayNode);
        provision_operation_fakes();
        $token = 'group-2074.'.str_repeat('a', 64);
        $agents = (new FakeAgentCommandRunner)->answer('swamp.start-worker', function (array $input) use ($group, $token): array {
            expect($group->fresh()->taskable->status)->toBe(InstanceState::SourceResolved);
            expect($input['enrollment_token'])->toBe($token);
            expect((array) $input['labels'])->toBe(['group' => (string) $group->id]);
            expect($input['workspace'])->toBe($group->fresh()->taskable->checkout_path);

            return ['started' => true];
        });
        app()->instance(AgentCommandRunner::class, $agents);
        Log::spy();
        $payload = ['worker' => ['orchestrator_url' => 'wss://swamp.orbit', 'enrollment_token' => $token, 'labels' => ['group' => (string) $group->id]]];
        $response = $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', $payload, ['Idempotency-Key' => 'worker.start'])->assertAccepted();
        $operation = Operation::query()->findOrFail($response->json('data.id'));
        $reference = $operation->input['worker']['enrollment_reference'];
        expect(app(TaskWorkerEnrollment::class)->read($reference))->toBe($token);
        expect(Cache::get('tasks:worker-enrollment:'.$reference))->not->toContain($token);
        expect($response->getContent())->not->toContain($token);
        app(OperationRunner::class)->run($operation->id);
        expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
        expect(app(TaskWorkerEnrollment::class)->read($reference))->toBeNull();
        expect(json_encode($operation->toArray()))->not->toContain($token);
        expect(json_encode($operation->steps()->get()->toArray()))->not->toContain($token);
        expect(json_encode(DB::table('jobs')->get()->toArray()))->not->toContain($token);
        expect(json_encode(DB::table('activity_log')->get()->toArray()))->not->toContain($token);
        $this->getJson('/api/v1/operations/'.$operation->id)->assertOk()->assertDontSee($token);
        $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', $payload, ['Idempotency-Key' => 'worker.start'])->assertAccepted()->assertJsonPath('data.id', $operation->id);
        expect($agents->calls)->toHaveCount(1);
        Log::shouldNotHaveReceived('error');
    });
    it('starts no worker when enrollment is omitted', function (): void {
        $group = provision_operation_group();
        test_task_vm($group, $this->gatewayNode);
        provision_operation_fakes();
        $agents = new FakeAgentCommandRunner;
        app()->instance(AgentCommandRunner::class, $agents);
        $operation = provision_operation_start($group);
        app(OperationRunner::class)->run($operation->id);
        expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
        expect($agents->calls)->toBe([]);
    });
    it('destroys the worker VM when a provisioned group is cancelled', function (): void {
        $group = provision_operation_group();
        $node = Node::query()->create([
            'name' => 'worker-vm', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.129', 'wireguard_ip' => '10.44.0.129',
            'user' => 'orbit', 'settings' => ['apps' => ['path' => '/home/orbit/apps']],
        ]);
        $vm = test_task_vm($group, $node);
        provision_operation_fakes();
        bind_task_node_reachability();
        $provider = new RecordingTaskVmProvider;
        app()->instance(TaskVmProvider::class, $provider);
        $agents = (new FakeAgentCommandRunner)->answer('swamp.start-worker', ['started' => true])->answer('swamp.stop-worker', ['stopped' => true]);
        app()->instance(AgentCommandRunner::class, $agents);
        $response = $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', ['worker' => [
            'orchestrator_url' => 'wss://swamp.orbit', 'enrollment_token' => 'group.'.str_repeat('f', 64),
        ]])->assertAccepted();
        $operation = Operation::query()->findOrFail($response->json('data.id'));
        app(OperationRunner::class)->run($operation->id);
        expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
        expect($agents->commands())->toBe(['swamp.start-worker']);
        expect($agents->calls[0]['node'])->toBe($node->name);
        $workspace = $group->refresh()->taskable;
        expect($workspace)->toBeInstanceOf(Instance::class);
        expect($provider->vmCalls)->toBe([]);
        Queue::fake([DestroyTaskVm::class]);

        $provider->onDestroy = function () use ($agents): void {
            expect($agents->commands())->toBe(['swamp.start-worker', 'swamp.stop-worker']);
        };
        // Worker stop precedes deletion, even when a later teardown step must wait.
        $this->postJson('/api/v1/task-groups/'.$group->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');

        expect($agents->commands())->toBe(['swamp.start-worker', 'swamp.stop-worker']);
        expect($vm->fresh()->worker_stop_required)->toBeFalse();
        expect($provider->vmCalls)->toBe(['destroy '.$vm->name]);
        expect($vm->refresh()->state)->toBe(TaskVmState::Destroying);
        expect(Instance::query()->find($workspace->id))->toBeNull();
        expect($group->refresh()->taskable_id)->toBeNull();
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $vm->id);
    });
    it('clears staged enrollment when a waiting provision is cancelled', function (): void {
        $group = provision_operation_group();
        TaskVmSettingsBuilder::bind();
        $response = $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', ['worker' => ['orchestrator_url' => 'wss://swamp.orbit', 'enrollment_token' => 'group.'.str_repeat('b', 64)]])->assertAccepted();
        $operation = Operation::query()->findOrFail($response->json('data.id'));
        app(OperationRunner::class)->run($operation->id);
        $this->postJson('/api/v1/task-groups/'.$group->id.'/cancel')->assertOk();
        expect(app(TaskWorkerEnrollment::class)->read($operation->input['worker']['enrollment_reference']))->toBeNull();
    });
    it('withholds remote diagnostics and clears enrollment on startup failure', function (): void {
        $group = provision_operation_group();
        test_task_vm($group, $this->gatewayNode);
        provision_operation_fakes();
        $token = 'group.'.str_repeat('c', 64);
        $agents = (new FakeAgentCommandRunner)->answer('swamp.start-worker', static fn (): array => throw new RuntimeException($token))->answer('swamp.stop-worker', ['stopped' => true]);
        app()->instance(AgentCommandRunner::class, $agents);
        Log::spy();
        $response = $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', ['worker' => ['orchestrator_url' => 'wss://swamp.orbit', 'enrollment_token' => $token]])->assertAccepted();
        $operation = Operation::query()->findOrFail($response->json('data.id'));
        app(OperationRunner::class)->run($operation->id);
        expect($operation->refresh()->error_code)->toBe('tasks.worker_start_failed');
        expect(app(TaskWorkerEnrollment::class)->read($operation->input['worker']['enrollment_reference']))->toBeNull();
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => ! str_contains(json_encode([$message, $context]), $token));
    });
    it('rejects unsafe worker settings before recording an operation', function (array $worker): void {
        $group = provision_operation_group();
        $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', ['worker' => $worker])->assertUnprocessable();
        expect(Operation::query()->count())->toBe(0);
    })->with([
        'missing token' => [['orchestrator_url' => 'wss://swamp.orbit']],
        'plaintext connection' => [['orchestrator_url' => 'ws://swamp.orbit', 'enrollment_token' => 'group.'.str_repeat('a', 64)]],
        'url credential' => [['orchestrator_url' => 'wss://user:secret@swamp.orbit', 'enrollment_token' => 'group.'.str_repeat('a', 64)]],
        'extra field' => [['orchestrator_url' => 'wss://swamp.orbit', 'enrollment_token' => 'group.'.str_repeat('a', 64), 'command' => 'arbitrary']],
        'label injection' => [['orchestrator_url' => 'wss://swamp.orbit', 'enrollment_token' => 'group.'.str_repeat('a', 64), 'labels' => ['group' => "bad\nlabel"]]],
    ]);
});

it('keeps provision usable when optional enrollment expires during capacity or setup waits', function (string $phase): void {
    $group = provision_operation_group();
    TaskVmSettingsBuilder::bind();
    if ($phase === 'setup') {
        $group->project->update(['task_workspace_routed' => true]);
        test_task_vm($group, $this->gatewayNode);
        provision_operation_fakes(afterSetup: fn () => $this->travel(31)->minutes());
    }
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $response = $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', ['worker' => [
        'orchestrator_url' => 'wss://swamp.orbit', 'enrollment_token' => 'expired.'.str_repeat('d', 64),
    ]])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    app(OperationRunner::class)->run($operation->id);
    if ($phase === 'capacity') {
        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
        $this->travel(31)->minutes();
        test_task_vm($group, $this->gatewayNode);
        provision_operation_fakes();
        app(OperationRunner::class)->read($operation->id);
    }
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->error_code)->toBeNull();
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running);
    expect($agents->calls)->toBe([]);
    expect(TaskVm::query()->count())->toBe(1);
    expect($group->taskable_id)->not->toBeNull();
    expect(app(TaskWorkerEnrollment::class)->read($operation->input['worker']['enrollment_reference']))->toBeNull();
    $agents->answer('swamp.start-worker', ['started' => true]);
    $worker = worker_operation_start($group);
    app(OperationRunner::class)->run($worker->id);
    expect($worker->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($agents->commands())->toBe(['swamp.start-worker']);
})->with(['capacity', 'setup']);

it('removes worker startup material when the workspace Route fails before worker startup', function (): void {
    $group = provision_operation_group();
    $group->project->update(['task_workspace_routed' => true]);
    test_task_vm($group, $this->gatewayNode);
    provision_operation_fakes(routeFails: true);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $response = $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', ['worker' => [
        'orchestrator_url' => 'wss://swamp.orbit', 'enrollment_token' => 'group.'.str_repeat('e', 64),
    ]])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    app(OperationRunner::class)->run($operation->id);
    expect($operation->refresh()->error_code)->toBe('route.projection_failed');
    expect($agents->calls)->toBe([]);
    expect(app(TaskWorkerEnrollment::class)->read($operation->input['worker']['enrollment_reference']))->toBeNull();
});

/** @return array{Task, TaskVm} */
function worker_operation_workspace(): array
{
    $group = provision_operation_group(TaskGroupStatus::Running);
    $node = Node::query()->create([
        'name' => 'worker-vm', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.129', 'wireguard_ip' => '10.44.0.129', 'user' => 'orbit',
    ]);
    $workspace = test_task_workspace($group, ['node_id' => $node->id, 'branch' => 'task-'.$group->id, 'starting_commit' => str_repeat('a', 40)]);
    $vm = test_task_vm($group, $node);
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    TaskVmSettingsBuilder::bind();

    return [$group->refresh(), $vm];
}

function worker_operation_start(Task $group, string $key = 'worker.1', string $secret = 'a'): Operation
{
    $response = test()->postJson('/api/v1/task-groups/'.$group->id.'/worker', ['worker' => [
        'orchestrator_url' => 'wss://swamp.orbit', 'enrollment_token' => 'group.'.str_repeat($secret, 64),
        'labels' => ['group' => (string) $group->id],
    ]], ['Idempotency-Key' => $key])->assertAccepted()->assertJsonPath('data.kind', 'tasks.worker');

    return Operation::query()->findOrFail($response->json('data.id'));
}

it('starts a worker again on the same group after parking and resuming its VM', function (): void {
    [$group, $vm] = worker_operation_workspace();
    $provider = new RecordingTaskVmProvider;
    app()->instance(TaskVmProvider::class, $provider);
    $agents = (new FakeAgentCommandRunner)
        ->answer('swamp.start-worker', ['started' => true])
        ->answer('agent.status', ['version' => '0.5.0', 'sha256' => str_repeat('a', 64), 'platform' => 'linux', 'architecture' => 'x86_64', 'observer' => 'active']);
    app()->instance(AgentCommandRunner::class, $agents);
    Http::fake(['http://10.44.0.129:3774/capabilities' => Http::response(['models' => []])]);
    $first = worker_operation_start($group);
    app(OperationRunner::class)->run($first->id);
    expect($first->refresh()->status)->toBe(OperationStatus::Succeeded);
    $group->tasks()->update(['status' => TaskStatus::Completed, 'settled_at' => now()->subHour()]);
    $group->update(['status' => TaskGroupStatus::WaitingForReview, 'pr_url' => 'https://github.com/acme/provision/pull/1', 'settled_at' => now()->subHour()]);
    $vm->update(['ready_at' => now()->subHour()]);
    $workspaceId = $group->taskable_id;
    Queue::fake([ParkTaskVm::class, ResumeTaskVm::class]);

    expect(app(ParkIdleTaskVmsAction::class)->execute())->toBe(1);
    app()->call([new ParkTaskVm($vm->id), 'handle']);
    expect($vm->refresh()->state)->toBe(TaskVmState::Parked);
    $second = worker_operation_start($group, 'worker.2', 'b');
    app(OperationRunner::class)->run($second->id);
    expect($second->refresh()->status)->toBe(OperationStatus::Waiting);
    expect(array_column($agents->calls, 'command'))->toBe(['swamp.start-worker']);
    Queue::assertPushed(ResumeTaskVm::class, fn (ResumeTaskVm $job): bool => $job->taskVmId === $vm->id);
    app()->call([new ResumeTaskVm($vm->id), 'handle']);
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($second->id);

    expect($second->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($vm->refresh()->state)->toBe(TaskVmState::Ready);
    expect($provider->vmCalls)->toBe(['stop '.$vm->name, 'start '.$vm->name]);
    $starts = array_values(array_filter($agents->calls, fn (array $call): bool => $call['command'] === 'swamp.start-worker'));
    expect($starts)->toHaveCount(2);
    expect($starts[1]['input']['enrollment_id'])->not->toBe($starts[0]['input']['enrollment_id']);
    expect($starts[1]['input']['enrollment_token'])->toBe('group.'.str_repeat('b', 64));
    expect($group->refresh()->taskable_id)->toBe($workspaceId);
    expect($group->status)->toBe(TaskGroupStatus::WaitingForReview);
    expect(TaskVm::query()->count())->toBe(1);
    expect(app(TaskWorkerEnrollment::class)->read($second->input['worker']['enrollment_reference']))->toBeNull();
});

it('replays worker and provision keys with a new token without retaining token-derived fingerprints', function (string $route): void {
    [$group, $vm] = worker_operation_workspace();
    if ($route === 'provision') {
        $group->taskable()->dissociate();
        $group->status = TaskGroupStatus::Todo;
        $group->save();
        Instance::query()->where('task_workspace_owner_id', $group->id)->delete();
    }
    $payload = ['worker' => ['orchestrator_url' => 'wss://swamp.orbit', 'enrollment_token' => 'old.'.str_repeat('a', 64)]];
    $url = '/api/v1/task-groups/'.$group->id.'/'.$route;
    $response = $this->postJson($url, $payload, ['Idempotency-Key' => 'worker.retry'])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    unset($payload['worker']['enrollment_token']);
    $expected = hash('sha256', json_encode(['target' => ['group' => $group->id], 'input' => json_encode($payload, JSON_PRESERVE_ZERO_FRACTION)], JSON_PRESERVE_ZERO_FRACTION));
    expect($operation->idempotency_fingerprint)->toBe($expected);
    $payload['worker']['enrollment_token'] = 'fresh.'.str_repeat('b', 64);
    $this->postJson($url, $payload, ['Idempotency-Key' => 'worker.retry'])->assertAccepted()->assertJsonPath('data.id', $operation->id);
    expect(Operation::query()->count())->toBe(1);
    expect(app(TaskWorkerEnrollment::class)->read($operation->input['worker']['enrollment_reference']))->toBe('old.'.str_repeat('a', 64));
    $payload['worker']['orchestrator_url'] = 'wss://other.orbit';
    $this->postJson($url, $payload, ['Idempotency-Key' => 'worker.retry'])->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
})->with(['worker', 'provision']);

it('fails expired worker enrollment before resuming a parked VM', function (): void {
    [$group, $vm] = worker_operation_workspace();
    $vm->update(['state' => TaskVmState::Parked]);
    Queue::fake([ResumeTaskVm::class]);
    $operation = worker_operation_start($group);
    Cache::forget('tasks:worker-enrollment:'.$operation->input['worker']['enrollment_reference']);
    app(OperationRunner::class)->run($operation->id);
    expect($operation->refresh()->error_code)->toBe('tasks.worker_enrollment_expired');
    Queue::assertNotPushed(ResumeTaskVm::class);
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running);
});

it('requires worker enrollment, a key, an open external group and an existing VM workspace', function (): void {
    [$group, $vm] = worker_operation_workspace();
    $url = '/api/v1/task-groups/'.$group->id.'/worker';
    $this->postJson($url, [])->assertUnprocessable();
    $this->postJson($url, [], ['Idempotency-Key' => 'worker.invalid'])->assertUnprocessable();
    $vm->delete();
    $payload = ['worker' => ['orchestrator_url' => 'wss://swamp.orbit', 'enrollment_token' => 'group.'.str_repeat('a', 64)]];
    $this->postJson($url, $payload, ['Idempotency-Key' => 'worker.no-vm'])->assertConflict()->assertJsonPath('error.code', 'tasks.no_workspace');
    Task::topLevel()->whereKey($group->id)->update(['orchestration' => TaskOrchestration::Tick]);
    $group->refresh();
    $this->postJson($url, $payload, ['Idempotency-Key' => 'worker.tick'])->assertConflict()->assertJsonPath('error.code', 'tasks.not_orchestrated');
    Task::topLevel()->whereKey($group->id)->update(['orchestration' => TaskOrchestration::External, 'status' => TaskGroupStatus::Cancelled]);
    $group->refresh();
    $this->postJson($url, $payload, ['Idempotency-Key' => 'worker.closed'])->assertConflict()->assertJsonPath('error.code', 'tasks.group_closed');
    expect(Operation::query()->count())->toBe(0);
});

it('stops the worker before preservation can leave the VM alive', function (string $path): void {
    [$group, $vm] = worker_operation_workspace();
    bind_task_node_reachability();
    $vm->update(['worker_stop_required' => true]);
    $group->update(['status' => TaskGroupStatus::WaitingForReview]);
    $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
    TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => str_repeat('c', 40), 'source' => TaskReviewedCommitSource::OrbitPush]);
    $agents = (new FakeAgentCommandRunner)->answer('swamp.stop-worker', ['stopped' => true]);
    app()->instance(AgentCommandRunner::class, $agents);
    mock(TaskPullRequestPublisher::class)->shouldReceive('push')->once()->andReturnUsing(function () use ($vm, $agents): void {
        expect($agents->commands())->toBe(['swamp.stop-worker']);
        expect($vm->fresh()->worker_stop_required)->toBeFalse();
        throw new TaskPullRequestException('Preservation is waiting.');
    });
    $provider = new RecordingTaskVmProvider;
    app()->instance(TaskVmProvider::class, $provider);

    if ($path === 'complete') {
        $this->postJson('/api/v1/task-groups/'.$group->id.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
    } else {
        expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($group))->toThrow(TaskPullRequestException::class);
        expect($group->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview);
    }

    expect($provider->vmCalls)->toBe([]);
    expect($vm->fresh()->state)->toBe(TaskVmState::Ready);
    expect($group->fresh()->taskable_id)->not->toBeNull();
})->with(['complete', 'workspace']);

it('powers off an unreachable worker VM so cancellation succeeds and deletion can proceed', function (): void {
    [$group, $vm] = worker_operation_workspace();
    bind_task_node_reachability(unreachable: true);
    $vm->update(['worker_stop_required' => true]);
    $agents = (new FakeAgentCommandRunner)->answer('swamp.stop-worker', static fn (): array => throw new RuntimeException('unsafe diagnostics'));
    app()->instance(AgentCommandRunner::class, $agents);
    $provider = new RecordingTaskVmProvider;
    app()->instance(TaskVmProvider::class, $provider);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled')->assertDontSee('unsafe diagnostics');
    expect($vm->fresh()->worker_stop_required)->toBeFalse();
    expect($vm->fresh()->state)->toBe(TaskVmState::Parked);
    expect($provider->calls)->toBe(['observe', 'stop']);
    app(DeleteTaskVmAction::class)->execute($vm);
    expect($provider->calls)->toBe(['observe', 'stop', 'destroy']);
    expect($group->fresh()->taskable_id)->toBeNull();
});

it('completes a settling group after powering off its unreachable worker VM', function (): void {
    [$group, $vm] = worker_operation_workspace();
    $group->update(['status' => TaskGroupStatus::WaitingForReview]);
    $vm->update(['worker_stop_required' => true]);
    app()->instance(AgentCommandRunner::class, (new FakeAgentCommandRunner)->answer('swamp.stop-worker', static fn (): array => throw new RuntimeException('unreachable')));
    $provider = new RecordingTaskVmProvider;
    app()->instance(TaskVmProvider::class, $provider);
    Queue::fake([ResumeTaskVm::class]);

    $this->postJson('/api/v1/task-groups/'.$group->id.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');

    expect($vm->fresh()->worker_stop_required)->toBeFalse();
    expect($vm->fresh()->state)->toBe(TaskVmState::Parked);
    expect($provider->calls)->toBe(['observe', 'stop']);
});

it('still deletes the VM when both agent stop and provider power-off fail', function (): void {
    [$group, $vm] = worker_operation_workspace();
    $group->update(['status' => TaskGroupStatus::Completed]);
    $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
    $commit = TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => str_repeat('c', 40), 'source' => TaskReviewedCommitSource::OrbitPush]);
    $vm->update(['worker_stop_required' => true]);
    app()->instance(AgentCommandRunner::class, (new FakeAgentCommandRunner)->answer('swamp.stop-worker', static fn (): array => throw new RuntimeException('unsafe agent diagnostics')));
    $provider = new RecordingTaskVmProvider;
    $provider->stopError = new RuntimeException('unsafe provider diagnostics');
    $provider->onDestroy = function (TaskVm $deleting): void {
        expect($deleting->state)->toBe(TaskVmState::Destroying);
        expect($deleting->worker_stop_required)->toBeTrue();
    };
    app()->instance(TaskVmProvider::class, $provider);

    app(DeleteTaskVmAction::class)->execute($vm);

    expect($provider->calls)->toBe(['observe', 'stop', 'destroy']);
    expect($vm->fresh()->worker_stop_required)->toBeFalse();
    expect($vm->fresh()->state)->toBe(TaskVmState::Destroying);
    expect($group->fresh()->taskable_id)->toBeNull();
    expect($commit->fresh()->sha)->toBe(str_repeat('c', 40));
});

it('ends a failed provision after powering off a worker whose startup response was lost', function (): void {
    $group = provision_operation_group();
    $vm = test_task_vm($group, $this->gatewayNode);
    provision_operation_fakes();
    $agents = (new FakeAgentCommandRunner)
        ->answer('swamp.start-worker', static fn (): array => throw new RuntimeException('lost startup response'))
        ->answer('swamp.stop-worker', static fn (): array => throw new RuntimeException('unreachable'));
    app()->instance(AgentCommandRunner::class, $agents);
    $provider = new RecordingTaskVmProvider;
    app()->instance(TaskVmProvider::class, $provider);
    $response = $this->postJson('/api/v1/task-groups/'.$group->id.'/provision', ['worker' => [
        'orchestrator_url' => 'wss://swamp.orbit', 'enrollment_token' => 'group.'.str_repeat('a', 64),
    ]])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));

    app(OperationRunner::class)->run($operation->id);

    expect($operation->refresh()->error_code)->toBe('tasks.worker_start_failed');
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Failed);
    expect($vm->fresh()->state)->toBe(TaskVmState::Parked);
    expect($vm->fresh()->worker_stop_required)->toBeFalse();
    expect($provider->calls)->toBe(['observe', 'stop']);
});

it('accepts provider proof that an unreachable worker VM is stopped or absent', function (bool $absent): void {
    [$group, $vm] = worker_operation_workspace();
    $vm->update(['worker_stop_required' => true, 'state' => TaskVmState::Parked]);
    $agents = (new FakeAgentCommandRunner)->answer('swamp.stop-worker', static fn (): array => throw new RuntimeException('unreachable'));
    app()->instance(AgentCommandRunner::class, $agents);
    $provider = new RecordingTaskVmProvider;
    $provider->observation = $absent ? null : new VmObservation(false, null);
    app()->instance(TaskVmProvider::class, $provider);
    app(TaskVmWorker::class)->stopGroup($group);
    expect($vm->fresh()->worker_stop_required)->toBeFalse();
    expect($provider->calls)->toBe(['observe']);
})->with([false, true]);

it('clears cancelled worker enrollment without starting a worker', function (): void {
    [$group, $vm] = worker_operation_workspace();
    $vm->update(['state' => TaskVmState::Parked]);
    Queue::fake([ResumeTaskVm::class]);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $operation = worker_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    app(CancelOperationAction::class)->execute($operation);
    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect(app(TaskWorkerEnrollment::class)->read($operation->input['worker']['enrollment_reference']))->toBeNull();
    expect($agents->calls)->toBe([]);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running);
});

it('stops the worker before a destroy job waits for unpublished reviewed work', function (): void {
    [$group, $vm] = worker_operation_workspace();
    $vm->update(['worker_stop_required' => true]);
    $group->update(['status' => TaskGroupStatus::Completed]);
    $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
    TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => str_repeat('c', 40), 'source' => TaskReviewedCommitSource::OrbitPush]);
    $agents = (new FakeAgentCommandRunner)->answer('swamp.stop-worker', ['stopped' => true]);
    app()->instance(AgentCommandRunner::class, $agents);
    $provider = new RecordingTaskVmProvider;
    app()->instance(TaskVmProvider::class, $provider);

    app()->call([new DestroyTaskVm($vm->id), 'handle']);

    expect($agents->commands())->toBe(['swamp.stop-worker']);
    expect($vm->fresh()->worker_stop_required)->toBeFalse();
    expect($vm->fresh()->state)->toBe(TaskVmState::Ready);
    expect($group->fresh()->taskable_id)->not->toBeNull();
    expect($provider->calls)->toBe(['observe']);
});
