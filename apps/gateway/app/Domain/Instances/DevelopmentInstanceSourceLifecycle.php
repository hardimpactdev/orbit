<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceLifecycle;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteDevelopmentInstanceSourceLifecycle::class)]
interface DevelopmentInstanceSourceLifecycle
{
    public function prepare(Instance $instance, bool $allowExisting): void;

    public function inspectPrepared(Instance $instance): void;

    public function resolve(Instance $instance): DevelopmentSourceResolution;

    public function inspectResolved(Instance $instance): DevelopmentSourceResolution;
}
