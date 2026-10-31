<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Fresh whole-workload data for an authenticated admitted removal participant. */
final readonly class NativeRouteRemovalPublicInput
{
    /** @return array<string,mixed> */
    public function forNode(Operation $operation, int $nodeId): array
    {
        $snapshot = ValidatedData::object($operation->input['route_removal']);
        $member = array_find(RouteExecutionProof::objects($snapshot['nodes']), static fn (array $node): bool => $node['node_id'] === $nodeId);
        $workloads = [];
        if (is_array($member)) {
            $node = Node::query()->findOrFail($nodeId);
            foreach (array_values(array_unique([...ValidatedData::integerList($member['production_instance_ids']), ...ValidatedData::integerList($member['url_instance_ids'])])) as $instanceId) {
                $instance = Instance::query()->with(['node', 'project'])->findOrFail($instanceId);
                if ($instance->node_id !== $nodeId) {
                    throw new ResourceOperationException('route.workload_moved', 'The workload moved from its admitted Route participant.', 409);
                }
                [$runtime] = $instance->placedOnAppProd() ? app(InstanceDeploymentIntent::class)->runtimeIntent($instance, null) : [null];
                $workloads[] = ['instance_id' => $instance->id, 'node_id' => $nodeId, 'production' => $instance->placedOnAppProd(),
                    'runtime' => $runtime, 'profile' => $runtime === null || ! is_string($instance->selected_php_version) ? null : app(RemotePhpPackageManager::class)->runtimeInput($node, $instance->selected_php_version),
                    'packages' => $runtime === null || ! is_string($instance->selected_php_version) ? null : app(RemotePhpPackageManager::class)->packagesOnlyInputForAppProd($node, [$instance->selected_php_version]),
                    'served_web_roots' => null, 'lan' => null];
            }
        }

        return ['route_removal' => $snapshot, 'workloads' => $workloads,
            'ingress_firewall' => is_array($member) && $member['ingress_firewall']
                ? app(NodeRoleFirewallInput::class)->converge(Node::query()->findOrFail($nodeId), RoleName::Ingress) : null];
    }
}
