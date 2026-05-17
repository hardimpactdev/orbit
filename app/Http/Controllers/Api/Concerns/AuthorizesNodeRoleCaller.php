<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;

trait AuthorizesNodeRoleCaller
{
    abstract protected function authorizationFailed(string $message, array $meta = []): JsonResponse;

    protected function authorizeNodeRoleCaller(Node $caller, string $verb): ?JsonResponse
    {
        if (app(NodeRoleAssignments::class)->nodeIsGateway($caller)) {
            return null;
        }

        if ($this->gatewayQuery()
            ->whereExists(function (QueryBuilder $query) use ($caller): void {
                $query
                    ->selectRaw('1')
                    ->from('node_access')
                    ->whereColumn('node_access.serving_node_id', 'nodes.id')
                    ->where('node_access.consumer_node_id', $caller->id);
            })
            ->exists()) {
            return null;
        }

        $gateway = $this->gatewayQuery()->orderBy('name')->first();

        if (! $gateway instanceof Node) {
            return $this->authorizationFailed(
                "This caller is not authorized to {$verb}.",
                ['required_node' => null, 'caller_role' => $caller->role],
            );
        }

        return $this->authorizationFailed(
            "This caller is not authorized to {$verb}.",
            ['required_node' => $gateway->name, 'caller_role' => $caller->role],
        );
    }

    private function gatewayQuery(): Builder
    {
        return app(NodeRoleAssignments::class)->activeGatewayNodeQuery();
    }
}
