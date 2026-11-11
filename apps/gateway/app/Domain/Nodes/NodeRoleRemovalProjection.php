<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Models\WebSocketRuntime;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Record-only completion of the native role withdrawal; no PHP effect or child execution. */
final readonly class NodeRoleRemovalProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent'], 'execution_secrets' => [], 'offline' => false];
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        app(NodeRolePublicationInput::class)->assertPublicParticipant($operation, $callerNodeId);
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        return app(NodeRolePublicationInput::class)->publicInput($operation, $callerNodeId);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $raw = ValidatedData::object($data['result'] ?? []);
            $snapshot = ValidatedData::object($operation->input['_role_remove']);
            $result = ($data['status'] ?? null) === 'succeeded' && NativeRunResult::stepStatus($raw, 'Confirm role removal') === 'succeeded'
                ? ['node_id' => $snapshot['node_id'], 'assignment_id' => $snapshot['assignment_id'], 'role' => $snapshot['role'], 'role_removed' => true, 'offline' => false]
                : array_intersect_key($raw, array_flip(['error_code', 'failed_step']));
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal) {
                return $projected;
            }
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            if (! $status->terminal()) {
                return $projected;
            }
            $assignment = NodeRole::query()->whereKey($snapshot['assignment_id'])->where('node_id', $operation->node_id)->where('role', $snapshot['role'])->firstOrFail();
            $node = $assignment->node;
            if ($status === OperationStatus::Succeeded) {
                self::recordCleanup($snapshot);
                $assignment->delete();
            } else {
                $assignment->update(['status' => LifecycleStatus::Failed,
                    'failed_step' => 'remove:'.ValidatedData::string($result['failed_step'] ?? 'operation'),
                    'error_code' => ValidatedData::string($result['error_code'] ?? 'operation.cancelled')]);
            }
            app(RecordEventBroadcaster::class)->broadcast(RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node->refresh())->toArray());

            return $projected;
        });
    }

    /** Finalize only the exact source whose native cleanup completed; relocation keeps the target assignment.
     * @param  array<string,mixed>  $snapshot
     */
    public static function recordCleanup(array $snapshot, bool $relocated = false): void
    {
        $nodeId = ValidatedData::integer($snapshot['node_id']);
        $role = RoleName::from(ValidatedData::string($snapshot['role']));
        $runtime = ValidatedData::object($snapshot['runtime'] ?? []);
        if ($role === RoleName::Analytics && isset($runtime['process_id'])) {
            $process = Process::query()->whereKey($runtime['process_id'])->where('owner_type', Node::class)
                ->where('owner_id', $nodeId)->where('name', PlausibleProcess::NAME)->first();
            $process?->delete();
        }
        if ($role === RoleName::Gateway) {
            app(GatewayPrivateDnsState::class)->forget($nodeId);
        }
        if ($role === RoleName::WebSocket) {
            app(WebSocketRegistry::class)->write(fn (): int => WebSocketRuntime::query()->where('node_id', $nodeId)->where('slot', 'default')->update(['required' => false]));
        }
        if ($relocated) {
            app(NodeRoleSettings::class)->removeTransferred($nodeId, $role);
        } else {
            app(NodeRoleSettings::class)->remove($nodeId, $role, ($snapshot['purge_data'] ?? false) === true, false);
        }
    }
}
