<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Doctor\DoctorRestoreActionId;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class AnalyticsProxyDoctorProbe
{
    public const string RouterRouteKey = 'proxy.analytics.router_route_missing';

    public const string RouterRouteOrphanedKey = 'proxy.analytics.router_route_orphaned';

    /**
     * @return array<string, string> code => restore_action
     */
    public static function restoreSupport(): array
    {
        return DoctorRestoreActionId::map([
            self::RouterRouteKey,
            self::RouterRouteOrphanedKey,
        ]);
    }

    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private AnalyticsRouteRegistrar $routeRegistrar,
    ) {}

    /**
     * @return list<DriftEntry>
     */
    public function drift(Node $node): array
    {
        if (! $this->nodeRoleAssignments->nodeCanServeRouter($node)) {
            return [];
        }

        $activeAnalyticsNodeIds = $this->nodeRoleAssignments
            ->activeNodeIdsForRole(NodeRoleName::Analytics->value);

        if ($activeAnalyticsNodeIds === []) {
            return $this->orphanedRouteDrift();
        }

        try {
            $intent = $this->routeRegistrar->serviceRouteIntent();
        } catch (Throwable $throwable) {
            return [new DriftEntry(
                family: 'proxy',
                key: self::RouterRouteKey,
                kind: DriftKind::Unverifiable,
                summary: "Analytics service route intent cannot be resolved for router node {$node->name}.",
                detail: [
                    'domain' => AnalyticsRouteRegistrar::ServiceDomain,
                    'reason' => $throwable->getMessage(),
                ],
            )];
        }

        if ($intent->node_id !== $node->id) {
            return [];
        }

        $route = ProxyRoute::query()
            ->where('domain', AnalyticsRouteRegistrar::ServiceDomain)
            ->first();

        if (! $route instanceof ProxyRoute) {
            return [new DriftEntry(
                family: 'proxy',
                key: self::RouterRouteKey,
                kind: DriftKind::Missing,
                summary: 'Analytics service route analytics.orbit is missing from gateway proxy registry.',
                detail: ['domain' => AnalyticsRouteRegistrar::ServiceDomain],
            )];
        }

        if ($this->routeMatchesIntent($route, $intent)) {
            return [];
        }

        return [new DriftEntry(
            family: 'proxy',
            key: self::RouterRouteKey,
            kind: DriftKind::Divergent,
            summary: 'Analytics service route analytics.orbit differs from gateway analytics route intent.',
            detail: ['domain' => AnalyticsRouteRegistrar::ServiceDomain],
        )];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function restore(Node $node, DriftEntry $entry): ?array
    {
        if ($entry->key === self::RouterRouteKey) {
            $route = $this->routeRegistrar->convergeServiceRoute();

            return $this->completed(
                $node,
                $entry,
                'Re-synced and enacted the private analytics router route from gateway intent.',
                $route->domain,
            );
        }

        if ($entry->key !== self::RouterRouteOrphanedKey) {
            return null;
        }

        $this->routeRegistrar->removeServiceRoute();

        return $this->completed(
            $node,
            $entry,
            'Removed the orphaned analytics.orbit service route and its rendered artifacts.',
            AnalyticsRouteRegistrar::ServiceDomain,
        );
    }

    /**
     * @return list<DriftEntry>
     */
    private function orphanedRouteDrift(): array
    {
        if (! ProxyRoute::query()->where('domain', AnalyticsRouteRegistrar::ServiceDomain)->exists()) {
            return [];
        }

        return [new DriftEntry(
            family: 'proxy',
            key: self::RouterRouteOrphanedKey,
            kind: DriftKind::Extra,
            summary: 'The analytics.orbit service route exists but no active analytics role assignment remains.',
            detail: ['domain' => AnalyticsRouteRegistrar::ServiceDomain],
        )];
    }

    private function routeMatchesIntent(ProxyRoute $route, ProxyRoute $intent): bool
    {
        return (
            $route->node_id === $intent->node_id
            && $route->app_id === $intent->app_id
            && $route->instance_id === $intent->instance_id
            && $route->workspace_id === $intent->workspace_id
            && $route->owner_type === $intent->owner_type
            && $route->kind === $intent->kind
            && $route->config === $intent->config
            && $route->source_hash === $intent->source_hash
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function completed(Node $node, DriftEntry $entry, string $summary, string $domain): array
    {
        return [
            'family' => 'proxy',
            'node' => $node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => $summary,
            'details' => ['domain' => $domain],
        ];
    }
}
