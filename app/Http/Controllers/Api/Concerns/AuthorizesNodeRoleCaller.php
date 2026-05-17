<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

trait AuthorizesNodeRoleCaller
{
    abstract protected function authorizationFailed(string $message, array $meta = []): JsonResponse;

    protected function authorizeNodeRoleCaller(Node $caller, string $verb): ?JsonResponse
    {
        if ($caller->role === 'gateway') {
            return null;
        }

        $gateway = Node::query()
            ->where('role', 'gateway')
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
