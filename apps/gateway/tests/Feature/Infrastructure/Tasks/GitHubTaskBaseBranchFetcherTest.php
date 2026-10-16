<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\GitHubTaskBaseBranchFetcher;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\GitRemoteRedirect;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\PlantedGitConfig;
use Tests\Support\TestOrbitHome;

pest()->group('privileged');

/** @param  list<string>  $arguments */
function fetcher_git(string $directory, array $arguments): string
{
    return trim((new Process(['git', '-C', $directory, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$arguments]))->mustRun()->getOutput());
}

function fetcher_group(string $checkout): Task
{
    $project = Project::query()->create([
        'name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'fetch-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.150', 'wireguard_ip' => '10.44.0.150', 'user' => 'orbit',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-7', 'checkout_path' => $checkout,
        'branch' => 'task-7', 'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Export orders', 'brief' => 'Add an export.', 'status' => 'settling',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    if (is_dir(dirname($checkout))) {
        // The mirror reaches the repository over HTTPS.
        GitRemoteRedirect::global(dirname($checkout).'/origin.git', 'https://github.com/acme/shop.git');
    }

    return test_vm_group($group);
}

function fetcher(SshExecutor $transport): GitHubTaskBaseBranchFetcher
{
    app()->instance(DevelopmentSshExecutor::class, new DevelopmentSshExecutor(
        $transport,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/home/orbit/.orbit/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/home/orbit/.orbit/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    ));

    return app(GitHubTaskBaseBranchFetcher::class);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => static fn (Request $request) => ($request->data()['permissions'] ?? []) === ['contents' => 'read']
            ? Http::response(['token' => 'ghs_read'], 201)
            : Http::response(['token' => 'ghs_fetch'], 201),
    ]);
});

afterEach(function (): void {
    GitRemoteRedirect::clear();
    TestOrbitHome::clearScratch();
});

it('fetches the base ref into the remote-tracking ref and leaves the task branch', function (): void {
    $root = TestOrbitHome::scratch('orbit-fetch');
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $root.'/checkout']))->mustRun();
    fetcher_git($root.'/checkout', ['commit', '--quiet', '--allow-empty', '-m', 'Local']);
    $local = fetcher_git($root.'/checkout', ['rev-parse', 'HEAD']);
    fetcher_git($root.'/checkout', ['commit', '--quiet', '--allow-empty', '-m', 'Base']);
    fetcher_git($root.'/checkout', ['remote', 'add', 'origin', $root.'/origin.git']);
    fetcher_git($root.'/checkout', ['push', '--quiet', 'origin', 'HEAD:refs/heads/main']);
    $base = fetcher_git($root.'/checkout', ['rev-parse', 'HEAD']);
    fetcher_git($root.'/checkout', ['reset', '--quiet', '--hard', $local]);
    fetcher_git($root.'/checkout', ['update-ref', '-d', 'refs/remotes/origin/main']);

    fetcher(new LocalShellSshExecutor)->fetch(fetcher_group($root.'/checkout'), 'main');

    expect(fetcher_git($root.'/checkout', ['rev-parse', 'HEAD']))->toBe($local)
        ->and(fetcher_git($root.'/checkout', ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('task-7')
        ->and(fetcher_git($root.'/checkout', ['rev-parse', 'refs/remotes/origin/main']))->toBe($base);
});

it('never runs a command planted in the checkout config while it fetches with a token', function (): void {
    $root = TestOrbitHome::scratch('orbit-fetch-planted');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $checkout]))->mustRun();
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Base']);
    fetcher_git($checkout, ['remote', 'add', 'origin', $root.'/origin.git']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/main', 'HEAD:refs/heads/release']);
    $base = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['update-ref', '-d', 'refs/remotes/origin/main']);
    $group = fetcher_group($checkout);
    PlantedGitConfig::plant($checkout, $root.'/markers', [$root.'/origin.git', 'git@github.com:acme/shop.git']);
    $fetcher = fetcher(new LocalShellSshExecutor);

    $fetcher->fetchForTurn($group);
    $fetcher->fetch($group, 'release');

    expect(fetcher_git($checkout, ['rev-parse', 'refs/remotes/origin/main']))->toBe($base)
        ->and(fetcher_git($checkout, ['rev-parse', 'refs/remotes/origin/release']))->toBe($base)
        ->and(PlantedGitConfig::markers($root.'/markers'))->toBe([]);
});

it('reports one failure when the base name is invalid or the fetch fails', function (string $base): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(128, '', 'fatal: not found', 1, false)]);

    expect(fn () => fetcher($transport)->fetch(fetcher_group('/srv/orbit/apps/shop/task-7'), $base))
        ->toThrow(TaskPullRequestException::class, 'The base branch could not be fetched.');
    // The Gateway mirror fetches first, so a failed fetch reaches no workspace.
    expect($transport->commands)->toBeEmpty();
})->with([
    'invalid name' => ['HEAD'],
    'failed fetch' => ['main'],
]);

it('fast-forwards a workspace that is strictly behind the task branch and leaves a level, ahead, or diverged one', function (string $case): void {
    $root = TestOrbitHome::scratch('orbit-fast-forward');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $checkout]))->mustRun();
    $group = fetcher_group($checkout);
    $branch = 'task-'.$group->id;
    fetcher_git($checkout, ['remote', 'add', 'origin', $root.'/origin.git']);
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Approved']);
    $approved = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/'.$branch]);
    $remote = $approved;
    if ($case === 'behind' || $case === 'diverged' || $case === 'behind-missing-ok') {
        fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Merge main']);
        $remote = fetcher_git($checkout, ['rev-parse', 'HEAD']);
        fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/'.$branch]);
        fetcher_git($checkout, ['reset', '--quiet', '--hard', $approved]);
        fetcher_git($checkout, ['update-ref', '-d', 'refs/remotes/origin/'.$branch]);
    }
    if ($case === 'ahead' || $case === 'diverged') {
        fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Local']);
    }
    $before = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', $approved.':refs/heads/main']);
    $fetcher = fetcher(new LocalShellSshExecutor);
    $fetcher->fetchForTurn($group);
    // Preparation must use the fetched refs, even if the remote is no longer reachable.
    fetcher_git($checkout, ['remote', 'set-url', 'origin', $root.'/unreachable.git']);

    $fetcher->fastForward($group, missingRefOk: $case === 'behind-missing-ok');

    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe(in_array($case, ['behind', 'behind-missing-ok'], true) ? $remote : $before)
        ->and(fetcher_git($checkout, ['rev-parse', 'refs/remotes/origin/'.$branch]))->toBe($remote)
        ->and(fetcher_git($checkout, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('task-7');
})->with(['behind', 'behind-missing-ok', 'level', 'ahead', 'diverged']);

it('fast-forwards the already-fetched task branch without a token, another fetch, or forcing the workspace', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');

    fetcher($transport)->fastForward($group);

    $command = $transport->commands[0];
    $script = (string) $command->input;
    expect($command->arguments)->toBe(['bash', '-seu', '--', '/srv/orbit/apps/shop/task-7', 'task-'.$group->id])
        ->and($command->protectedInput)->toBeNull()
        ->and($script)->not->toContain('fetch', 'ls-remote', 'token')
        ->and($script)->toContain('merge --ff-only')
        ->and($script)->not->toContain('reset')
        ->and($script)->not->toContain('push');
    Http::assertNothingSent();
});

it('reports one failure when fast-forwarding the task branch fails', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(128, '', 'fatal: not found', 1, false)]);

    expect(fn () => fetcher($transport)->fastForward(fetcher_group('/srv/orbit/apps/shop/task-7')))
        ->toThrow(TaskPullRequestException::class, 'The task branch could not be fetched.');
    expect($transport->commands)->toHaveCount(1);
});

it('passes missing-ok when a missing task branch should not fail the resume', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');

    fetcher($transport)->fastForward($group, missingRefOk: true);

    $script = (string) $transport->commands[0]->input;
    expect($transport->commands[0]->arguments)->toBe(['bash', '-seu', '--', '/srv/orbit/apps/shop/task-7', 'task-'.$group->id, 'missing-ok'])
        ->and($script)->toContain('show-ref --verify --quiet "refs/remotes/origin/$branch"')
        ->and($script)->toContain('missing-ok');
});

it('updates remote-tracking refs and does not move HEAD when the task branch or a tag is missing', function (bool $taskBranch, bool $staleRef): void {
    $root = TestOrbitHome::scratch('orbit-turn-fetch');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'workspace', $checkout]))->mustRun();
    $group = fetcher_group($checkout);
    $branch = 'task-'.$group->id;
    $remoteTask = null;
    fetcher_git($checkout, ['remote', 'add', 'origin', $root.'/origin.git']);
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Local']);
    $local = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Main']);
    $main = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['tag', 'demo']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/main']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'demo']);
    if ($taskBranch) {
        fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Task']);
        $remoteTask = fetcher_git($checkout, ['rev-parse', 'HEAD']);
        fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/'.$branch]);
    }
    fetcher_git($checkout, ['reset', '--quiet', '--hard', $local]);
    fetcher_git($checkout, ['tag', '-d', 'demo']);
    fetcher_git($checkout, ['update-ref', '-d', 'refs/remotes/origin/main']);
    if ($taskBranch) {
        fetcher_git($checkout, ['update-ref', '-d', 'refs/remotes/origin/'.$branch]);
    } elseif ($staleRef) {
        fetcher_git($checkout, ['update-ref', 'refs/remotes/origin/'.$branch, $main]);
    }

    fetcher(new LocalShellSshExecutor)->fetchForTurn($group->fresh() ?? $group);

    $tag = (new Process(['git', '-C', $checkout, 'tag', '--list', 'demo']))->mustRun();
    $missingTask = new Process(['git', '-C', $checkout, 'rev-parse', '--verify', '--quiet', 'refs/remotes/origin/'.$branch]);
    $missingTask->run();
    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($local)
        ->and(fetcher_git($checkout, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('workspace')
        ->and(fetcher_git($checkout, ['rev-parse', 'refs/remotes/origin/main']))->toBe($main)
        ->and(trim($tag->getOutput()))->toBe('');
    if ($taskBranch) {
        expect(fetcher_git($checkout, ['rev-parse', 'refs/remotes/origin/'.$branch]))->toBe($remoteTask);
    } else {
        expect($missingTask->getExitCode())->not->toBe(0);
        expect(fn () => fetcher(new LocalShellSshExecutor)->fastForward($group))
            ->toThrow(TaskPullRequestException::class, 'The task branch could not be fetched.');
        fetcher(new LocalShellSshExecutor)->fastForward($group, missingRefOk: true);
        expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($local);
    }
})->with([
    'missing task branch' => [false, false],
    'deleted task branch with a stale tracking ref' => [false, true],
    'task branch present' => [true, false],
]);

it('refreshes the default branch when only a nested branch shares the task name', function (): void {
    $root = TestOrbitHome::scratch('orbit-turn-fetch-suffix');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'workspace', $checkout]))->mustRun();
    $group = fetcher_group($checkout);
    $branch = 'task-'.$group->id;
    fetcher_git($checkout, ['remote', 'add', 'origin', $root.'/origin.git']);
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Local']);
    $local = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Main']);
    $main = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/main']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/archive/'.$branch]);
    fetcher_git($checkout, ['reset', '--quiet', '--hard', $local]);
    fetcher_git($checkout, ['update-ref', '-d', 'refs/remotes/origin/main']);

    fetcher(new LocalShellSshExecutor)->fetchForTurn($group->fresh() ?? $group);

    $missingTask = new Process(['git', '-C', $checkout, 'rev-parse', '--verify', '--quiet', 'refs/remotes/origin/'.$branch]);
    $missingTask->run();
    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($local)
        ->and(fetcher_git($checkout, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('workspace')
        ->and(fetcher_git($checkout, ['rev-parse', 'refs/remotes/origin/main']))->toBe($main)
        ->and($missingTask->getExitCode())->not->toBe(0);
});

it('does not run a fetch when the default branch name is invalid', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');
    $group->project->update(['default_branch' => 'HEAD']);

    expect(fn () => fetcher($transport)->fetchForTurn($group->fresh() ?? $group))
        ->toThrow(TaskPullRequestException::class, 'The workspace refs could not be fetched.');
    expect($transport->commands)->toHaveCount(0);
});

it('resets an untouched baseline workspace to the fetched default branch tip without changing its branch', function (): void {
    $root = TestOrbitHome::scratch('orbit-baseline-reset');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $checkout]))->mustRun();
    $group = fetcher_group($checkout);
    fetcher_git($checkout, ['remote', 'add', 'origin', $root.'/origin.git']);
    file_put_contents($checkout.'/tracked.txt', 'Original baseline');
    fetcher_git($checkout, ['add', 'tracked.txt']);
    fetcher_git($checkout, ['commit', '--quiet', '-m', 'Original baseline']);
    $original = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    file_put_contents($checkout.'/tracked.txt', 'Fixed baseline');
    fetcher_git($checkout, ['commit', '--quiet', '-am', 'Fix baseline']);
    $tip = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/main']);
    fetcher_git($checkout, ['reset', '--quiet', '--hard', $original]);
    fetcher_git($checkout, ['update-ref', '-d', 'refs/remotes/origin/main']);
    $bases = fetcher(new LocalShellSshExecutor);
    $bases->fetchForTurn($group);
    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($original);

    $head = $bases->resetToDefault($group);

    expect($head)->toBe($tip);
    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($tip);
    expect(fetcher_git($checkout, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('task-7');
    expect(file_get_contents($checkout.'/tracked.txt'))->toBe('Fixed baseline');
});

it('refuses to reset a baseline workspace that holds manual work', function (string $work): void {
    $root = TestOrbitHome::scratch('orbit-baseline-manual-work');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $checkout]))->mustRun();
    $group = fetcher_group($checkout);
    fetcher_git($checkout, ['remote', 'add', 'origin', $root.'/origin.git']);
    file_put_contents($checkout.'/tracked.txt', 'Original baseline');
    fetcher_git($checkout, ['add', 'tracked.txt']);
    fetcher_git($checkout, ['commit', '--quiet', '-m', 'Original baseline']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/main']);
    file_put_contents($checkout.'/tracked.txt', 'Manual work');
    match ($work) {
        'tracked change' => null,
        'staged change' => fetcher_git($checkout, ['add', 'tracked.txt']),
        'local commit' => fetcher_git($checkout, ['commit', '--quiet', '-am', 'Manual commit']),
        'other branch' => fetcher_git($checkout, ['checkout', '--quiet', '-b', 'manual']),
    };
    $head = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    $bases = fetcher(new LocalShellSshExecutor);
    $bases->fetchForTurn($group);

    expect(fn () => $bases->resetToDefault($group))
        ->toThrow(TaskPullRequestException::class, 'The baseline workspace could not be reset.');
    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($head)
        ->and(file_get_contents($checkout.'/tracked.txt'))->toBe('Manual work');
})->with(['tracked change', 'staged change', 'local commit', 'other branch']);

it('does not reset a baseline when the default branch name is invalid', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');
    $group->project->update(['default_branch' => 'HEAD']);

    expect(fn () => fetcher($transport)->resetToDefault($group->fresh() ?? $group))
        ->toThrow(TaskPullRequestException::class, 'The baseline workspace could not be reset.');
    expect($transport->commands)->toHaveCount(0);
});

it('leaves the workspace alone when the task branch is missing and that absence is allowed', function (): void {
    $root = TestOrbitHome::scratch('orbit-fast-forward-missing');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $checkout]))->mustRun();
    $group = fetcher_group($checkout);
    fetcher_git($checkout, ['remote', 'add', 'origin', $root.'/origin.git']);
    fetcher_git($checkout, ['commit', '--quiet', '--allow-empty', '-m', 'Local']);
    $before = fetcher_git($checkout, ['rev-parse', 'HEAD']);

    fetcher(new LocalShellSshExecutor)->fastForward($group, missingRefOk: true);

    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($before);
    expect(fn () => fetcher(new LocalShellSshExecutor)->fastForward($group))
        ->toThrow(TaskPullRequestException::class, 'The task branch could not be fetched.');
});

it('fast-forwards an incoming pull request branch instead of task-{id}', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');
    $group->update(['pr_branch' => 'cursor/login-throttle']);

    fetcher($transport)->fastForward($group);

    expect($transport->commands[0]->arguments)->toBe(['bash', '-seu', '--', '/srv/orbit/apps/shop/task-7', 'cursor/login-throttle']);
});

it('reads the merge base with the fetched default branch and moves a clean workspace to a pull request head', function (): void {
    $root = TestOrbitHome::scratch('orbit-pull-request-head');
    $checkout = $root.'/checkout';
    (new Process(['git', 'init', '--quiet', '--bare', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'task-7', $checkout]))->mustRun();
    $group = fetcher_group($checkout);
    $group->update(['pr_branch' => 'feature/login']);
    fetcher_git($checkout, ['remote', 'add', 'origin', $root.'/origin.git']);
    file_put_contents($checkout.'/tracked.txt', 'base');
    fetcher_git($checkout, ['add', 'tracked.txt']);
    fetcher_git($checkout, ['commit', '--quiet', '-m', 'Base']);
    $base = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/main']);
    file_put_contents($checkout.'/tracked.txt', 'feature');
    fetcher_git($checkout, ['commit', '--quiet', '-am', 'Feature']);
    $head = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['push', '--quiet', 'origin', 'HEAD:refs/heads/feature/login']);
    fetcher_git($checkout, ['reset', '--quiet', '--hard', $base]);
    fetcher_git($checkout, ['update-ref', '-d', 'refs/remotes/origin/feature/login']);
    $bases = fetcher(new LocalShellSshExecutor);
    $bases->fetchForTurn($group->fresh() ?? $group);

    $bases->moveTo($group, $head);

    expect(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($head)
        ->and(fetcher_git($checkout, ['rev-parse', '--abbrev-ref', 'HEAD']))->toBe('task-7')
        ->and($bases->mergeBase($group))->toBe($base);

    file_put_contents($checkout.'/tracked.txt', 'unsaved');

    expect(fn () => $bases->moveTo($group, $base))->toThrow(TaskPullRequestException::class, 'The workspace could not move to the pull request head.')
        ->and(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($head)
        ->and(file_get_contents($checkout.'/tracked.txt'))->toBe('unsaved');
});

it('refuses to move a workspace to a value that is not a commit SHA', function (): void {
    $transport = new AppDevFakeSshExecutor;

    expect(fn () => fetcher($transport)->moveTo(fetcher_group('/srv/orbit/apps/shop/task-7'), 'main'))
        ->toThrow(TaskPullRequestException::class, 'The pull request head is not a Git SHA.')
        ->and($transport->commands)->toBe([]);
});

it('merges main directly without hooks and aborts conflicts without losing the task tree', function (string $mode): void {
    $conflict = $mode === 'conflict';
    $overlap = $mode === 'overlap';
    $checkout = TestOrbitHome::scratch('mechanical-main-merge');
    mkdir($checkout, 0755, true);
    fetcher_git($checkout, ['init', '--quiet']);
    file_put_contents($checkout.'/shared.txt', "base\n".str_repeat("unchanged\n", 20)."end\n");
    fetcher_git($checkout, ['add', '.']);
    fetcher_git($checkout, ['commit', '--quiet', '-m', 'base']);
    $base = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    file_put_contents($checkout.'/shared.txt', "main\n".str_repeat("unchanged\n", 20)."end\n");
    fetcher_git($checkout, ['commit', '--quiet', '-am', 'main advances']);
    $main = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    fetcher_git($checkout, ['update-ref', 'refs/remotes/origin/main', $main]);
    $group = fetcher_group($checkout);
    fetcher_git($checkout, ['checkout', '--quiet', '-b', 'task-'.$group->id, $base]);
    file_put_contents($checkout.(($conflict || $overlap) ? '/shared.txt' : '/feature.txt'),
        $overlap ? "base\n".str_repeat("unchanged\n", 20)."feature\n" : "feature\n");
    fetcher_git($checkout, ['add', '.']);
    fetcher_git($checkout, ['commit', '--quiet', '-m', 'feature']);
    $head = fetcher_git($checkout, ['rev-parse', 'HEAD']);
    $hook = $checkout.'/.git/hooks/pre-merge-commit';
    file_put_contents($hook, "#!/bin/sh\nexit 91\n");
    chmod($hook, 0755);

    $merged = fetcher(new LocalShellSshExecutor)->mergeDefault($group);

    expect(fetcher_git($checkout, ['status', '--porcelain']))->toBe('');
    if ($conflict) {
        expect($merged->head)->toBeNull()
            ->and($merged->conflictingPaths)->toBe(['shared.txt'])
            ->and(fetcher_git($checkout, ['rev-parse', 'HEAD']))->toBe($head)
            ->and(file_get_contents($checkout.'/shared.txt'))->toBe("feature\n");
    } else {
        expect($merged->head)->not->toBe($head)
            ->and($merged->conflictingPaths)->toBe([])
            ->and(fetcher(new LocalShellSshExecutor)->overlappingPaths($group, $head, $merged->head))->toBe($overlap ? ['shared.txt'] : [])
            ->and(fetcher_git($checkout, ['rev-parse', 'HEAD^1']))->toBe($head)
            ->and(fetcher_git($checkout, ['rev-parse', 'HEAD^2']))->toBe($main)
            ->and(fetcher(new LocalShellSshExecutor)->mergeDefault($group)->head)->toBe($merged->head);
    }
})->with(['clean' => 'clean', 'conflict' => 'conflict', 'overlap' => 'overlap']);

it('retains uncertainty for a checkout move but not a timed-out merge-base read', function (bool $move): void {
    $group = fetcher_group('/srv/orbit/apps/shop/task-7');
    $lease = app(TaskWorkspaceLease::class)->acquire($group, 'publication', 'compare-or-move');
    $bases = fetcher(new AppDevFakeSshExecutor([new CommandResult(255, '', 'connection lost', 1, true)]));
    expect(fn () => $move ? $bases->moveTo($group, str_repeat('a', 40)) : $bases->mergeBase($group))->toThrow(TaskPullRequestException::class);
    expect($lease->fresh()->getAttribute('remote_uncertain'))->toBe($move)
        ->and($lease->fresh()->getAttribute('orbit_mutation'))->toBe($move);
})->with([false, true]);
