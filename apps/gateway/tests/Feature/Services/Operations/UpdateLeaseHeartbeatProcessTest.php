<?php

declare(strict_types=1);

use App\Models\OperationRun;
use App\Services\Operations\FleetUpdateLease;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\UpdateLeaseHeartbeatProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('runs the callback while a separate heartbeat command owns the database connection', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::describe()
            ->output(UpdateLeaseHeartbeatProcess::READY_MARKER)
            ->runsFor(1),
    ]);

    $run = update_lease_heartbeat_process_run();
    $lease = app(FleetUpdateLease::class)->acquireForRunner($run);

    $result = new UpdateLeaseHeartbeatProcess()->whileRunning(
        operationRun: $run,
        fleetLease: $lease,
        ttlSeconds: 90,
        callback: fn (): string => 'completed',
    );

    expect($result)->toBe('completed');

    Process::assertRan(function ($process) use ($lease, $run): bool {
        expect($process->command)
            ->toContain(PHP_BINARY)
            ->toContain(base_path('artisan'))
            ->toContain('orbit:update-lease-heartbeat')
            ->toContain("--operation-run-id={$run->id}")
            ->toContain("--fleet-lease-id={$lease->id}")
            ->toContain('--ttl-seconds=90')
            ->toContain('--interval-seconds=30')
            ->and(implode(' ', $process->command))
            ->not->toContain($lease->owner_token);

        return true;
    });
});

it('does not enter the runner callback when the heartbeat fails to become ready', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(errorOutput: 'bootstrap failed', exitCode: 1),
    ]);

    $run = update_lease_heartbeat_process_run();
    $lease = app(FleetUpdateLease::class)->acquireForRunner($run);
    $callbackRan = false;

    expect(fn () => new UpdateLeaseHeartbeatProcess()->whileRunning(
        operationRun: $run,
        fleetLease: $lease,
        ttlSeconds: 90,
        callback: function () use (&$callbackRan): void {
            $callbackRan = true;
        },
    ))
        ->toThrow(\RuntimeException::class, 'Update lease heartbeat failed to become ready.')
        ->and($callbackRan)
        ->toBeFalse();
});

it('interrupts the runner callback when the ready heartbeat child exits', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::describe()
            ->output(UpdateLeaseHeartbeatProcess::READY_MARKER)
            ->runsFor(1),
    ]);

    $run = update_lease_heartbeat_process_run();
    $lease = app(FleetUpdateLease::class)->acquireForRunner($run);
    $callbackCompleted = false;

    expect(fn () => new UpdateLeaseHeartbeatProcess()->whileRunning(
        operationRun: $run,
        fleetLease: $lease,
        ttlSeconds: 3,
        callback: function () use (&$callbackCompleted): void {
            sleep(3);
            $callbackCompleted = true;
        },
    ))
        ->toThrow(\RuntimeException::class, 'Update lease heartbeat stopped while the update runner was active.')
        ->and($callbackCompleted)
        ->toBeFalse();
})->skip(
    ! function_exists('pcntl_alarm')
    || ! function_exists('pcntl_async_signals')
    || ! function_exists('pcntl_signal')
    || ! function_exists('pcntl_signal_get_handler')
    || ! defined('SIGALRM')
    || ! defined('SIGTERM'),
    'PCNTL signals are required for heartbeat child monitoring.',
);

it('converts sigterm into a controlled runner failure and restores signal state', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::describe()
            ->output(UpdateLeaseHeartbeatProcess::READY_MARKER)
            ->runsFor(100),
    ]);

    $run = update_lease_heartbeat_process_run();
    $lease = app(FleetUpdateLease::class)->acquireForRunner($run);
    $callbackCompleted = false;
    $previousAsyncSignals = pcntl_async_signals();
    $previousSignalHandler = pcntl_signal_get_handler(SIGTERM);
    pcntl_async_signals(false);

    try {
        expect(fn () => new UpdateLeaseHeartbeatProcess()->whileRunning(
            operationRun: $run,
            fleetLease: $lease,
            ttlSeconds: 90,
            callback: function () use (&$callbackCompleted): void {
                $signalHandler = pcntl_signal_get_handler(SIGTERM);

                if (! is_callable($signalHandler)) {
                    throw new \RuntimeException('Update runner SIGTERM handler was not installed.');
                }

                $signalHandler(SIGTERM);
                $callbackCompleted = true;
            },
        ))
            ->toThrow(\RuntimeException::class, 'Update runner received SIGTERM while the lease heartbeat was active.')
            ->and($callbackCompleted)
            ->toBeFalse()
            ->and(pcntl_async_signals())
            ->toBeFalse()
            ->and(pcntl_signal_get_handler(SIGTERM))
            ->toBe($previousSignalHandler);
    } finally {
        pcntl_async_signals($previousAsyncSignals);
    }
})->skip(
    ! function_exists('pcntl_alarm')
    || ! function_exists('pcntl_async_signals')
    || ! function_exists('pcntl_signal')
    || ! function_exists('pcntl_signal_get_handler')
    || ! defined('SIGALRM')
    || ! defined('SIGTERM'),
    'PCNTL signals are required for controlled SIGTERM handling.',
);

function update_lease_heartbeat_process_run(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}
