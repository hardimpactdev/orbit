<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use Closure;

interface DatabaseRemovalLock
{
    /**
     * @template T
     *
     * @param  list<int>  $serverIds
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(array $serverIds, Closure $operation): mixed;
}
