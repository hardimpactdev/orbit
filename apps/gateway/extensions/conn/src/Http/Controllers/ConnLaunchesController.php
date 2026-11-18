<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Domain\Operations\OperationKinds;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Actions\AdmitConnLaunchAction;
use Orbit\Extensions\Conn\Actions\ControlConnLaunchAction;
use Orbit\Extensions\Conn\Actions\ReadConnLaunchesAction;
use Orbit\Extensions\Conn\Domain\ConnLaunchAccess;
use Orbit\Extensions\Conn\Http\Requests\ControlConnLaunchRequest;
use Orbit\Extensions\Conn\Http\Requests\CreateConnLaunchRequest;

#[RequiresNodeAccess(ServingNode::Collection)]
final class ConnLaunchesController extends Controller
{
    public function index(Request $request, ReadConnLaunchesAction $launches): JsonResponse
    {
        return $this->respond($request, $launches->index($this->caller($request)));
    }

    public function show(Request $request, string $operationId, ReadConnLaunchesAction $launches): JsonResponse
    {
        return $this->respond($request, $launches->show($this->caller($request), ConnLaunchAccess::operationId($operationId)));
    }

    #[StartsOperation(OperationKinds::ConnLaunch, requiresKey: true, secretInputs: ['prompt'], bindCaller: true, keyed: false)]
    public function store(CreateConnLaunchRequest $request, AdmitConnLaunchAction $admit): JsonResponse
    {
        return $this->respond($request, $admit->execute($this->caller($request), $request->payload(), $request->attributes->getString('orbit.request_id')), 202);
    }

    public function cancel(ControlConnLaunchRequest $request, ControlConnLaunchAction $control): JsonResponse
    {
        return $this->respond($request, $control->cancel($this->caller($request), $request->operationId(), $request->attributes->getString('orbit.request_id')), 202);
    }

    public function retry(ControlConnLaunchRequest $request, ControlConnLaunchAction $control): JsonResponse
    {
        return $this->respond($request, $control->retry($this->caller($request), $request->operationId(), $request->target(), $request->attributes->getString('orbit.request_id')), 202);
    }

    private function caller(Request $request): Node
    {
        $caller = $request->user();
        assert($caller instanceof Node, description: 'Authenticated peer must be a Node.');

        return $caller;
    }

    /** @param array<int|string, mixed> $data */
    private function respond(Request $request, array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], $status);
    }
}
