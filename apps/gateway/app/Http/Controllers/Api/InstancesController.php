<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\ListInstancesAction;
use App\Actions\Instances\RegisterInstanceAction;
use App\Actions\Instances\RenameInstanceAction;
use App\Actions\Instances\ShowInstanceAction;
use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Instances\StartInstanceRestoreAction;
use App\Actions\Instances\UpdateInstanceAction;
use App\Data\Instances\InstanceData;
use App\Data\Operations\OperationData;
use App\Domain\Shared\ResourceOperationException;
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
        $result = $action->execute($caller, $request->payload(), $request->attributes->getString('orbit.request_id'));
        // A checkout with a private or absent .env is registered at once; otherwise a native run protects .env first.
        if ($result instanceof Instance) {
            $request->attributes->set('orbit.instance_registration', $result);

            return response()->json(['data' => InstanceData::fromModel($result)->toArray(), 'meta' => $this->meta($request)], 201);
        }
        $request->attributes->set('orbit.instance_registration', Instance::query()->findOrFail($result->target_id));

        return response()->json(['data' => OperationData::fromModel($result)->toArray(), 'meta' => $this->meta($request)], 202);
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
            'data' => InstanceData::fromModel($action->execute($instance, $request->branch(), $request->sharedPaths(), array_key_exists('shared_paths', $request->validated())))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** Automatic deployment keeps deploying by itself, so it is an enable pair, not an update flag. Enable also resumes a pause. */
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function enableAutoDeploy(Request $request, Instance $instance, UpdateInstanceAction $action): JsonResponse
    {
        return response()->json(['data' => InstanceData::fromModel($action->execute($instance, null, autoDeploy: true))->toArray(), 'meta' => $this->meta($request)]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function disableAutoDeploy(Request $request, Instance $instance, UpdateInstanceAction $action): JsonResponse
    {
        return response()->json(['data' => InstanceData::fromModel($action->execute($instance, null, autoDeploy: false))->toArray(), 'meta' => $this->meta($request)]);
    }

    /** Records the branch the Node agent reports checked out; the answer is final, with no task to follow. */
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function rename(RenameInstanceRequest $request, Instance $instance, RenameInstanceAction $action): JsonResponse
    {
        return response()->json([
            'data' => InstanceData::fromModel($action->execute($instance, $request->payload())->load(['project', 'node', 'routes.targets']))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    #[StartsOperation('instance.destroy')]
    public function destroy(
        RemoveInstanceRequest $request,
        int $instance,
        StartInstanceDestroyAction $action,
    ): JsonResponse {
        $target = Instance::query()->findOrFail($instance);
        $operation = $action->execute($target, $request->force(),
            $this->caller($request), $request->attributes->getString('orbit.request_id'));

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => $this->meta($request),
        ], 202);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    #[StartsOperation('instance.restore')]
    public function restore(Request $request, Instance $instance, StartInstanceRestoreAction $action): JsonResponse
    {
        if ($request->all() !== []) {
            throw new ResourceOperationException('validation.failed', 'Instance restoration takes no options or body fields.', 422);
        }
        $operation = $action->execute($instance, $this->caller($request), $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
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
