<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitReadEnvironment;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\TaskVms\TaskVmGit;
use App\Jobs\TaskVms\FillTaskVmPackageCache;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Support\GitRemoteRedirect;
use Tests\Support\StreamingLocalShellSshExecutor;
use Tests\Support\TaskVmSettingsBuilder;
use Tests\Support\TestOrbitHome;

const TVM_GIT_REPOSITORY = 'https://github.com/acme/shop.git';

const TVM_GIT_TOKEN = 'ghs_vmGitToken0123456789';

/** @param list<string> $arguments */
function tvm_git(string $directory, array $arguments): string
{
    return trim((new Process(['git', '-C', $directory, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$arguments]))->mustRun()->getOutput());
}

function tvm_git_commit(string $checkout, string $file): string
{
    file_put_contents($checkout.'/'.$file, $file."\n");
    tvm_git($checkout, ['add', '--', $file]);
    tvm_git($checkout, ['commit', '--quiet', '-m', $file]);

    return tvm_git($checkout, ['rev-parse', 'HEAD']);
}

/** Runs the VM's commands in a local shell and records them, as the Node's SSH connection would. */
function tvm_git_vm(): Closure
{
    return function (RemoteCommand $command): CommandResult {
        test()->sent->append($command);
        $result = (new StreamingLocalShellSshExecutor)->execute(new SshConnection('10.44.0.130', 'orbit', 22, '/keys/id', '/keys/known_hosts'), $command);
        if (! $result->succeeded()) {
            throw new RuntimeConvergenceException('vm', 'tasks.fetch_failed', 'The VM command failed: '.$result->stderr, result: $result);
        }

        return $result;
    };
}

beforeEach(function (): void {
    $this->sent = new ArrayObject;
    $this->root = TestOrbitHome::scratch('tvm-git');
    mkdir($this->root, 0700, true);
    // The GitHub repository, and a clone that pushes to it.
    (new Process(['git', 'init', '--quiet', '--bare', '-b', 'main', $this->root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'main', $this->root.'/upstream']))->mustRun();
    $this->main = tvm_git_commit($this->root.'/upstream', 'README');
    tvm_git($this->root.'/upstream', ['push', '--quiet', $this->root.'/origin.git', 'main']);
    GitRemoteRedirect::global($this->root.'/origin.git', TVM_GIT_REPOSITORY);
    // The VM's checkout, as a workspace without a template starts.
    (new Process(['git', 'init', '--quiet', $this->root.'/vm']))->mustRun();
    tvm_git($this->root.'/vm', ['remote', 'add', 'origin', TVM_GIT_REPOSITORY]);
    $this->project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => TVM_GIT_REPOSITORY, 'default_branch' => 'main']);
    $this->mirrors = $this->root.'/mirrors';
    $this->git = new TaskVmGit(app(ProcessRunner::class), $this->mirrors, ['https', 'file']);
    $this->access = GitReadEnvironment::forGitHubToken(TVM_GIT_TOKEN);
});

afterEach(function (): void {
    GitRemoteRedirect::clear();
    TestOrbitHome::clearScratch();
});

/** No command that reached the VM carries the token, in its arguments or on its input. */
function tvm_git_expect_no_token(): void
{
    foreach (test()->sent as $command) {
        $input = $command->protectedInput?->stream();
        $text = implode("\n", $command->arguments)."\n".($command->input ?? '').(is_resource($input) ? stream_get_contents($input) : '');
        expect($text)->not->toContain(TVM_GIT_TOKEN)
            ->not->toContain(base64_encode('x-access-token:'.TVM_GIT_TOKEN))
            ->and($command->protectedInput?->carriesGitCredential())->not->toBeTrue();
    }
}

describe('send branches to the VM', function (): void {
    it('fetches the branches into the mirror and sets the remote-tracking refs in the VM without a token', function (): void {
        $tips = $this->git->fetch($this->project, ['main', 'task-7'], $this->access, 'tasks.fetch_failed');
        $this->git->send($this->project, $this->root.'/vm', $tips, tvm_git_vm(), 'tasks.fetch_failed');

        expect($tips)->toBe(['main' => $this->main, 'task-7' => null])
            ->and(tvm_git($this->root.'/vm', ['rev-parse', 'refs/remotes/origin/main']))->toBe($this->main)
            // As after a clone: Pest's test selection reads the default branch from origin/HEAD.
            ->and(tvm_git($this->root.'/vm', ['symbolic-ref', 'refs/remotes/origin/HEAD']))->toBe('refs/remotes/origin/main')
            ->and(tvm_git($this->root.'/vm', ['fsck', '--no-dangling', '--connectivity-only']))->toBe('');
        tvm_git_expect_no_token();
    });

    it('sends only the new objects and drops the ref of a branch that GitHub no longer has', function (): void {
        tvm_git($this->root.'/upstream', ['push', '--quiet', $this->root.'/origin.git', 'main:task-7']);
        $this->git->send($this->project, $this->root.'/vm', $this->git->fetch($this->project, ['main', 'task-7'], $this->access, 'tasks.fetch_failed'), tvm_git_vm(), 'tasks.fetch_failed');
        $next = tvm_git_commit($this->root.'/upstream', 'CHANGELOG');
        tvm_git($this->root.'/upstream', ['push', '--quiet', $this->root.'/origin.git', 'main', ':task-7']);
        $this->sent = new ArrayObject;

        $tips = $this->git->fetch($this->project, ['main', 'task-7'], $this->access, 'tasks.fetch_failed');
        $this->git->send($this->project, $this->root.'/vm', $tips, tvm_git_vm(), 'tasks.fetch_failed');

        expect($tips)->toBe(['main' => $next, 'task-7' => null])
            ->and(tvm_git($this->root.'/vm', ['rev-parse', 'refs/remotes/origin/main']))->toBe($next)
            ->and(tvm_git($this->root.'/vm', ['for-each-ref', 'refs/remotes/origin/task-7']))->toBe('')
            // The VM already had README, so the pack holds only the new commit, its tree, and CHANGELOG.
            ->and(strlen((string) stream_get_contents($this->sent[1]->protectedInput?->stream())))->toBeLessThan(400);
    });

    it('ignores commit IDs from the VM that the mirror does not hold', function (): void {
        $tips = $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');
        $lying = function (RemoteCommand $command): CommandResult {
            $result = (tvm_git_vm())($command);

            return str_contains($command->arguments[2], 'for-each-ref')
                ? new CommandResult(0, str_repeat('b', 40)."\n../../etc/passwd\n".$result->stdout, '', 1, false)
                : $result;
        };

        $this->git->send($this->project, $this->root.'/vm', $tips, $lying, 'tasks.fetch_failed');

        expect(tvm_git($this->root.'/vm', ['rev-parse', 'refs/remotes/origin/main']))->toBe($this->main);
    });

    it('fails loudly when GitHub cannot be read', function (): void {
        mkdir($this->root.'/gone');
        GitRemoteRedirect::global($this->root.'/gone/missing.git', 'https://github.com/acme/gone.git');
        $this->project->update(['repository_url' => 'https://github.com/acme/gone.git']);

        expect(fn () => $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed'))
            ->toThrow(RuntimeConvergenceException::class, 'Task VM Git step [task-vm-git-ls-remote] failed on the Gateway.');
    });
});

describe('publish from the VM', function (): void {
    beforeEach(function (): void {
        $tips = $this->git->fetch($this->project, ['main'], $this->access, 'tasks.push_failed');
        $this->git->send($this->project, $this->root.'/vm', $tips, tvm_git_vm(), 'tasks.push_failed');
        tvm_git($this->root.'/vm', ['checkout', '--quiet', '-B', 'task-7', 'refs/remotes/origin/main']);
        $this->approved = tvm_git_commit($this->root.'/vm', 'export.php');
        // A later commit that nobody approved.
        tvm_git_commit($this->root.'/vm', 'unapproved.php');
        $this->sent = new ArrayObject;
    });

    it('takes the approved commit into the mirror and pushes exactly that commit to GitHub', function (): void {
        $this->git->publish($this->project, $this->root.'/vm', $this->approved, 'task-7', [$this->main], $this->access, tvm_git_vm(), 'tasks.push_failed');

        expect(tvm_git($this->root.'/origin.git', ['rev-parse', 'refs/heads/task-7']))->toBe($this->approved)
            ->and(tvm_git($this->root.'/origin.git', ['fsck', '--no-dangling']))->toBe('')
            // The verified pack moved out of quarantine into the mirror, and the scratch directory is gone.
            ->and(tvm_git($this->mirrors.'/'.$this->project->id.'.git', ['rev-parse', 'refs/heads/task-7']))->toBe($this->approved)
            ->and(tvm_git($this->mirrors.'/'.$this->project->id.'.git', ['fsck', '--no-dangling', '--connectivity-only']))->toBe('')
            ->and(glob($this->mirrors.'/tmp/*'))->toBe([]);
        tvm_git_expect_no_token();
    });

    it('keeps the objects of a pack that fails verification out of the mirror', function (): void {
        // A whole, valid pack of another commit: index-pack accepts it, but it lacks the approved commit.
        tvm_git($this->root.'/vm', ['checkout', '--quiet', '--orphan', 'other']);
        $other = tvm_git_commit($this->root.'/vm', 'other.php');
        $pack = (string) (new Process(['git', '-C', $this->root.'/vm', 'pack-objects', '--revs', '--stdout', '--quiet'], null, null, $other."\n"))->mustRun()->getOutput();
        $forged = function (RemoteCommand $command) use ($pack): CommandResult {
            ($command->output)(new ProcessOutput(ProcessOutputStream::Stdout, $pack));

            return new CommandResult(0, '', '', 1, false);
        };

        expect(fn () => $this->git->publish($this->project, $this->root.'/vm', $this->approved, 'task-7', [$this->main], $this->access, $forged, 'tasks.push_failed'))
            ->toThrow(RuntimeConvergenceException::class, 'The task VM did not send the approved commit with its whole history.');
        $probe = new Process(['git', '--git-dir='.$this->mirrors.'/'.$this->project->id.'.git', 'cat-file', '-e', $other]);
        expect($probe->run())->not->toBe(0)
            ->and(glob($this->mirrors.'/tmp/*'))->toBe([])
            ->and(tvm_git($this->root.'/origin.git', ['for-each-ref', 'refs/heads/task-7']))->toBe('');
    });

    it('stops a VM that sends more than the limit and fails with the limit', function (): void {
        $git = new TaskVmGit(app(ProcessRunner::class), $this->mirrors, ['https', 'file'], maxPackBytes: 1024);
        $flood = function (RemoteCommand $command): CommandResult {
            // As the process runner does: output in chunks, then the cancel check stops the command.
            for ($chunk = 0; $chunk < 8; $chunk++) {
                ($command->output)(new ProcessOutput(ProcessOutputStream::Stdout, str_repeat('p', 512)));
                if (($command->cancelled)()) {
                    throw new ProcessCancelledException;
                }
            }

            return new CommandResult(0, '', '', 1, false);
        };

        expect(fn () => $git->publish($this->project, $this->root.'/vm', $this->approved, 'task-7', [$this->main], $this->access, $flood, 'tasks.push_failed'))
            ->toThrow(RuntimeConvergenceException::class, 'The task VM sent a pack over 1024 bytes.');
        expect(glob($this->mirrors.'/tmp/*'))->toBe([]);
    });

    it('refuses a pack that does not hold the approved commit and its history, and pushes nothing', function (string $stdout): void {
        $forged = function (RemoteCommand $command) use ($stdout): CommandResult {
            ($command->output)(new ProcessOutput(ProcessOutputStream::Stdout, $stdout));

            return new CommandResult(0, '', '', 1, false);
        };

        expect(fn () => $this->git->publish($this->project, $this->root.'/vm', $this->approved, 'task-7', [$this->main], $this->access, $forged, 'tasks.push_failed'))
            ->toThrow(RuntimeConvergenceException::class);
        expect(tvm_git($this->root.'/origin.git', ['for-each-ref', 'refs/heads/task-7']))->toBe('');
    })->with([
        'garbage' => ["not a pack\n"],
        'an empty pack' => [(string) (new Process(['git', 'pack-objects', '--stdout', '--quiet'], null, null, ''))->mustRun()->getOutput()],
    ]);

    it('refuses an approved commit that is not a commit ID', function (): void {
        expect(fn () => $this->git->publish($this->project, $this->root.'/vm', 'HEAD', 'task-7', [], $this->access, tvm_git_vm(), 'tasks.push_failed'))
            ->toThrow(RuntimeConvergenceException::class, 'The approved commit or its branch is invalid.');
        expect($this->sent)->toHaveCount(0);
    });

    it('reports GitHub refusing the push with the push result', function (): void {
        tvm_git($this->root.'/upstream', ['push', '--quiet', $this->root.'/origin.git', 'main:task-7']);
        tvm_git_commit($this->root.'/upstream', 'other.php');
        tvm_git($this->root.'/upstream', ['push', '--quiet', '--force', $this->root.'/origin.git', 'HEAD:task-7']);

        try {
            $this->git->publish($this->project, $this->root.'/vm', $this->approved, 'task-7', [$this->main], $this->access, tvm_git_vm(), 'tasks.push_failed');
            $this->fail('The push was not refused.');
        } catch (RuntimeConvergenceException $exception) {
            expect($exception->step)->toBe('task-pull-request-push')
                ->and($exception->result?->stderr)->toContain('rejected');
        }
    });
});

describe('the repository URL', function (): void {
    it('fetches a github.com repository named in SSH form over HTTPS', function (string $origin): void {
        $this->project->update(['repository_url' => $origin]);

        expect($this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed'))->toBe(['main' => $this->main]);
    })->with(['git@' => ['git@github.com:acme/shop.git'], 'ssh://' => ['ssh://git@github.com/acme/shop']]);

    it('refuses another SSH repository clearly', function (): void {
        $this->project->update(['repository_url' => 'git@gitlab.com:acme/shop.git']);

        expect(fn () => $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed'))
            ->toThrow(RuntimeConvergenceException::class, 'Task VM Git reaches a repository over HTTPS only.');
    });
});

describe('garbage collection', function (): void {
    it('never collects garbage inside a mirror command, and collects it on request', function (): void {
        $runs = new ArrayObject;
        $recording = new class(app(ProcessRunner::class), $runs) implements ProcessRunner
        {
            public function __construct(private ProcessRunner $runner, private ArrayObject $runs) {}

            public function run(ProcessInvocation $invocation): CommandResult
            {
                $this->runs->append($invocation->arguments);

                return $this->runner->run($invocation);
            }
        };
        $git = new TaskVmGit($recording, $this->mirrors, ['https', 'file']);
        $tips = $git->fetch($this->project, ['main'], $this->access, 'tasks.push_failed');
        $git->send($this->project, $this->root.'/vm', $tips, tvm_git_vm(), 'tasks.push_failed');
        tvm_git($this->root.'/vm', ['checkout', '--quiet', '-B', 'task-7', 'refs/remotes/origin/main']);
        $git->publish($this->project, $this->root.'/vm', tvm_git_commit($this->root.'/vm', 'export.php'), 'task-7', [$this->main], $this->access, tvm_git_vm(), 'tasks.push_failed');
        $mirrorRuns = array_filter($runs->getArrayCopy(), static fn (array $arguments): bool => ! in_array('init', $arguments, true));

        expect($mirrorRuns)->not->toBeEmpty();
        foreach ($mirrorRuns as $arguments) {
            expect(implode(' ', $arguments))->toContain('-c gc.auto=0')->not->toContain(' gc ');
        }

        $runs->exchangeArray([]);
        $git->collectGarbage($this->project);

        expect(implode(' ', $runs[0]))->toContain('-c gc.auto=0')->toContain('-c gc.auto=6700 gc --auto --quiet');
    });
});

it('queues a package fill only when an upstream default-branch lock moves in the mirror', function (): void {
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host(31)]]);
    Queue::fake([FillTaskVmPackageCache::class]);
    file_put_contents($this->root.'/upstream/composer.lock', '{"packages":[]}');
    tvm_git($this->root.'/upstream', ['add', 'composer.lock']);
    tvm_git($this->root.'/upstream', ['commit', '--quiet', '-m', 'default lock']);
    tvm_git($this->root.'/upstream', ['push', '--quiet', $this->root.'/origin.git', 'main']);
    $tips = $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');
    $main = $tips['main'];
    tvm_git($this->root.'/upstream', ['checkout', '--quiet', '-b', 'task-7']);
    file_put_contents($this->root.'/upstream/composer.lock', '{"packages":[],"packages-dev":[]}');
    tvm_git($this->root.'/upstream', ['add', 'composer.lock']);
    tvm_git($this->root.'/upstream', ['commit', '--quiet', '-m', 'task lock']);
    tvm_git($this->root.'/upstream', ['push', '--quiet', $this->root.'/origin.git', 'task-7']);

    $this->git->fetch($this->project, ['task-7'], $this->access, 'tasks.fetch_failed');

    Queue::assertPushed(FillTaskVmPackageCache::class, 1);
    expect(PackageCacheSnapshot::query()->findOrFail($this->project->id)->commit)->toBe($main);

    new UniqueLock(app(Repository::class))->release(new FillTaskVmPackageCache(31));
    tvm_git($this->root.'/upstream', ['push', '--quiet', $this->root.'/origin.git', 'task-7:main']);
    $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');

    Queue::assertPushed(FillTaskVmPackageCache::class, 2);
    expect(PackageCacheSnapshot::query()->findOrFail($this->project->id)->commit)->not->toBe($main);
});
