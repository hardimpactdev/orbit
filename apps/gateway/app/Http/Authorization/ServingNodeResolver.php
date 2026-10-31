<?php

declare(strict_types=1);

namespace App\Http\Authorization;

use App\Domain\Nodes\RoleName;
use App\Domain\Routes\PublicProcessRouteIngress;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\Tool;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

final readonly class ServingNodeResolver
{
    /** @return list<Node> */
    public function resolve(Request $request, ServingNode $scope): array
    {
        return match ($scope) {
            ServingNode::Gateway => $this->gateway(),
            ServingNode::Target => $this->target($request),
            ServingNode::ProjectOwning => $this->projectOwning($request),
            ServingNode::InstanceOwning => $this->instanceOwning($request),
            ServingNode::InstanceCreation => $this->instanceCreation($request),
            ServingNode::DeploymentOwning => $this->deploymentOwning($request),
            ServingNode::OperationOwning => $this->operationOwning($request),
            ServingNode::CandidateClone => $this->candidateClone($request),
            ServingNode::InstanceTransfer => $this->instanceTransfer($request),
            ServingNode::EnvironmentInstanceOwning => $this->environmentInstanceOwning($request),
            ServingNode::ProcessOwning => $this->processOwning($request),
            ServingNode::ScheduleOwning => $this->scheduleOwning($request),
            ServingNode::ScheduleHost => $this->scheduleHost($request),
            ServingNode::InstanceHost => $this->instanceHost($request),
            ServingNode::ToolOwning => $this->toolOwning($request),
            ServingNode::ClusterOwning => $this->clusterOwning($request),
            ServingNode::RouteOwning => $this->routeOwning($request),
            ServingNode::TaskGroupOwning => $this->taskGroupOwning($request),
            ServingNode::RoleMutation => $this->roleMutation($request),
            ServingNode::Collection => [],
            ServingNode::Caller => $this->caller($request),
        };
    }

    /**
     * ADR 0124: a Task group with an Instance is served by that Instance's Node, so a Node with access to itself
     * can manage the groups whose workspace it holds. A group without an Instance is served by the Gateway.
     *
     * @return list<Node>
     */
    private function taskGroupOwning(Request $request): array
    {
        $group = $request->route('group');

        if (! $group instanceof Task) {
            $id = $this->positiveInteger($group);
            $group = $id === null ? null : Task::topLevel()->find($id);
        }

        $instance = $group?->taskable;

        return $instance instanceof Instance ? [Node::query()->findOrFail($instance->node_id)] : $this->gateway();
    }

    /** @return list<Node> */
    private function caller(Request $request): array
    {
        $caller = $request->user();

        return $caller instanceof Node ? [$caller] : [];
    }

    /** @return list<Node> */
    private function gateway(): array
    {
        $gateways = Node::query()
            ->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static function ($query): void {
                $query
                    ->where('role', RoleName::Gateway)
                    ->where('status', LifecycleStatus::Active);
            })
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($gateways->count() !== 1) {
            throw new ActiveGatewayMissing('Exactly one active Gateway node is required.');
        }

        return [$gateways->sole()];
    }

    /** @return list<Node> */
    private function target(Request $request): array
    {
        foreach (['node', 'servingNode'] as $parameter) {
            $node = $request->route($parameter);

            if ($node instanceof Node) {
                return [$node];
            }
        }

        return [];
    }

    /** @return list<Node> */
    private function projectOwning(Request $request): array
    {
        $project = $request->route('project');

        if (! $project instanceof Project) {
            $projectId = $this->positiveInteger($request->input('project_id'));

            if ($projectId === null) {
                return [];
            }

            $project = Project::query()->findOrFail($projectId);
        }

        $nodes = Node::query()
            ->where(function ($query) use ($project): void {
                $query->whereIn('id', $project->instances()->select('node_id'));
            })
            ->orderBy('id')
            ->get()
            ->all();

        if ($nodes !== []) {
            return array_values($nodes);
        }

        return $this->gateway();
    }

    /** @return list<Node> */
    private function instanceOwning(Request $request): array
    {
        $instance = $request->route('instance');

        if ($instance instanceof Instance) {
            return [Node::query()->findOrFail($instance->node_id)];
        }

        if ($request->route()?->getName() === 'instance:destroy' && ($id = $this->positiveInteger($instance)) !== null) {
            $record = Instance::query()->find($id);
            if ($record !== null) {
                return [Node::query()->findOrFail($record->node_id)];
            }
            $key = $request->header('Idempotency-Key');
            $receipt = is_string($key) && $key !== ''
                ? Operation::query()->whereIn('kind', ['instance.destroy', 'instance.destroy_development'])->where('idempotency_key', $key)
                    ->where('target_type', 'instance')->where('target_id', $id)->first() : null;
            if ($receipt?->node_id !== null && ($receipt->input['instance_id'] ?? null) === $id) {
                return [Node::query()->findOrFail($receipt->node_id)];
            }
            throw (new ModelNotFoundException)->setModel(Instance::class, [$id]);
        }

        $nodeId = $this->positiveInteger($request->input('node_id'));

        if ($nodeId === null) {
            return [];
        }

        return [Node::query()->findOrFail($nodeId)];
    }

    /**
     * A create that names a Database server also creates a database on it, which `database:create`
     * allows only with access to the Gateway. So the caller needs access to both.
     *
     * @return list<Node>
     */
    private function instanceCreation(Request $request): array
    {
        $nodes = $this->instanceOwning($request);

        if (! $request->has('database_server')) {
            return $nodes;
        }

        $gateway = $this->gateway()[0];

        foreach ($nodes as $node) {
            if ($node->is($gateway)) {
                return $nodes;
            }
        }

        return [...$nodes, $gateway];
    }

    /** @return list<Node> */
    private function deploymentOwning(Request $request): array
    {
        $deployment = $request->route('deployment');

        if (! $deployment instanceof InstanceDeployment) {
            return [];
        }

        return [Node::query()->findOrFail($deployment->instance->node_id)];
    }

    /**
     * An operation is served by its target's Node, recorded when it started. An operation whose Node was removed is
     * served by the Gateway.
     *
     * @return list<Node>
     */
    private function operationOwning(Request $request): array
    {
        $operation = $request->route('operation');

        if (! $operation instanceof Operation) {
            return [];
        }

        $node = $operation->node_id === null ? null : Node::query()->find($operation->node_id);

        return $node instanceof Node ? [$node] : $this->gateway();
    }

    /** @return list<Node> */
    private function candidateClone(Request $request): array
    {
        $candidate = $request->route('candidate');

        if (! $candidate instanceof Instance) {
            return [];
        }

        $candidateNode = Node::query()->findOrFail($candidate->node_id);
        $destinationNodeId = $this->positiveInteger($request->input('node_id'));

        if ($destinationNodeId === null) {
            return [$candidateNode];
        }

        $destinationNode = Node::query()->findOrFail($destinationNodeId);

        if ($candidateNode->is($destinationNode)) {
            return [$candidateNode];
        }

        return [$candidateNode, $destinationNode];
    }

    /** @return list<Node> */
    private function instanceTransfer(Request $request): array
    {
        $instance = $request->route('instance');

        if (! $instance instanceof Instance) {
            return [];
        }

        $sourceNode = Node::query()->findOrFail($instance->node_id);
        $destinationNodeId = $this->positiveInteger($request->input('node_id'));

        if ($destinationNodeId === null) {
            return [$sourceNode];
        }

        $destinationNode = Node::query()->findOrFail($destinationNodeId);

        if ($sourceNode->is($destinationNode)) {
            return [$sourceNode];
        }

        return [$sourceNode, $destinationNode];
    }

    /** @return list<Node> */
    private function environmentInstanceOwning(Request $request): array
    {
        $target = $request->route('instance');

        if ($target instanceof Instance) {
            return [Node::query()->findOrFail($target->node_id)];
        }

        if (! is_string($target) || $target === '') {
            return [];
        }

        $numeric = $this->positiveInteger($target);

        if ($numeric !== null) {
            $instances = Instance::query()->whereKey($numeric)->limit(2)->get();
        } else {
            $instances = Instance::query()
                ->whereHas('routes', static fn ($query) => $query->where('domain', $target))
                ->orderBy('id')
                ->limit(2)
                ->get();
        }

        if ($instances->count() > 1) {
            throw new ResourceOperationException(
                errorCode: 'env.target_ambiguous',
                message: 'The environment target matches multiple Instances.',
                status: 409,
            );
        }

        if ($instances->isEmpty()) {
            throw new ModelNotFoundException()->setModel(Instance::class, [$target]);
        }

        $instance = $instances->sole();
        $request->route()?->setParameter('instance', $instance);

        return [Node::query()->findOrFail($instance->node_id)];
    }

    /**
     * @return list<Node>
     */
    private function processOwning(Request $request): array
    {
        $process = $request->route('process');
        if (is_string($process)) {
            $record = Process::query()->find($process);
            if ($record instanceof Process) {
                $process = $record;
            } elseif ($request->route()?->getName() === 'process:destroy') {
                $receipt = Operation::query()->where('kind', 'process.destroy')->where('idempotency_key', $request->header('Idempotency-Key'))->first();
                $recordedId = $receipt?->input['process_id'] ?? null;
                $caller = $this->caller($request)[0] ?? null;
                if ($receipt !== null && $caller instanceof Node && $receipt->callerNodeId() === $caller->id
                    && is_int($recordedId) && (string) $recordedId === $process) {
                    return [Node::query()->findOrFail($receipt->node_id)];
                }
            }
        }

        if ($process instanceof Process) {
            return match (true) {
                Instance::isMorphType($process->owner_type) => [Node::query()->findOrFail(
                    Instance::query()->findOrFail($process->owner_id)->node_id,
                )],
                $process->owner_type === Node::class => [Node::query()->findOrFail($process->owner_id)],
                default => throw new ResourceOperationException(
                    errorCode: 'process.target_unsupported',
                    message: 'The Process owner is not a supported Instance or Node.',
                    status: 409,
                ),
            };
        }

        $targetType = $request->input('target_type');
        $targetId = $this->positiveInteger($request->input('target_id'));
        $selector = $request->input('target_id');
        if ($targetType === 'instance' && $targetId === null && is_string($selector) && preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z0-9-]+\z/D', $selector) === 1) {
            $instances = Instance::query()
                ->whereHas('node.roles', static fn ($query) => $query
                    ->where('role', RoleName::AppDev)
                    ->where('status', LifecycleStatus::Active))
                ->whereHas('routes', static fn ($query) => $query->where('domain', $selector))
                ->limit(2)
                ->get();
            if ($instances->count() > 1) {
                throw new ResourceOperationException('process.target_ambiguous', 'The Route matches multiple development Instances.', 409);
            }
            if ($instances->isEmpty()) {
                throw new ModelNotFoundException()->setModel(Instance::class);
            }
            $targetId = $instances->sole()->id;
            $request->merge(['target_id' => $targetId]);
        }

        if (! is_string($targetType) || $targetId === null) {
            return [];
        }

        return match ($targetType) {
            'instance' => [Node::query()->findOrFail(
                Instance::query()->findOrFail($targetId)->node_id,
            )],
            'node' => [Node::query()->findOrFail($targetId)],
            default => [],
        };
    }

    /** @return list<Node> */
    private function instanceHost(Request $request): array
    {
        $instance = $request->route('instance');

        if ($instance instanceof Instance) {
            return [Node::query()->findOrFail($instance->node_id)];
        }

        $instanceId = $this->positiveInteger($instance);

        if ($instanceId === null) {
            return [];
        }

        $instance = Instance::query()->find($instanceId);

        return $instance instanceof Instance
            ? [Node::query()->findOrFail($instance->node_id)]
            : [];
    }

    /** @return list<Node> */
    private function scheduleHost(Request $request): array
    {
        $schedule = $request->route('schedule');

        if ($schedule instanceof Schedule) {
            return [Node::query()->findOrFail($schedule->host_node_id)];
        }

        if (! is_string($schedule) || $schedule === '') {
            return [];
        }

        $schedule = Schedule::query()->find($schedule);

        if (! $schedule instanceof Schedule) {
            return [];
        }

        return [Node::query()->findOrFail($schedule->host_node_id)];
    }

    /** @return list<Node> */
    private function scheduleOwning(Request $request): array
    {
        $schedule = $request->route('schedule');
        if (is_string($schedule)) {
            $record = Schedule::query()->find($schedule);
            if ($record instanceof Schedule) {
                $schedule = $record;
            } elseif ($request->route()?->getName() === 'schedule:destroy') {
                $receipt = Operation::query()->where('kind', 'schedule.destroy')->where('idempotency_key', $request->header('Idempotency-Key'))->first();
                $recordedId = $receipt?->input['id'] ?? null;
                $caller = $this->caller($request)[0] ?? null;
                if ($receipt !== null && $caller instanceof Node && $receipt->callerNodeId() === $caller->id
                    && is_string($recordedId) && $recordedId === $schedule) {
                    return [Node::query()->findOrFail($receipt->node_id)];
                }
            }
        }

        if ($schedule instanceof Schedule) {
            return match (true) {
                $schedule->target_type === Node::class => [Node::query()->findOrFail($schedule->target_id)],
                Instance::isMorphType($schedule->target_type) => [Node::query()->findOrFail(
                    Instance::query()->findOrFail($schedule->target_id)->node_id,
                )],
                default => [],
            };
        }

        $targetId = $this->positiveInteger($request->input('target_id'));

        if ($targetId === null) {
            return [];
        }

        return match ($request->input('target_type')) {
            'node' => [Node::query()->findOrFail($targetId)],
            'instance' => [Node::query()->findOrFail(
                Instance::query()->findOrFail($targetId)->node_id,
            )],
            default => [],
        };
    }

    /** @return list<Node> */
    private function toolOwning(Request $request): array
    {
        $tool = $request->route('tool');

        if ($tool instanceof Tool) {
            return [Node::query()->findOrFail($tool->node_id)];
        }

        if (is_string($tool) && ctype_digit($tool)) {
            $record = Tool::query()->find((int) $tool);
            if ($record !== null) {
                return [Node::query()->findOrFail($record->node_id)];
            }
            $kind = $request->route()?->getName() === 'tool:remove' ? 'tool.remove' : 'tool.update';
            $receipt = Operation::query()->where('kind', $kind)->where('idempotency_key', $request->header('Idempotency-Key'))->first();
            if ($receipt !== null && ($receipt->input['tool_id'] ?? null) === (int) $tool) {
                return [Node::query()->findOrFail($receipt->node_id)];
            }
            throw (new ModelNotFoundException)->setModel(Tool::class, [$tool]);
        }

        $nodeId = $this->positiveInteger($request->input('node_id'));

        if ($nodeId === null) {
            return [];
        }

        return [Node::query()->findOrFail($nodeId)];
    }

    /** @return list<Node> */
    private function clusterOwning(Request $request): array
    {
        $cluster = $request->route('cluster');

        if (! $cluster instanceof Cluster) {
            return [];
        }

        $nodes = $cluster->nodes()->orderBy('id')->get()->all();

        return $nodes !== [] ? array_values($nodes) : $this->gateway();
    }

    /** @return list<Node> */
    private function routeOwning(Request $request): array
    {
        $route = $request->route('route');
        $id = $this->positiveInteger($route);
        if ($id !== null) {
            $route = Route::query()->find($id);
            if (! $route instanceof Route) {
                $start = $request->route() instanceof \Illuminate\Routing\Route
                    ? StartsOperation::forRoute($request->route()) : null;
                $key = $request->header('Idempotency-Key');
                $receipt = $start !== null && is_string($key)
                    ? Operation::query()->where('kind', $start->kind)->where('target_type', 'route')
                        ->where('target_id', $id)->where('idempotency_key', $key)->first() : null;
                if (! $receipt instanceof Operation) {
                    throw (new ModelNotFoundException)->setModel(Route::class, [$id]);
                }
                $request->attributes->set('orbit.route_public_process', ($receipt->input['public_process'] ?? false) === true);
                $ids = ValidatedData::integerList($receipt->input['access_node_ids'] ?? ($receipt->node_id === null ? [] : [$receipt->node_id]));
                $nodes = Node::query()->whereIn('id', $ids)->get();
                if ($nodes->isEmpty() || $nodes->count() !== count($ids)) {
                    return $this->gateway();
                }

                return array_values($nodes->all());
            }
            $request->attributes->set('orbit.route_public_process', $route->isCustomProxy() && $route->cluster_id !== null);
        }

        if ($route instanceof Route) {
            if ($route->isCustomProxy() && $route->cluster_id !== null) {
                return [...$this->ingressNodes($route->cluster_id), Node::query()->findOrFail($route->customProxy?->node_id)];
            }
            if ($route->node_id !== null) {
                return [Node::query()->findOrFail($route->node_id)];
            }

            return $this->clusterNodes((int) $route->cluster_id);
        }

        $instanceId = $this->positiveInteger($request->input('instance_id'));

        if ($instanceId !== null) {
            $instance = Instance::query()->findOrFail($instanceId);

            return [Node::query()->findOrFail($instance->node_id)];
        }

        $nodeId = $this->positiveInteger($request->input('node_id'));

        if ($nodeId !== null) {
            return [Node::query()->findOrFail($nodeId)];
        }

        $processId = $this->positiveInteger($request->input('process_id'));
        if ($request->input('publication') === 'public' && $processId !== null) {
            $process = Process::query()->findOrFail($processId);
            if ($process->owner_type === Node::class) {
                $ingress = new PublicProcessRouteIngress()->resolve($process, $this->positiveInteger($request->input('ingress_node_id')));

                return [$ingress, Node::query()->findOrFail($process->owner_id)];
            }
        }

        $clusterId = $this->positiveInteger($request->input('cluster_id'));

        return $clusterId === null ? [] : $this->clusterNodes($clusterId);
    }

    /** @return list<Node> */
    private function ingressNodes(int $clusterId): array
    {
        $ingress = Cluster::query()->findOrFail($clusterId)->ingressAssignment?->node;

        return $ingress instanceof Node ? [$ingress] : [];
    }

    /** @return list<Node> */
    private function clusterNodes(int $clusterId): array
    {
        $cluster = Cluster::query()->findOrFail($clusterId);
        $nodes = $cluster->nodes()->orderBy('id')->get()->all();

        return $nodes !== [] ? array_values($nodes) : $this->gateway();
    }

    /**
     * @return list<Node>
     */
    private function roleMutation(Request $request): array
    {
        $role = $request->route('role') ?? $request->input('role');

        if ($role === RoleName::Metrics->value || $role === RoleName::Metrics) {
            return $this->gateway();
        }

        return $this->target($request);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) ? $integer : null;
    }
}
