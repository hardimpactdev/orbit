<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Domain\Analytics\AnalyticsTrackingRouteProjector;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Models\Route;

final readonly class NativeAnalyticsTrackingRouteProjector implements AnalyticsTrackingRouteProjector
{
    public function __construct(
        private IngressSiteRepository $sites,
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevCaddyManager $caddy,
        private DnsmasqPrivateDnsManager $dns,
    ) {}

    public function prepareHost(Route $candidate): void
    {
        $this->certificates->convergeRouteRouter($candidate, $this->sites->routerNode($candidate));
    }

    public function buildHost(Route $placement): void
    {
        $this->caddy->build($this->sites->routerNode($placement));
    }

    public function publishDns(): void
    {
        $this->dns->converge();
    }

    public function withdrawHost(Route $retired, Route $current): void
    {
        $host = $this->sites->routerNode($retired);
        $this->caddy->build($host);

        if (! $host->is($this->sites->routerNode($current))) {
            $this->certificates->removeRouteRouter($retired, $host);
        }
    }
}
