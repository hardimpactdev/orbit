<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Firewall\FirewallInspector;
use App\Domain\Firewall\FirewallManager;
use App\Domain\Firewall\RouterLanIngressPublisher;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Infrastructure\Firewall\NativeRouterLanIngressReconciler;
use App\Infrastructure\Firewall\NativeUfwFirewallInspector;
use App\Infrastructure\Firewall\NativeUfwFirewallManager;
use App\Infrastructure\Nodes\Roles\NativeNodeRoleFirewallManager;
use Illuminate\Support\ServiceProvider;

final class FirewallServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        FirewallInspector::class => NativeUfwFirewallInspector::class,
        FirewallManager::class => NativeUfwFirewallManager::class,
        RouterLanIngressPublisher::class => NativeNodeRoleFirewallManager::class,
        RouterLanIngressReconciler::class => NativeRouterLanIngressReconciler::class,
    ];
}
