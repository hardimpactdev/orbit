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
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\SourceControl\WorkspaceGit;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\TaskVms\TaskVmGit;
use App\Models\Instance;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final readonly class RemoteDevelopmentInstanceSourceLifecycle implements DevelopmentInstanceBranchInspector, DevelopmentInstanceSourceLifecycle
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $removal,
        private RepositoryReadAccess $access,
        private TaskVmGit $git,
    ) {}

    public function prepare(Instance $instance, bool $allowExisting, ?string $template = null): void
    {
        app(SelectInstanceSeedAction::class)->execute($instance);
        if ($instance->seed_commit !== null) {
            $instance->update(['source_layout' => InstanceSourceLayout::Worktree->value]);
        }
        $context = $this->context($instance);
        $taskVm = $this->onTaskVm($instance);
        $script = GitReadScript::for($this->readAccess($instance, $context['repository'], $taskVm), self::preparedRepositoryGuard($instance->source_prepare_id, $instance->seed_repository).<<<'BASH'
                    repository=$1
                    checkout=$2
                    allowed_root=$3
                    managed_user=$4
                    managed_group=$5
                    allow_existing=$6
                    seed_repository=$7
                    seed_commit=$8
                    template=$9
                    network=${10}
                    checkout_parent=$(dirname "$checkout")

                    guard_parent_chain "$checkout_parent" "$allowed_root"
                    create_directory "$allowed_root"
                    create_directory "$checkout_parent"

                    # A task VM's template is built at the checkout path itself. Until a preparation records itself in
                    # it, that checkout is the template, taken in place.
                    in_place=0
                    if [ -n "$template" ] && [ "$template" = "$checkout" ] && [ -d "$checkout" ] && [ ! -L "$checkout" ] \
                        && ! [ -e "$(git -c core.fsmonitor=false -C "$checkout" rev-parse --absolute-git-dir 2>/dev/null)/orbit-source-prepare" ]; then
                        in_place=1
                    fi
                    if [ "$in_place" = 0 ] && { [ -e "$checkout" ] || [ -L "$checkout" ]; }; then
                        test "$allow_existing" = 1
                        inspect_prepared_repository
                        exit 0
                    fi

                    # A template on the checkout's filesystem becomes the checkout in one rename, with its dependencies,
                    # or in place when it is already there. A template elsewhere that fails a check is left alone, and
                    # the checkout is cloned as without one. A template in place that fails a check fails preparation.
                    usable_template() {
                        [ -n "$template" ] && [ -d "$template" ] && [ ! -L "$template" ] \
                            && [ "$(dirname "$template")" = "$checkout_parent" ] \
                            && [ "$(stat -c '%U:%G' "$template")" = "$managed_user:$managed_group" ] \
                            && [ "$(git -C "$template" rev-parse --show-toplevel 2>/dev/null)" = "$template" ] \
                            && [ "$(git -C "$template" config --get remote.origin.url 2>/dev/null)" = "$repository" ]
                    }
                    if usable_template; then
                        if [ "$in_place" = 0 ]; then
                            mv -T -- "$template" "$checkout"
                        fi
                        chmod 0755 -- "$checkout"
                    elif [ "$in_place" = 1 ]; then
                        printf 'The workspace template at the checkout path failed its source checks.\n' >&2
                        exit 1
                    else
                        if [ -n "$template" ] && { [ -e "$template" ] || [ -L "$template" ]; }; then
                            printf '%s\n' template-refused
                        fi
                        mkdir -m 0755 -- "$checkout"
                    fi
                    if [ -d "$checkout/.git" ]; then
                        :
                    elif [ -n "$seed_repository" ]; then
                        test ! -L "$seed_repository"
                        test "$(git -C "$seed_repository" config --get remote.origin.url)" = "$repository"
                        git -c core.hooksPath=/dev/null -C "$seed_repository" worktree add --no-checkout --detach -- "$checkout" "$seed_commit"
                    elif [ "$network" = 1 ]; then
                        git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false clone --no-checkout --origin origin -- "$repository" "$checkout"
                    else
                        # A task VM gets no token. Resolve sends its branches from the Gateway's mirror.
                        git init --quiet -- "$checkout"
                        git -C "$checkout" remote add origin "$repository"
                    fi
                    git_directory=$(git -C "$checkout" rev-parse --absolute-git-dir)
                    if [ -n "$prepare_id" ]; then
                        (umask 077; set -C; printf '%s:%s\n' "$prepare_id" "$(stat -c '%d:%i' "$checkout")" > "$git_directory/orbit-source-prepare")
                    fi
                    inspect_prepared_repository
                    BASH);
        $result = $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: [...$this->arguments($instance, $context), $allowExisting ? '1' : '0', $instance->seed_repository ?? '', $instance->seed_commit ?? '', $template ?? '', $taskVm ? '0' : '1'],
                input: $script->input,
                protectedInput: $script->protectedInput,
            ),
            step: 'app-instance-source-prepare',
            errorCode: 'instance.clone_failed',
        );
        if (in_array('template-refused', explode("\n", $result->stdout), true)) {
            Log::warning('The workspace template failed its source checks; the checkout is a fresh clone.', ['instance_id' => $instance->id, 'template' => $template]);
        }
    }

    public function inspectPrepared(Instance $instance): void
    {
        $context = $this->context($instance);
        $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: $this->arguments($instance, $context),
                input: self::preparedRepositoryGuard($instance->source_prepare_id, $instance->seed_repository).<<<'BASH'
                    repository=$1
                    checkout=$2
                    allowed_root=$3
                    managed_user=$4
                    managed_group=$5
                    checkout_parent=$(dirname "$checkout")

                    guard_parent_chain "$checkout_parent" "$allowed_root"
                    inspect_prepared_repository
                    BASH,
            ),
            step: 'app-instance-source-inspect',
            errorCode: 'instance.source_identity_invalid',
        );
    }

    public function resolve(Instance $instance): DevelopmentSourceResolution
    {
        $context = $this->context($instance);
        $defaultBranch = $this->defaultBranch($instance);
        $taskVm = $this->onTaskVm($instance);
        if ($taskVm) {
            $this->sendToTaskVm($instance, $defaultBranch);
        }
        $script = GitReadScript::for($this->readAccess($instance, $context['repository'], $taskVm), self::preparedRepositoryGuard($instance->source_prepare_id, $instance->seed_repository).WorkspaceGit::fetchPreamble().<<<'BASH'
                    repository=$1
                    checkout=$2
                    allowed_root=$3
                    managed_user=$4
                    managed_group=$5
                    instance_name=$6
                    default_branch=$7
                    branch_override=$8
                    seed_commit=$9
                    network=${10}
                    checkout_parent=$(dirname "$checkout")

                    guard_parent_chain "$checkout_parent" "$allowed_root"
                    inspect_prepared_repository
                    if [ "$network" = 1 ]; then
                        checkout_fetch -C "$checkout" fetch --quiet --no-tags --prune -- "$repository" \
                            '+refs/heads/*:refs/remotes/origin/*'
                    fi

                    branch=${branch_override:-$instance_name}
                    if [ -n "$seed_commit" ]; then
                        source_ref=$seed_commit
                    elif [ "$instance_name" = default ] && [ -z "$branch_override" ]; then
                        branch=$default_branch
                        source_ref="refs/remotes/origin/$branch"
                        git -C "$checkout" show-ref --verify --quiet "$source_ref"
                    elif git -C "$checkout" show-ref --verify --quiet "refs/remotes/origin/$branch"; then
                        source_ref="refs/remotes/origin/$branch"
                    elif git -C "$checkout" show-ref --verify --quiet "refs/heads/$branch"; then
                        source_ref="refs/heads/$branch"
                    else
                        source_ref="refs/remotes/origin/$default_branch"
                        git -C "$checkout" show-ref --verify --quiet "$source_ref"
                    fi
                    git -C "$checkout" checkout --quiet --force --no-track -B "$branch" "$source_ref"
                    test "$(git -C "$checkout" symbolic-ref --short HEAD)" = "$branch"
                    commit=$(git -C "$checkout" rev-parse --verify HEAD^{commit})
                    printf '%s\n%s\n' "$branch" "$commit"
                    BASH);
        $result = $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: [
                    ...$this->arguments($instance, $context),
                    $instance->name,
                    $defaultBranch,
                    $instance->branch_override ?? '',
                    $instance->seed_commit ?? '',
                    $taskVm ? '0' : '1',
                ],
                input: $script->input,
                protectedInput: $script->protectedInput,
            ),
            step: 'app-instance-source-resolve',
            errorCode: 'instance.branch_resolution_failed',
        );

        return $this->resolution($result->stdout, $instance);
    }

    public function assertBranchCheckedOut(Instance $instance, ?string $branch): void
    {
        $context = $this->context($instance);
        try {
            $result = $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: [...$this->arguments($instance, $context), $branch ?? ''],
                    input: self::preparedRepositoryGuard($instance->source_prepare_id, $instance->seed_repository).<<<'BASH'
                        repository=$1
                        checkout=$2
                        allowed_root=$3
                        managed_user=$4
                        managed_group=$5
                        expected_branch=$6
                        checkout_parent=$(dirname "$checkout")
                        export GIT_OPTIONAL_LOCKS=0
                        guard_parent_chain "$checkout_parent" "$allowed_root"
                        inspect_prepared_repository identity
                        test -d "$checkout" && test ! -L "$checkout" || exit 1
                        flock -n -x -E 75 "$checkout" true
                        current=$(git -C "$checkout" symbolic-ref --quiet --short HEAD || true)
                        origin=$(git -C "$checkout" config --get remote.origin.url)
                        printf '%s' "$origin" | base64 --wrap=0
                        printf '\n'
                        printf '%s' "$current" | base64 --wrap=0
                        printf '\n'
                        BASH,
                ),
                step: 'app-instance-rename-inspect',
                errorCode: 'instance.source_identity_invalid',
            );
        } catch (RuntimeConvergenceException $exception) {
            $code = match ($exception->result?->exitCode) {
                42 => 'instance.branch_not_checked_out',
                75 => 'instance.lifecycle_busy',
                default => null,
            };
            if ($code !== null) {
                throw new ResourceOperationException(
                    $code,
                    $code === 'instance.lifecycle_busy' ? 'The Instance is busy with another lifecycle operation.' : 'HEAD is not on the requested branch.',
                    409,
                    previous: $exception,
                );
            }
            throw $exception;
        }
        $lines = explode("\n", preg_replace('/\n\z/', '', $result->stdout) ?? $result->stdout);
        $origin = count($lines) === 2 ? base64_decode($lines[0], true) : false;
        $current = count($lines) === 2 ? base64_decode($lines[1], true) : false;
        try {
            $valid = is_string($origin) && is_string($current)
                && GitRepositoryIdentity::derive($origin) === $instance->project->repository_identity;
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
        $context = $this->context($instance);
        $result = $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: $this->arguments($instance, $context),
                input: self::preparedRepositoryGuard($instance->source_prepare_id, $instance->seed_repository).<<<'BASH'
                    repository=$1
                    checkout=$2
                    allowed_root=$3
                    managed_user=$4
                    managed_group=$5
                    checkout_parent=$(dirname "$checkout")

                    guard_parent_chain "$checkout_parent" "$allowed_root"
                    inspect_prepared_repository
                    branch=$(git -C "$checkout" symbolic-ref --short HEAD)
                    commit=$(git -C "$checkout" rev-parse --verify HEAD^{commit})
                    printf '%s\n%s\n' "$branch" "$commit"
                    BASH,
            ),
            step: 'app-instance-source-inspect-resolved',
            errorCode: 'instance.source_identity_invalid',
        );

        return $this->resolution($result->stdout, $instance);
    }

    /**
     * @param  array{repository: string, allowedRoot: string, root: StoragePath, managedUser: string, managedGroup: string}  $context
     * @return non-empty-list<string>
     */
    private function arguments(Instance $instance, array $context): array
    {
        return [
            'bash',
            '-seu',
            '--',
            $context['repository'],
            $instance->checkout_path,
            $context['allowedRoot'],
            $context['managedUser'],
            $context['managedGroup'],
        ];
    }

    /** A task VM gets no GitHub token: it fetches nothing itself, and resolve sends its branches from the Gateway's mirror. */
    private function onTaskVm(Instance $instance): bool
    {
        return TaskVm::forNode($instance->node) instanceof TaskVm;
    }

    private function readAccess(Instance $instance, string $repository, bool $taskVm): GitReadEnvironment
    {
        return $taskVm ? GitReadEnvironment::none() : $this->access->for($repository, $instance->project->source_access);
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
        $this->git->send($project, $instance->checkout_path, $tips, fn (RemoteCommand $command): CommandResult => $this->ssh->execute($instance->node, $command, 'app-instance-source-resolve', 'instance.branch_resolution_failed'), 'instance.branch_resolution_failed');
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

    /** @return array{repository: string, allowedRoot: string, root: StoragePath, managedUser: string, managedGroup: string} */
    private function context(Instance $instance): array
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
            'allowedRoot' => $root->value,
            'root' => $root,
            'managedUser' => $account->user,
            'managedGroup' => $account->group,
        ];
    }

    private function resolution(string $stdout, Instance $instance): DevelopmentSourceResolution
    {
        $lines = preg_split('/\R/', trim($stdout));

        if (
            ! is_array($lines)
            || count($lines) !== 2
            || $lines[0] !== $this->selectedBranch($instance)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $lines[1]) !== 1
        ) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-result',
                errorCode: 'instance.source_identity_invalid',
                message: "Instance [{$instance->name}] returned invalid source evidence.",
            );
        }

        return new DevelopmentSourceResolution($lines[0], $lines[1]);
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

    private static function preparedRepositoryGuard(?string $prepareId = null, ?string $seedRepository = null): string
    {
        return 'expected_common='.escapeshellarg($seedRepository === null ? '' : $seedRepository.'/.git')."\n".'prepare_id='.escapeshellarg($prepareId ?? '')."\n".WorkspaceGit::bashPreamble().<<<'BASH'
            guard_parent_chain() {
                parent=$1
                root=$2
                case "$parent" in
                    "$root"|"$root"/*) ;;
                    *) return 1 ;;
                esac

                current=
                relative=${parent#/}
                if [ -n "$relative" ]; then
                    old_ifs=$IFS
                    IFS=/
                    for segment in $relative; do
                        IFS=$old_ifs
                        current="$current/$segment"
                        if [ -e "$current" ] || [ -L "$current" ]; then
                            test ! -L "$current"
                            test -d "$current"
                            test "$(realpath -e "$current")" = "$current"
                        fi
                        IFS=/
                    done
                    IFS=$old_ifs
                fi
            }
            create_directory() {
                if [ -e "$1" ] || [ -L "$1" ]; then
                    test -d "$1"
                    test ! -L "$1"
                    return 0
                fi
                install -d -m 0755 -- "$1"
            }
            inspect_prepared_repository() {
                test -d "$checkout"
                test ! -L "$checkout"
                test "$(realpath -e "$checkout")" = "$checkout"
                test "$(stat -c '%U:%G' "$checkout")" = "$managed_user:$managed_group"
                test ! -L "$checkout/.git"
                test "$(git -C "$checkout" rev-parse --show-toplevel)" = "$checkout"
                git_directory=$(git -C "$checkout" rev-parse --absolute-git-dir)
                common_directory=$(git -C "$checkout" rev-parse --path-format=absolute --git-common-dir)
                test -d "$git_directory" && test ! -L "$git_directory"
                test -d "$common_directory" && test ! -L "$common_directory"
                if [ -n "$expected_common" ]; then
                    test "$common_directory" = "$expected_common"
                fi
                test "$(stat -c '%U:%G' "$git_directory")" = "$managed_user:$managed_group"
                test "$(stat -c '%U:%G' "$common_directory")" = "$managed_user:$managed_group"
                if [ -f "$checkout/.git" ]; then
                    test -n "$expected_common"
                    case "$git_directory" in "$common_directory/worktrees/"*) ;; *) return 1 ;; esac
                    test "$(cat "$git_directory/gitdir")" = "$checkout/.git"
                else
                    test "$git_directory" = "$checkout/.git"
                    test "$common_directory" = "$checkout/.git"
                fi
                if [ "${1:-verify}" = verify ]; then
                    test "$(git -C "$checkout" config --get remote.origin.url)" = "$repository"
                fi
                if [ -n "$prepare_id" ]; then
                    test -f "$git_directory/orbit-source-prepare"
                    test ! -L "$git_directory/orbit-source-prepare"
                    test "$(stat -c '%U:%G' "$git_directory/orbit-source-prepare")" = "$managed_user:$managed_group"
                    test "$(cat "$git_directory/orbit-source-prepare")" = "$prepare_id:$(stat -c '%d:%i' "$checkout")"
                fi
            }

            BASH;
    }
}
