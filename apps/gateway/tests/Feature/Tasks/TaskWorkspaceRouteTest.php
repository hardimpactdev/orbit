<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\InstanceProvisionFailure;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceRoute;
use App\Domain\Tasks\TaskWorkspaceSnapshot;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\TaskModelRouter;

/**
 * A todo group whose provisioner returns a routed task VM workspace before its Route, as a task VM claim does. The
 * events list records the order of the baseline start and each Route attempt.
 *
 * @param  list<bool>  $routeResults  one result per Route attempt, true for success; the last one repeats
 * @return array{Task, object, ArrayObject<int, string>}
 */
function workspace_route_claim(array $routeResults): array
{
    bind_task_node_reachability();
    $gateway = test()->markAsGateway(Node::query()->create([
        'name' => 'workspace-route-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.91',
        'wireguard_ip' => '10.44.0.91',
    ]));
    test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    TaskModelRouter::ready();
    test()->postJson('/api/v1/extensions/tasks/enable')->assertOk();
    $project = Project::query()->create([
        'name' => 'Workspace route',
        'slug' => 'workspace-route',
        'repository_url' => 'git@example.test:workspace-route.git',
        'default_branch' => 'main',
        'task_check' => 'composer check',
    ]);
    $node = Node::query()->create([
        'name' => 'tvm-1',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.92',
        'wireguard_ip' => '10.44.0.92',
    ]);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Route', 'brief' => 'Route brief', 'status' => TaskGroupStatus::Todo]);
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'First', 'brief' => 'First task.', 'status' => TaskStatus::Todo]);
    $instance = Instance::query()->create([
        'task_workspace_owner_id' => $group->id,
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-'.$group->id,
        'branch_override' => 'task-'.$group->id,
        'checkout_path' => '/home/orbit/apps/workspace-route/task-'.$group->id,
        'starting_commit' => str_repeat('a', 40),
        'task_workspace_routed' => true,
        'status' => InstanceState::SourceResolved,
    ]);
    /** @var ArrayObject<int, string> $events */
    $events = new ArrayObject;

    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): Instance
        {
            return $this->instance;
        }
    });
    $inner = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, new class($inner, $events) implements TaskCheckRunner
    {
        /** @param ArrayObject<int, string> $events */
        public function __construct(private FakeTaskCheckRunner $inner, private ArrayObject $events) {}

        public function start(Instance $instance, ?string $command, array $setup = [], ?array $deliverables = null): TaskCheckProcess
        {
            $this->events->append('baseline-start');

            return $this->inner->start($instance, $command, $setup, $deliverables);
        }

        public function read(Instance $instance, TaskCheckProcess $process): TaskCheckReading
        {
            return $this->inner->read($instance, $process);
        }

        public function cancel(Instance $instance, TaskCheckProcess $process): void
        {
            $this->inner->cancel($instance, $process);
        }

        public function snapshot(Instance $instance): TaskWorkspaceSnapshot
        {
            return $this->inner->snapshot($instance);
        }
    });
    $route = new class($routeResults, $events) implements TaskWorkspaceRoute
    {
        /**
         * @param  list<bool>  $results
         * @param  ArrayObject<int, string>  $events
         */
        public bool $active = false;

        public function __construct(private array $results, private ArrayObject $events) {}

        public function pending(Task $group): bool
        {
            return ! $this->active && $group->taskable instanceof Instance;
        }

        public function complete(Task $group): ?InstanceProvisionFailure
        {
            $succeeded = count($this->results) > 1 ? array_shift($this->results) : $this->results[0];
            $this->events->append($succeeded ? 'route' : 'route-failed');
            if (! $succeeded) {
                return new InstanceProvisionFailure('RuntimeConvergenceException [router]: Another development projection operation is active.');
            }
            $this->active = true;

            return null;
        }
    };
    app()->instance(TaskWorkspaceRoute::class, $route);
    app()->instance(AgentSpawner::class, new class($events) implements AgentSpawner
    {
        /** @param ArrayObject<int, string> $events */
        public function __construct(private ArrayObject $events) {}

        public function spawnReviewer(Task $task): ?int
        {
            return null;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->events->append('implementer');

            return test_agent_thread($task->parent, 'workspace-route-implementer-'.$task->parent_id, $task)->id;
        }

        public function requestReview(Task $task): void {}
    });
    app()->forgetInstance(TaskScheduler::class);

    return [$group, $route, $events];
}

it('starts the baseline check before it finishes the Route of a task VM workspace', function (): void {
    [$group, $route, $events] = workspace_route_claim([true]);

    $claimed = app(TaskScheduler::class)->claimNext();

    expect($claimed?->id)->toBe($group->id)
        ->and($claimed?->status)->toBe(TaskGroupStatus::Running)
        ->and($events->getArrayCopy())->toBe(['baseline-start', 'route'])
        ->and($route->active)->toBeTrue();

    test_pass_baseline();

    expect($events->getArrayCopy())->toBe(['baseline-start', 'route', 'implementer']);
});

it('tries a Route that failed after the baseline check started again before the implementer starts', function (): void {
    [$group, $route, $events] = workspace_route_claim([false, true]);
    Log::spy();

    app(TaskScheduler::class)->claimNext();

    expect($events->getArrayCopy())->toBe(['baseline-start', 'route-failed'])
        ->and($route->active)->toBeFalse()
        ->and(TaskCheck::query()->where('kind', TaskCheckKind::Baseline->value)->sole()->status)->toBe(TaskCheckStatus::Running);
    Log::shouldHaveReceived('warning')->once()->with(
        'The task workspace Route failed after the baseline check started; Orbit tries again before the implementer starts.',
        Mockery::on(static fn (array $context): bool => $context['group_id'] === $group->id && str_contains($context['failure'], '[router]')),
    );

    test_pass_baseline();

    $task = $group->tasks()->sole();
    expect($events->getArrayCopy())->toBe(['baseline-start', 'route-failed', 'route', 'implementer'])
        ->and($route->active)->toBeTrue()
        ->and($task->communication_failures)->toBe(0)
        ->and($task->assistance_requested)->toBeFalse();
});

it('asks for assistance and starts no agent when the Route keeps failing after the baseline check passed', function (): void {
    [$group, $route, $events] = workspace_route_claim([false]);

    app(TaskScheduler::class)->claimNext();
    foreach (range(1, 6) as $tick) {
        test_pass_baseline();
    }

    $task = $group->tasks()->sole();
    $fresh = $group->fresh();
    expect($events->getArrayCopy())->not->toContain('implementer')
        ->and($task->assistance_requested)->toBeTrue()
        ->and($task->assistance_reason)->toStartWith(TaskScheduler::WorkspaceRouteFailedReason)
        ->and($task->assistance_reason)->toContain('Another development projection operation is active.')
        ->and($fresh?->assistance_requested)->toBeTrue()
        ->and($fresh?->status)->toBe(TaskGroupStatus::Running)
        ->and($route->active)->toBeFalse()
        ->and(TaskCheck::query()->where('kind', TaskCheckKind::Baseline->value)->sole()->status)->toBe(TaskCheckStatus::Passed)
        // The claim tries once, then each tick once until the fifth failure asks for assistance.
        ->and(array_count_values($events->getArrayCopy())['route-failed'])->toBe(6);
});

it('leaves a workspace whose Route is already active alone', function (): void {
    [$group, $route, $events] = workspace_route_claim([true]);
    $route->active = true;

    app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    expect($events->getArrayCopy())->toBe(['baseline-start', 'implementer']);
});
