<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Infrastructure\AppDev\RemoteAppDevTldRouteManager;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteAppDevTldRouteManager::class)]
interface AppDevTldRouteManager
{
    public function converge(Node $node): void;
}
