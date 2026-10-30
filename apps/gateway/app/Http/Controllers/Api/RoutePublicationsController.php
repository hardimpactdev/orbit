<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Routes\DirectRoutePublication;
use App\Domain\Routes\RoutePublicationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Routes\RoutePublicationRequest;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class RoutePublicationsController extends Controller
{
    public function show(RoutePublicationRequest $request, Operation $operation, DirectRoutePublication $publication): JsonResponse
    {
        $publication->assertOperation($operation);

        return $this->respond($request, $this->operation($operation));
    }

    public function publish(RoutePublicationRequest $request, Operation $operation, DirectRoutePublication $publication, OperationExecutionContract $contract): JsonResponse
    {
        $publication->assertOperation($operation);
        if ($operation->status->terminal()) {
            throw new ResourceOperationException('operation.finished', 'The publication Operation has ended.', 409);
        }

        return $this->respond($request, $contract->result($operation, $publication->publish($operation)));
    }

    public function result(RoutePublicationRequest $request, Operation $operation, RoutePublicationProjection $projection): JsonResponse
    {
        return $this->respond($request, $this->operation($projection->apply($operation, $request->validated())));
    }

    /** @return array<string, mixed> */
    private function operation(Operation $operation): array
    {
        return ['id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id,
            'status' => $operation->status->value, 'result' => $operation->result, 'cancel_requested' => $operation->cancel_requested_at !== null];
    }

    /** @param array<string, mixed> $data */
    private function respond(RoutePublicationRequest $request, array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
