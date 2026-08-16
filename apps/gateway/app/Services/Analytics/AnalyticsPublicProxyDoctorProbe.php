<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\AppAnalyticsBinding;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Doctor\DoctorRestoreActionId;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class AnalyticsPublicProxyDoctorProbe
{
    public const string PUBLIC_ROUTE_KEY = 'proxy.analytics.public_route_missing';

    /**
     * @return array<string, string> code => restore_action
     */
    public static function restoreSupport(): array
    {
        return DoctorRestoreActionId::map([
            self::PUBLIC_ROUTE_KEY,
        ]);
    }

    public function __construct(
        private AnalyticsRouteRegistrar $routeRegistrar,
    ) {}

    /**
     * @return list<DriftEntry>
     */
    public function drift(Node $node, ?string $app = null): array
    {
        $entries = [];
        $bindings = AppAnalyticsBinding::query()
            ->with('instance.app')
            ->where('enabled', true)
            ->get();

        foreach ($bindings as $binding) {
            if (
                $app !== null
                && $binding->instance->app->name !== $app
                && $binding->instance->name !== $app
                && "{$binding->instance->app->name}.{$binding->instance->name}" !== $app
            ) {
                continue;
            }

            try {
                $intents = $this->routeRegistrar->publicRouteIntents($binding);
            } catch (Throwable $throwable) {
                $entries[] = $this->unverifiableEntry($binding, $throwable);

                continue;
            }

            foreach ($intents as $intent) {
                if ($intent->node_id !== $node->id) {
                    continue;
                }

                $entry = $this->routeDrift($binding, $intent);

                if ($entry instanceof DriftEntry) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function restore(Node $node, DriftEntry $entry): ?array
    {
        if ($entry->key !== self::PUBLIC_ROUTE_KEY) {
            return null;
        }

        $binding = AppAnalyticsBinding::query()->find((int) ($entry->detail['binding_id'] ?? 0));

        if (! $binding instanceof AppAnalyticsBinding || ! $binding->enabled) {
            return null;
        }

        $this->routeRegistrar->syncPublicHosts($binding);
        $this->routeRegistrar->convergePublicHosts($binding);

        return [
            'family' => 'proxy',
            'node' => $node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => 'Re-synced and enacted the public analytics route from app binding intent.',
            'details' => [
                'domain' => (string) ($entry->detail['domain'] ?? ''),
            ],
        ];
    }

    private function routeDrift(AppAnalyticsBinding $binding, ProxyRoute $intent): ?DriftEntry
    {
        $route = ProxyRoute::query()->where('domain', $intent->domain)->first();
        $detail = [
            'binding_id' => $binding->id,
            'app' => $binding->instance->app->name,
            'instance' => $binding->instance->name,
            'domain' => $intent->domain,
        ];

        if (! $route instanceof ProxyRoute) {
            return new DriftEntry(
                family: 'proxy',
                key: self::PUBLIC_ROUTE_KEY,
                kind: DriftKind::Missing,
                summary: "Public analytics route {$intent->domain} is missing from gateway proxy registry.",
                detail: $detail,
            );
        }

        if (! $this->routeMatchesIntent($route, $intent)) {
            return new DriftEntry(
                family: 'proxy',
                key: self::PUBLIC_ROUTE_KEY,
                kind: DriftKind::Divergent,
                summary: "Public analytics route {$intent->domain} differs from app binding intent.",
                detail: $detail,
            );
        }

        return null;
    }

    private function unverifiableEntry(AppAnalyticsBinding $binding, Throwable $throwable): DriftEntry
    {
        return new DriftEntry(
            family: 'proxy',
            key: self::PUBLIC_ROUTE_KEY,
            kind: DriftKind::Unverifiable,
            summary: "Public analytics route intent cannot be resolved for instance {$binding->instance->app->name}.{$binding->instance->name}.",
            detail: [
                'binding_id' => $binding->id,
                'app' => $binding->instance->app->name,
                'instance' => $binding->instance->name,
                'reason' => $throwable->getMessage(),
            ],
        );
    }

    private function routeMatchesIntent(ProxyRoute $route, ProxyRoute $intent): bool
    {
        $keys = ['node_id', 'app_id', 'workspace_id', 'instance_id', 'owner_type', 'kind', 'config', 'source_hash'];

        return $route->only($keys) === $intent->only($keys);
    }
}
