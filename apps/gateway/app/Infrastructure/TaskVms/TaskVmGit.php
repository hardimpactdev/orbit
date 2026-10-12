<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Project;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Network Git for task VMs ([Git on a task VM](/reference/compute-drivers#git-on-a-task-vm)). Every Git command that
 * talks to GitHub runs here, on the Gateway, in one bare mirror per Project, so no token reaches a VM. Objects reach
 * a VM's checkout as a pack on the standard input of one command and leave it as a pack on standard output. `$run`
 * runs a command in the VM as the checkout's owner, over the Node's SSH connection or through a builder's host.
 *
 * The VM is untrusted. The Gateway takes only commit IDs and pack data from it, never a path, ref name, or config.
 * Mirror Git runs with the Gateway's own config and the settings in `git()`. A commit ID from the VM only shrinks a
 * pack, and `index-pack --strict` checks every object that comes back.
 */
final readonly class TaskVmGit
{
    /** The largest pack that a VM may send back. */
    public const int MaxPackBytes = 2 * 1024 * 1024 * 1024;

    /** At most this many of a VM's ref tips shrink a pack. More are ignored, which only makes the pack larger. */
    private const int MaxHaves = 1000;

    private const int LockSeconds = 1800;

    private const int LockWaitSeconds = 600;

    /** Prints the commit IDs of the checkout's refs. */
    private const string ReadRefs = <<<'BASH'
        command git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$1" for-each-ref --format='%(objectname)'
        BASH;

    /** Indexes the pack on stdin, then sets each `<ref> <commit>` pair, or deletes the ref for `-`. */
    private const string Apply = <<<'BASH'
        set -eu
        checkout=$1
        shift
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

    /** @param  list<string>  $protocols  the protocols the mirror may fetch and push with */
    public function __construct(
        private ProcessRunner $processes,
        private ?string $directory = null,
        private array $protocols = ['https'],
    ) {}

    /**
     * Fetches the branches into the Project's mirror and returns each branch's commit, or null for a branch that the
     * repository does not have.
     *
     * @param  list<string>  $branches
     * @return array<string, string|null>
     *
     * @throws RuntimeConvergenceException
     */
    public function fetch(Project $project, array $branches, GitReadEnvironment $access, string $errorCode): array
    {
        $branches = array_values(array_unique($branches));
        foreach ($branches as $branch) {
            if (! GitBranchName::isValid($branch)) {
                throw new RuntimeConvergenceException('task-vm-git-fetch', $errorCode, 'A task VM branch name is invalid.');
            }
        }
        $url = GitRepositoryOrigin::validate($project->repository_url);

        return $this->locked($project, function () use ($project, $branches, $access, $url, $errorCode): array {
            $mirror = $this->mirror($project, $errorCode);
            $advertised = $this->checked('task-vm-git-ls-remote', $errorCode, $this->git($mirror, [
                'ls-remote', '--heads', '--', $url, ...array_map(static fn (string $branch): string => 'refs/heads/'.$branch, $branches),
            ], $access));
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
                ], $access, timeout: 1800.0));
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

            return $tips;
        });
    }

    /**
     * Sets `refs/remotes/origin/<branch>` in the VM's checkout to each commit of the mirror, and deletes it for null.
     * The pack holds the objects that the history of the checkout's own commits lacks.
     *
     * @param  array<string, string|null>  $tips  from {@see fetch()}
     * @param  Closure(RemoteCommand): CommandResult  $run
     *
     * @throws RuntimeConvergenceException
     */
    public function send(Project $project, string $checkout, array $tips, Closure $run, string $errorCode): void
    {
        $haves = $this->commitIds($run(new RemoteCommand(['bash', '-c', self::ReadRefs, '--', $checkout]))->stdout);
        $scratch = $this->scratch($errorCode);
        try {
            $pack = $this->locked($project, fn (): string => $this->pack($this->mirror($project, $errorCode), array_values(array_filter($tips, is_string(...))), $haves, $scratch, $errorCode));
            $updates = [];
            foreach ($tips as $branch => $commit) {
                $updates[] = 'refs/remotes/origin/'.$branch;
                $updates[] = $commit ?? '-';
            }
            $run(new RemoteCommand(['bash', '-c', self::Apply, '--', $checkout, ...$updates], protectedInput: ProtectedInput::openFile($pack), timeout: 1800.0));
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    /**
     * Takes the approved commit out of the VM's checkout into the mirror and pushes it to `refs/heads/<branch>` with
     * the write access. The pack leaves out the history of `$haves`.
     *
     * @param  list<string>  $haves  commits of the mirror, such as those {@see fetch()} returned for the default and task branches
     * @param  Closure(RemoteCommand): CommandResult  $run
     *
     * @throws RuntimeConvergenceException `task-pull-request-push` with the push result when GitHub refuses the push
     */
    public function publish(Project $project, string $checkout, string $commit, string $branch, array $haves, GitReadEnvironment $write, Closure $run, string $errorCode): void
    {
        if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1 || ! GitBranchName::isValid($branch)) {
            throw new RuntimeConvergenceException('task-vm-git-publish', $errorCode, 'The approved commit or its branch is invalid.');
        }
        $url = GitRepositoryOrigin::validate($project->repository_url);
        $scratch = $this->scratch($errorCode);
        try {
            $pack = $scratch.'/receive.pack';
            $this->receive($checkout, $commit, $haves, $pack, $run, $errorCode);
            $this->locked($project, function () use ($project, $commit, $branch, $write, $url, $pack, $errorCode): void {
                $mirror = $this->mirror($project, $errorCode);
                $this->checked('task-vm-git-index-pack', $errorCode, $this->git($mirror, [
                    'index-pack', '--strict', '--fix-thin', '--stdin', '--max-input-size='.self::MaxPackBytes,
                ], input: ProtectedInput::openFile($pack), timeout: 1800.0));
                $type = $this->git($mirror, ['cat-file', '-t', $commit]);
                // Fails on any object that the history of the commit lacks, up to the mirror's own refs.
                $complete = $this->git($mirror, ['rev-list', '--objects', '--quiet', $commit, '--not', '--all']);
                if (! $type->succeeded() || trim($type->stdout) !== 'commit' || ! $complete->succeeded()) {
                    throw new RuntimeConvergenceException('task-vm-git-verify', $errorCode, 'The task VM did not send the approved commit with its whole history.');
                }
                $pushed = $this->git($mirror, ['push', '--quiet', '--', $url, "{$commit}:refs/heads/{$branch}"], $write, timeout: 1800.0);
                if (! $pushed->succeeded()) {
                    throw new RuntimeConvergenceException('task-pull-request-push', $errorCode, 'The task branch could not be pushed.', result: $pushed);
                }
                $this->git($mirror, ['update-ref', 'refs/heads/'.$branch, $commit]);
            });
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    /**
     * Streams the VM's pack to `$pack`. The VM leaves out the history of the commits it has of `$haves`.
     *
     * @param  list<string>  $haves
     * @param  Closure(RemoteCommand): CommandResult  $run
     */
    private function receive(string $checkout, string $commit, array $haves, string $pack, Closure $run, string $errorCode): void
    {
        $file = fopen($pack, 'xb');
        if ($file === false) {
            throw new RuntimeConvergenceException('task-vm-git-receive', $errorCode, 'The pack file could not be created.');
        }
        $size = 0;
        try {
            $run(new RemoteCommand(
                arguments: ['bash', '-c', self::Emit, '--', $checkout, $commit, ...$haves],
                maxOutputBytes: 65_536,
                output: static function (ProcessOutput $output) use ($file): void {
                    if ($output->stream === ProcessOutputStream::Stdout && ftell($file) <= self::MaxPackBytes) {
                        fwrite($file, $output->value);
                    }
                },
                cancelled: static fn (): bool => ftell($file) > self::MaxPackBytes,
                timeout: 1800.0,
            ));
        } finally {
            $size = (int) ftell($file);
            fclose($file);
        }
        if ($size > self::MaxPackBytes) {
            throw new RuntimeConvergenceException('task-vm-git-receive', $errorCode, 'The task VM sent a pack over '.self::MaxPackBytes.' bytes.');
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
    private function locked(Project $project, Closure $work): mixed
    {
        return Cache::lock('task-vm-git:'.$project->id, self::LockSeconds)->block(self::LockWaitSeconds, $work);
    }

    /** @param  list<string>  $arguments */
    private function git(string $mirror, array $arguments, ?GitReadEnvironment $access = null, ?ProtectedInput $input = null, ?string $stdin = null, float $timeout = 300.0): CommandResult
    {
        $settings = [
            'core.hooksPath=/dev/null', 'core.fsmonitor=false', 'credential.helper=', 'http.followRedirects=false',
            'transfer.fsckObjects=true', 'gc.autoDetach=false', 'protocol.allow=never',
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
            environment: [...($access->variables ?? []), 'GIT_TERMINAL_PROMPT' => '0', 'GIT_CONFIG_NOSYSTEM' => '1'],
        ));
    }

    private function checked(string $step, string $errorCode, CommandResult $result): CommandResult
    {
        return $result->succeeded()
            ? $result
            : throw new RuntimeConvergenceException($step, $errorCode, "Task VM Git step [{$step}] failed on the Gateway.", result: $result);
    }
}
