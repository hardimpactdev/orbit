<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use App\Models\OrbitAgentJob;
use App\Services\Operations\OperationPayloadRejected;
use App\Services\OrbitAgentJobs\OrbitAgentJobDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class OrbitAgentJobEventController
{
    public function __invoke(
        Request $request,
        OrbitAgentJob $job,
        OrbitAgentJobDispatcher $jobs,
    ): JsonResponse {
        /** @var mixed $node */
        $node = $request->user();

        if (! $node instanceof Node) {
            abort(403);
        }

        if ($job->target_node_id !== $node->id) {
            abort(404);
        }

        /** @var array{event: string, payload?: array<string, mixed>} $validated */
        $validated = $request->validate([
            'event' => ['required', 'string'],
            'payload' => ['sometimes', 'array'],
        ]);

        try {
            $job = $jobs->recordEvent(
                job: $job,
                node: $node,
                event: $validated['event'],
                payload: $validated['payload'] ?? [],
            );
        } catch (InvalidArgumentException|OperationPayloadRejected $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception instanceof OperationPayloadRejected
                        ? $exception->errorCode
                        : 'validation_failed',
                    'message' => $exception->getMessage(),
                    'meta' => $exception instanceof OperationPayloadRejected ? $exception->meta : [],
                ],
            ], 422);
        }

        return response()->json([
            'job' => [
                'id' => $job->id,
                'status' => $job->status,
            ],
        ]);
    }
}
