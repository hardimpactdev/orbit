<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\Nodes\NativeNodeProvisioningLock;
use Closure;
use Illuminate\Container\Attributes\Bind;
use Illuminate\Container\Attributes\Scoped;

#[Bind(NativeNodeProvisioningLock::class)]
#[Scoped]
interface NodeProvisioningLock
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $nodeName, Closure $callback): mixed;
}
