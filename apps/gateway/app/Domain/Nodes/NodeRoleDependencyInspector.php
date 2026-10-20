<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\Nodes\EloquentNodeRoleDependencyInspector;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(EloquentNodeRoleDependencyInspector::class)]
interface NodeRoleDependencyInspector
{
    public function inspect(Node $node, RoleName $role): NodeRoleDependencySet;
}
