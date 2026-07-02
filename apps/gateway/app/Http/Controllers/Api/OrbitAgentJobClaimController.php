<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use App\Models\OrbitAgentJob;
use App\Services\OrbitAgentJobs\OrbitAgentJobDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class OrbitAgentJobClaimController
{
    public function __invoke(Request $request, OrbitAgentJobDispatcher $jobs): JsonResponse
    {
        /** @var mixed $node */
        $node = $request->user();

        if (! $node instanceof Node) {
            abort(403);
        }

        $job = $jobs->claimNext($node);

        if (! $job instanceof OrbitAgentJob) {
            abort(404);
        }

        return response()->json([
            'job' => $this->jobEnvelope($job),
        ]);
    }

    /**
     * @return array{
     *     id: string,
     *     type: string,
     *     target_node: array{name: string},
     *     payload: array<string, mixed>,
     * }
     */
    private function jobEnvelope(OrbitAgentJob $job): array
    {
        return [
            'id' => $job->id,
            'type' => $job->type,
            'target_node' => [
                'name' => $job->targetNode->name,
            ],
            'payload' => $job->payload,
        ];
    }
}
