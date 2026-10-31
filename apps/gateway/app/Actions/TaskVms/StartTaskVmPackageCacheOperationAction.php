<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use App\Models\PackageCacheSnapshot;

final readonly class StartTaskVmPackageCacheOperationAction
{
    public function __construct(private StartOperationAction $start, private TaskVmSettings $settings) {}

    public function execute(int $hostNodeId): Operation
    {
        $this->settings->host($hostNodeId);
        $node = Node::query()->whereKey($hostNodeId)->where('status', LifecycleStatus::Active)->firstOrFail();
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
                if (strlen($artifact['key']) > 1024) {
                    throw new TaskVmException('task_vm.package_cache_invalid_result', 'The cache key exceeds the Operation result limit.');
                }
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
            'host_node_id' => $hostNodeId, 'agent_sha256' => app(AgentDelivery::class)->pin($node),
            'artifacts' => array_values(array_diff_key($artifacts, $conflicts)), 'conflicts' => $conflicts,
            'max_bytes' => $maxBytes, 'github_token' => ['$secret' => 'task_vm.package_cache_github', 'id' => $hostNodeId],
            'snapshots' => $snapshots->map->only(['project_id', 'blobs', 'artifacts'])->all(),
        ], 'task-vm', null);
    }
}
