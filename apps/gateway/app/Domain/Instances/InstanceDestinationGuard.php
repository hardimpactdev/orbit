<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Nodes\Storage\StoragePath;
use App\Infrastructure\Instances\RemoteInstanceDestinationGuard;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteInstanceDestinationGuard::class)]
interface InstanceDestinationGuard
{
    public function assertUnoccupied(Node $node, StoragePath $destination): void;
}
