<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\Nodes\NodeBridges;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

/**
 * Gives a Node its Instance bridge: the first /24 of the fleet range that overlaps no recorded bridge and not the VPN
 * subnet (docs/reference/development-containers.md#nodes). A Node keeps its bridge, so a second call returns it.
 */
final readonly class AllocateInstanceBridgeAction
{
    public const string FleetRange = '10.253.0.0/16';

    private const int BridgePrefix = 24;

    public function __construct(private VpnSettings $vpn) {}

    public function execute(Node $node): string
    {
        return DB::transaction(function () use ($node): string {
            $current = Node::query()->whereKey($node->id)->value('instance_bridge');
            if (is_string($current)) {
                $node->forceFill(['instance_bridge' => $current])->syncOriginalAttribute('instance_bridge');

                return $current;
            }
            $taken = [$this->vpn->subnet(), ...NodeBridges::recorded()];
            $range = Ipv4Subnet::from(self::FleetRange);
            $size = 2 ** (32 - self::BridgePrefix);
            $start = (int) ip2long($range->networkAddress());
            for ($offset = 0; $offset < 2 ** (32 - $range->prefixLength()); $offset += $size) {
                $candidate = long2ip($start + $offset).'/'.self::BridgePrefix;
                if (! NodeBridges::overlapsAny($candidate, $taken)) {
                    Node::query()->whereKey($node->id)->update(['instance_bridge' => $candidate]);

                    $node->forceFill(['instance_bridge' => $candidate])->syncOriginalAttribute('instance_bridge');

                    return $candidate;
                }
            }

            throw new ResourceOperationException('node.instance_bridge_exhausted', 'Every /24 of the Instance bridge range ['.self::FleetRange.'] overlaps a recorded bridge or the VPN subnet.', 409);
        });
    }
}
