<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\StoredInteger;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Collection;

final readonly class RouterLanIngressPolicy
{
    public const string CommentPrefix = 'orbit:router-lan-https';

    public const array Families = [
        self::CommentPrefix => ['protocol' => 'tcp', 'port' => '443'],
        'orbit:router-lan-dns-udp' => ['protocol' => 'udp', 'port' => '53'],
        'orbit:router-lan-dns-tcp' => ['protocol' => 'tcp', 'port' => '53'],
    ];

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState}>  $clusterOverrides
     * @return list<Node>
     */
    public function eligibleSources(Node $router, array $nodeOverrides = [], array $clusterOverrides = []): array
    {
        $router = $this->applyNodeOverrides($router, $this->overrideFor($router, $nodeOverrides));

        if (! $this->routerAdmits($router, $clusterOverrides, $nodeOverrides)) {
            return [];
        }

        $clusterId = $this->identity($router, $nodeOverrides)['cluster_id'];
        assert(is_int($clusterId));

        $memberIds = Node::query()
            ->where('cluster_id', $clusterId)
            ->whereKeyNot($router->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => StoredInteger::from($id))
            ->all();

        $candidate = app(RouteRenderContext::class)->enrollment();
        if ($candidate !== null && $candidate->nodeId !== $router->id) {
            $memberIds = array_values(array_filter($memberIds, static fn (int $id): bool => $id !== $candidate->nodeId));
            if ($candidate->clusterId === $clusterId) {
                $memberIds[] = $candidate->nodeId;
            }
        }

        foreach ($nodeOverrides as $id => $override) {
            if ($id === $router->id || ! array_key_exists('cluster_id', $override)) {
                continue;
            }

            if ($override['cluster_id'] === $clusterId) {
                $memberIds[] = $id;

                continue;
            }

            $memberIds = array_values(array_filter(
                $memberIds,
                static fn (int $memberId): bool => $memberId !== $id,
            ));
        }

        $memberIds = array_values(array_unique($memberIds));
        sort($memberIds);

        if ($memberIds === []) {
            return [];
        }

        $members = Node::query()->whereKey($memberIds)->orderBy('id')->get()->keyBy('id');
        $eligible = [];

        foreach ($memberIds as $id) {
            $member = $members->get($id);

            if (! $member instanceof Node) {
                continue;
            }

            $member = $this->applyNodeOverrides($member, $nodeOverrides[$id] ?? []);

            if ($this->isEligibleSource($member, $clusterId, $nodeOverrides)) {
                $eligible[] = $member;
            }
        }

        return $eligible;
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState}>  $clusterOverrides
     * @param  list<int>  $clusterIds
     * @return Collection<int, Node>
     */
    public function affectedRouters(
        array $nodeOverrides = [],
        array $clusterOverrides = [],
        array $clusterIds = [],
    ): Collection {
        $ids = $clusterIds;

        foreach (array_keys($clusterOverrides) as $clusterId) {
            $ids[] = (int) $clusterId;
        }

        foreach ($nodeOverrides as $nodeId => $override) {
            $node = Node::query()->find((int) $nodeId);

            if ($node instanceof Node && is_int($node->cluster_id)) {
                $ids[] = $node->cluster_id;
            }

            if (array_key_exists('cluster_id', $override) && is_int($override['cluster_id'])) {
                $ids[] = $override['cluster_id'];
            }
        }

        $ids = array_values(array_unique(array_filter($ids, is_int(...))));

        if ($ids === []) {
            return new Collection;
        }

        return NodeRole::query()
            ->where('role', RoleName::Router)
            ->where('status', LifecycleStatus::Active)
            ->whereIn('cluster_id', $ids)
            ->with(['node.cluster'])
            ->orderBy('id')
            ->get()
            ->map(static fn (NodeRole $assignment): Node => $assignment->node)
            ->unique('id')
            ->values();
    }

    public function commentFor(int $sourceNodeId, string $prefix = self::CommentPrefix): string
    {
        return $prefix.':'.$sourceNodeId;
    }

    public function ownsComment(string $comment): bool
    {
        return array_any(
            array_keys(self::Families),
            static fn (string $prefix): bool => $comment === $prefix || str_starts_with($comment, $prefix.':'),
        );
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     */
    public function routerHasContactableLan(Node $router, array $nodeOverrides = []): bool
    {
        $proposed = $this->ipv4($this->identity($router, $nodeOverrides)['lan_ip']);

        return $proposed !== null || $this->ipv4($router->lan_ip) !== null;
    }

    /**
     * @param  array<int, array{state?: ClusterState}>  $clusterOverrides
     * @param  array<int,array{cluster_id?:?int,lan_ip?:?string,status?:LifecycleStatus,wireguard_ip?:?string,wireguard_public_key?:?string}>  $nodeOverrides
     */
    private function routerAdmits(Node $router, array $clusterOverrides, array $nodeOverrides): bool
    {
        if (! $router->exists || $router->id < 1) {
            return false;
        }

        $identity = $this->identity($router, $nodeOverrides);
        if ($this->ipv4($identity['lan_ip']) === null || $this->ipv4($identity['wireguard_ip']) === null) {
            return false;
        }

        if (! $identity['prospective'] && (! is_string($router->wireguard_public_key) || $router->wireguard_public_key === '')) {
            return false;
        }

        if (! is_int($identity['cluster_id'])) {
            return false;
        }

        $cluster = $router->relationLoaded('cluster') && $router->cluster_id === $identity['cluster_id']
            ? $router->cluster : Cluster::query()->find($identity['cluster_id']);

        if (! $cluster instanceof Cluster) {
            return false;
        }

        $state = $clusterOverrides[$cluster->id]['state'] ?? $cluster->state;

        return $state === ClusterState::Active;
    }

    /** @param array<int,array{cluster_id?:?int,lan_ip?:?string,status?:LifecycleStatus,wireguard_ip?:?string,wireguard_public_key?:?string}> $nodeOverrides */
    private function isEligibleSource(Node $member, int $clusterId, array $nodeOverrides): bool
    {
        $identity = $this->identity($member, $nodeOverrides);

        return $member->exists
            && $identity['cluster_id'] === $clusterId
            && ($member->status === LifecycleStatus::Active || $identity['prospective'])
            && $this->ipv4($identity['lan_ip']) !== null
            && $this->ipv4($identity['wireguard_ip']) !== null
            && ($identity['prospective'] || (is_string($member->wireguard_public_key) && $member->wireguard_public_key !== ''));
    }

    /** Explicit simulation overrides win; the enrollment view never changes a Node model.
     * @param  array<int,array{cluster_id?:?int,lan_ip?:?string,status?:LifecycleStatus,wireguard_ip?:?string,wireguard_public_key?:?string}>  $nodeOverrides
     * @return array{cluster_id:?int,lan_ip:?string,wireguard_ip:?string,prospective:bool}
     */
    private function identity(Node $node, array $nodeOverrides): array
    {
        $render = app(RouteRenderContext::class);
        $candidate = $render->enrollment();
        $override = $nodeOverrides[$node->id] ?? [];
        $prospective = $candidate?->nodeId === $node->id && $candidate->platform === 'linux'
            && in_array($node->status, [LifecycleStatus::Active, LifecycleStatus::Provisioning, LifecycleStatus::Failed], true);

        return ['cluster_id' => array_key_exists('cluster_id', $override) ? $override['cluster_id'] : $render->clusterId($node),
            'lan_ip' => array_key_exists('lan_ip', $override) ? $override['lan_ip'] : $render->lanIp($node),
            'wireguard_ip' => array_key_exists('wireguard_ip', $override) ? $override['wireguard_ip'] : $render->wireguardIp($node),
            'prospective' => $prospective];
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @return array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}
     */
    private function overrideFor(Node $node, array $nodeOverrides): array
    {
        return $nodeOverrides[$node->id] ?? [];
    }

    /**
     * @param  array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}  $override
     */
    private function applyNodeOverrides(Node $node, array $override): Node
    {
        if ($override === []) {
            return $node;
        }

        $clone = clone $node;

        if (array_key_exists('cluster_id', $override)) {
            $clone->cluster_id = $override['cluster_id'];
            $clone->unsetRelation('cluster');
        }

        if (array_key_exists('lan_ip', $override)) {
            $clone->lan_ip = $override['lan_ip'];
        }

        if (array_key_exists('status', $override)) {
            $clone->status = $override['status'];
        }

        if (array_key_exists('wireguard_ip', $override)) {
            $clone->wireguard_ip = $override['wireguard_ip'];
        }

        if (array_key_exists('wireguard_public_key', $override)) {
            $clone->wireguard_public_key = $override['wireguard_public_key'];
        }

        return $clone;
    }

    private function ipv4(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            ? null
            : $value;
    }
}
