<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Doctor\CaddyBuildInspector;
use App\Domain\Doctor\CustomProxyRouteInspector;
use App\Domain\Doctor\GatewayVpnStateInspector;
use App\Domain\Doctor\InstalledPackageInventory;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Doctor\PhpPoolDirectoryInspector;
use App\Domain\Doctor\PrivateRouteProjectionInspector;
use App\Domain\Doctor\ProcessStateInspector;
use App\Domain\Doctor\ProjectStateInspector;
use App\Domain\Doctor\PublicRouteEdgeInspector;
use App\Domain\Doctor\RoleStateInspector;
use App\Domain\Doctor\RouteApplicationUrlInspector;
use App\Domain\Doctor\ScheduleStateInspector;
use App\Infrastructure\Doctor\NativeCaddyBuildInspector;
use App\Infrastructure\Doctor\NativeCustomProxyRouteInspector;
use App\Infrastructure\Doctor\NativeGatewayVpnStateInspector;
use App\Infrastructure\Doctor\NativeInstanceStateInspector;
use App\Infrastructure\Doctor\NativeNodeStateInspector;
use App\Infrastructure\Doctor\NativePhpPoolDirectoryInspector;
use App\Infrastructure\Doctor\NativePrivateRouteProjectionInspector;
use App\Infrastructure\Doctor\NativeProcessStateInspector;
use App\Infrastructure\Doctor\NativeProjectStateInspector;
use App\Infrastructure\Doctor\NativePublicRouteEdgeInspector;
use App\Infrastructure\Doctor\NativeRoleStateInspector;
use App\Infrastructure\Doctor\NativeRouteApplicationUrlInspector;
use App\Infrastructure\Doctor\NativeScheduleStateInspector;
use App\Infrastructure\Doctor\SharedInstalledPackageInventory;
use Illuminate\Support\ServiceProvider;

final class DoctorServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ProjectStateInspector::class => NativeProjectStateInspector::class,
        GatewayVpnStateInspector::class => NativeGatewayVpnStateInspector::class,
        InstanceStateInspector::class => NativeInstanceStateInspector::class,
        PublicRouteEdgeInspector::class => NativePublicRouteEdgeInspector::class,
        PrivateRouteProjectionInspector::class => NativePrivateRouteProjectionInspector::class,
        RouteApplicationUrlInspector::class => NativeRouteApplicationUrlInspector::class,
        CustomProxyRouteInspector::class => NativeCustomProxyRouteInspector::class,
        InstalledPackageInventory::class => SharedInstalledPackageInventory::class,
        NodeStateInspector::class => NativeNodeStateInspector::class,
        ProcessStateInspector::class => NativeProcessStateInspector::class,
        RoleStateInspector::class => NativeRoleStateInspector::class,
        CaddyBuildInspector::class => NativeCaddyBuildInspector::class,
        PhpPoolDirectoryInspector::class => NativePhpPoolDirectoryInspector::class,
        ScheduleStateInspector::class => NativeScheduleStateInspector::class,
    ];
}
