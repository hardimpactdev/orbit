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
    public function runDependency(
        OperationRun $run,
        RuntimeHibernationScope $scope,
        Closure $effect,
    ): bool {
        return $this->runWithKey($run, $scope, $scope->dependencyFenceKey(), $effect);
    }

    /**
     * @param  Closure(): bool  $effect
     */
    public function runScope(
        OperationRun $run,
        RuntimeHibernationScope $scope,
        Closure $effect,
    ): bool {
        return $this->runWithKey($run, $scope, $scope->activationFenceKey(), $effect);
    }

    /**
     * @param  Closure(): bool  $takeover
     */
    public function attemptTakeover(RuntimeHibernationScope $scope, Closure $takeover): bool
    {
        $dependencyLock = Cache::lock(
            $scope->dependencyFenceKey(),
            $scope->activationFenceSeconds(),
        );

        if (! $dependencyLock->get()) {
            return false;
        }

        $scopeLock = Cache::lock(
            $scope->activationFenceKey(),
            $scope->activationFenceSeconds(),
        );

        if (! $scopeLock->get()) {
            $dependencyLock->release();

            return false;
        }

        try {
            return $takeover();
        } finally {
            $scopeLock->release();
            $dependencyLock->release();
        }
    }

    /**
     * @param  Closure(): bool  $effect
     */
    private function runWithKey(
        OperationRun $run,
        RuntimeHibernationScope $scope,
        string $key,
        Closure $effect,
    ): bool {
        $lock = Cache::lock(
            $key,
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

    private function heartbeat(OperationRun $run): void
    {
        if ($this->operationRuns->heartbeat($run->id)->status->isTerminal()) {
            throw new RuntimeException('Runtime activation was superseded.');
        }
    }
}
