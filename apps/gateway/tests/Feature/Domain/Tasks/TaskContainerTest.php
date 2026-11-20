<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceCompleteAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\FailUnreadableResultAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\ReadAgentThreadAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\Tasks\StartTaskProvisionAction;
use App\Actions\Tasks\StartTaskTurnAction;
use App\Actions\Tasks\StopTaskSubtaskAction;
use App\Actions\Tasks\UpdateTaskAction;
use App\Data\Tasks\AgentThreadData;
use App\Data\Tasks\StartTaskCheckData;
use App\Data\Tasks\StartTaskPublishData;
use App\Data\Tasks\TaskTurnData;
use App\Data\Tasks\UpdateTaskData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\ContainerServer;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\InstanceAgentInstructions;
use App\Domain\Instances\InstanceCompleteProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceDestroyReplicationSlots;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceLifecycleContribution;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\InstanceThreadContribution;
use App\Domain\Instances\InstanceThreadContributions;
use App\Domain\Instances\NativeDevelopmentInstanceInput;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\NodeWorkAdmission;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Projects\ProjectAgentInstructionSource;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Schedules\NativeSchedules;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAgent;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskCheckIntent;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskGroupDone;
use App\Domain\Tasks\TaskGroupMetricsRefresher;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskLifecycle;
use App\Domain\Tasks\TaskProvisionIntent;
use App\Domain\Tasks\TaskPublishIntent;
use App\Domain\Tasks\TaskStart;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurn;
use App\Domain\Tasks\TaskTurnProjection;
use App\Domain\Tasks\TaskTurnPrompt;
use App\Domain\Tasks\TaskVmRemoved;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Jobs\Tasks\DestroyTaskCopy;
use App\Jobs\Tasks\RetryTaskCopyCreate;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../Instances/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    app(ExtensionStore::class)->set('conn', true);
    app(ExtensionStore::class)->set('tasks', true);
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
    // The workspace read: one SSH call to the Node, answered by the test.
    $this->ssh = new class implements SshExecutor
    {
        /** @var array<string, mixed> */
        public array $answer = ['state' => 'clean', 'baseline' => 'none', 'head' => 'main', 'modified' => ['count' => 0, 'names' => []],
            'untracked' => ['count' => 0, 'names' => []], 'stashes' => ['count' => 0, 'names' => []],
            'commits' => ['count' => 0, 'branch_count' => 0, 'branches' => []]];

        /** @var array<string, mixed>|null The ID map read's answer: a roomy host with no container; null fails the read. */
        public ?array $ids = ['uid' => [1000000, 1000000000], 'gid' => [1000000, 1000000000], 'containers' => []];

        public int $calls = 0;

        public int $idReads = 0;

        /** @var list<?string> */
        public array $inputs = [];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            if (str_contains((string) $command->input, 'ID map facts')) {
                $this->idReads++;

                return $this->ids === null ? new CommandResult(255, '', 'ssh: connect timed out', 5, false)
                    : new CommandResult(0, json_encode($this->ids, JSON_THROW_ON_ERROR), '', 5, false);
            }
            $this->calls++;
            $this->inputs[] = $command->input;

            return new CommandResult(0, json_encode($this->answer, JSON_THROW_ON_ERROR), '', 5, false);
        }
    };
    app()->instance(SshExecutor::class, $this->ssh);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/fixture/key');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/fixture/known_hosts');
    app()->instance(KnownHostsStore::class, $hosts);
    config(['orbit.tasks.copies_max' => 1]);
});

/**
 * A Project and its container default as a finished create (and, for a nested-fleet Project, refresh) leaves it: active, running, with
 * a seed. A nested-fleet Project's Node has its switch on; any other Project's default is a plain container.
 */
function copySource(string $slug = 'trial', bool $nested = true, bool $web = false): Instance
{
    $node = Node::query()->where('name', 'beast')->first() ?? containerDefaultNode();
    if ($web) {
        $node->update(['tld' => 'beast.test']);
    }
    if ($nested) {
        $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
    }
    Http::fake(['api.github.com/repos/nckrtl/'.$slug => Http::response(['full_name' => 'nckrtl/'.$slug, 'private' => false])]);
    // A web-serving Project's default and copies have a Route each; a node package has none.
    $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => $web ? 'laravel-app' : 'node-package', 'repository_url' => 'https://github.com/nckrtl/'.$slug.'.git',
        'default_branch' => 'main', 'root' => $web ? 'public' : '.', 'is_laravel' => false, 'php_version' => $web ? '8.5' : null, 'source_facts_captured_at' => now(), 'nested_fleet' => $nested]);
    $default = Instance::query()->findOrFail(containerCreate($project, $node->refresh())->target_id);
    // The default's create published its Route; an active routed Instance needs it active.
    Route::query()->where('project_id', $project->id)->update(['status' => 'active', 'sites_published' => true]);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    // The nested-fleet default that tasks copy today was made before the inner volume, so it records none
    // (docs/reference/development-containers.md#the-inner-volume); a test that needs the volume sets it.
    InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
        ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running, 'inner_volume' => null]);

    return $default->refresh();
}

function copyGroup(Project $project): Task
{
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Todo]);
    $group->tasks()->create(['title' => 'Step', 'brief' => 'Do it', 'position' => 1, 'status' => TaskStatus::Todo]);

    return $group;
}

function startCopy(Task $group): Operation
{
    return app(TaskProvisionIntent::class)->start($group, 'test', null);
}

/** @return array<string, int> */
function copyRows(): array
{
    return ['instances' => Instance::query()->count(), 'runtimes' => InstanceRuntime::query()->count(), 'operations' => Operation::query()->count(),
        'nodes' => Node::query()->count()];
}

function copyRefusal(Closure $start): string
{
    try {
        $start();
    } catch (ResourceOperationException $error) {
        return $error->errorCode.' '.$error->status;
    }

    return 'admitted';
}

/** The copy as its successful create leaves it: active on its branch, its group running. */
/** The Gateway's own Node as the caller of the API, for a test that changes a group or its Project over HTTP. */
function copyApiCaller(TestCase $test): void
{
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $test->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
}

function finishedCopy(Task $group, Operation $operation): Instance
{
    $operation->forceFill(['status' => 'succeeded', 'finished_at' => now()])->save();
    $copy = Instance::query()->findOrFail($operation->target_id);
    $copy->update(['status' => InstanceState::Active, 'first_setup_pending' => false, 'branch' => $copy->name, 'starting_commit' => str_repeat('c', 40)]);
    $group->update(['status' => TaskGroupStatus::Running]);

    return $copy->refresh();
}

/** The run of a destroy ended well. The vault copies of a container destroy are another test's matter, so this run has none. */
function destroySucceeded(Operation $destroy): void
{
    $destroy->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-1'], 'input' => Arr::except($destroy->input, 'container_destroy')])->save();
    app(DevelopmentInstanceDestroyProjection::class)->apply($destroy->refresh(), ['status' => 'succeeded', 'run_id' => 'run-1',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => []]);
}

/**
 * A copy whose create succeeded at its thread and whose completion runs.
 *
 * @return array{Instance, Operation}
 */
function copyCompleting(Task $group): array
{
    $create = startCopy($group);
    $copy = Instance::query()->findOrFail($create->target_id);
    $copy->update(['status' => InstanceState::SourceResolved, 'branch' => $copy->name, 'starting_commit' => str_repeat('c', 40)]);
    app(OperationStore::class)->change($create, ['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null]);

    return [$copy->refresh(), copyCompletion($copy)];
}

function copyCompletion(Instance $copy): Operation
{
    return Operation::query()->create(['kind' => 'instance.complete', 'target_type' => 'instance', 'target_id' => $copy->id, 'node_id' => $copy->node_id,
        'input' => [], 'state' => [], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 0]);
}

function destroyTaskCopy(Instance $copy): void
{
    app()->call([new DestroyTaskCopy($copy->id), 'handle']);
}

/** Every create and destroy contribution counts a T3 server as registered. */
function copyT3Registered(): void
{
    $contribution = Mockery::mock(InstanceLifecycleContribution::class);
    $contribution->shouldReceive('registered')->andReturn(true);
    $contribution->shouldIgnoreMissing();
    app()->instance(InstanceLifecycleContributions::class, new InstanceLifecycleContributions([$contribution]));
}

function runningOperation(Operation $operation): Operation
{
    $operation->forceFill(['status' => 'running', 'finished_at' => null, 'error_code' => null, 'result' => null, 'state' => ['swamp_run_id' => 'run-1']])->save();

    return $operation->refresh();
}

/** The copy's create ended failed at `$step` with `$code`, as OperationStore records every end. */
function failCopyCreate(Operation $create, string $code, string $step): void
{
    app(OperationStore::class)->change(runningOperation($create), ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => $code,
        'failed_step' => $step, 'lock_key' => null]);
}

/** The queued DestroyTaskCopy of the copy ran, and its destroy succeeded. */
function removeFailedCopy(int $copyId): void
{
    destroyTaskCopy(Instance::query()->findOrFail($copyId));
    destroySucceeded(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copyId)->sole());
}

function retryTaskCopy(Task $group, Operation $failed): void
{
    app()->call([new RetryTaskCopyCreate($group->id, $failed->id), 'handle']);
}

describe('the decision point', function (): void {
    it('starts a task of a nested-fleet Project as a copy and refuses a Project without a container default by name', function (): void {
        $project = containerDefaultProject();
        expect(InstancePlacement::copiesTasks($project))->toBeFalse()
            ->and(copyRefusal(fn () => InstancePlacement::taskCopy($project)))->toBe(TaskVmRemoved::Code.' 422');
        $project->update(['nested_fleet' => true]);
        InstancePlacement::taskCopy($project);
        expect(InstancePlacement::copiesTasks($project))->toBeTrue();
    });

    it('starts a task of a Project whose default is a container as a copy, and refuses one whose default has no container', function (): void {
        $default = copySource('app', nested: false);
        $project = $default->project;
        InstancePlacement::taskCopy($project);
        expect(InstancePlacement::copiesTasks($project))->toBeTrue();
        InstanceRuntime::query()->where('instance_id', $default->id)->delete();
        expect(InstancePlacement::copiesTasks($project))->toBeFalse()
            ->and(copyRefusal(fn () => InstancePlacement::taskCopy($project)))->toBe(TaskVmRemoved::Code.' 422');
    });

    it('refuses the task start of a Project without a container default before it records a group change, a copy, a VM or an Operation', function (): void {
        $default = copySource('app', nested: false);
        InstanceRuntime::query()->where('instance_id', $default->id)->delete();
        $group = copyGroup($default->project);
        $instances = Instance::query()->count();
        $operations = Operation::query()->count();

        expect(copyRefusal(fn () => app(StartTaskProvisionAction::class)->execute($group, 'test', null)))->toBe(TaskVmRemoved::Code.' 422')
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Todo)
            ->and($group->taskable_id)->toBeNull()
            ->and(Instance::query()->count())->toBe($instances)
            ->and(Operation::query()->count())->toBe($operations);
    });

    it('refuses the turn of a group without a copy by the same name, also when the default of a flag-off Project is a container', function (): void {
        $project = copySource('app', nested: false)->project;
        $group = copyGroup($project);
        $group->update(['status' => TaskGroupStatus::Running]);
        $operations = Operation::query()->count();

        expect(copyRefusal(fn () => copyTurn($group)))->toBe(TaskVmRemoved::Code.' 422')
            ->and(Operation::query()->count())->toBe($operations);
    });
});

describe('a task start on the copy path', function (): void {
    it('admits one clone of the default named and branched task-<id>, owned by and bound to the group, with no task VM', function (): void {
        $default = copySource();
        $group = copyGroup($default->project);

        $operation = startCopy($group);

        $copy = Instance::query()->findOrFail($operation->target_id);
        $runtime = InstanceRuntime::query()->where('instance_id', $copy->id)->sole();
        $group->refresh();
        expect($operation->kind)->toBe('instance.create')
            ->and($copy->name)->toBe('task-'.$group->id)
            ->and($copy->branch_override)->toBe('task-'.$group->id)
            ->and($copy->node_id)->toBe($default->node_id)
            ->and($copy->task_workspace_owner_id)->toBe($group->id)
            ->and($runtime->incus_project)->toBe(InstancePlacement::NestedProject)
            ->and($runtime->clone_source_instance_id)->toBe($default->id)
            ->and($group->status)->toBe(TaskGroupStatus::Reserved)
            ->and($group->taskable_id)->toBe($copy->id);
    });

    it('gives a task copy of a nested-fleet default with an inner volume its own volume, copied from the default\'s seed, and removes it with the copy', function (): void {
        $default = copySource();
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['inner_volume' => 'inner-'.$default->id]);
        $group = copyGroup($default->project);

        $operation = startCopy($group);

        $copy = Instance::query()->findOrFail($operation->target_id);
        $container = containerIntentOf($operation);
        $clone = ValidatedData::object($container['clone']);
        // docs/reference/development-containers.md#the-inner-volume: the copy's own volume, from the default's volume snapshot of the seed's name.
        expect(InstanceRuntime::query()->where('instance_id', $copy->id)->sole()->inner_volume)->toBe('inner-'.$copy->id)
            ->and(ValidatedData::object($container['inner_volume'])['name'])->toBe('inner-'.$copy->id)
            ->and($clone['inner_volume'])->toBe('inner-'.$default->id)
            ->and($clone['snapshot'])->toBe('seed-'.str_repeat('c', 40))
            ->and(ValidatedData::object($container['allowed_devices'])['inner-pool'])->toBe(['type' => 'disk', 'pool' => InstanceHostIntent::Pool,
                'source' => 'inner-'.$copy->id, 'path' => '/srv/inner-pool']);

        $operation->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
        $group->update(['status' => TaskGroupStatus::Failed]);
        destroyTaskCopy($copy);
        expect(Operation::query()->where('kind', 'instance.destroy')->sole()->input['container_destroy'])
            ->toMatchArray(['name' => 'instance-'.$copy->id, 'inner_volume' => 'inner-'.$copy->id]);
    });

    it('refuses a copy of a default with an inner volume while its deployment retakes the seed pair; a default without one is admitted', function (bool $volume): void {
        $default = copySource();
        if ($volume) {
            InstanceRuntime::query()->where('instance_id', $default->id)->update(['inner_volume' => 'inner-'.$default->id]);
        }
        Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $default->id, 'node_id' => $default->node_id,
            'status' => 'running', 'input' => [], 'state' => [], 'result' => null]);
        $group = copyGroup($default->project);
        $before = copyRows();

        if ($volume) {
            // docs/reference/development-containers.md#the-seed-pair: a copy must not read one half before the retake and one after.
            expect(copyRefusal(fn () => startCopy($group)))->toBe('instance.default_seed_in_flight 409')->and(copyRows())->toBe($before);
        } else {
            // Instance 2454's shape: a one-snapshot seed, admitted during a deployment as before.
            expect(startCopy($group)->kind)->toBe('instance.create');
        }
    })->with(['inner volume' => true, 'no inner volume (Instance 2454)' => false]);

    it('gives a task copy of a nested-fleet default without an inner volume (Instance 2454\'s shape) none: no volume in its record, intent or devices', function (): void {
        $default = copySource();
        $group = copyGroup($default->project);

        $operation = startCopy($group);

        $copy = Instance::query()->findOrFail($operation->target_id);
        $container = containerIntentOf($operation);
        expect(InstanceRuntime::query()->where('instance_id', $copy->id)->sole()->inner_volume)->toBeNull()
            ->and($container)->not->toHaveKey('inner_volume')
            ->and(ValidatedData::object($container['clone']))->not->toHaveKey('inner_volume')
            ->and(ValidatedData::object($container['allowed_devices']))->not->toHaveKey('inner-pool');
    });

    it('admits a plain clone for a Project whose default is a container: no nested project, no fleet, no Club key', function (): void {
        $default = copySource('app', nested: false);
        $group = copyGroup($default->project);

        $operation = startCopy($group);

        $copy = Instance::query()->findOrFail($operation->target_id);
        $runtime = InstanceRuntime::query()->where('instance_id', $copy->id)->sole();
        $container = containerIntentOf($operation);
        expect($operation->kind)->toBe('instance.create')
            ->and($copy->name)->toBe('task-'.$group->id)
            ->and($copy->branch_override)->toBe('task-'.$group->id)
            ->and($copy->task_workspace_owner_id)->toBe($group->id)
            ->and($runtime->incus_project)->toBe(InstanceHostIntent::Project)
            ->and($runtime->clone_source_instance_id)->toBe($default->id)
            ->and($container)->not->toHaveKey('club_key')
            ->and(ValidatedData::object($container['clone'])['clean_workspace'] ?? null)->toBeTrue()
            // A task copy takes no CI place, so its create holds none (docs/reference/node-provisioning.md#proof-places-on-a-ci-node).
            ->and(ValidatedData::object($container['clone']))->not->toHaveKey('ci_hold')
            // The copy's runtime reads running only when the create's result is recorded, after the checkout cleaned the tree: no check,
            // baseline included, is admitted before (tasks.instance_not_running), and a turn waits for `active`.
            ->and($runtime->power_state)->toBe(InstanceRuntimePowerState::Stopped)
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Reserved)
            ->and($group->taskable_id)->toBe($copy->id);
    });

    it('reads the Project\'s agent instructions before its admission transaction and gives the copy them', function (): void {
        $default = copySource('app', nested: false);
        $group = copyGroup($default->project);
        // A source that reads its bodies as Documents does: never inside a transaction deeper than the test's own.
        $levels = [];
        $outer = DB::transactionLevel();
        $source = new class($levels) implements ProjectAgentInstructionSource
        {
            /** @param list<int> $levels */
            public function __construct(public array &$levels) {}

            public function files(Project $project): array
            {
                return ['AGENTS.md' => ['bytes' => 5, 'read' => function (): string {
                    $this->levels[] = DB::transactionLevel();

                    return "Test.\n";
                }]];
            }

            public function summary(Project $project): ?array
            {
                return null;
            }
        };
        app()->instance(InstanceAgentInstructions::class, new InstanceAgentInstructions([$source]));

        $operation = startCopy($group);

        expect($levels)->toBe([$outer])
            ->and(containerIntentOf($operation)['clone']['agent_instructions']['files'][0]['content'])->toEndWith("# This Project\n\nTest.\n");
    });

    it('replays the same create for a retry with the request\'s key', function (): void {
        $group = copyGroup(copySource()->project);
        $idempotency = app(OperationIdempotency::class);
        $start = fn (): Operation => $idempotency->under('tasks.provision', 'retry-key', 'fingerprint', fn (): Operation => startCopy(Task::topLevel()->findOrFail($group->id)));

        $first = $start();
        $second = $start();

        expect($second->id)->toBe($first->id)->and($first->idempotency_key)->toBe('retry-key')
            ->and(Instance::query()->where('task_workspace_owner_id', $group->id)->count())->toBe(1);
    });

    it('is refused by name and leaves nothing when the default cannot be copied', function (Closure $arrange): void {
        $default = copySource();
        $group = copyGroup($default->project);
        $arrange($default);
        $before = copyRows();

        expect(copyRefusal(fn () => startCopy($group)))->toBe('tasks.copy_source_unavailable 409')
            ->and(copyRows())->toBe($before)
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Todo)
            ->and($group->taskable_id)->toBeNull();
    })->with([
        // As Project 79's default: a finished fixture whose container is stopped.
        'a stopped default' => [fn (Instance $default) => $default->runtime->update(['power_state' => InstanceRuntimePowerState::Stopped])],
        'a default without a seed' => [fn (Instance $default) => $default->runtime->update(['seed_commit' => null])],
        'a default that is not active' => [fn (Instance $default) => $default->update(['status' => InstanceState::Reserved])],
        'no default' => [function (Instance $default): void {
            $default->runtime()->delete();
            $default->delete();
        }],
    ]);

    it('is refused by name and leaves nothing when the default\'s container does not run, whatever its record says', function (array $answer): void {
        $default = copySource();
        $group = copyGroup($default->project);
        $this->ssh->answer = $answer;
        $before = copyRows();

        expect(copyRefusal(fn () => startCopy($group)))->toBe('tasks.copy_source_unavailable 409')
            ->and($default->runtime->refresh()->power_state)->toBe(InstanceRuntimePowerState::Running)
            ->and(copyRows())->toBe($before)
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Todo)
            ->and($this->ssh->calls)->toBe(1);
    })->with([
        // As Project 79's default on 2026-11-13: stopped by hand, its record still running.
        'stopped' => [['state' => 'unknown', 'reason' => 'stopped']],
        'unreachable' => [['state' => 'unknown', 'reason' => 'unreachable']],
    ]);

    it('copies an asleep default\'s seed snapshot without waking it', function (string $sleep): void {
        $default = copySource();
        $default->runtime->update(['power_state' => InstanceRuntimePowerState::Stopped]);
        Operation::query()->create(['kind' => InstanceSleep::Kind, 'target_type' => 'instance', 'target_id' => $default->id, 'node_id' => $default->node_id,
            'status' => 'succeeded', 'input' => [], 'state' => [], 'result' => ['power_state' => 'stopped', 'sleep' => $sleep], 'finished_at' => now()]);
        $group = copyGroup($default->project);
        $this->ssh->answer = ['state' => 'unknown', 'reason' => 'asleep'];

        expect(startCopy($group)->kind)->toBe('instance.create')
            ->and($default->runtime->refresh()->power_state)->toBe(InstanceRuntimePowerState::Stopped)
            ->and(Operation::query()->where('kind', 'instance.wake')->count())->toBe(0);
    })->with(['frozen', 'stopped']);

    it('refuses a stopped default whose asleep marker no sleep left, and a container that does not run without its marker', function (string $case): void {
        $default = copySource();
        $default->runtime->update(['power_state' => InstanceRuntimePowerState::Stopped]);
        if ($case === 'a failed sleep') {
            Operation::query()->create(['kind' => InstanceSleep::Kind, 'target_type' => 'instance', 'target_id' => $default->id, 'node_id' => $default->node_id,
                'status' => 'failed', 'error_code' => 'instance.container_freeze_failed', 'input' => [], 'state' => [], 'finished_at' => now()]);
        }
        $group = copyGroup($default->project);
        // The reader answers `asleep` only for a container that does not run behind its marker, and `stopped` without the marker.
        $this->ssh->answer = ['state' => 'unknown', 'reason' => $case === 'no marker after a sleep' ? 'stopped' : 'asleep'];
        if ($case === 'no marker after a sleep') {
            Operation::query()->create(['kind' => InstanceSleep::Kind, 'target_type' => 'instance', 'target_id' => $default->id, 'node_id' => $default->node_id,
                'status' => 'succeeded', 'input' => [], 'state' => [], 'result' => ['sleep' => 'frozen'], 'finished_at' => now()]);
        }
        $before = copyRows();

        expect(copyRefusal(fn () => startCopy($group)))->toBe('tasks.copy_source_unavailable 409')
            ->and(copyRows())->toBe($before);
    })->with(['no sleep', 'a failed sleep', 'no marker after a sleep']);

    it('admits a running default whose Git read ran out of time or found no repository', function (array $answer): void {
        $group = copyGroup(copySource()->project);
        $this->ssh->answer = $answer;

        expect(startCopy($group)->kind)->toBe('instance.create')->and($this->ssh->calls)->toBe(1);
    })->with([
        'timeout' => [['state' => 'unknown', 'reason' => 'timeout']],
        'not a repository' => [['state' => 'unknown', 'reason' => 'not_a_repository']],
    ]);

    it('holds at most the Gateway\'s number of copies on the Node, failing copies included', function (): void {
        $default = copySource();
        startCopy(copyGroup($default->project));
        $second = copyGroup($default->project);
        $before = copyRows();

        expect(copyRefusal(fn () => startCopy($second)))->toBe('tasks.copy_capacity_full 409')
            ->and(copyRows())->toBe($before)
            ->and($second->refresh()->status)->toBe(TaskGroupStatus::Todo);
        config(['orbit.tasks.copies_max' => 2]);
        expect(startCopy($second)->kind)->toBe('instance.create');
    });

    it('caps only nested-fleet copies: a copy of a plain container Project neither counts nor waits', function (): void {
        $nested = copySource();
        startCopy(copyGroup($nested->project));
        $plain = copySource('app', nested: false);

        expect(startCopy(copyGroup($plain->project))->kind)->toBe('instance.create')
            ->and(TaskStart::live($nested->node_id))->toBe(1);
        config(['orbit.tasks.copies_max' => 0]);
        expect(startCopy(copyGroup($plain->project))->kind)->toBe('instance.create')
            ->and(copyRefusal(fn () => startCopy(copyGroup($nested->project))))->toBe('tasks.copy_capacity_full 409');
    });

    it('refuses a copy that would leave no free ID range for the default\'s next seed, by the host\'s real ranges', function (): void {
        $default = copySource();
        $group = copyGroup($default->project);
        config(['orbit.tasks.copies_max' => 50]);
        $nested = static fn (string $name, int $base): array => ['name' => $name, 'project' => 'orbit-instances-nested',
            'config' => ['security.idmap.isolated' => 'true', 'security.idmap.size' => '101000000', 'volatile.idmap.base' => (string) $base]];
        // Root's 1,000,000,000 IDs hold nine maps of 101,000,000 past the first 65,536: eight held leave one, which the copy would take.
        $containers = [];
        foreach (range(0, 7) as $i) {
            $containers[] = $nested($i === 0 ? $default->runtime->container_name : 'instance-'.(100 + $i), 1065536 + $i * 101000000);
        }
        $this->ssh->ids = ['uid' => [1000000, 1000000000], 'gid' => [1000000, 1000000000], 'containers' => $containers];
        $before = copyRows();

        try {
            startCopy($group);
            $this->fail('admitted');
        } catch (ResourceOperationException $error) {
            expect($error->errorCode)->toBe('tasks.copy_id_range_full')->and($error->status)->toBe(409)
                ->and($error->details)->toMatchArray(['free' => 1, 'size' => 101000000, 'seed_sizes_short' => [101000000]]);
        }
        expect(copyRows())->toBe($before)->and($group->refresh()->status)->toBe(TaskGroupStatus::Todo);

        // A stopped container holds its range too; with one fewer held, two are free and the copy is admitted.
        array_pop($containers);
        expect($containers[0]['name'])->toBe($default->runtime->container_name);
        $this->ssh->ids['containers'] = $containers;
        expect(startCopy($group)->kind)->toBe('instance.create');

        // The copy just admitted has no container yet: it counts as held, so the next start is refused before Incus would fail it.
        expect(copyRefusal(fn () => startCopy(copyGroup($default->project))))->toBe('tasks.copy_id_range_full 409');
    });

    it('places a copy of a converted default by its recorded size, where the full size no longer fits', function (): void {
        $default = copySource();
        config(['orbit.tasks.copies_max' => 50]);
        $nested = static fn (string $name, int $base): array => ['name' => $name, 'project' => 'orbit-instances-nested',
            'config' => ['security.idmap.isolated' => 'true', 'security.idmap.size' => '101000000', 'volatile.idmap.base' => (string) $base]];
        // Nine full-size maps leave 90,934,464 IDs: no full-size range for a copy, but room for many of the converted size.
        $containers = [];
        foreach (range(0, 8) as $i) {
            $containers[] = $nested($i === 0 ? $default->runtime->container_name : 'instance-'.(100 + $i), 1065536 + $i * 101000000);
        }
        $this->ssh->ids = ['uid' => [1000000, 1000000000], 'gid' => [1000000, 1000000000], 'containers' => $containers];
        expect(copyRefusal(fn () => startCopy(copyGroup($default->project))))->toBe('tasks.copy_id_range_full 409')
            // The read is kept on the Node for Doctor (docs/reference/development-containers.md#isolated-id-ranges).
            ->and(Arr::except($default->node->refresh()->id_ranges ?? [], 'read_at'))->toBe(['full' => 0, 'small' => 10]);

        // docs/reference/development-containers.md#the-id-map-size: a converted default and its next copies record the small size.
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['idmap_size' => 1165536]);
        $operation = startCopy(copyGroup($default->project));
        expect($operation->kind)->toBe('instance.create')
            ->and(InstanceRuntime::query()->where('instance_id', $operation->target_id)->value('idmap_size'))->toBe(1165536);
    });

    it('keeps a range for another default\'s seed: a plain copy may not fill the last nested-sized gap', function (): void {
        $nested = copySource();
        $plain = copySource('app', nested: false);
        $map = static fn (string $name, string $project, int $base, int $size): array => ['name' => $name, 'project' => $project,
            'config' => ['security.idmap.isolated' => 'true', 'security.idmap.size' => (string) $size, 'volatile.idmap.base' => (string) $base]];
        // Past the host's share: the plain default, the nested default, then one nested-sized gap and nothing else.
        $containers = [$map($plain->runtime->container_name, 'orbit-instances', 1065536, 65536),
            $map($nested->runtime->container_name, 'orbit-instances-nested', 1131072, 101000000)];
        $root = [1000000, 65536 + 65536 + 2 * 101000000];
        $this->ssh->ids = ['uid' => $root, 'gid' => $root, 'containers' => $containers];

        // The plain copy would sit in the only gap, and the nested default's next seed would find none.
        expect(copyRefusal(fn () => startCopy(copyGroup($plain->project))))->toBe('tasks.copy_id_range_full 409');
        // Room for a small range besides the nested gap: the plain copy and its own seed take small gaps, so it is admitted.
        $root[1] += 2 * 65536;
        $this->ssh->ids = ['uid' => $root, 'gid' => $root, 'containers' => [$map($plain->runtime->container_name, 'orbit-instances', 1065536 + 2 * 65536, 65536),
            $map($nested->runtime->container_name, 'orbit-instances-nested', 1065536 + 3 * 65536, 101000000)]];
        expect(startCopy(copyGroup($plain->project))->kind)->toBe('instance.create');
    });

    it('counts a runtime without a container as held only while its create is open', function (): void {
        $default = copySource();
        config(['orbit.tasks.copies_max' => 50]);
        $nested = static fn (string $name, int $base): array => ['name' => $name, 'project' => 'orbit-instances-nested',
            'config' => ['security.idmap.isolated' => 'true', 'security.idmap.size' => '101000000', 'volatile.idmap.base' => (string) $base]];
        $containers = [];
        foreach (range(0, 6) as $i) {
            $containers[] = $nested($i === 0 ? $default->runtime->container_name : 'instance-'.(100 + $i), 1065536 + $i * 101000000);
        }
        $this->ssh->ids = ['uid' => [1000000, 1000000000], 'gid' => [1000000, 1000000000], 'containers' => $containers];
        // Two free ranges: the first copy is admitted, and its open create holds one until Incus makes its container.
        $first = startCopy(copyGroup($default->project));
        expect(copyRefusal(fn () => startCopy(copyGroup($default->project))))->toBe('tasks.copy_id_range_full 409');
        // Its create failed before the copy: the row holds nothing, and the next start is admitted.
        $first->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
        expect(startCopy(copyGroup($default->project))->kind)->toBe('instance.create');
    });

    it('refuses with its own code when the host\'s ID ranges cannot be read, and admits nothing on the hand-set cap alone', function (): void {
        $group = copyGroup(copySource()->project);
        config(['orbit.tasks.copies_max' => 50]);
        $this->ssh->ids = null;
        $before = copyRows();

        expect(copyRefusal(fn () => startCopy($group)))->toBe('tasks.copy_id_ranges_unreadable 409')
            ->and(copyRows())->toBe($before)->and($this->ssh->idReads)->toBe(1);
    });

    it('counts a plain container Project\'s copy and seed in ranges of 65,536', function (): void {
        $plain = copySource('app', nested: false);
        config(['orbit.tasks.copies_max' => 0]);
        $default = [['name' => $plain->runtime->container_name, 'project' => 'orbit-instances',
            'config' => ['security.idmap.isolated' => 'true', 'volatile.idmap.base' => '1065536']]];
        // Past the host's share and the default's own map, room for one more map of 65,536: the copy fits, the seed retake would not.
        $this->ssh->ids = ['uid' => [1000000, 65536 * 3], 'gid' => [1000000, 65536 * 3], 'containers' => $default];
        expect(copyRefusal(fn () => startCopy(copyGroup($plain->project))))->toBe('tasks.copy_id_range_full 409');
        $this->ssh->ids = ['uid' => [1000000, 65536 * 4], 'gid' => [1000000, 65536 * 4], 'containers' => $default];
        expect(startCopy(copyGroup($plain->project))->kind)->toBe('instance.create');
    });

    it('refuses a group that is not todo, and a name that an Instance already holds', function (): void {
        $default = copySource();
        $group = copyGroup($default->project);
        containerCreate($default->project, $default->node, 'task-'.$group->id);
        expect(copyRefusal(fn () => startCopy($group)))->toBe('tasks.copy_name_taken 409');
        $group->update(['status' => TaskGroupStatus::Backlog]);
        expect(copyRefusal(fn () => startCopy($group)))->toBe('tasks.not_todo 409');
    });
});

describe('the address of a copy (docs/reference/development-containers.md#the-address-during-the-create)', function (): void {
    it('keeps the nested-fleet task copy of Project 78\'s kind route-free: no Route, no publication, no hold', function (): void {
        swampVaultStandIn();
        // Guard: `requiresRoute()` is false for a Project that serves no web pages, so the admission captures nothing.
        $operation = startCopy(copyGroup(copySource()->project));

        expect($operation->input['route_id'])->toBeNull()
            ->and($operation->input)->not->toHaveKey('route_create')
            ->and(RoutePublicationHolds::nodes($operation))->toBe([])
            ->and(app(NativeDevelopmentInstanceInput::class)->create($operation)['intent']['route'])->toBeNull();
    });

    it('publishes the address of a web-serving nested-fleet task copy in its create when the Node is free; copies started together share the Node and each publish', function (): void {
        swampVaultStandIn();
        $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('root-certificate');
        config(['orbit.tasks.copies_max' => 3]);
        // The Swamp Node probes the Route over SSH: it needs its host key.
        Node::query()->where('name', 'control')->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']);
        $default = copySource('site', true, true);
        // The default's create has ended: its hold of the Node is gone.
        DB::table('route_publication_holds')->delete();
        $first = startCopy(copyGroup($default->project));
        $second = startCopy(copyGroup($default->project));
        $third = startCopy(copyGroup($default->project));

        // Caddy-only copies merge their own site under the Node lock, so they share the Node (docs/reference/routes.md#coordinate-publication).
        expect(DB::table('route_publication_holds')->count())->toBe(0)
            ->and(DB::table('route_withdrawal_holds')->orderBy('operation_id')->pluck('operation_id')->all())->toBe([$first->id, $second->id, $third->id]);
        foreach ([$first, $second, $third] as $copy) {
            expect($copy->refresh()->status)->not->toBe(OperationStatus::Waiting)
                ->and($copy->input['route_id'])->not->toBeNull()
                ->and($copy->input)->toHaveKey('route_create')
                ->and(app(NativeDevelopmentInstanceInput::class)->create($copy)['intent']['route'])->toBe($copy->input['route_create']);
        }
        // The copy is a nested-fleet clone: its Club key comes in the same run as its publication.
        $run = app(NativeDevelopmentInstanceInput::class)->create($first);
        expect($run['intent']['container']['project'])->toBe(InstancePlacement::NestedProject)
            ->and($run['intent']['container']['club_key'] ?? null)->not->toBeNull();
    });

    it('mints the copy key of a task copy at its create run, and none for a plain clone of the same default', function (): void {
        swampVaultStandIn();
        $default = copySource();
        $copy = startCopy(copyGroup($default->project));
        $plain = containerCreate($default->project, $default->node, 'feature');

        $run = app(NativeDevelopmentInstanceInput::class)->create($copy);
        app(NativeDevelopmentInstanceInput::class)->create($plain);

        // Only the hash stays on the runtime; the key itself goes to the copy through the vault (docs/reference/development-containers.md#the-copy-key).
        expect(InstanceRuntime::query()->where('instance_id', $copy->target_id)->value('agent_key_hash'))->toMatch('/\A[0-9a-f]{64}\z/')
            ->and(InstanceRuntime::query()->where('instance_id', $plain->target_id)->value('agent_key_hash'))->toBeNull()
            ->and(json_encode($run))->not->toContain('ORBIT_COPY_KEY');
    });
});

describe('a failed create of the copy retries once', function (): void {
    it('records the first failure, removes the copy and starts the create once more for the same group', function (string $code, string $step): void {
        $group = copyGroup(copySource()->project);
        $first = startCopy($group);

        failCopyCreate($first, $code, $step);

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Todo)
            ->and($group->taskable_id)->toBe($first->target_id)
            ->and($group->copy_create_failure)->toBe(['operation_id' => $first->id, 'failed_step' => $step, 'error_code' => $code])
            ->and($group->assistance_requested)->toBeFalse();
        Queue::assertPushed(DestroyTaskCopy::class, static fn (DestroyTaskCopy $job): bool => $job->instanceId === $first->target_id);
        Queue::assertNotPushed(RetryTaskCopyCreate::class);

        // The retry waits for the copy to be gone: its name and its isolated range are the new copy's.
        removeFailedCopy($first->target_id);
        expect($group->refresh()->taskable_id)->toBeNull()->and(Instance::query()->find($first->target_id))->toBeNull();
        Queue::assertPushed(RetryTaskCopyCreate::class, static fn (RetryTaskCopyCreate $job): bool => $job->groupId === $group->id && $job->operationId === $first->id);

        retryTaskCopy($group, $first);

        $retry = Operation::query()->where('kind', 'instance.create')->whereKeyNot($first->id)->latest('id')->firstOrFail();
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Reserved)
            ->and($group->taskable_id)->toBe($retry->target_id)
            ->and($retry->triggered_by)->toBe($first->triggered_by)
            ->and(Instance::query()->findOrFail($retry->target_id)->name)->toBe('task-'.$group->id);
    })->with([
        'a failed run' => ['instance.create_failed', 'clone'],
        'a register failure' => ['instance.create_register_failed', 'register'],
        'an unreadable baseline' => ['instance.workspace_baseline_unavailable', 'clone-register'],
        'a start that Swamp refused' => ['swamp.run_start_refused', 'start'],
    ]);

    it('puts the group in todo, never failed, with both failures named, and removes the copy without a third create', function (): void {
        $group = copyGroup(copySource()->project);
        $first = startCopy($group);
        failCopyCreate($first, 'instance.workspace_baseline_unavailable', 'clone-register');
        removeFailedCopy($first->target_id);
        retryTaskCopy($group, $first);
        $retry = Operation::query()->where('kind', 'instance.create')->whereKeyNot($first->id)->latest('id')->firstOrFail();

        failCopyCreate($retry, 'instance.create_register_failed', 'register');

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Todo)
            ->and($group->taskable_id)->toBe($retry->target_id)
            ->and($group->copy_create_failure)->toBeNull()
            ->and($group->assistance_kind)->toBe(AssistanceKind::Failure)
            ->and($group->assistance_reason)->toBe(TaskLifecycle::CreateFailedReason
                ."Operation {$first->id} ended at step clone-register with instance.workspace_baseline_unavailable, and its retry, Operation {$retry->id}, "
                ."ended at step register with instance.create_register_failed. Orbit removes the copy; start the task again with `orbit task:start {$group->id}` once it is gone.");
        Queue::assertPushed(DestroyTaskCopy::class, static fn (DestroyTaskCopy $job): bool => $job->instanceId === $retry->target_id);

        removeFailedCopy($retry->target_id);

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Todo)->and($group->taskable_id)->toBeNull()
            ->and($group->assistance_kind)->toBe(AssistanceKind::Failure);
        Queue::assertPushed(RetryTaskCopyCreate::class, 1);
    });

    it('retries only a create that failed: not one whose budget expired, and not a cancelled one', function (): void {
        $project = copySource(nested: false)->project;
        [$failed, $expired, $cancelled] = [copyGroup($project), copyGroup($project), copyGroup($project)];
        $failure = startCopy($failed);
        $expiry = startCopy($expired);
        $cancel = startCopy($cancelled);

        failCopyCreate($failure, 'instance.create_failed', 'clone');
        failCopyCreate($expiry, 'operation.timed_out', 'clone');
        app(CancelOperationAction::class)->execute($cancel);
        removeFailedCopy($failure->target_id);
        removeFailedCopy($cancel->target_id);

        expect([$failed->refresh()->status, $expired->refresh()->status, $cancelled->refresh()->status])
            ->toBe([TaskGroupStatus::Todo, TaskGroupStatus::Reserved, TaskGroupStatus::Failed])
            ->and([$expired->copy_create_failure, $cancelled->copy_create_failure])->toBe([null, null]);
        Queue::assertPushed(RetryTaskCopyCreate::class, 1);
        Queue::assertPushed(RetryTaskCopyCreate::class, static fn (RetryTaskCopyCreate $job): bool => $job->groupId === $failed->id);
    });

    it('cancels a todo group that task:cancel ends while it holds its failed copy, and starts no retry', function (): void {
        $group = copyGroup(copySource()->project);
        $first = startCopy($group);
        failCopyCreate($first, 'instance.create_failed', 'clone');

        $cancel = app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);
        destroySucceeded(Operation::query()->findOrFail($cancel->result['destroy_operation_id'] ?? $cancel->state['destroy_operation_id']));

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled)->and($group->taskable_id)->toBeNull();
        Queue::assertNotPushed(RetryTaskCopyCreate::class);
    });

    it('refuses task:start with tasks.copy_removing while the failed copy is bound, and starts the group again once it is gone', function (): void {
        $group = copyGroup(copySource()->project);
        $first = startCopy($group);
        failCopyCreate($first, 'instance.create_failed', 'clone');
        removeFailedCopy($first->target_id);
        retryTaskCopy($group, $first);
        $retry = Operation::query()->where('kind', 'instance.create')->whereKeyNot($first->id)->latest('id')->firstOrFail();
        failCopyCreate($retry, 'instance.create_failed', 'clone');

        expect(copyRefusal(fn () => startCopy($group->refresh())))->toBe('tasks.copy_removing 409');

        removeFailedCopy($retry->target_id);
        $again = startCopy($group->refresh());

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Reserved)->and($group->taskable_id)->toBe($again->target_id);
        // The copy that finally comes up ends the request that named the two failures.
        Instance::query()->findOrFail($again->target_id)->update(['status' => InstanceState::Active]);
        app(OperationStore::class)->change($again, ['status' => OperationStatus::Succeeded, 'finished_at' => now()]);
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($group->assistance_requested)->toBeFalse();
    });
});

describe('the end of the copy\'s create', function (): void {
    it('puts the group back in todo and queues the copy\'s destroy when the create fails; a clone that no group owns changes nothing', function (): void {
        $default = copySource();
        $group = copyGroup($default->project);
        $operation = runningOperation(startCopy($group));
        $normal = runningOperation(containerCreate($default->project, $default->node, 'feature'));
        $failed = ['status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
            'error_code' => 'instance.create_failed', 'result' => ['ready' => false]];

        app(DevelopmentInstanceCreateProjection::class)->apply($operation, [...$failed, 'result' => [...$failed['result'], 'instance_id' => $operation->target_id]]);
        app(DevelopmentInstanceCreateProjection::class)->apply($normal, [...$failed, 'result' => [...$failed['result'], 'instance_id' => $normal->target_id]]);

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Todo)
            ->and($group->taskable_id)->toBe($operation->target_id)
            ->and($operation->refresh()->status)->toBe(OperationStatus::Failed)
            ->and($normal->refresh()->status)->toBe(OperationStatus::Failed);
        Queue::assertPushed(DestroyTaskCopy::class, 1);
        Queue::assertPushed(DestroyTaskCopy::class, static fn (DestroyTaskCopy $job): bool => $job->instanceId === $operation->target_id);
    });

    it('runs the group when the create succeeds with the copy active, and waits while the copy is not fully set up', function (): void {
        $group = copyGroup(copySource()->project);
        $operation = startCopy($group);
        $copy = Instance::query()->findOrFail($operation->target_id);
        $copy->update(['status' => InstanceState::SourceResolved]);

        app(OperationStore::class)->change($operation, ['status' => OperationStatus::Succeeded, 'finished_at' => now()]);
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Reserved);

        $copy->update(['status' => InstanceState::Active]);
        expect(TaskLifecycle::ready($operation->refresh(), $copy->refresh()))->toBeTrue();
        $operation->forceFill(['status' => 'running', 'finished_at' => null])->save();
        app(OperationStore::class)->change($operation, ['status' => OperationStatus::Succeeded, 'finished_at' => now()]);

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($group->started_at)->not->toBeNull();
        Queue::assertNotPushed(DestroyTaskCopy::class);
    });

    it('runs the group when the copy\'s completion makes it active', function (): void {
        $group = copyGroup(copySource()->project);
        [$copy, $completion] = copyCompleting($group);
        $copy->update(['status' => InstanceState::Active]);

        app(OperationStore::class)->change($completion, ['status' => OperationStatus::Succeeded, 'finished_at' => now()]);

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($group->assistance_requested)->toBeFalse();
        Queue::assertNotPushed(DestroyTaskCopy::class);
    });

    it('keeps the copy and the group reserved with a failure assistance request when the completion fails or is cancelled', function (OperationStatus $status, ?string $code, string $step, string $named): void {
        $group = copyGroup(copySource()->project);
        [$copy, $completion] = copyCompleting($group);

        app(OperationStore::class)->change($completion, ['status' => $status, 'finished_at' => now(), 'error_code' => $code, 'failed_step' => $step === 'complete' ? null : $step]);

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Reserved)
            ->and($group->taskable_id)->toBe($copy->id)
            ->and($group->assistance_requested)->toBeTrue()
            ->and($group->assistance_kind)->toBe(AssistanceKind::Failure)
            ->and($group->assistance_reason)->toBe(TaskLifecycle::CompletionFailedReason
                ."Operation {$completion->id} ended at step {$step} with {$named}. Retry: orbit instance:complete {$copy->id}.")
            ->and($copy->refresh()->status)->toBe(InstanceState::SourceResolved);
        Queue::assertNotPushed(DestroyTaskCopy::class);
    })->with([
        'failed' => [OperationStatus::Failed, 'instance.container_fleet_drift', 'clone-fleet', 'instance.container_fleet_drift'],
        'cancelled' => [OperationStatus::Cancelled, 'operation.cancelled', 'complete', 'operation.cancelled'],
    ]);

    it('keeps the copy when the create\'s end records a refused completion', function (): void {
        $group = copyGroup(copySource()->project);
        $create = startCopy($group);
        $copy = Instance::query()->findOrFail($create->target_id);
        $copy->update(['status' => InstanceState::SourceResolved]);
        app(OperationStore::class)->change($create, ['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null]);
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Reserved);

        // What DevelopmentInstanceCreateProjection records when the completion's admission refuses at the create's end.
        $completion = app(StartInstanceCompleteAction::class)->refused($copy, $create->refresh(), 'cluster.router_required', 'The Cluster Route requires a recorded Router.');

        expect([$completion->status, $completion->error_code])->toBe([OperationStatus::Failed, 'cluster.router_required'])
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Reserved)
            ->and($group->assistance_reason)->toContain("Operation {$completion->id} ended at step complete with cluster.router_required");
        Queue::assertNotPushed(DestroyTaskCopy::class);
    });

    it('leaves the group reserved with its copy and asks nothing when the completion\'s budget expired', function (): void {
        $group = copyGroup(copySource()->project);
        [$copy, $completion] = copyCompleting($group);

        app(OperationStore::class)->change($completion, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'operation.timed_out']);

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Reserved)->and($group->taskable_id)->toBe($copy->id)
            ->and($group->assistance_requested)->toBeFalse()
            ->and(TaskLifecycle::completionFailure($copy->refresh()))->toBeNull();
        Queue::assertNotPushed(DestroyTaskCopy::class);
    });

    it('never replaces an open direction request with a failed completion', function (): void {
        $group = copyGroup(copySource()->project);
        [, $completion] = copyCompleting($group);
        TaskAssistance::apply($group, AssistanceKind::Direction, 'Which way?', 'Asked.');

        app(OperationStore::class)->change($completion, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'instance.container_fleet_drift']);

        expect($group->refresh()->only(['assistance_kind', 'assistance_question', 'assistance_reason']))
            ->toBe(['assistance_kind' => AssistanceKind::Direction, 'assistance_question' => 'Which way?', 'assistance_reason' => 'Asked.'])
            ->and($group->status)->toBe(TaskGroupStatus::Reserved);
        Queue::assertNotPushed(DestroyTaskCopy::class);
    });

    it('runs the group and clears exactly the completion\'s request when a retried completion succeeds', function (bool $other): void {
        $group = copyGroup(copySource()->project);
        [$copy, $completion] = copyCompleting($group);
        app(OperationStore::class)->change($completion, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'instance.container_fleet_drift']);
        $first = $group->refresh()->assistance_reason;

        $retry = copyCompletion($copy);
        app(OperationStore::class)->change($retry, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'instance.container_process_inactive']);
        expect($group->refresh()->assistance_reason)->toBe(str_replace(
            ["Operation {$completion->id}", 'instance.container_fleet_drift'], ["Operation {$retry->id}", 'instance.container_process_inactive'], $first));
        if ($other) {
            // Another failure that someone recorded after the completion's stays.
            TaskAssistance::apply($group, AssistanceKind::Failure, reason: 'The check failed.', replaceFailure: true);
        }

        $success = copyCompletion($copy);
        $copy->update(['status' => InstanceState::Active]);
        app(OperationStore::class)->change($success, ['status' => OperationStatus::Succeeded, 'finished_at' => now()]);

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)
            ->and($group->assistance_requested)->toBe($other)
            ->and($group->assistance_reason)->toBe($other ? 'The check failed.' : null);
        Queue::assertNotPushed(DestroyTaskCopy::class);
    })->with(['only the completion\'s request' => false, 'another failure request' => true]);

    it('replaces an unrelated open failure with the failed completion\'s request, so the held copy always shows', function (): void {
        $group = copyGroup(copySource()->project);
        [$copy, $completion] = copyCompleting($group);
        TaskAssistance::apply($group, AssistanceKind::Failure, reason: 'The check failed.');

        app(OperationStore::class)->change($completion, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'instance.container_fleet_drift']);

        expect($group->refresh()->assistance_kind)->toBe(AssistanceKind::Failure)
            ->and($group->assistance_reason)->toStartWith(TaskLifecycle::CompletionFailedReason."Operation {$completion->id} ");
    });

    it('names the real code of an interrupted completion and a Process definition the retry needs first', function (): void {
        $group = copyGroup(copySource()->project);
        [$copy, $completion] = copyCompleting($group);

        app(OperationStore::class)->change($completion, ['status' => OperationStatus::Interrupted, 'finished_at' => now(), 'error_code' => 'operation.worker_lost',
            'result' => ['recovery' => InstanceCompleteProjection::recovery($copy, true)]]);

        expect(TaskLifecycle::completionFailure($copy->refresh()))->toBe(['operation_id' => $completion->id, 'failed_step' => 'complete',
            'error_code' => 'operation.worker_lost', 'retry' => 'orbit instance:complete '.$copy->id, 'requires' => 'project_process_definition'])
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Reserved)
            ->and($group->assistance_reason)->toBe(TaskLifecycle::CompletionFailedReason."Operation {$completion->id} ended at step complete with operation.worker_lost. "
                ."Fix the Project's Process definition first: the retry installs the definitions as they are. Retry: orbit instance:complete {$copy->id}.");
        Queue::assertNotPushed(DestroyTaskCopy::class);

        // An interrupted end without a code names its status, not a cancel.
        $completion->forceFill(['error_code' => null, 'result' => []])->save();
        expect(TaskLifecycle::completionFailure($copy)['error_code'] ?? null)->toBe('operation.interrupted');
    });

    it('finds no completion failure on a copy that is not source_resolved: one a removal owns, or an active one', function (bool $removal): void {
        $group = copyGroup(copySource()->project);
        [$copy, $completion] = copyCompleting($group);
        app(OperationStore::class)->change($completion, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'instance.container_fleet_drift', 'lock_key' => null]);
        expect(TaskLifecycle::completionFailure($copy->refresh()))->not->toBeNull();

        $removal ? app(StartInstanceDestroyAction::class)->execute($copy, true, 'test') : $copy->update(['status' => InstanceState::Active]);

        expect($copy->refresh()->status)->toBe($removal ? InstanceState::Removing : InstanceState::Active)
            ->and(TaskLifecycle::completionFailure($copy))->toBeNull();
    })->with(['a removal' => true, 'an active copy' => false]);

    it('changes no group when the completion of a clone that no task owns fails', function (): void {
        $default = copySource();
        $normal = runningOperation(containerCreate($default->project, $default->node, 'feature'));
        $clone = Instance::query()->findOrFail($normal->target_id);
        $clone->update(['status' => InstanceState::SourceResolved]);
        app(OperationStore::class)->change($normal, ['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null]);
        $completion = copyCompletion($clone);

        app(OperationStore::class)->change($completion, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'instance.container_fleet_drift']);

        expect($clone->refresh()->status)->toBe(InstanceState::SourceResolved)
            ->and(Task::query()->where('assistance_requested', true)->exists())->toBeFalse();
        Queue::assertNotPushed(DestroyTaskCopy::class);
    });

    it('fails the group and queues the destroy when the create is cancelled before its run starts', function (): void {
        $group = copyGroup(copySource()->project);
        $operation = startCopy($group);

        app(CancelOperationAction::class)->execute($operation);

        expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Failed)
            ->and($group->taskable_id)->toBe($operation->target_id);
        Queue::assertPushed(DestroyTaskCopy::class, static fn (DestroyTaskCopy $job): bool => $job->instanceId === $operation->target_id);
    });

    it('leaves the group reserved with its copy when the create\'s budget expired, as its run may still be stopping', function (): void {
        $group = copyGroup(copySource()->project);
        $operation = runningOperation(startCopy($group));

        app(OperationStore::class)->change($operation, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'operation.timed_out']);

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Reserved)->and($group->taskable_id)->toBe($operation->target_id);
        Queue::assertNotPushed(DestroyTaskCopy::class);
    });

    it('puts the group back in todo and queues the destroy when the create\'s run ended without a readable result', function (): void {
        $group = copyGroup(copySource()->project);
        $operation = runningOperation(startCopy($group));

        app(FailUnreadableResultAction::class)->execute($operation, 'run-1', ['status' => 'succeeded', 'completedAt' => now()->toIso8601ZuluString()], 'finish', 'skipped');

        expect($operation->refresh()->error_code)->toBe('operation.result_unreadable')
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Todo);
        Queue::assertPushed(DestroyTaskCopy::class, static fn (DestroyTaskCopy $job): bool => $job->instanceId === $operation->target_id);
    });

    it('destroys a failed group\'s copy with force, and leaves the copy of a group that still runs', function (): void {
        $group = copyGroup(copySource()->project);
        $operation = startCopy($group);
        $copy = Instance::query()->findOrFail($operation->target_id);
        $operation->forceFill(['status' => 'failed', 'finished_at' => now()])->save();

        $group->update(['status' => TaskGroupStatus::Running]);
        destroyTaskCopy($copy);
        expect(Operation::query()->where('kind', 'instance.destroy')->count())->toBe(0);

        $group->update(['status' => TaskGroupStatus::Failed]);
        $calls = $this->ssh->calls;
        destroyTaskCopy($copy);
        $destroy = Operation::query()->where('kind', 'instance.destroy')->sole();
        expect($destroy->target_id)->toBe($copy->id)->and($destroy->input['force'])->toBeTrue()->and($destroy->triggered_by)->toBe('tasks')
            // The records prove that no agent worked in the copy: its create failed and no T3 server is registered, so nothing is read.
            ->and($this->ssh->calls)->toBe($calls);
    });

    it('reads a failed group\'s copy first when the records do not prove it unused, and keeps one that holds work', function (Closure $usable): void {
        $group = copyGroup(copySource()->project);
        $operation = startCopy($group);
        $copy = Instance::query()->findOrFail($operation->target_id);
        $operation->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
        $usable($operation);
        $group->update(['status' => TaskGroupStatus::Failed]);
        $this->ssh->answer = ['state' => 'dirty', 'baseline' => 'recorded', 'head' => str_repeat('e', 40),
            'modified' => ['count' => 1, 'names' => ['a.php']], 'untracked' => ['count' => 2, 'names' => ['b', 'c']],
            'stashes' => ['count' => 1, 'names' => ['stash@{0}']], 'commits' => ['count' => 4, 'branch_count' => 1, 'branches' => [['name' => $copy->name, 'count' => 4]]]];

        $calls = $this->ssh->calls;
        destroyTaskCopy($copy);

        expect(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse()
            ->and($this->ssh->calls)->toBe($calls + 1)
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Failed)
            ->and($group->taskable_id)->toBe($copy->id)
            ->and($group->assistance_kind)->toBe(AssistanceKind::RemovalFailure)
            ->and($group->assistance_reason)->toBe(DestroyTaskCopy::KeptReason.'it holds work that origin lacks: 4 unpushed commits, 3 uncommitted files, 2 of them untracked, 1 stashes. '
                ."End it with `orbit task:cancel {$group->id}`, with `--discard` if the work is not wanted.");
    })->with([
        'a registered T3 server' => [copyT3Registered(...)],
        'a create that succeeded' => [fn (Operation $create) => $create->forceFill(['status' => 'succeeded'])->save()],
    ]);

    it('keeps a failed group\'s registered copy whose workspace cannot be read', function (): void {
        $group = copyGroup(copySource()->project);
        $operation = startCopy($group);
        $copy = Instance::query()->findOrFail($operation->target_id);
        $operation->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
        copyT3Registered();
        $group->update(['status' => TaskGroupStatus::Failed]);
        $this->ssh->answer = ['state' => 'unknown', 'reason' => 'stopped'];

        $calls = $this->ssh->calls;
        destroyTaskCopy($copy);

        expect(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse()
            ->and($group->refresh()->assistance_kind)->toBe(AssistanceKind::RemovalFailure)
            ->and($group->assistance_reason)->toBe(DestroyTaskCopy::KeptReason.'its workspace could not be read: stopped. '
                ."End it with `orbit task:cancel {$group->id}`, with `--discard` if the work is not wanted.");
    });

    it('destroys a failed group\'s registered copy whose read is clean', function (): void {
        $group = copyGroup(copySource()->project);
        $operation = startCopy($group);
        $copy = Instance::query()->findOrFail($operation->target_id);
        $operation->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
        copyT3Registered();
        $group->update(['status' => TaskGroupStatus::Failed]);

        $calls = $this->ssh->calls;
        destroyTaskCopy($copy);

        expect(Operation::query()->where('kind', 'instance.destroy')->sole()->target_id)->toBe($copy->id)
            ->and($this->ssh->calls)->toBe($calls + 1)
            ->and($group->refresh()->assistance_requested)->toBeFalse();
    });

    it('reads the guards again after the read and neither destroys nor asks when another Operation took the copy meanwhile', function (string $state): void {
        $group = copyGroup(copySource()->project);
        $operation = startCopy($group);
        $copy = Instance::query()->findOrFail($operation->target_id);
        $operation->forceFill(['status' => 'failed', 'finished_at' => now(), 'lock_key' => null])->save();
        copyT3Registered();
        $group->update(['status' => TaskGroupStatus::Failed]);
        $this->ssh->answer = $state === 'clean' ? $this->ssh->answer : ['state' => 'unknown', 'reason' => 'stopped'];
        // During the read, a person's destroy of the copy is admitted.
        $read = $this->ssh;
        app()->instance(SshExecutor::class, new readonly class($read, $copy) implements SshExecutor
        {
            public function __construct(private SshExecutor $read, private Instance $copy) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => $this->copy->id, 'node_id' => $this->copy->node_id,
                    'input' => [], 'status' => OperationStatus::Queued, 'sequence' => 1, 'attempts' => 0]);

                return $this->read->execute($connection, $command);
            }
        });

        destroyTaskCopy($copy);

        expect(Operation::query()->where('kind', 'instance.destroy')->count())->toBe(1)
            ->and($group->refresh()->assistance_requested)->toBeFalse();
    })->with(['a clean read' => 'clean', 'an unknown read' => 'unknown']);

    it('ends the request it recorded when the kept copy\'s destroy succeeds, and only that request', function (bool $own): void {
        $group = copyGroup(copySource()->project);
        $operation = startCopy($group);
        $copy = Instance::query()->findOrFail($operation->target_id);
        $operation->forceFill(['status' => 'failed', 'finished_at' => now(), 'lock_key' => null])->save();
        copyT3Registered();
        $group->update(['status' => TaskGroupStatus::Failed]);
        $this->ssh->answer = ['state' => 'unknown', 'reason' => 'stopped'];
        destroyTaskCopy($copy);
        expect($group->refresh()->assistance_kind)->toBe(AssistanceKind::RemovalFailure);
        if (! $own) {
            TaskAssistance::apply($group, AssistanceKind::RemovalFailure, reason: 'The branch could not be deleted.', replaceFailure: true);
        }

        destroySucceeded(app(StartInstanceDestroyAction::class)->execute($copy->refresh(), true, 'test'));

        expect($group->refresh()->taskable_id)->toBeNull()
            ->and($group->status)->toBe(TaskGroupStatus::Failed)
            ->and($group->assistance_requested)->toBe(! $own)
            ->and($group->assistance_reason)->toBe($own ? null : 'The branch could not be deleted.');
    })->with(['its own request' => true, 'another removal failure' => false]);
});

describe('a cancel of a group that owns a copy', function (): void {
    it('refuses a copy that holds work origin lacks, with its counts, and changes nothing', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $this->ssh->answer = ['state' => 'dirty', 'baseline' => 'recorded', 'head' => str_repeat('e', 40),
            'modified' => ['count' => 1, 'names' => ['a.php']], 'untracked' => ['count' => 2, 'names' => ['b', 'c']],
            'stashes' => ['count' => 0, 'names' => []], 'commits' => ['count' => 3, 'branch_count' => 1, 'branches' => [['name' => 'task-1', 'count' => 3]]]];

        try {
            app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);
            $this->fail('The cancel destroyed unpublished work.');
        } catch (ResourceOperationException $error) {
            expect($error->errorCode)->toBe('tasks.copy_unpublished_work')->and($error->status)->toBe(409)
                ->and($error->details)->toBe(['head' => str_repeat('e', 40), 'commits' => 3, 'unpushed' => 3, 'work' => null, 'uncommitted' => 3, 'modified' => 1, 'untracked' => 2, 'stashes' => 0]);
        }
        expect(Operation::query()->whereIn('kind', ['instance.destroy', 'tasks.cancel'])->exists())->toBeFalse()
            ->and(Instance::query()->find($copy->id))->not->toBeNull()
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Running);
    });

    it('records what the copy holds, admits the copy\'s destroy with force on discard and cancels the group when the destroy succeeds', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $this->ssh->answer = ['state' => 'dirty', 'baseline' => 'recorded', 'head' => str_repeat('e', 40),
            'modified' => ['count' => 1, 'names' => ['a.php']], 'untracked' => ['count' => 2, 'names' => ['b', 'c']],
            'stashes' => ['count' => 0, 'names' => []], 'commits' => ['count' => 3, 'branch_count' => 1, 'branches' => [['name' => 'task-1', 'count' => 3]]]];

        $cancel = app(TaskCancelIntent::class)->start($group->refresh(), 'test', null, discard: true);

        $destroy = Operation::query()->where('kind', 'instance.destroy')->sole();
        expect($cancel->kind)->toBe('tasks.cancel')
            ->and($cancel->status)->toBe(OperationStatus::Succeeded)
            ->and($cancel->result['destroy_operation_id'])->toBe($destroy->id)
            ->and($cancel->result['workspace'])->toMatchArray(['state' => 'dirty', 'head' => str_repeat('e', 40), 'unpushed' => 3, 'uncommitted' => 3,
                'modified' => 1, 'untracked' => 2, 'stashes' => 0])
            ->and($destroy->target_id)->toBe($copy->id)->and($destroy->input['force'])->toBeTrue()
            // The group runs on until the copy is gone.
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Running);
        expect(copyRefusal(fn () => app(TaskCancelIntent::class)->start($group->refresh(), 'test', null, discard: true)))->toBe('operation.target_busy 409');

        destroySucceeded($destroy);

        expect(Instance::query()->find($copy->id))->toBeNull()
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($group->taskable_id)->toBeNull()
            ->and($group->tasks()->firstOrFail()->status)->toBe(TaskStatus::Cancelled);
    });

    it('refuses a copy it cannot read unless the caller discards it, then records it as unknown and admits its destroy', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $this->ssh->answer = ['state' => 'unknown', 'reason' => 'stopped'];

        try {
            app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);
            $this->fail('The cancel destroyed an unread copy.');
        } catch (ResourceOperationException $error) {
            expect($error->errorCode)->toBe('tasks.copy_workspace_unknown')->and($error->status)->toBe(409)->and($error->details)->toBe(['reason' => 'stopped']);
        }
        expect(Operation::query()->whereIn('kind', ['instance.destroy', 'tasks.cancel'])->exists())->toBeFalse();

        $cancel = app(TaskCancelIntent::class)->start($group->refresh(), 'test', null, discard: true);

        expect($cancel->result['workspace'])->toMatchArray(['state' => 'unknown', 'reason' => 'stopped', 'unpushed' => null])
            ->and(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->exists())->toBeTrue();
    });

    it('is refused while the copy\'s create still runs', function (): void {
        $group = copyGroup(copySource()->project);
        $operation = runningOperation(startCopy($group));

        expect(copyRefusal(fn () => app(TaskCancelIntent::class)->start($group->refresh(), 'test', null)))->toBe('operation.target_busy 409')
            ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse()
            ->and($operation->refresh()->status)->toBe(OperationStatus::Running);
    });

    it('is refused before the copy\'s destroy while a timed-out Operation still holds the group', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $held = Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $copy->node_id,
            'lock_key' => OperationStore::lockKey('task_group', $group->id), 'input' => [], 'status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out',
            'state' => ['swamp_run_id' => '5d0c3a8e-4f7b-4a51-9a63-0e2b1c7d9f10', 'native_timeout' => ['budget_seconds' => 900]], 'sequence' => 1, 'attempts' => 1,
            'started_at' => now()->subMinutes(20), 'finished_at' => now()->subMinutes(5)]);

        expect(copyRefusal(fn () => app(TaskCancelIntent::class)->start($group->refresh(), 'test', null)))->toBe('operation.target_busy 409')
            ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse()
            ->and(Operation::query()->where('kind', 'tasks.cancel')->exists())->toBeFalse()
            ->and(Instance::query()->find($copy->id))->not->toBeNull()
            ->and($held->refresh()->lock_key)->not->toBeNull();
    });

    it('names only the commits the group published with success to the read, so a published copy with no later work cancels without discard', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $published = str_repeat('f', 40);
        Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $copy->node_id,
            'input' => [], 'result' => ['branch' => $copy->name, 'commit' => $published], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1]);
        // A failed publish of this group and a publish of another group pushed nothing of this copy: neither commit reaches the read.
        Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $copy->node_id,
            'input' => [], 'result' => ['branch' => $copy->name, 'commit' => str_repeat('a', 40)], 'status' => OperationStatus::Failed, 'sequence' => 2, 'attempts' => 1]);
        $other = copyGroup($group->project);
        Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $other->id, 'node_id' => $copy->node_id,
            'input' => [], 'result' => ['branch' => 'other', 'commit' => str_repeat('b', 40)], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1]);

        $cancel = app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);

        expect($cancel->result['workspace']['state'])->toBe('clean')
            ->and(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->exists())->toBeTrue()
            ->and(last($this->ssh->inputs))->toContain('PUBLISHED = json.loads(base64.b64decode("'.base64_encode(json_encode([$published])).'"))');
    });

    it('reads the copy, its replication slots and its Schedules outside the transaction that records the cancel and the destroy', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $outside = DB::transactionLevel();
        $levels = new ArrayObject;
        app()->instance(InstanceDestroyReplicationSlots::class, new class($levels)
        {
            public function __construct(private ArrayObject $levels) {}

            public function assertNone(array $input): void
            {
                $this->levels['slots'] = DB::transactionLevel();
            }
        });
        app()->instance(NativeSchedules::class, new class($levels)
        {
            public function __construct(private ArrayObject $levels) {}

            public function removeForInstances(array $instanceIds): void
            {
                $this->levels['schedules'] = DB::transactionLevel();
            }
        });
        Operation::creating(function (Operation $operation) use ($levels): void {
            $levels[$operation->kind] = DB::transactionLevel();
        });

        app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);

        expect($levels['slots'])->toBe($outside)->and($levels['schedules'])->toBe($outside)
            ->and($levels['tasks.cancel'])->toBeGreaterThan($outside)
            ->and(Operation::query()->where('kind', 'instance.destroy')->sole()->id)
            ->toBe(Operation::query()->where('kind', 'tasks.cancel')->sole()->result['destroy_operation_id']);
    });

    it('refuses a dirty copy over HTTP with its counts, and admits its destroy when the request discards it', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
            'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
        $this->ssh->answer = ['state' => 'dirty', 'baseline' => 'recorded', 'head' => 'task-1',
            'modified' => ['count' => 1, 'names' => ['a.php']], 'untracked' => ['count' => 0, 'names' => []],
            'stashes' => ['count' => 0, 'names' => []], 'commits' => ['count' => 2, 'branch_count' => 1, 'branches' => [['name' => 'task-1', 'count' => 2]]]];

        $this->withHeader('Idempotency-Key', 'cancel-dirty')->postJson("/api/v1/tasks/{$group->id}/cancel")
            ->assertStatus(409)->assertJsonPath('error.code', 'tasks.copy_unpublished_work')
            ->assertJsonPath('error.details.unpushed', 2)->assertJsonPath('error.details.uncommitted', 1);
        expect(Operation::query()->whereIn('kind', ['instance.destroy', 'tasks.cancel'])->exists())->toBeFalse();

        $this->withHeader('Idempotency-Key', 'cancel-discard')->postJson("/api/v1/tasks/{$group->id}/cancel", ['discard' => true])
            ->assertStatus(202)->assertJsonPath('data.kind', 'tasks.cancel');
        expect(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->exists())->toBeTrue();
    });

    it('is accepted over HTTP, with its destroy, while an automatic role converge waits for the copy\'s Node', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
            'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
        $this->ssh->answer = ['state' => 'clean', 'baseline' => 'recorded', 'head' => 'task-1',
            'modified' => ['count' => 0, 'names' => []], 'untracked' => ['count' => 0, 'names' => []],
            'stashes' => ['count' => 0, 'names' => []], 'commits' => ['count' => 0, 'branch_count' => 0, 'branches' => []]];
        // Instance work holds the Node, so the release's role converge is refused and queues: new Instance work waits behind it.
        app(OperationStore::class)->create('instance.deploy', 'instance', 99, $copy->node_id, [], 'test', null);
        $converge = ['_converge' => ['intent' => ['release_roles_only' => true, 'automatic' => true]]];
        expect(copyRefusal(fn () => app(NodeWorkAdmission::class)->check('node.converge', $copy->node_id, $converge)))->toBe('operation.target_busy 409')
            ->and(copyRefusal(fn () => app(OperationStore::class)->create('instance.deploy', 'instance', 100, $copy->node_id, [], 'test', null)))
            ->toBe('operation.target_busy 409');

        $this->withHeader('Idempotency-Key', 'cancel-waiting')->postJson("/api/v1/tasks/{$group->id}/cancel", ['discard' => true])
            ->assertStatus(202)->assertJsonPath('data.kind', 'tasks.cancel');
        expect(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->exists())->toBeTrue();
    });

    it('answers HTTP 409 operation.target_busy recorded as such, and admits the copy\'s destroy at once while an automatic role converge runs on its Node', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
            'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
        $this->ssh->answer = ['state' => 'clean', 'baseline' => 'recorded', 'head' => 'task-1',
            'modified' => ['count' => 0, 'names' => []], 'untracked' => ['count' => 0, 'names' => []],
            'stashes' => ['count' => 0, 'names' => []], 'commits' => ['count' => 0, 'branch_count' => 0, 'branches' => []]];
        $converge = Operation::query()->create(['operation_version' => 1, 'child_versions' => [], 'kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $copy->node_id,
            'node_id' => $copy->node_id, 'input' => ['_converge' => ['intent' => ['release_roles_only' => true, 'automatic' => true]]], 'state' => [], 'status' => OperationStatus::Running,
            'sequence' => 1, 'attempts' => 1, 'triggered_by' => 'test', 'lock_key' => 'node:'.$copy->node_id]);

        // A refusal of the cancel answers HTTP 409 with its own code, never gateway.unhandled, and Activity records that code.
        $other = Operation::query()->create(['kind' => 'tasks.cancel', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $copy->node_id,
            'input' => [], 'state' => [], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1, 'triggered_by' => 'test']);
        $this->withHeader('Idempotency-Key', 'cancel-busy')->postJson("/api/v1/tasks/{$group->id}/cancel", ['discard' => true])
            ->assertStatus(409)->assertJsonPath('error.code', 'operation.target_busy');
        $activity = Activity::query()->where('command', 'task:cancel')->sole();
        expect([$activity->status, $activity->error_code])->toBe(['failed', 'operation.target_busy']);
        $other->update(['status' => OperationStatus::Cancelled, 'finished_at' => now()]);

        // Release 2161's first cancel of group 5956 was refused while a release converge ran on the copy's Node. The copy's destroy is
        // now accepted at once beside it (docs/reference/node-provisioning.md).
        $this->withHeader('Idempotency-Key', 'cancel-converging')->postJson("/api/v1/tasks/{$group->id}/cancel", ['discard' => true])
            ->assertStatus(202)->assertJsonPath('data.kind', 'tasks.cancel');
        expect(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->exists())->toBeTrue()
            ->and($converge->refresh()->status)->toBe(OperationStatus::Running);
    });

    it('admits no destroy when another Operation takes the group lock after the cancel\'s checks', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        // The race: the first Operation the cancel writes after its checks finds the group lock taken by another Operation.
        $raced = false;
        Operation::creating(function (Operation $operation) use ($group, $copy, &$raced): void {
            if ($raced || $operation->kind === 'tasks.publish') {
                return;
            }
            $raced = true;
            Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $copy->node_id,
                'lock_key' => OperationStore::lockKey('task_group', $group->id), 'input' => [], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1]);
        });

        expect(copyRefusal(fn () => app(TaskCancelIntent::class)->start($group->refresh(), 'test', null)))->toBe('operation.target_busy 409')
            ->and($raced)->toBeTrue()
            ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse()
            ->and(Operation::query()->where('kind', 'tasks.cancel')->exists())->toBeFalse()
            ->and(Instance::query()->find($copy->id))->not->toBeNull();
    });

    it('leaves the destroy of a clone that no group owns as it was', function (): void {
        $default = copySource();
        $group = copyGroup($default->project);
        $group->update(['status' => TaskGroupStatus::Running]);
        $clone = Instance::query()->findOrFail(containerCreate($default->project, $default->node, 'feature')->target_id);
        destroySucceeded(app(StartInstanceDestroyAction::class)->execute($clone, true));

        expect(Instance::query()->find($clone->id))->toBeNull()->and($group->refresh()->status)->toBe(TaskGroupStatus::Running);
    });
});

describe('a complete of a group that owns a copy', function (): void {
    it('removes a copy with an inner volume and its volume in the one destroy that a cancel or a complete admits', function (string $end): void {
        $default = copySource();
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['inner_volume' => 'inner-'.$default->id]);
        $group = copyGroup($default->project);
        $copy = finishedCopy($group, startCopy($group));
        if ($end === 'cancel') {
            $this->ssh->answer = ['state' => 'dirty', 'baseline' => 'recorded', 'head' => str_repeat('e', 40),
                'modified' => ['count' => 1, 'names' => ['a.php']], 'untracked' => ['count' => 0, 'names' => []],
                'stashes' => ['count' => 0, 'names' => []], 'commits' => ['count' => 0, 'branch_count' => 0, 'branches' => []]];
            app(TaskCancelIntent::class)->start($group->refresh(), 'test', null, discard: true);
        } else {
            $group->update(['status' => TaskGroupStatus::Settling]);
            $group->tasks()->update(['status' => TaskStatus::Completed]);
            app(CompleteTaskGroupAction::class)->execute($group->refresh());
        }

        // docs/reference/development-containers.md#copies-of-the-volume: the copy's own volume, never the default's.
        $destroy = Operation::query()->where('kind', 'instance.destroy')->sole();
        expect($destroy->target_id)->toBe($copy->id)
            ->and($destroy->input['container_destroy'])->toMatchArray(['name' => 'instance-'.$copy->id, 'inner_volume' => 'inner-'.$copy->id]);
    })->with(['cancel', 'complete']);

    it('admits the copy\'s destroy with force, completes the group and lets go of the copy when the destroy succeeds', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $group->update(['status' => TaskGroupStatus::Settling]);
        $group->tasks()->update(['status' => TaskStatus::Completed]);

        $completed = app(CompleteTaskGroupAction::class)->execute($group->refresh());

        $destroy = Operation::query()->where('kind', 'instance.destroy')->sole();
        expect($destroy->target_id)->toBe($copy->id)->and($destroy->input['force'])->toBeTrue()
            ->and($completed->status)->toBe(TaskGroupStatus::Completed)
            ->and($completed->taskable_id)->toBe($copy->id)
            ->and($completed->assistance_reason)->toBeNull();
        // A repeated complete reuses the open destroy.
        app(CompleteTaskGroupAction::class)->execute($group->refresh());
        expect(Operation::query()->where('kind', 'instance.destroy')->count())->toBe(1);

        destroySucceeded($destroy);

        expect(Instance::query()->find($copy->id))->toBeNull()
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Completed)
            ->and($group->taskable_id)->toBeNull();
    });

    it('completes a copy group over HTTP: admits the copy\'s destroy, and the complete after it succeeded finishes the removal and ends its request', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $group->update(['status' => TaskGroupStatus::Settling]);
        $group->tasks()->update(['status' => TaskStatus::Completed]);
        // An earlier complete could not remove the copy and asked for assistance.
        TaskAssistance::apply($group->refresh(), AssistanceKind::RemovalFailure, null, RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The destroy failed.', replaceFailure: true);
        $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
            'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);

        $this->withHeader('Idempotency-Key', 'complete-1')->postJson("/api/v1/tasks/{$group->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

        $destroy = Operation::query()->where('kind', 'instance.destroy')->sole();
        expect($destroy->target_id)->toBe($copy->id)->and($destroy->input['force'])->toBeTrue()
            ->and($group->refresh()->taskable_id)->toBe($copy->id)
            ->and($group->assistance_kind)->toBe(AssistanceKind::RemovalFailure);

        destroySucceeded($destroy);
        expect(Instance::query()->find($copy->id))->toBeNull()->and($group->refresh()->taskable_id)->toBeNull();

        $this->withHeader('Idempotency-Key', 'complete-2')->postJson("/api/v1/tasks/{$group->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

        expect($group->refresh()->assistance_kind)->toBeNull()->and($group->assistance_reason)->toBeNull()
            ->and(Operation::query()->where('kind', 'instance.destroy')->count())->toBe(1);
    });

    it('keeps the copy of a completed group when its destroy fails, and admits a new destroy on the next complete', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $group->update(['status' => TaskGroupStatus::Settling]);
        $group->tasks()->update(['status' => TaskStatus::Completed]);
        app(CompleteTaskGroupAction::class)->execute($group->refresh());
        Operation::query()->where('kind', 'instance.destroy')->sole()->forceFill(['status' => 'failed', 'finished_at' => now(), 'lock_key' => null])->save();

        app(CompleteTaskGroupAction::class)->execute($group->refresh());

        expect(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->count())->toBe(2)
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Completed)
            ->and($group->taskable_id)->toBe($copy->id);
    });
});

describe('a stop of a subtask in a copy', function (): void {
    it('refuses to stop a subtask whose running check task:check admitted: such a check records no process identity', function (): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $subtask = $group->tasks()->firstOrFail();
        copyTurnEnded(copyTurn($group), 'succeeded', copyTurnDone('mcp:1'));
        app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null);
        $check = $subtask->checks()->sole();
        expect($check->status)->toBe(TaskCheckStatus::Running)->and($check->pid)->toBe(0);

        $refusal = null;
        try {
            app(StopTaskSubtaskAction::class)->execute($group->refresh(), $subtask->refresh());
        } catch (ResourceOperationException $exception) {
            $refusal = $exception;
        }

        expect($refusal)->not->toBeNull()->errorCode->toBe('tasks.subtask_interrupt_failed')->status->toBe(502)
            ->and($refusal->getMessage())->toBe('The subtask remains running because its implementer or check could not be stopped: The running check has no recorded process identity; its start may have been interrupted.')
            ->and($check->refresh()->status)->toBe(TaskCheckStatus::Running)
            ->and($subtask->refresh()->status)->toBe(TaskStatus::Running);
    });

    it('sends no interrupt to a t3 thread, which has none, and does not fail; the subtask stays as it was', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $subtask = $group->tasks()->firstOrFail();
        $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'node_id' => $copy->node_id, 'driver' => 't3',
            'runtime_key' => 'instance:'.$copy->id, 'external_id' => (string) Str::uuid(), 'role' => 'implementer', 'model' => 'claude-opus-5-5', 'effort' => null]);
        $subtask->update(['status' => TaskStatus::Running, 'implementer_agent_thread_id' => $thread->id]);

        app(StopTaskSubtaskAction::class)->execute($group->refresh(), $subtask->refresh());

        expect($subtask->refresh()->status)->toBe(TaskStatus::Running);
    });
});

describe('a baseline check of a copy group', function (): void {
    it('records no subtask start at admission while no implementer turn ran; the check\'s result records it', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $copy->runtime->update(['power_state' => InstanceRuntimePowerState::Running]);
        $subtask = $group->tasks()->firstOrFail();

        $check = app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Baseline), 'test', null);

        expect($check->kind)->toBe('tasks.check')
            ->and($subtask->refresh()->subtask_start_commit)->toBeNull()
            ->and($subtask->checks()->sole()->only(['pid', 'process_started', 'head_before']))->toBe(['pid' => 0, 'process_started' => '', 'head_before' => '']);
    });
});

/** One turn of the group's current subtask, as `task:turn` sends it. */
function copyTurn(Task $group, array $change = []): Operation
{
    // A finished create leaves the copy's container running.
    Instance::query()->find($group->refresh()->taskable_id)?->runtime?->update(['power_state' => InstanceRuntimePowerState::Running]);
    $subtask = $group->tasks()->firstOrFail();

    return app(StartTaskTurnAction::class)->execute($group->refresh(), TaskTurnData::from([
        'subtask' => $subtask->id, 'role' => 'implementer', 'prompt' => 'Run Doctor and commit a note.', ...$change,
    ]), 'test', null);
}

/** The copy turn's run ended with this shaped turn, as `@nckrtl/task-copy-turn` writes it. */
function copyTurnEnded(Operation $turn, string $status, array $shaped): Operation
{
    $turn = runningOperation($turn);

    return app(TaskTurnProjection::class)->apply($turn, ['status' => $status, 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['turn' => $shaped]]);
}

/** The earlier turns that TURN settles, each by its Operation, title and branch alone. */
function copyTurnSettle(Operation $turn): array
{
    return array_map(static fn (array $earlier): array => Arr::only($earlier, ['operation_id', 'title', 'branch']), $turn->input['turn']['settle']);
}

/** The shaped turn of a completed run in T3 thread THREAD. */
function copyTurnDone(string $thread): array
{
    return ['state' => 'done', 'error' => null, 'ended' => true, 't3_thread_id' => $thread, 'run_id' => 'run:1', 'launched' => true, 'provider_instance' => 'claudeMedium',
        'model' => 'claude-opus-5-5', 'questions' => [], 'transcript' => []];
}

/** The shaped turn of a run RUN in T3 thread THREAD that asked one question, which RUN owns, and whose run Orbit stopped. */
function copyTurnAsked(string $thread, string $run = 'run:1'): array
{
    return [...copyTurnDone($thread), 'run_id' => $run, 'state' => 'asking_for_input', 'questions' => [['request_id' => 'request:1', 'run_id' => $run, 'id' => 'Which color?', 'header' => 'Color',
        'question' => 'Which color?', 'options' => [['label' => 'Red', 'description' => 'Choose red', 'value' => null], ['label' => 'Blue', 'description' => 'Choose blue', 'value' => null]],
        'multi_select' => false, 'allow_custom_answer' => null, 'initial_answer' => null, 'required' => null]]];
}

/** A passed handoff check of the group's subtask in its copy, with the check Operation that records it: the reviewer's candidate. */
function copyCandidate(Task $group): TaskCheck
{
    $subtask = $group->tasks()->firstOrFail();
    $head = str_repeat('d', 40);
    $check = TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'pid' => 1, 'process_started' => 'x',
        'head_before' => str_repeat('1', 40), 'tree_before' => str_repeat('2', 40), 'head_after' => str_repeat('3', 40), 'tree_after' => str_repeat('4', 40),
        'commit_sha' => $head, 'exit_code' => 0, 'output' => '', 'started_at' => now(), 'finished_at' => now()]);
    Operation::query()->create(['kind' => OperationKinds::TasksCheck, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'input' => ['subtask' => $subtask->id, 'phase' => 'handoff', 'check_id' => $check->id], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1,
        'result' => ['outcome' => 'passed', 'check_id' => $check->id, 'commit' => $head, 'head' => $check->head_after, 'tree' => $check->tree_after, 'ci' => null]]);

    return $check;
}

describe('a turn of a group that owns a copy', function (): void {
    it('runs a t3 Project\'s copy on the Conn Server through a completed implementer and reviewer turn, while its dev clone runs T3', function (): void {
        Storage::fake('local');
        $source = copySource();
        $group = copyGroup($source->project);
        $copy = finishedCopy($group, startCopy($group));

        $implementer = copyTurn($group);
        $implemented = copyTurnEnded($implementer, 'succeeded', copyTurnDone('mcp:1'));
        $reviewer = copyTurn($group, ['role' => 'reviewer', 'check' => copyCandidate($group)->id]);
        $reviewed = copyTurnEnded($reviewer, 'succeeded', copyTurnDone('mcp:2'));
        // A clone of the same Project that is no task copy runs the Project's server.
        $clone = containerCreate($source->project, Node::query()->findOrFail($source->node_id), 'feature');

        expect($source->project->refresh()->container_server)->toBe(ContainerServer::T3)
            ->and($copy->runtime->container_server)->toBe(ContainerServer::Conn)
            ->and([$implementer->input['turn']['t3'], $reviewer->input['turn']['t3']])->toBe(array_fill(0, 2, '/opt/orbit-agent/current/agent/bin/conn-server'))
            ->and([$implementer->input['role'], $reviewer->input['role']])->toBe(['implementer', 'reviewer'])
            ->and([$implemented->status, $reviewed->status])->toBe([OperationStatus::Succeeded, OperationStatus::Succeeded])
            ->and([$implemented->result['state'], $reviewed->result['state']])->toBe(['done', 'done'])
            ->and(AgentThread::query()->where('task_group_id', $group->id)->pluck('state')->all())->toBe([AgentThreadState::Done, AgentThreadState::Done])
            ->and(InstanceRuntime::query()->where('instance_id', $clone->target_id)->firstOrFail()->container_server)->toBe(ContainerServer::T3);
    });

    it('refuses a task copy before any record when the recipe pins no Conn Server', function (): void {
        $source = copySource();
        $group = copyGroup($source->project);
        containerServerConnUnpinned();
        $before = copyRows();

        expect(copyRefusal(fn () => startCopy($group)))->toBe('project.container_server_unavailable 409')
            ->and(copyRows())->toBe($before);
    });

    it('admits one tasks.copy_turn on the copy\'s Node with the composed prompt and the copy\'s model, and names no credential', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $this->travelTo(CarbonImmutable::parse('2026-11-16T10:00:00.5Z'));

        $turn = copyTurn($group);

        $thread = AgentThread::query()->where('task_group_id', $group->id)->sole();
        expect($turn->kind)->toBe('tasks.copy_turn')
            ->and($turn->node_id)->toBe($copy->node_id)
            ->and($turn->input['runtime'])->toBe(['project' => InstancePlacement::NestedProject, 'name' => $copy->runtime->container_name])
            ->and($turn->input['branch'])->toBe('task-'.$group->id)
            ->and(Arr::except($turn->input['turn'], ['prompt', 'message_id']))->toBe(['t3' => '/opt/orbit-agent/current/agent/bin/conn-server', 'node' => '/opt/orbit-runtime/current/bin/node',
                'thread' => 'new', 'provider' => 'claudeMedium', 'model' => 'claude-opus-5-5', 'options' => [], 'candidate' => null, 'settle' => [],
                // Capture sets the new thread's ID and marks it admitted: T3 does not hold it yet.
                't3_thread_id' => $thread->external_id, 'admission' => 'admitted', 'answered' => [], 'answered_run' => null, 'seconds' => 3600,
                'title' => 'Orbit task '.$group->id.' thread '.$thread->id])
            // The implementer gets its own fixed text: the briefs, its rules, the caller's prompt and the end of the turn's bound.
            ->and(Str::isUuid($thread->external_id))->toBeTrue()->and($thread->external_id_admission)->toBe('admitted')
            ->and(Str::isUuid($turn->input['turn']['message_id']))->toBeTrue()
            ->and($turn->input['turn']['prompt'])->toBe(TaskTurnPrompt::implementer($group->tasks()->firstOrFail(), $group->project->taskCheckCommand(),
                'Run Doctor and commit a note.', CarbonImmutable::parse('2026-11-16T10:00:00Z')))
            ->and($turn->input['turn']['prompt'])->toStartWith('You implement one subtask of an Orbit task in its task copy.')
            ->and($turn->input['turn']['prompt'])->toContain("<task_brief title=\"Group\">\nBrief\n</task_brief>\n\n<subtask_brief title=\"Step\">\nDo it\n</subtask_brief>")
            ->and($turn->input['turn']['prompt'])->toEndWith("<turn>\nRun Doctor and commit a note.\n</turn>\n\nOrbit ends this turn at 2026-11-16T11:00:00Z. Commit your work before then.")
            ->and($thread->only(['driver', 'role', 'model', 'effort', 'provider_instance', 'node_id']))->toBe(['driver' => 't3', 'role' => 'implementer', 'model' => 'claude-opus-5-5',
                'effort' => 'medium', 'provider_instance' => 'claudeMedium', 'node_id' => $copy->node_id])
            ->and($group->tasks()->firstOrFail()->implementer_agent_thread_id)->toBe($thread->id)
            // T3 issues its own session inside the copy: no vault key, no token. The prompt, pinned above, is text: its copy rules name both.
            ->and(app(DirectOperation::class)->definition('tasks.copy_turn'))->not->toHaveKey('execution_copies')
            ->and(json_encode(Arr::except($turn->input, 'turn.prompt'), JSON_THROW_ON_ERROR))->not->toContain('vault')->not->toContain('token')->not->toContain('session');
    });

    it('moves the current subtask from todo to running at the turn\'s admission, and never completes it', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $subtask = $group->tasks()->firstOrFail();
        expect($subtask->status)->toBe(TaskStatus::Todo);

        expect($subtask->started_at)->toBeNull();

        $implementer = copyTurn($group);
        // The first turn stamps the subtask's start, which its duration counts from (docs/reference/tasks.md#metrics).
        $started = $subtask->refresh()->started_at?->toIso8601String();
        expect($subtask->status)->toBe(TaskStatus::Running)->and($started)->not->toBeNull();
        copyTurnEnded($implementer, 'succeeded', copyTurnDone('mcp:1'));
        $this->travel(5)->minutes();
        $review = copyTurn($group, ['role' => 'reviewer', 'check' => copyCandidate($group)->id]);
        copyTurnEnded($review, 'succeeded', copyTurnDone('mcp:2'));

        // A done reviewer turn leaves the subtask running: only the caller completes it (docs/reference/tasks.md#subtask-status-in-a-task-copy).
        expect($subtask->refresh()->status)->toBe(TaskStatus::Running)
            ->and($subtask->started_at?->toIso8601String())->toBe($started)
            ->and(app(TaskGroupDone::class)->check($group->refresh()))->toBeNull();
    });

    it('keeps a completed subtask\'s duration from its first turn to its completion, and a reopen counts again (docs/reference/tasks.md#lifecycle-times)', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $subtask = $group->tasks()->firstOrFail();
        copyTurnEnded(copyTurn($group), 'succeeded', copyTurnDone('mcp:1'));
        copyTurnEnded(copyTurn($group, ['role' => 'reviewer', 'check' => copyCandidate($group)->id]), 'succeeded', copyTurnDone('mcp:2'));
        $move = fn (TaskStatus $to): Task => app(UpdateTaskAction::class)->execute($group->refresh(), $subtask->refresh(), new UpdateTaskData(null, null, null, status: $to));
        $duration = fn (): ?int => app(TaskGroupMetricsRefresher::class)->refresh(Task::topLevel()->findOrFail($group->id))->tasks->firstOrFail()->duration_ms;

        $this->travel(7)->minutes();
        $move(TaskStatus::Completed);
        $settled = $subtask->refresh()->settled_at?->toIso8601String();
        $done = $duration();
        $this->travel(30)->minutes();

        expect($settled)->not->toBeNull()
            ->and($done)->toBeGreaterThanOrEqual(7 * 60 * 1000)
            ->and($duration())->toBe($done);

        // A reopen clears the settle: the duration runs again until the subtask ends again.
        $move(TaskStatus::Running);
        expect($subtask->refresh()->settled_at)->toBeNull()
            ->and($duration())->toBeGreaterThan($done);
    });

    it('admits an implementer turn on the reopened last subtask, which continues its thread (docs/reference/tasks.md#reopen-the-last-subtask)', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $subtask = $group->tasks()->firstOrFail();
        $first = copyTurn($group);
        copyTurnEnded($first, 'succeeded', copyTurnDone('mcp:1'));
        copyTurnEnded(copyTurn($group, ['role' => 'reviewer', 'check' => copyCandidate($group)->id]), 'succeeded', copyTurnDone('mcp:2'));
        $move = fn (TaskStatus $to): Task => app(UpdateTaskAction::class)->execute($group->refresh(), $subtask->refresh(), new UpdateTaskData(null, null, null, status: $to));
        $move(TaskStatus::Completed);
        expect(copyRefusal(fn () => copyTurn($group)))->toBe('tasks.subtask_not_current 409');

        expect($move(TaskStatus::Running)->status)->toBe(TaskStatus::Running);
        $fix = copyTurn($group, ['prompt' => 'Fix the CI failure.']);

        expect($fix->input['subtask'])->toBe($subtask->id)
            ->and($fix->input['thread_id'])->toBe($first->input['thread_id'])
            ->and($fix->input['turn']['thread'])->toBe('continue')
            ->and($subtask->refresh()->status)->toBe(TaskStatus::Running);
    });

    it('leaves the subtask todo when the turn is refused', function (): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));

        expect(copyRefusal(fn () => copyTurn($group, ['role' => 'reviewer', 'check' => 1])))->toBe('tasks.commit_not_checked 422')
            ->and($group->tasks()->firstOrFail()->status)->toBe(TaskStatus::Todo);
    });

    it('admits a tasks.copy_turn in the copy of a plain container Project, in that copy\'s own Incus project', function (): void {
        $group = copyGroup(copySource('app', nested: false)->project);
        $copy = finishedCopy($group, startCopy($group));

        $turn = copyTurn($group);

        expect($turn->kind)->toBe('tasks.copy_turn')
            ->and($turn->input['runtime'])->toBe(['project' => InstanceHostIntent::Project, 'name' => $copy->runtime->container_name])
            ->and($turn->input['branch'])->toBe('task-'.$group->id);
    });

    it('takes the agent of a new thread from the Gateway\'s settings of its role, through the provider instance of its provider and effort', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        config(['orbit.tasks.copy_agents.implementer.model' => 'claude-sonnet-5-5', 'orbit.tasks.copy_agents.reviewer' => ['provider' => 'claude', 'model' => 'claude-haiku-5-5', 'effort' => 'max']]);

        $implementer = copyTurn($group);
        copyTurnEnded($implementer, 'succeeded', copyTurnDone('mcp:1'));
        $reviewer = copyTurn($group, ['role' => 'reviewer', 'check' => copyCandidate($group)->id]);

        // The shipped implementer effort is medium.
        expect($implementer->input['turn'])->toMatchArray(['thread' => 'new', 'provider' => 'claudeMedium', 'model' => 'claude-sonnet-5-5'])
            ->and($reviewer->input['turn'])->toMatchArray(['thread' => 'new', 'provider' => 'claudeMax', 'model' => 'claude-haiku-5-5'])
            ->and(AgentThread::query()->findOrFail($implementer->input['thread_id'])->only(['model', 'effort', 'provider_instance']))
            ->toBe(['model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'provider_instance' => 'claudeMedium'])
            ->and(AgentThread::query()->findOrFail($reviewer->input['thread_id'])->only(['model', 'effort', 'provider_instance']))
            ->toBe(['model' => 'claude-haiku-5-5', 'effort' => 'max', 'provider_instance' => 'claudeMax']);
    });

    it('ships Claude Opus 5.5 at medium effort for the implementer and at high effort for the reviewer, and reads no task VM model', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        // An open group of an earlier release holds the task VM's Pi model in its VM columns.
        $group->update(['implementer_model' => TaskAgentDefaults::ImplementerModel, 'reviewer_model' => TaskAgentDefaults::ReviewerModel]);

        $implementer = copyTurn($group);
        copyTurnEnded($implementer, 'succeeded', copyTurnDone('mcp:1'));
        $reviewer = copyTurn($group, ['role' => 'reviewer', 'check' => copyCandidate($group)->id]);

        expect($implementer->input['turn'])->toMatchArray(['provider' => 'claudeMedium', 'model' => 'claude-opus-5-5'])
            ->and($reviewer->input['turn'])->toMatchArray(['provider' => 'claudeHigh', 'model' => 'claude-opus-5-5']);
    });

    it('reads an empty Gateway agent key as its shipped default, and a set one as itself', function (): void {
        $keys = ['ORBIT_TASKS_COPY_IMPLEMENTER_PROVIDER' => '', 'ORBIT_TASKS_COPY_IMPLEMENTER_MODEL' => '', 'ORBIT_TASKS_COPY_IMPLEMENTER_EFFORT' => '',
            'ORBIT_TASKS_COPY_REVIEWER_PROVIDER' => '', 'ORBIT_TASKS_COPY_REVIEWER_MODEL' => 'claude-sonnet-5-5', 'ORBIT_TASKS_COPY_REVIEWER_EFFORT' => ''];
        // The keys' earlier values, absent ones as null, so the test leaves the environment as it found it.
        $earlier = array_map(static fn (string $key): array => [$_SERVER[$key] ?? null, $_ENV[$key] ?? null], array_combine(array_keys($keys), array_keys($keys)));
        foreach ($keys as $key => $value) {
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
        try {
            $agents = (require config_path('orbit.php'))['tasks']['copy_agents'];
        } finally {
            foreach ($earlier as $key => [$server, $env]) {
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
            }
        }

        expect($agents)->toBe([
            'implementer' => ['provider' => 'claude', 'model' => 'claude-opus-5-5', 'effort' => 'medium'],
            'reviewer' => ['provider' => 'claude', 'model' => 'claude-sonnet-5-5', 'effort' => 'high'],
        ]);
    });

    it('resolves each value of a new thread on its own: the group\'s, else the Project\'s, else the Gateway\'s', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $group->project->update(['task_implementer_model' => 'claude-sonnet-5-5', 'task_implementer_effort' => 'low', 'task_reviewer_effort' => 'xhigh']);
        $group->update(['implementer_agent_effort' => 'max', 'reviewer_agent_model' => 'claude-fable-5-2']);

        $implementer = copyTurn($group);
        copyTurnEnded($implementer, 'succeeded', copyTurnDone('mcp:1'));
        $reviewer = copyTurn($group, ['role' => 'reviewer', 'check' => copyCandidate($group)->id]);

        expect(TaskAgent::resolve($group->refresh(), TaskThreadRole::Implementer))->toBe([
            'provider' => ['value' => 'claude', 'source' => 'gateway'],
            'model' => ['value' => 'claude-sonnet-5-5', 'source' => 'project'],
            'effort' => ['value' => 'max', 'source' => 'group'],
        ])
            ->and($implementer->input['turn'])->toMatchArray(['provider' => 'claudeMax', 'model' => 'claude-sonnet-5-5'])
            ->and($reviewer->input['turn'])->toMatchArray(['provider' => 'claudeXhigh', 'model' => 'claude-fable-5-2'])
            ->and(AgentThread::query()->findOrFail($reviewer->input['thread_id'])->only(['model', 'effort', 'provider_instance']))
            ->toBe(['model' => 'claude-fable-5-2', 'effort' => 'xhigh', 'provider_instance' => 'claudeXhigh']);
    });

    it('refuses a new thread whose provider and effort the copy\'s T3 has no instance for, with what it offers, before anything is recorded', function (Closure $set): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $set($group);
        $before = Operation::query()->count();

        try {
            copyTurn($group);
            $this->fail('The turn was admitted.');
        } catch (ResourceOperationException $error) {
            expect($error->errorCode.' '.$error->status)->toBe('tasks.copy_turn_unoffered 422')
                ->and($error->details['offered'])->toBe(['claude' => ['low', 'medium', 'high', 'xhigh', 'max'], 'codex' => ['low', 'medium', 'high', 'xhigh', 'max', 'ultra'],
                    'antigravity' => ['high']]);
        }
        expect(AgentThread::query()->count())->toBe(0)->and(Operation::query()->count())->toBe($before);
    })->with([
        'a Gateway effort Claude Code has no instance for' => [static fn (Task $group) => config(['orbit.tasks.copy_agents.implementer.effort' => 'minimal'])],
        'a Gateway provider the copy has no instance of' => [static fn (Task $group) => config(['orbit.tasks.copy_agents.implementer.provider' => 'pi'])],
        'a Codex effort Claude Code has no instance for' => [static fn (Task $group) => $group->update(['implementer_agent_effort' => 'ultra'])],
        'a Codex effort the group names with Claude' => [static fn (Task $group) => $group->update(['implementer_agent_provider' => 'claude', 'implementer_agent_effort' => 'ultra'])],
        'a stored group effort the copy no longer offers' => [static fn (Task $group) => $group->update(['implementer_agent_effort' => 'minimal'])],
        'a stored Project provider the copy no longer offers' => [static fn (Task $group) => $group->project->update(['task_implementer_provider' => 'pi'])],
    ]);

    it('starts an Antigravity implementer on the Conn Server\'s antigravity instance with gemini-3.8-flash, never the Project\'s Claude model', function (): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $group->project->update(['task_implementer_model' => 'claude-sonnet-5-5', 'task_implementer_effort' => 'low']);
        $group->update(['implementer_agent_provider' => 'antigravity']);

        $turn = copyTurn($group);

        expect(TaskAgent::resolve($group->refresh(), TaskThreadRole::Implementer))->toBe([
            'provider' => ['value' => 'antigravity', 'source' => 'group'],
            'model' => ['value' => 'gemini-3.8-flash', 'source' => 'provider'],
            'effort' => ['value' => 'high', 'source' => 'provider'],
        ])
            ->and($turn->input['turn'])->toMatchArray(['t3' => '/opt/orbit-agent/current/agent/bin/conn-server', 'thread' => 'new', 'provider' => 'antigravity',
                'model' => 'gemini-3.8-flash', 'options' => []])
            ->and(AgentThread::query()->findOrFail($turn->input['thread_id'])->only(['model', 'effort', 'provider_instance']))
            ->toBe(['model' => 'gemini-3.8-flash', 'effort' => 'high', 'provider_instance' => 'antigravity']);
    });

    it('offers Antigravity only in a copy whose create settings hold its instance; an older copy refuses it and can change back to Claude', function (string $role): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $runtime = $copy->runtime()->firstOrFail();
        $clone = Operation::query()->where('kind', 'instance.create')->where('target_id', $copy->id)->sole();
        expect(array_keys(InstanceContainer::offered($runtime)))->toBe(['claude', 'codex', 'antigravity']);
        copyApiCaller($this);
        $review = [];
        if ($role === 'reviewer') {
            copyTurnEnded(copyTurn($group), 'succeeded', copyTurnDone('mcp:1'));
            $review = ['role' => 'reviewer', 'check' => copyCandidate($group)->id];
        }
        $turn = fn () => copyTurn($group, $review);
        // A copy made before Antigravity: the settings its create wrote hold Claude and Codex only.
        $input = $clone->input;
        foreach ($input['direct_provisioning']['container']['clone']['files'] as $index => $file) {
            if ($file['path'] === InstanceContainer::T3Settings) {
                unset($input['direct_provisioning']['container']['clone']['files'][$index]['json']['providerInstances']['antigravity']);
            }
        }
        $clone->forceFill(['input' => $input])->save();
        $group->update([$role.'_agent_provider' => 'antigravity']);
        $threads = AgentThread::query()->count();
        $before = Operation::query()->count();

        try {
            $turn();
            $this->fail('The turn was admitted.');
        } catch (ResourceOperationException $error) {
            expect($error->errorCode.' '.$error->status)->toBe('tasks.copy_turn_unoffered 422')
                ->and($error->details['offered'])->toBe(['claude' => ['low', 'medium', 'high', 'xhigh', 'max'], 'codex' => ['low', 'medium', 'high', 'xhigh', 'max', 'ultra']]);
        }
        expect(AgentThread::query()->count())->toBe($threads)->and(Operation::query()->count())->toBe($before);

        // No thread was made, so the role is not locked: back on Claude it runs.
        $this->patchJson('/api/v1/tasks/'.$group->id, [$role.'_provider' => 'claude'])->assertOk();
        expect($turn()->input['turn'])->toMatchArray(['thread' => 'new', 'provider' => $role === 'reviewer' ? 'claudeHigh' : 'claudeMedium']);
    })->with(['implementer', 'reviewer']);

    it('offers Claude and Codex in a copy whose create input holds no settings, as every copy did before Antigravity', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        Operation::query()->where('kind', 'instance.create')->where('target_id', $copy->id)->sole()->forceFill(['input' => []])->save();

        expect(InstanceContainer::offered($copy->runtime()->firstOrFail()))
            ->toBe(['claude' => ['low', 'medium', 'high', 'xhigh', 'max'], 'codex' => ['low', 'medium', 'high', 'xhigh', 'max', 'ultra']])
            ->and(copyTurn($group)->input['turn'])->toMatchArray(['provider' => 'claudeMedium']);
    });

    it('keeps Claude\'s aliases for Claude and never sends one to Antigravity', function (string $role): void {
        $group = copyGroup(copySource()->project);
        $roleCase = TaskThreadRole::from($role);
        // A Project that names no provider, with Claude's alias: it stays with Claude and never reaches Antigravity.
        $group->project->update(['task_'.$role.'_model' => 'opus']);
        $group->update([$role.'_agent_provider' => 'antigravity']);
        expect(TaskAgent::resolve($group, $roleCase)['model'])->toBe(['value' => 'gemini-3.8-flash', 'source' => 'provider']);
        $group->update([$role.'_agent_provider' => 'claude']);
        expect(TaskAgent::resolve($group, $roleCase)['model'])->toBe(['value' => 'opus', 'source' => 'project']);

        // The Gateway names Antigravity with a Claude alias left in its model: Gemini runs.
        $group->update([$role.'_agent_provider' => null]);
        $group->project->update(['task_'.$role.'_model' => null]);
        config(['orbit.tasks.copy_agents.'.$role => ['provider' => 'antigravity', 'model' => 'sonnet', 'effort' => 'medium']]);
        expect(TaskAgent::resolve($group, $roleCase))->toBe([
            'provider' => ['value' => 'antigravity', 'source' => 'gateway'],
            'model' => ['value' => 'gemini-3.8-flash', 'source' => 'provider'],
            'effort' => ['value' => 'high', 'source' => 'provider'],
        ]);

        // Even a group that names Antigravity and a Claude alias together runs Gemini: agy runs Gemini models only.
        $group->update([$role.'_agent_provider' => 'antigravity', $role.'_agent_model' => 'opus']);
        expect(TaskAgent::resolve($group, $roleCase)['model'])->toBe(['value' => 'gemini-3.8-flash', 'source' => 'provider']);
    })->with(['implementer', 'reviewer']);

    it('resolves Claude and Codex exactly by the group, Project and Gateway chain, whatever provider each level names', function (): void {
        $group = copyGroup(copySource()->project);
        $values = ['provider' => [null, 'claude', 'codex'], 'model' => [null, 'claude-sonnet-5-5', 'gpt-6.2-astra'], 'effort' => [null, 'low', 'xhigh', 'ultra']];
        $gateways = [['provider' => 'claude', 'model' => 'claude-opus-5-5', 'effort' => 'medium'], ['provider' => 'codex', 'model' => 'gpt-6.1-sol', 'effort' => 'high']];
        $cases = 0;
        foreach ($gateways as $gateway) {
            config(['orbit.tasks.copy_agents.implementer' => $gateway]);
            foreach ($values['provider'] as $groupProvider) {
                foreach ($values['provider'] as $projectProvider) {
                    foreach ($values['model'] as $groupModel) {
                        foreach ($values['model'] as $projectModel) {
                            foreach ([[null, 'low'], ['ultra', 'xhigh'], [null, null]] as [$groupEffort, $projectEffort]) {
                                $group->project->forceFill(['task_implementer_provider' => $projectProvider, 'task_implementer_model' => $projectModel,
                                    'task_implementer_effort' => $projectEffort])->save();
                                $group->forceFill(['implementer_agent_provider' => $groupProvider, 'implementer_agent_model' => $groupModel,
                                    'implementer_agent_effort' => $groupEffort])->save();
                                // origin/main's rule: each value on its own, the group's, else the Project's, else the Gateway's.
                                $main = static fn (?string $grouped, ?string $projected, string $gatewayValue): array => match (true) {
                                    $grouped !== null => ['value' => $grouped, 'source' => 'group'],
                                    $projected !== null => ['value' => $projected, 'source' => 'project'],
                                    default => ['value' => $gatewayValue, 'source' => 'gateway'],
                                };
                                expect(TaskAgent::resolve($group->refresh(), TaskThreadRole::Implementer))->toBe([
                                    'provider' => $main($groupProvider, $projectProvider, $gateway['provider']),
                                    'model' => $main($groupModel, $projectModel, $gateway['model']),
                                    'effort' => $main($groupEffort, $projectEffort, $gateway['effort']),
                                ]);
                                $cases++;
                            }
                        }
                    }
                }
            }
        }
        expect($cases)->toBe(2 * 3 * 3 * 3 * 3 * 3);
    });

    it('replaces only a model that is no Gemini model and an effort Antigravity does not offer, wherever Antigravity comes from', function (): void {
        $group = copyGroup(copySource()->project);
        $group->project->update(['task_reviewer_provider' => 'codex', 'task_reviewer_model' => 'gpt-6.2-sol', 'task_implementer_provider' => 'antigravity']);

        expect(TaskAgent::resolve($group, TaskThreadRole::Reviewer))->toBe([
            'provider' => ['value' => 'codex', 'source' => 'project'],
            'model' => ['value' => 'gpt-6.2-sol', 'source' => 'project'],
            'effort' => ['value' => 'high', 'source' => 'gateway'],
        ])
            ->and(TaskAgent::resolve($group, TaskThreadRole::Implementer))->toBe([
                'provider' => ['value' => 'antigravity', 'source' => 'project'],
                'model' => ['value' => 'gemini-3.8-flash', 'source' => 'provider'],
                'effort' => ['value' => 'high', 'source' => 'provider'],
            ]);

        // A Gemini model and the high effort stand, from any level.
        $group->update(['implementer_agent_model' => 'gemini-3.7-flash', 'reviewer_agent_provider' => 'antigravity', 'reviewer_agent_effort' => 'high']);
        config(['orbit.tasks.copy_agents.reviewer' => ['provider' => 'antigravity', 'model' => 'gemini-4-argon', 'effort' => 'high']]);
        expect(TaskAgent::resolve($group, TaskThreadRole::Implementer)['model'])->toBe(['value' => 'gemini-3.7-flash', 'source' => 'group'])
            ->and(TaskAgent::resolve($group, TaskThreadRole::Reviewer))->toBe([
                'provider' => ['value' => 'antigravity', 'source' => 'group'],
                // The Project's Codex model comes first in the chain and is no Gemini model: Antigravity's own replaces it.
                'model' => ['value' => 'gemini-3.8-flash', 'source' => 'provider'],
                'effort' => ['value' => 'high', 'source' => 'group'],
            ]);
    });

    it('starts Gemini at high after a provider-only switch to Antigravity at the Project, the group or the Gateway', function (string $role): void {
        $group = copyGroup(copySource()->project);
        $runtime = finishedCopy($group, startCopy($group))->runtime()->firstOrFail();
        copyApiCaller($this);
        $roleCase = TaskThreadRole::from($role);
        $agent = static fn (): array => TaskAgent::forNewThread($group->refresh(), $roleCase, $runtime);
        $gemini = ['provider' => 'antigravity', 'instance' => 'antigravity', 'model' => 'gemini-3.8-flash', 'effort' => 'high', 'options' => []];
        $this->patchJson('/api/v1/projects/'.$group->project_id, ['task_'.$role.'_provider' => 'claude', 'task_'.$role.'_model' => 'claude-sonnet-5-5',
            'task_'.$role.'_effort' => 'medium'])->assertOk();

        // The Project switches its provider alone, at medium and at high: the Claude model stays behind, and so does medium.
        $this->patchJson('/api/v1/projects/'.$group->project_id, ['task_'.$role.'_provider' => 'antigravity'])->assertOk();
        expect($agent())->toBe($gemini);
        $this->patchJson('/api/v1/projects/'.$group->project_id, ['task_'.$role.'_effort' => 'high'])->assertOk();
        expect($agent())->toBe($gemini)
            ->and(TaskAgent::resolve($group, $roleCase)['effort'])->toBe(['value' => 'high', 'source' => 'project']);

        // The group's own Claude values, then a provider-only switch of the group.
        $this->patchJson('/api/v1/projects/'.$group->project_id, ['task_'.$role.'_provider' => null, 'task_'.$role.'_model' => null, 'task_'.$role.'_effort' => null])->assertOk();
        $this->patchJson('/api/v1/tasks/'.$group->id, [$role.'_provider' => 'claude', $role.'_model' => 'claude-sonnet-5-5', $role.'_effort' => 'xhigh'])->assertOk();
        $this->patchJson('/api/v1/tasks/'.$group->id, [$role.'_provider' => 'antigravity'])->assertOk();
        expect($agent())->toBe($gemini);

        // The Gateway's provider alone, with its shipped Claude model and effort.
        $this->patchJson('/api/v1/tasks/'.$group->id, [$role.'_provider' => null, $role.'_model' => null, $role.'_effort' => null])->assertOk();
        config(['orbit.tasks.copy_agents.'.$role.'.provider' => 'antigravity']);
        expect($agent())->toBe($gemini);
    })->with(['implementer', 'reviewer']);

    it('refuses a new thread whose Gateway model is no model name, and records nothing', function (): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        config(['orbit.tasks.copy_agents.implementer.model' => 'claudeAgent/claude-opus-5-5']);
        $before = Operation::query()->count();

        expect(copyRefusal(fn () => copyTurn($group)))->toBe('tasks.copy_turn_model_invalid 503')
            ->and(AgentThread::query()->count())->toBe(0)->and(Operation::query()->count())->toBe($before);
    });

    it('gives a task copy\'s T3 one Claude provider instance per effort, each with CLAUDE_CODE_EFFORT_LEVEL, and one Codex instance per effort', function (): void {
        $group = copyGroup(copySource()->project);

        $files = collect(ValidatedData::object(containerIntentOf(startCopy($group))['clone'])['files'])->keyBy('path');

        $instances = $files['/home/agent/.t3/userdata/settings.json']['json']['providerInstances'];
        expect(array_keys($instances))->toBe(['claudeLow', 'claudeMedium', 'claudeHigh', 'claudeXhigh', 'claudeMax',
            'codexLow', 'codexMedium', 'codexHigh', 'codexXhigh', 'codexMax', 'codexUltra', 'antigravity'])
            ->and($instances['claudeMedium'])->toBe(['driver' => 'claudeAgent', 'displayName' => 'Claude, medium effort',
                'environment' => [['name' => 'CLAUDE_CODE_EFFORT_LEVEL', 'value' => 'medium']]])
            // Codex takes its effort from the thread's model options, not from its instance (InstanceContainer::providerOptions).
            ->and($instances['codexHigh'])->toBe(['driver' => 'codex', 'displayName' => 'Codex, high effort'])
            ->and($files['/home/agent/.t3/userdata/settings.json']['json']['linkedEnvironmentsEnabled'])->toBeTrue();
    });

    it('continues the role\'s thread without a thread id: the same Orbit thread and T3 title, its own model and effort, and no new thread', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $first = copyTurn($group);
        copyTurnEnded($first, 'succeeded', copyTurnDone('mcp:66105c2c'));
        config(['orbit.tasks.copy_agents.implementer.model' => 'claude-sonnet-5-5', 'orbit.tasks.copy_agents.implementer.effort' => 'high']);
        $group->project->update(['task_implementer_model' => 'claude-haiku-5-5']);

        $next = copyTurn($group, ['prompt' => 'Fix the failing test.']);
        copyTurnEnded($next, 'succeeded', copyTurnDone('mcp:66105c2c'));
        // --thread=ID may name the thread the turn continues.
        $named = copyTurn($group, ['thread' => (string) $first->input['thread_id']]);

        expect($next->input['thread_id'])->toBe($first->input['thread_id'])
            ->and(Arr::except($next->input['turn'], ['prompt', 'message_id']))->toBe(['t3' => '/opt/orbit-agent/current/agent/bin/conn-server', 'node' => '/opt/orbit-runtime/current/bin/node',
                'thread' => 'continue', 'provider' => null, 'model' => 'claude-opus-5-5', 'options' => [], 'candidate' => null, 'settle' => [],
                // The CLI driver reported its own thread ID over the admitted one: its provenance is not proven.
                't3_thread_id' => 'mcp:66105c2c', 'admission' => 'overwritten', 'answered' => [], 'answered_run' => null, 'seconds' => 3600, 'title' => $first->input['turn']['title']])
            ->and($next->input['turn']['message_id'])->not->toBe($first->input['turn']['message_id'])
            ->and($next->input['turn']['prompt'])->toContain("<turn>\nFix the failing test.\n</turn>")
            ->and($named->input['thread_id'])->toBe($first->input['thread_id'])->and($named->input['turn']['thread'])->toBe('continue')
            ->and(AgentThread::query()->where('task_group_id', $group->id)->sole()->only(['model', 'effort', 'provider_instance']))
            ->toBe(['model' => 'claude-opus-5-5', 'effort' => 'medium', 'provider_instance' => 'claudeMedium'])
            ->and($group->tasks()->firstOrFail()->implementer_agent_thread_id)->toBe($first->input['thread_id']);
    });

    it('starts a new thread of the role on --thread=new, which later turns then continue', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $first = copyTurn($group);
        copyTurnEnded($first, 'succeeded', copyTurnDone('mcp:1'));

        $fresh = copyTurn($group, ['thread' => 'new']);
        copyTurnEnded($fresh, 'succeeded', copyTurnDone('mcp:2'));
        $next = copyTurn($group);

        expect($fresh->input['thread_id'])->not->toBe($first->input['thread_id'])->and($fresh->input['turn']['thread'])->toBe('new')
            ->and($next->input['thread_id'])->toBe($fresh->input['thread_id'])->and($next->input['turn']['thread'])->toBe('continue')
            ->and($group->tasks()->firstOrFail()->implementer_agent_thread_id)->toBe($fresh->input['thread_id']);
    });

    it('starts a Codex reviewer on its effort\'s instance with the effort as its reasoningEffort option, and the reviewer rule', function (): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        copyTurnEnded(copyTurn($group), 'succeeded', copyTurnDone('mcp:1'));
        $check = copyCandidate($group);
        $group->project->update(['task_reviewer_provider' => 'codex', 'task_reviewer_model' => 'gpt-6.1-sol', 'task_reviewer_effort' => 'high']);

        $review = copyTurn($group, ['role' => 'reviewer', 'check' => $check->id]);

        expect($review->input['turn'])->toMatchArray(['thread' => 'new', 'provider' => 'codexHigh', 'model' => 'gpt-6.1-sol',
            'options' => [['id' => 'reasoningEffort', 'value' => 'high']]])
            ->and($review->input['turn']['prompt'])->toContain("</review_rules>\n\n".TaskTurn::PrTextRule."\n\n<turn>\n")
            ->and(AgentThread::query()->findOrFail($review->input['thread_id'])->only(['model', 'effort', 'provider_instance']))
            ->toBe(['model' => 'gpt-6.1-sol', 'effort' => 'high', 'provider_instance' => 'codexHigh']);
    });

    it('starts a new thread when the role\'s recorded thread is not a T3 thread of this copy', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $earlier = AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 't3', 'runtime_key' => 'instance:'.($copy->id + 1000),
            'external_id' => 'mcp:old', 'role' => 'implementer']);
        $group->tasks()->firstOrFail()->update(['implementer_agent_thread_id' => $earlier->id]);

        $turn = copyTurn($group);

        expect($turn->input['thread_id'])->not->toBe($earlier->id)->and($turn->input['turn']['thread'])->toBe('new');
    });

    it('runs a reviewer turn on the checked candidate in its own thread, recorded on the group, and continues it', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $implementer = copyTurn($group);
        copyTurnEnded($implementer, 'succeeded', [...copyTurnDone('mcp:1'), 'transcript' => [['label' => 'assistant', 'text' => 'Handed off.']]]);
        $check = copyCandidate($group);

        $review = copyTurn($group, ['role' => 'reviewer', 'check' => $check->id, 'prompt' => 'Review the checked commit.']);
        copyTurnEnded($review, 'succeeded', copyTurnDone('mcp:2'));
        $again = copyTurn($group, ['role' => 'reviewer', 'check' => $check->id]);

        $thread = AgentThread::query()->findOrFail($review->input['thread_id']);
        expect($thread->id)->not->toBe($implementer->input['thread_id'])
            ->and($thread->only(['driver', 'role', 'model', 'effort', 'external_id']))->toBe(['driver' => 't3', 'role' => 'reviewer', 'model' => 'claude-opus-5-5',
                'effort' => 'high', 'external_id' => 'mcp:2'])
            ->and($review->input)->toMatchArray(['role' => 'reviewer', 'check' => $check->id, 'candidate' => $check->commit_sha])
            // The phase checks the candidate out for the reviewer, and the prompt names it.
            // A Claude thread carries no model options: its instance's CLAUDE_CODE_EFFORT_LEVEL sets its effort, as before.
            ->and($review->input['turn'])->toMatchArray(['thread' => 'new', 'provider' => 'claudeHigh', 'model' => 'claude-opus-5-5', 'options' => [],
                'candidate' => $check->commit_sha])
            // The reviewer gets its own fixed text: the first review of the check's commit, the implementer's handoff note, the review
            // rules (docs/reference/tasks.md#the-reviewer-rule) and the pull request text rule (docs/reference/tasks.md#the-reviewers-pull-request-text).
            ->and($review->input['turn']['prompt'])->toStartWith("You review one subtask of an Orbit task in its task copy.\n"
                ."This is the first review of this subtask: check {$check->id}, commit {$check->commit_sha}, against base ")
            ->and($review->input['turn']['prompt'])->toContain("<task_brief title=\"Group\">\nBrief\n</task_brief>\n\n<subtask_brief title=\"Step\">\nDo it\n</subtask_brief>\n\n"
                ."<handoff_note>\nHanded off.\n</handoff_note>\n")
            ->and($review->input['turn']['prompt'])->toContain("</review_rules>\n\n".TaskTurn::PrTextRule."\n\n<turn>\nReview the checked commit.\n</turn>\n\n")
            ->and($review->input['turn']['prompt'])->toContain('A finding blocks only if:')->not->toContain('Commit your work')
            ->and($implementer->input['turn']['prompt'])->not->toContain('<review_rules>')->not->toContain(TaskTurn::PrTextRule)
            // A later reviewer turn on the subtask is a re-review: its round, and the commit the finished review judged.
            ->and($again->input['turn']['prompt'])->toContain("This is review round 2 of this subtask: check {$check->id}, commit {$check->commit_sha}. "
                ."You last reviewed commit {$check->commit_sha}.\n")
            ->and($again->input['turn']['prompt'])->toContain("Re-review. Check only:\n")
            ->and($again->input['turn']['candidate'])->toBe($check->commit_sha)
            ->and($group->refresh()->reviewer_agent_thread_id)->toBe($thread->id)
            ->and($again->input['thread_id'])->toBe($thread->id)->and($again->input['turn']['thread'])->toBe('continue');
    });

    it('refuses a thread id that is not the role\'s thread this turn continues', function (Closure $thread): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $own = copyTurn($group);
        copyTurnEnded($own, 'succeeded', copyTurnDone('mcp:1'));
        $other = $thread($group, $copy, AgentThread::query()->findOrFail($own->input['thread_id']));
        $before = Operation::query()->count();

        expect(copyRefusal(fn () => copyTurn($group, ['thread' => (string) $other])))->toBe('tasks.thread_role_mismatch 422')
            ->and(Operation::query()->count())->toBe($before);
    })->with([
        'the implementer\'s thread for a reviewer turn' => [function (Task $group, Instance $copy, AgentThread $own): int {
            $own->update(['role' => 'reviewer']);

            return $own->id;
        }],
        'a task VM thread' => [fn (Task $group, Instance $copy, AgentThread $own): int => AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 'pi',
            'runtime_key' => 'node:'.$copy->node_id, 'external_id' => 'pi-session', 'role' => 'implementer'])->id],
        'a thread of an earlier copy' => [fn (Task $group, Instance $copy, AgentThread $own): int => AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 't3',
            'runtime_key' => 'instance:'.($copy->id + 1000), 'external_id' => 'mcp:old', 'role' => 'implementer'])->id],
        'a thread of another group' => [function (Task $group, Instance $copy, AgentThread $own): int {
            $elsewhere = Task::query()->create(['project_id' => $group->project_id, 'title' => 'Other', 'brief' => 'Other', 'status' => TaskGroupStatus::Todo]);

            return AgentThread::query()->create(['task_group_id' => $elsewhere->id, 'driver' => 't3', 'runtime_key' => 'instance:'.$copy->id,
                'external_id' => 'mcp:other', 'role' => 'implementer'])->id;
        }],
        'no such thread' => [fn (Task $group, Instance $copy, AgentThread $own): int => $own->id + 1000],
    ]);

    it('replays the same turn for a retry with the request\'s key', function (): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $idempotency = app(OperationIdempotency::class);
        $start = fn (): Operation => $idempotency->under('tasks.copy_turn', 'turn-key', 'fingerprint', fn (): Operation => copyTurn(Task::topLevel()->findOrFail($group->id)));

        $first = $start();

        expect($start()->id)->toBe($first->id)->and($first->idempotency_key)->toBe('turn-key')
            ->and(AgentThread::query()->where('task_group_id', $group->id)->count())->toBe(1);
    });

    // The API path of every copy turn: `task:turn` with an Idempotency-Key. A retry gets the same Operation; the same key with another
    // body is a conflict. The consult role and `model` were options of the removed Pi turn: a request with either is refused on that field.
    it('replays a turn sent twice through the API with one key, and refuses another body under that key', function (): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        Instance::query()->find($group->refresh()->taskable_id)?->runtime?->update(['power_state' => InstanceRuntimePowerState::Running]);
        $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
            'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
        $subtask = $group->tasks()->firstOrFail();
        $body = ['role' => 'implementer', 'prompt' => 'Run Doctor and commit a note.'];

        $first = $this->withHeader('Idempotency-Key', 'turn-api')->postJson("/api/v1/tasks/{$subtask->id}/turn", $body)
            ->assertStatus(202)->assertJsonPath('data.kind', 'tasks.copy_turn')->json('data.id');
        $this->withHeader('Idempotency-Key', 'turn-api')->postJson("/api/v1/tasks/{$subtask->id}/turn", $body)
            ->assertStatus(202)->assertJsonPath('data.id', $first);
        $this->withHeader('Idempotency-Key', 'turn-api')->postJson("/api/v1/tasks/{$subtask->id}/turn", [...$body, 'prompt' => 'Another turn.'])
            ->assertStatus(409)->assertJsonPath('error.code', 'operation.idempotency_conflict');

        // Activity keeps the turn's role but never its prompt: only the Operation's input holds it.
        $activities = Activity::query()->where('command', 'task:turn')->get();
        expect($activities)->toHaveCount(3)
            ->and($activities->map(fn (Activity $activity): mixed => $activity->properties['input'] ?? null)->unique()->values()->all())->toBe([['role' => 'implementer']])
            ->and($activities->toJson())->not->toContain('Run Doctor')->not->toContain('Another turn');
        expect(Operation::query()->where('kind', 'tasks.copy_turn')->pluck('id')->all())->toBe([$first])
            ->and(Operation::query()->findOrFail($first)->idempotency_key)->toBe('turn-api')
            ->and(AgentThread::query()->where('task_group_id', $group->id)->count())->toBe(1);
    });

    // The bodies are the SDK fixtures `tasks/task-turn/consult-refused` and `model-refused`: what an older CLI or SDK gets.
    it('refuses the consult role and a model of the caller on that field, and records nothing', function (array $change, string $field, string $fixture): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
            'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
        $subtask = $group->tasks()->firstOrFail();
        $before = Operation::query()->count();

        $body = $this->withHeader('Idempotency-Key', 'turn-pi-option')->postJson("/api/v1/tasks/{$subtask->id}/turn", ['role' => 'implementer', 'prompt' => 'Go.', 'thread' => 'new', ...$change])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed')->assertJsonStructure(['error' => ['details' => [$field]]])->json();
        expect(Operation::query()->count())->toBe($before)
            ->and(AgentThread::query()->count())->toBe(0);

        $recorded = ['schema' => 1, 'request' => 'Orbit\\Sdk\\Requests\\Tasks\\TurnTaskRequest', 'route' => 'POST /api/v1/tasks/{task}/turn', 'status' => 422, 'body' => $body];
        $path = base_path("../../packages/php-sdk/fixtures/tasks/task-turn/{$fixture}.json");
        if (getenv('ORBIT_FIXTURES') === 'record') {
            file_put_contents($path, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }
        expect($recorded)->toBe(json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
    })->with([
        'a consult' => [['role' => 'consult'], 'role', 'consult-refused'],
        'a model of the caller' => [['model' => 'claude-sonnet-5-5'], 'model', 'model-refused'],
    ]);

    it('is refused by name and records nothing before the copy is active, or for what a copy turn does not do yet', function (Closure $arrange, array $change, string $refusal): void {
        $group = copyGroup(copySource()->project);
        $arrange($group, startCopy($group));
        $before = Operation::query()->count();

        expect(copyRefusal(fn () => copyTurn($group, $change)))->toBe($refusal)
            ->and(Operation::query()->count())->toBe($before)
            ->and(AgentThread::query()->count())->toBe(0);
    })->with([
        'a copy still being created' => [fn (Task $group, Operation $create) => runningOperation($create), [], 'tasks.copy_not_ready 409'],
        'a copy not fully set up' => [function (Task $group, Operation $create): void {
            finishedCopy($group, $create)->update(['status' => InstanceState::Reserved]);
        }, [], 'tasks.copy_not_ready 409'],
        'a reviewer turn on no checked candidate' => [finishedCopy(...), ['role' => 'reviewer', 'check' => 1], 'tasks.commit_not_checked 422'],
    ]);

    it('refuses a turn, a check and a publish while the copy\'s completion failed, and each is only not ready while a retry runs', function (): void {
        $group = copyGroup(copySource()->project);
        [$copy, $completion] = copyCompleting($group);
        $copy->runtime->update(['power_state' => InstanceRuntimePowerState::Running]);
        $subtask = $group->tasks()->firstOrFail();
        $refusal = static function (Closure $start): array {
            try {
                $start();
            } catch (ResourceOperationException $error) {
                return [$error->errorCode, $error->status, $error->details];
            }

            return ['admitted'];
        };
        $check = fn () => app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Baseline), 'test', null);
        $publish = fn () => app(TaskPublishIntent::class)->start($group->refresh(), StartTaskPublishData::from(['check' => 1]), 'test', null);
        expect($refusal(fn () => copyTurn($group))[0])->toBe('tasks.copy_not_ready');

        app(OperationStore::class)->change($completion, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'instance.container_fleet_drift', 'failed_step' => 'clone-fleet']);
        $before = Operation::query()->count();

        $failed = ['tasks.copy_completion_failed', 409, ['operation_id' => $completion->id, 'failed_step' => 'clone-fleet',
            'error_code' => 'instance.container_fleet_drift', 'retry' => 'orbit instance:complete '.$copy->id]];
        expect($refusal(fn () => copyTurn($group)))->toBe($failed)
            ->and($refusal($check))->toBe($failed)
            ->and($refusal($publish))->toBe($failed)
            ->and(Operation::query()->count())->toBe($before)
            ->and(TaskCheck::query()->count())->toBe(0)
            ->and(AgentThread::query()->count())->toBe(0);

        // The retry runs: the copy is only not ready, for a turn, a check and a publish alike.
        copyCompletion($copy);
        expect($refusal(fn () => copyTurn($group))[0])->toBe('tasks.copy_not_ready')
            ->and($refusal($check)[0])->toBe('tasks.copy_not_ready')
            ->and($refusal($publish)[0])->toBe('tasks.copy_not_ready')
            ->and(TaskCheck::query()->count())->toBe(0);
    });

    it('answers tasks.copy_not_ready to a publish and a hosted handoff check while the copy\'s create still runs', function (): void {
        $group = copyGroup(copySource()->project);
        $create = runningOperation(startCopy($group));
        $copy = Instance::query()->findOrFail($create->target_id);
        // The create's copy has no start commit yet; a hosted handoff needs CI, which a copy cannot run yet.
        expect($copy->starting_commit)->toBeNull();
        $copy->runtime->update(['power_state' => InstanceRuntimePowerState::Running]);
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required', 'source_access' => ProjectSourceAccess::GitHubApp]);
        $subtask = $group->tasks()->firstOrFail();
        $code = static function (Closure $start): string {
            try {
                $start();
            } catch (ResourceOperationException $error) {
                return $error->errorCode;
            }

            return 'admitted';
        };

        expect($code(fn () => app(TaskPublishIntent::class)->start($group->refresh(), StartTaskPublishData::from(['check' => 1]), 'test', null)))->toBe('tasks.copy_not_ready')
            ->and($code(fn () => app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null)))->toBe('tasks.copy_not_ready');
    });

    it('refuses a nested-fleet group without a copy instead of reaching for a task VM', function (): void {
        $group = copyGroup(copySource()->project);
        $group->update(['status' => TaskGroupStatus::Running]);

        expect(copyRefusal(fn () => copyTurn($group)))->toBe('tasks.no_workspace 409')
            ->and(Operation::query()->count())->toBe(1);
    });

    it('records a completed turn on its thread, with T3\'s thread id and a transcript that outlives the copy, without the user\'s text', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $turn = copyTurn($group);
        $transcript = [['id' => 'run:1:0', 'kind' => 'message', 'label' => 'user', 'text' => 'Run Doctor.', 'at' => ''],
            ['id' => 'run:1:1', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Committed d867aba2.', 'at' => '']];

        $ended = copyTurnEnded($turn, 'succeeded', ['state' => 'done', 'error' => null, 'ended' => true, 't3_thread_id' => 'mcp:66105c2c', 'run_id' => 'run:1', 'transcript' => $transcript]);

        $thread = AgentThread::query()->where('task_group_id', $group->id)->sole();
        expect($ended->status)->toBe(OperationStatus::Succeeded)
            ->and($ended->result)->toMatchArray(['state' => 'done', 't3_thread_id' => 'mcp:66105c2c'])->and($ended->result)->not->toHaveKey('transcript')
            ->and($thread->state)->toBe(AgentThreadState::Done)->and($thread->external_id)->toBe('mcp:66105c2c')->and($thread->tokens)->toBeNull();

        $cancel = app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);
        destroySucceeded(Operation::query()->findOrFail($cancel->result['destroy_operation_id']));

        $read = app(ReadAgentThreadAction::class)->execute($group->refresh(), $thread->refresh(), null);
        expect(Instance::query()->find($copy->id))->toBeNull()
            ->and($read['archived'])->toBeTrue()
            // A worker that still sends the user's text has it dropped: the user message keeps its ID, author and time, the reply stays.
            ->and(array_map(static fn (array $entry): array => Arr::only($entry, ['id', 'label', 'text']), $read['data']['entries'] ?? $read['entries'] ?? []))
            ->toBe([['id' => 'run:1:0', 'label' => 'user', 'text' => ''], ['id' => 'run:1:1', 'label' => 'assistant', 'text' => 'Committed d867aba2.']])
            ->and(json_encode($read, JSON_THROW_ON_ERROR))->not->toContain('Run Doctor.');
    });

    it('records the T3 thread\'s token use on its agent thread, replacing the stored total, and leaves unknown use null', function (): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $usage = ['tokens' => 1370, 'input_tokens' => 500, 'cached_input_tokens' => 800, 'output_tokens' => 70, 'model_calls' => 2];
        $metrics = static fn (): array => Arr::only(AgentThread::query()->where('task_group_id', $group->id)->sole()->getAttributes(), array_keys($usage));

        copyTurnEnded(copyTurn($group), 'succeeded', [...copyTurnDone('mcp:66105c2c'), 'usage' => null]);
        expect($metrics())->toBe(array_fill_keys(array_keys($usage), null));

        AgentThread::query()->where('task_group_id', $group->id)->update(['tokens' => 9]);
        copyTurnEnded(copyTurn($group), 'succeeded', [...copyTurnDone('mcp:66105c2c'), 'usage' => $usage]);
        expect($metrics())->toBe($usage);

        // An older worker's result without usage, and a usage that is not a set of non-negative counts, change nothing.
        foreach ([[], ['usage' => [...$usage, 'tokens' => -1]], ['usage' => [...$usage, 'tokens' => '5']], ['usage' => Arr::except($usage, 'model_calls')]] as $change) {
            copyTurnEnded(copyTurn($group), 'succeeded', [...copyTurnDone('mcp:66105c2c'), ...$change]);
            expect($metrics())->toBe($usage);
        }
    });

    it('names the agent thread and the agent\'s last reply in the result, so a driver reads the turn in one call', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $turn = copyTurn($group);
        $transcript = [['id' => 'run:1:0', 'kind' => 'message', 'label' => 'assistant', 'text' => 'An earlier reply.', 'at' => ''],
            ['id' => 'run:1:1', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Committed d867aba2.', 'at' => ''],
            ['id' => 'run:1:2', 'kind' => 'message', 'label' => 'user', 'text' => 'Thanks.', 'at' => '']];

        $ended = copyTurnEnded($turn, 'succeeded', [...copyTurnDone('mcp:1'), 'transcript' => $transcript]);

        expect($ended->result)->toMatchArray(['agent_id' => $turn->input['thread_id'], 'reply' => 'Committed d867aba2.'])
            ->and($ended->result)->not->toHaveKey('transcript');
    });

    it('cuts a reply longer than its bound at the end and says where the rest is', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $turn = copyTurn($group);
        $agent = $turn->input['thread_id'];
        $long = str_repeat('é', TaskTurnProjection::ReplyLimit).'tail';
        $exact = str_repeat('é', TaskTurnProjection::ReplyLimit);

        $cut = copyTurnEnded($turn, 'succeeded', [...copyTurnDone('mcp:1'), 'transcript' => [['id' => 'run:1:0', 'kind' => 'message', 'label' => 'assistant', 'text' => $long, 'at' => '']]]);
        $whole = copyTurnEnded(copyTurn($group), 'succeeded', [...copyTurnDone('mcp:1'), 'transcript' => [['id' => 'run:2:0', 'kind' => 'message', 'label' => 'assistant', 'text' => $exact, 'at' => '']]]);

        expect(TaskTurnProjection::ReplyLimit)->toBe(16384)
            ->and($cut->result['reply'])->toBe($exact." [cut: 4 more characters; read task:agent:show {$group->id} {$agent}]")
            ->and($whole->result['reply'])->toBe($exact);
    });

    it('names no reply for a turn with no assistant entry', function (array $shaped): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $turn = copyTurn($group);

        $ended = copyTurnEnded($turn, 'succeeded', [...array_diff_key(copyTurnDone('mcp:1'), ['transcript' => true]), ...$shaped]);

        expect($ended->result)->toHaveKey('reply')->and($ended->result['reply'])->toBeNull()
            ->and($ended->result['agent_id'])->toBe($turn->input['thread_id']);
    })->with([
        'no transcript' => [[]],
        'an empty transcript' => [['transcript' => []]],
        'user entries only' => [['transcript' => [['id' => 'run:1:0', 'kind' => 'message', 'label' => 'user', 'text' => 'Run Doctor.', 'at' => '']]]],
        // Swamp's notice that it cut older entries is Orbit's text, not the agent's.
        'the truncation notice and a user entry' => [['transcript' => [
            ['id' => 'orbit-truncated', 'kind' => 'message', 'label' => 'system', 'text' => '[Transcript truncated: older entries omitted from the recorded turn.]', 'at' => ''],
            ['id' => 'run:1:0', 'kind' => 'message', 'label' => 'user', 'text' => 'Run Doctor.', 'at' => '']]]],
    ]);

    it('names the agent thread and its last reply for a failed turn too', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $turn = copyTurn($group);

        $failed = copyTurnEnded($turn, 'failed', ['state' => 'failed', 'error' => 'Bound.', 'error_code' => 'tasks.turn_timeout', 'ended' => false, 't3_thread_id' => 'mcp:1',
            'run_id' => null, 'transcript' => [['id' => 'run:1:0', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Half done.', 'at' => '']]]);

        expect($failed->result)->toMatchArray(['agent_id' => $turn->input['thread_id'], 'reply' => 'Half done.']);
    });

    it('hands the copy\'s new T3 thread and its title to the extensions once the turn\'s projection committed', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $turn = copyTurn($group);
        $contribution = Mockery::mock(InstanceThreadContribution::class);
        $contribution->shouldReceive('recordThread')->once()->withArgs(fn (Instance $instance, string $id, string $title): bool => $instance->id === $copy->id
            && $id === 'mcp:66105c2c' && $title === 'Orbit task '.$group->id.' thread '.$turn->input['thread_id']);
        app()->instance(InstanceThreadContributions::class, new InstanceThreadContributions([$contribution]));

        copyTurnEnded($turn, 'succeeded', ['state' => 'done', 'error' => null, 'ended' => true, 't3_thread_id' => 'mcp:66105c2c', 'run_id' => 'run:1', 'transcript' => []]);
    });

    it('records a turn that ran past its bound as failed with tasks.turn_timeout', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $timedOut = copyTurnEnded(copyTurn($group), 'failed', ['state' => 'failed', 'error' => 'The turn ran longer than its bound.',
            'error_code' => 'tasks.turn_timeout', 'ended' => false, 't3_thread_id' => 'mcp:1', 'run_id' => null, 'transcript' => []]);

        expect($timedOut->error_code)->toBe('tasks.turn_timeout')
            ->and(AgentThread::query()->findOrFail($timedOut->input['thread_id'])->only(['state', 'error']))
            // Its run did not stop: the error says the group holds.
            ->toBe(['state' => AgentThreadState::Failed, 'error' => 'The turn ran longer than its bound.'.TaskTurnProjection::Held]);
    });

    it('is stopped by the group\'s cancel before the copy\'s destroy is admitted', function (): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $turn = copyTurn($group);

        $cancel = app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);

        expect($turn->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and(Operation::query()->findOrFail($cancel->result['destroy_operation_id'])->kind)->toBe('instance.destroy');
    });

    it('hands a turn that may still run to the next turn to settle, and refuses a check until a turn settled it', function (string $status, array $shaped): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $cut = copyTurnEnded(copyTurn($group), $status, $shaped);
        $subtask = $group->tasks()->firstOrFail();
        $check = fn () => app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null);

        // A check cannot read T3 in the copy: it refuses and names the turn.
        expect(copyRefusal($check))->toBe('tasks.copy_turn_unended 409')
            ->and(Operation::query()->where('kind', 'tasks.check')->exists())->toBeFalse()
            // A cancelled turn records nothing on its thread.
            ->and(AgentThread::query()->findOrFail($cut->input['thread_id'])->state)->toBe($status === 'cancelled' ? null : AgentThreadState::Failed);
        try {
            $check();
        } catch (ResourceOperationException $error) {
            // It names the way out: the next turn stops the earlier run and settles it.
            expect($error->details)->toBe(['operation_id' => $cut->id])
                ->and($error->getMessage())->toBe('An earlier turn may still run in the task copy or left its checkout unrestored. '
                    .'A check, publish or proof waits for the next task:turn, which stops that run and settles it. operation:cancel ends a running turn, '
                    .'and the next turn stops its run.');
        }

        // The next turn is admitted and settles the earlier one in its own phase first, also on another thread.
        $next = copyTurn($group, ['thread' => 'new']);
        expect(copyTurnSettle($next))->toBe([['operation_id' => $cut->id, 'title' => $cut->input['turn']['title'], 'branch' => null]]);
    })->with([
        'a wait cut by the bound' => ['failed', ['state' => 'failed', 'error' => 'Bound.', 'error_code' => 'tasks.turn_timeout', 'ended' => false, 't3_thread_id' => 'mcp:1', 'run_id' => null, 'transcript' => []]],
        'a cancelled run' => ['cancelled', []],
        'a phase that wrote no end' => ['failed', ['state' => 'failed', 'error' => 'No end.', 'error_code' => 'tasks.copy_turn_failed', 'ended' => false, 't3_thread_id' => null, 'run_id' => null, 'transcript' => []]],
        'a reviewer checkout left unrestored' => ['failed', ['state' => 'failed', 'error' => 'Unrestored.', 'error_code' => 'tasks.copy_review_unrestored', 'ended' => false, 't3_thread_id' => 'mcp:1', 'run_id' => 'run:1', 'transcript' => []]],
    ]);

    it('admits a check once the next turn found the cut turn\'s run over, and keeps refusing while T3 still runs it', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $subtask = $group->tasks()->firstOrFail();
        $check = fn () => app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null);
        $cut = copyTurnEnded(copyTurn($group), 'failed', ['state' => 'failed', 'error' => 'Bound.', 'error_code' => 'tasks.turn_timeout', 'ended' => false,
            't3_thread_id' => 'mcp:1', 'run_id' => null, 'transcript' => []]);

        // T3 still runs it: the next turn launched nothing and settled nothing.
        $held = copyTurnEnded(copyTurn($group), 'failed', ['state' => 'failed', 'error' => 'Still runs.', 'error_code' => 'tasks.copy_turn_unended', 'ended' => true,
            't3_thread_id' => null, 'run_id' => null, 'settled' => [], 'transcript' => []]);
        expect($held->error_code)->toBe('tasks.copy_turn_unended')->and(copyRefusal($check))->toBe('tasks.copy_turn_unended 409');

        // T3 later completed it: the next turn settles it, runs, and the group is free.
        $next = copyTurn($group);
        expect(copyTurnSettle($next))->toBe([['operation_id' => $cut->id, 'title' => $cut->input['turn']['title'], 'branch' => null]]);
        copyTurnEnded($next, 'succeeded', [...copyTurnDone('mcp:1'), 'settled' => [$cut->id]]);
        expect(copyTurn($group)->input['turn']['settle'])->toBe([]);
    });

    it('names a reviewer turn\'s task branch, so the next turn settles it only on a clean checkout of that branch', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $reviewer = copyTurnEnded(copyTurn($group, ['role' => 'reviewer', 'check' => copyCandidate($group)->id]), 'failed', ['state' => 'failed', 'error' => 'Unrestored.',
            'error_code' => 'tasks.copy_review_unrestored', 'ended' => false, 't3_thread_id' => 'mcp:1', 'run_id' => 'run:1', 'transcript' => []]);

        // The settle restores the checkout to the task branch only while the copy still holds the reviewer's detach.
        expect(copyTurnSettle(copyTurn($group)))->toBe([['operation_id' => $reviewer->id, 'title' => $reviewer->input['turn']['title'],
            'branch' => 'task-'.$group->id]]);
    });

    it('admits the next turn after a turn whose T3 run ended, also a failed one', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        copyTurnEnded(copyTurn($group), 'failed', ['state' => 'failed', 'error' => 'T3 run failed: api_error_529', 'error_code' => 'tasks.turn_failed', 'ended' => true,
            't3_thread_id' => 'mcp:1', 'run_id' => 'run:1', 'transcript' => []]);

        expect(copyTurn($group)->kind)->toBe('tasks.copy_turn');
    });

    it('records a turn that asked as a succeeded Operation with its questions on the thread, and holds no other turn or check', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $subtask = $group->tasks()->firstOrFail();
        $asked = copyTurnEnded(copyTurn($group), 'succeeded', copyTurnAsked('mcp:1'));

        $thread = AgentThread::query()->findOrFail($asked->input['thread_id']);
        expect($asked->status)->toBe(OperationStatus::Succeeded)->and($asked->error_code)->toBeNull()
            ->and($asked->result)->toMatchArray(['state' => 'asking_for_input', 'ended' => true, 'questions' => copyTurnAsked('mcp:1')['questions']])
            ->and($thread->state)->toBe(AgentThreadState::AskingForInput)
            ->and($thread->questions)->toBe(copyTurnAsked('mcp:1')['questions'])
            // task:agent:list reads the question with the thread.
            ->and(AgentThreadData::fromModel($thread)->toArray()['questions'])->toBe(copyTurnAsked('mcp:1')['questions']);

        // The stopped run holds nothing: a new thread and a check are admitted.
        $fresh = copyTurn($group, ['thread' => 'new']);
        copyTurnEnded($fresh, 'succeeded', copyTurnDone('mcp:2'));
        expect(app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null)->kind)->toBe('tasks.check');
    });

    it('keeps a thread\'s question when the turn that would answer it launched nothing', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $asked = copyTurnEnded(copyTurn($group), 'succeeded', copyTurnAsked('mcp:1'));

        // The answer's turn found an earlier run still going and launched nothing.
        copyTurnEnded(copyTurn($group, ['prompt' => 'Blue.']), 'failed', ['state' => 'failed', 'error' => 'Still runs.', 'error_code' => 'tasks.copy_turn_unended',
            'ended' => true, 't3_thread_id' => null, 'run_id' => null, 'launched' => false, 'settled' => [], 'transcript' => []]);

        expect(AgentThread::query()->findOrFail($asked->input['thread_id'])->questions)->toBe(copyTurnAsked('mcp:1')['questions'])
            ->and(copyTurn($group, ['prompt' => 'Blue.'])->input['turn']['prompt'])->toContain("You asked in your last turn:\n[Color] Which color?");
    });

    it('quotes the questions of a thread\'s last turn before the answer that continues it, and clears them when that turn is recorded', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $asked = copyTurnEnded(copyTurn($group), 'succeeded', copyTurnAsked('mcp:1'));

        $answer = copyTurn($group, ['prompt' => 'Blue.']);

        expect($answer->input['thread_id'])->toBe($asked->input['thread_id'])->and($answer->input['turn']['thread'])->toBe('continue')
            ->and($answer->input['turn']['answered'])->toBe(['request:1'])
            // The answer cancels only requests of the run that owns them.
            ->and($answer->input['turn']['answered_run'])->toBe('run:1')
            ->and($answer->input['turn']['prompt'])->toContain("<turn>\nYou asked in your last turn:\n[Color] Which color?\n- Red: Choose red\n"
                ."- Blue: Choose blue\n\nAnswer:\nBlue.\n</turn>\n\nOrbit ends this turn at ");
        copyTurnEnded($answer, 'succeeded', copyTurnDone('mcp:1'));
        expect(AgentThread::query()->findOrFail($asked->input['thread_id'])->questions)->toBeNull()
            // A thread that asked nothing last time quotes nothing.
            ->and(copyTurn($group, ['prompt' => 'Next.'])->input['turn']['prompt'])->not->toContain('You asked');
    });

    it('admits only the answered thread requests once and retains them after a resolution refusal before launch', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $question = copyTurnAsked('mcp:1')['questions'][0];
        $asked = copyTurnEnded(copyTurn($group), 'succeeded', [...copyTurnAsked('mcp:1'), 'questions' => [
            $question, [...$question, 'id' => 'second'], [...$question, 'request_id' => 'request:2'],
        ]]);

        $answer = copyTurn($group, ['prompt' => 'Blue.']);

        expect($answer->input['turn']['answered'])->toBe(['request:1', 'request:2']);
        copyTurnEnded($answer, 'failed', ['state' => 'failed', 'error' => 'Still pending.', 'error_code' => 'tasks.copy_question_unresolved',
            'ended' => true, 't3_thread_id' => null, 'run_id' => null, 'launched' => false, 'settled' => [], 'transcript' => []]);
        expect(AgentThread::query()->findOrFail($asked->input['thread_id'])->questions)->toHaveCount(3);
        $retry = copyTurn($group, ['prompt' => 'Blue.']);
        expect($retry->input['turn']['answered'])->toBe(['request:1', 'request:2'])
            ->and($retry->input['turn']['prompt'])->toContain('You asked in your last turn:');
        copyTurnEnded($retry, 'succeeded', copyTurnDone('mcp:1'));
        $fresh = copyTurn($group, ['thread' => 'new']);
        expect($fresh->input['turn']['answered'])->toBe([])->and($fresh->input['turn']['prompt'])->not->toContain('You asked in your last turn:');
    });

    it('settles a run that asked and was not stopped by the same rule: a check waits, and the next turn admits once T3 shows it ended', function (string $code): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $subtask = $group->tasks()->firstOrFail();
        $held = copyTurnEnded(copyTurn($group), 'failed', [...copyTurnAsked('mcp:1'), 'ended' => false, 'error' => 'Not stopped.', 'error_code' => $code]);

        expect($held->error_code)->toBe($code)
            ->and(AgentThread::query()->findOrFail($held->input['thread_id'])->state)->toBe(AgentThreadState::AskingForInput)
            ->and(copyRefusal(fn () => app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null)))
            ->toBe('tasks.copy_turn_unended 409');
        $next = copyTurn($group, ['prompt' => 'Blue.']);
        expect(array_column($next->input['turn']['settle'], 'operation_id'))->toBe([$held->id]);
        copyTurnEnded($next, 'succeeded', [...copyTurnDone('mcp:1'), 'settled' => [$held->id]]);
        expect(app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null)->kind)->toBe('tasks.check');
    })->with(['tasks.copy_question_unstopped', 'tasks.copy_question_unread']);

    it('names the provider instance, the model and the effort the thread started with in the turn\'s result, as the group or Project resolved them', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $group->update(['implementer_agent_effort' => 'xhigh']);
        $group->project->update(['task_implementer_model' => 'claude-sonnet-5-5']);

        // T3 shows what it ran; the result names what the thread started with.
        $done = copyTurnEnded(copyTurn($group), 'succeeded', [...copyTurnDone('mcp:1'), 'provider_instance' => 'claudeMedium', 'model' => 'claude-opus-5-5']);
        // A continued thread keeps its recorded values, whatever the group says now.
        $group->update(['implementer_agent_effort' => 'low']);
        $continued = copyTurnEnded(copyTurn($group), 'succeeded', copyTurnDone('mcp:1'));

        expect($done->result)->toMatchArray(['provider_instance' => 'claudeXhigh', 'model' => 'claude-sonnet-5-5', 'effort' => 'xhigh'])
            ->and($continued->input['turn']['thread'])->toBe('continue')
            ->and($continued->result)->toMatchArray(['provider_instance' => 'claudeXhigh', 'model' => 'claude-sonnet-5-5', 'effort' => 'xhigh']);
    });

    it('backfills the provider instance of each copy thread recorded before it was stored: the instance of its effort', function (): void {
        $migration = require database_path('migrations/2027_02_18_000000_add_task_copy_agents.php');
        $migration->down();
        $group = copyGroup(copySource()->project);
        $thread = static fn (string $driver, string $runtime, ?string $effort): int => DB::table('agent_threads')->insertGetId(['task_group_id' => $group->id,
            'driver' => $driver, 'runtime_key' => $runtime, 'external_id' => (string) Str::uuid(), 'role' => 'implementer', 'model' => 'claude-opus-5-5',
            'effort' => $effort, 'observation_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $threads = ['medium' => $thread('t3', 'instance:7', 'medium'), 'xhigh' => $thread('t3', 'instance:7', 'xhigh'), 'max' => $thread('t3', 'instance:8', 'max'),
            'task vm' => $thread('pi', 'vm:9', 'high'), 'no effort' => $thread('t3', 'instance:7', null), 'unknown effort' => $thread('t3', 'instance:7', 'minimal')];

        $migration->up();

        expect(DB::table('agent_threads')->orderBy('id')->pluck('provider_instance', 'id')->all())->toBe([
            $threads['medium'] => 'claudeMedium', $threads['xhigh'] => 'claudeXhigh', $threads['max'] => 'claudeMax',
            $threads['task vm'] => null, $threads['no effort'] => null, $threads['unknown effort'] => null,
        ]);
    });

    it('marks the thread failed when Swamp refuses to start the turn, so the subtask points at no silent thread', function (): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $turn = copyTurn($group);

        // As StartSwampRun::refuse leaves it: the Operation failed before any run.
        $turn->forceFill(['status' => 'failed', 'error_code' => 'operation.start_refused', 'finished_at' => now()])->save();
        app(TaskTurnProjection::class)->refused($turn);

        expect(AgentThread::query()->findOrFail($turn->input['thread_id'])->only(['state', 'error']))
            ->toBe(['state' => AgentThreadState::Failed, 'error' => 'Swamp refused to start the turn.'])
            // A turn that never started a run leaves the group free.
            ->and(copyTurn($group)->kind)->toBe('tasks.copy_turn');
    });

    it('serves a T3 thread only from its archive', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 't3', 'runtime_key' => 'host', 'external_id' => 'retired', 'role' => 'implementer']);

        expect(copyRefusal(fn () => app(ReadAgentThreadAction::class)->execute($group, $thread, null)))->toBe('tasks.agent_transcript_unavailable 409');
    });
});

describe('a cancel or complete of a copy group whose work was carried to the default branch', function (): void {
    /**
     * A running copy group without a watched pull request, whose copy now reads `$head`, and the content read of its latest sync:
     * `$work` overrides the read of a hand-carried branch (docs/reference/tasks.md#published-work-of-a-copy).
     *
     * @param  array<string, mixed>|null  $work
     * @return array{Task, Instance}
     */
    function carriedCopy(?array $work, string $approved = ''): array
    {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        // The reader names the branch as `head` and its commit as `head_commit`; the content read is of the commit.
        $head = str_repeat('d', 40);
        test()->ssh->answer['head_commit'] = $head;
        if ($work !== null) {
            Operation::query()->create(['kind' => 'tasks.sync', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $copy->node_id,
                'input' => ['copy' => ['approved' => $approved]], 'state' => [], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 0,
                'finished_at' => now(), 'result' => ['work' => [...['state' => 'clean', 'head' => $head, 'default_commit' => str_repeat('e', 40), 'empty' => false,
                    'paths' => ['count' => 0, 'names' => []], 'uncommitted' => 0, 'untracked' => 0, 'stashes' => 0, 'approved_held' => null], ...$work]]]);
        }

        return [$group->refresh(), $copy];
    }

    function completeRefusal(Task $group): ?string
    {
        try {
            app(CompleteTaskGroupAction::class)->execute($group);
        } catch (ResourceOperationException $error) {
            return $error->errorCode.' '.($error->details['reason'] ?? '');
        }

        return null;
    }

    it('completes a running copy group with no watched pull request whose committed work is on the default branch, and destroys its copy', function (): void {
        [$group, $copy] = carriedCopy([]);

        $completed = app(CompleteTaskGroupAction::class)->execute($group);

        $destroy = Operation::query()->where('kind', 'instance.destroy')->sole();
        expect($completed->status)->toBe(TaskGroupStatus::Completed)
            ->and($completed->settled_at)->not->toBeNull()
            ->and($completed->tasks()->where('status', TaskStatus::Cancelled)->count())->toBe(1)
            ->and($destroy->target_id)->toBe($copy->id)->and($destroy->input['force'])->toBeTrue();
    });

    it('refuses a running copy group whose work is not shown on the default branch with tasks.not_settling and the reason', function (?array $work, string $reason): void {
        [$group] = carriedCopy($work);

        expect(completeRefusal($group))->toBe('tasks.not_settling '.$reason)
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Running)
            ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse();
    })->with([
        'never read' => [null, 'work_unread'],
        'read of an older head' => [['head' => str_repeat('f', 40)], 'work_unread'],
        'a path that differs' => [['state' => 'dirty', 'paths' => ['count' => 1, 'names' => ['work.txt']]], 'work_not_on_default'],
        'an empty copy: cancel it' => [['empty' => true], 'work_empty'],
    ]);

    it('cancels a squash-merged copy without discard: its local commits are on the default branch by content', function (): void {
        [$group, $copy] = carriedCopy([]);
        // The read counts the copy's own commits and the check's empty handoff commit: origin lacks their ids.
        $this->ssh->answer = [...$this->ssh->answer, 'state' => 'dirty',
            'commits' => ['count' => 2, 'branch_count' => 1, 'branches' => [['name' => 'task-'.$group->id, 'count' => 2]], 'head' => 2]];

        $cancel = app(TaskCancelIntent::class)->start($group, 'test', null);

        $destroy = Operation::query()->where('kind', 'instance.destroy')->sole();
        expect($cancel->result['destroy_operation_id'])->toBe($destroy->id)->and($destroy->target_id)->toBe($copy->id)
            ->and($cancel->result['workspace'])->toMatchArray(['state' => 'clean', 'commits' => 2, 'unpushed' => 0, 'work' => 'on_default']);
    });

    it('still refuses a copy whose committed work is on the default branch but which holds uncommitted work, or whose commits were not read', function (array $answer, ?array $work, array $details): void {
        [$group] = carriedCopy($work);
        $this->ssh->answer = [...$this->ssh->answer, 'state' => 'dirty',
            'commits' => ['count' => 1, 'branch_count' => 1, 'branches' => [['name' => 'task-'.$group->id, 'count' => 1]], 'head' => 1], ...$answer];

        try {
            app(TaskCancelIntent::class)->start($group, 'test', null);
            $this->fail('The cancel destroyed unpublished work.');
        } catch (ResourceOperationException $error) {
            expect($error->errorCode)->toBe('tasks.copy_unpublished_work')->and($error->details)->toMatchArray($details);
        }
        expect(Operation::query()->whereIn('kind', ['instance.destroy', 'tasks.cancel'])->exists())->toBeFalse();
    })->with([
        'an uncommitted file' => [['modified' => ['count' => 1, 'names' => ['a.php']]], [], ['unpushed' => 0, 'work' => 'on_default', 'uncommitted' => 1]],
        'a stash' => [['stashes' => ['count' => 1, 'names' => ['stash@{0}']]], [], ['unpushed' => 0, 'stashes' => 1]],
        'no read of this head' => [[], ['head' => str_repeat('f', 40)], ['unpushed' => 1, 'work' => 'work_unread']],
        // The content read is of the checked-out branch alone: a commit that only another branch holds is never cleared.
        'a commit only another branch holds' => [['commits' => ['count' => 2, 'branch_count' => 2,
            'branches' => [['name' => 'experiment', 'count' => 1], ['name' => 'task-1', 'count' => 1]], 'head' => 1]], [],
            ['commits' => 2, 'unpushed' => 1, 'work' => 'on_default', 'uncommitted' => 0, 'stashes' => 0]],
        'a path that differs' => [[], ['state' => 'dirty', 'paths' => ['count' => 1, 'names' => ['a.php']]], ['unpushed' => 1, 'work' => 'work_not_on_default']],
    ]);

    it('takes the approved commit as kept when the content read holds it on the default branch, though origin\'s branch cannot be read', function (bool $held): void {
        $approved = str_repeat('a', 40);
        [$group] = carriedCopy(['approved_held' => $held], $approved);
        // No GitHub origin: the branch compare refuses without a read, so only the content read can keep the approved work.
        $group->project->update(['repository_url' => 'https://git.example.test/trial.git']);
        TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $group->tasks()->value('id'), 'type' => TaskCommentType::Approved,
            'body' => 'Approved.', 'author' => 'reviewer', 'commit_sha' => $approved, 'posted_at' => now()]);

        expect(completeRefusal($group->refresh()))->toBe($held ? null : 'tasks.approved_work_unpublished ');
    })->with(['held' => [true], 'not held' => [false]]);
});

describe('a copy group whose watched pull request merged settles itself', function (): void {
    /**
     * A running copy group whose watched pull request is #7, on a Project with maintenance off: the settle does not depend on it.
     *
     * @return array{Task, Instance}
     */
    function watchedCopy(): array
    {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'settle', 'settle', 'nckrtl', 'organization', 'https://github.com/apps/settle', 'unused'));
        expect($group->project->maintenance)->toBeFalse();
        $group->update(['watched_pr_url' => 'https://github.com/nckrtl/trial/pull/7', 'watched_pr_state' => 'open']);

        return [$group->refresh(), $copy];
    }

    /**
     * One `task:copy-settle` tick, with a GitHub that serves the watched pull request in `$state` and fails every other read with
     * `$branch` (docs/reference/tasks.md#a-copy-group-settles-on-merge). Returns how often the tick read the pull request.
     */
    function settleTick(string $state, ?Throwable $branch = null): int
    {
        $reads = 0;
        $pull = new GitHubPullRequest(GitHubPullRequestState::from($state), null, null, str_repeat('a', 40), 'main', mergedAt: $state === 'merged' ? '2026-11-16T20:00:00Z' : null,
            createdAt: '2026-11-16T18:00:00Z');
        app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($pull, $branch, &$reads): void {
            $github->shouldReceive('repositoryInstallation')->andReturn(7);
            $github->shouldReceive('repositoryPullRequestReadToken')->andReturn('read-token');
            $github->shouldReceive('repositoryReadToken')->andReturn('contents-token');
            $github->shouldReceive('pullRequest')->andReturnUsing(function () use ($pull, &$reads): GitHubPullRequest {
                $reads++;

                return $pull;
            });
            $github->shouldReceive('branchHead')->andThrow($branch ?? new GitHubApiException('No branch read expected.'));
        }));
        expect(Artisan::call('task:copy-settle'))->toBe(0);

        return $reads;
    }

    it('runs every five minutes beside the other task jobs', function (): void {
        Artisan::call('schedule:list');

        expect(Artisan::output())->toMatch('/\*\/5\s+\*\s+\*\s+\*\s+\*\s+php artisan task:copy-settle/');
    });

    it('records the merge, completes the group and admits the copy\'s destroy, in a Project with maintenance off, and only reads the pull request', function (): void {
        [$group, $copy] = watchedCopy();
        Http::fake();

        $reads = settleTick('merged');

        $destroy = Operation::query()->where('kind', 'instance.destroy')->sole();
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)
            ->and($group->watched_pr_state)->toBe('merged')->and($group->watched_pr_completion)->toBe('merged')
            ->and($group->merged_at?->toIso8601ZuluString())->toBe('2026-11-16T20:00:00Z')->and($group->settled_at)->not->toBeNull()
            ->and($group->tasks()->where('status', TaskStatus::Cancelled)->count())->toBe(1)
            ->and($destroy->target_id)->toBe($copy->id)->and($destroy->input['force'])->toBeTrue()
            ->and($group->assistance_requested)->toBeFalse();
        // Orbit never merges, readies or comments: the tick reads the pull request once, and nothing else goes to GitHub.
        expect($reads)->toBe(1);
        Http::assertNothingSent();
        // A repeated tick while the destroy runs admits nothing more and reads no pull request.
        expect(settleTick('merged'))->toBe(0);
        expect(Operation::query()->where('kind', 'instance.destroy')->count())->toBe(1);

        destroySucceeded($destroy);
        expect(Instance::query()->find($copy->id))->toBeNull()->and($group->refresh()->status)->toBe(TaskGroupStatus::Completed)->and($group->taskable_id)->toBeNull();
    });

    it('records when GitHub opened the watched pull request, and never moves a time already recorded', function (?string $recorded, string $expected): void {
        [$group] = watchedCopy();
        $group->update(['pr_opened_at' => $recorded]);

        settleTick('open');

        expect($group->refresh()->pr_opened_at?->toIso8601ZuluString())->toBe($expected);
    })->with([
        'none recorded' => [null, '2026-11-16T18:00:00Z'],
        'one recorded' => ['2026-11-16T17:00:00Z', '2026-11-16T17:00:00Z'],
    ]);

    it('records a pull request that closed without a merge, and leaves the group and its copy for a person', function (): void {
        [$group, $copy] = watchedCopy();

        settleTick('closed');

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($group->watched_pr_state)->toBe('closed')
            ->and($group->watched_pr_completion)->toBeNull()->and($group->taskable_id)->toBe($copy->id)
            ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse();
    });

    it('skips a group while an Operation of its own runs, and completes it on the next tick once that ended', function (): void {
        [$group] = watchedCopy();
        $turn = Operation::query()->create(['kind' => 'tasks.copy_turn', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $group->taskable->node_id,
            'input' => [], 'state' => [], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 0]);

        settleTick('merged');

        // The merge is recorded; the running turn is not stopped.
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($group->watched_pr_completion)->toBe('merged')
            ->and($turn->refresh()->status)->toBe(OperationStatus::Running)
            ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse();

        $turn->forceFill(['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null])->save();
        settleTick('merged');

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)
            ->and(Operation::query()->where('kind', 'instance.destroy')->count())->toBe(1);
    });

    it('completes a group whose merge an earlier tick recorded before it crashed, without reading the pull request again', function (): void {
        [$group] = watchedCopy();
        $group->update(['watched_pr_state' => 'merged', 'watched_pr_completion' => 'merged', 'merged_at' => '2026-11-16T20:00:00Z']);

        expect(settleTick('merged'))->toBe(0);

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)
            ->and(Operation::query()->where('kind', 'instance.destroy')->count())->toBe(1);
    });

    it('keeps a completed group with its copy when the destroy fails, and the next tick admits a new destroy', function (): void {
        [$group, $copy] = watchedCopy();
        settleTick('merged');
        Operation::query()->where('kind', 'instance.destroy')->sole()->forceFill(['status' => 'failed', 'finished_at' => now(), 'lock_key' => null])->save();
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)->and($group->taskable_id)->toBe($copy->id);

        settleTick('merged');

        expect(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->count())->toBe(2)
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Completed);
    });

    it('retries a destroy that an earlier complete could not admit, and leaves any other request on a completed group to a person', function (string $reason, int $destroys): void {
        [$group, $copy] = watchedCopy();
        $group->update(['watched_pr_state' => 'merged', 'watched_pr_completion' => 'merged', 'merged_at' => '2026-11-16T20:00:00Z', 'status' => TaskGroupStatus::Completed]);
        TaskAssistance::apply($group->refresh(), AssistanceKind::RemovalFailure, null, $reason, replaceFailure: true);

        settleTick('merged');

        expect(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->count())->toBe($destroys);
    })->with([
        'a refused destroy admission' => [RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The Node refused the destroy.', 1],
        'another request' => ['A person looks at this copy.', 0],
    ]);

    it('retries a copy read that timed out or a failed GitHub read of origin\'s branch, with no request for a person, and completes on the next tick', function (string $cause): void {
        [$group] = watchedCopy();
        if ($cause === 'github') {
            // An approval whose commit origin's branch must reach: GitHub fails that read.
            TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $group->tasks()->value('id'), 'type' => TaskCommentType::Approved,
                'body' => 'Approved.', 'author' => 'reviewer', 'commit_sha' => str_repeat('a', 40), 'posted_at' => now()]);
        } else {
            $this->ssh->answer = [...$this->ssh->answer, 'state' => 'unknown', 'reason' => 'timeout'];
        }

        settleTick('merged', new GitHubApiException('GitHub answered 500.'));

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($group->watched_pr_completion)->toBe('merged')
            ->and($group->assistance_requested)->toBeFalse()
            ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse();

        // The cause passes: the copy answers, and origin's branch is the approved commit.
        $this->ssh->answer = [...$this->ssh->answer, 'state' => 'clean', 'reason' => null];
        TaskComment::query()->where('task_group_id', $group->id)->delete();
        settleTick('merged');

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)
            ->and(Operation::query()->where('kind', 'instance.destroy')->count())->toBe(1);
    })->with(['an SSH timeout' => ['timeout'], 'a GitHub 500' => ['github']]);

    it('does not destroy a copy that a person must fix: it records the request once, never pointing to a discard, and skips the group', function (array $answer, string $request): void {
        [$group, $copy] = watchedCopy();
        $this->ssh->answer = [...$this->ssh->answer, ...$answer];

        settleTick('merged');

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($group->taskable_id)->toBe($copy->id)
            ->and($group->assistance_kind)->toBe(AssistanceKind::Failure)
            ->and($group->assistance_reason)->toContain($request)->not->toContain('discard')
            ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse();
        $reads = $this->ssh->calls;

        settleTick('merged');

        expect($this->ssh->calls)->toBe($reads)->and($group->refresh()->status)->toBe(TaskGroupStatus::Running);
    })->with([
        'uncommitted work' => [['state' => 'dirty', 'modified' => ['count' => 1, 'names' => ['a.php']]], 'Publish or remove that work'],
        'a stopped copy' => [['state' => 'unknown', 'reason' => 'stopped'], 'Start the copy'],
    ]);

    it('refuses a complete of a copy that holds work that is not published, and changes nothing', function (array $answer, string $code, array $details): void {
        [$group] = watchedCopy();
        $group->update(['status' => TaskGroupStatus::Settling]);
        $group->tasks()->update(['status' => TaskStatus::Completed]);
        $this->ssh->answer = [...$this->ssh->answer, ...$answer];

        try {
            app(CompleteTaskGroupAction::class)->execute($group->refresh());
            $this->fail('The complete destroyed unpublished work.');
        } catch (ResourceOperationException $error) {
            expect($error->errorCode)->toBe($code)->and($error->status)->toBe(409)->and($error->details)->toMatchArray($details);
        }
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Settling)
            ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse();
    })->with([
        'an uncommitted file' => [['state' => 'dirty', 'modified' => ['count' => 1, 'names' => ['a.php']]], 'tasks.copy_unpublished_work', ['uncommitted' => 1]],
        'an untracked file' => [['state' => 'dirty', 'untracked' => ['count' => 1, 'names' => ['b.txt']]], 'tasks.copy_unpublished_work', ['untracked' => 1]],
        'a stash' => [['state' => 'dirty', 'stashes' => ['count' => 1, 'names' => ['stash@{0}']]], 'tasks.copy_unpublished_work', ['stashes' => 1]],
        'a commit only another branch holds' => [['state' => 'dirty', 'commits' => ['count' => 1, 'branch_count' => 1, 'branches' => [['name' => 'experiment', 'count' => 1]], 'head' => 0]],
            'tasks.copy_unpublished_work', ['unpushed' => 1]],
        'a copy that cannot be read' => [['state' => 'unknown', 'reason' => 'stopped'], 'tasks.copy_workspace_unknown', ['reason' => 'stopped']],
    ]);
});

describe('the turn state a copy turn keeps for the task-copy RPC', function (): void {
    it('passes each earlier unsettled turn\'s thread, frozen message ID, body digest, recorded run, sent and answered, never its body', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        copyTurnEnded(copyTurn($group), 'succeeded', copyTurnAsked('mcp:1'));
        // The answer was possibly sent: its wait was cut and T3 confirmed no run of it.
        $answer = copyTurnEnded(copyTurn($group, ['prompt' => 'Blue.']), 'failed', ['state' => 'failed', 'error' => 'Bound.', 'error_code' => 'tasks.turn_timeout',
            'ended' => false, 't3_thread_id' => 'mcp:1', 'run_id' => 'run:2', 'sent' => true, 'launched' => false, 'transcript' => []]);

        $next = copyTurn($group, ['thread' => 'new']);

        $prompt = $answer->input['turn']['prompt'];
        expect($next->input['turn']['settle'])->toBe([['operation_id' => $answer->id, 'title' => $answer->input['turn']['title'], 'branch' => null,
            't3_thread_id' => 'mcp:1', 'admitted' => false, 'message_id' => $answer->input['turn']['message_id'], 'digest' => hash('sha256', $prompt), 'run_id' => 'run:2',
            'sent' => true, 'answered' => ['request:1'], 'answered_run' => 'run:1']])
            ->and(json_encode($next->input['turn']['settle'], JSON_THROW_ON_ERROR))->not->toContain('Blue.')
            // A possibly sent answer keeps the questions it answers.
            ->and(AgentThread::query()->findOrFail($answer->input['thread_id'])->questions)->toBe(copyTurnAsked('mcp:1')['questions']);
    });

    it('names an earlier turn that sent nothing as not sent, and one with no result or a result from before `sent` as possibly sent', function (string $status, array $shaped, bool $sent): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $cut = copyTurnEnded(copyTurn($group), $status, $shaped);

        expect(copyTurn($group, ['thread' => 'new'])->input['turn']['settle'][0]['sent'])->toBe($sent)
            ->and($cut->input['turn']['message_id'])->toBeString();
    })->with([
        'a reviewer cut before its send' => ['failed', ['state' => 'failed', 'error' => 'Unrestored.', 'error_code' => 'tasks.copy_review_unrestored', 'ended' => false,
            't3_thread_id' => null, 'run_id' => null, 'sent' => false, 'launched' => false, 'transcript' => []], false],
        'a cancelled turn with no result' => ['cancelled', [], true],
        'a result of the CLI driver before `sent`' => ['failed', ['state' => 'failed', 'error' => 'Bound.', 'error_code' => 'tasks.turn_timeout', 'ended' => false,
            't3_thread_id' => 'mcp:1', 'run_id' => null, 'launched' => true, 'transcript' => []], true],
    ]);

    it('marks a captured thread ID admitted, confirms it only when T3 reports it back, and marks any other report overwritten', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $first = copyTurn($group);
        $thread = AgentThread::query()->findOrFail($first->input['thread_id']);
        $admitted = $thread->external_id;

        // A turn that reported no thread leaves the admitted ID that T3 never received.
        copyTurnEnded($first, 'failed', ['state' => 'failed', 'error' => 'No end.', 'error_code' => 'tasks.copy_turn_failed', 'ended' => true,
            't3_thread_id' => null, 'run_id' => null, 'sent' => false, 'launched' => false, 'transcript' => []]);
        expect($thread->refresh()->only(['external_id', 'external_id_admission']))->toBe(['external_id' => $admitted, 'external_id_admission' => 'admitted']);

        copyTurnEnded(copyTurn($group), 'succeeded', copyTurnDone($admitted));
        expect($thread->refresh()->only(['external_id', 'external_id_admission']))->toBe(['external_id' => $admitted, 'external_id_admission' => 'confirmed']);

        // A reported ID that is not the admitted one replaces it, and stays overwritten when later turns report it again.
        copyTurnEnded(copyTurn($group), 'succeeded', copyTurnDone('mcp:other'));
        copyTurnEnded(copyTurn($group), 'succeeded', copyTurnDone('mcp:other'));
        expect($thread->refresh()->only(['external_id', 'external_id_admission']))->toBe(['external_id' => 'mcp:other', 'external_id_admission' => 'overwritten'])
            ->and(copyTurn($group)->input['turn']['admission'])->toBe('overwritten');
    });

    it('reads a row from before the mark as provisional until a turn reported a thread ID, and as overwritten after', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $first = copyTurn($group);
        $thread = AgentThread::query()->findOrFail($first->input['thread_id']);
        $thread->update(['external_id_admission' => null]);
        copyTurnEnded($first, 'failed', ['state' => 'failed', 'error' => 'No end.', 'error_code' => 'tasks.copy_turn_failed', 'ended' => true,
            't3_thread_id' => null, 'run_id' => null, 'launched' => false, 'transcript' => []]);

        expect(TaskTurn::admission($thread->refresh()))->toBe('provisional');

        $thread->update(['external_id_admission' => null]);
        $reported = copyTurn($group);
        $thread->update(['external_id_admission' => null]);
        // T3 reports the row's own ID, yet a row from before the mark has no proven provenance.
        copyTurnEnded($reported, 'succeeded', copyTurnDone($thread->external_id));
        $thread->refresh();
        expect($thread->external_id_admission)->toBe('overwritten');
        $thread->update(['external_id_admission' => null]);
        expect(TaskTurn::admission($thread->refresh()))->toBe('overwritten');
    });

    it('stores each question with the run that owns it', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $asked = copyTurnEnded(copyTurn($group), 'succeeded', copyTurnAsked('mcp:1'));

        expect(array_column(AgentThread::query()->findOrFail($asked->input['thread_id'])->questions ?? [], 'run_id', 'request_id'))->toBe(['request:1' => 'run:1']);
    });

    it('clears a thread\'s stored questions once a later turn names exactly their requests spent, and keeps them otherwise', function (array $spent, bool $cleared): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $asked = copyTurnEnded(copyTurn($group), 'succeeded', copyTurnAsked('mcp:1'));

        // A turn on another thread that launched nothing still names what an earlier answer's run used.
        copyTurnEnded(copyTurn($group, ['thread' => 'new']), 'failed', ['state' => 'failed', 'error' => 'Answered.', 'error_code' => 'tasks.copy_question_answered',
            'ended' => true, 't3_thread_id' => null, 'run_id' => null, 'sent' => false, 'launched' => false, 'spent' => $spent, 'transcript' => []]);

        expect(AgentThread::query()->findOrFail($asked->input['thread_id'])->questions)->toBe($cleared ? null : copyTurnAsked('mcp:1')['questions']);
    })->with([
        'its requests' => [['request:1'], true],
        'its requests twice' => [['request:1', 'request:1'], true],
        'another request' => [['request:2'], false],
        'a superset of its requests' => [['request:1', 'request:2'], false],
        'none' => [[], false],
    ]);

    it('keeps the stored questions when the spent requests only overlap them', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $two = [...copyTurnAsked('mcp:1'), 'questions' => [copyTurnAsked('mcp:1')['questions'][0], [...copyTurnAsked('mcp:1')['questions'][0], 'request_id' => 'request:2']]];
        $asked = copyTurnEnded(copyTurn($group), 'succeeded', $two);

        copyTurnEnded(copyTurn($group, ['thread' => 'new']), 'failed', ['state' => 'failed', 'error' => 'Answered.', 'error_code' => 'tasks.copy_question_answered',
            'ended' => true, 't3_thread_id' => null, 'run_id' => null, 'sent' => false, 'launched' => false, 'spent' => ['request:1', 'request:3'], 'transcript' => []]);

        expect(array_column(AgentThread::query()->findOrFail($asked->input['thread_id'])->questions ?? [], 'request_id'))->toBe(['request:1', 'request:2']);
    });

    it('names the thread that a cancelled earlier turn reported, not the row\'s placeholder ID', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $first = copyTurn($group);
        $placeholder = AgentThread::query()->findOrFail($first->input['thread_id'])->external_id;
        // A cancel records no report on the row, yet the turn's result names the thread and run T3 made.
        $cancelled = copyTurnEnded($first, 'cancelled', ['state' => 'failed', 'error' => 'Cancelled.', 'error_code' => 'tasks.copy_turn_failed', 'ended' => false,
            't3_thread_id' => 'mcp:real', 'run_id' => 'run:9', 'sent' => true, 'launched' => true, 'transcript' => []]);

        $settle = copyTurn($group, ['thread' => 'new'])->input['turn']['settle'];

        expect(AgentThread::query()->findOrFail($first->input['thread_id'])->external_id)->toBe($placeholder)
            ->and(Arr::only($settle[0], ['operation_id', 't3_thread_id', 'run_id']))->toBe(['operation_id' => $cancelled->id, 't3_thread_id' => 'mcp:real', 'run_id' => 'run:9']);
    });

    it('names the thread that an earlier turn froze when its result reported none, only while T3 held that ID', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        // A new thread's admitted UUID, which no T3 was seen to receive: the earlier turn passes it marked admitted, so the RPC path can
        // reconcile it and the CLI phase still finds that thread by its title.
        $first = copyTurn($group);
        copyTurnEnded($first, 'cancelled', []);
        expect($first->input['turn']['admission'])->toBe('admitted')
            ->and(Arr::only(copyTurn($group, ['thread' => 'new'])->input['turn']['settle'][0], ['t3_thread_id', 'admitted', 'sent', 'message_id']))
            ->toBe(['t3_thread_id' => $first->input['turn']['t3_thread_id'], 'admitted' => true, 'message_id' => $first->input['turn']['message_id'], 'sent' => true])
            ->and(Str::isUuid($first->input['turn']['t3_thread_id']))->toBeTrue();
    });

    it('marks a reported thread as not admitted, also when the turn froze an admitted UUID', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $first = copyTurn($group);
        copyTurnEnded($first, 'failed', ['state' => 'failed', 'error' => 'Bound.', 'error_code' => 'tasks.turn_timeout', 'ended' => false,
            't3_thread_id' => $first->input['turn']['t3_thread_id'], 'run_id' => null, 'sent' => true, 'launched' => false, 'transcript' => []]);

        expect(Arr::only(copyTurn($group, ['thread' => 'new'])->input['turn']['settle'][0], ['t3_thread_id', 'admitted']))
            ->toBe(['t3_thread_id' => $first->input['turn']['t3_thread_id'], 'admitted' => false]);
    });

    it('names the thread a continued earlier turn froze from a reported ID, and that turn\'s answered owner run', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        // The question is owned by run:question; the answer turn launched run:answer and was cut.
        copyTurnEnded(copyTurn($group), 'succeeded', copyTurnAsked('mcp:1', 'run:question'));
        $answer = copyTurn($group, ['prompt' => 'Blue.']);
        copyTurnEnded($answer, 'failed', ['state' => 'failed', 'error' => 'Bound.', 'error_code' => 'tasks.turn_timeout', 'ended' => false,
            't3_thread_id' => null, 'run_id' => 'run:answer', 'sent' => true, 'launched' => true, 'transcript' => []]);
        AgentThread::query()->findOrFail($answer->input['thread_id'])->update(['external_id' => 'mcp:later']);

        $settle = copyTurn($group, ['thread' => 'new'])->input['turn']['settle'][0];

        expect($answer->input['turn']['admission'])->toBe('overwritten')
            ->and(Arr::only($settle, ['t3_thread_id', 'run_id', 'answered', 'answered_run']))
            ->toBe(['t3_thread_id' => 'mcp:1', 'run_id' => 'run:answer', 'answered' => ['request:1'], 'answered_run' => 'run:question']);
    });

    it('keeps no worker error text or diagnostic in the result or on the thread, only the code\'s sentence', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $turn = copyTurn($group, ['prompt' => 'Acquire Juniper']);

        // An older worker quotes the whole prompt in its error and sends a diagnostic: neither is kept.
        $ended = copyTurnEnded($turn, 'failed', ['state' => 'failed', 'error' => 'T3 run failed: Invalid input: Acquire Juniper', 'error_code' => 'tasks.turn_failed',
            'ended' => true, 't3_thread_id' => 'mcp:1', 'run_id' => 'run:1', 'launched' => true, 'transcript' => [],
            'diagnostic' => ['http_status' => 429, 'retry_after_seconds' => 37, 'provider_error' => 'rate_limit_error']]);

        $thread = AgentThread::query()->findOrFail($turn->input['thread_id']);
        expect($ended->error_code)->toBe('tasks.turn_failed')
            ->and($ended->result['error'])->toBe(TaskTurnProjection::Errors['tasks.turn_failed'])
            ->and($ended->result)->not->toHaveKey('diagnostic')
            ->and($thread->error)->toBe($ended->result['error'])
            ->and(json_encode([$ended->result, $thread->error], JSON_THROW_ON_ERROR))->not->toContain('Juniper');
    });

    it('says a failed turn that has not ended holds the group, and claims no end', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));

        // A start with no status and a queued read back: the worker keeps the turn unended.
        $queued = copyTurnEnded(copyTurn($group), 'failed', ['state' => 'failed', 'error' => 'T3 started no run', 'error_code' => 'tasks.turn_failed', 'ended' => false,
            't3_thread_id' => 'mcp:1', 'run_id' => null, 'sent' => true, 'launched' => false, 'transcript' => []]);

        expect($queued->result['error'])->toBe(TaskTurnProjection::Errors['tasks.turn_failed'].TaskTurnProjection::Held)
            ->and($queued->result['error'])->not->toContain('ended other');
    });

    it('stores no question without its owner run from an older worker, and records the turn as unread', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $older = copyTurnAsked('mcp:1');
        $older['questions'] = array_map(static fn (array $question): array => Arr::except($question, 'run_id'), $older['questions']);

        $asked = copyTurnEnded(copyTurn($group), 'succeeded', $older);

        expect($asked->status)->toBe(OperationStatus::Failed)->and($asked->error_code)->toBe('tasks.copy_question_unread')
            ->and($asked->result['questions'])->toBe([])
            // Its run may still wait on that request: the turn holds the group, and the next turn settles it.
            ->and($asked->result['ended'])->toBeFalse()
            ->and(AgentThread::query()->findOrFail($asked->input['thread_id'])->questions)->toBeNull();
        // The next turn settles it first and has nothing to answer.
        $next = copyTurn($group, ['prompt' => 'Blue.']);
        expect(copyTurnSettle($next))->toBe([['operation_id' => $asked->id, 'title' => $asked->input['turn']['title'], 'branch' => null]])
            ->and($next->input['turn']['answered'])->toBe([]);
    });

    it('matches spent request IDs as strings, in any order', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $question = copyTurnAsked('mcp:1')['questions'][0];
        $asked = copyTurnEnded(copyTurn($group), 'succeeded', [...copyTurnAsked('mcp:1'), 'questions' => [[...$question, 'request_id' => '01'], [...$question, 'request_id' => '1']]]);

        copyTurnEnded(copyTurn($group, ['thread' => 'new']), 'failed', ['state' => 'failed', 'error' => 'Answered.', 'error_code' => 'tasks.copy_question_answered',
            'ended' => true, 't3_thread_id' => null, 'run_id' => null, 'sent' => false, 'launched' => false, 'spent' => ['1', '01'], 'transcript' => []]);

        expect(AgentThread::query()->findOrFail($asked->input['thread_id'])->questions)->toBeNull();
    });

    it('keeps an unrestored reviewer\'s code over an unread ownerless question', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $older = [...copyTurnAsked('mcp:1'), 'state' => 'asking_for_input', 'error' => 'Unrestored.', 'error_code' => 'tasks.copy_review_unrestored', 'ended' => false];
        $older['questions'] = array_map(static fn (array $question): array => Arr::except($question, 'run_id'), $older['questions']);

        $reviewer = copyTurnEnded(copyTurn($group, ['role' => 'reviewer', 'check' => copyCandidate($group)->id]), 'failed', $older);

        expect($reviewer->error_code)->toBe('tasks.copy_review_unrestored')->and($reviewer->result['error_code'])->toBe('tasks.copy_review_unrestored')
            ->and($reviewer->result['questions'])->toBe([])->and($reviewer->result['ended'])->toBeFalse();
    });

    it('records a code outside the copy turn\'s codes as tasks.copy_turn_failed', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));

        $ended = copyTurnEnded(copyTurn($group), 'failed', ['state' => 'failed', 'error' => 'Acquire Juniper', 'error_code' => 'tasks.acquire_juniper', 'ended' => true,
            't3_thread_id' => 'mcp:1', 'run_id' => 'run:1', 'launched' => true, 'transcript' => []]);

        expect($ended->error_code)->toBe('tasks.copy_turn_failed')->and($ended->result['error_code'])->toBe('tasks.copy_turn_failed')
            ->and($ended->result['error'])->toBe(TaskTurnProjection::Errors['tasks.copy_turn_failed'])
            ->and(json_encode($ended->result, JSON_THROW_ON_ERROR))->not->toContain('juniper')->not->toContain('Juniper');
    });

    it('stores no question that another run owns, and holds the turn', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $foreign = copyTurnAsked('mcp:1');
        $foreign['questions'] = array_map(static fn (array $question): array => [...$question, 'run_id' => 'run:B'], $foreign['questions']);

        $asked = copyTurnEnded(copyTurn($group), 'succeeded', $foreign);

        expect([$asked->status, $asked->error_code, $asked->result['questions'], $asked->result['ended']])
            ->toBe([OperationStatus::Failed, 'tasks.copy_question_unread', [], false])
            ->and(AgentThread::query()->findOrFail($asked->input['thread_id'])->questions)->toBeNull();
    });

    it('answers no stored question without an owner run, as a row from before the owner was stored holds', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $asked = copyTurnEnded(copyTurn($group), 'succeeded', copyTurnAsked('mcp:1'));
        $thread = AgentThread::query()->findOrFail($asked->input['thread_id']);
        $thread->update(['questions' => array_map(static fn (array $question): array => Arr::except($question, 'run_id'), $thread->questions ?? [])]);

        $answer = copyTurn($group, ['prompt' => 'Blue.']);

        expect([$answer->input['turn']['answered'], $answer->input['turn']['answered_run']])->toBe([[], null]);
    });
});
