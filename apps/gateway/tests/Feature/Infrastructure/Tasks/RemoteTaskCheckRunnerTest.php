<?php

declare(strict_types=1);

use App\Domain\AppDev\SsrPortAllocator;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\TiaBaselineSource;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskDeliverableEvidence;
use App\Domain\Tasks\TaskDeliverableVerifier;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolManagerException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Infrastructure\Tasks\TaskWorkspaceExecutor;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\FakeVitePortRuntime;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\TiaBaselineTestSource;

pest()->group('privileged');

function check_runner_checkout(string $check): string
{
    $checkout = test()->directory.'/'.bin2hex(random_bytes(6));
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    file_put_contents($checkout.'/composer.json', json_encode(['scripts' => ['check' => $check]], JSON_THROW_ON_ERROR));
    file_put_contents($checkout.'/.gitignore', "ignored/\n");
    (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'start'], $checkout))->mustRun();
    file_put_contents($checkout.'/uncommitted.php', "<?php\n");

    return $checkout;
}

function check_runner_instance(string $checkout): Instance
{
    $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'check-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.160', 'wireguard_ip' => '10.44.0.160', 'user' => 'orbit']);

    return Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-8', 'checkout_path' => $checkout, 'branch' => 'task-8', 'status' => 'source_resolved']);
}

function check_runner(SshExecutor $transport, string $vpHome = '/opt/orbit/vite-plus', ?SshExecutor $probe = null): RemoteTaskCheckRunner
{
    if ($transport instanceof LocalShellSshExecutor) {
        $transport->environment['HOME'] = test()->directory.'/home';
    }
    $vp = new VpToolManager(
        new RemoteToolCommandRunner(
            $probe ?? new class($vpHome) implements SshExecutor
            {
                public function __construct(private string $home) {}

                public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
                {
                    return new CommandResult(0, $this->home."/bin/vp\n", '', 1, false);
                }
            },
            app(SshKeyProvider::class),
            app(KnownHostsStore::class),
        ),
        new SemverVersionNormalizer,
    );

    return new RemoteTaskCheckRunner(new TaskWorkspaceExecutor(new DevelopmentSshExecutor(
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
    )), $vp, app(GatewaySetupSteps::class), app(RepositoryPullRequestAccess::class));
}

function check_runner_wait(RemoteTaskCheckRunner $runner, Instance $instance, TaskCheckProcess $process): TaskCheckReading
{
    for ($attempt = 0; $attempt < 150; $attempt++) {
        $reading = $runner->read($instance, $process);
        if ($reading->state !== 'running') {
            return $reading;
        }
        usleep(100_000);
    }

    throw new RuntimeException('The check did not finish.');
}

/**
 * Run the real `check status` with a stubbed process lookup. The lookup answers whether the check is alive,
 * and first runs `$meanwhile`, so a test can place a result write at the moment status looks up the process.
 *
 * @return array<string, mixed>
 */
function check_runner_status(bool $alive, string $meanwhile = ''): array
{
    $script = test()->directory.'/script/check';
    File::ensureDirectoryExists(dirname($script));
    File::copy(resource_path('tasks/check'), $script);
    $status = new Process(['python3', '-c', <<<'PYTHON'
        import importlib.machinery, importlib.util, sys
        loader = importlib.machinery.SourceFileLoader('check', sys.argv[1])
        check = importlib.util.module_from_spec(importlib.util.spec_from_loader('check', loader))
        loader.exec_module(check)
        def process_started(pid):
            exec(sys.argv[3], {'check': check})
            return 'Wed Sep 23 12:00:00 2026' if sys.argv[2] == 'alive' else None
        check.process_started = process_started
        check.status(7, 'Wed Sep 23 12:00:00 2026')
        PYTHON, $script, $alive ? 'alive' : 'gone', $meanwhile]);

    return json_decode($status->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('normalizes VP_HOME probe failures without launching a check', function (int $exit, bool $truncated, string $stdout): void {
    $instance = check_runner_instance('/fast/apps/shop/task-8');
    $probe = new AppDevFakeSshExecutor([new CommandResult($exit, $stdout, '', 1, $truncated)]);
    $transport = new AppDevFakeSshExecutor;
    $runner = check_runner($transport, probe: $probe);

    expect(fn () => $runner->start($instance, 'true'))->toThrow(function (TaskCheckException $exception): void {
        expect($exception->getPrevious())->toBeInstanceOf(ToolManagerException::class);
        expect($exception->getMessage())->toBe('The task workspace could not be reached for the check.');
    });
    expect($transport->commands)->toBeEmpty();
    expect($probe->commands)->toHaveCount(1);
})->with([
    'absent' => [42, false, ''],
    'conflict' => [43, false, ''],
    'failed' => [1, false, ''],
    'truncated' => [0, true, "/opt/orbit/vite-plus/bin/vp\n"],
    'malformed' => [0, false, '/tmp/untrusted/vp'],
]);

it('releases the unstarted baseline reservation and retries after a VP_HOME probe timeout', function (): void {
    test_bind_snapshot_driver();
    $instance = check_runner_instance('/fast/apps/shop/task-8');
    $group = Task::topLevel()->create([
        'project_id' => $instance->project_id, 'title' => 'Probe timeout', 'brief' => 'Retry baseline',
        'status' => TaskGroupStatus::Running, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $group = test_vm_group($group);
    $task = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'First', 'brief' => 'First subtask', 'status' => TaskStatus::Running, 'subtask_start_commit' => 'abc',
    ]);
    $timeout = new ProcessTimedOutException(new Process(['ssh', 'probe']), ProcessTimedOutException::TYPE_GENERAL);
    $probe = new class($timeout) implements SshExecutor
    {
        public int $calls = 0;

        public function __construct(private ProcessTimedOutException $timeout) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            expect(TaskCheck::query()->sole()->pid)->toBe(0);
            if (++$this->calls === 1) {
                throw $this->timeout;
            }

            return new CommandResult(0, "/opt/orbit/vite-plus/bin/vp\n", '', 1, false);
        }
    };
    $transport = new class implements SshExecutor
    {
        public int $starts = 0;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            if (str_contains($command->input ?? '', ' start "$checkout"')) {
                $this->starts++;

                return new CommandResult(0, '{"pid":4100,"started":"started","head":"abc","tree":"def"}', '', 1, false);
            }

            return new CommandResult(0, '{"head":"abc","tree":"def"}', '', 1, false);
        }
    };
    app()->instance(TaskCheckRunner::class, check_runner($transport, probe: $probe));

    test_pass_baseline();

    expect(TaskCheck::query()->count())->toBe(0);
    expect($transport->starts)->toBe(0);
    expect($task->fresh()->communication_failures)->toBe(1);
    expect($group->fresh()->assistance_requested)->toBeFalse();

    test_pass_baseline();

    expect(TaskCheck::query()->sole()->pid)->toBe(4100);
    expect($probe->calls)->toBe(2);
    expect($transport->starts)->toBe(1);
    expect($task->fresh()->communication_failures)->toBe(0);
    expect($group->fresh()->assistance_requested)->toBeFalse();
});

it('normalizes thrown failures only during the known unstarted VP_HOME probe', function (string $failure): void {
    $instance = check_runner_instance('/fast/apps/shop/task-8');
    $exception = $failure === 'timeout'
        ? new ProcessTimedOutException(new Process(['ssh', 'probe']), ProcessTimedOutException::TYPE_GENERAL)
        : new RuntimeException('SSH transport failed');
    $failing = new class($exception) implements SshExecutor
    {
        public function __construct(private Throwable $exception) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            throw $this->exception;
        }
    };
    $transport = new AppDevFakeSshExecutor;

    expect(fn () => check_runner($transport, probe: $failing)->start($instance, 'true'))
        ->toThrow(function (TaskCheckException $normalized) use ($exception): void {
            expect($normalized->getPrevious())->toBe($exception);
        });
    expect($transport->commands)->toBeEmpty();

    $runner = check_runner($failing);
    $process = new TaskCheckProcess(4100, 'started', 'abc', 'def');
    foreach ([fn () => $runner->start($instance, 'true'), fn () => $runner->read($instance, $process), fn () => $runner->cancel($instance, $process), fn () => $runner->snapshot($instance)] as $operation) {
        try {
            $operation();
            $this->fail('The remote transport failure must escape unchanged.');
        } catch (Throwable $unchanged) {
            expect($unchanged)->toBe($exception);
        }
    }
})->with(['timeout', 'transport failure']);

it('polls and cancels independently of VP_HOME probe availability', function (): void {
    $instance = check_runner_instance('/fast/apps/shop/task-8');
    $probe = new AppDevFakeSshExecutor([new CommandResult(42, '', '', 1, false)]);
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, '{"state":"running"}', '', 1, false),
        new CommandResult(0, '{}', '', 1, false),
        new CommandResult(0, '{"head":"abc","tree":"def"}', '', 1, false),
    ]);
    $runner = check_runner($transport, probe: $probe);
    $process = new TaskCheckProcess(4100, 'started', 'abc', 'def');

    expect($runner->read($instance, $process)->state)->toBe('running');
    $runner->cancel($instance, $process);
    expect($runner->snapshot($instance)->head)->toBe('abc');
    expect($probe->commands)->toBeEmpty();
    expect($transport->commands)->toHaveCount(3);
});

it('exports resolved VP_HOME to non-login task setup and check processes', function (string $home): void {
    $checkout = check_runner_checkout('true');
    File::ensureDirectoryExists($checkout.'/node_modules/.bin');
    file_put_contents($checkout.'/node_modules/.bin/vp', "#!/bin/sh\nprintf '%s' \"\$VP_HOME\" > \"\$1\"\n");
    chmod($checkout.'/node_modules/.bin/vp', 0755);
    $runner = check_runner(new LocalShellSshExecutor, $home);
    $instance = check_runner_instance($checkout);
    $process = $runner->start($instance, 'bash -eu -c \'./node_modules/.bin/vp check-home\'', [
        ['name' => 'VP_HOME setup', 'command' => 'bash -eu -c \'./node_modules/.bin/vp setup-home\'', 'timeout_seconds' => 10],
    ]);
    $reading = check_runner_wait($runner, $instance, $process);

    expect($reading->exitCode)->toBe(0);
    expect(file_get_contents($checkout.'/setup-home'))->toBe($home);
    expect(file_get_contents($checkout.'/check-home'))->toBe($home);
})->with(['/opt/orbit/vite-plus', '/home/orbit/.local/share/vite-plus', '/home/orbit/.vite-plus']);

describe('TaskGitHardening', function (): void {
    it('replaces input-file symlinks without overwriting their targets as the Node user', function (): void {
        $checkout = check_runner_checkout('true');
        File::ensureDirectoryExists($checkout.'/.git/orbit');
        $target = $this->directory.'/node-file';
        file_put_contents($target, 'managed file');
        symlink($target, $checkout.'/.git/orbit/check-command');
        $runner = check_runner(new LocalShellSshExecutor);
        $instance = check_runner_instance($checkout);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'true'));

        expect($reading->exitCode)->toBe(0)
            ->and(file_get_contents($target))->toBe('managed file')
            ->and(is_link($checkout.'/.git/orbit/check-command'))->toBeFalse();
    });

    it('does not run a planted fsmonitor when the Gateway snapshots and checks the workspace', function (): void {
        $checkout = check_runner_checkout('true');
        $monitor = $checkout.'/.git/planted-monitor';
        file_put_contents($monitor, "#!/bin/sh\nprintf ran >> '$checkout/.git/fsmonitor-ran'\n");
        chmod($monitor, 0755);
        (new Process(['git', '-C', $checkout, 'config', 'core.fsmonitor', $monitor]))->mustRun();
        $runner = check_runner(new LocalShellSshExecutor);
        $instance = check_runner_instance($checkout);

        $runner->snapshot($instance);
        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'true'));

        expect($reading->exitCode)->toBe(0)
            ->and(file_exists($checkout.'/.git/fsmonitor-ran'))->toBeFalse();
    });
});

beforeEach(function (): void {
    // Each test owns one directory, so a concurrent run on the same machine keeps its checkouts.
    $this->directory = sys_get_temp_dir().'/orbit-task-check-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->directory.'/home');
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('runs login shells with the fixture account home and profile', function (): void {
    $checkout = check_runner_checkout('true');
    $instance = check_runner_instance($checkout);
    File::put($this->directory.'/home/.profile', "export ORBIT_TEST_LOGIN_HOME=fixture-user-home\n");
    $runner = check_runner(new LocalShellSshExecutor);

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'printf "%s" "$ORBIT_TEST_LOGIN_HOME"'));

    expect($reading->exitCode)->toBe(0)
        ->and($reading->output)->toEndWith('fixture-user-home');
});

it('BaseMerge distinguishes merged-only source failures from missing executables without changing the checkout', function (string $failure): void {
    $checkout = check_runner_checkout('true');
    $git = static fn (array $arguments): string => trim((new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', ...$arguments], $checkout))->mustRun()->getOutput());
    File::ensureDirectoryExists($checkout.'/docs');
    file_put_contents($checkout.'/format', 'v1');
    file_put_contents($checkout.'/docs/original.txt', 'v1');
    $git(['add', '.']);
    $git(['commit', '-qm', 'initial generated docs']);
    $git(['branch', 'main-fixture']);
    file_put_contents($checkout.'/format', 'v2');
    file_put_contents($checkout.'/docs/original.txt', 'v2');
    $git(['commit', '-qam', 'new generator format']);
    $feature = $git(['rev-parse', 'HEAD']);
    $git(['checkout', '-q', 'main-fixture']);
    file_put_contents($checkout.'/docs/new.txt', 'v1');
    $git(['add', '.']);
    $git(['commit', '-qm', 'main adds generated docs']);
    $main = $git(['rev-parse', 'HEAD']);
    $git(['checkout', '-q', '--detach', $feature]);
    $git(['merge', '--no-ff', '-qm', 'mechanical main merge', $main]);
    $merged = $git(['rev-parse', 'HEAD']);
    $command = match ($failure) {
        'merged docs' => 'for file in docs/*.txt; do grep -q "$(cat format)" "$file" || { echo "$file is stale"; exit 1; }; done',
        'merged long output' => 'if test -f docs/new.txt && test "$(cat format)" = v2; then echo merged-tree-failure; exit 1; fi; python3 -c \'print("parent noise" * 10000)\'',
        'pre-existing long output' => 'if test -f docs/new.txt && test "$(cat format)" = v2; then echo original-environment-failure; exit 1; fi; python3 -c \'print("parent noise" * 10000)\'; exit 1',
        'merged class' => 'php -r \'if (is_file("docs/new.txt") && file_get_contents("format") === "v2") { new AddedFeature; }\'',
        'merged resource' => 'php -r \'if (is_file("docs/new.txt") && file_get_contents("format") === "v2") { require "missing-resource.php"; }\'',
        'pre-existing' => 'echo baseline check failed; exit 1',
        'missing shellcheck' => 'PATH=/nonexistent shellcheck',
        'wrapped missing shellcheck' => 'PATH=/nonexistent shellcheck; exit 1',
        'required shellcheck' => 'echo "shellcheck: required tool not found"; exit 1',
    };
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $reading = check_runner_wait($runner, $instance, $runner->start($instance, $command, deliverables: ['start' => null, 'commands' => [], 'base_merge' => true]));

    expect($reading->failedStep)->toBe(str_starts_with($failure, 'merged ') ? 'gate' : 'environment')
        ->and($reading->exitCode)->not->toBe(0)
        ->and($reading->headAfter)->toBe($merged)
        ->and($reading->treeAfter)->toBe($reading->treeBefore)
        ->and($git(['status', '--porcelain']))->toBe('')
        ->and($git(['worktree', 'list', '--porcelain']))->not->toContain('orbit-base-');
    $diagnostic = match ($failure) {
        'merged docs' => 'docs/new.txt is stale',
        'merged long output' => 'merged-tree-failure',
        'pre-existing long output' => 'original-environment-failure',
        'merged class' => 'Class "AddedFeature" not found',
        'merged resource' => 'No such file or directory',
        'pre-existing' => 'baseline check failed',
        'required shellcheck' => 'shellcheck: required tool not found',
        default => 'shellcheck: command not found',
    };
    expect($reading->output)->toContain($diagnostic);
    expect($reading->output)->not->toContain('# main merge gate parent', 'parent noise');
})->with(['merged docs', 'merged long output', 'merged class', 'merged resource', 'pre-existing', 'pre-existing long output', 'missing shellcheck', 'wrapped missing shellcheck', 'required shellcheck']);

it('BaseMerge reuses only a matching handoff tree and gives slow parents the Project gate timeout', function (bool $matching): void {
    $checkout = check_runner_checkout('true');
    $git = static fn (array $arguments): string => trim((new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', ...$arguments], $checkout))->mustRun()->getOutput());
    $git(['branch', 'main-fixture']);
    file_put_contents($checkout.'/feature', 'approved feature');
    $git(['add', '.']);
    $git(['commit', '-qm', 'feature']);
    $feature = $git(['rev-parse', 'HEAD']);
    $tree = $git(['rev-parse', 'HEAD^{tree}']);
    $git(['checkout', '-q', 'main-fixture']);
    file_put_contents($checkout.'/main', 'main changes');
    $git(['add', '.']);
    $git(['commit', '-qm', 'main']);
    $main = $git(['rev-parse', 'HEAD']);
    $git(['checkout', '-q', '--detach', $feature]);
    $git(['merge', '--no-ff', '-qm', 'mechanical merge', $main]);
    $head = $git(['rev-parse', 'HEAD']);
    $harness = $this->directory.'/merge-parents.py';
    file_put_contents($harness, <<<'PYTHON'
import importlib.machinery
import importlib.util
import json
import sys

loader = importlib.machinery.SourceFileLoader('task_check', sys.argv[1])
module = importlib.util.module_from_spec(importlib.util.spec_from_loader('task_check', loader))
loader.exec_module(module)
# Scale the command-deliverable limit down; parent gates must not inherit it.
module.BASE_RUN_TIMEOUT = 0.01
copies = []
original_copy = module.copy_dependencies
def copy_dependencies(checkout, scratch):
    copies.append(scratch)
    original_copy(checkout, scratch)
module.copy_dependencies = copy_dependencies
with open(sys.argv[5], 'wb') as log:
    result = module.classify_merge_gate(sys.argv[2], sys.argv[3], 'sleep 0.05; echo parent-passed', 1,
                                        'merged-tree-failure', log, sys.argv[4])
print(json.dumps({'classification': result, 'copies': len(copies)}))
PYTHON);
    $log = $this->directory.'/parents.log';
    $probe = new Process(['python3', $harness, resource_path('tasks/check'), $checkout, $head, $matching ? $tree : str_repeat('f', 40), $log], base_path(), ['HOME' => $this->directory.'/home']);
    $result = json_decode($probe->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toBe(['classification' => 'gate', 'copies' => $matching ? 1 : 2])
        ->and(file_get_contents($log))->toContain($main, 'parent-passed')
        ->and($git(['status', '--porcelain']))->toBe('')
        ->and($git(['worktree', 'list', '--porcelain']))->not->toContain('orbit-base-');
    if ($matching) {
        expect(file_get_contents($log))->toContain('reused passing handoff tree '.$tree);
    }
})->with(['matching approved tree' => true, 'different tree' => false]);

it('runs composer check detached and reports running, then the exit code and output', function (string $check, int $exitCode, string $output): void {
    $checkout = check_runner_checkout($check);
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $status = (new Process(['git', 'status', '--porcelain'], $checkout))->mustRun()->getOutput();

    $process = $runner->start($instance, 'composer check');
    $first = $runner->read($instance, $process);
    $reading = check_runner_wait($runner, $instance, $process);

    expect(fileperms($checkout.'/.git/orbit') & 0777)->toBe(0775);
    expect($process->pid)->toBeGreaterThan(1)
        ->and($process->head)->toBe(trim((new Process(['git', 'rev-parse', 'HEAD'], $checkout))->mustRun()->getOutput()))
        ->and($first->state)->toBe('running')
        ->and($reading->state)->toBe('finished')
        ->and($reading->exitCode)->toBe($exitCode)
        ->and($reading->output)->toContain($output)
        ->and($reading->treeAfter)->toBe($process->tree)
        ->and($reading->changedPaths)->toBe([])
        ->and($reading->finishedAt)->toBeFloat()
        ->and($reading->finishedAt)->toBeLessThanOrEqual(microtime(true))
        ->and((new Process(['git', 'status', '--porcelain'], $checkout))->mustRun()->getOutput())->toBe($status);
})->with([
    'passing' => ['sleep 1 && echo all checks passed', 0, 'all checks passed'],
    'failing' => ['sleep 1 && echo PHPStan found 2 errors && exit 2', 2, 'PHPStan found 2 errors'],
]);

it('reports the paths a check changed in the working tree, but not ignored files', function (): void {
    $checkout = check_runner_checkout('sleep 1 && mkdir -p ignored && echo x > ignored/cache && echo y > written.txt');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check'));

    expect($reading->exitCode)->toBe(0)
        ->and($reading->changedPaths)->toBe(['written.txt']);
});

it('snapshots same-size edits whose timestamp matches the original index without changing that index', function (): void {
    $checkout = check_runner_checkout('true');
    $timestamp = 1_700_000_000;
    (new Process(['git', 'config', 'core.trustctime', 'false'], $checkout))->mustRun();
    file_put_contents($checkout.'/state', "old\n");
    touch($checkout.'/state', $timestamp);
    (new Process(['git', 'add', '--all'], $checkout))->mustRun();
    (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'base'], $checkout))->mustRun();
    file_put_contents($checkout.'/state', "new\n");
    touch($checkout.'/state', $timestamp);
    // Reproduce Git's racy-clean window without waiting for a clock boundary.
    touch($checkout.'/.git/index', $timestamp);
    $index = file_get_contents($checkout.'/.git/index');
    $runner = check_runner(new LocalShellSshExecutor);
    $instance = check_runner_instance($checkout);

    $snapshot = $runner->snapshot($instance);

    expect((new Process(['git', 'show', $snapshot->tree.':state'], $checkout))->mustRun()->getOutput())->toBe("new\n");
    expect(file_get_contents($checkout.'/.git/index'))->toBe($index)
        ->and(filemtime($checkout.'/.git/index'))->toBe($timestamp);
});

it('keeps an untracked directory symlink in snapshots and reports one created during the handoff check', function (): void {
    $checkout = check_runner_checkout('true');
    $target = $this->directory.'/target';
    File::ensureDirectoryExists($target);
    file_put_contents($target.'/not-in-checkout.txt', "outside\n");
    symlink($target, $checkout.'/existing-link');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $index = file_get_contents($checkout.'/.git/index');
    $snapshot = $runner->snapshot($instance);

    $process = $runner->start($instance, 'ln -s '.escapeshellarg($target).' created-link');
    $reading = check_runner_wait($runner, $instance, $process);

    expect($process->tree)->toBe($snapshot->tree);
    expect($reading->state)->toBe('finished')
        ->and($reading->exitCode)->toBe(0)
        ->and($reading->failedStep)->toBeNull()
        ->and($reading->changedPaths)->toBe(['created-link'])
        ->and($reading->treeAfter)->not->toBe($snapshot->tree);
    expect((new Process(['git', 'ls-tree', $snapshot->tree, '--', 'existing-link'], $checkout))->mustRun()->getOutput())
        ->toStartWith('120000 blob ')->toContain("\texisting-link\n");
    expect((new Process(['git', 'ls-tree', $reading->treeAfter, '--', 'created-link'], $checkout))->mustRun()->getOutput())
        ->toStartWith('120000 blob ')->toContain("\tcreated-link\n");
    expect((new Process(['git', 'ls-files', '--others', '--exclude-standard', '-z'], $checkout))->mustRun()->getOutput())
        ->toContain("existing-link\0", "created-link\0")->not->toContain('not-in-checkout.txt');
    expect(file_get_contents($checkout.'/.git/index'))->toBe($index);
});

it('stops the check process group on cancel, which leaves the check without a result', function (): void {
    $checkout = check_runner_checkout('sleep 30');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $process = $runner->start($instance, 'composer check');

    $runner->cancel($instance, $process);

    expect(check_runner_wait($runner, $instance, $process)->state)->toBe('lost');
});

it('does not take a reused process ID for the check', function (): void {
    $checkout = check_runner_checkout('sleep 30');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $process = $runner->start($instance, 'composer check');

    $reading = $runner->read($instance, new TaskCheckProcess($process->pid, 'Thu Jan  1 00:00:00 1970', $process->head, $process->tree));
    $runner->cancel($instance, $process);

    expect($reading->state)->toBe('lost');
});

it('reports an unreachable workspace and invalid output as check failures', function (CommandResult $result, string $message): void {
    $runner = check_runner(new AppDevFakeSshExecutor([$result]));

    expect(fn () => $runner->read(check_runner_instance('/srv/orbit/apps/shop/task-8'), new TaskCheckProcess(7, 'started', 'head', 'tree')))
        ->toThrow(TaskCheckException::class, $message);
})->with([
    'unreachable' => [new CommandResult(255, '', 'Connection refused', 1, false), 'The task workspace could not be reached for the check.'],
    'invalid output' => [new CommandResult(0, 'not json', '', 1, false), 'The check answered with invalid output.'],
]);

it('reads a finished status larger than the 64 KiB process default', function (): void {
    $cases = array_map(static fn (int $index): array => ['name' => 'case '.$index.' '.str_repeat('x', 150), 'status' => 'passed'], range(1, 400));
    $status = json_encode(['state' => 'finished', 'output' => str_repeat('o', 16_384), 'result' => [
        'exit_code' => 0,
        'head_after' => str_repeat('a', 40),
        'tree_after' => str_repeat('b', 40),
        'changed_paths' => [],
        'deliverables' => ['commands' => ['repro' => ['exit_code' => 0, 'output' => implode("\n", array_column($cases, 'name'))]]],
    ]], JSON_THROW_ON_ERROR);
    expect(strlen($status))->toBeGreaterThan(65_536);
    $transport = new class($status) implements SshExecutor
    {
        public function __construct(private string $status) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return new CommandResult(0, substr($this->status, 0, $command->maxOutputBytes ?? 65_536), '', 1, false);
        }
    };

    $reading = check_runner($transport)->read(check_runner_instance('/srv/orbit/apps/shop/task-8'), new TaskCheckProcess(7, 'started', 'head', 'tree'));

    expect($reading->state)->toBe('finished')
        ->and($reading->exitCode)->toBe(0);
});

it('reads the same working tree as the check without touching the index', function (): void {
    $checkout = check_runner_checkout('echo ok');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $status = (new Process(['git', 'status', '--porcelain'], $checkout))->mustRun()->getOutput();

    $process = $runner->start($instance, 'echo ok');
    $runner->cancel($instance, $process);
    chmod($checkout.'/.git/orbit', 0755);
    $snapshot = $runner->snapshot($instance);
    clearstatcache();

    expect(fileperms($checkout.'/.git/orbit') & 0777)->toBe(0775);
    expect($snapshot->head)->toBe($process->head)
        ->and($snapshot->tree)->toBe($process->tree)
        ->and($snapshot->parent)->toBeNull()
        ->and($snapshot->commitTree)->toBe(trim((new Process(['git', 'rev-parse', 'HEAD^{tree}'], $checkout))->mustRun()->getOutput()))
        ->and((new Process(['git', 'status', '--porcelain'], $checkout))->mustRun()->getOutput())->toBe($status);

    file_put_contents($checkout.'/extra.txt', "extra\n");
    $written = (new Process(['git', 'status', '--porcelain'], $checkout))->mustRun()->getOutput();
    $changed = $runner->snapshot($instance);
    File::ensureDirectoryExists($checkout.'/ignored');
    file_put_contents($checkout.'/ignored/cache', "cache\n");
    $ignored = $runner->snapshot($instance);

    expect($changed->head)->toBe($snapshot->head)
        ->and($changed->tree)->not->toBe($snapshot->tree)
        ->and($ignored->head)->toBe($changed->head)
        ->and($ignored->tree)->toBe($changed->tree)
        ->and((new Process(['git', 'status', '--porcelain'], $checkout))->mustRun()->getOutput())->toBe($written);
});

it('reports an unreachable workspace when the review tree cannot be read', function (CommandResult $result, string $message): void {
    $runner = check_runner(new AppDevFakeSshExecutor([$result]));

    expect(fn () => $runner->snapshot(check_runner_instance('/srv/orbit/apps/shop/task-8')))
        ->toThrow(TaskCheckException::class, $message);
})->with([
    'unreachable' => [new CommandResult(255, '', 'Connection refused', 1, false), 'The task workspace could not be reached for the workspace tree.'],
    'invalid output' => [new CommandResult(0, 'not json', '', 1, false), 'The workspace tree could not be read.'],
]);

it('passes the seed stored when the check starts to asynchronous baseline setup', function (bool $seeded): void {
    $checkout = check_runner_checkout('true');
    $instance = check_runner_instance($checkout);
    $instance->update(['seed_selected' => true, 'seed_path' => $this->directory.'/releases/old', 'seed_commit' => str_repeat('f', 40)]);
    $path = $seeded ? $this->directory."/seed with 'quote" : null;
    $commit = $seeded ? str_repeat('a', 40) : null;
    // A default's conversion moves the stored seed after the scheduler loaded the workspace.
    Instance::query()->whereKey($instance->id)->update(['seed_path' => $path, 'seed_commit' => $commit]);
    $runner = check_runner(new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $process = new Process($command->arguments, null, [
                'ORBIT_SEED_PATH' => 'inherited-unrelated-seed', 'ORBIT_SEED_COMMIT' => 'inherited-unrelated-commit',
            ], $command->input);
            $process->run();

            return new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
        }
    });
    $process = $runner->start($instance, 'true', [[
        'name' => 'read seed', 'command' => 'printf "path=<%s> commit=<%s>" "$ORBIT_SEED_PATH" "$ORBIT_SEED_COMMIT" > seed-observed', 'timeout_seconds' => 10,
    ]]);
    expect(check_runner_wait($runner, $instance, $process)->exitCode)->toBe(0)
        ->and(file_get_contents($checkout.'/seed-observed'))->toBe('path=<'.($path ?? '').'> commit=<'.($commit ?? '').'>');
})->with(['seeded' => true, 'empty fallback' => false]);

it('runs setup steps in order before composer check, and records the tree after setup', function (): void {
    $checkout = check_runner_checkout('test -f ignored/installed && echo checked');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', [
        ['name' => 'Install', 'command' => 'mkdir -p ignored && touch ignored/installed', 'timeout_seconds' => 60],
        ['name' => 'Notes', 'command' => 'echo notes > notes.txt', 'timeout_seconds' => 60],
    ]));

    expect($reading->exitCode)->toBe(0)
        ->and($reading->failedStep)->toBeNull()
        ->and($reading->output)->toContain("$ mkdir -p ignored && touch ignored/installed\n$ echo notes > notes.txt\n$ composer check\n")
        ->and($reading->output)->toContain('checked')
        ->and($reading->treeBefore)->toBe($reading->treeAfter)
        ->and($reading->changedPaths)->toBe([]);
});

it('runs setup payloads larger than the operating system argument limit', function (): void {
    $checkout = check_runner_checkout('test -f ignored/baseline && echo checked');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $payload = str_repeat('x', 512 * 1024);

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', [
        ['name' => 'Restore baseline', 'command' => "mkdir -p ignored; cat > ignored/baseline <<'BASELINE'\n".$payload."\nBASELINE\nprintf '%s' \"\$0\" > ignored/setup-script\n", 'timeout_seconds' => 10],
    ]));

    expect($reading->exitCode)->toBe(0)
        ->and($reading->failedStep)->toBeNull()
        ->and(file_get_contents($checkout.'/ignored/baseline'))->toBe($payload."\n")
        ->and(file_exists(file_get_contents($checkout.'/ignored/setup-script')))->toBeFalse()
        ->and($reading->output)->toContain('checked')
        ->and(file_get_contents($checkout.'/.git/orbit/check.log'))->toContain('more characters of the command are not shown]')
        ->and(filesize($checkout.'/.git/orbit/check.log'))->toBeLessThan(16 * 1024);
});

it('removes setup scripts after failure or timeout and skips later work', function (string $command, int $exit): void {
    $checkout = check_runner_checkout('touch check-ran');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', [
        ['name' => 'Stop', 'command' => 'mkdir -p ignored; printf "%s" "$0" > ignored/setup-script; '.$command, 'timeout_seconds' => 1],
        ['name' => 'Never', 'command' => 'touch never-ran', 'timeout_seconds' => 10],
    ]));

    expect($reading->exitCode)->toBe($exit)
        ->and($reading->failedStep)->toBe('Stop')
        ->and(file_exists(file_get_contents($checkout.'/ignored/setup-script')))->toBeFalse()
        ->and(file_exists($checkout.'/never-ran'))->toBeFalse()
        ->and(file_exists($checkout.'/check-ran'))->toBeFalse();
})->with([
    'failure' => ['exit 5', 5],
    'timeout' => ['sleep 10', 124],
    'timeout with closed output' => ['exec >/dev/null 2>&1; sleep 10', 124],
]);

it('stops at the first failing setup step without running composer check', function (): void {
    $checkout = check_runner_checkout('echo checked');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', [
        ['name' => 'Install', 'command' => 'echo cannot install && exit 5', 'timeout_seconds' => 60],
        ['name' => 'Never', 'command' => 'touch never-ran', 'timeout_seconds' => 60],
    ]));

    expect($reading->exitCode)->toBe(5)
        ->and($reading->failedStep)->toBe('Install')
        ->and($reading->output)->toContain('cannot install')
        ->and($reading->output)->not->toContain('composer check')
        ->and(file_exists($checkout.'/never-ran'))->toBeFalse();
});

it('reports a check that writes its result and exits while status looks it up as finished, not lost', function (): void {
    $status = check_runner_status(alive: false, meanwhile: 'with open(check.RESULT, "w") as result: result.write(\'{"exit_code": 5}\')');

    expect($status['state'])->toBe('finished')
        ->and($status['result']['exit_code'])->toBe(5);
});

it('keeps a check running while the wall-clock start time of its process moves, as on a freshly booted VM', function (): void {
    $script = test()->directory.'/script/check';
    File::ensureDirectoryExists(dirname($script));
    File::copy(resource_path('tasks/check'), $script);
    $status = new Process(['python3', '-c', <<<'PYTHON'
        import importlib.machinery, importlib.util, json, subprocess, sys
        loader = importlib.machinery.SourceFileLoader('check', sys.argv[1])
        check = importlib.util.module_from_spec(importlib.util.spec_from_loader('check', loader))
        loader.exec_module(check)
        child = subprocess.Popen(['sleep', '30'])
        try:
            started = check.process_started(child.pid)
            with open(f'/proc/{child.pid}/stat') as source:
                ticks = source.read().rsplit(')', 1)[1].split()[19]
            # The boot time settles: ps now derives a start time one second later for the same process.
            real = subprocess.run
            def run(arguments, *rest, **options):
                if arguments[:1] == ['ps']:
                    return subprocess.CompletedProcess(arguments, 0, 'Mon Oct 12 22:34:02 2026\n', '')
                return real(arguments, *rest, **options)
            check.subprocess.run = run
            check.status(child.pid, started)
            print(json.dumps({'started': started, 'ticks': ticks}))
        finally:
            child.kill()
            child.wait()
        check.status(child.pid, started)
        PYTHON, $script]);
    $lines = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($status->mustRun()->getOutput())));

    expect($lines[0]['state'])->toBe('running')
        ->and($lines[1]['started'])->toBe($lines[1]['ticks'])
        ->and($lines[2]['state'])->toBe('lost');
});

it('reports a live check without a result as running, and a gone check without a result as lost', function (): void {
    expect(check_runner_status(alive: true)['state'])->toBe('running')
        ->and(check_runner_status(alive: false)['state'])->toBe('lost');
});

/**
 * A checkout with one committed project, then uncommitted work on top.
 *
 * @return array{0: string, 1: string} the checkout and its start commit
 */
function check_runner_deliverables_checkout(string $check = 'echo checks passed'): array
{
    $checkout = check_runner_checkout($check);
    file_put_contents($checkout.'/.gitignore', "ignored/\nvendor/\n");
    File::ensureDirectoryExists($checkout.'/app/tests');
    file_put_contents($checkout.'/app/tests/OldTest.php', "<?php\n");
    file_put_contents($checkout.'/README.md', "# Shop\n");
    (new Process(['git', 'add', '--all'], $checkout))->mustRun();
    (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'project'], $checkout))->mustRun();
    $start = trim((new Process(['git', 'rev-parse', 'HEAD'], $checkout))->mustRun()->getOutput());
    file_put_contents($checkout.'/app/tests/ExportTest.php', "<?php\n");
    file_put_contents($checkout.'/README.md', "# Shop\n\nExports.\n");
    unlink($checkout.'/app/tests/OldTest.php');

    return [$checkout, $start];
}

describe('topology diff evidence', function (): void {
    it('records only the group changes against main, including the candidate working tree', function (bool $merge, string $own): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $git = static fn (array $arguments): string => trim((new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', ...$arguments], $checkout))->mustRun()->getOutput());
        $git(['add', '--all']);
        $git(['commit', '--quiet', '-m', 'Group documentation']);
        $git(['branch', 'task']);
        $git(['checkout', '--quiet', '-b', 'upstream', $start]);
        File::ensureDirectoryExists($checkout.'/apps/agent/src');
        file_put_contents($checkout.'/apps/agent/src/main.rs', "// Released on main\n");
        $git(['add', '--all']);
        $git(['commit', '--quiet', '-m', 'Released Node change']);
        $git(['update-ref', 'refs/remotes/origin/main', 'HEAD']);
        $git(['checkout', '--quiet', 'task']);
        $ownPath = 'apps/agent/src/own.rs';
        if ($own === 'committed') {
            File::ensureDirectoryExists(dirname($checkout.'/'.$ownPath));
            file_put_contents($checkout.'/'.$ownPath, "// Group Node change\n");
            $git(['add', '--all']);
            $git(['commit', '--quiet', '-m', 'Group Node change']);
        }
        if ($merge) {
            $git(['merge', '--quiet', '--no-ff', '--no-edit', 'origin/main']);
        }
        if ($own === 'untracked') {
            file_put_contents($checkout.'/'.$ownPath, "// Group Node change\n");
        }
        if ($own === 'resolution') {
            $ownPath = 'apps/agent/src/main.rs';
            file_put_contents($checkout.'/'.$ownPath, "// Group merge resolution\n");
            $git(['add', '--all']);
            $git(['commit', '--quiet', '--amend', '--no-edit']);
        }
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, null, deliverables: [
            'start' => $start, 'commands' => [], 'topology' => true,
        ]));

        $expected = [
            ['status' => 'M', 'path' => 'README.md'],
            ['status' => 'A', 'path' => 'app/tests/ExportTest.php'],
            ['status' => 'D', 'path' => 'app/tests/OldTest.php'],
        ];
        if ($own !== 'none') {
            $expected[] = ['status' => $own === 'resolution' ? 'M' : 'A', 'path' => $ownPath];
        }
        expect($reading->exitCode)->toBe(0)
            ->and($reading->deliverables['topology_diff'] ?? null)->toEqualCanonicalizing($expected);
        if ($merge && $own === 'none') {
            expect($reading->deliverables['diff'])->toContain(['status' => 'A', 'path' => 'apps/agent/src/main.rs']);
        }
    })->with([
        'main merge only' => [true, 'none'],
        'own Node change without merging main' => [false, 'committed'],
        'main merge with own Node commit' => [true, 'committed'],
        'main merge with untracked Node file' => [true, 'untracked'],
        'main merge with own resolution of a main Node path' => [true, 'resolution'],
    ]);

    it('records an unreadable topology diff when origin main is missing', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, null, deliverables: [
            'start' => $start, 'commands' => [], 'topology' => true,
        ]));

        expect($reading->deliverables)->toHaveKey('topology_diff', null)
            ->and($reading->deliverables['diff'])->not->toBeNull();
    });
});

describe('deliverable evidence', function (): void {
    it('reuses the task check result for a matching fixup deliverable without running it twice', function (int $exit): void {
        [$checkout, $start] = check_runner_deliverables_checkout('mkdir -p ignored; echo run >> ignored/runs; echo project-output; exit '.$exit);
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);
        $deliverables = ['start' => $start, 'commands' => [
            ['id' => 'project-check', 'command' => 'composer check', 'directory' => '.'],
            ['id' => 'other', 'command' => 'touch ignored/other-ran', 'directory' => '.'],
        ]];

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', deliverables: $deliverables));
        $evidence = TaskDeliverableEvidence::fromArray($reading->deliverables);
        $deliverable = TaskDeliverable::fromArray([
            'id' => 'project-check', 'type' => 'command', 'description' => 'Run the Project task check',
            'command' => 'composer check', 'directory' => '.',
        ]);

        expect(file_get_contents($checkout.'/ignored/runs'))->toBe("run\n")
            ->and($reading->exitCode)->toBe($exit)
            ->and($reading->treeBefore)->toBe($reading->treeAfter)
            ->and($evidence->commands['project-check']['exit_code'])->toBe($exit)
            ->and($evidence->commands['project-check']['output'])->toContain('Reused Project task check on tree '.$reading->treeBefore, 'project-output')
            ->and(TaskDeliverableVerifier::failures([$deliverable], $evidence) === [])->toBe($exit === 0)
            ->and(file_exists($checkout.'/ignored/other-ran'))->toBe($exit === 0);
    })->with(['passing check' => 0, 'failing check' => 7]);

    it('still runs the base reproduction while reusing the passing working-tree check', function (): void {
        $checkout = check_runner_checkout('true');
        File::ensureDirectoryExists($checkout.'/tests');
        file_put_contents($checkout.'/state', "old\n");
        file_put_contents($checkout.'/tests/check.sh', "mkdir -p ignored; echo run >> ignored/runs; echo checked; grep -q new state\n");
        (new Process(['git', 'add', '--all'], $checkout))->mustRun();
        (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'base'], $checkout))->mustRun();
        $start = trim((new Process(['git', 'rev-parse', 'HEAD'], $checkout))->mustRun()->getOutput());
        file_put_contents($checkout.'/state', "new\n");
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'bash tests/check.sh', deliverables: [
            'start' => $start, 'commands' => [[
                'id' => 'project-check', 'command' => 'bash tests/check.sh', 'directory' => '.',
                'fails_on_base' => true, 'paths' => ['tests/check.sh'],
            ]],
        ]));

        expect(file_get_contents($checkout.'/ignored/runs'))->toBe("run\n")
            ->and($reading->deliverables['commands']['project-check']['exit_code'])->toBe(0)
            ->and($reading->deliverables['commands']['project-check']['base_exit_code'])->toBe(1)
            ->and($reading->deliverables['commands']['project-check']['base_output'])->toContain('checked');
    });

    it('runs project-check as a normal deliverable when no task check is configured', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, null, deliverables: [
            'start' => $start, 'commands' => [['id' => 'project-check', 'command' => 'echo snapshot-check', 'directory' => '.']],
        ]));

        expect($reading->deliverables['commands']['project-check'])->toBe(['exit_code' => 0, 'output' => "snapshot-check\n"]);
    });

    it('runs a snapshotted project-check separately when the current task check changed', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'echo current-check', deliverables: [
            'start' => $start, 'commands' => [['id' => 'project-check', 'command' => 'echo snapshot-check', 'directory' => '.']],
        ]));

        expect($reading->output)->toContain('current-check', 'snapshot-check')
            ->and($reading->deliverables['commands']['project-check'])->toBe(['exit_code' => 0, 'output' => "snapshot-check\n"]);
    });

    it('does not reuse the root check for the same command in another directory', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'basename "$PWD"', deliverables: [
            'start' => $start, 'commands' => [['id' => 'project-check', 'command' => 'basename "$PWD"', 'directory' => 'app']],
        ]));

        expect($reading->deliverables['commands']['project-check'])->toBe(['exit_code' => 0, 'output' => "app\n"]);
    });

    it('refuses reuse on a changed tree without repeating the project check', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout('mkdir -p ignored; echo run >> ignored/runs; echo changed >> README.md');
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', deliverables: [
            'start' => $start, 'commands' => [['id' => 'project-check', 'command' => 'composer check', 'directory' => '.']],
        ]));

        expect(file_get_contents($checkout.'/ignored/runs'))->toBe("run\n")
            ->and($reading->treeBefore)->not->toBe($reading->treeAfter)
            ->and($reading->deliverables['commands']['project-check']['exit_code'])->toBe(1)
            ->and($reading->deliverables['commands']['project-check']['output'])->toContain('cannot be reused');
    });

    it('runs the task check anew on the next handoff even on an unchanged tree', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout('mkdir -p ignored; echo run >> ignored/runs');
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);
        $deliverables = ['start' => $start, 'commands' => [['id' => 'project-check', 'command' => 'composer check', 'directory' => '.']]];
        check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', deliverables: $deliverables));

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', deliverables: $deliverables));

        expect(file_get_contents($checkout.'/ignored/runs'))->toBe("run\nrun\n")
            ->and($reading->deliverables['commands']['project-check']['exit_code'])->toBe(0);
    });

    it('fails on base command when the base tree does not satisfy the overlaid command paths', function (): void {
        $checkout = check_runner_checkout('echo checks passed');
        File::ensureDirectoryExists($checkout.'/app/tests');
        file_put_contents($checkout.'/app/state', "old\n");
        file_put_contents($checkout.'/app/tests/check.sh', "#!/usr/bin/env bash\necho base-script\ngrep -q old state\n");
        chmod($checkout.'/app/tests/check.sh', 0755);
        (new Process(['git', 'add', '--all'], $checkout))->mustRun();
        (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'base'], $checkout))->mustRun();
        $start = trim((new Process(['git', 'rev-parse', 'HEAD'], $checkout))->mustRun()->getOutput());
        file_put_contents($checkout.'/app/state', "new\n");
        file_put_contents($checkout.'/app/tests/check.sh', "#!/usr/bin/env bash\necho overlay-script\ngrep -q new state\n");
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'echo checks passed', [], [
            'start' => $start,
            'commands' => [['id' => 'base-repro', 'command' => 'bash tests/check.sh', 'directory' => 'app', 'fails_on_base' => true, 'paths' => ['app/tests/check.sh']]],
        ]));

        expect($reading->deliverables['commands']['base-repro'])->toBe([
            'exit_code' => 0,
            'output' => "overlay-script\n",
            'base_started' => true,
            'base_exit_code' => 1,
            'base_output' => "overlay-script\n",
        ]);
    });

    it('treats a missing command in the base tree as unable to reproduce', function (): void {
        $checkout = check_runner_checkout('echo checks passed');
        file_put_contents($checkout.'/status', "missing\n");
        File::ensureDirectoryExists($checkout.'/tests');
        file_put_contents($checkout.'/tests/check.sh', "#!/usr/bin/env bash\ncd \"$(dirname \"$0\")/..\"\nif grep -q missing status; then command-that-does-not-exist; else exit 0; fi\n");
        (new Process(['git', 'add', '--all'], $checkout))->mustRun();
        (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'base'], $checkout))->mustRun();
        $start = trim((new Process(['git', 'rev-parse', 'HEAD'], $checkout))->mustRun()->getOutput());
        file_put_contents($checkout.'/status', "available\n");
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'echo checks passed', [], [
            'start' => $start,
            'commands' => [['id' => 'missing-base-command', 'command' => 'bash tests/check.sh', 'directory' => '.', 'fails_on_base' => true, 'paths' => ['tests/check.sh']]],
        ]));

        expect($reading->deliverables['commands']['missing-base-command'])->toMatchArray([
            'exit_code' => 0,
            'base_started' => true,
            'base_exit_code' => 127,
        ]);
        $deliverable = TaskDeliverable::fromArray([
            'id' => 'missing-base-command', 'type' => 'command', 'description' => 'Run the check',
            'command' => 'bash tests/check.sh', 'directory' => '.', 'fails_on_base' => true, 'paths' => ['tests/check.sh'],
        ]);
        $evidence = TaskDeliverableEvidence::fromArray([
            'diff' => [],
            'commands' => ['missing-base-command' => $reading->deliverables['commands']['missing-base-command']],
        ]);
        expect(TaskDeliverableVerifier::failures([$deliverable], $evidence))->not->toBe([]);
    });

    it('does not substitute HEAD when the resolved start commit is unavailable', function (): void {
        [$checkout] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'echo checks passed', [], [
            'start' => str_repeat('f', 40),
            'commands' => [['id' => 'base-repro', 'command' => 'true', 'directory' => '.', 'fails_on_base' => true, 'paths' => ['README.md']]],
        ]));

        expect($reading->deliverables['commands']['base-repro'])->toBe([
            'exit_code' => 0,
            'output' => '',
            'base_started' => false,
            'base_exit_code' => 127,
            'base_output' => 'Could not extract the start commit.',
        ]);
    });

    it('kills a timed-out base process group and preserves its partial output', function (): void {
        $harness = $this->directory.'/base-timeout.py';
        file_put_contents($harness, <<<'PYTHON'
import importlib.machinery
import importlib.util
import json
import os
import sys
import time

loader = importlib.machinery.SourceFileLoader('task_check', sys.argv[1])
module = importlib.util.module_from_spec(importlib.util.spec_from_loader('task_check', loader))
loader.exec_module(module)
workdir = sys.argv[2]
group_file = os.path.join(workdir, 'process-group')
log_path = os.path.join(workdir, 'check.log')
command = f'echo $$ > {group_file}; trap "exit 0" TERM; (trap "" TERM; echo child-ready; exec sleep 30) & wait'
with open(log_path, 'wb') as log:
    code, output, timed_out = module.run_base(command, workdir, log, 0.5)
process_group = int(open(group_file).read())
deadline = time.monotonic() + 2
while module.process_group_exists(process_group) and time.monotonic() < deadline:
    time.sleep(0.05)
print(json.dumps({
    'code': code,
    'timed_out': timed_out,
    'output': output,
    'log': open(log_path, 'rb').read().decode(),
    'group_exists': module.process_group_exists(process_group),
}))
PYTHON);
        $probe = new Process(['python3', $harness, resource_path('tasks/check'), $this->directory], base_path());
        $result = json_decode($probe->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($result['code'])->toBeNull()
            ->and($result['timed_out'])->toBeTrue()
            ->and($result['output'])->toContain('child-ready')
            ->and($result['log'])->toContain('child-ready')
            ->and($result['log'])->toContain('Base command timed out after 0.5 seconds.')
            ->and($result['group_exists'])->toBeFalse();
    });

    it('records the diff and runs each command after a passing check', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', [], [
            'start' => $start,
            'commands' => [
                ['id' => 'lint', 'command' => 'echo linted && exit 3', 'directory' => 'app'],
                ['id' => 'pwd', 'command' => 'basename "$PWD"', 'directory' => '.'],
            ],
        ]));

        expect($reading->exitCode)->toBe(0)
            ->and($reading->changedPaths)->toBe([])
            ->and($reading->deliverables['diff'])->toEqualCanonicalizing([
                ['status' => 'M', 'path' => 'README.md'],
                ['status' => 'A', 'path' => 'app/tests/ExportTest.php'],
                ['status' => 'D', 'path' => 'app/tests/OldTest.php'],
            ])
            ->and($reading->deliverables['commands'])->toBe([
                'lint' => ['exit_code' => 3, 'output' => "linted\n"],
                'pwd' => ['exit_code' => 0, 'output' => basename($checkout)."\n"],
            ]);
    });

    it('records no evidence when composer check fails', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout('exit 1');
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', [], ['start' => $start, 'commands' => [['id' => 'lint', 'command' => 'touch ran', 'directory' => '.']]]));

        expect($reading->exitCode)->toBe(1)
            ->and($reading->deliverables)->toBeNull()
            ->and(file_exists($checkout.'/ran'))->toBeFalse();
    });

    it('refuses a command directory outside the workspace', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', [], ['start' => $start, 'commands' => [['id' => 'escape', 'command' => 'touch escaped', 'directory' => '../..']]]));

        expect($reading->failedStep)->toBe('invalid_deliverable')
            ->and($reading->deliverables)->toBeNull()
            ->and($reading->output)->toContain('Deliverable escape names a directory outside the checkout.');
    });

    it('rejects a missing base command overlay path before the project check runs', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'touch project-check-ran', [], [
            'start' => $start,
            'commands' => [['id' => 'repro', 'command' => 'true', 'directory' => '.', 'fails_on_base' => true, 'paths' => ['missing.sh']]],
        ]));

        expect($reading->state)->toBe('finished')
            ->and($reading->exitCode)->not->toBe(0)
            ->and($reading->failedStep)->toBe('invalid_deliverable')
            ->and($reading->changedPaths)->toBe([])
            ->and($reading->deliverables)->toBeNull()
            ->and($reading->output)->toContain('Deliverable repro names invalid overlay path missing.sh.')
            ->and(file_exists($checkout.'/project-check-ran'))->toBeFalse();
    });
});

it('writes a failed result when the check script hits an unexpected error', function (): void {
    $checkout = check_runner_checkout('echo ok');
    $script = test()->directory.'/script/check';
    File::ensureDirectoryExists(dirname($script));
    File::copy(resource_path('tasks/check'), $script);
    $deliverables = test()->directory.'/not-a-file';
    File::ensureDirectoryExists($deliverables);
    $ran = new Process(['python3', '-c', <<<'PYTHON'
        import importlib.machinery, importlib.util, sys
        loader = importlib.machinery.SourceFileLoader('check', sys.argv[1])
        check = importlib.util.module_from_spec(importlib.util.spec_from_loader('check', loader))
        loader.exec_module(check)
        checkout, deliverables = sys.argv[2], sys.argv[3]
        check.run(checkout, check.git(checkout, 'rev-parse', 'HEAD'), check.working_tree(checkout), '-', deliverables, 'touch project-check-ran')
        PYTHON, $script, $checkout, $deliverables]);

    $ran->mustRun();
    $result = json_decode((string) file_get_contents(dirname($script).'/check.json'), true, flags: JSON_THROW_ON_ERROR);
    $log = (string) file_get_contents(dirname($script).'/check.log');

    expect($result['exit_code'])->not->toBe(0)
        ->and($result['failed_step'])->toBe('check_error')
        ->and($result['deliverables'])->toBeNull()
        ->and($log)->toContain('Traceback (most recent call last):')
        ->and(trim($log))->toContain('IsADirectoryError')
        ->and(file_exists($checkout.'/project-check-ran'))->toBeFalse();
});

describe('fails_on_base command deliverables', function (): void {
    it('fails verification when the command also passes on the start commit', function (): void {
        $deliverable = TaskDeliverable::fromArray([
            'id' => 'base-repro', 'type' => 'command', 'description' => 'The behavior fails on base',
            'command' => 'bash tests/check.sh', 'directory' => 'app', 'fails_on_base' => true, 'paths' => ['app/tests/check.sh'],
        ]);
        $evidence = TaskDeliverableEvidence::fromArray([
            'diff' => [],
            'commands' => ['base-repro' => ['base_started' => true, 'base_exit_code' => 0, 'base_output' => '', 'exit_code' => 0, 'output' => '']],
        ]);

        expect(TaskDeliverableVerifier::failures([$deliverable], $evidence))->toBe([
            'base-repro (command): `bash tests/check.sh` in app also exited 0 on the start commit, so it does not reproduce the failure.',
        ]);
    });
});

it('cleans stale base archive directories without registering worktrees or removing unrelated files', function (): void {
    $checkout = check_runner_checkout('echo ok');
    $gitDir = trim((new Process(['git', 'rev-parse', '--absolute-git-dir'], $checkout))->mustRun()->getOutput());
    File::ensureDirectoryExists($gitDir.'/orbit/bases/orbit-base-leftover');
    file_put_contents($gitDir.'/orbit/bases/orbit-base-leftover/marker', "x\n");
    File::ensureDirectoryExists($gitDir.'/orbit/bases/notes');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'echo ok'));

    expect($reading->exitCode)->toBe(0)
        ->and(is_dir($gitDir.'/orbit/bases/orbit-base-leftover'))->toBeFalse()
        ->and(is_dir($gitDir.'/orbit/bases/notes'))->toBeTrue()
        ->and(substr_count((string) (new Process(['git', 'worktree', 'list'], $checkout))->mustRun()->getOutput(), "\n"))->toBe(1);
});

/**
 * @return array<string, mixed>
 */
function omitted_command_check_result(string $resultPath): array
{
    for ($attempt = 0; $attempt < 100; $attempt++) {
        if (is_file($resultPath)) {
            $decoded = json_decode((string) file_get_contents($resultPath), true);
            if (is_array($decoded) && array_key_exists('finished_at', $decoded)) {
                return $decoded;
            }
        }
        usleep(50_000);
    }

    throw new RuntimeException('The check did not finish.');
}

it('runs no project command when start and run omit the command file, and still checks the tree and deliverables', function (): void {
    $checkout = check_runner_checkout('touch composer-check-ran');
    $script = test()->directory.'/helper/check';
    File::ensureDirectoryExists(dirname($script));
    File::copy(resource_path('tasks/check'), $script);
    $deliverables = test()->directory.'/deliverables.json';
    file_put_contents($deliverables, json_encode([
        'start' => null,
        'commands' => [['id' => 'hello', 'command' => 'touch deliverable-ran', 'directory' => '.']],
    ], JSON_THROW_ON_ERROR));
    $resultPath = dirname($script).'/check.json';
    $logPath = dirname($script).'/check.log';
    $snapshot = json_decode((new Process(['python3', $script, 'snapshot', $checkout]))->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot)->toBeArray();

    (new Process(['python3', $script, 'run', $checkout, $snapshot['head'], $snapshot['tree'], '-', $deliverables]))->mustRun();
    $ran = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);
    $ranLog = (string) file_get_contents($logPath);

    expect($ran['exit_code'])->toBe(0)
        ->and($ran['failed_step'])->toBeNull()
        ->and($ran['head_before'])->toBe($snapshot['head'])
        ->and($ran['tree_before'])->toBe($snapshot['tree'])
        ->and($ran['tree_after'])->not->toBe($snapshot['tree'])
        ->and($ran['changed_paths'])->toBe(['deliverable-ran'])
        ->and($ran['deliverables']['commands']['hello']['exit_code'])->toBe(0)
        ->and($ranLog)->not->toContain('composer check')
        ->and(is_file($checkout.'/composer-check-ran'))->toBeFalse()
        ->and(is_file($checkout.'/deliverable-ran'))->toBeTrue();

    File::delete([$resultPath, $logPath, $checkout.'/deliverable-ran']);
    (new Process(['python3', $script, 'start', $checkout, '-', $deliverables]))->mustRun();
    $started = omitted_command_check_result($resultPath);
    $startedLog = (string) file_get_contents($logPath);

    expect($started['exit_code'])->toBe(0)
        ->and($started['failed_step'])->toBeNull()
        ->and($started['head_before'])->not->toBe('')
        ->and($started['tree_before'])->not->toBe('')
        ->and($started['tree_after'])->not->toBe($started['tree_before'])
        ->and($started['changed_paths'])->toBe(['deliverable-ran'])
        ->and($started['deliverables']['commands']['hello']['exit_code'])->toBe(0)
        ->and($startedLog)->not->toContain('composer check')
        ->and(is_file($checkout.'/composer-check-ran'))->toBeFalse()
        ->and(is_file($checkout.'/deliverable-ran'))->toBeTrue();
});

it('restores the builtin TIA baseline after dependency setup before a task check', function (): void {
    app()->instance(TiaBaselineSource::class, new TiaBaselineTestSource);
    $checkout = check_runner_checkout('true');
    $target = $checkout.'/.pest/tia';
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $setup = [
        ['name' => 'dependencies', 'command' => 'mkdir -p vendor/bin; printf %s '.escapeshellarg('<?php echo '.var_export($target."\n", true).';').' > vendor/bin/pest', 'timeout_seconds' => 10],
        ['name' => 'baseline', 'command' => LifecycleStep::RestoreTiaBaseline, 'timeout_seconds' => 30],
    ];
    $process = $runner->start($instance, 'test -f .pest/tia/graph.json', $setup);
    $reading = check_runner_wait($runner, $instance, $process);
    expect($reading->state)->toBe('finished')->and($reading->exitCode)->toBe(0);
    expect(file_get_contents($target.'/graph.json'))->toContain(str_repeat('a', 40));
});

it('passes the handoff test base only to a scoped project check', function (bool $scoped, bool $tree): void {
    $checkout = check_runner_checkout('true');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $base = trim((new Process(['git', 'rev-parse', $tree ? 'HEAD^{tree}' : 'HEAD'], $checkout))->mustRun()->getOutput());
    $command = $scoped ? 'test "$ORBIT_TASK_CHECK_BASE" = '.escapeshellarg($base) : 'test -z "${ORBIT_TASK_CHECK_BASE+set}"';
    $deliverables = $scoped ? ['start' => $base, 'commands' => [], 'test_base' => $base] : null;

    $process = $runner->start($instance, $command, deliverables: $deliverables);
    $reading = check_runner_wait($runner, $instance, $process);

    expect($reading->exitCode)->toBe(0);
})->with(['scoped commit' => [true, false], 'scoped tree' => [true, true], 'unscoped' => [false, false]]);

describe('ssr port', function (): void {
    it('starts the check of two workspaces on one Node with their own allocated ssr ports and never the held 13719', function (): void {
        $node = Node::query()->create(['name' => 'shared-check-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.161', 'wireguard_ip' => '10.44.0.161', 'user' => 'orbit']);
        orbit_test_set_app_placement_role($node, false);
        $runtime = new FakeVitePortRuntime;
        $runtime->occupied[$node->id] = range(SsrPortAllocator::FIRST_PORT, 13719);
        app()->instance(VitePortRuntime::class, $runtime);
        $project = Project::query()->create(['name' => 'Recall', 'slug' => 'recall', 'repository_url' => 'git@github.com:acme/recall.git', 'default_branch' => 'main']);
        $started = '{"pid":4100,"started":"now","head":"abc","tree":"def"}';
        $transport = new AppDevFakeSshExecutor([new CommandResult(0, $started, '', 1, false), new CommandResult(0, $started, '', 1, false)]);
        $runner = check_runner($transport);
        $ports = [];

        foreach (['task-a', 'task-b'] as $name) {
            $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => "/fast/apps/recall/{$name}", 'branch' => $name, 'status' => 'source_resolved']);
            $ports[] = app(SsrPortAllocator::class)->assign($instance);
            $runner->start($instance, 'composer check');
        }

        expect($ports)->toBe([13720, 13721])
            ->and($transport->commands[0]->input)->toContain("export ORBIT_SSR_PORT='13720'\nexport INERTIA_SSR_URL='http://127.0.0.1:13720'\n")
            ->and($transport->commands[1]->input)->toContain("export ORBIT_SSR_PORT='13721'\nexport INERTIA_SSR_URL='http://127.0.0.1:13721'\n")
            ->and($transport->commands[0]->input.$transport->commands[1]->input)->not->toContain('13719');
    });

    it('hands the allocated ssr port to setup, the check, and deliverable commands', function (): void {
        $checkout = check_runner_checkout('printf "%s %s" "$ORBIT_SSR_PORT" "$INERTIA_SSR_URL" > check-ssr');
        $instance = check_runner_instance($checkout);
        $instance->forceFill(['ssr_port' => 13722])->save();
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', [
            ['name' => 'ssr setup', 'command' => 'printf "%s" "$INERTIA_SSR_URL" > setup-ssr', 'timeout_seconds' => 10],
        ], ['commands' => [['id' => 'ssr', 'command' => 'printf "%s" "$ORBIT_SSR_PORT"', 'directory' => '.', 'fails_on_base' => false, 'paths' => []]]]));

        expect($reading->exitCode)->toBe(0)
            ->and(file_get_contents($checkout.'/setup-ssr'))->toBe('http://127.0.0.1:13722')
            ->and(file_get_contents($checkout.'/check-ssr'))->toBe('13722 http://127.0.0.1:13722')
            ->and($reading->deliverables['commands']['ssr']['output'] ?? null)->toBe('13722');
    });
});

it('authenticates task VM setup through protected input and redacts tokens before writing logs', function (string $ending, int $exitCode): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_setup_sentinel'], 201),
    ]);
    $checkout = check_runner_checkout('true');
    $instance = check_runner_instance($checkout);
    TaskVm::query()->create(['node_id' => $instance->node_id, 'provider' => 'upcloud', 'name' => 'tvm-setup', 'state' => 'ready', 'wireguard_ip' => $instance->node->wireguard_ip, 'pi_token' => 'private']);
    $transport = new class implements SshExecutor
    {
        public array $commands = [];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;

            return new LocalShellSshExecutor()->execute($connection, $command);
        }
    };
    $runner = check_runner($transport);
    file_put_contents($checkout.'/AuthProbe.php', <<<'PHP_PROBE'
        <?php
        class AuthProbe {
            public static function check(\Composer\Script\Event $event): void {
                $token = $event->getIO()->getAuthentication('github.com')['username'];
                if (getenv('COMPOSER_AUTH') !== false || str_contains(file_get_contents('/proc/'.getmypid().'/environ'), $token)
                    || str_contains(file_get_contents('/proc/'.posix_getppid().'/environ'), $token)) {
                    throw new \RuntimeException('Composer inherited the secret in its environment.');
                }
                echo substr($token, 0, 7);
                flush();
                usleep(150000);
                echo substr($token, 7)."\n";
                echo base64_encode($token.':x-oauth-basic')."\n";
                passthru('php -r \'exit(getenv("COMPOSER_AUTH") === false ? 0 : 1);\'', $exit);
                if ($exit !== 0) { throw new \RuntimeException('A script child inherited authentication.'); }
            }
        }
        PHP_PROBE);
    file_put_contents($checkout.'/composer.json', json_encode([
        'repositories' => ['packagist.org' => false],
        'autoload' => ['classmap' => ['AuthProbe.php']],
        'scripts' => [
            'post-autoload-dump' => ['AuthProbe::check', '@composer run-script nested-probe --no-interaction'],
            'nested-probe' => '@php -r "echo \'nested-composer-ok\'.PHP_EOL;"',
        ],
    ], JSON_THROW_ON_ERROR));
    $setup = <<<'BASH'
        python3 - <<'PY'
        import os
        assert 'COMPOSER_AUTH' not in os.environ
        assert 'ORBIT_SETUP_COMPOSER_AUTH' not in os.environ
        assert b'ORBIT_SETUP_COMPOSER_AUTH=' not in open('/proc/%s/environ' % os.getppid(), 'rb').read()
        assert os.environ['COMPOSER_CACHE_DIR'] == os.path.expanduser('~/.cache/composer')
        assert os.environ['ORBIT_SEED_PATH'] == ''
        assert os.environ['ORBIT_SEED_COMMIT'] == ''
        PY
        unset COMPOSER_BINARY
        composer install --no-interaction --no-plugins --no-progress
        BASH;

    $process = $runner->start($instance, 'test -z "${COMPOSER_AUTH:-}" && test -z "${ORBIT_SETUP_COMPOSER_AUTH:-}" && echo checked', [
        ['name' => 'install-apps-cli', 'command' => $setup."\n".$ending, 'timeout_seconds' => $exitCode === 124 ? 5 : 10],
    ]);
    $reading = check_runner_wait($runner, $instance, $process);

    expect($reading->exitCode)->toBe($exitCode);
    if ($exitCode === 0) {
        expect($reading->output)->toContain('checked');
    }
    expect($reading->output)->toContain('[REDACTED]')->not->toContain('ghs_setup_sentinel', base64_encode('ghs_setup_sentinel:x-oauth-basic'));
    expect($reading->output)->toContain('nested-composer-ok');
    $git = trim((new Process(['git', '-C', $checkout, 'rev-parse', '--absolute-git-dir']))->mustRun()->getOutput());
    expect(file_get_contents($git.'/orbit/check.log'))->not->toContain('ghs_setup_sentinel');
    expect(file_get_contents($git.'/orbit/setup.json'))->not->toContain('ghs_setup_sentinel');
    foreach ($transport->commands as $command) {
        expect(implode(' ', $command->arguments).($command->input ?? ''))->not->toContain('ghs_setup_sentinel');
    }
    expect($transport->commands[0]->protectedInput)->not->toBeNull();
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->data() === ['repositories' => ['shop'], 'permissions' => ['metadata' => 'read']]);
})->with(['success' => [':', 0], 'failure' => ['exit 7', 7], 'timeout' => ['sleep 20', 124]]);

it('continues task VM setup without authentication when the App does not cover the repository', function (): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    Http::fake(['https://api.github.com/repos/acme/shop/installation' => Http::response([], 404)]);
    $instance = check_runner_instance(check_runner_checkout('true'));
    TaskVm::query()->create(['node_id' => $instance->node_id, 'provider' => 'upcloud', 'name' => 'tvm-setup', 'state' => 'ready', 'wireguard_ip' => $instance->node->wireguard_ip, 'pi_token' => 'private']);
    $runner = check_runner(new LocalShellSshExecutor);

    $process = $runner->start($instance, 'true', [['name' => 'install', 'command' => 'test -z "${COMPOSER_AUTH:-}" && echo uncovered', 'timeout_seconds' => 10]]);

    expect(check_runner_wait($runner, $instance, $process)->exitCode)->toBe(0);
    Http::assertSentCount(1);
});

it('mints no setup token for an incoming pull request baseline', function (): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    $instance = check_runner_instance(check_runner_checkout('true'));
    $group = Task::topLevel()->create(['project_id' => $instance->project_id, 'title' => 'Incoming', 'brief' => 'Review', 'status' => 'running', 'pr_branch' => 'external/contribution']);
    $group->taskable()->associate($instance)->save();
    test_vm_workspace($group, $instance);
    $runner = check_runner(new LocalShellSshExecutor);

    $process = $runner->start($instance, 'true', [['name' => 'install', 'command' => 'test -z "${COMPOSER_AUTH:-}"', 'timeout_seconds' => 10]]);

    expect(check_runner_wait($runner, $instance, $process)->exitCode)->toBe(0);
    Http::assertNothingSent();
});

it('returns only the failed setup step output after an earlier rate limit recovered', function (): void {
    $instance = check_runner_instance(check_runner_checkout('true'));
    $runner = check_runner(new LocalShellSshExecutor);

    $process = $runner->start($instance, 'true', [
        ['name' => 'recovered', 'command' => 'echo "https://codeload.github.com/archive HTTP 429"; true', 'timeout_seconds' => 10],
        ['name' => 'broken', 'command' => 'echo "invalid configuration"; exit 2', 'timeout_seconds' => 10],
    ]);
    $reading = check_runner_wait($runner, $instance, $process);

    expect($reading->failedStep)->toBe('broken');
    expect($reading->output)->toContain('invalid configuration')->not->toContain('429');
});

it('streams setup output and stops its descendants on timeout or cancellation', function (bool $authenticated, bool $cancel): void {
    Http::preventStrayRequests();
    if ($authenticated) {
        GitHubTestSupport::storeApp();
        Http::fake([
            'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
            'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_setup_sentinel'], 201),
        ]);
    }
    $checkout = check_runner_checkout('true');
    $instance = check_runner_instance($checkout);
    if ($authenticated) {
        TaskVm::query()->create(['node_id' => $instance->node_id, 'provider' => 'upcloud', 'name' => 'tvm-setup', 'state' => 'ready', 'wireguard_ip' => $instance->node->wireguard_ip, 'pi_token' => 'private']);
    }
    $runner = check_runner(new LocalShellSshExecutor);
    $process = $runner->start($instance, 'true', [[
        'name' => 'waiting',
        'command' => 'mkdir -p ignored; sleep 30 & echo $! > ignored/setup-child; printf "streamed-output:%08000d" 0; wait',
        'timeout_seconds' => $cancel ? 20 : 2,
    ]]);
    try {
        $log = $checkout.'/.git/orbit/check.log';
        $deadline = microtime(true) + 10;
        do {
            usleep(50000);
            $output = is_file($log) ? file_get_contents($log) : '';
        } while (! str_contains($output, 'streamed-output:000') && microtime(true) < $deadline);

        expect($output)->toContain('streamed-output:000');
        expect($runner->read($instance, $process)->state)->toBe('running');
        $child = (int) file_get_contents($checkout.'/ignored/setup-child');
        if ($cancel) {
            $runner->cancel($instance, $process);
            expect(check_runner_wait($runner, $instance, $process)->state)->toBe('lost');
        } else {
            expect(check_runner_wait($runner, $instance, $process)->exitCode)->toBe(124);
        }
        $probe = new Process(['python3', '-c', <<<'PYTHON'
            import pathlib, sys
            stat = pathlib.Path('/proc/' + sys.argv[1] + '/stat')
            assert not stat.exists() or stat.read_text().rsplit(')', 1)[1].split()[0] == 'Z'
            PYTHON, (string) $child]);
        expect($probe->run())->toBe(0, $probe->getErrorOutput());
    } finally {
        $runner->cancel($instance, $process);
    }
})->with([
    'plain timeout' => [false, false],
    'plain cancellation' => [false, true],
    'authenticated timeout' => [true, false],
    'authenticated cancellation' => [true, true],
]);

it('bounds output draining after an escaped grandchild outlives the setup shell', function (?int $timeout): void {
    $checkout = check_runner_checkout('touch check-ran');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $command = <<<'BASH'
        mkdir -p ignored
        python3 - <<'PY'
        import pathlib, subprocess, sys
        child = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(30)'], start_new_session=True)
        pathlib.Path('ignored/escaped-child').write_text(str(child.pid))
        PY
        BASH;
    $started = microtime(true);
    $process = $runner->start($instance, 'touch check-ran', [
        ['name' => 'escaped', 'command' => $command, 'timeout_seconds' => $timeout],
        ['name' => 'never', 'command' => 'touch never-ran', 'timeout_seconds' => 10],
    ]);
    try {
        $reading = check_runner_wait($runner, $instance, $process);

        expect($reading->exitCode)->toBe(124)
            ->and($reading->failedStep)->toBe('escaped')
            ->and($reading->output)->toContain('output did not close')
            ->and(microtime(true) - $started)->toBeLessThan(10)
            ->and(file_exists($checkout.'/never-ran'))->toBeFalse()
            ->and(file_exists($checkout.'/check-ran'))->toBeFalse();
    } finally {
        $runner->cancel($instance, $process);
        if (is_file($checkout.'/ignored/escaped-child')) {
            (new Process(['kill', '-TERM', trim(file_get_contents($checkout.'/ignored/escaped-child'))]))->run();
        }
    }
})->with(['configured timeout' => [30], 'no configured timeout' => [null]]);

it('reads both merge parents and the parent tree for historical mutation proof', function (): void {
    $checkout = check_runner_checkout('echo ok');
    $git = static fn (array $args): string => trim((new Process(['git', '-C', $checkout, ...$args]))->mustRun()->getOutput());
    $git(['add', '--all']);
    $git(['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-m', 'clean baseline']);
    $parent = $git(['rev-parse', 'HEAD']);
    $parentTree = $git(['rev-parse', 'HEAD^{tree}']);
    $git(['checkout', '-b', 'incoming']);
    $git(['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--allow-empty', '-m', 'incoming']);
    $second = $git(['rev-parse', 'HEAD']);
    $git(['checkout', '--detach', $parent]);
    $git(['-c', 'user.name=t', '-c', 'user.email=t@t', 'merge', '--no-ff', '--no-edit', $second]);
    $snapshot = check_runner(new LocalShellSshExecutor)->snapshot(check_runner_instance($checkout));

    expect($snapshot->parent)->toBe($parent)->and($snapshot->parentTree)->toBe($parentTree)
        ->and($snapshot->secondParent)->toBe($second)->and($snapshot->commitTree)->toBe($snapshot->tree);
});

it('recovers an exact managed run and never exposes a result before descendants stop', function (): void {
    $checkout = check_runner_checkout('true');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $runId = bin2hex(random_bytes(16));
    $barrier = $this->directory.'/descendant-barrier';
    $ready = $this->directory.'/descendant-ready';
    if (! mkdir($barrier)) {
        throw new RuntimeException('Could not create the observable barrier.');
    }
    $child = 'touch '.escapeshellarg($ready).'; while test -d '.escapeshellarg($barrier).'; do read -r -t 0.02 ignored || true; done; printf descendant > late.txt';
    $process = null;
    try {
        $process = $runner->start($instance, 'bash -c '.escapeshellarg($child).' >/dev/null 2>&1 &', runId: $runId);
        $recovered = $runner->recoverStart($instance, $runId);
        expect($recovered?->pid)->toBe($process->pid)->and($recovered?->started)->toBe($process->started);
        $deadline = microtime(true) + 10;
        while ((! file_exists($ready) || ! file_exists($checkout.'/.git/orbit/check-starts/'.$runId.'.result.json')) && microtime(true) < $deadline) {
            clearstatcache();
        }
        expect(file_exists($ready))->toBeTrue()
            ->and(file_exists($checkout.'/.git/orbit/check-starts/'.$runId.'.result.json'))->toBeTrue()
            ->and($runner->read($instance, $process)->state)->toBe('running');
        rmdir($barrier);
        $reading = check_runner_wait($runner, $instance, $process);
        expect($reading->state)->toBe('finished')->and($reading->exitCode)->toBe(1)
            ->and($reading->treeAfter)->toBe($runner->snapshot($instance)->tree)
            ->and($reading->changedPaths)->toContain('late.txt');
    } finally {
        if (is_dir($barrier)) {
            rmdir($barrier);
        }
        (new Process(['sudo', '-n', 'systemctl', 'stop', 'orbit-task-check-'.$runId.'.service']))->run();
        (new Process(['sudo', '-n', 'systemctl', 'reset-failed', 'orbit-task-check-'.$runId.'.service']))->run();
    }
});

it('tombstones a missing managed start and refuses a delayed original without executing it', function (): void {
    $checkout = check_runner_checkout('true');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $runId = bin2hex(random_bytes(16));
    expect($runner->recoverStart($instance, $runId))->toBeNull();
    expect(fn () => $runner->start($instance, 'touch late-start', runId: $runId))->toThrow(TaskCheckException::class);
    expect(file_exists($checkout.'/late-start'))->toBeFalse()
        ->and($runner->recoverStart($instance, $runId))->toBeNull();
});

it('keeps finished managed results bound to their run when a newer check completes', function (): void {
    $checkout = check_runner_checkout('true');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $runIds = [bin2hex(random_bytes(16)), bin2hex(random_bytes(16))];
    try {
        $first = $runner->start($instance, 'true', runId: $runIds[0]);
        $original = check_runner_wait($runner, $instance, $first);
        expect($original->state)->toBe('finished')->and($original->exitCode)->toBe(0);
        $second = $runner->start($instance, 'printf newer > second-run.txt; exit 7', runId: $runIds[1]);
        $newer = check_runner_wait($runner, $instance, $second);
        $repeated = $runner->read($instance, $first);
        expect($newer->state)->toBe('finished')->and($newer->exitCode)->toBe(7)
            ->and($repeated->state)->toBe('finished')->and($repeated->exitCode)->toBe(0)
            ->and($repeated->treeAfter)->toBe($original->treeAfter)
            ->and($repeated->changedPaths)->toBe($original->changedPaths)
            ->and($runner->read($instance, $second)->treeAfter)->toBe($newer->treeAfter);
    } finally {
        foreach ($runIds as $runId) {
            (new Process(['sudo', '-n', 'systemctl', 'stop', 'orbit-task-check-'.$runId.'.service']))->run();
            (new Process(['sudo', '-n', 'systemctl', 'reset-failed', 'orbit-task-check-'.$runId.'.service']))->run();
        }
    }
});
