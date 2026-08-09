<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use App\Models\UpdateLease;
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
        $parentPid = getmypid();

        if (! is_int($parentPid) || $parentPid < 1) {
            throw new RuntimeException('Update lease heartbeat parent process id could not be resolved.');
        }

        $intervalSeconds = max(1, intdiv(num1: $ttlSeconds, num2: 3));
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

        try {
            $startup = $heartbeat->waitUntil(
                static fn (string $type, string $output): bool => str_contains($output, self::READY_MARKER),
            );

            if (! str_contains($startup->output(), self::READY_MARKER)) {
                throw new RuntimeException('Update lease heartbeat failed to become ready.');
            }

            return $callback();
        } finally {
            if ($heartbeat->running()) {
                $heartbeat->stop(2);
            }
        }
    }
}
