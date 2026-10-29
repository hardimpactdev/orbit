<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\StartInstanceStepListAction;
use App\Data\Operations\OperationData;
use App\Domain\Projects\LifecyclePhase;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstanceSetupController extends Controller
{
    #[StartsOperation('instance.run_steps')]
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function store(Request $request, Instance $instance, StartInstanceStepListAction $action): JsonResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->execute($instance, LifecyclePhase::Setup, CallerName::fromRequest($request), $requestId === '' ? null : $requestId);

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $requestId]], 202);
    }
}
