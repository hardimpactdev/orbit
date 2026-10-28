<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\ReadInstanceSmokeContextAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstanceSmokeController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function context(Request $request, Instance $instance, ReadInstanceSmokeContextAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($instance)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
