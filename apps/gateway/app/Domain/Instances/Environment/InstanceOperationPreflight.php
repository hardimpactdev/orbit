<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Infrastructure\Instances\RemoteInstanceEnvironmentAccess;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteInstanceEnvironmentAccess::class)]
interface InstanceOperationPreflight
{
    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void;

    public function assertEnvironmentWritable(
        InstanceEnvironmentContext $context,
        int $requiredCapacityBytes,
    ): void;
}
