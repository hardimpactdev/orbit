<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

interface InstanceEnvironmentRuntimeRefresher
{
    public function refresh(InstanceEnvironmentContext $context): string;
}
