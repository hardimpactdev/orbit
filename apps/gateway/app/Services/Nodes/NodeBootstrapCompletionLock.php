<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use Closure;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class NodeBootstrapCompletionLock
{
    /** @param Closure(): NodeBootstrapCompletionResult $callback */
    public function synchronized(
        string $bootstrapId,
        Closure $callback,
    ): NodeBootstrapCompletionResult {
        $lockSeconds = max(1, (int) config('orbit.node_bootstrap.completion_lock_seconds'));
        $waitSeconds = max(1, (int) config('orbit.node_bootstrap.completion_lock_wait_seconds'));

        return $this->completionResult(
            Cache::lock(
                "orbit:node-bootstrap:completion:{$bootstrapId}",
                $lockSeconds,
            )->block($waitSeconds, $callback),
        );
    }

    private function completionResult(mixed $result): NodeBootstrapCompletionResult
    {
        if (! $result instanceof NodeBootstrapCompletionResult) {
            throw new RuntimeException('Node bootstrap completion lock returned an invalid result.');
        }

        return $result;
    }
}
