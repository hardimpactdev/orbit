<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Routes\AdmitRouteOperationAction;
use App\Actions\Routes\ListRoutesAction;
use App\Actions\Routes\ShowRouteAction;
use App\Data\Operations\OperationData;
use App\Data\Routes\RouteData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\Routes\EmptyRouteRequest;
use App\Http\Requests\Routes\RemoveRouteRequest;
use App\Http\Requests\Routes\SetRouteTargetRequest;
use App\Http\Requests\Routes\StoreRouteRequest;
use App\Http\Requests\Routes\UpdateRouteRequest;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RoutesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(Request $request, ListRoutesAction $action): JsonResponse
    {
        $consumer = $request->user();
        assert($consumer instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => $action
                ->handle($consumer)
                ->map(static fn (Route $route): array => RouteData::fromModel($route)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::RouteOwning)]
    #[StartsOperation('route.create')]
    public function store(StoreRouteRequest $request, AdmitRouteOperationAction $action): JsonResponse
    {
        $caller = $request->user();
        $operation = $action->create($request->payload(), $caller instanceof Node ? $caller : null, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[RequiresNodeAccess(ServingNode::RouteOwning)]
    public function show(Request $request, Route $route, ShowRouteAction $action): JsonResponse
    {
        return response()->json([
            'data' => RouteData::fromModel($action->handle($route))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::RouteOwning)]
    #[StartsOperation('route.update')]
    public function update(UpdateRouteRequest $request, int $route, AdmitRouteOperationAction $action): JsonResponse
    {
        $caller = $request->user();
        $operation = $action->update(Route::query()->findOrFail($route), $request->payload(), $caller instanceof Node ? $caller : null, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[RequiresNodeAccess(ServingNode::RouteOwning)]
    #[StartsOperation('route.target_set')]
    public function setTarget(SetRouteTargetRequest $request, int $route, AdmitRouteOperationAction $action): JsonResponse
    {
        $caller = $request->user();
        $operation = $request->isTargetSet()
            ? $action->setTargets(Route::query()->findOrFail($route), $request->payload(), $caller instanceof Node ? $caller : null, $request->attributes->getString('orbit.request_id'))
            : $action->setTarget(Route::query()->findOrFail($route), $request->instanceId(), $caller instanceof Node ? $caller : null, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[RequiresNodeAccess(ServingNode::RouteOwning)]
    #[StartsOperation('route.target_unset')]
    public function clearTarget(EmptyRouteRequest $request, int $route, AdmitRouteOperationAction $action): JsonResponse
    {
        $caller = $request->user();
        $operation = $action->clearTarget(Route::query()->findOrFail($route), $caller instanceof Node ? $caller : null, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[RequiresNodeAccess(ServingNode::RouteOwning)]
    #[StartsOperation('route.destroy')]
    public function destroy(RemoveRouteRequest $request, int $route, AdmitRouteOperationAction $action): JsonResponse
    {
        $caller = $request->user();
        $operation = $action->destroy(Route::query()->findOrFail($route), $request->offline(), $caller instanceof Node ? $caller : null, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
