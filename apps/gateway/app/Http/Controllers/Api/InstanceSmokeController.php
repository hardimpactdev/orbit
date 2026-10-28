<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\InstanceSmokeRecoveryAction;
use App\Actions\Instances\ReadInstanceSmokeContextAction;
use App\Actions\Instances\ReadInstanceSmokeReceiptAction;
use App\Actions\Instances\StartInstanceSmokeAction;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRequestFingerprint;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\InstanceSmokeReceiptRequest;
use App\Http\Requests\Instances\RecordInstanceSmokeFailureRequest;
use App\Http\Requests\Instances\StartInstanceSmokeRequest;
use App\Http\Requests\Instances\StartInstanceSmokeRollbackRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstanceSmokeController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function context(Request $request, Instance $instance, ReadInstanceSmokeContextAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($instance)->toArray(), 'meta' => ['request_id' => $request
            ->attributes->getString('orbit.request_id')]]);
    }

    #[StartsOperation(OperationKinds::InstanceSmoke, requiresKey: true, bindCaller: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function store(StartInstanceSmokeRequest $request, Instance $instance, StartInstanceSmokeAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);
        $operation = $action->execute($instance, $caller, $request->payload(), $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function receipt(InstanceSmokeReceiptRequest $request, Instance $instance,
        ReadInstanceSmokeReceiptAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);

        return response()->json(['data' => $action->execute($instance, $caller, $request->requestKey())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function failure(RecordInstanceSmokeFailureRequest $request, Instance $instance, InstanceSmokeRecoveryAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);
        $fingerprint = app(OperationRequestFingerprint::class)->make($caller, 'instance:smoke:failure', ['instance' => $instance],
            $request->getContent(), new StartsOperation('instance.smoke_failure', maxJsonBytes: 4096, bindCaller: true));

        return response()->json(['data' => $action->record($instance, $caller, $request->payload(), $request->key(), $fingerprint)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function failureReceipt(InstanceSmokeReceiptRequest $request, Instance $instance, InstanceSmokeRecoveryAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);

        return response()->json(['data' => $action->failureReceipt($instance, $caller, $request->requestKey())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    #[StartsOperation(OperationKinds::InstanceRollback, requiresKey: true, maxJsonBytes: 4096, bindCaller: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function rollback(StartInstanceSmokeRollbackRequest $request, Instance $instance, InstanceSmokeRecoveryAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);
        $operation = $action->start($instance, $caller, $request->payload(), $request->key(), $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function rollbackReceipt(InstanceSmokeReceiptRequest $request, Instance $instance, InstanceSmokeRecoveryAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);

        return response()->json(['data' => $action->rollbackReceipt($instance, $caller, $request->requestKey())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
