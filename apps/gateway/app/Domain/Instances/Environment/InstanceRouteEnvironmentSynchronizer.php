<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

#[Bind(SynchronizeInstanceEnvironmentAction::class)]
interface InstanceRouteEnvironmentSynchronizer
{
    public function synchronizeRouteDomain(
        Instance $instance,
        InstanceEnvironmentRouteDomain $domain,
    ): InstanceEnvironmentResult;
}
