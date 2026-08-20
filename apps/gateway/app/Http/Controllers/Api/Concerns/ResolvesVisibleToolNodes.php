<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Data\Apps\AppSelection;
use App\Enums\Nodes\NodeStatus;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Tools\AgentToolAuthorizer;
use App\Services\Tools\ToolCatalog;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait ResolvesVisibleToolNodes
{
    /**
     * @return list<int>
     */
    private function visibleToolNodeIds(
        Node $caller,
        bool $allowAnyActiveNode = false,
        ?string $requiredPermission = null,
        bool $includeMetricsExporterNodes = false,
    ): array {
        $requiredPermission ??= 'tool:read';

        if ($this->nodeRoleAssignments()->nodeIsGateway($caller)) {
            $query = Node::query()
                ->where('status', NodeStatus::Active->value);

            if (! $allowAnyActiveNode) {
                $query->whereIn('id', $this->visibleManagedToolNodeIds($includeMetricsExporterNodes));
            } else {
                $query->whereNotIn('id', $this->gatewayNodeIds());
            }

            return $query->pluck('id')->all();
        }

        $authorizer = app(NodeAccessAuthorizer::class);

        $query = Node::query()
            ->where('status', NodeStatus::Active->value);

        if (! $allowAnyActiveNode) {
            $query->whereIn('id', $this->visibleManagedToolNodeIds($includeMetricsExporterNodes));
        } else {
            $query->whereNotIn('id', $this->gatewayNodeIds());
        }

        $visibleNodeIds = [];

        foreach ($query->get() as $node) {
            if ($authorizer->allows($caller, $node, $requiredPermission)) {
                $visibleNodeIds[] = $node->id;
            }
        }

        return array_values(array_unique($visibleNodeIds));
    }

    /**
     * @param  list<int>  $visibleNodeIds
     * @return array{node: ?string, app: ?string}|JsonResponse
     */
    private function authorizedToolTarget(
        Request $request,
        Node $caller,
        array $visibleNodeIds,
        bool $allowOnlyVisibleFallback = true,
        bool $allowAnyActiveNode = false,
        ?string $tool = null,
    ): array|JsonResponse {
        return $this->authorizedToolTargetWithUnsupportedPolicy(
            request: $request,
            caller: $caller,
            visibleNodeIds: $visibleNodeIds,
            options: [
                'allow_only_visible_fallback' => $allowOnlyVisibleFallback,
                'allow_any_active_node' => $allowAnyActiveNode,
                'tool' => $tool,
                'unsupported_policy' => 'strict',
            ],
        );
    }

    /**
     * @param  list<int>  $visibleNodeIds
     * @return array{node: ?string, app: ?string}|JsonResponse
     */
    private function authorizedToolRemovalTarget(
        Request $request,
        Node $caller,
        array $visibleNodeIds,
        ?string $tool = null,
    ): array|JsonResponse {
        return $this->authorizedToolTargetWithUnsupportedPolicy(
            request: $request,
            caller: $caller,
            visibleNodeIds: $visibleNodeIds,
            options: [
                'allow_only_visible_fallback' => true,
                'allow_any_active_node' => true,
                'tool' => $tool,
                'unsupported_policy' => 'stale-removal',
            ],
        );
    }

    /**
     * @param  list<int>  $visibleNodeIds
     * @param  array{
     *     allow_only_visible_fallback: bool,
     *     allow_any_active_node: bool,
     *     tool: ?string,
     *     unsupported_policy: string,
     * }  $options
     * @return array{node: ?string, app: ?string}|JsonResponse
     */
    private function authorizedToolTargetWithUnsupportedPolicy(
        Request $request,
        Node $caller,
        array $visibleNodeIds,
        array $options,
    ): array|JsonResponse {
        $allowOnlyVisibleFallback = $options['allow_only_visible_fallback'];
        $allowAnyActiveNode = $options['allow_any_active_node'];
        $tool = $options['tool'];
        $unsupportedPolicy = $options['unsupported_policy'];
        $node = $this->toolTargetString($request, 'node');
        $app = $this->toolTargetString($request, 'instance');
        $nodeFilter = null;

        if ($node !== null) {
            $nodeFilter = $this->resolveNodeFilter($node, $caller, $visibleNodeIds, $allowAnyActiveNode);

            if (! $nodeFilter instanceof Node) {
                return $this->toolTargetFailure($node, 'node', $caller, $visibleNodeIds, $allowAnyActiveNode);
            }

            if ($this->shouldRejectUnsupportedToolNode($tool, $nodeFilter, $unsupportedPolicy)) {
                return $this->toolTargetUnsupportedNode((string) $tool, $nodeFilter);
            }
        }

        if ($app !== null) {
            $appNode = $this->resolveAppNodeFilter($app, $caller, $visibleNodeIds);

            if (! $appNode instanceof Node) {
                return $this->toolTargetFailure($app, 'instance', $caller, $visibleNodeIds);
            }

            if ($nodeFilter instanceof Node && $nodeFilter->id !== $appNode->id) {
                return $this->toolTargetValidationFailed(
                    'instance',
                    $app,
                    "Invalid value for --instance: '{$app}'. Instance is not owned by the selected node.",
                );
            }

            if ($this->shouldRejectUnsupportedToolNode($tool, $appNode, $unsupportedPolicy)) {
                return $this->toolTargetUnsupportedNode((string) $tool, $appNode);
            }

            $nodeFilter = $appNode;
        }

        if ($nodeFilter instanceof Node) {
            return [
                'node' => $node,
                'app' => $app,
            ];
        }

        if ($allowOnlyVisibleFallback && ! $this->nodeRoleAssignments()->nodeIsGateway($caller)) {
            $nodes = Node::query()
                ->whereIn('id', $visibleNodeIds)
                ->whereIn('id', $this->nodeRoleAssignments()->activeToolHostNodeIds())
                ->where('status', NodeStatus::Active->value)
                ->orderBy('name')
                ->limit(2)
                ->get();

            if ($nodes->count() === 1) {
                $fallbackNodeName = $nodes->first()?->name;

                return [
                    'node' => is_string($fallbackNodeName) ? $fallbackNodeName : null,
                    'app' => null,
                ];
            }
        }

        return [
            'node' => null,
            'app' => null,
        ];
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function resolveNodeFilter(
        string $node,
        Node $caller,
        array $visibleNodeIds,
        bool $allowAnyActiveNode = false,
        bool $includeMetricsExporterNodes = false,
    ): ?Node {
        $query = Node::query()
            ->where('name', $node)
            ->where('status', NodeStatus::Active->value)
            ->when(
                ! $this->nodeRoleAssignments()->nodeIsGateway($caller),
                fn (Builder $query) => $query->whereIn('id', $visibleNodeIds),
            );

        if (! $allowAnyActiveNode) {
            $query->whereIn('id', $this->visibleManagedToolNodeIds($includeMetricsExporterNodes));
        } else {
            $query->whereNotIn('id', $this->gatewayNodeIds());
        }

        return $query->first();
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function resolveAppNodeFilter(string $app, Node $caller, array $visibleNodeIds): ?Node
    {
        try {
            $selection = app(AppSelectorResolver::class)->resolve($app);

            if (! $selection instanceof AppSelection) {
                return null;
            }

            $selection = app(AppSelectorResolver::class)->requireInstance($selection);
        } catch (AppSelectionResolutionFailed) {
            return null;
        }

        $instance = $selection->instance;

        if (! $instance instanceof Instance) {
            return null;
        }

        $node = app(WorkspacePlacement::class)->nodeForInstance($instance);

        if (! $node instanceof Node || ! $node->isActive()) {
            return null;
        }

        if (! $this->nodeRoleAssignments()->nodeIsGateway($caller) && ! in_array($node->id, $visibleNodeIds, true)) {
            return null;
        }

        return $node;
    }

    private function toolTargetString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param array{node: ?string, app: ?string} $target */
    private function resolvedToolActivityTarget(array $target): ?Node
    {
        if ($target['node'] !== null) {
            return Node::query()
                ->where('name', $target['node'])
                ->where('status', NodeStatus::Active->value)
                ->first();
        }

        if ($target['app'] === null) {
            return null;
        }

        try {
            $selection = app(AppSelectorResolver::class)->resolve($target['app']);
            $selection = $selection instanceof AppSelection
                ? app(AppSelectorResolver::class)->requireInstance($selection)
                : null;
        } catch (AppSelectionResolutionFailed) {
            return null;
        }

        return $selection?->instance instanceof Instance
            ? app(WorkspacePlacement::class)->nodeForInstance($selection->instance)
            : null;
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function toolTargetFailure(
        string $value,
        string $field,
        Node $caller,
        array $visibleNodeIds,
        bool $allowAnyActiveNode = false,
    ): JsonResponse {
        if ($field === 'node' && $this->gatewayToolTargetExists($value)) {
            return $this->toolTargetValidationFailed(
                field: 'node',
                value: $value,
                message: "Invalid value for --node: '{$value}'. Gateway nodes are not tool targets.",
                meta: ['reason' => 'gateway_not_tool_eligible'],
            );
        }

        if (
            ! $this->nodeRoleAssignments()->nodeIsGateway($caller)
            && $this->toolTargetExists($field, $value, $visibleNodeIds, $allowAnyActiveNode)
        ) {
            return $this->toolTargetAuthorizationFailed(
                "This node is not authorized to manage tools for the selected {$field}.",
                [
                    $field => $value,
                ],
            );
        }

        $expected = $field === 'node'
            ? ($allowAnyActiveNode ? 'Expected a visible node name.' : 'Expected a visible tool node name.')
            : 'Expected a visible instance selector.';

        return $this->toolTargetValidationFailed(
            $field,
            $value,
            "Invalid value for --{$field}: '{$value}'. {$expected}",
        );
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function toolTargetExists(
        string $field,
        string $value,
        array $visibleNodeIds,
        bool $allowAnyActiveNode = false,
    ): bool {
        if ($field === 'node') {
            $query = Node::query()
                ->where('name', $value)
                ->where('status', NodeStatus::Active->value)
                ->whereNotIn('id', $visibleNodeIds);

            if (! $allowAnyActiveNode) {
                $query->whereIn('id', $this->nodeRoleAssignments()->activeToolHostNodeIds());
            } else {
                $query->whereNotIn('id', $this->gatewayNodeIds());
            }

            return $query->exists();
        }

        // Placement is instance-authoritative: resolve the selector to a
        // concrete instance and inspect its serving node, rather than querying
        // the (now logical-only) app for a node relation or domain column.
        try {
            $selection = app(AppSelectorResolver::class)->resolve($value);

            if (! $selection instanceof AppSelection) {
                return false;
            }

            $selection = app(AppSelectorResolver::class)->requireInstance($selection);
        } catch (AppSelectionResolutionFailed) {
            return false;
        }

        $instance = $selection->instance;

        if (! $instance instanceof Instance) {
            return false;
        }

        $node = app(WorkspacePlacement::class)->nodeForInstance($instance);

        if (! $node instanceof Node || ! $node->isActive()) {
            return false;
        }

        return (
            in_array($node->id, $this->nodeRoleAssignments()->activeAppHostNodeIds(), true)
            && ! in_array($node->id, $visibleNodeIds, true)
        );
    }

    /**
     * @return list<int>
     */
    private function visibleManagedToolNodeIds(bool $includeMetricsExporterNodes): array
    {
        $nodeIds = $this->nodeRoleAssignments()->activeToolHostNodeIds();

        if ($includeMetricsExporterNodes) {
            $nodeIds = [
                ...$nodeIds,
                ...$this->nodeRoleAssignments()->activeMetricsExporterNodeIds(),
            ];
        }

        return array_values(array_unique($nodeIds));
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return app(NodeRoleAssignments::class);
    }

    /**
     * @return list<int>
     */
    private function gatewayNodeIds(): array
    {
        return $this
            ->nodeRoleAssignments()
            ->activeGatewayNodeQuery()
            ->pluck('id')
            ->map(fn (mixed $nodeId): int => (int) $nodeId)
            ->all();
    }

    private function gatewayToolTargetExists(string $node): bool
    {
        return $this
            ->nodeRoleAssignments()
            ->activeGatewayNodeQuery()
            ->where('name', $node)
            ->exists();
    }

    private function isAgentSelf(Node $caller, ?string $targetNodeName): bool
    {
        if ($targetNodeName === null) {
            return false;
        }

        if (! $this->nodeRoleAssignments()->nodeHasActiveAgentRole($caller)) {
            return false;
        }

        return $caller->name === $targetNodeName;
    }

    /**
     * Check agent self authorization for tool actions.
     */
    private function authorizeAgentToolAction(
        Node $caller,
        ?string $targetNodeName,
        string $tool,
        string $action,
    ): ?JsonResponse {
        $authorizer = app(AgentToolAuthorizer::class);

        if (! $authorizer->isAgentSelf($caller, $targetNodeName)) {
            return null;
        }

        $result = $authorizer->authorizeAgentSelfAction($caller, $tool, $action);

        if (! $result['authorized']) {
            return $this->toolTargetAuthorizationFailed(
                $result['reason'] ?? 'Agent self is not authorized to perform this action.',
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function toolTargetAuthorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], 403);
    }

    private function toolTargetValidationFailed(
        string $field,
        string $value,
        string $message,
        array $meta = [],
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => [
                    'field' => $field,
                    'value' => $value,
                    ...$meta,
                ],
            ],
        ], 422);
    }

    private function toolTargetUnsupportedNode(string $tool, Node $node): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'tool.unsupported_on_node',
                'message' => "Tool '{$tool}' does not support node '{$node->name}' platform.",
                'meta' => [
                    'tool' => $tool,
                    'node' => $node->name,
                    'platform' => $node->platform,
                    'supported_operating_systems' => app(ToolCatalog::class)->supportedOperatingSystems($tool),
                ],
            ],
        ], 422);
    }

    private function shouldRejectUnsupportedToolNode(?string $tool, Node $node, string $unsupportedPolicy): bool
    {
        if ($tool === null) {
            return false;
        }

        $catalog = app(ToolCatalog::class);

        if (! $catalog->supports($tool)) {
            return false;
        }

        if ($catalog->supportsNode($tool, $node)) {
            return false;
        }

        if ($unsupportedPolicy === 'stale-removal' && $this->hasStaleToolIntent($tool, $node)) {
            return false;
        }

        return true;
    }

    private function hasStaleToolIntent(string $tool, Node $node): bool
    {
        if (NodeTool::query()->where('node_id', $node->id)->where('name', $tool)->exists()) {
            return true;
        }

        return ProxyRoute::query()
            ->where('node_id', $node->id)
            ->where('owner_type', 'tool')
            ->get()
            ->contains(static function (ProxyRoute $route) use ($tool): bool {
                $config = is_array($route->config) ? $route->config : [];

                return ($config['owner_name'] ?? null) === $tool;
            });
    }
}
