<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Infrastructure\Instances\RemoteInstanceEnvironmentRuntimeRefresher;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteInstanceEnvironmentRuntimeRefresher::class)]
interface InstanceEnvironmentRuntimeRefresher
{
    public function refresh(InstanceEnvironmentContext $context): string;
}
