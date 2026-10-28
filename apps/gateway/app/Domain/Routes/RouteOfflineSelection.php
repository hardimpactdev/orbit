<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Support\ValidatedData;

/** Read-only selection before effects. A reachable Node keeps every cleanup child. */
final readonly class RouteOfflineSelection
{
    public function __construct(private NodeReachabilityProbe $reachability) {}

    /** @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function apply(array $plan): array
    {
        $stages = ValidatedData::object($plan['stages']);
        $steps = [];
        foreach ($stages as $children) {
            foreach (RouteExecutionProof::objects($children) as $child) {
                $step = match ($child['kind']) {
                    'route.caddy_converge' => 'caddy', 'certificate.remove' => 'certificates',
                    'instance.sync_environment', 'route.shared_php_withdraw', 'instance.prepare_php_runtime' => 'php',
                    'route.lan_remove', 'route.public_firewall' => 'firewall', default => null,
                };
                if ($step !== null) {
                    $nodeId = ValidatedData::integer($child['node_id']);
                    $steps[$nodeId][] = $step;
                }
            }
        }
        $retained = [];
        foreach ($steps as $nodeId => $nodeSteps) {
            $node = Node::query()->findOrFail($nodeId);
            if ($node->status !== LifecycleStatus::Active || $this->reachability->degradation($node) === ExporterDegradationReason::Unreachable) {
                $retained[$nodeId] = ['node_id' => $nodeId, 'node' => $node->name, 'steps' => array_values(array_unique($nodeSteps))];
            }
        }
        foreach ($stages as $stage => $children) {
            $stages[$stage] = array_values(array_filter(RouteExecutionProof::objects($children), static fn (array $child): bool => $child['kind'] === 'route.private_dns_publish'
                || ! isset($retained[ValidatedData::integer($child['node_id'])])));
        }
        $plan['stages'] = $stages;
        $plan['retained_on_nodes'] = array_values($retained);

        return $plan;
    }
}
