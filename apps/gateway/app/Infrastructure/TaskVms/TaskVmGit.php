<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\TaskVms\ObservePackageCacheAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\AgentCommands\AgentBinaryInput;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\WorkspaceAgentCommandRunner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Mirror reads and pack preparation used by the remaining native provision adapter. */
final readonly class TaskVmGit
{
    /** The largest pack that a VM may send back. */
    public const int MaxPackBytes = 2 * 1024 * 1024 * 1024;

    /** At most this many of a VM's ref tips shrink a pack. More are ignored, which only makes the pack larger. */
    private const int MaxHaves = 1000;

    /**
     * Outlasts the longest work under the lock: a fetch's `ls-remote` of 300 seconds and its fetch of 1800 seconds, or
     * a pack of 1800 seconds, with a margin. The lock cannot expire while its holder still writes the mirror.
     */
    private const int LockSeconds = 2400;

    private const int LockWaitSeconds = 300;

    /**
     * @param  list<string>  $protocols  the protocols the mirror may fetch and push with
     */
    public function __construct(
        private ProcessRunner $processes,
        private WorkspaceAgentCommandRunner $agents,
        private ?string $directory = null,
        private array $protocols = ['https'],
    ) {}

    /**
     * Fetches the branches into the Project's mirror and returns each branch's commit, or null for a branch that the
     * repository does not have.
     *
     * @param  list<string>  $branches
     * @param  float|null  $deadline  a `microtime(true)` that no Git command may outlast
     * @return array<string, string|null>
     *
     * @throws RuntimeConvergenceException
     */
    public function fetch(Project $project, array $branches, GitReadEnvironment $access, string $errorCode, ?float $deadline = null): array
    {
        $branches = array_values(array_unique($branches));
        foreach ($branches as $branch) {
            if (! GitBranchName::isValid($branch)) {
                throw new RuntimeConvergenceException('task-vm-git-fetch', $errorCode, 'A task VM branch name is invalid.');
            }
        }
        $url = $this->url($project, $errorCode);

        return $this->locked($project, $errorCode, function () use ($project, $branches, $access, $url, $errorCode, $deadline): array {
            $mirror = $this->mirror($project, $errorCode);
            $advertised = $this->checked('task-vm-git-ls-remote', $errorCode, $this->git($mirror, [
                'ls-remote', '--heads', '--', $url, ...array_map(static fn (string $branch): string => 'refs/heads/'.$branch, $branches),
            ], $access, timeout: $this->timeout($deadline, 300.0, $errorCode)));
            $present = [];
            foreach (preg_split('/\R/', trim($advertised->stdout)) ?: [] as $line) {
                if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\trefs\/heads\/(.+)\z/D', $line, $match) === 1 && in_array($match[1], $branches, true)) {
                    $present[] = $match[1];
                }
            }
            if ($present !== []) {
                $this->checked('task-vm-git-fetch', $errorCode, $this->git($mirror, [
                    'fetch', '--quiet', '--no-tags', '--no-write-fetch-head', '--', $url,
                    ...array_map(static fn (string $branch): string => "+refs/heads/{$branch}:refs/heads/{$branch}", $present),
                ], $access, timeout: $this->timeout($deadline, 1800.0, $errorCode)));
            }
            $tips = [];
            foreach ($branches as $branch) {
                if (! in_array($branch, $present, true)) {
                    $this->git($mirror, ['update-ref', '-d', 'refs/heads/'.$branch]);
                    $tips[$branch] = null;

                    continue;
                }
                $tips[$branch] = $this->commit($mirror, 'refs/heads/'.$branch, $errorCode);
            }

            if (is_string($project->default_branch) && array_key_exists($project->default_branch, $tips)) {
                try {
                    app(ObservePackageCacheAction::class)->execute($project, $mirror, $tips[$project->default_branch]);
                } catch (ProcessCancelledException $exception) {
                    throw $exception;
                } catch (\Throwable) {
                    Log::warning('Package cache refresh unavailable; source refs remain available.', ['project_id' => $project->id]);
                }
            }

            // Cache refs are a separate optional fetch. A cache outage cannot turn a source fetch red.
            try {
                $result = $this->git($mirror, [
                    'fetch', '--quiet', '--no-tags', '--no-write-fetch-head', '--prune', '--', $url,
                    '+refs/orbit/ci-cache/*:refs/orbit/ci-cache/*',
                ], $access, timeout: $this->timeout($deadline, 60.0, $errorCode));
                $available = $result->succeeded();
                if (! $available) {
                    Log::warning('CI cache ref fetch failed; setup continues cold.', ['project_id' => $project->id, 'exit_code' => $result->exitCode, 'stderr' => $result->stderr]);
                }
            } catch (ProcessCancelledException $exception) {
                throw $exception;
            } catch (\RuntimeException $exception) {
                $available = false;
                Log::warning('CI cache ref fetch failed; setup continues cold.', ['project_id' => $project->id, 'reason' => $exception->getMessage()]);
            }
            if (! $available) {
                // Do not send a previously cached ref after an unreadable upstream refresh.
                $refs = $this->git($mirror, ['for-each-ref', '--format=%(refname)', 'refs/orbit/ci-cache/']);
                foreach (preg_split('/\R/', trim($refs->stdout)) ?: [] as $ref) {
                    if (preg_match('/\Arefs\/orbit\/ci-cache\/[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $ref) === 1) {
                        $this->git($mirror, ['update-ref', '-d', $ref]);
                    }
                }
            }

            return $tips;
        });
    }

    /** Selects the newest published baseline on the default branch, without waiting for a newer publication. */
    private function newestCachedMain(Project $project, string $mirror, string $tip, string $errorCode): ?string
    {
        $refs = $this->checked('task-vm-git-cache-baseline', $errorCode, $this->git($mirror, [
            'for-each-ref', '--format=%(refname:strip=3)', 'refs/orbit/ci-cache/',
        ]));
        $cached = array_fill_keys(preg_split('/\R/', trim($refs->stdout)) ?: [], true);
        $history = $this->checked('task-vm-git-cache-baseline', $errorCode, $this->git($mirror, [
            'rev-list', '--first-parent', $tip,
        ]));
        foreach (preg_split('/\R/', trim($history->stdout)) ?: [] as $distance => $commit) {
            if (isset($cached[$commit])) {
                Log::info('CI cache baseline selected.', ['project_id' => $project->id, 'tip' => $tip, 'baseline' => $commit, 'distance' => $distance]);

                return $commit;
            }
        }

        Log::notice('CI caches cold; no cached first-parent ancestor is available in the mirror.', ['project_id' => $project->id, 'tip' => $tip]);

        return null;
    }

    /**
     * Sets `refs/remotes/origin/<branch>` in the VM's checkout to each commit of the mirror, and deletes it for null.
     * `refs/remotes/origin/HEAD` points at the Project's default branch, as after a clone, so tools that read the
     * default branch from it, such as Pest's test selection, find it. The pack holds the objects that the history of the
     * checkout's own commits lacks.
     *
     * @param  array<string, string|null>  $tips  from {@see fetch()}
     * @param  Closure(RemoteCommand): CommandResult  $run
     *
     * @throws RuntimeConvergenceException
     */
    public function send(Project $project, Node $node, string $checkout, array $tips, Closure $run, string $errorCode, bool $cachesOnly = false, bool $selectBaseline = false): void
    {
        if (! $cachesOnly) {
            $this->transfer($project, $node, $checkout, $tips, $run, $errorCode, false, false);
        }
        try {
            $this->transfer($project, $node, $checkout, $tips, $run, $errorCode, true, $selectBaseline);
        } catch (ProcessCancelledException $exception) {
            throw $exception;
        } catch (\RuntimeException) {
            Log::notice('CI cache transfer unavailable; source refs remain available.', ['project_id' => $project->id]);
        }
    }

    /**
     * @param  array<string, string|null>  $tips
     * @param  Closure(RemoteCommand): CommandResult  $run
     */
    private function transfer(Project $project, Node $node, string $checkout, array $tips, Closure $run, string $errorCode, bool $cachesOnly, bool $selectBaseline): void
    {
        try {
            $state = $this->agents->runInWorkspace($node, $run, 'task-vm.read-git-refs', ['checkout' => $checkout]);
        } catch (AgentCommandRefused|AgentRunnerFailure $exception) {
            throw new RuntimeConvergenceException('task-vm-git-refs', $errorCode, 'The checkout refs could not be read.', previous: $exception);
        }
        $haves = $this->commitIds(is_array($state['haves'] ?? null) ? $state['haves'] : []);
        $default = is_string($project->default_branch) ? $project->default_branch : '';
        $recorded = $this->commitId($state['baseline'] ?? null);
        $head = $this->commitId($state['head'] ?? null);
        $tip = $tips[$default] ?? null;
        $onDefault = $head !== null && ($state['branch'] ?? null) === $default;
        $baseline = $onDefault ? ($tip ?? $recorded) : ($recorded ?? $tip);
        $scratch = $this->scratch($errorCode);
        try {
            $cacheRef = null;
            $cacheCommit = null;
            $pack = $this->locked($project, $errorCode, function () use ($project, $tips, $haves, $scratch, $errorCode, $cachesOnly, $selectBaseline, $recorded, $head, $tip, $onDefault, &$baseline, &$cacheRef, &$cacheCommit): string {
                $mirror = $this->mirror($project, $errorCode);
                if ($selectBaseline && is_string($tip)) {
                    $baseline = $this->newestCachedMain($project, $mirror, $head ?? $tip, $errorCode);
                }
                // Another branch at a main commit, such as a fresh agent branch, uses that commit's own caches.
                if (! $selectBaseline && $cachesOnly && ! $onDefault && $recorded === null && is_string($head) && is_string($tip)
                    && $this->git($mirror, ['merge-base', '--is-ancestor', $head, $tip])->succeeded()) {
                    $baseline = $head;
                }
                $cacheRef = $cachesOnly && is_string($baseline) ? 'refs/orbit/ci-cache/'.$baseline : null;
                if ($cacheRef !== null) {
                    $result = $this->git($mirror, ['rev-parse', '--verify', '--quiet', $cacheRef.'^{commit}']);
                    $cacheCommit = $result->succeeded() ? trim($result->stdout) : null;
                }

                return $this->pack($mirror, [...($cachesOnly ? [] : array_values(array_filter($tips, is_string(...)))), ...($cacheCommit === null ? [] : [$cacheCommit])], $haves, $scratch, $errorCode);
            });
            $updates = [];
            foreach ($cachesOnly ? [] : $tips as $branch => $commit) {
                $updates[] = ['reference' => 'refs/remotes/origin/'.$branch, 'commit' => $commit];
            }
            if ($selectBaseline && $baseline === null) {
                $updates[] = ['reference' => 'refs/worktree/orbit/ci-baseline', 'commit' => null];
            }
            if ($cacheRef !== null && is_string($baseline)) {
                $updates[] = ['reference' => $cacheRef, 'commit' => $cacheCommit];
                $updates[] = ['reference' => 'refs/worktree/orbit/ci-baseline', 'commit' => $baseline];
            }
            $head = is_string($project->default_branch) && GitBranchName::isValid($project->default_branch) && array_key_exists($project->default_branch, $tips) ? $project->default_branch : '';
            try {
                $this->agents->runInWorkspace($node, $run, 'task-vm.import-git-pack', [
                    'checkout' => $checkout, 'default_branch' => $head === '' ? null : $head, 'updates' => $updates,
                ], binaryInput: AgentBinaryInput::fromFile($pack, self::MaxPackBytes));
            } catch (AgentCommandRefused|AgentRunnerFailure $exception) {
                throw new RuntimeConvergenceException('task-vm-git-import', $errorCode, 'The checkout pack could not be imported.', previous: $exception);
            }
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    /**
     * Packs the objects of `$tips` that the history of `$haves` lacks, and returns the pack file.
     *
     * @param  list<string>  $tips
     * @param  list<string>  $haves
     */
    private function pack(string $mirror, array $tips, array $haves, string $scratch, string $errorCode): string
    {
        $known = [];
        if ($haves !== []) {
            $types = $this->git($mirror, ['cat-file', '--batch-check=%(objectname) %(objecttype)'], stdin: implode("\n", $haves)."\n");
            foreach (preg_split('/\R/', trim($types->stdout)) ?: [] as $line) {
                if (preg_match('/\A([0-9a-f]{40}(?:[0-9a-f]{24})?) commit\z/D', $line, $match) === 1) {
                    $known[] = '^'.$match[1];
                }
            }
        }
        $result = $this->checked('task-vm-git-pack', $errorCode, $this->git($mirror, [
            'pack-objects', '--revs', '--delta-base-offset', '--quiet', $scratch.'/send',
        ], stdin: implode("\n", [...$tips, ...$known])."\n", timeout: 1800.0));
        $name = trim($result->stdout);
        $pack = $scratch.'/send-'.$name.'.pack';
        if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $name) !== 1 || ! is_file($pack)) {
            throw new RuntimeConvergenceException('task-vm-git-pack', $errorCode, 'The mirror wrote no pack.');
        }

        return $pack;
    }

    /**
     * @param  array<mixed>  $haves
     * @return list<string> unique commit IDs, at most {@see MaxHaves}
     */
    private function commitIds(array $haves): array
    {
        $ids = [];
        foreach ($haves as $value) {
            $id = $this->commitId($value);
            if ($id !== null) {
                $ids[$id] = true;
            }
            if (count($ids) >= self::MaxHaves) {
                break;
            }
        }

        return array_keys($ids);
    }

    private function commitId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $value) === 1 ? $value : null;
    }

    private function commit(string $mirror, string $ref, string $errorCode): string
    {
        $commit = trim($this->checked('task-vm-git-fetch', $errorCode, $this->git($mirror, ['rev-parse', '--verify', '--quiet', $ref.'^{commit}']))->stdout);

        return preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) === 1
            ? $commit
            : throw new RuntimeConvergenceException('task-vm-git-fetch', $errorCode, "The mirror has no commit for [{$ref}].");
    }

    /**
     * The HTTPS URL that the mirror fetches from and pushes to. The token goes in an HTTPS header, so a `git@` or
     * `ssh://` URL of a `github.com` repository becomes its HTTPS URL. Another SSH URL fails.
     */
    private function url(Project $project, string $errorCode): string
    {
        $origin = GitRepositoryOrigin::validate((string) $project->repository_url);
        if (str_starts_with($origin, 'https://')) {
            return $origin;
        }
        $repository = GitHubRepository::fromOrigin($origin);
        if (! $repository instanceof GitHubRepository) {
            throw new RuntimeConvergenceException('task-vm-git-url', $errorCode, 'Task VM Git reaches a repository over HTTPS only. Give the Project an https:// repository URL.');
        }

        return "https://github.com/{$repository->owner}/{$repository->name}.git";
    }

    /** The step's own timeout, cut to what is left before the deadline. A deadline that has passed fails the step. */
    private function timeout(?float $deadline, float $timeout, string $errorCode): float
    {
        if ($deadline === null) {
            return $timeout;
        }
        $left = $deadline - microtime(true);
        if ($left < 1.0) {
            throw new RuntimeConvergenceException('task-vm-git-deadline', $errorCode, 'Task VM Git ran out of time.');
        }

        return min($timeout, $left);
    }

    /** The Project's mirror, created on first use. */
    private function mirror(Project $project, string $errorCode): string
    {
        $mirror = $this->root().'/'.$project->id.'.git';
        if (! is_dir($mirror)) {
            File::ensureDirectoryExists($this->root(), 0700);
            $this->checked('task-vm-git-init', $errorCode, $this->processes->run(new ProcessInvocation(
                arguments: ['git', 'init', '--quiet', '--bare', '--', $mirror],
                timeout: 60.0,
                environment: ['GIT_CONFIG_NOSYSTEM' => '1'],
            )));
        }

        return $mirror;
    }

    private function scratch(string $errorCode): string
    {
        $scratch = $this->root().'/tmp/'.Str::uuid()->toString();
        if (! @mkdir($scratch, 0700, true) && ! is_dir($scratch)) {
            throw new RuntimeConvergenceException('task-vm-git-scratch', $errorCode, 'The Gateway could not create a scratch directory for task VM Git.');
        }

        return $scratch;
    }

    private function root(): string
    {
        return $this->directory ?? storage_path('app/task-vm-git');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function locked(Project $project, string $errorCode, Closure $work): mixed
    {
        try {
            return Cache::lock('task-vm-git:'.$project->id, self::LockSeconds)->block(self::LockWaitSeconds, $work);
        } catch (LockTimeoutException $exception) {
            throw new RuntimeConvergenceException('task-vm-git-lock', $errorCode, "The task VM Git mirror of Project [{$project->id}] stayed busy for ".self::LockWaitSeconds.' seconds.', $exception);
        }
    }

    /**
     * Runs Git in the mirror. With `$quarantine`, Git writes objects there and reads the mirror's objects as alternates,
     * and refuses to change a ref, as during `receive-pack`.
     *
     * @param  list<string>  $arguments
     */
    private function git(string $mirror, array $arguments, ?GitReadEnvironment $access = null, ?ProtectedInput $input = null, ?string $stdin = null, float $timeout = 300.0, ?string $quarantine = null): CommandResult
    {
        $settings = [
            'core.hooksPath=/dev/null', 'core.fsmonitor=false', 'credential.helper=', 'http.followRedirects=false',
            'gc.auto=0', 'gc.autoDetach=false', 'protocol.allow=never',
            ...array_map(static fn (string $protocol): string => "protocol.{$protocol}.allow=always", $this->protocols),
        ];
        $options = [];
        foreach ($settings as $setting) {
            $options[] = '-c';
            $options[] = $setting;
        }

        return $this->processes->run(new ProcessInvocation(
            arguments: ['git', ...$options, '--git-dir='.$mirror, ...$arguments],
            timeout: $timeout,
            input: $stdin,
            protectedInput: $input,
            environment: [
                ...($access->variables ?? []), 'GIT_TERMINAL_PROMPT' => '0', 'GIT_CONFIG_NOSYSTEM' => '1',
                ...($quarantine === null ? [] : ['GIT_OBJECT_DIRECTORY' => $quarantine, 'GIT_ALTERNATE_OBJECT_DIRECTORIES' => $mirror.'/objects', 'GIT_QUARANTINE_PATH' => $quarantine]),
            ],
        ));
    }

    private function checked(string $step, string $errorCode, CommandResult $result): CommandResult
    {
        return $result->succeeded()
            ? $result
            : throw new RuntimeConvergenceException($step, $errorCode, "Task VM Git step [{$step}] failed on the Gateway.", result: $result);
    }
}
