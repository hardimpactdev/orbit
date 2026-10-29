<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Actions\Instances\SelectInstanceSeedAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\DevelopmentInstanceBranchInspector;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\TaskVms\TaskVmGit;
use App\Models\Instance;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final readonly class RemoteDevelopmentInstanceSourceLifecycle implements DevelopmentInstanceBranchInspector, DevelopmentInstanceSourceLifecycle
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
        private AgentCommandRunner $agents,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $removal,
        private RepositoryReadAccess $access,
        private TaskVmGit $git,
    ) {}

    public function prepare(Instance $instance, bool $allowExisting): void
    {
        app(SelectInstanceSeedAction::class)->execute($instance);
        if ($instance->seed_commit !== null) {
            $instance->update(['source_layout' => InstanceSourceLayout::Worktree->value]);
        }
        $taskVm = $this->onTaskVm($instance);
        $this->run($instance, 'instance.prepare-development-source', [
            ...$this->source($instance),
            'allow_existing' => $allowExisting,
            'seed_commit' => $instance->seed_commit,
            'network' => ! $taskVm,
            'git_read' => $this->readAccess($instance, $taskVm),
        ], step: 'app-instance-source-prepare', errorCode: 'instance.clone_failed');
    }

    public function inspectPrepared(Instance $instance): void
    {
        $this->inspect($instance, 'app-instance-source-inspect');
    }

    public function resolve(Instance $instance): DevelopmentSourceResolution
    {
        $source = $this->source($instance);
        $defaultBranch = $this->defaultBranch($instance);
        $taskVm = $this->onTaskVm($instance);
        if ($taskVm) {
            $this->sendToTaskVm($instance, $defaultBranch);
        }
        $result = $this->run($instance, 'instance.resolve-development-source', [
            ...$source,
            'instance_name' => $instance->name,
            'default_branch' => $defaultBranch,
            'branch_override' => $instance->branch_override,
            'seed_commit' => $instance->seed_commit,
            'network' => ! $taskVm,
            'protocols' => array_values(array_filter(Config::array('orbit.git.checkout_protocols', ['https']), is_string(...))),
            'git_read' => $this->readAccess($instance, $taskVm),
        ], step: 'app-instance-source-resolve', errorCode: 'instance.branch_resolution_failed');

        if (! $taskVm && $this->importsCiCaches($instance)) {
            try {
                $project = $instance->project;
                $tips = $this->git->fetch($project, [$defaultBranch], $this->access->for($source['repository'], $project->source_access), 'instance.ci_caches_unavailable');
                $this->git->send($project, $instance->node, $instance->checkout_path, $tips, fn (RemoteCommand $command): CommandResult => $this->ssh->execute($instance->node, $command, 'instance-ci-cache-transfer', 'instance.ci_caches_unavailable'), 'instance.ci_caches_unavailable', cachesOnly: true);
            } catch (ProcessCancelledException $exception) {
                throw $exception;
            } catch (\RuntimeException) {
                Log::notice('Instance CI cache transfer unavailable; setup continues cold.', ['instance_id' => $instance->id]);
            }
        }

        return $this->resolution($result['branch'] ?? null, $result['commit'] ?? null, $instance);
    }

    public function assertBranchCheckedOut(Instance $instance, ?string $branch): void
    {
        try {
            $result = $this->inspect($instance, 'app-instance-rename-inspect', verifyOrigin: false, requireIdle: true);
        } catch (AgentCommandRefused $busy) {
            throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance is busy with another lifecycle operation.', 409, previous: $busy);
        }
        $origin = $result['origin'] ?? null;
        $current = $result['branch'] ?? null;
        try {
            $valid = is_string($origin) && GitRepositoryIdentity::derive($origin) === $instance->project->repository_identity;
        } catch (InvalidArgumentException) {
            $valid = false;
        }
        if (! $valid) {
            throw new RuntimeConvergenceException('app-instance-rename-inspect', 'instance.source_identity_invalid', 'The checkout origin does not match the Project repository.');
        }
        if ($branch !== null && $current !== $branch) {
            throw new ResourceOperationException('instance.branch_not_checked_out', 'HEAD is not on the requested branch.', 409);
        }
    }

    public function inspectResolved(Instance $instance): DevelopmentSourceResolution
    {
        $result = $this->inspect($instance, 'app-instance-source-inspect-resolved');

        return $this->resolution($result['branch'] ?? null, $result['commit'] ?? null, $instance);
    }

    /**
     * Checks the prepared checkout and reads its origin, branch, and commit. Only `requireIdle` lets a busy checkout
     * through, as the refusal for its caller to map.
     *
     * @return array<string, mixed>
     */
    private function inspect(Instance $instance, string $step, bool $verifyOrigin = true, bool $requireIdle = false): array
    {
        $input = [...$this->source($instance), 'verify_origin' => $verifyOrigin, 'require_idle' => $requireIdle];

        return $this->run($instance, 'instance.inspect-development-source', $input, $step, 'instance.source_identity_invalid', busy: $requireIdle);
    }

    /**
     * Runs a development source command. With `busy`, a checkout another operation holds stays the refusal, for the
     * caller to map.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(Instance $instance, string $command, array $input, string $step, string $errorCode, bool $busy = false): array
    {
        try {
            return AgentStep::run($this->agents, $instance->node, $command, $input, $step, $errorCode, 'App development');
        } catch (RuntimeConvergenceException $exception) {
            $refused = AgentStep::refusal($exception);
            throw $busy && $refused?->errorCode === 'instance.source_busy' ? $refused : $exception;
        }
    }

    /** A task VM gets no GitHub token: it fetches nothing itself, and resolve sends its branches from the Gateway's mirror. */
    private function onTaskVm(Instance $instance): bool
    {
        return TaskVm::forNode($instance->node) instanceof TaskVm;
    }

    private function importsCiCaches(Instance $instance): bool
    {
        return ProjectLifecycleStep::query()->where('project_id', $instance->project_id)->where('command', LifecycleStep::ImportCiCaches)->exists();
    }

    private function readAccess(Instance $instance, bool $taskVm): GitReadEnvironment
    {
        return $taskVm ? GitReadEnvironment::none() : $this->access->for($instance->project->repository_url, $instance->project->source_access);
    }

    /**
     * Sends the default branch and the Instance's branch, when the repository has it, to the checkout on the task VM
     * ([Git on a task VM](/reference/compute-drivers#send-branches-to-the-vm)).
     */
    private function sendToTaskVm(Instance $instance, string $defaultBranch): void
    {
        $project = $instance->project;
        $tips = $this->git->fetch($project, [$defaultBranch, $this->selectedBranch($instance)], $this->access->for($project->repository_url, $project->source_access), 'instance.branch_resolution_failed');
        if ($tips[$defaultBranch] === null) {
            throw new RuntimeConvergenceException('app-instance-source-resolve', 'instance.branch_resolution_failed', "The repository of Instance [{$instance->name}] has no default branch [{$defaultBranch}].");
        }
        $owner = Task::topLevel()->find($instance->task_workspace_owner_id);
        $selectBaseline = $owner instanceof Task && ! $owner->reviewsIncomingPullRequest()
            && $instance->seed_commit === null && $tips[$this->selectedBranch($instance)] === null
            && $this->importsCiCaches($instance);
        $this->git->send($project, $instance->node, $instance->checkout_path, $tips, fn (RemoteCommand $command): CommandResult => $this->ssh->execute($instance->node, $command, 'app-instance-source-resolve', 'instance.branch_resolution_failed'), 'instance.branch_resolution_failed', selectBaseline: $selectBaseline);
    }

    private function defaultBranch(Instance $instance): string
    {
        $defaultBranch = $instance->project->default_branch;

        if (! is_string($defaultBranch) || ! GitBranchName::isValid($defaultBranch)) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-resolve',
                errorCode: 'instance.branch_resolution_failed',
                message: "Instance [{$instance->name}] has incomplete App source defaults.",
            );
        }

        return $defaultBranch;
    }

    /**
     * The checkout every source command works on, and the identity it must have.
     *
     * @return array{repository: string, checkout: string|null, root: string, user: string, group: string, prepare_id: string|null, seed_repository: string|null}
     */
    private function source(Instance $instance): array
    {
        $instance->loadMissing(['project', 'node']);

        if ($instance->source_layout !== InstanceSourceLayout::Checkout->value && ! ($instance->source_layout === InstanceSourceLayout::Worktree->value && $instance->seed_repository !== null)) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-layout',
                errorCode: 'instance.source_layout_conflict',
                message: "Instance [{$instance->name}] does not own an independent checkout.",
            );
        }

        $account = $this->accounts->resolve($instance->node);
        $root = $this->removal->instanceRoot($instance, $account);

        return [
            'repository' => GitRepositoryOrigin::validate($instance->project->repository_url),
            'checkout' => $instance->checkout_path,
            'root' => $root->value,
            'user' => $account->user,
            'group' => $account->group,
            'prepare_id' => $instance->source_prepare_id,
            'seed_repository' => $instance->seed_repository,
        ];
    }

    private function resolution(mixed $branch, mixed $commit, Instance $instance): DevelopmentSourceResolution
    {
        if (
            ! is_string($branch) || ! DevelopmentSourceResolution::matchesBranch($branch, $this->selectedBranch($instance), $instance->seed_commit, $instance->source_prepare_id)
            || ! is_string($commit)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1
        ) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-result',
                errorCode: 'instance.source_identity_invalid',
                message: "Instance [{$instance->name}] returned invalid source evidence.",
            );
        }

        return new DevelopmentSourceResolution($branch, $commit);
    }

    private function selectedBranch(Instance $instance): string
    {
        $branch = is_string($instance->branch_override)
            ? $instance->branch_override
            : ($instance->name === 'default' ? $this->defaultBranch($instance) : $instance->name);

        if (! GitBranchName::isValid($branch)) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-resolve',
                errorCode: 'instance.branch_resolution_failed',
                message: "Instance [{$instance->name}] has an invalid branch selection.",
            );
        }

        return $branch;
    }
}
