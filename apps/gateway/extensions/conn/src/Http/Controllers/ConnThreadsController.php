<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Orbit\Extensions\Conn\Data\ConnThreadData;
use Orbit\Extensions\Conn\Domain\ConnServerAccess;
use Orbit\Extensions\Conn\Domain\ConnThreadIndex;
use Orbit\Extensions\Conn\Http\Requests\ListConnThreadsRequest;
use Orbit\Extensions\Conn\Http\Requests\RecordConnThreadRequest;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/** The thread index of container T3 servers (docs/reference/conn.md#thread-index). */
final class ConnThreadsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(ListConnThreadsRequest $request, ConnThreadIndex $index): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node, description: 'Authenticated peer must be a Node.');
        ['environments' => $environments, 'threads' => $threads] = $index->list($caller, $request->projectId(), $request->instanceId());

        return response()->json([
            'data' => $threads,
            'meta' => [
                'request_id' => $request->attributes->getString('orbit.request_id'),
                // Every server the list covers, also one without threads: when the Gateway last read it, and why that read failed.
                'environments' => $environments->map(static fn (ConnEnvironment $environment): array => [
                    'environment_id' => $environment->environment_id,
                    'instance_id' => $environment->instance_id,
                    'power_state' => $environment->instance?->runtime?->power_state->value,
                    'read_at' => $environment->threads_read_at?->toIso8601String(),
                    'read_error' => $environment->threads_read_error,
                ])->values()->all(),
            ],
        ]);
    }

    /** The write-through of a thread that Conn started on a container server; a repeat updates its title. */
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function store(RecordConnThreadRequest $request, ConnEnvironment $environment, ConnServerAccess $access, ConnThreadIndex $index): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node, description: 'Authenticated peer must be a Node.');
        $access->assert($caller, $environment);
        if ($environment->instance_id === null) {
            throw new ResourceOperationException('conn.thread_index_unsupported', 'Only the T3 server of a container Instance has a thread index.', 409);
        }
        ['thread' => $thread, 'created' => $created] = $index->record($environment, $request->threadId(), $request->title());

        return response()->json([
            'data' => ConnThreadData::fromModel($thread)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], $created ? 201 : 200);
    }
}
