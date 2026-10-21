<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\TaskVms\ObservePackageCacheAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Jobs\TaskVms\PublishTaskVmCommit;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Network Git for task VMs ([Git on a task VM](/reference/compute-drivers#git-on-a-task-vm)). Every Git command that
 * talks to GitHub runs here, on the Gateway, in one bare mirror per Project, so no token reaches a VM. Objects reach
 * a VM's checkout as a pack on the standard input of one command and leave it as a pack on standard output. `$run`
 * runs a command in the VM as the checkout's owner, over the Node's SSH connection or through a builder's host.
 *
 * The VM is untrusted. The Gateway takes only commit IDs and pack data from it, never a path, ref name, or config.
 * Mirror Git runs with the Gateway's own config and the settings in `git()`. A commit ID from the VM only shrinks a
 * pack, and `index-pack --strict` checks every object that comes back, as `transfer.fsckObjects` would for a fetch.
 * Fetches from GitHub skip those checks: an old commit in a real repository can fail them. A pack from a VM is indexed
 * into a quarantine object directory and joins the mirror only after the checks pass.
 *
 * The mirror lock serializes the commands that write the mirror. It covers no work at a VM's pace: a VM sends and
 * receives its packs outside the lock. Mirror commands never collect garbage themselves; {@see collectGarbage()} does,
 * outside the lock.
 */
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

    /** The automatic garbage collection threshold that {@see collectGarbage()} applies, Git's default. */
    private const int GcAutoObjects = 6700;

    /** Prints the commit IDs of the checkout's refs. */
    private const string ReadRefs = <<<'BASH'
        command git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$1" for-each-ref --format='%(objectname)'
        baseline=$(command git -C "$1" rev-parse --verify --quiet refs/worktree/orbit/ci-baseline || true)
        printf 'baseline %s\n' "$baseline"
        branch=$(command git -C "$1" symbolic-ref --quiet --short HEAD || true)
        printf 'branch %s\n' "$branch"
        head=$(command git -C "$1" rev-parse --verify --quiet HEAD || true)
        printf 'head %s\n' "$head"
        BASH;

    /** Indexes the pack on stdin, then sets each `<ref> <commit>` pair, or deletes the ref for `-`. */
    private const string Apply = <<<'BASH'
        set -eu
        checkout=$1
        head=$2
        shift 2
        git() { command git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" "$@"; }
        git index-pack --stdin --fix-thin >/dev/null
        while [ "$#" -ge 2 ]; do
            if [ "$2" = - ]; then
                git update-ref -d "$1"
            else
                git update-ref "$1" "$2"
            fi
            shift 2
        done
        # origin/HEAD names the default branch, as a clone sets it. The @orbit/ci-caches setup step reads it.
        if [ -n "$head" ] && git rev-parse --verify --quiet "refs/remotes/origin/$head" >/dev/null; then
            git symbolic-ref refs/remotes/origin/HEAD "refs/remotes/origin/$head"
        fi
        BASH;

    /** Writes a pack of the commit to stdout, without the history of those of the following commits it has. */
    private const string Emit = <<<'BASH'
        set -euo pipefail
        checkout=$1
        commit=$2
        shift 2
        git() { command git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" "$@"; }
        git cat-file -e "$commit^{commit}"
        {
            printf '%s\n' "$commit"
            for have in "$@"; do
                if git cat-file -e "$have^{commit}" 2>/dev/null; then
                    printf '^%s\n' "$have"
                fi
            done
        } | git pack-objects --revs --thin --stdout --delta-base-offset --quiet
        BASH;

    /**
     * @param  list<string>  $protocols  the protocols the mirror may fetch and push with
     * @param  int  $maxPackBytes  the largest pack that a VM may send back
     */
    public function __construct(
        private ProcessRunner $processes,
        private ?string $directory = null,
        private array $protocols = ['https'],
        private int $maxPackBytes = self::MaxPackBytes,
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
    public function send(Project $project, string $checkout, array $tips, Closure $run, string $errorCode, bool $cachesOnly = false, bool $selectBaseline = false): void
    {
        if (! $cachesOnly) {
            $this->transfer($project, $checkout, $tips, $run, $errorCode, false, false);
        }
        try {
            $this->transfer($project, $checkout, $tips, $run, $errorCode, true, $selectBaseline);
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
    private function transfer(Project $project, string $checkout, array $tips, Closure $run, string $errorCode, bool $cachesOnly, bool $selectBaseline): void
    {
        $state = $run(new RemoteCommand(['bash', '-c', self::ReadRefs, '--', $checkout]))->stdout;
        $haves = $this->commitIds($state);
        $default = is_string($project->default_branch) ? $project->default_branch : '';
        $recorded = preg_match('/^baseline ([0-9a-f]{40}(?:[0-9a-f]{24})?)$/m', $state, $match) === 1 ? $match[1] : null;
        $head = preg_match('/^head ([0-9a-f]{40}(?:[0-9a-f]{24})?)$/m', $state, $match) === 1 ? $match[1] : null;
        $tip = $tips[$default] ?? null;
        $onDefault = $head !== null && str_contains($state, "branch {$default}\n");
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
                $updates[] = 'refs/remotes/origin/'.$branch;
                $updates[] = $commit ?? '-';
            }
            if ($selectBaseline && $baseline === null) {
                $updates[] = 'refs/worktree/orbit/ci-baseline';
                $updates[] = '-';
            }
            if ($cacheRef !== null && is_string($baseline)) {
                $updates[] = $cacheRef;
                $updates[] = $cacheCommit ?? '-';
                $updates[] = 'refs/worktree/orbit/ci-baseline';
                $updates[] = $baseline;
            }
            $head = is_string($project->default_branch) && GitBranchName::isValid($project->default_branch) && array_key_exists($project->default_branch, $tips) ? $project->default_branch : '';
            $run(new RemoteCommand(['bash', '-c', self::Apply, '--', $checkout, $head, ...$updates], protectedInput: ProtectedInput::openFile($pack), timeout: 1800.0));
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    /**
     * Takes the approved commit out of the VM's checkout into the mirror and pushes it to `refs/heads/<branch>` with
     * the write access. The pack leaves out the history of `$haves`. Its objects go to a quarantine directory first and
     * join the mirror only when the approved commit and its whole history are there, so a refused pack leaves nothing.
     * It runs in a queued job ({@see PublishTaskVmCommit}), never in the task tick.
     *
     * @param  list<string>  $haves  commits of the mirror, such as those {@see fetch()} returned for the default and task branches
     * @param  Closure(RemoteCommand): CommandResult  $run
     * @param  float|null  $deadline  a `microtime(true)` that no Git command may outlast
     * @param  bool  $replace  whether the commit may replace a branch whose history moved: only over the tip that the last
     *                         {@see fetch()} of the branch saw, and never otherwise forced
     *
     * @throws RuntimeConvergenceException `task-pull-request-push` with the push result when GitHub refuses the push
     */
    public function publish(Project $project, string $checkout, string $commit, string $branch, array $haves, GitReadEnvironment $write, Closure $run, string $errorCode, ?float $deadline = null, bool $replace = false): void
    {
        if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1 || ! GitBranchName::isValid($branch)) {
            throw new RuntimeConvergenceException('task-vm-git-publish', $errorCode, 'The approved commit or its branch is invalid.');
        }
        $url = $this->url($project, $errorCode);
        $mirror = $this->mirror($project, $errorCode);
        $scratch = $this->scratch($errorCode);
        try {
            $pack = $scratch.'/receive.pack';
            $this->receive($checkout, $commit, $haves, $pack, $run, $errorCode, $deadline);
            $quarantine = $scratch.'/objects';
            File::ensureDirectoryExists($quarantine.'/pack', 0700);
            $this->checked('task-vm-git-index-pack', $errorCode, $this->git($mirror, [
                'index-pack', '--strict', '--fix-thin', '--stdin', '--max-input-size='.$this->maxPackBytes,
            ], input: ProtectedInput::openFile($pack), timeout: $this->timeout($deadline, 1800.0, $errorCode), quarantine: $quarantine));
            $type = $this->git($mirror, ['cat-file', '-t', $commit], timeout: $this->timeout($deadline, 300.0, $errorCode), quarantine: $quarantine);
            // Fails on any object that the history of the commit lacks, up to the mirror's own refs.
            $complete = $this->git($mirror, ['rev-list', '--objects', '--quiet', $commit, '--not', '--all'], timeout: $this->timeout($deadline, 1800.0, $errorCode), quarantine: $quarantine);
            if (! $type->succeeded() || trim($type->stdout) !== 'commit' || ! $complete->succeeded()) {
                throw new RuntimeConvergenceException('task-vm-git-verify', $errorCode, 'The task VM did not send the approved commit with its whole history.');
            }
            $this->locked($project, $errorCode, fn () => $this->migrate($quarantine, $mirror, $errorCode));
            $lease = $replace
                ? ['--force-with-lease=refs/heads/'.$branch.':'.trim($this->git($mirror, ['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch])->stdout)]
                : [];
            $pushed = $this->git($mirror, ['push', '--quiet', ...$lease, '--', $url, "{$commit}:refs/heads/{$branch}"], $write, timeout: $this->timeout($deadline, 1800.0, $errorCode));
            if (! $pushed->succeeded()) {
                throw new RuntimeConvergenceException('task-pull-request-push', $errorCode, 'The task branch could not be pushed.', result: $pushed);
            }
            $this->git($mirror, ['update-ref', 'refs/heads/'.$branch, $commit]);
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    /**
     * Packs the mirror when Git's automatic threshold says so. It runs outside the mirror lock, after a publish, so no
     * fetch or pack in the task tick waits for it. Git's own `gc.pid` lock keeps two collections apart.
     *
     * @throws RuntimeConvergenceException
     */
    public function collectGarbage(Project $project, ?float $deadline = null): void
    {
        $mirror = $this->root().'/'.$project->id.'.git';
        if (is_dir($mirror)) {
            $this->checked('task-vm-git-gc', 'tasks.push_failed', $this->git($mirror, ['-c', 'gc.auto='.self::GcAutoObjects, 'gc', '--auto', '--quiet'], timeout: $this->timeout($deadline, 1800.0, 'tasks.push_failed')));
        }
    }

    /**
     * Streams the VM's pack to `$pack`. The VM leaves out the history of the commits it has of `$haves`. A pack over
     * the limit stops the VM's command and fails with that limit, as any other failed push does.
     *
     * @param  list<string>  $haves
     * @param  Closure(RemoteCommand): CommandResult  $run
     */
    private function receive(string $checkout, string $commit, array $haves, string $pack, Closure $run, string $errorCode, ?float $deadline): void
    {
        $file = fopen($pack, 'xb');
        if ($file === false) {
            throw new RuntimeConvergenceException('task-vm-git-receive', $errorCode, 'The pack file could not be created.');
        }
        $size = 0;
        $max = $this->maxPackBytes;
        try {
            $run(new RemoteCommand(
                arguments: ['bash', '-c', self::Emit, '--', $checkout, $commit, ...$haves],
                maxOutputBytes: 65_536,
                output: static function (ProcessOutput $output) use ($file, $max): void {
                    if ($output->stream === ProcessOutputStream::Stdout && ftell($file) <= $max) {
                        fwrite($file, $output->value);
                    }
                },
                cancelled: static fn (): bool => ftell($file) > $max,
                timeout: $this->timeout($deadline, 1800.0, $errorCode),
            ));
        } catch (ProcessCancelledException $exception) {
            if (ftell($file) <= $max) {
                throw $exception;
            }
        } finally {
            $size = (int) ftell($file);
            fclose($file);
        }
        if ($size > $max) {
            throw new RuntimeConvergenceException('task-vm-git-receive', $errorCode, 'The task VM sent a pack over '.self::size($max).'.');
        }
    }

    /**
     * Moves the quarantined pack into the mirror. Each index moves last, so Git sees no pack before its data is there.
     * A pack that the mirror already has stays where it is.
     */
    private function migrate(string $quarantine, string $mirror, string $errorCode): void
    {
        $files = glob($quarantine.'/pack/pack-*') ?: [];
        usort($files, static fn (string $left, string $right): int => [str_ends_with($left, '.idx'), $left] <=> [str_ends_with($right, '.idx'), $right]);
        foreach ($files as $file) {
            $target = $mirror.'/objects/pack/'.basename($file);
            if (! is_file($target) && ! @rename($file, $target)) {
                throw new RuntimeConvergenceException('task-vm-git-migrate', $errorCode, 'The verified pack could not be moved into the mirror.');
            }
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

    /** @return list<string> unique commit IDs in `$stdout`, at most {@see MaxHaves} */
    private function commitIds(string $stdout): array
    {
        $ids = [];
        foreach (preg_split('/\R/', trim($stdout)) ?: [] as $line) {
            if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $line) === 1) {
                $ids[$line] = true;
            }
            if (count($ids) >= self::MaxHaves) {
                break;
            }
        }

        return array_keys($ids);
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

    private static function size(int $bytes): string
    {
        return $bytes % (1024 ** 3) === 0 ? ($bytes / 1024 ** 3).' GiB' : $bytes.' bytes';
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
