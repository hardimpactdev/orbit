<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class AnalyticsRouteParentExecution extends RoutePhaseExecution
{
    public const array Kinds = ['route.analytics_publish', 'route.analytics_remove', 'route.analytics_host_prepare', 'route.analytics_host_withdraw'];

    protected function command(): string
    {
        return 'route.analytics-parent';
    }

    /** @return list<string> Validation order. Swamp owns execution of these phases. */
    public function sequence(Operation $operation): array
    {
        $plan = ValidatedData::object($operation->state['route_plan'] ?? []);
        $public = ($plan['publication'] ?? null) === 'public';

        return match ($operation->kind) {
            'route.analytics_host_prepare' => ['host-certificate'],
            'route.analytics_host_withdraw' => ['await-dns-expiry', 'host-withdraw', 'host-certificate-remove'],
            'route.analytics_remove' => ['dns', 'caddy', 'certificates', 'firewall', 'record'],
            'route.analytics_publish' => ['host-certificate', 'publish-sites', 'host-caddy', 'dns-published',
                ...($public ? ['public-edge-verified', 'public-activated', 'ingress-firewall'] : []), 'activate-route'],
            default => throw new ResourceOperationException('operation.execution_forbidden', 'This is not an analytics Route parent.', 422),
        };
    }
}
