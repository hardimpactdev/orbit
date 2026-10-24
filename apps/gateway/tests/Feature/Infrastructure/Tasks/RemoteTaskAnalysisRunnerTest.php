<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskAnalysisReport;
use App\Infrastructure\Tasks\TaskUnitProgram;
use App\Models\Instance;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\ForkedPythonPrograms;
use Tests\Support\LocalShellSshExecutor;

require_once __DIR__.'/../../../Support/TaskCheckRunnerFixtures.php';

beforeEach(function (): void {
    $this->directory = check_runner_directory();
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

function analysis_runner_instance(): Instance
{
    $checkout = check_runner_checkout('true');
    File::ensureDirectoryExists($checkout.'/bin');
    file_put_contents($checkout.'/bin/task-analyze', <<<'PHP'
        #!/usr/bin/env php
        <?php
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        if ($argc !== 1 || getenv('COMPOSER_AUTH') !== false || getenv('ORBIT_SECRET_TEST') !== false) {
            exit(70);
        }
        file_put_contents('.git/analysis-invocations', "1\n", FILE_APPEND);
        $result = ['schema' => 1, ...array_intersect_key($input, array_flip(['execution_id', 'plan_sha256', 'base', 'head', 'tree'])), 'status' => 'ready', 'diagnostics' => [], 'report' => ['schema' => 'fixture.v1', 'data' => ['all' => str_repeat('é', 6000)]]];
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        PHP
        ."\n");
    chmod($checkout.'/bin/task-analyze', 0755);
    new Process(['git', 'add', '.'], $checkout)->mustRun();
    new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'fixed entrypoint'], $checkout)->mustRun();
    new Process(['git', 'branch', '-m', 'task-8'], $checkout)->mustRun();

    return check_runner_instance($checkout);
}

it('captures complete evidence through the real owned profile and fences a report without unit terminal proof', function (): void {
    $instance = analysis_runner_instance();
    $runner = check_runner(new LocalShellSshExecutor);
    $head = trim(new Process(['git', 'rev-parse', 'HEAD'], $instance->checkout_path)->mustRun()->getOutput());
    $before = $runner->inspectAnalysis($instance, $head);
    $id = bin2hex(random_bytes(16));
    $input = ['execution_id' => $id, 'plan' => ['fixture' => true], 'plan_sha256' => str_repeat('b', 64), 'base' => $head, 'head' => $head, 'tree' => $before['tree'], 'entrypoint_sha256' => $before['entrypoint_sha256']];
    $script = $instance->checkout_path.'/.git/orbit/check';
    $bootstrap = <<<'PYTHON'
        import io, json, os, sys
        source, options = sys.stdin.buffer.read().split(b'\0', 1)
        options = json.loads(options)
        scope = {'__name__': 'check', '__file__': options['script']}
        exec(compile(source, sys.argv[1], 'exec'), scope)
        request = options['input']
        os.chdir(options['checkout'])
        lock, path, _ = scope['start_record'](request['execution_id'])
        scope['write_start_record'](path, {'run_id': request['execution_id'], 'state': 'starting', 'starter_pid': os.getpid(),
            'starter_started': scope['process_started'](os.getpid()), 'boot': open('/proc/sys/kernel/random/boot_id').read().strip()})
        lock.close()
        sys.stdin = io.TextIOWrapper(io.BytesIO(json.dumps(request).encode()))
        arguments = ['run-owned', request['execution_id'], options['checkout'], request['head'], request['tree'], '-', '-', '', 'analysis']
        scope['main'](arguments)
        # The existing durable start record prevents a second profile invocation.
        scope['main'](arguments)
        with open(scope['RESULT']) as result:
            print(result.read())
        PYTHON;
    $programs = new ForkedPythonPrograms;
    try {
        $capture = $programs->run($bootstrap, 'analysis-owned-profile', TaskUnitProgram::read('tasks/check')."\0".json_encode(['script' => $script, 'checkout' => $instance->checkout_path, 'input' => $input], JSON_THROW_ON_ERROR), ['ORBIT_SECRET_TEST' => 'never-inherit']);
        expect($capture->exitCode)->toBe(0, $capture->stderr);
        $result = json_decode($capture->stdout, true, flags: JSON_THROW_ON_ERROR);
        $bytes = base64_decode($result['report_base64'], true);
        expect($result['exit_code'])->toBe(0)->and(strlen($bytes))->toBeGreaterThan(8192)->and(strlen($bytes))->toBe($result['report_bytes'])
            ->and($result['tree_after'])->toBe($before['tree'])->and($result['duration_ms'])->toBeGreaterThanOrEqual(0)
            ->and(TaskAnalysisReport::validate($bytes, $input)['status'])->toBe('ready');
        $recovered = $runner->recoverStart($instance, $id);
        expect($recovered)->not->toBeNull()
            ->and($runner->readAnalysis($instance, $recovered)['state'])->toBe('running')
            ->and(file_get_contents($instance->checkout_path.'/.git/analysis-invocations'))->toBe("1\n");
    } finally {
        $programs->close();
    }
});

it('rejects an altered untracked-mode or symlinked fixed entrypoint before any dispatch', function (): void {
    $instance = analysis_runner_instance();
    $runner = check_runner(new LocalShellSshExecutor);
    $head = trim(new Process(['git', 'rev-parse', 'HEAD'], $instance->checkout_path)->mustRun()->getOutput());
    file_put_contents($instance->checkout_path.'/bin/task-analyze', "# changed\n", FILE_APPEND);
    expect($runner->inspectAnalysis($instance, $head))->toBe(['unavailable' => true]);
    unlink($instance->checkout_path.'/bin/task-analyze');
    symlink('/bin/true', $instance->checkout_path.'/bin/task-analyze');
    expect($runner->inspectAnalysis($instance, $head))->toBe(['unavailable' => true])
        ->and(file_exists($instance->checkout_path.'/.git/analysis-invocations'))->toBeFalse();
});

it('resolves only the provisioned starting commit against the local origin main ref without fetching', function (): void {
    $instance = analysis_runner_instance();
    $runner = check_runner(new LocalShellSshExecutor);
    $head = trim(new Process(['git', 'rev-parse', 'HEAD'], $instance->checkout_path)->mustRun()->getOutput());
    new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'task starting commit'], $instance->checkout_path)->mustRun();
    $starting = trim(new Process(['git', 'rev-parse', 'HEAD'], $instance->checkout_path)->mustRun()->getOutput());
    $instance->update(['starting_commit' => $starting]);
    new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'later candidate'], $instance->checkout_path)->mustRun();
    $candidate = trim(new Process(['git', 'rev-parse', 'HEAD'], $instance->checkout_path)->mustRun()->getOutput());
    new Process(['git', 'update-ref', 'refs/remotes/origin/main', $head], $instance->checkout_path)->mustRun();
    $pinned = $runner->initialAnalysisBase($instance);
    expect($pinned)->toBe($head);
    new Process(['git', 'update-ref', 'refs/remotes/origin/main', $candidate], $instance->checkout_path)->mustRun();
    expect($runner->initialAnalysisBase($instance))->toBe($starting)
        ->and($runner->inspectAnalysis($instance, $pinned)['base'])->toBe($head)
        ->and($runner->inspectAnalysis($instance, $pinned)['head'])->toBe($candidate);
    new Process(['git', 'update-ref', '-d', 'refs/remotes/origin/main'], $instance->checkout_path)->mustRun();
    expect($runner->initialAnalysisBase($instance))->toBeNull();
});

it('leaves the initial base unavailable without a full existing starting commit', function (?string $starting): void {
    $instance = analysis_runner_instance();
    $instance->update(['starting_commit' => $starting]);
    new Process(['git', 'update-ref', 'refs/remotes/origin/main', 'HEAD'], $instance->checkout_path)->mustRun();

    expect(check_runner(new LocalShellSshExecutor)->initialAnalysisBase($instance))->toBeNull();
})->with([
    'missing' => [null],
    'revision selector' => ['HEAD'],
    'option' => ['--all'],
    'absent object' => [str_repeat('a', 40)],
]);
