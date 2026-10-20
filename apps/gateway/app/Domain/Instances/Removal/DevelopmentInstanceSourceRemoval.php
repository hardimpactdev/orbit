<?php

declare(strict_types=1);

namespace App\Domain\Instances\Removal;

use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceRemoval;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteDevelopmentInstanceSourceRemoval::class)]
interface DevelopmentInstanceSourceRemoval
{
    public function inspect(
        Instance $instance,
        bool $force,
        bool $inspectContent = true,
    ): InstanceSourceInventory;

    public function remove(
        Instance $instance,
        InstanceSourceInventory $inventory,
        bool $force,
    ): void;
}
