<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;

final readonly class ProxyRouteProbe
{
    private const array OwnerTypes = ['app', 'workspace', 'gateway', 'tool', 'custom'];

    private const array Kinds = ['app', 'workspace', 'internal', 'proxy', 'redirect'];

    public function key(): string
    {
        return 'proxy';
    }

    public function label(): string
    {
        return 'Proxy';
    }

    public function introspect(ProxyRoute $route): ProbeSnapshot
    {
        return new ProbeSnapshot([]);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(ProxyRoute $route, ProbeSnapshot $snapshot): array
    {
        return [
            ...$this->checkRecordCompleteness($route),
            ...$this->checkOwnerEligibility($route),
            ...$this->checkNodeEligibility($route),
            ...$this->checkCustomDomainConflict($route),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(ProxyRoute $route): array
    {
        if (
            ! is_string($route->domain)
            || $route->domain === ''
            || ! is_int($route->node_id)
            || ! in_array($route->owner_type, self::OwnerTypes, true)
            || ! in_array($route->kind, self::Kinds, true)
            || ! is_string($route->source_hash)
            || $route->source_hash === ''
            || ! $this->hasTargetShape($route)
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Proxy route record for {$route->domain} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkOwnerEligibility(ProxyRoute $route): array
    {
        $route->loadMissing(['app', 'workspace']);

        if ($route->owner_type === 'app' && ! $route->app instanceof App) {
            return [$this->ownerInvalid($route, 'app')];
        }

        if ($route->owner_type === 'workspace' && ! $route->workspace instanceof Workspace) {
            return [$this->ownerInvalid($route, 'workspace')];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkNodeEligibility(ProxyRoute $route): array
    {
        $route->loadMissing('node');

        if (! $route->node instanceof Node) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Proxy route {$route->domain} points at a missing serving node.",
                ),
            ];
        }

        if ($route->node->status !== 'active' || ! in_array($route->node->role, ['gateway', 'app'], true)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Proxy route {$route->domain} is served by node {$route->node->name}, which is not an active gateway or app node.",
                    detail: [
                        'node' => $route->node->name,
                        'role' => $route->node->role,
                        'status' => $route->node->status,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCustomDomainConflict(ProxyRoute $route): array
    {
        if ($route->owner_type !== 'custom') {
            return [];
        }

        $app = App::query()
            ->where('domain', $route->domain)
            ->first();

        if ($app instanceof App) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.domain_conflict',
                    kind: DriftKind::Divergent,
                    summary: "Custom proxy route {$route->domain} conflicts with app {$app->name}.",
                    detail: [
                        'domain' => $route->domain,
                        'owner_type' => 'app',
                        'owner_name' => $app->name,
                    ],
                ),
            ];
        }

        return [];
    }

    private function ownerInvalid(ProxyRoute $route, string $ownerType): DriftEntry
    {
        return new DriftEntry(
            family: $this->key(),
            key: 'proxy.owner_invalid',
            kind: DriftKind::Divergent,
            summary: "Proxy route {$route->domain} points at a missing {$ownerType} owner.",
            detail: [
                'owner_type' => $ownerType,
            ],
        );
    }

    private function hasTargetShape(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];

        if ($route->kind === 'redirect') {
            $target = $config['target']['value'] ?? $config['redirect'] ?? $config['redirect_url'] ?? null;
            $code = $config['code'] ?? $config['redirect_code'] ?? null;

            return is_string($target)
                && $target !== ''
                && is_int($code);
        }

        if ($route->kind === 'proxy') {
            $target = $config['target']['value'] ?? $config['upstream'] ?? $config['target'] ?? null;

            return is_string($target) && $target !== '';
        }

        if ($route->kind === 'app') {
            return is_int($route->app_id);
        }

        if ($route->kind === 'workspace') {
            return is_int($route->workspace_id);
        }

        return true;
    }
}
