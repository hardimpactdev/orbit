<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\LocalShellSshExecutor;

require_once __DIR__.'/../../../Support/TaskCheckRunnerFixtures.php';

pest()->group('privileged');

beforeEach(function (): void {
    $this->directory = check_runner_directory();
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
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
            ->and($reading->changedPaths)->toContain('late.txt')
            ->and($reading->causalityUncertain)->toBeTrue();
    } finally {
        if (is_dir($barrier)) {
            rmdir($barrier);
        }
        (new Process(['sudo', '-n', 'systemctl', 'stop', 'orbit-task-check-'.$runId.'.service']))->run();
        (new Process(['sudo', '-n', 'systemctl', 'reset-failed', 'orbit-task-check-'.$runId.'.service']))->run();
    }
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

it('records a failed uncertain outcome after a managed check terminates without a result', function (bool $cancel): void {
    $checkout = check_runner_checkout('true');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $runId = bin2hex(random_bytes(16));
    $ready = $checkout.'/command-ready';
    try {
        $process = $runner->start($instance, 'touch command-ready; exec sleep 60', runId: $runId);
        $deadline = microtime(true) + 10;
        while (! file_exists($ready) && microtime(true) < $deadline) {
            clearstatcache();
            usleep(10_000);
        }
        expect(file_exists($ready))->toBeTrue();
        if ($cancel) {
            $runner->cancel($instance, $process);
        } else {
            (new Process(['sudo', '-n', 'systemctl', 'kill', '--signal=KILL', 'orbit-task-check-'.$runId.'.service']))->mustRun();
        }

        $reading = check_runner_wait($runner, $instance, $process);

        expect($reading->state)->toBe('finished')->and($reading->exitCode)->toBe(1)
            ->and($reading->failedStep)->toBe('check_error')->and($reading->causalityUncertain)->toBeTrue()
            ->and($reading->changedPaths)->toContain('command-ready')
            ->and($reading->treeAfter)->toBe($runner->snapshot($instance)->tree)
            ->and($reading->output)->toContain('without a result');
        $repeated = $runner->read($instance, $process);
        expect($repeated->exitCode)->toBe(1)->and($repeated->causalityUncertain)->toBeTrue()
            ->and($repeated->treeAfter)->toBe($reading->treeAfter);
    } finally {
        (new Process(['sudo', '-n', 'systemctl', 'stop', 'orbit-task-check-'.$runId.'.service']))->run();
        (new Process(['sudo', '-n', 'systemctl', 'reset-failed', 'orbit-task-check-'.$runId.'.service']))->run();
    }
})->with(['cancel' => [true], 'killed supervisor and descendants' => [false]]);

it('keeps a lost managed supervisor fenced until its surviving descendant terminates', function (): void {
    $checkout = check_runner_checkout('true');
    $instance = check_runner_instance($checkout);
    $runner = check_runner(new LocalShellSshExecutor);
    $runId = bin2hex(random_bytes(16));
    $barrier = $this->directory.'/missing-result-barrier';
    $ready = $this->directory.'/missing-result-ready';
    mkdir($barrier);
    $command = 'touch '.escapeshellarg($ready).'; while test -d '.escapeshellarg($barrier).'; do read -r -t 0.02 ignored || true; done; printf late > descendant.txt';
    try {
        $process = $runner->start($instance, 'bash -c '.escapeshellarg($command), runId: $runId);
        $deadline = microtime(true) + 10;
        while (! file_exists($ready) && microtime(true) < $deadline) {
            clearstatcache();
            usleep(10_000);
        }
        expect(file_exists($ready))->toBeTrue();
        (new Process(['kill', '-KILL', (string) $process->pid]))->mustRun();

        expect($runner->read($instance, $process)->state)->toBe('running');
        rmdir($barrier);
        $reading = check_runner_wait($runner, $instance, $process);

        expect($reading->state)->toBe('finished')->and($reading->exitCode)->toBe(1)
            ->and($reading->causalityUncertain)->toBeTrue()
            ->and($reading->changedPaths)->toContain('descendant.txt')
            ->and(file_get_contents($checkout.'/descendant.txt'))->toBe('late');
    } finally {
        if (is_dir($barrier)) {
            rmdir($barrier);
        }
        (new Process(['sudo', '-n', 'systemctl', 'stop', 'orbit-task-check-'.$runId.'.service']))->run();
        (new Process(['sudo', '-n', 'systemctl', 'reset-failed', 'orbit-task-check-'.$runId.'.service']))->run();
    }
});
