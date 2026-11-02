<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Project the native terminal transition. No host reads or intermediate callbacks. */
final readonly class NativeClusterRouterProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input, 'execution_secrets' => []];
    }

    /** @return list<int> */
    public function participantNodeIds(Operation $operation): array
    {
        return ValidatedData::integerList($operation->input['participant_node_ids']);
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if (! in_array($callerNodeId, $this->participantNodeIds($operation), true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node is not an admitted Router participant.', 403);
        }
    }

    public function publicInput(Operation $operation, int $nodeId): array
    {
        $this->assertPublicParticipant($operation, $nodeId);

        return $operation->input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $operation->status->terminal();
            $raw = ValidatedData::object($data['result'] ?? []);
            $result = Arr::only($raw, ['ready', 'selected', 'restored', 'cleanup_complete', 'failed_step', 'error_code']);
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal) {
                $this->project($projected, [...$data, 'result' => $result]);
            }

            return $projected->refresh();
        });
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function project(Operation $operation, array $data): array
    {
        $result = ValidatedData::object($data['result'] ?? []);
        $intent = ValidatedData::object($operation->input['cluster_router']);
        $candidate = $intent['candidate'];
        $oldIds = $this->ids($intent['old_nodes'], 'assignment_id');
        $status = OperationStatus::from(ValidatedData::string($data['status'] ?? 'running'));
        if (! $status->terminal()) {
            return $result;
        }
        $selected = ($result['selected'] ?? false) === true && ($result['restored'] ?? false) !== true;
        if ($status === OperationStatus::Succeeded || $selected) {
            NodeRole::query()->whereKey($oldIds)->update(['status' => LifecycleStatus::Removing,
                'failed_step' => ClusterRouterTransition::OldRouterCleanup]);
            if (is_array($candidate)) {
                NodeRole::query()->whereKey($candidate['assignment_id'])->update(['status' => LifecycleStatus::Active,
                    'failed_step' => $status === OperationStatus::Succeeded ? null : ($result['failed_step'] ?? 'cleanup'),
                    'error_code' => $status === OperationStatus::Succeeded ? null : ($data['error_code'] ?? 'cluster.router_failed')]);
            }
            if (($result['cleanup_complete'] ?? false) === true || $status === OperationStatus::Succeeded) {
                NodeRole::query()->whereKey($oldIds)->delete();
            }
        } elseif (is_array($candidate)) {
            $role = NodeRole::query()->find(ValidatedData::integer($candidate['assignment_id']));
            $role?->update(['status' => $role->status === LifecycleStatus::Active ? LifecycleStatus::Active : LifecycleStatus::Failed,
                'failed_step' => $result['failed_step'] ?? 'router-preparation',
                'error_code' => $data['error_code'] ?? 'cluster.router_failed']);
        }

        return $result;
    }

    /** @return list<int> */
    private function ids(mixed $rows, string $field): array
    {
        if (! is_array($rows)) {
            throw new \UnexpectedValueException('Stored Router participants must be an array.');
        }

        return array_values(array_map(static fn (mixed $row): int => ValidatedData::integer(ValidatedData::object($row)[$field]), $rows));
    }
}
