<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\StartDependencyBatchMergeAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\Tasks\DependencyBatchMergeRequest;
use App\Models\Task;
use Illuminate\Http\JsonResponse;

final class DependencyBatchMergeController extends Controller
{
    #[StartsOperation('tasks.dependency_merge', requiresKey: true, bindCaller: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function store(DependencyBatchMergeRequest $request, Task $group, StartDependencyBatchMergeAction $action): JsonResponse
    {
        $operation = $action->execute($group, $request->integer('publish_operation_id'), $request->string('head_sha')->toString(),
            CallerName::fromRequest($request), $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }
}
