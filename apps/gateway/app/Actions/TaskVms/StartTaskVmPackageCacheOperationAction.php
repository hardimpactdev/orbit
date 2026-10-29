<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmCacheFillBatches;
use App\Domain\TaskVms\TaskVmCapabilities;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\Node;
use App\Models\Operation;
use App\Models\PackageCacheSnapshot;

final readonly class StartTaskVmPackageCacheOperationAction
{
    public function __construct(private StartOperationAction $start, private TaskVmSettings $settings) {}

    public function execute(int $hostNodeId): Operation
    {
        TaskVmCapabilities::require('list_of');
        TaskVmCapabilities::require('children_settled');
        TaskVmCapabilities::require('receipts');
        $this->settings->host($hostNodeId);
        Node::query()->whereKey($hostNodeId)->where('status', LifecycleStatus::Active)->firstOrFail();
        $existing = Operation::query()->where('kind', 'task_vm.fill_package_cache')->where('target_id', $hostNodeId)
            ->where('target_type', 'task_vm_cache')->whereNotIn('status', ['succeeded', 'failed', 'cancelled'])->first();
        if ($existing !== null) {
            return $existing;
        }
        $snapshots = PackageCacheSnapshot::query()->orderBy('project_id')->get();
        $artifacts = [];
        $conflicts = [];
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot->artifacts as $artifact) {
                $previous = $artifacts[$artifact['key']] ?? null;
                if ($previous !== null && $previous['integrity'] !== null && $artifact['integrity'] !== null && $previous['integrity'] !== $artifact['integrity']) {
                    $conflicts[$artifact['key']] = 'IntegrityConflict';
                }
                if ($previous === null || $artifact['integrity'] !== null) {
                    $artifacts[$artifact['key']] = $artifact;
                }
            }
        }
        $maxBytes = config()->integer('package_cache.max_bytes');
        if ($maxBytes < 1) {
            throw new TaskVmException('task_vm.package_cache_invalid_limit', 'The package cache byte budget must be positive.');
        }

        return $this->start->execute('task_vm.fill_package_cache', 'task_vm_cache', $hostNodeId, $hostNodeId, [
            'host_node_id' => $hostNodeId, 'batches' => TaskVmCacheFillBatches::compile(array_values(array_diff_key($artifacts, $conflicts)), $conflicts),
            'deadline_at' => now()->addSeconds(1700)->getTimestamp(),
            'max_bytes' => $maxBytes, 'github_token' => ['$secret' => 'task_vm.package_cache_github', 'id' => $hostNodeId],
            'snapshots' => $snapshots->map->only(['project_id', 'blobs', 'artifacts'])->all(),
        ], 'task-vm', null);
    }
}
