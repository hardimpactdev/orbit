<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Infrastructure\Routes\NativeCustomProxyRouteProjector;
use App\Models\Route;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeCustomProxyRouteProjector::class)]
interface CustomProxyRouteProjector
{
    public function converge(Route $route): void;
}
