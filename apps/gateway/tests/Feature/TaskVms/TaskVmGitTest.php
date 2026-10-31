<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitReadEnvironment;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\WorkspaceAgentCommandRunner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\TaskVms\TaskVmGit;
use App\Jobs\TaskVms\FillTaskVmPackageCache;
use App\Models\Node;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Support\GitRemoteRedirect;
use Tests\Support\ProcessInput;
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
    $this->git = new TaskVmGit(app(ProcessRunner::class), app(WorkspaceAgentCommandRunner::class), $this->mirrors, ['https', 'file']);
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
        $text = implode("\n", $command->arguments)."\n".($command->input ?? '').ProcessInput::contents($command->protectedInput);
        expect($text)->not->toContain(TVM_GIT_TOKEN)
            ->not->toContain(base64_encode('x-access-token:'.TVM_GIT_TOKEN))
            ->and($command->protectedInput?->carriesGitCredential())->not->toBeTrue();
    }
}

describe('send branches to the VM', function (): void {
    it('maps an agent read refusal and sends no pack after it', function (): void {
        $agents = Mockery::mock(WorkspaceAgentCommandRunner::class);
        $agents->shouldReceive('runInWorkspace')->once()->withArgs(fn ($node, $execute, $command, $input): bool => $command === 'task-vm.read-git-refs' && $input === ['checkout' => $this->root.'/vm'])
            ->andThrow(new AgentCommandRefused('task-vm.read-git-refs', 'task_vm.git_unreadable', 'The checkout cannot be read.'));
        $git = new TaskVmGit(app(ProcessRunner::class), $agents, $this->mirrors, ['https', 'file']);

        expect(fn () => $git->send($this->project, new Node(['name' => 'task-vm']), $this->root.'/vm', ['main' => $this->main], tvm_git_vm(), 'tasks.fetch_failed'))
            ->toThrow(fn (RuntimeConvergenceException $error): bool => $error->errorCode === 'tasks.fetch_failed' && $error->getPrevious() instanceof AgentCommandRefused)
            ->and($this->sent)->toHaveCount(0);
    });

    it('fetches the branches into the mirror and sets the remote-tracking refs in the VM without a token', function (): void {
        $tips = $this->git->fetch($this->project, ['main', 'task-7'], $this->access, 'tasks.fetch_failed');
        $this->git->send($this->project, new Node(['name' => 'task-vm']), $this->root.'/vm', $tips, tvm_git_vm(), 'tasks.fetch_failed');

        expect($tips)->toBe(['main' => $this->main, 'task-7' => null])
            ->and(tvm_git($this->root.'/vm', ['rev-parse', 'refs/remotes/origin/main']))->toBe($this->main)
            // As after a clone: Pest's test selection reads the default branch from origin/HEAD.
            ->and(tvm_git($this->root.'/vm', ['symbolic-ref', 'refs/remotes/origin/HEAD']))->toBe('refs/remotes/origin/main')
            ->and(tvm_git($this->root.'/vm', ['fsck', '--no-dangling', '--connectivity-only']))->toBe('');
        tvm_git_expect_no_token();
    });

    it('sends only the new objects and drops the ref of a branch that GitHub no longer has', function (): void {
        tvm_git($this->root.'/upstream', ['push', '--quiet', $this->root.'/origin.git', 'main:task-7']);
        $this->git->send($this->project, new Node(['name' => 'task-vm']), $this->root.'/vm', $this->git->fetch($this->project, ['main', 'task-7'], $this->access, 'tasks.fetch_failed'), tvm_git_vm(), 'tasks.fetch_failed');
        $next = tvm_git_commit($this->root.'/upstream', 'CHANGELOG');
        tvm_git($this->root.'/upstream', ['push', '--quiet', $this->root.'/origin.git', 'main', ':task-7']);
        $this->sent = new ArrayObject;

        $tips = $this->git->fetch($this->project, ['main', 'task-7'], $this->access, 'tasks.fetch_failed');
        $this->git->send($this->project, new Node(['name' => 'task-vm']), $this->root.'/vm', $tips, tvm_git_vm(), 'tasks.fetch_failed');

        expect($tips)->toBe(['main' => $next, 'task-7' => null])
            ->and(tvm_git($this->root.'/vm', ['rev-parse', 'refs/remotes/origin/main']))->toBe($next)
            ->and(tvm_git($this->root.'/vm', ['for-each-ref', 'refs/remotes/origin/task-7']))->toBe('')
            // The VM already had README, so the pack holds only the new commit, its tree, and CHANGELOG.
            ->and(strlen(ProcessInput::contents($this->sent[0]->protectedInput)))->toBeLessThan(400);
    });

    it('ignores commit IDs from the VM that the mirror does not hold', function (): void {
        $tips = $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');
        app(WorkspaceAgentCommandRunner::class)->malformed = true;
        app(WorkspaceAgentCommandRunner::class)->refs = [
            'haves' => [str_repeat('b', 40), '../../etc/passwd', 7],
            'baseline' => 'not-a-commit', 'head' => 'HEAD', 'branch' => 'main',
        ];

        $this->git->send($this->project, new Node(['name' => 'task-vm']), $this->root.'/vm', $tips, tvm_git_vm(), 'tasks.fetch_failed');

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

it('queues a package fill only when an upstream default-branch lock moves in the mirror', function (): void {
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host(31)]]);
    Queue::fake([FillTaskVmPackageCache::class]);
    file_put_contents($this->root.'/upstream/composer.json', '{}');
    file_put_contents($this->root.'/upstream/composer.lock', '{"packages":[]}');
    tvm_git($this->root.'/upstream', ['add', 'composer.json', 'composer.lock']);
    tvm_git($this->root.'/upstream', ['commit', '--quiet', '-m', 'default lock']);
    tvm_git($this->root.'/upstream', ['push', '--quiet', $this->root.'/origin.git', 'main']);
    $tips = $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');
    $main = $tips['main'];
    tvm_git($this->root.'/upstream', ['checkout', '--quiet', '-b', 'task-7']);
    file_put_contents($this->root.'/upstream/composer.lock', '{"packages":[],"packages-dev":[]}');
    tvm_git($this->root.'/upstream', ['add', 'composer.json', 'composer.lock']);
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

it('keeps source fetch usable during a package queue outage and retries dispatch on the next fetch', function (): void {
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host(31)]]);
    file_put_contents($this->root.'/upstream/composer.json', '{}');
    file_put_contents($this->root.'/upstream/composer.lock', '{"packages":[]}');
    tvm_git($this->root.'/upstream', ['add', 'composer.json', 'composer.lock']);
    tvm_git($this->root.'/upstream', ['commit', '--quiet', '-m', 'lock']);
    tvm_git($this->root.'/upstream', ['push', '--quiet', $this->root.'/origin.git', 'main']);
    $dispatcher = app(Dispatcher::class);
    $unavailable = Mockery::mock(Dispatcher::class);
    $unavailable->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
    app()->instance(Dispatcher::class, $unavailable);

    $tips = $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');

    expect($tips['main'])->toBe(tvm_git($this->root.'/upstream', ['rev-parse', 'HEAD']));
    expect(PackageCacheSnapshot::query()->findOrFail($this->project->id)->fill_pending)->toBeTrue();
    app()->instance(Dispatcher::class, $dispatcher);
    Queue::fake([FillTaskVmPackageCache::class]);

    $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');

    Queue::assertPushed(FillTaskVmPackageCache::class, 1);
    expect(PackageCacheSnapshot::query()->findOrFail($this->project->id)->fill_pending)->toBeTrue();
});
