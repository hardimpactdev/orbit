<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\ReadDeploymentSourceAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\DeploymentSourceChecksRequest;
use App\Http\Requests\Instances\DeploymentSourceCommitsRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class InstanceDeploymentSourceController extends Controller
{
    public function head(Request $request, Instance $instance, ReadDeploymentSourceAction $action): JsonResponse
    {
        return response()->json(['data' => $action->head($instance)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function commits(DeploymentSourceCommitsRequest $request, Instance $instance, ReadDeploymentSourceAction $action): JsonResponse
    {
        return response()->json(['data' => $action->commits($instance, $request->base())->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function checks(DeploymentSourceChecksRequest $request, Instance $instance, ReadDeploymentSourceAction $action): JsonResponse
    {
        return response()->json(['data' => $action->checks($instance, $request->commit())->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
