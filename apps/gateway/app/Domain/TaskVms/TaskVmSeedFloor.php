<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use Closure;
use Illuminate\Container\Attributes\Scoped;

/**
 * The hosts whose ready copies a placement may use, while a proof is admitted: only those whose copies include every change of what the
 * image carries up to the merge base of the commit under proof (docs/reference/pipeline/2-development-environment.mmd). Outside {@see self::during()}
 * there is no floor and any host with usable copies serves. GitHub is asked before the admission transaction, so the transaction reads
 * only this list.
 */
#[Scoped]
final class TaskVmSeedFloor
{
    /** @var list<int>|null */
    private ?array $hosts = null;

    /**
     * @param  list<int>  $hostNodeIds  the hosts whose copies are fresh enough
     * @param  Closure(): mixed  $admit
     */
    public function during(array $hostNodeIds, Closure $admit): mixed
    {
        $previous = $this->hosts;
        $this->hosts = $hostNodeIds;
        try {
            return $admit();
        } finally {
            $this->hosts = $previous;
        }
    }

    public function active(): bool
    {
        return $this->hosts !== null;
    }

    public function allows(int $hostNodeId): bool
    {
        return $this->hosts === null || in_array($hostNodeId, $this->hosts, true);
    }
}
