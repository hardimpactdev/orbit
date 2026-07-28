<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\OperationRun;
use App\Services\Operations\OperationRunRecorder;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final readonly class RuntimeActivationFence
{
    public function __construct(
        private OperationRunRecorder $operationRuns,
    ) {}

    /**
     * @param  Closure(): bool  $effect
     */
    public function run(
        OperationRun $run,
        RuntimeHibernationScope $scope,
        Closure $effect,
    ): bool {
        $lock = Cache::lock(
            $scope->activationFenceKey(),
            $scope->activationFenceSeconds(),
        );

        try {
            $result = $lock->block(
                (int) config('orbit.runtime_hibernation.activation_fence_wait_seconds', default: 10),
                function () use ($run, $effect): bool {
                    $this->heartbeat($run);
                    $successful = $effect();
                    $this->heartbeat($run);

                    return $successful;
                },
            );
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException('Runtime activation fence timed out.', previous: $exception);
        }

        return $result === true;
    }

    /**
     * @param  Closure(): bool  $takeover
     */
    public function attemptTakeover(RuntimeHibernationScope $scope, Closure $takeover): bool
    {
        $lock = Cache::lock(
            $scope->activationFenceKey(),
            $scope->activationFenceSeconds(),
        );

        if (! $lock->get()) {
            return false;
        }

        try {
            return $takeover();
        } finally {
            $lock->release();
        }
    }

    private function heartbeat(OperationRun $run): void
    {
        if ($this->operationRuns->heartbeat($run->id)->status->isTerminal()) {
            throw new RuntimeException('Runtime activation was superseded.');
        }
    }
}
