<?php

declare(strict_types=1);

use App\Domain\AppDev\SsrPortAllocator;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckOutputBuilder;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskDeliverableEvidence;
use App\Domain\Tasks\TaskDeliverableVerifier;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tools\ToolManagerException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Tasks\TaskUnitProgram;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeVitePortRuntime;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\PythonPrograms;

require_once __DIR__.'/../../../Support/TaskCheckRunnerFixtures.php';
require_once __DIR__.'/../../../Support/TaskCheckAuthenticationFixture.php';

beforeAll(function (): void {
    check_runner_programs();
});

function check_runner_programs(): PythonPrograms
{
    static $programs = null;

    return $programs ??= new PythonPrograms;
}

/** Run the real check synchronously when capture/status, not detached admission, is the subject. */
function check_runner_capture(Instance $instance, ?string $command, ?array $deliverables = null): TaskCheckProcess
{
    $directory = $instance->checkout_path.'/.git/orbit';
    File::ensureDirectoryExists($directory);
    $script = $directory.'/check';
    file_put_contents($script, TaskUnitProgram::read('tasks/check'));
    $evidence = $directory.'/deliverables.json';
    file_put_contents($evidence, json_encode($deliverables, JSON_THROW_ON_ERROR));
    $payload = json_encode(['script' => $script, 'checkout' => $instance->checkout_path, 'command' => $command ?? '', 'deliverables' => $deliverables === null ? null : $evidence], JSON_THROW_ON_ERROR);
    $bootstrap = <<<'PYTHON'
        import json, os, sys
        source, payload = sys.stdin.buffer.read().split(b'\0', 1)
        options = json.loads(payload)
        check = {'__name__': 'check', '__file__': options['script']}
        exec(compile(source, sys.argv[1], 'exec'), check)
        os.chdir(options['checkout'])
        head = check['git'](options['checkout'], 'rev-parse', 'HEAD')
        tree = check['working_tree'](options['checkout'])
        started = check['process_started'](os.getpid())
        check['run'](options['checkout'], head, tree, None, options['deliverables'], options['command'])
        print(json.dumps({'pid': os.getpid(), 'started': started, 'head': head, 'tree': tree}))
        PYTHON;
    $result = check_runner_programs()->run($bootstrap, 'task-check-capture', TaskUnitProgram::read('tasks/check')."\0".$payload, ['HOME' => test()->directory.'/home']);
    expect($result->exitCode)->toBe(0, $result->stderr);
    $data = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);

    return new TaskCheckProcess($data['pid'], $data['started'], $data['head'], $data['tree']);
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
    File::put($script, TaskUnitProgram::read('tasks/check'));
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

it('normalizes VP_HOME probe refusals without launching a check', function (string $code): void {
    $instance = check_runner_instance('/fast/apps/shop/task-8');
    $probe = new FakeAgentCommandRunner()->refuse('tools.inspect-vp-scope', $code);
    $transport = new AppDevFakeSshExecutor;
    $runner = check_runner($transport, probe: $probe);

    expect(fn () => $runner->start($instance, 'true'))->toThrow(function (TaskCheckException $exception): void {
        expect($exception->getPrevious())->toBeInstanceOf(ToolManagerException::class);
        expect($exception->getMessage())->toBe('The task workspace could not be reached for the check.');
    });
    expect($transport->commands)->toBeEmpty();
    expect($probe->calls)->toHaveCount(1);
})->with([
    'absent' => ['tools.vp_scope_absent'],
    'conflict' => ['tools.vp_scope_conflict'],
    'unreadable' => ['tools.vp_scope_unreadable'],
]);

it('releases the unstarted baseline reservation and retries after a VP_HOME probe timeout', function (): void {
    test_bind_snapshot_driver();
    $instance = check_runner_instance('/fast/apps/shop/task-8', group: [
        'title' => 'Probe timeout', 'brief' => 'Retry baseline',
        'status' => TaskGroupStatus::Running, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
    ]);
    $group = Task::topLevel()->findOrFail($instance->task_workspace_owner_id);
    $task = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'First', 'brief' => 'First subtask', 'status' => TaskStatus::Running, 'subtask_start_commit' => 'abc',
    ]);
    $timeout = new ProcessTimedOutException(new Process(['ssh', 'probe']), ProcessTimedOutException::TYPE_GENERAL);
    $probeCalls = 0;
    $probe = new FakeAgentCommandRunner()->answer('tools.inspect-vp-scope', static function () use ($timeout, &$probeCalls): array {
        expect(TaskCheck::query()->sole()->pid)->toBe(0);
        if (++$probeCalls === 1) {
            throw $timeout;
        }

        return ['binary' => '/opt/orbit/vite-plus/bin/vp'];
    });
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
    expect($probe->calls)->toHaveCount(2);
    expect($transport->starts)->toBe(1);
    expect($task->fresh()->communication_failures)->toBe(0);
    expect($group->fresh()->assistance_requested)->toBeFalse();
});

it('normalizes thrown failures only during the known unstarted VP_HOME probe', function (string $failure): void {
    $instance = check_runner_instance('/fast/apps/shop/task-8');
    $exception = $failure === 'timeout'
        ? new ProcessTimedOutException(new Process(['ssh', 'probe']), ProcessTimedOutException::TYPE_GENERAL)
        : new RuntimeException('SSH transport failed');
    $failing = new readonly class($exception) implements SshExecutor
    {
        public function __construct(private Throwable $exception) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            throw $this->exception;
        }
    };
    $transport = new AppDevFakeSshExecutor;

    expect(fn () => check_runner($transport, probe: new FakeAgentCommandRunner()->answer('tools.inspect-vp-scope', static fn (): array => throw $exception))->start($instance, 'true'))
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
    $probe = new FakeAgentCommandRunner()->refuse('tools.inspect-vp-scope', 'tools.vp_scope_absent');
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
    expect($probe->calls)->toBeEmpty();
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
        new Process(['git', '-C', $checkout, 'config', 'core.fsmonitor', $monitor])->mustRun();
        $runner = check_runner(new LocalShellSshExecutor);
        $instance = check_runner_instance($checkout);

        $runner->snapshot($instance);
        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'true'));

        expect($reading->exitCode)->toBe(0)
            ->and(file_exists($checkout.'/.git/fsmonitor-ran'))->toBeFalse();
    });
});

beforeEach(function (): void {
    $this->directory = check_runner_directory();
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

it('runs composer check detached and reports running, then the exit code and output', function (string $check, int $exitCode, string $output): void {
    $checkout = check_runner_checkout('read -r release < ignored/release; '.$check, realComposer: true);
    File::ensureDirectoryExists($checkout.'/ignored');
    expect(posix_mkfifo($checkout.'/ignored/release', 0600))->toBeTrue();
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $status = new Process(['git', 'status', '--porcelain'], $checkout)->mustRun()->getOutput();

    $process = $runner->start($instance, 'composer check');
    $first = $runner->read($instance, $process);
    file_put_contents($checkout.'/ignored/release', "go\n");
    $reading = check_runner_wait($runner, $instance, $process);

    expect(fileperms($checkout.'/.git/orbit') & 0777)->toBe(0775);
    expect($process->pid)->toBeGreaterThan(1)
        ->and($process->head)->toBe(trim(new Process(['git', 'rev-parse', 'HEAD'], $checkout)->mustRun()->getOutput()))
        ->and($first->state)->toBe('running')
        ->and($reading->state)->toBe('finished')
        ->and($reading->exitCode)->toBe($exitCode)
        ->and($reading->output)->toContain($output)
        ->and($reading->treeAfter)->toBe($process->tree)
        ->and($reading->changedPaths)->toBe([])
        ->and($reading->finishedAt)->toBeFloat()
        ->and($reading->finishedAt)->toBeLessThanOrEqual(microtime(true))
        ->and(new Process(['git', 'status', '--porcelain'], $checkout)->mustRun()->getOutput())->toBe($status);
})->with([
    'passing' => ['echo all checks passed', 0, 'all checks passed'],
    'failing' => ['echo PHPStan found 2 errors && exit 2', 2, 'PHPStan found 2 errors'],
]);

it('reports the paths a check changed in the working tree, but not ignored files', function (): void {
    $checkout = check_runner_checkout('mkdir -p ignored && echo x > ignored/cache && echo y > written.txt');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check'));

    expect($reading->exitCode)->toBe(0)
        ->and($reading->changedPaths)->toBe(['written.txt']);
});

it('snapshots same-size edits whose timestamp matches the original index without changing that index', function (): void {
    $checkout = check_runner_checkout('true');
    $timestamp = 1_700_000_000;
    new Process(['git', 'config', 'core.trustctime', 'false'], $checkout)->mustRun();
    file_put_contents($checkout.'/state', "old\n");
    touch($checkout.'/state', $timestamp);
    new Process(['git', 'add', '--all'], $checkout)->mustRun();
    new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'base'], $checkout)->mustRun();
    file_put_contents($checkout.'/state', "new\n");
    touch($checkout.'/state', $timestamp);
    // Reproduce Git's racy-clean window without waiting for a clock boundary.
    touch($checkout.'/.git/index', $timestamp);
    $index = file_get_contents($checkout.'/.git/index');
    $runner = check_runner(new LocalShellSshExecutor);
    $instance = check_runner_instance($checkout);

    $snapshot = $runner->snapshot($instance);

    expect(new Process(['git', 'show', $snapshot->tree.':state'], $checkout)->mustRun()->getOutput())->toBe("new\n");
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
    expect(new Process(['git', 'ls-tree', $snapshot->tree, '--', 'existing-link'], $checkout)->mustRun()->getOutput())
        ->toStartWith('120000 blob ')->toContain("\texisting-link\n");
    expect(new Process(['git', 'ls-tree', $reading->treeAfter, '--', 'created-link'], $checkout)->mustRun()->getOutput())
        ->toStartWith('120000 blob ')->toContain("\tcreated-link\n");
    expect(new Process(['git', 'ls-files', '--others', '--exclude-standard', '-z'], $checkout)->mustRun()->getOutput())
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
    $transport = new readonly class($status) implements SshExecutor
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
    $status = new Process(['git', 'status', '--porcelain'], $checkout)->mustRun()->getOutput();

    $process = $runner->start($instance, 'echo ok');
    $runner->cancel($instance, $process);
    chmod($checkout.'/.git/orbit', 0755);
    $snapshot = $runner->snapshot($instance);
    clearstatcache();

    expect(fileperms($checkout.'/.git/orbit') & 0777)->toBe(0775);
    expect($snapshot->head)->toBe($process->head)
        ->and($snapshot->tree)->toBe($process->tree)
        ->and($snapshot->parent)->toBeNull()
        ->and($snapshot->commitTree)->toBe(trim(new Process(['git', 'rev-parse', 'HEAD^{tree}'], $checkout)->mustRun()->getOutput()))
        ->and(new Process(['git', 'status', '--porcelain'], $checkout)->mustRun()->getOutput())->toBe($status);

    file_put_contents($checkout.'/extra.txt', "extra\n");
    $written = new Process(['git', 'status', '--porcelain'], $checkout)->mustRun()->getOutput();
    $changed = $runner->snapshot($instance);
    File::ensureDirectoryExists($checkout.'/ignored');
    file_put_contents($checkout.'/ignored/cache', "cache\n");
    $ignored = $runner->snapshot($instance);

    expect($changed->head)->toBe($snapshot->head)
        ->and($changed->tree)->not->toBe($snapshot->tree)
        ->and($ignored->head)->toBe($changed->head)
        ->and($ignored->tree)->toBe($changed->tree)
        ->and(new Process(['git', 'status', '--porcelain'], $checkout)->mustRun()->getOutput())->toBe($written);
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

it('reaps an exited setup leader before waiting for its process group to disappear', function (): void {
    $script = $this->directory.'/check.py';
    file_put_contents($script, TaskUnitProgram::read('tasks/check'));
    $probe = new Process(['python3', '-c', <<<'PYTHON'
        import importlib.machinery, importlib.util, signal, sys
        from types import SimpleNamespace
        loader = importlib.machinery.SourceFileLoader('check', sys.argv[1])
        check = importlib.util.module_from_spec(importlib.util.spec_from_loader('check', loader))
        loader.exec_module(check)
        class Child:
            pid = 123
            reaped = False
            def poll(self):
                self.reaped = True
                return 0
            def wait(self):
                self.reaped = True
        child = Child()
        clock = [0]
        signals = []
        def sleep(seconds):
            clock[0] += seconds
        check.time = SimpleNamespace(monotonic=lambda: clock[0], sleep=sleep)
        check.os.killpg = lambda pid, sig: signals.append((pid, sig))
        check.process_group_exists = lambda pid: not child.reaped
        check.CHILD_PROCESS = child
        check.stop_child_process()
        assert child.reaped
        assert check.CHILD_PROCESS is None
        assert clock[0] == 0, clock
        assert signals == [(123, signal.SIGTERM)], signals
        PYTHON, $script]);

    expect($probe->run())->toBe(0, $probe->getErrorOutput());
});

it('reports a check that writes its result and exits while status looks it up as finished, not lost', function (): void {
    $status = check_runner_status(alive: false, meanwhile: 'with open(check.RESULT, "w") as result: result.write(\'{"exit_code": 5}\')');

    expect($status['state'])->toBe('finished')
        ->and($status['result']['exit_code'])->toBe(5);
});

it('keeps a check running while the wall-clock start time of its process moves, as on a freshly booted VM', function (): void {
    $script = test()->directory.'/script/check';
    File::ensureDirectoryExists(dirname($script));
    File::put($script, TaskUnitProgram::read('tasks/check'));
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
    new Process(['git', 'add', '--all'], $checkout)->mustRun();
    new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'project'], $checkout)->mustRun();
    $start = trim(new Process(['git', 'rev-parse', 'HEAD'], $checkout)->mustRun()->getOutput());
    file_put_contents($checkout.'/app/tests/ExportTest.php', "<?php\n");
    file_put_contents($checkout.'/README.md', "# Shop\n\nExports.\n");
    unlink($checkout.'/app/tests/OldTest.php');

    return [$checkout, $start];
}

describe('topology diff evidence', function (): void {
    it('records only the group changes against main, including the candidate working tree', function (bool $merge, string $own): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $git = static fn (array $arguments): string => trim(new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', ...$arguments], $checkout)->mustRun()->getOutput());
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

        $reading = $runner->read($instance, check_runner_capture($instance, null, [
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

        $reading = $runner->read($instance, check_runner_capture($instance, null, [
            'start' => $start, 'commands' => [], 'topology' => true,
        ]));

        expect($reading->deliverables)->toHaveKey('topology_diff', null)
            ->and($reading->deliverables['diff'])->not->toBeNull();
    });
});

describe('deliverable evidence', function (): void {
    it('runs command deliverables independently after a passing Project check', function (int $exit): void {
        [$checkout, $start] = check_runner_deliverables_checkout('mkdir -p ignored; echo run >> ignored/runs; echo project-output; exit '.$exit);
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);
        $deliverables = ['start' => $start, 'commands' => [
            ['id' => 'project-check', 'command' => 'composer check', 'directory' => '.'],
            ['id' => 'other', 'command' => 'touch ignored/other-ran', 'directory' => '.'],
        ]];

        $reading = $runner->read($instance, check_runner_capture($instance, 'composer check', $deliverables));
        $evidence = TaskDeliverableEvidence::fromArray($reading->deliverables);
        $deliverable = TaskDeliverable::fromArray([
            'id' => 'project-check', 'type' => 'command', 'description' => 'Run the Project task check',
            'command' => 'composer check', 'directory' => '.',
        ]);

        expect(file_get_contents($checkout.'/ignored/runs'))->toBe($exit === 0 ? "run\nrun\n" : "run\n")
            ->and($reading->exitCode)->toBe($exit)
            ->and($reading->treeBefore)->toBe($reading->treeAfter)
            ->and(file_exists($checkout.'/ignored/other-ran'))->toBe($exit === 0);
        if ($exit === 0) {
            expect($evidence->commands['project-check'])->toBe(['exit_code' => 0, 'output' => "project-output\n"])
                ->and(TaskDeliverableVerifier::failures([$deliverable], $evidence, new TaskCheckOutputBuilder(new CommandActivityInputSanitizer)))->toBe([]);
        } else {
            expect($reading->deliverables)->toBeNull();
        }
    })->with(['passing check' => 0, 'failing check' => 7]);

    it('runs both the base reproduction and working-tree command after the Project check', function (): void {
        $checkout = check_runner_checkout('true');
        File::ensureDirectoryExists($checkout.'/tests');
        file_put_contents($checkout.'/state', "old\n");
        file_put_contents($checkout.'/tests/check.sh', "mkdir -p ignored; echo run >> ignored/runs; echo checked; grep -q new state\n");
        new Process(['git', 'add', '--all'], $checkout)->mustRun();
        new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'base'], $checkout)->mustRun();
        $start = trim(new Process(['git', 'rev-parse', 'HEAD'], $checkout)->mustRun()->getOutput());
        file_put_contents($checkout.'/state', "new\n");
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = $runner->read($instance, check_runner_capture($instance, 'bash tests/check.sh', [
            'start' => $start, 'commands' => [[
                'id' => 'project-check', 'command' => 'bash tests/check.sh', 'directory' => '.',
                'fails_on_base' => true, 'paths' => ['tests/check.sh'],
            ]],
        ]));

        expect(file_get_contents($checkout.'/ignored/runs'))->toBe("run\nrun\n")
            ->and($reading->deliverables['commands']['project-check']['exit_code'])->toBe(0)
            ->and($reading->deliverables['commands']['project-check']['base_exit_code'])->toBe(1)
            ->and($reading->deliverables['commands']['project-check']['base_output'])->toContain('checked');
    });

    it('runs project-check as a normal deliverable when no task check is configured', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = $runner->read($instance, check_runner_capture($instance, null, [
            'start' => $start, 'commands' => [['id' => 'project-check', 'command' => 'echo snapshot-check', 'directory' => '.']],
        ]));

        expect($reading->deliverables['commands']['project-check'])->toBe(['exit_code' => 0, 'output' => "snapshot-check\n"]);
    });

    it('runs a snapshotted project-check separately when the current task check changed', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = $runner->read($instance, check_runner_capture($instance, 'echo current-check', [
            'start' => $start, 'commands' => [['id' => 'project-check', 'command' => 'echo snapshot-check', 'directory' => '.']],
        ]));

        expect($reading->output)->toContain('current-check', 'snapshot-check')
            ->and($reading->deliverables['commands']['project-check'])->toBe(['exit_code' => 0, 'output' => "snapshot-check\n"]);
    });

    it('runs a command deliverable in its own directory', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = $runner->read($instance, check_runner_capture($instance, 'basename "$PWD"', [
            'start' => $start, 'commands' => [['id' => 'project-check', 'command' => 'basename "$PWD"', 'directory' => 'app']],
        ]));

        expect($reading->deliverables['commands']['project-check'])->toBe(['exit_code' => 0, 'output' => "app\n"]);
    });

    it('reports a changed tree after checking command deliverables', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout('mkdir -p ignored; echo run >> ignored/runs; echo changed >> README.md');
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = $runner->read($instance, check_runner_capture($instance, 'composer check', [
            'start' => $start, 'commands' => [['id' => 'project-check', 'command' => 'composer check', 'directory' => '.']],
        ]));

        expect(file_get_contents($checkout.'/ignored/runs'))->toBe("run\nrun\n")
            ->and($reading->treeBefore)->not->toBe($reading->treeAfter)
            ->and($reading->deliverables['commands']['project-check']['exit_code'])->toBe(0)
            ->and($reading->deliverables['commands']['project-check']['output'])->toBe('');
    });

    it('runs the task check anew on the next handoff even on an unchanged tree', function (): void {
        [$checkout, $start] = check_runner_deliverables_checkout('mkdir -p ignored; echo run >> ignored/runs');
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);
        $deliverables = ['start' => $start, 'commands' => []];
        check_runner_local_result($checkout, $runner->start($instance, 'composer check', deliverables: $deliverables));

        $completed = check_runner_local_result($checkout, $runner->start($instance, 'composer check', deliverables: $deliverables));

        expect(file_get_contents($checkout.'/ignored/runs'))->toBe("run\nrun\n")
            ->and($completed['result']['deliverables']['commands'])->toBe([]);
    });

    it('fails on base command when the base tree does not satisfy the overlaid command paths', function (): void {
        $checkout = check_runner_checkout('echo checks passed');
        File::ensureDirectoryExists($checkout.'/app/tests');
        file_put_contents($checkout.'/app/state', "old\n");
        file_put_contents($checkout.'/app/tests/check.sh', "#!/usr/bin/env bash\necho base-script\ngrep -q old state\n");
        chmod($checkout.'/app/tests/check.sh', 0755);
        new Process(['git', 'add', '--all'], $checkout)->mustRun();
        new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'base'], $checkout)->mustRun();
        $start = trim(new Process(['git', 'rev-parse', 'HEAD'], $checkout)->mustRun()->getOutput());
        file_put_contents($checkout.'/app/state', "new\n");
        file_put_contents($checkout.'/app/tests/check.sh', "#!/usr/bin/env bash\necho overlay-script\ngrep -q new state\n");
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = $runner->read($instance, check_runner_capture($instance, 'echo checks passed', [
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
        new Process(['git', 'add', '--all'], $checkout)->mustRun();
        new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'base'], $checkout)->mustRun();
        $start = trim(new Process(['git', 'rev-parse', 'HEAD'], $checkout)->mustRun()->getOutput());
        file_put_contents($checkout.'/status', "available\n");
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = $runner->read($instance, check_runner_capture($instance, 'echo checks passed', [
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
        expect(TaskDeliverableVerifier::failures([$deliverable], $evidence, new TaskCheckOutputBuilder(new CommandActivityInputSanitizer)))->not->toBe([]);
    });

    it('does not substitute HEAD when the resolved start commit is unavailable', function (): void {
        [$checkout] = check_runner_deliverables_checkout();
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor);

        $reading = $runner->read($instance, check_runner_capture($instance, 'echo checks passed', [
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
    File::put($script, TaskUnitProgram::read('tasks/check'));
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

        expect(TaskDeliverableVerifier::failures([$deliverable], $evidence, new TaskCheckOutputBuilder(new CommandActivityInputSanitizer)))->toBe([
            'base-repro (command): `bash tests/check.sh` in app also exited 0 on the start commit, so it does not reproduce the failure.',
        ]);
    });
});

it('cleans stale base archive directories without registering worktrees or removing unrelated files', function (): void {
    $checkout = check_runner_checkout('echo ok');
    $gitDir = trim(new Process(['git', 'rev-parse', '--absolute-git-dir'], $checkout)->mustRun()->getOutput());
    File::ensureDirectoryExists($gitDir.'/orbit/bases/orbit-base-leftover');
    file_put_contents($gitDir.'/orbit/bases/orbit-base-leftover/marker', "x\n");
    File::ensureDirectoryExists($gitDir.'/orbit/bases/notes');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);

    $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'echo ok'));

    expect($reading->exitCode)->toBe(0)
        ->and(is_dir($gitDir.'/orbit/bases/orbit-base-leftover'))->toBeFalse()
        ->and(is_dir($gitDir.'/orbit/bases/notes'))->toBeTrue()
        ->and(substr_count((string) new Process(['git', 'worktree', 'list'], $checkout)->mustRun()->getOutput(), "\n"))->toBe(1);
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
    File::put($script, TaskUnitProgram::read('tasks/check'));
    $deliverables = test()->directory.'/deliverables.json';
    file_put_contents($deliverables, json_encode([
        'start' => null,
        'commands' => [['id' => 'hello', 'command' => 'touch deliverable-ran', 'directory' => '.']],
    ], JSON_THROW_ON_ERROR));
    $resultPath = dirname($script).'/check.json';
    $logPath = dirname($script).'/check.log';
    $snapshot = json_decode(new Process(['python3', $script, 'snapshot', $checkout])->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot)->toBeArray();

    new Process(['python3', $script, 'run', $checkout, $snapshot['head'], $snapshot['tree'], '-', $deliverables])->mustRun();
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
    new Process(['python3', $script, 'start', $checkout, '-', $deliverables])->mustRun();
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

describe('ssr port', function (): void {
    it('starts the check of a workspace with its allocated ssr port and never the held 13719', function (): void {
        $node = Node::query()->create(['name' => 'shared-check-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.161', 'wireguard_ip' => '10.44.0.161', 'user' => 'orbit']);
        orbit_test_set_app_placement_role($node, false);
        $runtime = new FakeVitePortRuntime;
        $runtime->occupied[$node->id] = range(SsrPortAllocator::FIRST_PORT, 13719);
        app()->instance(VitePortRuntime::class, $runtime);
        $project = Project::query()->create(['name' => 'Recall', 'slug' => 'recall', 'repository_url' => 'git@github.com:acme/recall.git', 'default_branch' => 'main']);
        $started = '{"pid":4100,"started":"now","head":"abc","tree":"def"}';
        $transport = new AppDevFakeSshExecutor([new CommandResult(0, $started, '', 1, false)]);
        $runner = check_runner($transport);
        // A task VM Node holds one workspace, so the held ports are the only other claims on the range.
        $instance = test_ready_task_workspace($project, $node, ['name' => 'task-a', 'checkout_path' => '/fast/apps/recall/task-a', 'branch' => 'task-a']);

        $port = app(SsrPortAllocator::class)->assign($instance);
        $runner->start($instance, 'composer check');

        expect($port)->toBe(13720)
            ->and($transport->commands[0]->input)->toContain("export ORBIT_SSR_PORT='13720'\nexport INERTIA_SSR_URL='http://127.0.0.1:13720'\n")
            ->and($transport->commands[0]->input)->not->toContain('13719');
    });

    it('hands the allocated ssr port to setup, the check, and deliverable commands', function (): void {
        $checkout = check_runner_checkout('printf "%s %s" "$ORBIT_SSR_PORT" "$INERTIA_SSR_URL" > check-ssr');
        $instance = check_runner_instance($checkout);
        $instance->forceFill(['ssr_port' => 13722])->save();
        $runner = check_runner(new LocalShellSshExecutor);

        $completed = check_runner_local_result($checkout, $runner->start($instance, 'composer check', [
            ['name' => 'ssr setup', 'command' => 'printf "%s" "$INERTIA_SSR_URL" > setup-ssr', 'timeout_seconds' => 10],
        ], ['commands' => [['id' => 'ssr', 'command' => 'printf "%s" "$ORBIT_SSR_PORT"', 'directory' => '.', 'fails_on_base' => false, 'paths' => []]]]));

        expect($completed['result']['exit_code'])->toBe(0)
            ->and(file_get_contents($checkout.'/setup-ssr'))->toBe('http://127.0.0.1:13722')
            ->and(file_get_contents($checkout.'/check-ssr'))->toBe('13722 http://127.0.0.1:13722')
            ->and($completed['result']['deliverables']['commands']['ssr']['output'] ?? null)->toBe('13722');
    });
});

it('authenticates task VM setup through protected input and redacts tokens before writing logs', function (string $ending, int $exitCode): void {
    check_runner_authentication($ending, $exitCode, $this->directory);
})->with(['success' => [':', 0], 'failure' => ['exit 7', 7]]);

it('continues task VM setup without authentication when the App does not cover the repository', function (): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    Http::fake(['https://api.github.com/repos/acme/shop/installation' => Http::response([], 404)]);
    $instance = check_runner_instance(check_runner_checkout('true'));
    $runner = check_runner(new LocalShellSshExecutor);

    $process = $runner->start($instance, 'true', [['name' => 'install', 'command' => 'test -z "${COMPOSER_AUTH:-}" && echo uncovered', 'timeout_seconds' => 10]]);

    expect(check_runner_wait($runner, $instance, $process)->exitCode)->toBe(0);
    Http::assertSentCount(1);
});

it('mints no setup token for an incoming pull request baseline', function (): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    $instance = check_runner_instance(check_runner_checkout('true'), group: ['title' => 'Incoming', 'brief' => 'Review', 'status' => 'running', 'pr_branch' => 'external/contribution']);
    $group = Task::topLevel()->findOrFail($instance->task_workspace_owner_id);
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

it('reads both merge parents and the parent tree for historical mutation proof', function (): void {
    $checkout = check_runner_checkout('echo ok');
    $git = static fn (array $args): string => trim(new Process(['git', '-C', $checkout, ...$args])->mustRun()->getOutput());
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

it('keeps secret-bearing output records intact until the shared builder runs at the real transport boundary', function (string $source, int $secretBytes, string $format): void {
    $checkout = check_runner_checkout('true');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $secret = match ($format) {
        'quoted' => "'TOKEN=\"' + ('s' * 64 + '\\n') * ".intdiv($secretBytes, 64)." + '\"'",
        'pem' => "'-----BEGIN PRIVATE KEY-----\\n' + ('s' * 64 + '\\n') * ".intdiv($secretBytes, 64)." + '-----END PRIVATE KEY-----'",
        default => "'TOKEN=' + 's' * ".$secretBytes,
    };
    $command = 'python3 -c '.escapeshellarg('print('.$secret.'); print("Last line"); raise SystemExit(1)');
    $head = new Process(['git', 'rev-parse', 'HEAD'], $checkout)->mustRun()->getOutput();
    $deliverables = $source === 'check' ? null : [
        'start' => trim($head),
        'commands' => [['id' => 'probe', 'command' => $command, 'directory' => '.', 'fails_on_base' => $source === 'base', 'paths' => ['uncommitted.php']]],
    ];

    $process = check_runner_capture($instance, $source === 'check' ? $command : null, $deliverables);
    $reading = $runner->read($instance, $process);
    $output = match ($source) {
        'check' => $reading->output,
        'base' => $reading->deliverables['commands']['probe']['base_output'] ?? '',
        default => $reading->deliverables['commands']['probe']['output'] ?? '',
    };
    $builder = app(TaskCheckOutputBuilder::class);
    $excerpt = $builder->build($builder->tail($output));

    expect($reading->state)->toBe('finished')
        ->and($excerpt)->not->toContain('ssss')
        ->and($excerpt)->toContain('[REDACTED]', 'Last line');
})->with(['check', 'command', 'base'])->with([40_000, 100_000])->with(['assignment', 'quoted', 'pem']);

it('uses the same safe raw window in the check transport and PHP builder', function (string $end): void {
    $pem = $end !== '';
    $raw = $pem
        ? str_repeat("ordinary line\n", 5000).str_repeat("-----BEGIN PRIVATE KEY-----\n".str_repeat(str_repeat('A', 64)."\n", 50).$end."\n", 40)."FAILED last line\n"
        : str_repeat('x', 200_000).'FAILED last line';
    $pythonRaw = $pem
        ? "'ordinary line\\n' * 5000 + ('-----BEGIN PRIVATE KEY-----\\n' + ('A' * 64 + '\\n') * 50 + ".json_encode($end, JSON_THROW_ON_ERROR)." + '\\n') * 40 + 'FAILED last line\\n'"
        : "'x' * 200000 + 'FAILED last line'";
    $setup = "import json\nraw = ".$pythonRaw."\n".<<<'PYTHON'
        with open(check.RESULT, 'w') as result:
            json.dump({'failure_output': raw, 'deliverables': {'commands': {'probe': {'output': check.output_records(raw.encode()), 'base_output': check.output_records(raw.encode())}}}}, result)
        PYTHON;

    $status = check_runner_status(alive: false, meanwhile: $setup);

    $builder = app(TaskCheckOutputBuilder::class);
    $expected = $builder->tail($raw);
    foreach ([$status['output'], ...array_values($status['result']['deliverables']['commands']['probe'])] as $output) {
        expect(strlen($output))->toBeLessThanOrEqual(100 * 1024);
        expect($builder->tail($output))->toBe($expected)->toContain('FAILED last line');
        expect($builder->tail($output))->not->toContain('AAAA');
    }
})->with(['many PEM keys' => ['-----END PRIVATE KEY-----'], 'ANSI END' => ["-----END PRIVATE\x1b[0m KEY-----"], 'oversized last line' => ['']]);

it('windows only the raw failure record when printing status', function (): void {
    $setup = <<<'PYTHON'
        import json
        original = check.output_records
        def once(raw):
            if raw != b'raw failure':
                raise AssertionError('Status windowed an already bounded record')
            return original(raw)
        check.output_records = once
        with open(check.RESULT, 'w') as result:
            json.dump({'failure_output': 'raw failure', 'deliverables': {'commands': {'probe': {'output': 'bounded command', 'base_output': 'bounded base'}}}}, result)
        PYTHON;

    $status = check_runner_status(alive: false, meanwhile: $setup);

    expect($status['output'])->toBe('raw failure')
        ->and($status['result'])->not->toHaveKey('failure_output')
        ->and($status['result']['deliverables']['commands']['probe'])->toBe([
            'output' => 'bounded command', 'base_output' => 'bounded base',
        ]);
});

it('keeps the failing tail of ordinary large output through the real check transport', function (string $source, int $lines): void {
    $checkout = check_runner_checkout('true');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $command = 'python3 -c '.escapeshellarg('print("PASS ordinary test output expected 200 got 200\\n" * '.$lines.'); print("FAILED expected 200, got 500"); raise SystemExit(1)');
    $head = trim(new Process(['git', 'rev-parse', 'HEAD'], $checkout)->mustRun()->getOutput());
    $mainCheck = $source === 'check';
    $deliverables = $mainCheck ? null : [
        'start' => $head,
        'commands' => [['id' => 'probe', 'command' => $command, 'directory' => '.', 'fails_on_base' => $source === 'base', 'paths' => ['uncommitted.php']]],
    ];

    $reading = $runner->read($instance, check_runner_capture($instance, $mainCheck ? $command : null, $deliverables));
    $output = match ($source) {
        'check' => $reading->output,
        'base' => $reading->deliverables['commands']['probe']['base_output'] ?? '',
        default => $reading->deliverables['commands']['probe']['output'] ?? '',
    };
    $builder = app(TaskCheckOutputBuilder::class);
    $excerpt = $builder->build($builder->tail($output));

    expect($reading->state)->toBe('finished')
        ->and(strlen($output))->toBeGreaterThan(64 * 1024)
        ->and(strlen($output))->toBeLessThanOrEqual(100 * 1024)
        ->and($excerpt)->toContain('FAILED expected 200, got 500')
        ->and(strlen($excerpt))->toBeLessThan(33_000);
})->with([
    '76 KB check' => ['check', 1_800],
    '106 KB check' => ['check', 2_600],
    '7 MB check' => ['check', 150_000],
    '76 KB command' => ['command', 1_800],
    '106 KB command' => ['command', 2_600],
    '76 KB base run' => ['base', 1_800],
    '106 KB base run' => ['base', 2_600],
]);

it('keeps the failing tail without a stale log path above the status transport budget', function (): void {
    $checkout = check_runner_checkout('true');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $command = 'python3 -c '.escapeshellarg('print("x" * (8 * 1024 * 1024)); print("TOKEN=" + "s" * 40000); print("FAILED last line"); raise SystemExit(1)');

    $reading = $runner->read($instance, check_runner_capture($instance, $command));

    expect($reading->state)->toBe('finished')
        ->and(app(TaskCheckOutputBuilder::class)->build(app(TaskCheckOutputBuilder::class)->tail($reading->output)))->toContain('FAILED last line', 'TOKEN=[REDACTED]')
        ->not->toContain('ssss')->not->toContain('check.log');
});

it('keeps every command and base tail within the real status transport budget', function (): void {
    $checkout = check_runner_checkout('true');
    $instance = check_runner_instance($checkout);
    $local = new LocalShellSshExecutor;
    $local->environment['HOME'] = $this->directory.'/home';
    $transport = new readonly class($local) implements SshExecutor
    {
        public function __construct(private LocalShellSshExecutor $local) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $result = $this->local->execute($connection, $command);
            expect(strlen($result->stdout))->toBeLessThanOrEqual($command->maxOutputBytes ?? 65_536);

            return $result;
        }
    };
    $runner = check_runner($transport);
    $command = 'python3 -c '.escapeshellarg('print("START"); print("ordinary output\\n" * 65536); print("END")');
    $head = trim(new Process(['git', 'rev-parse', 'HEAD'], $checkout)->mustRun()->getOutput());
    $commands = array_map(static fn (int $id): array => [
        'id' => 'probe-'.$id, 'command' => $command, 'directory' => '.', 'fails_on_base' => true, 'paths' => ['uncommitted.php'],
    ], range(1, 5));

    $reading = $runner->read($instance, check_runner_capture($instance, null, ['start' => $head, 'commands' => $commands]));

    expect($reading->state)->toBe('finished')
        ->and($reading->deliverables['commands'] ?? [])->toHaveCount(5);
    foreach ($reading->deliverables['commands'] as $record) {
        foreach (['output', 'base_output'] as $key) {
            expect($record[$key])->toEndWith("END\n")
                ->not->toContain($checkout.'/.git/orbit/')
                ->and(strlen($record[$key]))->toBeLessThanOrEqual(100 * 1024);
        }
    }
});

it('keeps each tail when combined records exceed the status transport budget', function (bool $escaped): void {
    $setup = $escaped ? <<<'PYTHON'
        import json
        output = ('\x01\n' * (512 * 1024)) + 'FAILED last line\n'
        commands = {f'probe-{i}': {'output': check.output_records(output.encode()), 'base_output': check.output_records(output.encode())} for i in range(5)}
        with open(check.RESULT, 'w') as result:
            json.dump({'failure_output': output, 'deliverables': {'commands': commands}}, result)
        PYTHON : <<<'PYTHON'
        import json
        with open(check.RESULT, 'w') as result:
            json.dump({'failure_output': ('check line\n' * 500000) + 'FAILED last line\n',
                       'deliverables': {'commands': {'probe': {'output': check.output_records((('command line\n' * 400000) + 'FAILED last line\n').encode())}}}}, result)
        PYTHON;

    $status = check_runner_status(alive: false, meanwhile: $setup);

    expect($status['output'])->toContain('FAILED last line')
        ->and(strlen(json_encode($status, JSON_THROW_ON_ERROR)))->toBeLessThan(8 * 1024 * 1024);
    foreach ($status['result']['deliverables']['commands'] as $record) {
        foreach ($record as $output) {
            expect($output)->toContain('FAILED last line')
                ->and(strlen($output))->toBeLessThanOrEqual(100 * 1024);
        }
    }
})->with(['five plus four MiB' => [false], 'eleven JSON-escaped records' => [true]]);

it('removes setup scripts after failure and skips later work', function (): void {
    $command = 'exit 5';
    $exit = 5;
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
});
