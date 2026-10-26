<?php

declare(strict_types=1);

use App\Domain\GitHub\GitReadEnvironment;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\WorkspaceAgentCommandRunner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\TaskVms\TaskVmGit;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\GitRemoteRedirect;
use Tests\Support\StreamingLocalShellSshExecutor;
use Tests\Support\TestOrbitHome;

/** @param list<string> $arguments */
function ci_ref_git(string $directory, array $arguments): string
{
    return trim((new Process(['git', '-C', $directory, '-c', 'user.name=CI', '-c', 'user.email=ci@example.test', ...$arguments]))->mustRun()->getOutput());
}

describe('CI cache Git transport replacing Actions downloads', function (): void {
    beforeEach(function (): void {
        $this->root = TestOrbitHome::scratch('ci-cache-git');
        mkdir($this->root, 0700, true);
        (new Process(['git', 'init', '--quiet', '--bare', '-b', 'main', $this->root.'/origin.git']))->mustRun();
        (new Process(['git', 'init', '--quiet', '-b', 'main', $this->root.'/source']))->mustRun();
        file_put_contents($this->root.'/source/code', 'code');
        ci_ref_git($this->root.'/source', ['add', '.']);
        ci_ref_git($this->root.'/source', ['commit', '--quiet', '-m', 'code']);
        $this->sha = ci_ref_git($this->root.'/source', ['rev-parse', 'HEAD']);
        ci_ref_git($this->root.'/source', ['push', '--quiet', $this->root.'/origin.git', 'main']);
        $this->url = 'https://github.com/acme/ci-cache.git';
        GitRemoteRedirect::global($this->root.'/origin.git', $this->url);
        (new Process(['git', 'init', '--quiet', '-b', 'task-1574', $this->root.'/vm']))->mustRun();
        $this->project = Project::query()->create(['name' => 'CI', 'slug' => 'ci', 'repository_url' => $this->url, 'default_branch' => 'main']);
        $this->git = new TaskVmGit(app(ProcessRunner::class), app(WorkspaceAgentCommandRunner::class), $this->root.'/mirrors', ['https', 'file']);
        $this->access = GitReadEnvironment::forGitHubToken('ghs_ciCacheReadOnlyToken');
        $this->run = function (RemoteCommand $command): CommandResult {
            expect(implode(' ', $command->arguments).($command->input ?? ''))->not->toContain('ghs_ciCacheReadOnlyToken');
            $result = (new StreamingLocalShellSshExecutor)->execute(new SshConnection('10.44.0.130', 'orbit', 22, '/keys/id', '/keys/known_hosts'), $command);
            expect($result->succeeded())->toBeTrue($result->stderr);

            return $result;
        };
        $tree = ci_ref_git($this->root.'/source', ['rev-parse', 'HEAD^{tree}']);
        $this->cacheCommit = trim((new Process(['git', '-C', $this->root.'/source', '-c', 'user.name=CI', '-c', 'user.email=ci@example.test', 'commit-tree', $tree], input: 'CI cache'))->mustRun()->getOutput());
        $this->cacheRef = 'refs/orbit/ci-cache/'.$this->sha;
    });
    afterEach(function (): void {
        GitRemoteRedirect::clear();
        TestOrbitHome::clearScratch();
    });

    it('carries a matching cache ref with code through the mirror without exposing credentials', function (): void {
        ci_ref_git($this->root.'/source', ['push', '--quiet', $this->root.'/origin.git', $this->cacheCommit.':'.$this->cacheRef]);
        $tips = $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');
        $this->git->send($this->project, new Node(['name' => 'task-vm']), $this->root.'/vm', $tips, $this->run, 'tasks.fetch_failed');

        expect(ci_ref_git($this->root.'/vm', ['rev-parse', $this->cacheRef]))->toBe($this->cacheCommit)
            ->and(ci_ref_git($this->root.'/vm', ['rev-parse', 'refs/worktree/orbit/ci-baseline']))->toBe($this->sha)
            ->and(ci_ref_git($this->root.'/vm', ['rev-parse', 'refs/remotes/origin/main']))->toBe($this->sha)
            ->and(file_exists($this->root.'/vm/code'))->toBeFalse();
    });

    it('chooses the newest cached first-parent ancestor rather than a later publication or merged side branch', function (string $unbornBranch): void {
        Log::spy();
        ci_ref_git($this->root.'/source', ['commit', '--quiet', '--allow-empty', '-m', 'cached main']);
        $cached = ci_ref_git($this->root.'/source', ['rev-parse', 'HEAD']);
        ci_ref_git($this->root.'/source', ['commit', '--quiet', '--allow-empty', '-m', 'uncached head']);
        ci_ref_git($this->root.'/source', ['checkout', '--quiet', '-b', 'other', $this->sha]);
        ci_ref_git($this->root.'/source', ['commit', '--quiet', '--allow-empty', '-m', 'other branch']);
        $other = ci_ref_git($this->root.'/source', ['rev-parse', 'HEAD']);
        ci_ref_git($this->root.'/source', ['checkout', '--quiet', 'main']);
        ci_ref_git($this->root.'/source', ['merge', '--quiet', '--no-ff', 'other', '-m', 'Merge side branch']);
        $tip = ci_ref_git($this->root.'/source', ['rev-parse', 'HEAD']);
        ci_ref_git($this->root.'/source', ['push', '--quiet', $this->root.'/origin.git', 'main', 'other', $this->cacheCommit.':refs/orbit/ci-cache/'.$cached]);
        // Publish the older and second-parent refs last. Their completion order cannot choose the baseline.
        ci_ref_git($this->root.'/source', ['push', '--quiet', $this->root.'/origin.git', $this->cacheCommit.':'.$this->cacheRef, $this->cacheCommit.':refs/orbit/ci-cache/'.$other]);
        $tips = $this->git->fetch($this->project, ['main', 'other'], $this->access, 'tasks.fetch_failed');
        ci_ref_git($this->root.'/vm', ['symbolic-ref', 'HEAD', 'refs/heads/'.$unbornBranch]);

        $this->git->send($this->project, new Node(['name' => 'task-vm']), $this->root.'/vm', $tips, $this->run, 'tasks.fetch_failed', selectBaseline: true);

        expect(ci_ref_git($this->root.'/vm', ['rev-parse', 'refs/worktree/orbit/ci-baseline']))->toBe($cached)
            ->and(ci_ref_git($this->root.'/vm', ['rev-parse', 'origin/main']))->toBe($tip)
            ->and(ci_ref_git($this->root.'/vm', ['for-each-ref', '--format=%(refname)', 'refs/orbit/ci-cache/']))->toBe('refs/orbit/ci-cache/'.$cached);
        Log::shouldHaveReceived('info')->with('CI cache baseline selected.', ['project_id' => $this->project->id, 'tip' => $tip, 'baseline' => $cached, 'distance' => 2])->once();
    })->with(['unborn default branch' => 'main', 'unborn other branch' => 'master']);

    it('does not substitute another SHA and missing cache refs do not fail source transfer', function (): void {
        $other = str_repeat('a', 40);
        ci_ref_git($this->root.'/source', ['push', '--quiet', $this->root.'/origin.git', $this->cacheCommit.':refs/orbit/ci-cache/'.$other]);
        $tips = $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');
        $this->git->send($this->project, new Node(['name' => 'task-vm']), $this->root.'/vm', $tips, $this->run, 'tasks.fetch_failed');

        expect(ci_ref_git($this->root.'/vm', ['for-each-ref', 'refs/orbit/ci-cache/']))->toBe('')
            ->and(ci_ref_git($this->root.'/vm', ['rev-parse', 'refs/remotes/origin/main']))->toBe($this->sha);
    });

    it('prunes upstream-deleted cache refs but preserves the recorded task baseline when main moves', function (): void {
        ci_ref_git($this->root.'/source', ['push', '--quiet', $this->root.'/origin.git', $this->cacheCommit.':'.$this->cacheRef]);
        $tips = $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');
        $this->git->send($this->project, new Node(['name' => 'task-vm']), $this->root.'/vm', $tips, $this->run, 'tasks.fetch_failed');
        ci_ref_git($this->root.'/vm', ['checkout', '--quiet', '-B', 'task-1574', 'refs/remotes/origin/main']);
        ci_ref_git($this->root.'/source', ['commit', '--quiet', '--allow-empty', '-m', 'new main']);
        $next = ci_ref_git($this->root.'/source', ['rev-parse', 'HEAD']);
        ci_ref_git($this->root.'/source', ['push', '--quiet', $this->root.'/origin.git', 'main', ':'.$this->cacheRef]);
        $tips = $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');
        $this->git->send($this->project, new Node(['name' => 'task-vm']), $this->root.'/vm', $tips, $this->run, 'tasks.fetch_failed');

        expect(ci_ref_git($this->root.'/vm', ['rev-parse', 'refs/remotes/origin/main']))->toBe($next)
            ->and(ci_ref_git($this->root.'/vm', ['rev-parse', 'refs/worktree/orbit/ci-baseline']))->toBe($this->sha)
            ->and(ci_ref_git($this->root.'/vm', ['for-each-ref', 'refs/orbit/ci-cache/']))->toBe('');
    });

    it('logs a failed cache ref fetch and starts cold without failing source delivery', function (bool $throws): void {
        Log::spy();
        ci_ref_git($this->root.'/source', ['push', '--quiet', $this->root.'/origin.git', $this->cacheCommit.':'.$this->cacheRef]);
        $tips = $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');
        $runner = new class(app(ProcessRunner::class), $throws) implements ProcessRunner
        {
            public function __construct(private ProcessRunner $runner, private bool $throws) {}

            public function run(ProcessInvocation $invocation): CommandResult
            {
                if (in_array('+refs/orbit/ci-cache/*:refs/orbit/ci-cache/*', $invocation->arguments, true)) {
                    if ($this->throws) {
                        throw new RuntimeException('Cache namespace unavailable.');
                    }

                    return new CommandResult(1, '', 'Cache namespace unavailable.', 0, false);
                }

                return $this->runner->run($invocation);
            }
        };
        $git = new TaskVmGit($runner, app(WorkspaceAgentCommandRunner::class), $this->root.'/mirrors', ['https', 'file']);
        $tips = $git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');
        $git->send($this->project, new Node(['name' => 'task-vm']), $this->root.'/vm', $tips, $this->run, 'tasks.fetch_failed', selectBaseline: true);
        ci_ref_git($this->root.'/vm', ['checkout', '--quiet', '-b', 'task-cold', 'origin/main']);

        expect(ci_ref_git($this->root.'/vm', ['rev-parse', 'HEAD']))->toBe($this->sha)
            ->and(ci_ref_git($this->root.'/vm', ['for-each-ref', 'refs/orbit/ci-cache/', 'refs/worktree/orbit/ci-baseline']))->toBe('');
        Log::shouldHaveReceived('warning')->with('CI cache ref fetch failed; setup continues cold.', [
            'project_id' => $this->project->id,
            ...($throws ? ['reason' => 'Cache namespace unavailable.'] : ['exit_code' => 1, 'stderr' => 'Cache namespace unavailable.']),
        ])->once();
    })->with(['nonzero exit' => false, 'process exception' => true]);

    it('keeps source refs available when the optional cache transfer fails', function (): void {
        ci_ref_git($this->root.'/source', ['push', '--quiet', $this->root.'/origin.git', $this->cacheCommit.':'.$this->cacheRef]);
        $tips = $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');
        app(WorkspaceAgentCommandRunner::class)->beforeImport = function (array $input): void {
            if (in_array($this->cacheRef, array_column($input['updates'], 'reference'), true)) {
                throw new AgentCommandRefused('task-vm.import-git-pack', 'task_vm.git_import_failed', 'Disposable cache transfer failed.');
            }
        };
        $this->git->send($this->project, new Node(['name' => 'task-vm']), $this->root.'/vm', $tips, $this->run, 'tasks.fetch_failed');

        expect(ci_ref_git($this->root.'/vm', ['rev-parse', 'refs/remotes/origin/main']))->toBe($this->sha)
            ->and(ci_ref_git($this->root.'/vm', ['for-each-ref', 'refs/orbit/ci-cache/']))->toBe('');
    });

    it('can transfer only caches for an ordinary workspace without overwriting source refs', function (): void {
        ci_ref_git($this->root.'/source', ['push', '--quiet', $this->root.'/origin.git', $this->cacheCommit.':'.$this->cacheRef]);
        ci_ref_git($this->root.'/vm', ['fetch', '--quiet', $this->root.'/origin.git', 'main']);
        ci_ref_git($this->root.'/vm', ['checkout', '--quiet', '-B', 'main', 'FETCH_HEAD']);
        $tips = $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');
        $this->git->send($this->project, new Node(['name' => 'task-vm']), $this->root.'/vm', $tips, $this->run, 'tasks.fetch_failed', cachesOnly: true);

        expect(ci_ref_git($this->root.'/vm', ['rev-parse', $this->cacheRef]))->toBe($this->cacheCommit)
            ->and(ci_ref_git($this->root.'/vm', ['rev-parse', 'HEAD']))->toBe($this->sha)
            ->and(ci_ref_git($this->root.'/vm', ['for-each-ref', 'refs/remotes/origin/main']))->toBe('');
    });

    it('sends the caches of HEAD for another branch at an older main commit', function (): void {
        ci_ref_git($this->root.'/source', ['push', '--quiet', $this->root.'/origin.git', $this->cacheCommit.':'.$this->cacheRef]);
        ci_ref_git($this->root.'/vm', ['fetch', '--quiet', $this->root.'/origin.git', 'main']);
        ci_ref_git($this->root.'/vm', ['checkout', '--quiet', '-B', 't3/agent', 'FETCH_HEAD']);
        ci_ref_git($this->root.'/source', ['commit', '--quiet', '--allow-empty', '-m', 'new main']);
        ci_ref_git($this->root.'/source', ['push', '--quiet', $this->root.'/origin.git', 'main']);
        $tips = $this->git->fetch($this->project, ['main'], $this->access, 'tasks.fetch_failed');
        $this->git->send($this->project, new Node(['name' => 'task-vm']), $this->root.'/vm', $tips, $this->run, 'tasks.fetch_failed', cachesOnly: true);

        expect(ci_ref_git($this->root.'/vm', ['rev-parse', $this->cacheRef]))->toBe($this->cacheCommit)
            ->and(ci_ref_git($this->root.'/vm', ['rev-parse', 'refs/worktree/orbit/ci-baseline']))->toBe($this->sha);
    });
});
