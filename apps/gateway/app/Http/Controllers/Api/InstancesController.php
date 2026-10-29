<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\DestroyInstanceAction;
use App\Actions\Instances\ListInstancesAction;
use App\Actions\Instances\RegisterInstanceAction;
use App\Actions\Instances\RenameInstanceAction;
use App\Actions\Instances\ShowInstanceAction;
use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Instances\UpdateInstanceAction;
use App\Data\Instances\InstanceData;
use App\Data\Instances\InstanceRemovalData;
use App\Data\Operations\OperationData;
use App\Domain\Processes\ProcessSwampSafety;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Requests\Instances\RegisterInstanceRequest;
use App\Http\Requests\Instances\RemoveInstanceRequest;
use App\Http\Requests\Instances\RenameInstanceRequest;
use App\Http\Requests\Instances\StoreInstanceRequest;
use App\Http\Requests\Instances\UpdateInstanceRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstancesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(Request $request, ListInstancesAction $action): JsonResponse
    {
        $consumer = $request->user();
        assert($consumer instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => $action
                ->handle($consumer, $request->boolean('auto_deploy'))
                ->map(static fn (Instance $row): array => InstanceData::fromModel($row)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceCreation)]
    #[StartsOperation('instance.create')]
    public function store(StoreInstanceRequest $request, StartDevelopmentInstanceCreateAction $action): JsonResponse
    {
        $operation = $action->execute($request->payload(), $this->caller($request), $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[RequiresNodeAccess(ServingNode::Caller)]
    #[StartsOperation('instance.register')]
    public function register(RegisterInstanceRequest $request, RegisterInstanceAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);
        $operation = $action->execute($caller, $request->payload(), $request->attributes->getString('orbit.request_id'));
        $primary = Instance::query()->findOrFail($operation->target_id);
        $request->attributes->set('orbit.instance_registration', $primary);

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function show(Request $request, Instance $instance, ShowInstanceAction $action): JsonResponse
    {
        return response()->json([
            'data' => InstanceData::fromModel($action->handle($instance))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function update(
        UpdateInstanceRequest $request,
        Instance $instance,
        UpdateInstanceAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => InstanceData::fromModel($action->execute($instance, $request->branch(), $request->sharedPaths(), array_key_exists('shared_paths', $request->validated()), $request->autoDeploy()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    #[StartsOperation('instance.rename')]
    public function rename(RenameInstanceRequest $request, Instance $instance, RenameInstanceAction $action): JsonResponse
    {
        $operation = $action->execute($instance, $request->payload(), $this->caller($request), $request->attributes->getString('orbit.request_id'));

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => $this->meta($request),
        ], 202);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    #[StartsOperation('instance.destroy')]
    public function destroy(
        RemoveInstanceRequest $request,
        int $instance,
        StartInstanceDestroyAction $action,
    ): JsonResponse {
        $target = Instance::query()->findOrFail($instance);
        if (app(ProcessSwampSafety::class)->requiresSynchronous($target->node)) {
            return response()->json([
                'data' => InstanceRemovalData::fromModel(app(DestroyInstanceAction::class)->execute($target, $request->force()))->toArray(),
                'meta' => $this->meta($request),
            ]);
        }
        $operation = $action->execute($target, $request->force(),
            $this->caller($request), $request->attributes->getString('orbit.request_id'));

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => $this->meta($request),
        ], 202);
    }

    private function caller(Request $request): ?string
    {
        $caller = RequireActiveWireGuardPeer::activePeer($request);

        return $caller instanceof Node ? 'node:'.$caller->id : null;
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
