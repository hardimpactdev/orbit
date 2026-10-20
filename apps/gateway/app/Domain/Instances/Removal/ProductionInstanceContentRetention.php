<?php

declare(strict_types=1);

namespace App\Domain\Instances\Removal;

use App\Infrastructure\Instances\RecordedProductionInstanceContentRetention;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use Illuminate\Container\Attributes\Bind;

#[Bind(RecordedProductionInstanceContentRetention::class)]
interface ProductionInstanceContentRetention
{
    public function inventory(Instance $instance): InstanceSourceInventory;

    public function prepare(InstanceRemovalMember $member): void;

    public function revalidate(InstanceRemovalMember $member): void;

    public function finalize(InstanceRemovalMember $member): string;
}
