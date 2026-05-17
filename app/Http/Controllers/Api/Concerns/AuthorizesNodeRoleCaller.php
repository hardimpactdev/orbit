<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

trait AuthorizesNodeRoleCaller
{
    abstract protected function authorizationFailed(string $message, array $meta = []): JsonResponse;

    protected function authorizeNodeRoleCaller(Node $caller, string $verb): ?JsonResponse
    {
        if (app(NodeRoleAssignments::class)->nodeIsGateway($caller)) {
            return null;
        }

        $gatewayNodeIds = app(NodeRoleAssignments::class)->activeNodeIdsForRole('gateway');

        $gateway = Node::query()
            ->where(function (Builder $query) use ($gatewayNodeIds): void {
                $query
                    ->where('role', 'gateway')
                    ->orWhereIn('id', $gatewayNodeIds);
            })
            ->where('status', 'active')
            ->orderBy('name')
            ->first();

        if (! $gateway instanceof Node) {
            return $this->authorizationFailed(
                "This caller is not authorized to {$verb}.",
                ['required_node' => null, 'caller_role' => $caller->role],
            );
        }

        $hasGatewayAccess = DB::table('node_access')
            ->where('consumer_node_id', $caller->id)
            ->where('serving_node_id', $gateway->id)
            ->exists();

        if ($hasGatewayAccess) {
            return null;
        }

        return $this->authorizationFailed(
            "This caller is not authorized to {$verb}.",
            ['required_node' => $gateway->name, 'caller_role' => $caller->role],
        );
    }
}
