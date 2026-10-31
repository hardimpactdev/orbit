<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Support\ValidatedData;

/** Serving effects only. Hub, Metrics and guest custody remain with the owning parent. */
final readonly class NodeRemovalParticipants
{
    /**
     * @param  array<string,mixed>  $routes  Canonical NativeRouteRemovalPlan capture, when required.
     * @return list<array{node_id:int,peer:bool,routes:bool,residue:bool,dns:bool}>
     */
    public function capture(Node $context, array $routes = [], bool $excludeContext = true): array
    {
        $members = [];
        $dnsIds = PrivateDnsPublicationNodes::query()->pluck('id')->all();
        foreach (Node::query()->with('roles')->whereKeyNot($context->id)->where('status', LifecycleStatus::Active)->orderBy('id')->get() as $node) {
            $peer = app(NodeNetworkParticipantInput::class)->affects($node, $context);
            $residue = NodeConvergeInput::residueRows($node)->exists();
            $dns = in_array($node->id, $dnsIds, true);
            if ($peer || $residue || $dns) {
                $members[$node->id] = ['node_id' => $node->id, 'peer' => $peer, 'routes' => false, 'residue' => $residue, 'dns' => $dns];
            }
        }
        foreach (['routes' => array_column(RouteExecutionProof::objects($routes['nodes'] ?? []), 'node_id'),
            'dns' => ValidatedData::integerList($routes['dns_node_ids'] ?? [])] as $effect => $ids) {
            foreach ($ids as $id) {
                $id = ValidatedData::integer($id);
                if ($excludeContext && $id === $context->id) {
                    continue;
                }
                $members[$id] ??= ['node_id' => $id, 'peer' => false, 'routes' => false, 'residue' => false, 'dns' => false];
                $members[$id][$effect] = true;
            }
        }
        ksort($members, SORT_NUMERIC);

        return array_values($members);
    }
}
