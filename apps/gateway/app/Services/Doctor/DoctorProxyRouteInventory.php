<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorTargetScope;
use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class DoctorProxyRouteInventory
{
    private const array WORKSPACE_OWNER_TYPES = ['workspace', Workspace::class];

    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    /**
     * @return Collection<int, ProxyRoute>
     */
    public function forScope(Node $node, DoctorTargetScope $scope): Collection
    {
        $query = ProxyRoute::query()
            ->with(['node', 'app', 'workspace'])
            ->where('node_id', $node->id);

        if ($this->nodeExcludesWorkspaces($node)) {
            $query
                ->whereNull('workspace_id')
                ->whereNotIn('owner_type', self::WORKSPACE_OWNER_TYPES)
                ->where('kind', '!=', 'workspace');
        }

        if ($scope->app !== null) {
            $query->whereHas('app', static fn (Builder $appQuery): Builder => $appQuery->where('name', $scope->app));
        }

        if ($scope->workspace !== null) {
            $query->whereHas(
                'workspace',
                static fn (Builder $workspaceQuery): Builder => $workspaceQuery->where('name', $scope->workspace),
            );
        }

        /** @var Collection<int, ProxyRoute> */
        return $query->orderBy('id')->get()->values();
    }

    /**
     * @return list<string>
     * @mago-expect lint:inline-variable-return
     */
    public function excludedWorkspaceDomains(Node $node): array
    {
        if (! $this->nodeExcludesWorkspaces($node)) {
            return [];
        }

        /** @var list<string> $domains */
        $domains = ProxyRoute::query()
            ->where('node_id', $node->id)
            ->where(static function (Builder $query): void {
                $query
                    ->whereNotNull('workspace_id')
                    ->orWhereIn('owner_type', self::WORKSPACE_OWNER_TYPES)
                    ->orWhere('kind', 'workspace');
            })
            ->pluck('domain')
            ->all();

        return $domains;
    }

    public function nodeExcludesWorkspaces(Node $node): bool
    {
        return $this->nodeRoleAssignments->nodeHasActiveRole($node, NodeRoleName::AppProduction->value);
    }

    public function routeIsWorkspaceOwned(ProxyRoute $route): bool
    {
        return (
            $route->workspace_id !== null
            || in_array($route->owner_type, self::WORKSPACE_OWNER_TYPES, strict: true)
            || $route->kind === 'workspace'
        );
    }
}
