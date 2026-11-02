<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\StartInstanceEnvironmentImportAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\ImportInstanceEnvironmentRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;

final class InstanceEnvironmentImportsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::EnvironmentInstanceOwning)]
    #[StartsOperation('instance.env_import')]
    public function store(
        ImportInstanceEnvironmentRequest $request,
        StartInstanceEnvironmentImportAction $action,
    ): JsonResponse {
        $instance = $request->route('instance');
        assert($instance instanceof Instance);

        $operation = $action->execute($instance, $request->shouldReplace(), requestId: $request->attributes->getString('orbit.request_id'));

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], 202);
    }
}
