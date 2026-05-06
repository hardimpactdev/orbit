<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreSchedulerHeartbeatApiRequest;
use App\Models\Node;
use App\Models\SchedulerState;
use Illuminate\Http\JsonResponse;

final readonly class SchedulerHeartbeatController
{
    public function __invoke(StoreSchedulerHeartbeatApiRequest $request): JsonResponse
    {
        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => [],
                ],
            ], 403);
        }

        $state = SchedulerState::query()->updateOrCreate(
            ['node_id' => $caller->id],
            $request->payload(),
        );

        return response()->json([
            'success' => [
                'data' => [
                    'state' => [
                        'node' => $caller->name,
                        'heartbeat_at' => $state->heartbeat_at?->toIso8601String(),
                        'registry_synced_at' => $state->registry_synced_at?->toIso8601String(),
                    ],
                ],
            ],
        ]);
    }
}
