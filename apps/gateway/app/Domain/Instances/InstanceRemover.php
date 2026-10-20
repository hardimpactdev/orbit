<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\DestroyInstanceAction;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use Illuminate\Container\Attributes\Bind;

#[Bind(DestroyInstanceAction::class)]
interface InstanceRemover
{
    public function execute(Instance $instance, bool $force): InstanceRemoval;
}
