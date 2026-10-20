<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Infrastructure\Tools\NativeToolManagerScopeLock;
use Closure;
use Illuminate\Container\Attributes\Bind;
use Illuminate\Container\Attributes\Scoped;

#[Bind(NativeToolManagerScopeLock::class)]
#[Scoped]
interface ToolManagerScopeLock
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(int $nodeId, ToolManagerName $manager, Closure $callback): mixed;
}
