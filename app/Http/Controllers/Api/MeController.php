<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class MeController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Node $self */
        $self = $request->user();

        $gateway = Node::query()
            ->where('is_local', true)
            ->where('role', 'gateway')
            ->first();

        return response()->json([
            'success' => [
                'data' => [
                    'self' => $this->serialize($self),
                    'gateway' => $gateway instanceof Node ? $this->serialize($gateway) : null,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Node $node): array
    {
        return [
            'name' => $node->name,
            'role' => $node->role,
            'status' => $node->status ?? 'active',
            'platform' => $node->platform ?? 'unknown',
            'addresses' => [
                'wireguard' => $node->wireguard_address,
            ],
        ];
    }
}
