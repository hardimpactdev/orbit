<?php

declare(strict_types=1);

namespace App\Domain\Instances\Removal;

use App\Models\Instance;
use App\Models\InstanceRemovalMember;

interface ProductionInstanceSourceRemoval
{
    public function inventory(Instance $instance): InstanceSourceInventory;

    public function revalidate(InstanceRemovalMember $member): void;
}
