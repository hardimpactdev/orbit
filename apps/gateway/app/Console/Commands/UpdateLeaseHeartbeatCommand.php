<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OperationRun;
use App\Services\Operations\UpdateLeaseHeartbeatInvocationResolver;
use App\Services\Operations\UpdateLeaseHeartbeatProcess;
use App\Services\Operations\UpdateOperationLeaseHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('orbit:update-lease-heartbeat
    {--operation-run-id= : Operation run UUID whose leases should be renewed}
    {--fleet-lease-id= : Fenced fleet update lease id}
    {--parent-pid= : Update runner process id}
    {--ttl-seconds= : Lease lifetime renewed by each heartbeat}
    {--interval-seconds= : Seconds between heartbeats}
    {--once : Run one heartbeat without waiting}')]
#[Description('Keep a durable fleet update operation lease alive')]
class UpdateLeaseHeartbeatCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(
        UpdateOperationLeaseHeartbeat $heartbeat,
        UpdateLeaseHeartbeatInvocationResolver $invocationResolver,
    ): int {
        $ready = false;

        try {
            $invocation = $invocationResolver->resolve($this);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        while ($this->shouldContinue($invocation->identity->operationRunId, $invocation->parentPid)) {
            try {
                $renewedLeaseCount = $heartbeat->renew(
                    operationRun: $invocation->identity->operationRunId,
                    fleetLease: $invocation->identity->fleetLeaseId,
                    fleetOwnerToken: $invocation->identity->ownerToken,
                    ttlSeconds: $invocation->ttlSeconds,
                );

                if ($renewedLeaseCount < 1) {
                    throw new RuntimeException('An update lease expired before heartbeat.');
                }

                if (! $ready) {
                    $this->line(UpdateLeaseHeartbeatProcess::READY_MARKER);
                    $ready = true;
                }
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());
                $this->terminateParent($invocation->parentPid);

                return self::FAILURE;
            }

            if ($invocation->once) {
                return self::SUCCESS;
            }

            sleep($invocation->intervalSeconds);
        }

        return self::SUCCESS;
    }

    protected function terminateParent(int $parentPid): void
    {
        if (function_exists('posix_kill') && defined('SIGTERM')) {
            posix_kill(process_id: $parentPid, signal: SIGTERM);
        }
    }

    private function shouldContinue(string $operationRunId, int $parentPid): bool
    {
        $operationRun = OperationRun::query()->find($operationRunId);

        if (! $operationRun instanceof OperationRun || $operationRun->status->isTerminal()) {
            return false;
        }

        return ! function_exists('posix_kill') || posix_kill(process_id: $parentPid, signal: 0);
    }
}
