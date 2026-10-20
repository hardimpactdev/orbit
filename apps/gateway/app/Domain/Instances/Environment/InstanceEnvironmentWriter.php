<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Infrastructure\Instances\RemoteInstanceEnvironmentAccess;
use Illuminate\Container\Attributes\Bind;
use SensitiveParameter;

#[Bind(RemoteInstanceEnvironmentAccess::class)]
interface InstanceEnvironmentWriter
{
    public function write(
        InstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
    ): InstanceEnvironmentWriteResult;
}
