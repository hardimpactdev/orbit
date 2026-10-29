<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\InstanceProvisionFailure;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Domain\TaskVms\TaskVmPackageCacheMount;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FakeTaskVmPackageCacheMount;
use Tests\Support\TaskVmSettingsBuilder;

function provisioner_app(
    string $slug,
    ?string $root = 'public',
    ProjectType $type = ProjectType::LaravelApp,
): Project {
    return Project::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
        'type' => $type,
        'default_branch' => 'main',
        'root' => $root,
    ]);
}

function provisioner_node(string $name, string $ip): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => "{$name}.test",
        'public_ssh_host' => $ip,
        'wireguard_ip' => $ip,
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $node->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);

    $node->processes()->create([
        'name' => 'pi-server',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/home/orbit',
        'runtime_config' => ['command' => ['/home/orbit/.local/bin/pi-server', 'serve', "--host={$ip}", '--port=3774']],
        'restart_policy' => 'always',
        'keep_alive' => true,
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

/** A reserved group. With `$vmNode`, the group's ready task VM serves that Node. */
function provisioner_group(Project $project, string $title = 'Workspace', ?Node $vmNode = null): Task
{
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => $title,
        'brief' => "{$title} brief",
        'status' => TaskGroupStatus::Reserved,
    ]);
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'First',
        'brief' => 'First subtask',
        'status' => TaskStatus::Todo,
    ]);

    if ($vmNode instanceof Node) {
        test_task_vm($group, $vmNode);
    }

    return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
}

function bind_task_workspace_fakes(): object
{
    $accounts = new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    };
    $destination = new class implements InstanceDestinationGuard
    {
        public function assertUnoccupied(Node $node, StoragePath $destination): void {}
    };
    $source = new class implements DevelopmentInstanceSourceLifecycle
    {
        /** @var list<string> */
        public array $calls = [];

        public ?Closure $onPrepare = null;

        public ?Closure $onResolve = null;

        public function prepare(Instance $instance, bool $allowExisting): void
        {
            $this->calls[] = 'prepare';
            if ($this->onPrepare !== null) {
                ($this->onPrepare)($instance, $allowExisting);
            }
        }

        public function inspectPrepared(Instance $instance): void
        {
            $this->calls[] = 'inspect-prepared';
        }

        public function resolve(Instance $instance): DevelopmentSourceResolution
        {
            $this->calls[] = 'resolve';

            return $this->onResolve !== null ? ($this->onResolve)($instance)
                : new DevelopmentSourceResolution($instance->name, str_repeat('a', 40));
        }

        public function inspectResolved(Instance $instance): DevelopmentSourceResolution
        {
            $this->calls[] = 'inspect-resolved';

            return new DevelopmentSourceResolution((string) $instance->branch, (string) $instance->starting_commit);
        }
    };
    $development = new class implements DevelopmentInstanceProvisioner
    {
        public int $reserves = 0;

        public int $prepares = 0;

        public int $completes = 0;

        public function reserve(Instance $instance, ?string $domain): void
        {
            $this->reserves++;
        }

        public function prepare(Instance $instance, ?string $domain): Instance
        {
            $this->prepares++;

            return $instance;
        }

        public function complete(
            Instance $instance,
            ?string $domain,
            bool $setupPending = false,
        ): Instance {
            $this->completes++;

            return $instance->refresh();
        }
    };

    app()->instance(ManagedUserAccountResolver::class, $accounts);
    app()->instance(InstanceDestinationGuard::class, $destination);
    app()->instance(DevelopmentInstanceSourceLifecycle::class, $source);
    app()->instance(DevelopmentInstanceProvisioner::class, $development);

    return (object) ['source' => $source, 'development' => $development];
}

it('task workspace stamps source_prepare_id before preparing source', function (): void {
    $project = provisioner_app('prepare-id');
    $node = provisioner_node('prepare-id-dev', '10.44.0.138');
    $group = provisioner_group($project, vmNode: $node);
    $fakes = bind_task_workspace_fakes();
    $reservation = null;
    $fakes->source->onPrepare = static function (Instance $instance, bool $allowExisting) use (&$reservation): void {
        $reservation = $instance->fresh();
    };

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($reservation?->status)->toBe(InstanceState::Reserved);
    expect($reservation?->task_workspace_owner_id)->toBe($group->id);
    expect($reservation?->source_prepare_id)->not->toBeNull()->toBeString();
    expect(Str::isUuid($reservation->source_prepare_id, version: 4))->toBeTrue();
    expect($instance?->fresh()?->source_prepare_id)->toBe($reservation->source_prepare_id);
    expect($instance?->status)->toBe(InstanceState::SourceResolved);
});

it('provisions a workspace without an automatic root dependency copy', function (): void {
    $project = provisioner_app('acme');
    $node = provisioner_node('acme-dev', '10.44.0.111');
    Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => provisioner_node('acme-host', '10.44.0.112')->id,
        'name' => 'default',
        'checkout_path' => '/srv/orbit/apps/acme/default',
        'branch' => 'main',
        'starting_commit' => str_repeat('b', 40),
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $group = provisioner_group($project, 'Copied', $node);
    bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance?->status)->toBe(InstanceState::SourceResolved);
});

it('creates a non-visitable Orbit checkout without activating a Route', function (): void {
    $project = provisioner_app('orbit');
    $node = provisioner_node('orbit-dev', '10.44.0.101');
    $group = provisioner_group($project, 'Isolated', $node);
    $fakes = bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance)->toBeInstanceOf(Instance::class)
        ->and($instance?->node_id)->toBe($node->id)
        ->and($instance?->name)->toBe(TaskWorkspaceName::for($group))
        ->and($instance?->checkout_path)->toBe('/srv/orbit/apps/orbit/workspace')
        ->and($instance?->branch_override)->toBe(TaskWorkspaceName::for($group))
        ->and($instance?->branch)->toBe(TaskWorkspaceName::for($group))
        ->and($instance?->status)->toBe(InstanceState::SourceResolved)
        ->and($instance?->task_workspace_routed)->toBeFalse()
        ->and($instance?->routes()->count())->toBe(0)
        ->and($fakes->source->calls)->toBe(['prepare', 'inspect-prepared', 'resolve', 'inspect-prepared', 'inspect-resolved', 'inspect-prepared'])
        ->and($fakes->development->reserves)->toBe(0)
        ->and($fakes->development->completes)->toBe(0);
});

it('activates a visitable workspace through the development provisioner', function (): void {
    $project = provisioner_app('shop');
    $node = provisioner_node('shop-dev', '10.44.0.102');
    $group = provisioner_group($project, 'Inspect', $node);
    $fakes = bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

    expect($instance?->node_id)->toBe($node->id)
        ->and($instance?->root)->toBe('public')
        ->and($instance?->status)->toBe(InstanceState::SourceResolved)
        ->and($instance?->task_workspace_routed)->toBeTrue()
        ->and($fakes->development->reserves)->toBe(1)
        ->and($fakes->development->prepares)->toBe(1)
        ->and($fakes->development->completes)->toBe(0);
});

it('creates a visitable Task workspace at the repository root for each package type', function (ProjectType $type): void {
    $project = provisioner_app($type->value, '.', $type);
    $node = provisioner_node($type->value.'-dev', '10.44.0.125');
    $group = provisioner_group($project, vmNode: $node);
    $fakes = bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

    expect($instance)->toBeInstanceOf(Instance::class)
        ->and($instance?->node_id)->toBe($node->id)
        ->and($instance?->root)->toBe('.')
        ->and($instance?->status)->toBe(InstanceState::SourceResolved)
        ->and($fakes->development->reserves)->toBe(1)
        ->and($fakes->development->prepares)->toBe(1)
        ->and($fakes->development->completes)->toBe(0);
})->with([
    'laravel-package' => ProjectType::LaravelPackage,
    'node-package' => ProjectType::NodePackage,
]);

it('keeps an already claimed workspace without source or cache preparation', function (): void {
    $project = provisioner_app('reuse');
    $node = provisioner_node('reuse-dev', '10.44.0.103');
    $group = provisioner_group($project, vmNode: $node);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => TaskWorkspaceName::for($group),
        'checkout_path' => '/srv/orbit/apps/reuse/workspace',
        'task_workspace_owner_id' => $group->id,
        'status' => InstanceState::Active,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $fakes = bind_task_workspace_fakes();

    expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group->fresh(['taskable']) ?? $group, true))?->id)
        ->toBe($instance->id);
    expect($fakes->source->calls)->toBe([]);
    $cacheMount = app(TaskVmPackageCacheMount::class);
    expect($cacheMount)->toBeInstanceOf(FakeTaskVmPackageCacheMount::class);
    expect($cacheMount->prepared)->toBe([]);
});

it('returns a failure when a visitable App lacks a web root', function (): void {
    $project = provisioner_app('bare', null);
    $node = provisioner_node('bare-dev', '10.44.0.104');
    $group = provisioner_group($project, vmNode: $node);
    bind_task_workspace_fakes();

    expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true)))
        ->toBeInstanceOf(InstanceProvisionFailure::class);
});

it('returns a failure when destination occupation refuses the checkout', function (): void {
    $project = provisioner_app('blocked');
    $node = provisioner_node('blocked-dev', '10.44.0.105');
    $group = provisioner_group($project, vmNode: $node);
    bind_task_workspace_fakes();
    app()->instance(InstanceDestinationGuard::class, new class implements InstanceDestinationGuard
    {
        public function assertUnoccupied(Node $node, StoragePath $destination): void
        {
            throw new ResourceOperationException(
                'instance.migration_conflict',
                'Instance destination is occupied by unmanaged data.',
                409,
            );
        }
    });

    expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false)))
        ->toBeInstanceOf(InstanceProvisionFailure::class);
});

it('resumes a reserved worktree reclaim with no starting commit', function (): void {
    $project = provisioner_app('reclaim');
    $node = provisioner_node('reclaim-dev', '10.44.0.135');
    $group = provisioner_group($project, vmNode: $node);
    $sandbox = sys_get_temp_dir().'/orbit-reserved-reclaim-'.Str::uuid();
    $seed = $sandbox.'/default';
    $checkout = $sandbox.'/'.TaskWorkspaceName::for($group);
    $files = new Filesystem;
    $files->makeDirectory($seed, 0o755, true);

    try {
        foreach ([
            ['git', 'init', '--initial-branch=main', $seed],
            ['git', '-C', $seed, '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '--allow-empty', '-m', 'Seed'],
            ['git', '-C', $seed, 'worktree', 'add', '--detach', $checkout, 'HEAD'],
        ] as $command) {
            (new Process($command))->mustRun();
        }
        $commit = trim((new Process(['git', '-C', $seed, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
        $left = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => TaskWorkspaceName::for($group),
            'checkout_path' => $checkout,
            'branch_override' => TaskWorkspaceName::for($group),
            'task_workspace_owner_id' => $group->id,
            'source_layout' => InstanceSourceLayout::Worktree,
            'seed_repository' => $seed,
            'seed_commit' => $commit,
            'starting_commit' => null,
            'source_prepare_id' => null,
            'task_workspace_routed' => false,
            'status' => InstanceState::Reserved,
        ]);
        $fakes = bind_task_workspace_fakes();
        // Model the remote prepare contract: an existing checkout refuses unless explicitly allowed.
        $fakes->source->onPrepare = static function (Instance $instance, bool $allowExisting): void {
            if (is_file($instance->checkout_path.'/.git') && ! $allowExisting) {
                throw new ResourceOperationException('instance.clone_failed', 'Checkout already exists.', 409);
            }
        };

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

        expect($instance?->id)->toBe($left->id)
            ->and($instance?->status)->toBe(InstanceState::SourceResolved)
            ->and($instance?->starting_commit)->toBe(str_repeat('a', 40))
            ->and($instance?->source_prepare_id)->toBeNull();
        expect($fakes->source->calls)->toBe(['prepare', 'inspect-prepared', 'resolve', 'inspect-prepared', 'inspect-resolved', 'inspect-prepared']);
        expect(is_file($checkout.'/.git'))->toBeTrue();
        $this->assertDatabaseCount('instances', 1);
    } finally {
        $files->deleteDirectory($sandbox);
    }
});

it('refuses a reserved worktree reclaim when source identity does not match', function (): void {
    $project = provisioner_app('foreign-reclaim');
    $node = provisioner_node('foreign-dev', '10.44.0.137');
    $group = provisioner_group($project, vmNode: $node);
    $left = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => TaskWorkspaceName::for($group),
        'checkout_path' => '/srv/orbit/apps/foreign-reclaim/'.TaskWorkspaceName::for($group),
        'branch_override' => TaskWorkspaceName::for($group),
        'task_workspace_owner_id' => $group->id,
        'source_layout' => InstanceSourceLayout::Worktree,
        'seed_repository' => '/srv/orbit/apps/foreign-reclaim/default',
        'seed_commit' => str_repeat('b', 40),
        'status' => InstanceState::Reserved,
    ]);
    $fakes = bind_task_workspace_fakes();
    $fakes->source->onPrepare = static function (Instance $instance, bool $allowExisting): void {
        throw new ResourceOperationException('instance.clone_failed', 'Source identity does not match.', 409);
    };

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance)->toBeInstanceOf(InstanceProvisionFailure::class)
        ->and($instance->cause)->toBe(ResourceOperationException::class.' [instance.clone_failed]: Source identity does not match.');
    expect($left->refresh()->status)->toBe(InstanceState::Reserved)
        ->and($left->starting_commit)->toBeNull();
    expect($fakes->source->calls)->toBe(['prepare']);
    $this->assertModelExists($left);
});

it('does not allow_existing task provision for a newly reserved Instance', function (): void {
    $project = provisioner_app('fresh-reclaim');
    $node = provisioner_node('fresh-dev', '10.44.0.136');
    $group = provisioner_group($project, vmNode: $node);
    $fakes = bind_task_workspace_fakes();
    $fakes->source->onPrepare = static function (Instance $instance, bool $allowExisting): void {
        if ($allowExisting) {
            throw new ResourceOperationException('instance.clone_failed', 'Cannot adopt an existing checkout on first prepare.', 409);
        }
    };

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance?->status)->toBe(InstanceState::SourceResolved);
});

describe('a workspace an interrupted claim left unattached', function (): void {
    it('never adopts a user Instance named and branched like the workspace', function (): void {
        $project = provisioner_app('orbit');
        $node = provisioner_node('only', '10.44.0.134');
        $group = provisioner_group($project, vmNode: $node);
        $name = TaskWorkspaceName::for($group);
        $lookalike = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => provisioner_node('user-dev', '10.44.0.135')->id,
            'name' => $name,
            'checkout_path' => '/srv/orbit/apps/orbit/'.$name,
            'branch_override' => $name,
            'status' => InstanceState::SourceResolved,
        ]);
        bind_task_workspace_fakes();

        expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false)))->toBeInstanceOf(InstanceProvisionFailure::class)
            ->cause->toContain('[instance.name_taken]')
            ->and($lookalike->fresh()?->task_workspace_owner_id)->toBeNull()
            ->and($lookalike->fresh()?->status)->toBe(InstanceState::SourceResolved);
        $this->assertDatabaseCount('instances', 1);
    });
});

/**
 * @return array{0: Task, 1: Instance, 2: string}
 */
function orbit_workspace_clone(): array
{
    $project = provisioner_app('orbit', type: ProjectType::Monorepo);
    $node = provisioner_node('orbit-clone', '10.44.0.181');
    $group = provisioner_group($project, 'Clone', $node);
    bind_task_workspace_fakes();
    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));
    expect($instance)->toBeInstanceOf(Instance::class)
        ->and($instance->status)->toBe(InstanceState::SourceResolved)
        ->and($instance->routes()->count())->toBe(0);
    // The task VM is already gone, so removal falls back to the generic Instance remover.
    TaskVm::query()->where('group_id', $group->id)->update(['state' => TaskVmState::Destroyed]);

    $checkout = sys_get_temp_dir().'/orbit-task-'.$instance->id;
    if (! is_dir($checkout)) {
        mkdir($checkout);
    }
    file_put_contents($checkout.'/KEEP', 'clone');
    $instance->update(['checkout_path' => $checkout]);

    return [$group->fresh(['project', 'taskable']) ?? $group, $instance->fresh() ?? $instance, $checkout];
}

function bind_checkout_remover(): void
{
    app()->instance(InstanceRemover::class, new class implements InstanceRemover
    {
        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            expect($force)->toBeTrue();
            $path = $instance->checkout_path;
            if (is_string($path) && is_file($path.'/KEEP')) {
                unlink($path.'/KEEP');
            }
            if (is_string($path) && is_dir($path)) {
                rmdir($path);
            }
            $instance->delete();

            return new InstanceRemoval;
        }
    });
}

function forget_checkout(string $checkout): void
{
    if (is_file($checkout.'/KEEP')) {
        unlink($checkout.'/KEEP');
    }
    if (is_dir($checkout)) {
        rmdir($checkout);
    }
}

it('removes the orbit workspace clone on cancel and on complete', function (string $operation): void {
    app(TaskExtensionState::class)->enable();
    bind_task_node_reachability();
    [$group, $instance, $checkout] = orbit_workspace_clone();
    if ($operation !== 'unattached') {
        $group->taskable()->associate($instance);
    }
    $group->status = $operation === 'complete' ? TaskGroupStatus::Settling : TaskGroupStatus::Running;
    if ($operation === 'complete') {
        $group->pr_url = 'https://github.com/hardimpactdev/orbit/pull/120';
    }
    $group->save();
    bind_checkout_remover();

    try {
        $result = $operation === 'complete'
            ? app(CompleteTaskGroupAction::class)->execute($group)
            : app(CancelTaskGroupAction::class)->execute($group);

        expect($result->status)->toBe($operation === 'complete' ? TaskGroupStatus::Completed : TaskGroupStatus::Cancelled)
            ->and($result->taskable_id)->toBeNull()
            ->and(is_dir($checkout))->toBeFalse()
            ->and(Instance::query()->whereKey($instance->id)->exists())->toBeFalse();
    } finally {
        forget_checkout($checkout);
    }
})->with(['cancel', 'complete', 'unattached']);

it('keeps the source-resolved orbit clone and asks for assistance when removal is refused', function (string $operation): void {
    app(TaskExtensionState::class)->enable();
    bind_task_node_reachability();
    [$group, $instance, $checkout] = orbit_workspace_clone();
    if ($operation !== 'unattached') {
        $group->taskable()->associate($instance);
    }
    $group->status = $operation === 'complete' ? TaskGroupStatus::Settling : TaskGroupStatus::Running;
    $group->save();
    app()->instance(InstanceRemover::class, new class implements InstanceRemover
    {
        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            throw new ResourceOperationException('instance.force_failed', 'The checkout could not be inspected.', 409);
        }
    });

    try {
        if ($operation === 'complete') {
            $completed = app(CompleteTaskGroupAction::class)->execute($group);

            expect($completed->status)->toBe(TaskGroupStatus::Completed)
                ->and($completed->taskable_id)->toBe($instance->id)
                ->and($completed->assistance_requested)->toBeTrue()
                ->and($completed->assistance_reason)->toBe(RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The checkout could not be inspected.')
                ->and(is_dir($checkout))->toBeTrue()
                ->and(file_get_contents($checkout.'/KEEP'))->toBe('clone')
                ->and(Instance::query()->whereKey($instance->id)->exists())->toBeTrue();

            return;
        }

        expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
            ->toThrow(ResourceOperationException::class, 'The checkout could not be inspected.');

        $fresh = $group->fresh();
        expect(is_dir($checkout))->toBeTrue()
            ->and(file_get_contents($checkout.'/KEEP'))->toBe('clone')
            ->and(Instance::query()->whereKey($instance->id)->exists())->toBeTrue()
            ->and($fresh?->taskable_id)->toBe($operation === 'unattached' ? null : $instance->id)
            ->and($fresh?->assistance_requested)->toBeTrue()
            ->and($fresh?->assistance_reason)->toBe(RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The checkout could not be inspected.')
            ->and($fresh?->status)->toBe(TaskGroupStatus::Running);
    } finally {
        forget_checkout($checkout);
    }
})->with(['cancel', 'complete', 'unattached']);

describe('task VMs', function (): void {

    it('creates the unrouted workspace of an Orbit vm group on its ready task VM Node, like any Project', function (): void {
        $project = provisioner_app('orbit', '.', ProjectType::Monorepo);
        $host = provisioner_node('beast', '10.44.0.7');
        $vmNode = provisioner_node('tvm-1', '10.44.0.129');
        $group = provisioner_group($project);
        TaskVm::query()->create(['group_id' => $group->id, 'host_node_id' => $host->id, 'node_id' => $vmNode->id, 'provider' => 'incus',
            'name' => 'tvm-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.129', 'pi_token' => str_repeat('p', 64)]);
        bind_task_workspace_fakes();

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group->fresh() ?? $group, false));

        expect($instance)->toBeInstanceOf(Instance::class)
            ->and($instance?->node_id)->toBe($vmNode->id)
            ->and($instance?->name)->toBe(TaskWorkspaceName::for($group))
            ->and($instance?->task_workspace_routed)->toBeFalse();
    });

    it('creates the workspace of a web vm group on its ready task VM Node, and waits before that', function (): void {
        $project = provisioner_app('dlf');
        $host = provisioner_node('beast', '10.44.0.7');
        $group = provisioner_group($project);
        $vm = TaskVm::query()->create(['group_id' => $group->id, 'host_node_id' => $host->id, 'provider' => 'incus',
            'name' => 'tvm-1', 'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.129', 'pi_token' => str_repeat('p', 64)]);
        $fakes = bind_task_workspace_fakes();

        expect(fn () => app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group->fresh() ?? $group, true)))
            ->toThrow(TaskCapacityException::class, 'Task VM: provisioning.');

        $vmNode = provisioner_node('tvm-1', '10.44.0.129');
        $vm->update(['state' => TaskVmState::Ready, 'node_id' => $vmNode->id]);
        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group->fresh() ?? $group, true));

        expect($instance?->node_id)->toBe($vmNode->id)
            ->and($instance?->name)->toBe(TaskWorkspaceName::for($group))
            // A task VM holds one workspace at a fixed path.
            ->and($instance?->checkout_path)->toBe('/srv/orbit/apps/dlf/workspace')
            ->and($instance?->status)->toBe(InstanceState::SourceResolved)
            ->and($fakes->development->prepares)->toBe(1)
            ->and($fakes->development->completes)->toBe(0);
    });

    it('stops a routed task VM workspace before its Route, and completes the Route later', function (): void {
        $project = provisioner_app('dlf');
        $host = provisioner_node('beast', '10.44.0.7');
        $vmNode = provisioner_node('tvm-1', '10.44.0.129');
        $group = provisioner_group($project);
        TaskVm::query()->create(['group_id' => $group->id, 'host_node_id' => $host->id, 'node_id' => $vmNode->id, 'provider' => 'incus',
            'name' => 'tvm-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.129', 'pi_token' => str_repeat('p', 64)]);
        $fakes = bind_task_workspace_fakes();
        $provisioner = app(TaskWorkspaceProvisioner::class);
        $instance = $provisioner->provision(new InstanceProvisionIntent($group->fresh() ?? $group, true));
        expect($instance)->toBeInstanceOf(Instance::class);
        assert($instance instanceof Instance);

        expect($provisioner->pending($group->fresh(['taskable']) ?? $group))->toBeFalse();
        $group->taskable()->associate($instance)->save();
        $attached = $group->fresh(['taskable']) ?? $group;

        expect($provisioner->pending($attached))->toBeTrue()
            ->and($provisioner->complete($attached))->toBeNull()
            ->and($fakes->development->completes)->toBe(1);

        // An unrouted workspace never waits for a Route.
        $instance->update(['task_workspace_routed' => false]);
        expect($provisioner->pending($group->fresh(['taskable']) ?? $group))->toBeFalse()
            ->and($provisioner->complete($group->fresh(['taskable']) ?? $group))->toBeNull()
            ->and($fakes->development->completes)->toBe(1);
    });

    it('returns a failed Route as a failure and leaves the workspace pending', function (): void {
        $project = provisioner_app('dlf');
        $host = provisioner_node('beast', '10.44.0.7');
        $vmNode = provisioner_node('tvm-1', '10.44.0.129');
        $group = provisioner_group($project);
        TaskVm::query()->create(['group_id' => $group->id, 'host_node_id' => $host->id, 'node_id' => $vmNode->id, 'provider' => 'incus',
            'name' => 'tvm-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.129', 'pi_token' => str_repeat('p', 64)]);
        bind_task_workspace_fakes();
        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group->fresh() ?? $group, true));
        assert($instance instanceof Instance);
        $group->taskable()->associate($instance)->save();
        app()->instance(DevelopmentInstanceProvisioner::class, new class implements DevelopmentInstanceProvisioner
        {
            public function reserve(Instance $instance, ?string $domain): void {}

            public function prepare(Instance $instance, ?string $domain): Instance
            {
                return $instance;
            }

            public function complete(Instance $instance, ?string $domain, bool $setupPending = false): Instance
            {
                throw new RuntimeConvergenceException('router', 'app-dev.projection_busy', 'Another development projection operation is active.');
            }
        });
        app()->forgetInstance(TaskWorkspaceProvisioner::class);
        $provisioner = app(TaskWorkspaceProvisioner::class);
        $attached = $group->fresh(['taskable']) ?? $group;

        expect($provisioner->complete($attached)?->cause)->toContain('[router]', 'Another development projection operation is active.')
            ->and($provisioner->pending($group->fresh(['taskable']) ?? $group))->toBeTrue();
    });

    it('creates a fresh workspace without template operations and logs each part as a task VM step', function (): void {
        Log::spy();
        Queue::fake();
        $project = provisioner_app('dlf');
        $host = provisioner_node('beast', '10.44.0.7');
        $vmNode = provisioner_node('tvm-1', '10.44.0.129');
        $group = provisioner_group($project);
        TaskVm::query()->create(['group_id' => $group->id, 'host_node_id' => $host->id, 'node_id' => $vmNode->id, 'provider' => 'incus',
            'name' => 'tvm-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.129', 'pi_token' => str_repeat('p', 64)]);
        TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id, ['maxVms' => 4])]]);
        $fakes = bind_task_workspace_fakes();

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group->fresh() ?? $group, true));
        assert($instance instanceof Instance);
        $group->taskable()->associate($instance)->save();
        app(TaskWorkspaceProvisioner::class)->complete($group->fresh(['taskable']) ?? $group);

        expect($fakes->source->calls)->toContain('prepare', 'resolve');
        Queue::assertNothingPushed();
        $cacheMount = app(TaskVmPackageCacheMount::class);
        expect($cacheMount)->toBeInstanceOf(FakeTaskVmPackageCacheMount::class);
        expect($cacheMount->prepared)->toBe([['vm' => 'tvm-1', 'project_id' => $project->id]]);
        foreach (['workspace-package-cache', 'workspace-source', 'workspace-prepare', 'workspace', 'workspace-route'] as $step) {
            Log::shouldHaveReceived('info')->with('Task VM step done.', Mockery::on(static fn (array $context): bool => $context['task_vm'] === 'tvm-1' && $context['step'] === $step));
        }
    });
});

it('seeds task VM package caches after source resolution has observed the lockfiles', function (): void {
    $project = provisioner_app('cache-order');
    $node = provisioner_node('tvm-cache', '10.44.0.129');
    $group = provisioner_group($project, vmNode: $node);
    $fakes = bind_task_workspace_fakes();
    $mount = new class($fakes) implements TaskVmPackageCacheMount
    {
        public bool $seeded = false;

        public function __construct(private object $fakes) {}

        public function prepare(TaskVm $vm, Project $project): void
        {
            expect($this->fakes->source->calls)->toContain('resolve');
            expect($this->fakes->development->prepares)->toBe(0);
            $this->seeded = true;
        }
    };
    app()->instance(TaskVmPackageCacheMount::class, $mount);

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

    expect($instance)->toBeInstanceOf(Instance::class);
    expect($mount->seeded)->toBeTrue();
});

it('retains a seeded private local branch without changing the requested task branch', function (): void {
    $project = provisioner_app('private-branch');
    $node = provisioner_node('private-branch-dev', '10.44.0.139');
    $group = provisioner_group($project, vmNode: $node);
    $fakes = bind_task_workspace_fakes();
    $fakes->source->onPrepare = static function (Instance $instance): void {
        $instance->update(['seed_commit' => str_repeat('a', 40), 'seed_repository' => '/srv/orbit/apps/private-branch/default',
            'source_layout' => 'worktree']);
    };
    $fakes->source->onResolve = static fn (Instance $instance): DevelopmentSourceResolution => new DevelopmentSourceResolution('orbit/instance/'.$instance->source_prepare_id, str_repeat('a', 40));

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance?->status)->toBe(InstanceState::SourceResolved);
    expect($instance?->branch)->toBe('orbit/instance/'.$instance?->source_prepare_id);
    expect($instance?->branch_override)->toBe(TaskWorkspaceName::for($group));
});
