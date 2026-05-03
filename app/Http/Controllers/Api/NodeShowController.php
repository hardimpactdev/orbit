<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use Illuminate\Http\JsonResponse;

final readonly class NodeShowController
{
    public function __invoke(string $name): JsonResponse
    {
        $node = Node::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if (! $node instanceof Node) {
            return response()->json([
                'error' => [
                    'code' => 'node.not_found',
                    'message' => "Node '{$name}' not found or not visible.",
                    'meta' => [
                        'name' => $name,
                    ],
                ],
            ], 404);
        }

        return response()->json([
            'success' => [
                'data' => [
                    'node' => [
                        'name' => $node->name,
                        'role' => $node->role,
                        'status' => $node->status,
                        'environment' => $node->role === 'app' ? $node->environment : null,
                        'platform' => $node->platform ?? 'unknown',
                        'addresses' => [
                            'wireguard' => $node->wireguard_address ?? $node->host,
                        ],
                        'agent_ide' => [
                            'adapter' => null,
                            'source' => 'default',
                        ],
                        'grants' => [
                            'consuming_nodes' => $node->consumingNodes()->pluck('name')->all(),
                            'serving_nodes' => $node->servingNodes()->pluck('name')->all(),
                        ],
                    ],
                ],
            ],
        ]);
    }
}
