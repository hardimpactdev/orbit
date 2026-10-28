<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskReviewDiffException;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskReviewDiff;
use App\Infrastructure\Tasks\RemoteTaskReviewReset;
use App\Infrastructure\Tasks\TaskWorkspaceExecutor;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Symfony\Component\Process\Process;
use Tests\Support\AcceptingTaskWorkspaceMcp;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\TestOrbitHome;

beforeEach(function (): void {
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
});

afterEach(function (): void {
    TestOrbitHome::clearScratch();
});

it('reads tracked and untracked review diff without updating the index', function (): void {
    $checkout = TestOrbitHome::scratch('orbit-review-diff');
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    (new Process(['git', '-C', $checkout, 'config', 'user.email', 'test@example.com']))->mustRun();
    (new Process(['git', '-C', $checkout, 'config', 'user.name', 'Test']))->mustRun();
    (new Process(['git', '-C', $checkout, 'config', 'core.trustctime', 'false']))->mustRun();
    (new Process(['git', '-C', $checkout, 'config', 'core.checkStat', 'minimal']))->mustRun();
    file_put_contents($checkout.'/tracked.php', "<?php\nreturn 1;\n");
    touch($checkout.'/tracked.php', 1_700_000_000);
    (new Process(['git', '-C', $checkout, 'add', 'tracked.php']))->mustRun();
    (new Process(['git', '-C', $checkout, 'commit', '--quiet', '-m', 'start']))->mustRun();
    $start = trim((new Process(['git', '-C', $checkout, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
    touch($checkout.'/.git/index', 1_700_000_000);
    file_put_contents($checkout.'/tracked.php', "<?php\nreturn 2;\n");
    touch($checkout.'/tracked.php', 1_700_000_000);
    file_put_contents($checkout.'/untracked.php', "<?php\nreturn 'new';\n");
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'git@example.test:orbit.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'review-diff-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.144', 'wireguard_ip' => '10.44.0.144', 'user' => 'orbit']);
    $instance = test_ready_task_workspace($project, $node, ['name' => 'task-14', 'checkout_path' => $checkout, 'branch' => 'task-14']);
    $reader = new RemoteTaskReviewDiff(new TaskWorkspaceExecutor(new DevelopmentSshExecutor(
        new LocalShellSshExecutor,
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
    )));

    $diff = $reader->read($instance, $start);
    $cached = new Process(['git', '-C', $checkout, 'diff', '--cached', '--name-only']);
    $cached->run();

    expect($cached->getExitCode())->toBe(0)
        ->and(trim($cached->getOutput()))->toBe('')
        ->and($diff['files'])->toHaveCount(2)
        ->and($diff['files_complete'])->toBeTrue()
        ->and($diff['diff_available'])->toBeTrue()
        ->and($diff['summary'])->toBe(['files' => 2, 'insertions' => 3, 'deletions' => 1])
        ->and(array_column($diff['files'], 'path'))->toContain('tracked.php', 'untracked.php')
        ->and($diff['diff'])->toContain('return 2;')
        ->and($diff['diff'])->toContain("return 'new';");
});

it('keeps an untracked directory symlink visible without updating the index', function (string $path, string $reviewPath): void {
    $target = '../.agents/skills';
    $checkout = review_diff_checkout();
    mkdir($checkout.'/.agents/skills', 0755, true);
    file_put_contents($checkout.'/.agents/skills/example.md', "A tracked skill.\n");
    (new Process(['git', '-C', $checkout, 'add', '.agents']))->mustRun();
    (new Process(['git', '-C', $checkout, 'commit', '--quiet', '-m', 'skills']))->mustRun();
    $start = trim((new Process(['git', '-C', $checkout, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
    mkdir($checkout.'/.claude');
    symlink($target, $checkout.'/'.$path);
    file_put_contents($checkout.'/untracked.php', "<?php\nreturn 'new';\n");
    file_put_contents($checkout.'/tracked.php', "<?php\nreturn 2;\n");
    $indexBefore = file_get_contents($checkout.'/.git/index');
    $reader = review_diff_reader(new LocalShellSshExecutor);

    $diff = $reader->read(review_diff_instance($checkout), $start);

    expect($diff['files_complete'])->toBeTrue()
        ->and($diff['diff_available'])->toBeTrue()
        ->and(array_column($diff['files'], 'path'))->toContain($reviewPath, 'tracked.php', 'untracked.php')
        ->and($diff['summary'])->toBe(['files' => 3, 'insertions' => 4, 'deletions' => 1])
        ->and($diff['diff'])->toContain('new file mode 120000', '+'.$target, "return 'new';", 'return 2;')
        ->and(file_get_contents($checkout.'/.git/index'))->toBe($indexBefore)
        ->and((new Process(['git', '-C', $checkout, 'ls-files', '--others', '--exclude-standard', '-z']))->mustRun()->getOutput())->toContain($path);
})->with([
    'plain path' => ['.claude/skills', '.claude/skills'],
    'newline in path' => [".claude/skills\nextra", '.claude/skills\\nextra'],
    'tab and quote in path' => [".claude/skills\t\"extra", '.claude/skills\\t\\"extra'],
    'backslash in path' => ['.claude/skills\\extra', '.claude/skills\\\\extra'],
]);

it('reads untracked symlinks without following their targets or changing the index', function (string $target): void {
    $checkout = review_diff_checkout();
    $start = trim((new Process(['git', '-C', $checkout, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
    mkdir($checkout.'/skills');
    file_put_contents($checkout.'/skills/private.txt', "Do not include target contents.\n");
    file_put_contents($checkout.'/.gitignore', "skills/\n");
    (new Process(['git', '-C', $checkout, 'add', '.gitignore']))->mustRun();
    symlink($target, $checkout.'/skill-link');
    $index = file_get_contents($checkout.'/.git/index');

    $diff = review_diff_reader(new LocalShellSshExecutor)->read(review_diff_instance($checkout), $start);

    expect(file_get_contents($checkout.'/.git/index'))->toBe($index)
        ->and(array_column($diff['files'], 'path'))->toContain('skill-link')
        ->and($diff['diff'])->toContain('new file mode 120000', '+'.$target)
        ->and($diff['diff'])->not->toContain('Do not include target contents.')
        ->and($diff['summary'])->toBe(['files' => 2, 'insertions' => 2, 'deletions' => 0]);
})->with(['directory' => 'skills', 'file' => 'skills/private.txt', 'dangling' => 'missing']);

it('refuses a missing checkout, a missing base, and output that is not a diff', function (string $case): void {
    $checkout = review_diff_checkout();
    $start = trim((new Process(['git', '-C', $checkout, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
    $instance = review_diff_instance($checkout);
    $reader = match ($case) {
        'checkout' => review_diff_reader(new LocalShellSshExecutor),
        'base' => review_diff_reader(new LocalShellSshExecutor),
        'garbage' => review_diff_reader(review_diff_result(new CommandResult(0, "not a diff\n", '', 1, false))),
        'truncated' => review_diff_reader(review_diff_result(new CommandResult(0, "1\t0\tlate.php\n", '', 1, true))),
        default => throw new InvalidArgumentException($case),
    };
    if ($case === 'checkout') {
        $instance->update(['checkout_path' => '']);
    }
    $commit = match ($case) {
        'base' => str_repeat('c', 40),
        'checkout' => 'short',
        default => $start,
    };

    expect(fn () => $reader->read($instance->fresh() ?? $instance, $commit))->toThrow(TaskReviewDiffException::class);
})->with(['checkout', 'base', 'garbage', 'truncated']);

it('keeps the full counts when the captured output is only the tail', function (): void {
    $checkout = review_diff_checkout();
    $start = trim((new Process(['git', '-C', $checkout, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
    file_put_contents($checkout.'/tracked.php', "<?php\n".str_repeat("return 2;\n", 40));
    $reader = review_diff_reader(new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return (new NativeProcessRunner)->run(new ProcessInvocation(
                arguments: $command->arguments,
                input: $command->input,
                timeout: 30,
                maxOutputBytes: 180,
            ));
        }
    });

    $diff = $reader->read(review_diff_instance($checkout), $start);

    expect($diff['files'])->toBe([])
        ->and($diff['diff'])->toBe('')
        ->and($diff['files_complete'])->toBeFalse()
        ->and($diff['diff_available'])->toBeFalse()
        ->and($diff['summary']['files'])->toBe(1)
        ->and($diff['summary']['insertions'])->toBeGreaterThan(1)
        ->and($diff['summary']['deletions'])->toBeGreaterThan(0);
});

function review_diff_checkout(): string
{
    $checkout = TestOrbitHome::scratch('orbit-review-diff-'.bin2hex(random_bytes(4)));
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    (new Process(['git', '-C', $checkout, 'config', 'user.email', 'test@example.com']))->mustRun();
    (new Process(['git', '-C', $checkout, 'config', 'user.name', 'Test']))->mustRun();
    file_put_contents($checkout.'/tracked.php', "<?php\nreturn 1;\n");
    (new Process(['git', '-C', $checkout, 'add', 'tracked.php']))->mustRun();
    (new Process(['git', '-C', $checkout, 'commit', '--quiet', '-m', 'start']))->mustRun();

    return $checkout;
}

/** @param  array<string, mixed>  $group */
function review_diff_instance(string $checkout, array $group = []): Instance
{
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit-'.bin2hex(random_bytes(3)), 'repository_url' => 'git@example.test:orbit.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'review-diff-'.bin2hex(random_bytes(3)), 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.144', 'wireguard_ip' => '10.44.0.144', 'user' => 'orbit']);

    return test_ready_task_workspace($project, $node, ['name' => 'task-14', 'checkout_path' => $checkout, 'branch' => 'task-14'], $group);
}

function review_diff_reader(SshExecutor $ssh): RemoteTaskReviewDiff
{
    return new RemoteTaskReviewDiff(review_workspace_executor($ssh));
}

function review_workspace_executor(SshExecutor $ssh): TaskWorkspaceExecutor
{
    return new TaskWorkspaceExecutor(new DevelopmentSshExecutor(
        $ssh,
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
}

function review_diff_result(CommandResult $result): SshExecutor
{
    return new class($result) implements SshExecutor
    {
        public function __construct(private CommandResult $result) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return $this->result;
        }
    };
}

it('reviews only changes since an uncommitted reviewed tree', function (): void {
    $checkout = review_diff_checkout();
    file_put_contents($checkout.'/earlier.php', "<?php return 1;\n");
    (new Process(['git', 'add', '.'], $checkout))->mustRun();
    $tree = trim((new Process(['git', 'write-tree'], $checkout))->mustRun()->getOutput());
    (new Process(['git', 'reset', '--quiet'], $checkout))->mustRun();
    file_put_contents($checkout.'/tracked.php', "<?php return 3;\n");

    $diff = review_diff_reader(new LocalShellSshExecutor)->read(review_diff_instance($checkout), $tree);

    expect(array_column($diff['files'], 'path'))->toBe(['tracked.php'])
        ->and($diff['diff'])->toContain('return 3;')->not->toContain('earlier.php');
});

it('restores the checked commit and removes reviewer tracked and untracked changes', function (): void {
    $checkout = review_diff_checkout();
    $checked = trim((new Process(['git', '-C', $checkout, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
    file_put_contents($checkout.'/tracked.php', "<?php return 2;\n");
    (new Process(['git', '-C', $checkout, 'commit', '-am', 'reviewer commit', '--quiet']))->mustRun();
    file_put_contents($checkout.'/tracked.php', "<?php return 3;\n");
    mkdir($checkout.'/untracked');
    file_put_contents($checkout.'/untracked/new.txt', "reviewer scratch\n");
    $instance = review_diff_instance($checkout);
    $group = Task::topLevel()->findOrFail($instance->task_workspace_owner_id);
    app(TaskWorkspaceLease::class)->run($group, 'turn_result', 'test:reset', function () use ($instance, $checked): void {
        (new RemoteTaskReviewReset(review_workspace_executor(new LocalShellSshExecutor)))->reset($instance, $checked);
    });

    expect(trim((new Process(['git', '-C', $checkout, 'rev-parse', 'HEAD']))->mustRun()->getOutput()))->toBe($checked);
    expect(file_get_contents($checkout.'/tracked.php'))->toBe("<?php\nreturn 1;\n");
    expect(file_exists($checkout.'/untracked'))->toBeFalse();
    expect((new Process(['git', '-C', $checkout, 'status', '--porcelain']))->mustRun()->getOutput())->toBe('');
});
