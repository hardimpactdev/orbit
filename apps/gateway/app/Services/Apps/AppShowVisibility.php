<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Builder;

final readonly class AppShowVisibility
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $authorizer,
        private WorkspacePlacement $workspacePlacement,
    ) {}

    public function callerIsGateway(Node $caller): bool
    {
        return $this->nodeRoleAssignments->nodeIsGateway($caller);
    }

    /**
     * @return list<AppInstance>
     */
    public function visibleInstances(App $app, Node $caller): array
    {
        $callerIsGateway = $this->callerIsGateway($caller);
        $visibleNodeIds = $callerIsGateway ? [] : $this->visibleAppNodeIds($caller);
        $app->loadMissing('instances');
        $instances = [];

        foreach ($app->instances->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE) as $instance) {
            if (! $instance instanceof AppInstance) {
                continue;
            }

            if (
                ! $callerIsGateway
                && ! in_array($this->instanceNodeId($instance), $visibleNodeIds, strict: true)
            ) {
                continue;
            }

            $instances[] = $instance;
        }

        return $instances;
    }

    public function firstServingNodeName(App $app): ?string
    {
        $app->loadMissing('instances');

        foreach ($app->instances as $instance) {
            $node = $this->instanceNodeName($instance);

            if ($node !== null) {
                return $node;
            }
        }

        return $app->node?->name;
    }

    /**
     * @return list<int>
     */
    private function visibleAppNodeIds(Node $caller): array
    {
        $visibleNodeIds = array_values(array_unique([
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('app-dev'),
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('app-prod'),
        ]));

        /** @var Builder<Node> $query */
        $query = Node::query();
        $query->whereIn('id', $visibleNodeIds);
        $authorizedNodeIds = [];

        foreach ($query->get() as $node) {
            if (! $this->authorizer->allows($caller, $node, 'app:read')) {
                continue;
            }

            $authorizedNodeIds[] = $node->id;
        }

        return $authorizedNodeIds;
    }

    private function instanceNodeId(AppInstance $instance): ?int
    {
        $config = $instance->driver_config;

        return $config instanceof OrbitAppInstanceDriverConfigData ? $config->node_id : null;
    }

    private function instanceNodeName(AppInstance $instance): ?string
    {
        $config = $instance->driver_config;

        if ($config instanceof OrbitAppInstanceDriverConfigData && is_string($config->node) && $config->node !== '') {
            return $config->node;
        }

        return $this->workspacePlacement->nodeForInstance($instance)?->name;
    }
}
