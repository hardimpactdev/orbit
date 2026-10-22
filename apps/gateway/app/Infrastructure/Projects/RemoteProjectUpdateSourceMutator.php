<?php

declare(strict_types=1);

namespace App\Infrastructure\Projects;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Projects\ProjectUpdateSourceMutator;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\SourceControl\WorkspaceGit;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\TaskVm;

final readonly class RemoteProjectUpdateSourceMutator implements ProjectUpdateSourceMutator
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
        private RepositoryReadAccess $access,
    ) {}

    public function preflightRepository(array $checkouts, string $currentUrl, string $proposedUrl): void
    {
        $read = null;

        foreach ($this->uniqueCheckouts($checkouts) as $checkout) {
            // A task VM gets no token. UpdateProjectAction reads the proposed repository on the Gateway instead.
            $network = ! TaskVm::forNode($checkout->node_id) instanceof TaskVm;
            if ($network) {
                $read ??= $this->access->for($proposedUrl, $checkout->loadMissing('project')->project->source_access);
            }
            $this->run(
                $checkout,
                [$checkout->checkout_path, $currentUrl, $proposedUrl, $network ? '1' : '0'],
                <<<'BASH'
                    path=$1
                    current=$2
                    proposed=$3
                    network=$4
                    test -d "$path"
                    test -d "$path/.git"
                    test ! -f "$path/.git"
                    origin=$(git -C "$path" config --get remote.origin.url)
                    test "$origin" = "$current"
                    git -C "$path" rev-parse --verify --quiet HEAD >/dev/null
                    if [ "$network" = 1 ]; then
                        # Outside the checkout, so its config cannot shape a credentialed call.
                        git_read git -c credential.helper= -c http.followRedirects=false -c core.hooksPath=/dev/null -c core.fsmonitor=false -C / ls-remote --heads -- "$proposed" >/dev/null
                    fi
                    BASH,
                'app-update-repository-preflight',
                'project.repository_preflight_failed',
                read: $network ? $read : null,
            );
        }
    }

    public function changeOrigins(array $checkouts, string $previousUrl, string $newUrl, array $evidence): array
    {
        $byKey = [];

        foreach ($evidence as $row) {
            $byKey[self::evidenceKey($row)] = $row;
        }

        foreach ($this->uniqueCheckouts($checkouts) as $checkout) {
            $path = rtrim($checkout->checkout_path, '/');
            $key = 'instance:'.$checkout->id;

            // Evidence from before rows named their Instance carries only the path.
            if (($byKey[$key]['mutated'] ?? $byKey['path:'.$path]['mutated'] ?? false) === true) {
                continue;
            }

            $this->run(
                $checkout,
                [$path, $newUrl],
                <<<'BASH'
                    path=$1
                    url=$2
                    test -d "$path/.git"
                    test ! -f "$path/.git"
                    git -C "$path" remote set-url origin -- "$url"
                    test "$(git -C "$path" config --get remote.origin.url)" = "$url"
                    BASH,
                'app-update-repository-origin',
                'project.repository_origin_failed',
            );

            $byKey[$key] = [
                'instance_id' => $checkout->id,
                'path' => $path,
                'previous_url' => $previousUrl,
                'current_url' => $newUrl,
                'mutated' => true,
            ];
        }

        return array_values($byKey);
    }

    /** @param  array{instance_id?: int, path: string}  $row */
    private static function evidenceKey(array $row): string
    {
        return isset($row['instance_id']) ? 'instance:'.$row['instance_id'] : 'path:'.$row['path'];
    }

    public function restoreOrigins(array $mutations): void
    {
        foreach ($mutations as $mutation) {
            if ($mutation['mutated'] !== true) {
                continue;
            }

            $checkout = isset($mutation['instance_id'])
                ? Instance::query()->find($mutation['instance_id'])
                : Instance::query()->where('checkout_path', $mutation['path'])->first();

            if (! $checkout instanceof Instance) {
                continue;
            }

            $this->run(
                $checkout,
                [$mutation['path'], $mutation['previous_url']],
                <<<'BASH'
                    path=$1
                    url=$2
                    git -C "$path" remote set-url origin -- "$url"
                    BASH,
                'app-update-repository-restore',
                'project.repository_origin_failed',
            );
        }
    }

    public function preflightDefaultBranch(Instance $instance, string $newBranch): void
    {
        $this->run(
            $instance,
            [$instance->checkout_path, $newBranch, $instance->loadMissing('project')->project->repository_url],
            WorkspaceGit::fetchPreamble().<<<'BASH'
                path=$1
                branch=$2
                repository=$3
                test -d "$path"
                git -C "$path" rev-parse --is-inside-work-tree >/dev/null
                checkout_fetch -C "$path" fetch --quiet --no-tags --prune -- "$repository" \
                    '+refs/heads/*:refs/remotes/origin/*'
                git -C "$path" show-ref --verify --quiet "refs/remotes/origin/$branch"
                BASH,
            'app-update-default-branch-preflight',
            'project.source_switch_failed',
            read: $this->access->for(
                $instance->project->repository_url,
                $instance->project->source_access,
            ),
        );
    }

    public function switchDefaultBranch(Instance $instance, string $newBranch): void
    {
        $this->run(
            $instance,
            [$instance->checkout_path, $newBranch],
            <<<'BASH'
                path=$1
                branch=$2
                git -C "$path" checkout --no-track -B "$branch" "origin/$branch"
                git -C "$path" config "branch.$branch.remote" origin
                git -C "$path" config "branch.$branch.merge" "refs/heads/$branch"
                BASH,
            'app-update-default-branch-switch',
            'project.source_switch_failed',
        );
    }

    public function restoreDefaultBranch(Instance $instance, string $previousBranch): void
    {
        $this->run(
            $instance,
            [$instance->checkout_path, $previousBranch],
            <<<'BASH'
                path=$1
                branch=$2
                git -C "$path" checkout "$branch" --
                BASH,
            'app-update-default-branch-restore',
            'project.source_switch_failed',
        );
    }

    /**
     * @param  list<Instance>  $checkouts
     * @return list<Instance>
     */
    private function uniqueCheckouts(array $checkouts): array
    {
        $unique = [];

        foreach ($checkouts as $checkout) {
            // Task VMs share one checkout path, so the Node is part of a checkout's identity.
            $unique[$checkout->node_id.':'.rtrim($checkout->checkout_path, '/')] = $checkout;
        }

        return array_values($unique);
    }

    /**
     * Hooks never run: every Git call sets core.hooksPath.
     *
     * @param  list<string>  $arguments
     */
    private function run(
        Instance $instance,
        array $arguments,
        string $script,
        string $step,
        string $errorCode,
        ?GitReadEnvironment $read = null,
    ): void {
        $script = WorkspaceGit::bashPreamble().$script;
        $readScript = $read instanceof GitReadEnvironment
            ? GitReadScript::for($read, $script)
            : null;

        try {
            $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: ['bash', '-seu', '--', ...$arguments],
                    input: $readScript === null ? $script : $readScript->input,
                    protectedInput: $readScript?->protectedInput,
                ),
                step: $step,
                errorCode: $errorCode,
            );
        } catch (RuntimeConvergenceException $exception) {
            throw new ResourceOperationException(
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
                status: 409,
                previous: $exception,
            );
        }
    }
}
