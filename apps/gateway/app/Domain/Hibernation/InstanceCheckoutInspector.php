<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

use App\Infrastructure\Hibernation\RemoteInstanceCheckoutInspector;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteInstanceCheckoutInspector::class)]
interface InstanceCheckoutInspector
{
    public function inspect(Instance $instance): RuntimeDependencyState;

    public function prune(Instance $instance, RuntimeDependencyState $state): void;

    public function restore(Instance $instance, RuntimeDependencyState $state): void;
}
