<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use Closure;
use Illuminate\Container\Attributes\Scoped;

/**
 * A Node whose SSH host key is pinned only during the run that captures it, such as a task VM guest. Inside
 * {@see self::during()} its SSH targets carry `host_keys: null`; the run's typed bind step fills in the key its
 * own VM step pinned before any child uses the target (the node.add `Bind the Node identity` pattern).
 */
#[Scoped]
final class PendingSshHostKey
{
    private ?int $nodeId = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $capture
     * @return T
     */
    public function during(int $nodeId, Closure $capture): mixed
    {
        $previous = $this->nodeId;
        $this->nodeId = $nodeId;
        try {
            return $capture();
        } finally {
            $this->nodeId = $previous;
        }
    }

    public function covers(?int $nodeId): bool
    {
        return $nodeId !== null && $this->nodeId === $nodeId;
    }
}
