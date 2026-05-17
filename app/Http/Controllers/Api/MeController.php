<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

final readonly class MeController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Node $self */
        $self = $request->user();
        $self->loadMissing('roleAssignments');

        $gateway = Node::query()
            ->with('roleAssignments')
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
            'environment' => $node->role === 'app' ? $node->environment : null,
            'platform' => $node->platform ?? 'unknown',
            'roles' => $node->roleAssignments->map(fn (NodeRoleAssignment $assignment): array => [
                'role' => $assignment->role,
                'status' => $assignment->status,
                'settings' => $this->normalizeRoleSettings($assignment->settings),
            ])->all(),
            'addresses' => [
                'wireguard' => $node->wireguard_address,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function normalizeRoleSettings(mixed $settings): array|stdClass
    {
        if (! is_array($settings) || $settings === []) {
            return (object) [];
        }

        return $settings;
    }
}
