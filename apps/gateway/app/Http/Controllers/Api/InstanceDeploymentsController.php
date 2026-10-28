<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\ListInstanceDeploymentsAction;
use App\Actions\Instances\ReadAutomaticDeployReceiptAction;
use App\Actions\Instances\ResolveAutomaticDeployAdmissionAction;
use App\Actions\Instances\StartInstanceDeployAction;
use App\Data\Instances\InstanceDeploymentData;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationKinds;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\AutomaticDeployInstanceRequest;
use App\Http\Requests\Instances\AutomaticDeployReceiptRequest;
use App\Http\Requests\Instances\DeployInstanceRequest;
use App\Http\Requests\Instances\ResolveAutomaticDeployAdmissionRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstanceDeploymentsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function index(
        Request $request,
        Instance $instance,
        ListInstanceDeploymentsAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action->execute($instance)
                ->map(static fn (InstanceDeployment $deployment): array => InstanceDeploymentData::fromModel($deployment)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::DeploymentOwning)]
    public function show(Request $request, InstanceDeployment $deployment): JsonResponse
    {
        return response()->json([
            'data' => [
                ...InstanceDeploymentData::fromModel($deployment)->toArray(),
                'events' => $deployment->events ?? [],
            ],
            'meta' => $this->meta($request),
        ]);
    }

    /** Starts an `instance.deploy` operation and answers 202 with it; the deploy runs on the Gateway queue. */
    #[StartsOperation(OperationKinds::InstanceDeploy)]
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function store(
        DeployInstanceRequest $request,
        Instance $instance,
        StartInstanceDeployAction $action,
    ): JsonResponse {
        $requestId = $request->attributes->getString('orbit.request_id');
        $commit = $request->commit();
        $operation = $commit === null
            ? $action->execute($instance, CallerName::fromRequest($request), $requestId === '' ? null : $requestId)
            : $action->pinned($instance, CallerName::fromRequest($request), $requestId === '' ? null : $requestId, $commit);

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => $this->meta($request),
        ], 202);
    }

    /** Starts a pinned automatic deploy using the caller's existing fleet authority. */
    #[StartsOperation(OperationKinds::InstanceDeploy, requiresKey: true, bindCaller: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function automatic(AutomaticDeployInstanceRequest $request, Instance $instance, StartInstanceDeployAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->automatic($instance, $caller, $requestId === '' ? null : $requestId, $request->commit());

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => $this->meta($request),
        ], 202);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function receipt(AutomaticDeployReceiptRequest $request, Instance $instance, ReadAutomaticDeployReceiptAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);

        return response()->json(['data' => $action->execute($instance, $caller, $request->requestKey(), $request->commit())->toArray(), 'meta' => $this->meta($request)]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function resolve(ResolveAutomaticDeployAdmissionRequest $request, Instance $instance, ResolveAutomaticDeployAdmissionAction $action): JsonResponse
    {
        $caller = $request->originalCaller();
        request()->attributes->set('orbit.automatic_deploy_resolution', ['caller_node_id' => $caller->id]);

        return response()->json([
            'data' => $action->execute($instance, $caller, $request->requestKey(), $request->commit(), $request->attributes->getString('orbit.request_id'))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
