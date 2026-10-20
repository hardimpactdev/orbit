<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Infrastructure\Doctor\NativeCustomProxyRouteInspector;
use App\Models\RouteCustomProxy;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeCustomProxyRouteInspector::class)]
interface CustomProxyRouteInspector
{
    public function inspect(RouteCustomProxy $proxy): CustomProxyRouteObservation;
}
