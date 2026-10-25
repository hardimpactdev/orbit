<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

interface InstanceOperationPreflight
{
    public function readWritableEnvironment(
        InstanceEnvironmentContext $context,
        int $requiredCapacityBytes,
    ): string;

    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void;

    public function assertEnvironmentWritable(
        InstanceEnvironmentContext $context,
        int $requiredCapacityBytes,
    ): void;
}
