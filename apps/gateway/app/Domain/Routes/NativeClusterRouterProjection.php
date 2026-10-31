<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Store native transition checkpoints. The Gateway does not verify Swamp's DAG. */
final readonly class NativeClusterRouterProjection
{
    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function project(Operation $operation, array $data): array
    {
        $result = ValidatedData::object($data['result'] ?? []);
        $intent = ValidatedData::object($operation->input['cluster_router']);
        $candidate = $intent['candidate'];
        $oldIds = array_column($intent['old_nodes'], 'assignment_id');
        $status = OperationStatus::from(ValidatedData::string($data['status'] ?? 'running'));
        if ($status === OperationStatus::Running) {
            if (($result['checkpoint'] ?? null) === 'router-serving' && is_array($candidate)) {
                NodeRole::query()->whereKey($candidate['assignment_id'])->update(['failed_step' => ClusterRouterReplacementStep::RouterCaddy->value]);
            } elseif (($result['checkpoint'] ?? null) === 'router-selected' && is_array($candidate)) {
                NodeRole::query()->whereKey($oldIds)->update(['status' => LifecycleStatus::Removing, 'failed_step' => null]);
                NodeRole::query()->whereKey($candidate['assignment_id'])->update(['status' => LifecycleStatus::Active, 'failed_step' => ClusterRouterReplacementStep::DatabaseCutover->value, 'error_code' => null]);
            } elseif (($result['checkpoint'] ?? null) === 'old-router-withdrawn') {
                NodeRole::query()->whereKey($oldIds)->update(['status' => LifecycleStatus::Removing, 'failed_step' => ClusterRouterTransition::OldRouterCleanup]);
            } elseif (($result['checkpoint'] ?? null) === 'candidate-withdrawn' && is_array($candidate)) {
                NodeRole::query()->whereKey($candidate['assignment_id'])->update(['status' => LifecycleStatus::Failed, 'failed_step' => 'rollback:dns-publication', 'error_code' => $result['error_code'] ?? 'cluster.router_failed']);
            }
        } elseif ($status === OperationStatus::Succeeded) {
            NodeRole::query()->whereKey($oldIds)->delete();
            if (is_array($candidate)) {
                NodeRole::query()->whereKey($candidate['assignment_id'])->update(['failed_step' => null, 'error_code' => null]);
            }
        } elseif ($status === OperationStatus::Failed && is_array($candidate)) {
            $role = NodeRole::query()->find($candidate['assignment_id']);
            $role?->update(['status' => $role->status === LifecycleStatus::Active ? LifecycleStatus::Active : LifecycleStatus::Failed,
                'failed_step' => $result['failed_step'] ?? $role->failed_step ?? 'router-preparation', 'error_code' => $data['error_code'] ?? 'cluster.router_failed']);
        }

        return $result;
    }
}
