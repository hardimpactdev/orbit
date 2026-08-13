<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use App\Models\UpdateLease;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class UpdateLeaseHeartbeatProcess
{
    public const string OWNER_TOKEN_ENVIRONMENT = 'ORBIT_UPDATE_LEASE_OWNER_TOKEN';

    public const string READY_MARKER = 'ORBIT_UPDATE_LEASE_HEARTBEAT_READY';

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function whileRunning(
        OperationRun $operationRun,
        UpdateLease $fleetLease,
        int $ttlSeconds,
        callable $callback,
    ): mixed {
        $this->assertSignalSupport();

        $parentPid = getmypid();

        if (! is_int($parentPid) || $parentPid < 1) {
            throw new RuntimeException('Update lease heartbeat parent process id could not be resolved.');
        }

        $signalState = $this->installTerminationHandler();
        $intervalSeconds = max(1, intdiv(num1: $ttlSeconds, num2: 3));
        $heartbeat = null;

        try {
            $heartbeat = Process::forever()
                ->env([
                    self::OWNER_TOKEN_ENVIRONMENT => $fleetLease->owner_token,
                ])
                ->start([
                    PHP_BINARY,
                    base_path('artisan'),
                    'orbit:update-lease-heartbeat',
                    "--operation-run-id={$operationRun->id}",
                    "--fleet-lease-id={$fleetLease->id}",
                    "--parent-pid={$parentPid}",
                    "--ttl-seconds={$ttlSeconds}",
                    "--interval-seconds={$intervalSeconds}",
                ]);

            $startup = $heartbeat->waitUntil(
                static fn (string $type, string $output): bool => str_contains($output, self::READY_MARKER),
            );

            if (! str_contains($startup->output(), self::READY_MARKER)) {
                throw new RuntimeException('Update lease heartbeat failed to become ready.');
            }

            $this->startHeartbeatWatchdog($heartbeat, $ttlSeconds);

            return $callback();
        } finally {
            pcntl_alarm(0);

            try {
                if ($heartbeat instanceof InvokedProcess && $heartbeat->running()) {
                    $heartbeat->stop(2);
                }
            } finally {
                $this->restoreSignalState($signalState);
            }
        }
    }

    private function assertSignalSupport(): void
    {
        if (
            function_exists('pcntl_alarm')
            && function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_signal_get_handler')
            && defined('SIGALRM')
            && defined('SIGTERM')
        ) {
            return;
        }

        throw new RuntimeException('PCNTL signal support is required to monitor the update lease heartbeat.');
    }

    /**
     * @return array{async_signals: bool, alarm_handler: callable|int, termination_handler: callable|int}
     */
    private function installTerminationHandler(): array
    {
        $pendingAlarmSeconds = pcntl_alarm(0);

        if ($pendingAlarmSeconds > 0) {
            pcntl_alarm($pendingAlarmSeconds);

            throw new RuntimeException('Update lease heartbeat cannot replace an active process alarm.');
        }

        /** @var mixed $alarmHandler */
        $alarmHandler = pcntl_signal_get_handler(SIGALRM);
        /** @var mixed $terminationHandler */
        $terminationHandler = pcntl_signal_get_handler(SIGTERM);

        if (
            ! is_int($alarmHandler)
            && ! is_callable($alarmHandler)
            || ! is_int($terminationHandler)
            && ! is_callable($terminationHandler)
        ) {
            throw new RuntimeException('Update lease heartbeat could not preserve the process signal handlers.');
        }

        $state = [
            'async_signals' => pcntl_async_signals(),
            'alarm_handler' => $alarmHandler,
            'termination_handler' => $terminationHandler,
        ];

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): never {
            throw new RuntimeException('Update runner received SIGTERM while the lease heartbeat was active.');
        });

        return $state;
    }

    private function startHeartbeatWatchdog(InvokedProcess $heartbeat, int $ttlSeconds): void
    {
        $watchdogIntervalSeconds = max(1, min(5, intdiv(num1: $ttlSeconds, num2: 3)));

        pcntl_signal(SIGALRM, static function () use ($heartbeat, $watchdogIntervalSeconds): void {
            if (! $heartbeat->running()) {
                throw new RuntimeException('Update lease heartbeat stopped while the update runner was active.');
            }

            pcntl_alarm($watchdogIntervalSeconds);
        });
        pcntl_alarm($watchdogIntervalSeconds);
    }

    /**
     * @param  array{async_signals: bool, alarm_handler: callable|int, termination_handler: callable|int}  $state
     */
    private function restoreSignalState(array $state): void
    {
        pcntl_signal(SIGALRM, $state['alarm_handler']);
        pcntl_signal(SIGTERM, $state['termination_handler']);
        pcntl_async_signals($state['async_signals']);
    }
}
