<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\StartInstanceEnvironmentSyncAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\SynchronizeInstanceEnvironmentRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;

final class InstanceEnvironmentSynchronizationsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::EnvironmentInstanceOwning)]
    #[StartsOperation('instance.sync_environment')]
    public function store(
        SynchronizeInstanceEnvironmentRequest $request,
        StartInstanceEnvironmentSyncAction $action,
    ): JsonResponse {
        $instance = $request->route('instance');
        assert($instance instanceof Instance);

        $operation = $action->execute($instance, requestId: $request->attributes->getString('orbit.request_id'));

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], 202);
    }
}
