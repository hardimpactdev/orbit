<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Analytics\AnalyticsTrackingRouteProjector;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Models\NodeRole;
use App\Models\Route;

final readonly class NativeAnalyticsTrackingRouteProjector implements AnalyticsTrackingRouteProjector
{
    public function __construct(private IngressSiteRepository $sites, private StartOperationAction $operations, private AwaitOperation $await) {}

    public function converge(Route $route): void
    {
        $this->start('route.analytics_publish', $route, ['route_id' => $route->id]);
    }

    public function prepareHost(Route $candidate): void
    {
        $this->start('route.analytics_host_prepare', $candidate, ['route_id' => $candidate->id]);
    }

    public function buildHost(Route $placement): void
    {
        $this->start('route.caddy_converge', $placement, ['reason' => 'analytics tracking host']);
    }

    public function publishDns(): void
    {
        $dns = NodeRole::query()->where('role', RoleName::Vpn)->where('status', LifecycleStatus::Active)->sole();
        $this->await->execute($this->operations->execute('route.private_dns_publish', 'node', $dns->node_id, $dns->node_id, ['reason' => 'analytics tracking intent'], 'integration', null));
    }

    public function withdrawHost(Route $retired, Route $current): void
    {
        $host = $this->sites->routerNode($retired);
        if ($host->id === $this->sites->routerNode($current)->id) {
            $this->buildHost($current);

            return;
        }
        $this->start('route.analytics_host_withdraw', $retired, ['route_id' => $retired->id]);
    }

    /** @param array<string, mixed> $input */
    private function start(string $kind, Route $route, array $input): void
    {
        $host = $this->sites->routerNode($route);
        $this->await->execute($this->operations->execute($kind, 'route', $route->id, $host->id, $input, 'integration', null));
    }
}
