<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ListOperationsAction;
use App\Data\Operations\OperationData;
use App\Data\Operations\OperationStepData;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\CancelOperationRequest;
use App\Http\Requests\Operations\ListOperationsRequest;
use App\Http\Requests\Operations\ShowOperationRequest;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reads and cancels operations (docs/reference/operations.md#read-an-operation). Each read is one database query and
 * never waits for the operation.
 */
final class OperationsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(ListOperationsRequest $request, ListOperationsAction $action): JsonResponse
    {
        $consumer = $request->user();

        if (! $consumer instanceof Node) {
            throw new ResourceOperationException('peer.identity_unknown', 'Active WireGuard peer identity required.', 403);
        }

        return $this->respond($request, $action->execute($consumer, $request->filters())
            ->map(static fn (Operation $operation): array => OperationData::fromModel($operation)->toArray())
            ->values()
            ->all());
    }

    #[RequiresNodeAccess(ServingNode::OperationOwning)]
    public function show(ShowOperationRequest $request, Operation $operation): JsonResponse
    {
        return $this->respond($request, self::withSteps($operation, $request->afterSequence()));
    }

    #[RequiresNodeAccess(ServingNode::OperationOwning)]
    public function cancel(CancelOperationRequest $request, Operation $operation, CancelOperationAction $action): JsonResponse
    {
        return $this->respond($request, self::withSteps($action->execute($operation), null));
    }

    /** @return array<string, mixed> */
    public static function withSteps(Operation $operation, ?int $afterSequence): array
    {
        $steps = $operation->steps()
            ->when($afterSequence !== null, static fn ($query) => $query->where('sequence', '>', $afterSequence))
            ->get();

        return [
            ...OperationData::fromModel($operation)->toArray(),
            'steps' => $steps->map(static fn (OperationStep $step): array => OperationStepData::fromModel($step)->toArray())->values()->all(),
        ];
    }

    /** @param array<int|string, mixed> $data */
    private function respond(Request $request, array $data): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
