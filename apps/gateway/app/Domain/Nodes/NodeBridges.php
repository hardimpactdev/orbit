<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\WireGuard\Ipv4Subnet;
use App\Models\InstanceRuntime;
use App\Models\Node;

/**
 * The bridge networks the fleet records: each Node's Instance bridge and each Node's CI bridge `orbitci0` as its ci-runner converge read it. No Instance bridge overlaps another of them
 * (docs/reference/development-containers.md#nodes).
 */
final readonly class NodeBridges
{
    /** The step of the role converge that prints the CI bridge's address. */
    public const string CiBridgeStep = 'Read the CI bridge';

    /**
     * Every recorded bridge: each Node's Instance bridge and CI bridge.
     *
     * @return list<string>
     */
    public static function recorded(): array
    {
        $bridges = [];
        foreach (Node::query()->get(['instance_bridge', 'ci_bridge']) as $node) {
            $bridges = [...$bridges, ...array_values(array_filter([$node->instance_bridge, $node->ci_bridge], is_string(...)))];
        }

        return $bridges;
    }

    /**
     * Records the Node's CI bridge from the address that its ci-runner converge read (`incus network get orbitci0 ipv4.address`, such as
     * `10.253.7.1/24`), as the subnet that the address lies in. A Node's Instance bridge that overlaps it and that no container uses
     * yet goes, so the next admission allocates around it; one in use stays, and its converge keeps refusing the clash
     * (docs/reference/development-containers.md#nodes). An empty or unreadable address records nothing.
     */
    public static function recordCiBridge(int $nodeId, string $address): void
    {
        [$host, $prefix] = array_pad(explode('/', trim($address), 2), 2, '');
        $long = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? false : ip2long($host);
        $length = filter_var($prefix, FILTER_VALIDATE_INT, ['options' => ['min_range' => 8, 'max_range' => 30]]);
        if ($long === false || ! is_int($length)) {
            return;
        }
        $bridge = long2ip($long & ((-1 << (32 - $length)) & 0xFFFF_FFFF)).'/'.$length;
        Node::query()->whereKey($nodeId)->update(['ci_bridge' => $bridge]);
        $instance = Node::query()->whereKey($nodeId)->value('instance_bridge');
        if (is_string($instance) && self::overlap($instance, $bridge)) {
            self::releaseUnused($nodeId);
        }
    }

    /** Drops the Node's Instance bridge when no container uses it; one in use stays. */
    public static function releaseUnused(int $nodeId): void
    {
        if (Node::query()->whereKey($nodeId)->value('instance_bridge') !== null && ! InstanceRuntime::query()->where('node_id', $nodeId)->exists()) {
            Node::query()->whereKey($nodeId)->update(['instance_bridge' => null]);
        }
    }

    /** @param  list<string>  $bridges */
    public static function overlapsAny(string $cidr, array $bridges): bool
    {
        return array_any($bridges, static fn (string $bridge): bool => self::overlap($cidr, $bridge));
    }

    /** True when two bridge networks share an address. */
    public static function overlap(string $cidr, string $other): bool
    {
        [$a, $b] = [Ipv4Subnet::from($cidr), Ipv4Subnet::from($other)];

        return $a->contains($b->networkAddress()) || $b->contains($a->networkAddress());
    }
}
