<?php

declare(strict_types=1);

use App\Infrastructure\Analytics\AnalyticsCaddySiteRenderer;

it('renders a site that proxies analytics.orbit to Plausible on the WireGuard address', function (): void {
    $site = new AnalyticsCaddySiteRenderer()->render('10.44.0.3');

    expect($site)->toStartWith('# Managed by Orbit: analytics')
        ->toContain('analytics.orbit {')
        ->toContain('bind __ORBIT_ANALYTICS_BIND__')
        ->toContain('tls /etc/caddy/orbit-analytics-cert-current/analytics.pem /etc/caddy/orbit-analytics-cert-current/analytics.key')
        ->toContain('reverse_proxy 10.44.0.3:8000');
});
