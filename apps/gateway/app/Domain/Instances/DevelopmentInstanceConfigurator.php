<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Infrastructure\Instances\RemoteDevelopmentInstanceConfigurator;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteDevelopmentInstanceConfigurator::class)]
interface DevelopmentInstanceConfigurator
{
    public function inspect(Instance $instance): DevelopmentSourceProfile;

    public function configureLaravelUrl(Instance $instance, string $url): void;
}
