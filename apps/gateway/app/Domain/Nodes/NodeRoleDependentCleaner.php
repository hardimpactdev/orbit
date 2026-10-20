<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\Nodes\NativeNodeRoleDependentCleaner;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeNodeRoleDependentCleaner::class)]
interface NodeRoleDependentCleaner
{
    public function clean(NodeRoleDependencySet $dependencies): void;
}
