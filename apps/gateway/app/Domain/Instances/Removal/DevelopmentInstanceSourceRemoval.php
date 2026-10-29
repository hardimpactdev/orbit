<?php

declare(strict_types=1);

namespace App\Domain\Instances\Removal;

use App\Models\Instance;

interface DevelopmentInstanceSourceRemoval
{
    public function inspect(
        Instance $instance,
        bool $force,
        bool $inspectContent = true,
    ): InstanceSourceInventory;
}
