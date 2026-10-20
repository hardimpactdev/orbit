<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Infrastructure\Instances\RemoteProductionInstanceSourceLifecycle;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteProductionInstanceSourceLifecycle::class)]
interface ProductionInstanceSourceLifecycle
{
    public function prepareUser(Instance $instance): void;

    public function prepareSource(Instance $instance, bool $allowExisting): void;

    public function resolve(Instance $instance): DevelopmentSourceResolution;

    public function inspectProfile(Instance $instance): DevelopmentSourceProfile;

    public function prepareCaddyAccess(Instance $instance): void;
}
