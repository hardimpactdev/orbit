<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\ManagedTaskCheckSeed;
use Tests\Support\TaskCheckChild;

require_once __DIR__.'/../../../Support/TaskCheckRunnerFixtures.php';

pest()->group('privileged');

function managed_analysis_seed(): ManagedTaskCheckSeed
{
    static $seed = null;

    return $seed ??= new ManagedTaskCheckSeed;
}

afterAll(function (): void {
    managed_analysis_seed()->close();
});

beforeEach(function (): void {
    $this->directory = check_runner_directory();
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('retains exact owned analysis bytes once and fences a detached descendant before terminal proof', function (bool $cancel): void {
    $checkout = managed_analysis_seed()->checkout($this->directory);
    $child = new TaskCheckChild($this->directory);
    $childCommand = $child->readyCommand().'; '.$child->waitCommand();
    mkdir($checkout.'/bin');
    $script = <<<'PYTHON'
        #!/usr/bin/env python3
        import errno, json, os, subprocess, sys
        assert len(sys.argv) == 1
        assert 'COMPOSER_AUTH' not in os.environ and 'ORBIT_SECRET_TEST' not in os.environ
        try:
            os.fstat(3)
        except OSError as error:
            assert error.errno == errno.EBADF
        else:
            raise AssertionError('Analysis inherited FD3')
        incoming = json.load(sys.stdin)
        with open('.git/analysis-invocations', 'a') as output:
            output.write('once\n')
        subprocess.Popen(['bash', '-c', CHILD_COMMAND], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                         stderr=subprocess.DEVNULL, start_new_session=True)
        report = {name: incoming[name] for name in ('execution_id', 'plan_sha256', 'base', 'head', 'tree')}
        report.update({'schema': 1, 'status': 'ready', 'diagnostics': [], 'report': {'schema': 'fixture.v1', 'data': {'complete': 'é' * 6000}}})
        print(json.dumps(report, ensure_ascii=False))
        PYTHON;
    $script = str_replace('CHILD_COMMAND', json_encode($childCommand, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $script)."\n";
    file_put_contents($checkout.'/bin/task-analyze', $script);
    chmod($checkout.'/bin/task-analyze', 0755);
    new Process(['git', 'add', 'bin/task-analyze'], $checkout)->mustRun();
    new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'fixed analysis'], $checkout)->mustRun();
    new Process(['git', 'checkout', '-qb', 'task-8'], $checkout)->mustRun();
    $instance = check_runner_instance($checkout);
    $transport = managed_analysis_seed()->transport($checkout);
    $transport->environment['COMPOSER_AUTH'] = 'fixture-auth-must-not-inherit';
    $transport->environment['ORBIT_SECRET_TEST'] = 'fixture-secret-must-not-inherit';
    $runner = check_runner($transport);
    $snapshot = $runner->snapshot($instance);
    $runId = bin2hex(random_bytes(16));
    $input = ['execution_id' => $runId, 'plan' => [], 'plan_sha256' => str_repeat('a', 64), 'base' => $snapshot->head,
        'head' => $snapshot->head, 'tree' => $snapshot->tree, 'entrypoint_sha256' => hash('sha256', $script)];
    try {
        $process = $runner->startAnalysis($instance, $input, $runId);
        $child->awaitReady();
        check_runner_wait_for_exit($process);
        expect($runner->readAnalysis($instance, $process)['state'])->toBe('running')
            ->and($runner->recoverStart($instance, $runId)?->pid)->toBe($process->pid);
        $same = $runner->startAnalysis($instance, $input, $runId);
        expect($same->pid)->toBe($process->pid)->and(file_get_contents($checkout.'/.git/analysis-invocations'))->toBe("once\n");
        if ($cancel) {
            $runner->cancel($instance, $process);
        } else {
            $child->release();
        }
        $child->waitForExit();
        $reading = null;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $reading = $runner->readAnalysis($instance, $process);
            if (in_array($reading['state'], ['finished', 'interrupted'], true)) {
                break;
            }
            usleep(10_000);
        }
        expect($reading['state'])->toBe('finished');
        $bytes = base64_decode($reading['result']['report_base64'], true);
        $envelope = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        expect(strlen($bytes))->toBeGreaterThan(8192)->and($reading['result']['report_bytes'])->toBe(strlen($bytes))
            ->and($envelope['report']['data']['complete'])->toBe(str_repeat('é', 6000))
            ->and($reading['result']['tree_after'])->toBe($snapshot->tree)
            ->and($runner->readAnalysis($instance, $process)['result']['report_base64'])->toBe(base64_encode($bytes));
        unlink($checkout.'/.git/orbit/check-starts/'.$runId.'.result.json');
        $missing = $runner->readAnalysis($instance, $process);
        expect($missing['state'])->toBe('finished')->and($missing['result']['exit_code'])->toBe(1)
            ->and($missing['result']['causality_uncertain'])->toBeTrue()
            ->and($missing['result'])->not->toHaveKey('report_base64');
    } finally {
        $child->release();
        new Process(['sudo', '-n', 'systemctl', 'stop', 'orbit-task-check-'.$runId.'.service'])->run();
        new Process(['sudo', '-n', 'systemctl', 'reset-failed', 'orbit-task-check-'.$runId.'.service'])->run();
    }
})->with([false, true]);
