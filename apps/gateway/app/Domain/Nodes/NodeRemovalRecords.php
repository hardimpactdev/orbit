<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
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
use Illuminate\Support\Facades\DB;

/** Database projection only. The owning native DAG decides when cleanup is complete. */
final readonly class NodeRemovalRecords
{
    public function __construct(private RecordEventBroadcaster $broadcaster, private WebSocketRegistry $registry) {}

    /** No host calls, receipt reads, child ownership or replay of native validation.
     * @param  array<string,mixed>  $snapshot  Safe input captured by the owning parent.
     */
    public function remove(array $snapshot): void
    {
        $nodeId = ValidatedData::integer($snapshot['node_id']);
        DB::transaction(function () use ($snapshot, $nodeId): void {
            $node = Node::query()->lockForUpdate()->find($nodeId);
            if ($node === null) {
                return;
            }
            if ($node->name !== $snapshot['name'] || ($snapshot['task_vm_id'] !== null && ! TaskVm::query()->whereKey($snapshot['task_vm_id'])->where('node_id', $node->id)->exists())) {
                throw new ResourceOperationException('node.removal_owner_mismatch', 'The admitted cleanup no longer owns this Node row.', 422);
            }
            foreach (ValidatedData::integerList($snapshot['route_ids']) as $id) {
                $route = Route::query()->find($id);
                if ($route?->node_id === $node->id || $route?->generation_basis_node_id === $node->id) {
                    $route->delete();
                } elseif ($route?->transition_node_id === $node->id) {
                    $route->update(['transition_node_id' => null]);
                }
            }
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
    public function restore(array $snapshot): void
    {
        Node::query()->whereKey($snapshot['node_id'])->update(['status' => $snapshot['prior_status'], 'failed_step' => null, 'error_code' => null]);
    }
}
