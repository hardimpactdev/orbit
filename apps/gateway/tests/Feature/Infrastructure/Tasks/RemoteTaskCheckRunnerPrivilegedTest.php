<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\ManagedTaskCheckSeed;
use Tests\Support\TaskCheckChild;

require_once __DIR__.'/../../../Support/TaskCheckRunnerFixtures.php';

pest()->group('privileged');

function managed_check_seed(): ManagedTaskCheckSeed
{
    static $seed = null;

    return $seed ??= new ManagedTaskCheckSeed;
}

afterAll(function (): void {
    managed_check_seed()->close();
});

beforeEach(function (): void {
    $this->directory = check_runner_directory();
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('recovers an exact managed run and never exposes a result before descendants stop', function (): void {
    $checkout = managed_check_seed()->checkout($this->directory);
    $instance = check_runner_instance($checkout);
    $runner = check_runner(managed_check_seed()->transport($checkout));
    $runId = bin2hex(random_bytes(16));
    $barrier = new TaskCheckChild($this->directory);
    $ready = $this->directory.'/descendant-ready';
    $child = 'touch '.escapeshellarg($ready).'; '.$barrier->readyCommand().'; '.$barrier->waitCommand().'; printf descendant > late.txt';
    $process = null;
    try {
        $process = $runner->start($instance, 'bash -c '.escapeshellarg($child).' >/dev/null 2>&1 &', runId: $runId);
        $recovered = $runner->recoverStart($instance, $runId);
        expect($recovered?->pid)->toBe($process->pid)->and($recovered?->started)->toBe($process->started);
        $barrier->awaitReady();
        check_runner_wait_for_exit($process);
        expect(file_exists($ready))->toBeTrue()
            ->and(file_exists($checkout.'/.git/orbit/check-starts/'.$runId.'.result.json'))->toBeTrue();
        $barrier->release();
        $barrier->waitForExit();
        $reading = check_runner_wait($runner, $instance, $process);
        expect($reading->state)->toBe('finished')->and($reading->exitCode)->toBe(1)
            ->and($reading->treeAfter)->toBe($runner->snapshot($instance)->tree)
            ->and($reading->changedPaths)->toContain('late.txt')
            ->and($reading->causalityUncertain)->toBeTrue();
    } finally {
        $barrier->release();
        (new Process(['sudo', '-n', 'systemctl', 'stop', 'orbit-task-check-'.$runId.'.service']))->run();
        (new Process(['sudo', '-n', 'systemctl', 'reset-failed', 'orbit-task-check-'.$runId.'.service']))->run();
    }
});

it('drains an orphaned server after the exact managed check has finished', function (int $exit, bool $setup): void {
    $checkout = managed_check_seed()->checkout($this->directory);
    $instance = check_runner_instance($checkout);
    $runner = check_runner($setup ? new LocalShellSshExecutor : managed_check_seed()->transport($checkout));
    $runId = bin2hex(random_bytes(16));
    $child = new TaskCheckChild($this->directory);
    $command = 'trap "" TERM; '.$child->readyCommand().'; '.$child->waitCommand();
    try {
        $process = $runner->start($instance, 'bash -c '.escapeshellarg($command).' >/dev/null 2>&1 & exit '.$exit,
            $setup ? [['name' => 'Prepare', 'command' => 'touch setup-file', 'timeout_seconds' => 10]] : [], runId: $runId);
        $child->awaitReady();
        check_runner_wait_for_exit($process);

        $runner->read($instance, $process);
        $child->waitForExit();
        $reading = $runner->read($instance, $process);
        expect($reading->state)->toBe('finished')
            ->and($reading->exitCode)->toBe($exit)
            ->and($reading->treeAfter)->toBe($runner->snapshot($instance)->tree)
            ->and($reading->causalityUncertain)->toBeFalse();
    } finally {
        $child->release();
        (new Process(['sudo', '-n', 'systemctl', 'stop', 'orbit-task-check-'.$runId.'.service']))->run();
        (new Process(['sudo', '-n', 'systemctl', 'reset-failed', 'orbit-task-check-'.$runId.'.service']))->run();
    }
})->with(['passed command' => [0, false], 'failed command' => [7, false], 'setup changes the baseline tree' => [0, true]]);

it('does not drain descendants when the completed result identity is unavailable', function (string $damage): void {
    $checkout = managed_check_seed()->checkout($this->directory);
    $instance = check_runner_instance($checkout);
    $runner = check_runner(managed_check_seed()->transport($checkout));
    $runId = bin2hex(random_bytes(16));
    $child = new TaskCheckChild($this->directory);
    $command = $child->readyCommand().'; '.$child->waitCommand();
    try {
        $process = $runner->start($instance, 'bash -c '.escapeshellarg($command).' >/dev/null 2>&1 &', runId: $runId);
        $child->awaitReady();
        check_runner_wait_for_exit($process);
        $path = $checkout.'/.git/orbit/check-starts/'.$runId.'.result.json';
        $result = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if ($damage === 'malformed') {
            file_put_contents($path, '{');
        } else {
            $result[$damage] = $damage === 'checkout' ? $this->directory : 'another-run';
            file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
        }

        expect($runner->read($instance, $process)->state)->toBe('running');
        $child->release();
        $child->waitForExit();
    } finally {
        $child->release();
        (new Process(['sudo', '-n', 'systemctl', 'stop', 'orbit-task-check-'.$runId.'.service']))->run();
        (new Process(['sudo', '-n', 'systemctl', 'reset-failed', 'orbit-task-check-'.$runId.'.service']))->run();
    }
})->with(['malformed', 'head_before', 'checkout']);

it('keeps finished managed results bound to their run when a newer check completes', function (): void {
    $checkout = managed_check_seed()->checkout($this->directory);
    $instance = check_runner_instance($checkout);
    $runner = check_runner(managed_check_seed()->transport($checkout));
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
    $checkout = managed_check_seed()->checkout($this->directory);
    $instance = check_runner_instance($checkout);
    $runner = check_runner(managed_check_seed()->transport($checkout));
    $runId = bin2hex(random_bytes(16));
    $ready = $checkout.'/command-ready';
    $child = new TaskCheckChild($this->directory);
    try {
        $process = $runner->start($instance, 'touch command-ready; '.$child->readyCommand().'; '.$child->waitCommand(), runId: $runId);
        $child->awaitReady();
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
    $checkout = managed_check_seed()->checkout($this->directory);
    $instance = check_runner_instance($checkout);
    $runner = check_runner(managed_check_seed()->transport($checkout));
    $runId = bin2hex(random_bytes(16));
    $barrier = new TaskCheckChild($this->directory);
    $ready = $this->directory.'/missing-result-ready';
    $command = 'touch '.escapeshellarg($ready).'; '.$barrier->readyCommand().'; '.$barrier->waitCommand().'; printf late > descendant.txt';
    try {
        $process = $runner->start($instance, 'bash -c '.escapeshellarg($command), runId: $runId);
        $barrier->awaitReady();
        expect(file_exists($ready))->toBeTrue();
        (new Process(['kill', '-KILL', (string) $process->pid]))->mustRun();

        expect($runner->read($instance, $process)->state)->toBe('running');
        $barrier->release();
        $barrier->waitForExit();
        $reading = check_runner_wait($runner, $instance, $process);

        expect($reading->state)->toBe('finished')->and($reading->exitCode)->toBe(1)
            ->and($reading->causalityUncertain)->toBeTrue()
            ->and($reading->changedPaths)->toContain('descendant.txt')
            ->and(file_get_contents($checkout.'/descendant.txt'))->toBe('late');
    } finally {
        $barrier->release();
        (new Process(['sudo', '-n', 'systemctl', 'stop', 'orbit-task-check-'.$runId.'.service']))->run();
        (new Process(['sudo', '-n', 'systemctl', 'reset-failed', 'orbit-task-check-'.$runId.'.service']))->run();
    }
});
