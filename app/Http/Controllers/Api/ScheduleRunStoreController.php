<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreScheduleRunApiRequest;
use App\Models\Node;
use App\Models\ScheduleRun;
use Illuminate\Http\JsonResponse;

final readonly class ScheduleRunStoreController
{
    public function __invoke(StoreScheduleRunApiRequest $request): JsonResponse
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

        $run = ScheduleRun::query()->create([
            ...$request->payload(),
            'node_id' => $caller->id,
        ]);

        return response()->json([
            'success' => [
                'data' => [
                    'run' => $this->serialize($run),
                ],
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ScheduleRun $run): array
    {
        return [
            'id' => $run->id,
            'schedule_key' => $run->schedule_key,
            'node' => $run->node?->name,
            'status' => $run->status,
            'exit_code' => $run->exit_code,
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'output' => [
                'stdout' => $run->stdout ?? '',
                'stderr' => $run->stderr ?? '',
            ],
        ];
    }
}
