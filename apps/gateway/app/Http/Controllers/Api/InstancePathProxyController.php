<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\RemoveInstancePathProxyAction;
use App\Actions\Instances\SetInstancePathProxyAction;
use App\Data\Instances\InstancePathProxyData;
use App\Domain\Instances\PathProxy;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\SetInstancePathProxyRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::PathProxyInstanceOwning)]
final class InstancePathProxyController extends Controller
{
    public function store(SetInstancePathProxyRequest $request, SetInstancePathProxyAction $action): JsonResponse
    {
        $instance = $request->route('instance');
        assert($instance instanceof Instance);

        return response()->json(['data' => $action->execute($instance, $request->toData())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function show(Request $request): JsonResponse
    {
        $instance = $request->route('instance');
        assert($instance instanceof Instance);

        return response()->json(['data' => InstancePathProxyData::fromModel(PathProxy::stored($instance))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function destroy(Request $request, RemoveInstancePathProxyAction $action): JsonResponse
    {
        $instance = $request->route('instance');
        assert($instance instanceof Instance);

        return response()->json(['data' => $action->execute($instance)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
