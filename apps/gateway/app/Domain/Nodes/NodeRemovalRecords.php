<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Routes\NativeRouteRemovalCompletion;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use App\Models\WebSocketRuntime;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Database projection only. The owning native DAG decides when cleanup is complete. */
final readonly class NodeRemovalRecords
{
    public function __construct(private RecordEventBroadcaster $broadcaster, private WebSocketRegistry $registry) {}

    /** Public membership transition requested by the owning native DAG.
     * @param  array<string,mixed>  $snapshot
     */
    public function begin(array $snapshot): void
    {
        DB::transaction(function () use ($snapshot): void {
            $node = Node::query()->lockForUpdate()->find(ValidatedData::integer($snapshot['node_id']));
            if ($node === null || $node->name !== $snapshot['name']
                || ($snapshot['task_vm_id'] !== null && ! TaskVm::query()->whereKey($snapshot['task_vm_id'])->where('node_id', $node->id)->exists())) {
                throw new ResourceOperationException('node.removal_owner_mismatch', 'The admitted removal no longer owns this Node row.', 422);
            }
            $node->update(['status' => LifecycleStatus::Removing]);
        });
    }

    /** No host calls, receipt reads, child ownership or replay of native validation.
     * @param  array<string,mixed>  $snapshot  Safe input captured by NodeRemovalPlan.
     * @param  array<string,mixed>  $outcome  Closed native result.
     * @param  array<string,mixed>|null  $routes
     */
    public function remove(array $snapshot, array $outcome, ?array $routes = null): void
    {
        DB::transaction(function () use ($snapshot, $routes): void {
            $node = Node::query()->lockForUpdate()->find(ValidatedData::integer($snapshot['node_id']));
            if ($node === null) {
                return;
            }
            if ($node->name !== $snapshot['name'] || ($snapshot['task_vm_id'] !== null && ! TaskVm::query()->whereKey($snapshot['task_vm_id'])->where('node_id', $node->id)->exists())) {
                throw new ResourceOperationException('node.removal_owner_mismatch', 'The admitted cleanup no longer owns this Node row.', 422);
            }
            $routes ??= ['routes' => Route::query()->whereKey(ValidatedData::integerList($snapshot['route_ids']))->get()
                ->map(static fn (Route $route): array => ['route_id' => $route->id, 'domain' => $route->domain,
                    'replacement_of' => $route->isUnactivatedReplacement() ? $route->replaces_route_id : null])->all()];
            $rows = is_array($routes['routes'] ?? null) ? array_values($routes['routes']) : [];
            app(NativeRouteRemovalCompletion::class)->apply(['routes' => array_map(static function (mixed $raw): array {
                $row = ValidatedData::object($raw);

                return ['route_id' => ValidatedData::integer($row['route_id']), 'domain' => ValidatedData::string($row['domain']),
                    'replacement_of' => ($row['replacement_of'] ?? null) === null ? null : ValidatedData::integer($row['replacement_of'])];
            }, $rows)]);
            $this->registry->abandonNode($node->id);
            Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->get()->each(static fn (Process $process): bool => $process->delete() ?? false);
            $node->roles()->delete();
            TaskVm::query()->where('host_node_id', $node->id)->delete();
            TaskVmImageBuild::query()->where('host_node_id', $node->id)->whereIn('state', [TaskVmImageBuildState::Succeeded, TaskVmImageBuildState::Failed])->delete();
            WebSocketRuntime::query()->where('node_id', $node->id)->delete();
            $node->delete();
            DB::afterCommit(fn () => $this->broadcaster->broadcast(RecordEventType::NodeDeleted, $node->id, ['id' => $node->id, 'name' => $node->name]));
        });
    }

    /** @param array<string,mixed> $snapshot */
    public function restore(array $snapshot, mixed $routePriorStates = []): void
    {
        $node = Node::query()->find(ValidatedData::integer($snapshot['node_id']));
        if ($node === null) {
            return;
        }
        if ($node->name !== $snapshot['name'] || ($snapshot['task_vm_id'] !== null && ! TaskVm::query()->whereKey($snapshot['task_vm_id'])->where('node_id', $node->id)->exists())) {
            throw new ResourceOperationException('node.removal_owner_mismatch', 'The admitted restore no longer owns this Node row.', 422);
        }
        $node->update(['status' => $snapshot['prior_status'], 'failed_step' => null, 'error_code' => null]);
        foreach (is_array($routePriorStates) ? $routePriorStates : [] as $prior) {
            $state = ValidatedData::object($prior);
            $id = ValidatedData::integer($state['id']);
            if (in_array($id, ValidatedData::integerList($snapshot['route_ids']), true)) {
                unset($state['id']);
                Route::query()->whereKey($id)->update(Arr::only($state, ['status', 'sites_published', 'failed_step', 'error_code']));
            }
        }
    }
}
