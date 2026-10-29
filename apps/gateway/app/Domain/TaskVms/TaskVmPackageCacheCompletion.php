<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\TaskVms\TaskVmInputs;
use App\Models\Operation;
use App\Models\PackageCacheEntry;
use App\Models\PackageCacheSnapshot;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class TaskVmPackageCacheCompletion implements OperationCompletion
{
    public function __construct(private TaskVmSettings $settings, private OperationExecutionContract $contract) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Cancelled) {
            return;
        }
        $hashes = [];
        $verified = false;
        foreach ($operation->children()->get() as $child) {
            $child = app(OperationChildren::class)->child($operation, (string) $child->child_key);
            $receipt = $child->result;
            if (! isset($receipt['archive_ids'])) {
                continue;
            }
            $this->contract->assertCompletion($child, $receipt);
            $keys = ValidatedData::stringList($receipt['archive_ids']);
            $values = ValidatedData::stringList($receipt['archive_digests'] ?? null);
            if (count($keys) !== count($values) || count(array_unique($keys)) !== count($keys)) {
                throw new \LogicException('The cache receipt has mismatched archive lists.');
            }
            $hashes += array_combine($keys, $values);
            $failed = ValidatedData::stringList($receipt['failed_ids'] ?? []);
            $reasons = ValidatedData::stringList($receipt['failure_reasons'] ?? []);
            foreach ($failed as $index => $id) {
                Log::warning('Task VM package cache artifact failed.', ['host_node_id' => $operation->node_id, 'archive_id' => $id, 'reason' => $reasons[$index] ?? 'Unknown']);
            }
            $verified = true;
        }
        if (! $verified) {
            return;
        }
        $result = ['hashes' => $hashes];
        $snapshots = TaskVmInputs::snapshots($operation->input['snapshots']);
        $hostNodeId = $operation->node_id;
        DB::transaction(function () use ($snapshots, $hostNodeId, $result): void {
            foreach ($snapshots as $snapshot) {
                $current = PackageCacheSnapshot::query()->lockForUpdate()->find($snapshot['project_id']);
                if (! $current instanceof PackageCacheSnapshot || $current->blobs !== $snapshot['blobs'] || $current->artifacts !== $snapshot['artifacts']) {
                    continue;
                }
                PackageCacheEntry::query()->where('project_id', $snapshot['project_id'])->where('host_node_id', $hostNodeId)->delete();
                foreach ($snapshot['artifacts'] as $artifact) {
                    if (! isset($result['hashes'][$artifact['key']])) {
                        continue;
                    }
                    PackageCacheEntry::query()->create([
                        'project_id' => $snapshot['project_id'], 'host_node_id' => $hostNodeId,
                        'manager' => $artifact['manager'], 'package' => $artifact['package'], 'version' => $artifact['version'],
                        'key' => $artifact['key'], 'integrity' => $result['hashes'][$artifact['key']],
                    ]);
                }
                $complete = true;
                foreach ($this->settings->hosts as $configuredHost) {
                    $entries = PackageCacheEntry::query()->where('project_id', $snapshot['project_id'])->where('host_node_id', $configuredHost->nodeId)->get()->keyBy(static fn (PackageCacheEntry $entry): string => $entry->package.' '.$entry->version.' '.$entry->key);
                    foreach ($snapshot['artifacts'] as $artifact) {
                        if (! $entries->has($artifact['package'].' '.$artifact['version'].' '.$artifact['key'])) {
                            $complete = false;
                            break 2;
                        }
                    }
                }
                $current->update(['fill_pending' => ! $complete]);
            }
        });
    }
}
