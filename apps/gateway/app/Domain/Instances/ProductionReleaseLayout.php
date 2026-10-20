<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Infrastructure\Instances\RemoteProductionInstanceSourceLifecycle;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteProductionInstanceSourceLifecycle::class)]
interface ProductionReleaseLayout
{
    public function validateCurrent(Instance $instance): void;

    public function clearCurrent(Instance $instance): void;
}
